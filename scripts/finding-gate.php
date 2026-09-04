<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The finding-equivalence gate.
 *
 * Proves that a step of docs/internal/plans/rule-vocabulary/PLAN.md changed
 * nothing observable except what a declared map says it changed. See
 * finding-gate/README.md for the corpus layout and the surface list.
 *
 * Deliberately outside `src/`: it is not product code, it must run against two
 * trees at once, and it must keep working while the product's own vocabulary is
 * being renamed under it.
 */

foreach (
    [
        'CommandLine',
        'FailureClass',
        'GateError',
        'BudgetExceeded',
        'Fs',
        'Tsv',
        'Process',
        'Surfaces',
        'MetricVocabulary',
        'SubjectLevel',
        'Diff',
        'ExactDiff',
        'GateReport',
        'Options',
        'CaseDefinition',
        'Corpus',
        'RenameMaps',
        'ChannelSplit',
        'PublishedVocabulary',
        'DeclaredDelta',
        'DeclaredFieldMoves',
        'NormalizationRule',
        'Normalization',
        'NormalizationDeriver',
        'EquivalenceTuple',
        'FingerprintSubstitution',
        'Fingerprints',
        'ReportPayload',
        'ChannelWitness',
        'ChannelCoverage',
        'TreeRun',
        'ReferenceTree',
        'Gate',
        'SelfTest',
    ] as $class
) {
    require __DIR__ . '/finding-gate/' . $class . '.php';
}

/**
 * A derive run is a write, not a verdict, and none of the three returns 0.
 *
 * Returning 0 made a write look like a passing check to anything reading an exit
 * code, including a DoD, while what it had actually done was replace the
 * declaration the next real run will be judged against. Two distinct non-zero
 * codes so the two outcomes are told apart: {@see WROTE} for "wrote a
 * declaration, now read it", {@see MEASUREMENT_FAILED} for "the run that was
 * supposed to measure it failed, and nothing was written".
 */
const WROTE = 4;

const MEASUREMENT_FAILED = 5;

exit(main(CommandLine::arguments()));

/** @param list<string> $argv */
function main(array $argv): int
{
    try {
        $options = Options::parse($argv, \dirname(__DIR__));

        return match ($options->mode) {
            Options::MODE_SELF_TEST => selfTest($options),
            Options::MODE_DERIVE_TUPLE => deriveTuple($options),
            Options::MODE_DERIVE_NORMALIZATION => deriveNormalization($options),
            Options::MODE_DERIVE_DECLARED_DELTA => deriveDeclaredDelta($options),
            default => compare($options),
        };
    } catch (GateError $error) {
        fwrite(\STDERR, 'finding-gate: ' . $error->getMessage() . "\n");

        return 3;
    }
}

function compare(Options $options): int
{
    $report = new GateReport();
    $gate = new Gate($options, $report);

    // A worktree left behind on a crash would be picked up as a stale checkout
    // by the next run, so cleanup is registered before anything can fail.
    register_shutdown_function($gate->cleanUp(...));

    $gate->compare();

    echo $report->render();

    if ($options->reportPath !== null) {
        $report->writeJson($options->reportPath);
    }

    return $report->exitCode();
}

function deriveTuple(Options $options): int
{
    $path = $options->candidateRoot . '/' . EquivalenceTuple::TRACKED_PATH;
    Fs::write($path, EquivalenceTuple::derive($options->candidateRoot)->render());
    echo 'Derived ' . $path . " from the publishing code.\n";
    echo "This was a write, not a check: re-run without --derive-tuple to be judged against it.\n";

    return WROTE;
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
function deriveNormalization(Options $options): int
{
    $path = $options->candidateRoot . '/finding-gate/normalization.tsv';
    $report = new GateReport();
    $gate = new Gate($options, $report);
    register_shutdown_function($gate->cleanUp(...));
    $measured = $gate->deriveNormalization();

    if ($options->reportPath !== null) {
        $report->writeJson($options->reportPath);
    }

    if ($measured === null) {
        echo $report->render();
        echo "The runs this list would be measured from failed, so nothing was written: a list measured from a"
            . " broken run describes the breakage and lets the next run agree with it.\n";

        return MEASUREMENT_FAILED;
    }

    Fs::write($path, $measured);
    echo 'Measured ' . $path . " from repeated runs of the candidate tree.\n";
    echo "This was a write, not a check: re-run without --derive-normalization to be judged against it.\n";

    return WROTE;
}

/** Rewrites the declared delta and its diff files from a full comparison. */
function deriveDeclaredDelta(Options $options): int
{
    $report = new GateReport();
    $gate = new Gate($options, $report);
    register_shutdown_function($gate->cleanUp(...));
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

        return MEASUREMENT_FAILED;
    }

    echo 'Measured the declared delta into: ' . implode(', ', $written) . "\n";
    echo "Fill in the reason of every row marked \"?\" — the gate refuses to load one that is not explained.\n";
    echo "This was a write, not a check: re-run without --derive-declared-delta to be judged against it.\n";

    return WROTE;
}

function selfTest(Options $options): int
{
    $failures = (new SelfTest($options->candidateRoot))->run();

    foreach ($failures as $failure) {
        echo '  FAIL  ', $failure, "\n";
    }

    echo $failures === [] ? "  self-test green\n" : \sprintf("  self-test RED (%d)\n", \count($failures));

    return $failures === [] ? 0 : 1;
}
