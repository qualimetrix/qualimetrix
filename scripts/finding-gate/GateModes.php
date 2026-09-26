<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What each mode of the gate's command line does, and the exit code it ends with.
 */
final class GateModes
{
    /**
     * A derive run is a write, not a verdict, and none of the three returns 0.
     *
     * Returning 0 made a write look like a passing check to anything reading an exit
     * code, including a DoD, while what it had actually done was replace the
     * declaration the next real run will be judged against. Two distinct non-zero
     * codes so the two outcomes are told apart: {@see self::WROTE} for "wrote a
     * declaration, now read it", {@see self::MEASUREMENT_FAILED} for "the run that was
     * supposed to measure it failed, and nothing was written".
     */
    public const int WROTE = 4;

    public const int MEASUREMENT_FAILED = 5;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        try {
            $options = Options::parse($argv, \dirname(__DIR__, 2));

            return match ($options->mode) {
                Options::MODE_SELF_TEST => self::selfTest($options),
                Options::MODE_CASE_WORKER => self::runCaseWorker($options),
                default => self::run($options, new GateReport()),
            };
        } catch (GateError $error) {
            fwrite(\STDERR, 'finding-gate: ' . $error->getMessage() . "\n");

            return Interruption::stoppedRun() ? (Interruption::exitCode() ?? 3) : 3;
        }
    }

    /**
     * The modes that compare or write, into a report the caller owns — which is
     * what lets the self-test drive each mode through the same door the command
     * line does and still see where every failure was raised.
     */
    public static function run(Options $options, GateReport $report): int
    {
        return match ($options->mode) {
            Options::MODE_DERIVE_TUPLE => self::deriveTuple($options),
            Options::MODE_DERIVE_NORMALIZATION => self::deriveNormalization($options, $report),
            Options::MODE_DERIVE_DECLARED_DELTA => self::deriveDeclaredDelta($options, $report),
            Options::MODE_COMPARE => self::compare($options, $report),
            default => throw new GateError(\sprintf('The mode "%s" neither compares nor writes.', $options->mode)),
        };
    }

    /** Runs one independent corpus case for the bounded parent scheduler. */
    private static function runCaseWorker(Options $options): int
    {
        if ($options->caseWorker === null || $options->workerOutput === null || $options->workerLabel === null || $options->workerTree === null) {
            throw new GateError('The internal case worker is missing its required arguments.');
        }

        $case = Corpus::load($options->candidateRoot, [$options->caseWorker])->cases[0];
        $vocabulary = MetricVocabulary::ofTree($options->candidateRoot);
        $maps = RenameMaps::load($options->candidateRoot . '/finding-gate/maps', $vocabulary);
        // Beside its output, which the parent put inside the run directory it will
        // remove. A worker therefore needs no cleanup window of its own: the parent
        // SIGKILLs its whole process group and then removes the directory the worker
        // was writing into. A worker scratch of its own in TMPDIR would need one,
        // and would not get it — the parent allows 0.3 s between SIGTERM and
        // SIGKILL, less than terminating one `bin/qmx` takes.
        $temporaryDirectory = \dirname($options->workerOutput) . '/worker-' . bin2hex(random_bytes(6));

        if (!@mkdir($temporaryDirectory, 0o700, true)) {
            throw new GateError(\sprintf('Cannot create the case worker directory %s.', $temporaryDirectory));
        }

        try {
            $run = new TreeRun(
                $options->workerTree,
                $temporaryDirectory,
                $options->workerLabel,
                $maps,
                $options->workerReverseInput,
            );
            $artifacts = $run->forCase($case);

            // Beside the artifacts, what this worker's own maps translated. A row
            // whose only work is on a case's input fires here and in no other
            // process, so without this the parent judges it stale.
            Fs::write(
                $options->workerOutput,
                json_encode(
                    ['artifacts' => $artifacts, 'mapHits' => $maps->firedRows()],
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
                ),
            );
        } finally {
            Fs::removeRecursively($temporaryDirectory);
        }

        return 0;
    }

    private static function compare(Options $options, GateReport $report): int
    {
        $gate = new Gate($options, $report);

        $gate->compare();

        echo $report->render();

        if ($options->reportPath !== null) {
            $report->writeJson($options->reportPath);
        }

        return $report->exitCode();
    }

    private static function deriveTuple(Options $options): int
    {
        $path = $options->candidateRoot . '/' . EquivalenceTuple::TRACKED_PATH;
        Fs::write($path, EquivalenceTuple::derive($options->candidateRoot)->render());
        echo 'Derived ' . $path . " from the publishing code.\n";
        echo "This was a write, not a check: re-run without --derive-tuple to be judged against it.\n";

        return self::WROTE;
    }

    /**
     * Measures the normalization list — and, like the declared delta, only from a
     * run that produced something.
     *
     * The verdict has to be taken before the write and not after it: this mode
     * compares nothing, so every failure class lived in a code path it never
     * entered, and a candidate whose runs all failed rewrote the tracked list down
     * to its header while printing "Measured ... from repeated runs".
     */
    private static function deriveNormalization(Options $options, GateReport $report): int
    {
        $path = $options->candidateRoot . '/finding-gate/normalization.tsv';
        $gate = new Gate($options, $report);
        $measured = $gate->deriveNormalization();

        if ($options->reportPath !== null) {
            $report->writeJson($options->reportPath);
        }

        if ($measured === null) {
            echo $report->render();
            echo "The runs this list would be measured from failed, so nothing was written: a list measured from a"
                . " broken run describes the breakage and lets the next run agree with it.\n";

            return self::MEASUREMENT_FAILED;
        }

        // A signal that arrived in the tail of a run reaches no decision point: the
        // measurement is complete, the scratch is already handed back, and nothing
        // would stop this write. Refused here, because Ctrl-C must not be the last
        // thing a developer does before the tracked list changes under them.
        if (($interrupted = Interruption::exitCode()) !== null) {
            echo "The run was interrupted, so nothing was written: a declaration is only ever measured from a run that\n"
                . "was allowed to finish.\n";

            return $interrupted;
        }

        Fs::write($path, $measured);
        echo 'Measured ' . $path . " from repeated runs of the candidate tree.\n";
        echo "This was a write, not a check: re-run without --derive-normalization to be judged against it.\n";

        return self::WROTE;
    }

    /** Rewrites the declared delta and its diff files from a full comparison. */
    private static function deriveDeclaredDelta(Options $options, GateReport $report): int
    {
        $gate = new Gate($options, $report);
        $written = $gate->deriveDeclaredDelta();
        echo $report->render();

        // A derive run's verdict is what decides whether anything was written, so
        // it has to be as readable by machine as a comparison's is: the control that
        // proves a failed derivation leaves the tree alone reads it from here.
        if ($options->reportPath !== null) {
            $report->writeJson($options->reportPath);
        }

        // A declaration derived from a broken run describes the breakage: if it is
        // deterministic — and a product bug on the reference side is — the next real
        // run reproduces it and goes green against it.
        if ($report->exitCode() !== 0) {
            echo "The run this declaration would be derived from failed, so nothing was written: a declaration"
                . " measured from a broken run would describe the breakage and let the next run agree with it.\n";

            return self::MEASUREMENT_FAILED;
        }

        echo 'Measured the declared delta into: ' . implode(', ', $written) . "\n";
        echo "Fill in the reason of every row marked \"?\" — the gate refuses to load one that is not explained.\n";
        echo "This was a write, not a check: re-run without --derive-declared-delta to be judged against it.\n";

        return self::WROTE;
    }

    private static function selfTest(Options $options): int
    {
        $failures = (new SelfTest($options->candidateRoot))->run();

        foreach ($failures as $failure) {
            echo '  FAIL  ', $failure, "\n";
        }

        echo $failures === [] ? "  self-test green\n" : \sprintf("  self-test RED (%d)\n", \count($failures));

        return $failures === [] ? 0 : 1;
    }
}
