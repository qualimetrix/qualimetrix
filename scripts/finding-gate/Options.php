<?php

declare(strict_types=1);

namespace QmxFindingGate;

final class Options
{
    public const MODE_COMPARE = 'compare';
    public const MODE_DERIVE_TUPLE = 'derive-tuple';
    public const MODE_DERIVE_NORMALIZATION = 'derive-normalization';
    public const MODE_SELF_TEST = 'self-test';

    /** @param list<string> $cases */
    private function __construct(
        public readonly string $mode,
        public readonly string $candidateRoot,
        public readonly ?string $reference,
        public readonly array $cases,
        public readonly ?string $reportPath,
        public readonly bool $incompleteCorpus,
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

        foreach (\array_slice($argv, 1) as $argument) {
            $value = self::value($argument);

            match (true) {
                $argument === '--derive-tuple' => $mode = self::MODE_DERIVE_TUPLE,
                $argument === '--derive-normalization' => $mode = self::MODE_DERIVE_NORMALIZATION,
                $argument === '--self-test' => $mode = self::MODE_SELF_TEST,
                $argument === '--incomplete-corpus' => $incomplete = true,
                str_starts_with($argument, '--candidate=') => $candidate = self::directory($value),
                str_starts_with($argument, '--reference=') => $reference = $value,
                str_starts_with($argument, '--cases=') => $cases = self::list($value),
                str_starts_with($argument, '--report=') => $report = $value,
                default => throw new GateError(\sprintf("Unknown argument \"%s\".\n%s", $argument, self::usage())),
            };
        }

        if ($mode === self::MODE_COMPARE && $reference === null) {
            throw new GateError("--reference=<git-ref> is required.\n" . self::usage());
        }

        return new self($mode, $candidate, $reference, $cases, $report, $incomplete);
    }

    public static function usage(): string
    {
        return <<<'TEXT'
            Usage: php scripts/finding-gate.php [options]

            Exit codes: 0 GREEN (full corpus, equivalent), 1 RED (a failure class fired),
            2 PARTIAL (nothing failed, but the run claims no equivalence), 3 the gate could not run.

              --reference=<git-ref>   The tree to prove equivalence against (required for a comparison).
              --candidate=<path>      The tree under test. Default: this checkout.
              --cases=<a,b>           Restrict the corpus to these cases. Default: all.
                                      A restricted run reports PARTIAL and exits 2, never GREEN.
              --report=<file>         Write the machine-readable outcome as JSON.
              --incomplete-corpus     Report a coverage shortfall as a warning instead of a failure.
                                      Only for a corpus that does not claim the whole declared set yet.
                                      Such a run reports PARTIAL and exits 2, never GREEN.
              --derive-tuple          Regenerate finding-gate/equivalence-tuple.tsv from the publishing code.
              --derive-normalization  Regenerate finding-gate/normalization.tsv by measuring two runs.
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
}
