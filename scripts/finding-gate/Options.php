<?php

declare(strict_types=1);

namespace QmxFindingGate;

final class Options
{
    public const MODE_COMPARE = 'compare';
    public const MODE_DERIVE_TUPLE = 'derive-tuple';
    public const MODE_DERIVE_NORMALIZATION = 'derive-normalization';
    public const MODE_DERIVE_DECLARED_DELTA = 'derive-declared-delta';
    public const MODE_SELF_TEST = 'self-test';
    public const MODE_CASE_WORKER = 'case-worker';

    private const DEFAULT_JOBS = 4;

    private const MAX_JOBS = 16;

    /** @param list<string> $cases */
    private function __construct(
        public readonly string $mode,
        public readonly string $candidateRoot,
        public readonly ?string $reference,
        public readonly array $cases,
        public readonly ?string $reportPath,
        public readonly bool $incompleteCorpus,
        public readonly int $jobs,
        public readonly ?string $caseWorker,
        public readonly ?string $workerOutput,
        public readonly ?string $workerLabel,
        public readonly ?string $workerTree,
        public readonly bool $workerReverseInput,
    ) {}

    /** @param list<string> $argv */
    public static function parse(array $argv, string $defaultCandidateRoot): self
    {
        $mode = self::MODE_COMPARE;
        $candidate = $defaultCandidateRoot;
        $reference = null;
        $cases = [];
        $report = null;
        $incomplete = false;
        $jobs = self::DEFAULT_JOBS;
        $caseWorker = null;
        $workerOutput = null;
        $workerLabel = null;
        $workerTree = null;
        $workerReverseInput = false;

        foreach (\array_slice($argv, 1) as $argument) {
            $value = self::value($argument);

            match (true) {
                $argument === '--derive-tuple' => $mode = self::MODE_DERIVE_TUPLE,
                $argument === '--derive-normalization' => $mode = self::MODE_DERIVE_NORMALIZATION,
                $argument === '--derive-declared-delta' => $mode = self::MODE_DERIVE_DECLARED_DELTA,
                $argument === '--self-test' => $mode = self::MODE_SELF_TEST,
                $argument === '--worker-reverse-input' => $workerReverseInput = true,
                $argument === '--incomplete-corpus' => $incomplete = true,
                str_starts_with($argument, '--candidate=') => $candidate = self::directory($value),
                str_starts_with($argument, '--reference=') => $reference = $value,
                str_starts_with($argument, '--cases=') => $cases = self::list($value),
                str_starts_with($argument, '--report=') => $report = $value,
                str_starts_with($argument, '--jobs=') => $jobs = self::jobs($value),
                str_starts_with($argument, '--case-worker=') => [$mode, $caseWorker] = [self::MODE_CASE_WORKER, $value],
                str_starts_with($argument, '--worker-output=') => $workerOutput = $value,
                str_starts_with($argument, '--worker-label=') => $workerLabel = $value,
                str_starts_with($argument, '--worker-tree=') => $workerTree = self::directory($value),
                default => throw new GateError(\sprintf("Unknown argument \"%s\".\n%s", $argument, self::usage())),
            };
        }

        if (\in_array($mode, [self::MODE_COMPARE, self::MODE_DERIVE_DECLARED_DELTA], true) && $reference === null) {
            throw new GateError("--reference=<git-ref> is required.\n" . self::usage());
        }

        if ($mode === self::MODE_CASE_WORKER && ($caseWorker === null || $caseWorker === '' || $workerOutput === null || $workerOutput === '' || $workerLabel === null || $workerLabel === '' || $workerTree === null)) {
            throw new GateError('The internal case worker requires --case-worker, --worker-output, --worker-label and --worker-tree.');
        }

        // Deriving a declaration from a narrowed corpus deletes the rows the run
        // did not measure — and with them the `reason` column, the one thing a
        // run cannot produce. A PARTIAL run may not write a tracked declaration
        // at all, and that holds for the normalization list word for word: a
        // rule no narrowed run exercised leaves as stale, and the next full run
        // is then judged against a list measured from part of the corpus.
        $deriving = [self::MODE_DERIVE_DECLARED_DELTA => '--derive-declared-delta', self::MODE_DERIVE_NORMALIZATION => '--derive-normalization'];

        if (isset($deriving[$mode]) && ($cases !== [] || $incomplete)) {
            throw new GateError(\sprintf(
                "%s measures the whole corpus or nothing: it rewrites a tracked declaration, and a run narrowed by"
                . " --cases= or --incomplete-corpus would delete the rows it did not measure along with anything"
                . " written against them. Drop the narrowing, or edit the declaration by hand and let a full run"
                . " judge it.\n%s",
                $deriving[$mode],
                self::usage(),
            ));
        }

        return new self(
            $mode,
            $candidate,
            $reference,
            $cases,
            $report,
            $incomplete,
            $jobs,
            $caseWorker,
            $workerOutput,
            $workerLabel,
            $workerTree,
            $workerReverseInput,
        );
    }

    public static function usage(): string
    {
        return <<<'TEXT'
            Usage: php scripts/finding-gate.php [options]

            Exit codes: 0 GREEN (full corpus, equivalent), 1 RED (a failure class fired),
            2 PARTIAL (nothing failed, but the run claims no equivalence), 3 the gate could not run,
            4 a declaration was written (--derive-*, never a verdict), 5 the run it would have been
            derived from failed, so nothing was written, 128+n a signal stopped the run (130 SIGINT,
            143 SIGTERM), which also refuses a --derive-* write.

              --reference=<git-ref>   The tree to prove equivalence against (required for a comparison).
              --candidate=<path>      The tree under test. Default: this checkout.
              --cases=<a,b>           Restrict the corpus to these cases. Default: all.
                                      A restricted run reports PARTIAL and exits 2, never GREEN.
              --report=<file>         Write the machine-readable outcome as JSON.
              --jobs=<1-16>            Number of independent corpus cases to run at once. Default: 4.
                                      Commands within one case and the three tree waves stay ordered. Use 1 to
                                      reproduce the serial schedule.
              --incomplete-corpus     Report a coverage shortfall as a warning instead of a failure.
                                      Only for a corpus that does not claim the whole declared set yet.
                                      Such a run reports PARTIAL and exits 2, never GREEN.
              --derive-tuple          Regenerate finding-gate/equivalence-tuple.tsv from the publishing code.
              --derive-normalization  Regenerate finding-gate/normalization.tsv by measuring two runs.
              --derive-declared-delta Regenerate finding-gate/declared-delta.tsv and its diff files by measuring
                                      every surface that differs from --reference. The `reason` column of an
                                      existing row is kept; a new row gets "?" and the run refuses to load it
                                      until someone writes why the surface changed.
              --self-test             Check the gate's own map and normalization mechanics.
            TEXT;
    }

    private static function value(string $argument): string
    {
        $position = strpos($argument, '=');

        return $position === false ? '' : substr($argument, $position + 1);
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(
            explode(',', $value),
            static fn(string $item): bool => $item !== '',
        ));
    }

    private static function directory(string $path): string
    {
        $resolved = realpath($path);

        if ($resolved === false || !is_dir($resolved)) {
            throw new GateError(\sprintf('No such directory: %s.', $path));
        }

        return $resolved;
    }

    private static function jobs(string $value): int
    {
        if (filter_var($value, \FILTER_VALIDATE_INT) === false) {
            throw new GateError(\sprintf('--jobs must be an integer between 1 and %d.', self::MAX_JOBS));
        }

        $jobs = (int) $value;

        if ($jobs < 1 || $jobs > self::MAX_JOBS) {
            throw new GateError(\sprintf('--jobs must be an integer between 1 and %d.', self::MAX_JOBS));
        }

        return $jobs;
    }
}
