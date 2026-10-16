<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Accumulates the outcome and renders it for a human and for a machine.
 *
 * Three verdicts, not two. A run that was narrowed — a subset of the corpus, a
 * coverage shortfall downgraded by `--incomplete-corpus` — has proved something
 * real about the surfaces it did compare, but it has not proved
 * finding-equivalence, and a later step's DoD must not be able to cite it as if
 * it had. So a narrowed run says PARTIAL and exits 2: a distinct word and a
 * distinct exit code, neither of which reads as the full claim.
 */
final class GateReport
{
    public const VERDICT_GREEN = 'green';
    public const VERDICT_PARTIAL = 'partial';
    public const VERDICT_RED = 'red';

    public const EXIT_GREEN = 0;
    public const EXIT_RED = 1;
    public const EXIT_PARTIAL = 2;

    /** @var list<array{class: string, scope: string, detail: string, diff: list<string>}> */
    private array $failures = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $limits = [];

    /** @var array<string, mixed> */
    private array $facts = [];

    /** @var array<string,true> */
    private array $semanticResiduals = [];

    /** @var array<string,bool> */
    private array $sourceEvidence = [];

    /** @var array<int,array{side:string,key:string,role:string}> */
    private array $sourceFailures = [];

    public function sourceEvidence(string $side, string $key, string $role, bool $valid, string $pass = 'first'): void
    {
        $address = implode("\0", [$side, $pass, $key, $role]);
        $this->sourceEvidence[$address] = ($this->sourceEvidence[$address] ?? true) && $valid;
    }

    public function sourceValid(string $side, string $key, string $role, string $pass = 'first'): bool
    {
        return $this->sourceEvidence[implode("\0", [$side, $pass, $key, $role])] ?? false;
    }

    public function sourceRejected(string $side, string $key, string $role, string $pass = 'first'): bool
    {
        return ($this->sourceEvidence[implode("\0", [$side, $pass, $key, $role])] ?? null) === false;
    }

    public function semanticResidual(string $surface): void
    {
        $this->semanticResiduals[$surface] = true;
    }

    public function hasSemanticResidual(string $surface): bool
    {
        return isset($this->semanticResiduals[$surface]);
    }

    /**
     * How many surfaces this run compared against a declaration rather than for
     * equality, so the verdict sentence can name them.
     */
    private int $declaredDeltaCount = 0;

    private int $declaredExactSurfaceCount = 0;

    private int $exactSurfaceUsedCount = 0;

    /**
     * How many moves of a compared field this run licensed rather than refused.
     *
     * Counted for the same reason the deltas are, and it was prose before: a
     * declaration that lets a surface differ has to be visible to a machine, or
     * a control cannot hold a green run to the number the repository declares.
     */
    private int $fieldMoveCount = 0;

    /**
     * The other declaration forms a run can be green under, by the report key
     * each count is published as, in the order the verdict sentence names them.
     *
     * @var array<string, string> report key => what one unit of it is
     */
    public const array DECLARATION_COUNTS = [
        'declaredRecordCount' => 'declared record(s)',
        'declaredValueCount' => 'declared value intent(s)',
        'declaredFieldCount' => 'declared field change(s)',
        'declaredSurfaceCount' => 'declared surface change(s)',
        'structuralMapCount' => 'structural map row(s)',
    ];

    /** @var array<string, int> */
    private array $declarationCounts = [];

    public function countDeclarations(string $reportKey, int $count): void
    {
        if (!isset(self::DECLARATION_COUNTS[$reportKey])) {
            throw new GateError(\sprintf('Unknown declaration count "%s".', $reportKey));
        }

        $this->declarationCounts[$reportKey] = $count;
    }

    public function countDeclaredDeltas(int $count): void
    {
        $this->declaredDeltaCount = $count;
    }

    public function countExactSurfaces(int $count): void
    {
        $this->declaredExactSurfaceCount = $count;
    }

    public function usedExactSurface(): void
    {
        ++$this->exactSurfaceUsedCount;
    }

    public function countFieldMoves(int $count): void
    {
        $this->fieldMoveCount = $count;
    }

    /** @param list<string> $diff
     * @param array{side:string,key:string,role:string}|null $source
     */
    public function fail(string $failureClass, string $scope, string $detail, array $diff = [], ?array $source = null): void
    {
        if (!\in_array($failureClass, FailureClass::ALL, true)) {
            throw new GateError(\sprintf('Unknown failure class "%s".', $failureClass));
        }

        $index = \count($this->failures);
        $this->failures[] = ['class' => $failureClass, 'scope' => $scope, 'detail' => $detail, 'diff' => $diff];
        if ($source !== null) {
            $this->sourceFailures[$index] = $source;
        }

    }

    /** @return list<array{class:string,scope:string,detail:string}> */
    public function raised(): array
    {
        return array_map(static fn(array $failure): array => array_intersect_key($failure, array_flip(['class', 'scope', 'detail'])), $this->failures);
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** Records why this run cannot make the full claim, whatever else it proves. */
    public function limit(string $reason): void
    {
        $this->limits[] = $reason;
    }

    public function fact(string $key, mixed $value): void
    {
        $this->facts[$key] = $value;
    }

    public function verdict(): string
    {
        if ($this->failures !== []) {
            return self::VERDICT_RED;
        }

        return $this->limits === [] ? self::VERDICT_GREEN : self::VERDICT_PARTIAL;
    }

    public function exitCode(): int
    {
        return match ($this->verdict()) {
            self::VERDICT_GREEN => self::EXIT_GREEN,
            self::VERDICT_PARTIAL => self::EXIT_PARTIAL,
            default => self::EXIT_RED,
        };
    }

    /** @return list<string> */
    public function failureClasses(): array
    {
        return array_values(array_unique(array_column($this->failures, 'class')));
    }

    /** @param list<string> $formFailures failures invalidating this form's measurement */
    public function canDerive(array $formFailures = []): bool
    {
        return $this->limits === [] && array_intersect($this->failureClasses(), [
            FailureClass::ENV_MISMATCH, FailureClass::CORPUS_INVALID, FailureClass::RUN_FAILED,
            FailureClass::CANDIDATE_INPUT_REFUSED, FailureClass::REFERENCE_INPUT_UNTRANSLATED,
            ...$formFailures,
        ]) === [];
    }

    public function canDeriveExact(): bool
    {
        if ($this->limits !== []) {
            return false;
        }
        foreach ($this->failures as $index => $failure) {
            if (\in_array($failure['class'], [FailureClass::ENV_MISMATCH, FailureClass::CORPUS_INVALID, FailureClass::CANDIDATE_INPUT_REFUSED, FailureClass::REFERENCE_INPUT_UNTRANSLATED], true)) {
                return false;
            }
            if ($failure['class'] === FailureClass::RUN_FAILED) {
                $source = $this->sourceFailures[$index] ?? null;
                if ($source === null || !$this->sourceRejected($source['side'], $source['key'], $source['role'])) {
                    return false;
                }
            }
        }
        return true;
    }

    public function render(): string
    {
        $lines = [];

        foreach ($this->facts as $key => $value) {
            $lines[] = \sprintf('  %-22s %s', $key, self::scalar($value));
        }

        foreach ($this->warnings as $warning) {
            $lines[] = '  WARNING  ' . $warning;
        }

        foreach ($this->failures as $failure) {
            $lines[] = \sprintf('  FAIL [%s] %s', $failure['class'], $failure['scope']);
            $lines[] = '    ' . $failure['detail'];

            foreach ($failure['diff'] as $diffLine) {
                $lines[] = '      ' . $diffLine;
            }
        }

        $lines[] = match ($this->verdict()) {
            // Every declaration named, not just the maps: a run with declared
            // deltas is GREEN too, and this is the one sentence a later DoD
            // quotes. "Under the declared maps" read as if nothing else had been
            // waived.
            self::VERDICT_GREEN => \sprintf(
                '  GREEN — the two trees are finding-equivalent under the declared maps%s%s.',
                $this->declaredDeltaCount === 0 && $this->fieldMoveCount === 0 && $this->exactSurfaceUsedCount === 0
                    ? ''
                    : \sprintf(
                        ' and %d declared delta(s), %d licensed field move(s)%s',
                        $this->declaredDeltaCount,
                        $this->fieldMoveCount,
                        $this->exactSurfaceUsedCount === 0 ? '' : \sprintf(', %d exact surface(s)', $this->exactSurfaceUsedCount),
                    ),
                $this->otherDeclarations(),
            ),
            self::VERDICT_PARTIAL => \sprintf(
                "  PARTIAL — no equivalence is claimed: %s.\n"
                . '  A PARTIAL run is not evidence of finding-equivalence; only a GREEN full-corpus run is.',
                implode('; ', $this->limits),
            ),
            default => \sprintf('  RED — %d failure(s): %s', \count($this->failures), implode(', ', $this->failureClasses())),
        };

        return implode("\n", $lines) . "\n";
    }

    public function writeJson(string $path): void
    {
        $payload = [
            'verdict' => $this->verdict(),
            'exitCode' => $this->exitCode(),
            'green' => $this->verdict() === self::VERDICT_GREEN,
            'facts' => $this->facts,
            'limits' => $this->limits,
            'warnings' => $this->warnings,
            'failures' => $this->failures,
            'failureClasses' => $this->failureClasses(),
            // Read by the controls harness: a control that declares the gate
            // stays GREEN under a declared map row has to be able to assert that
            // it stayed green without a declared delta absorbing the difference.
            'declaredDeltaCount' => $this->declaredDeltaCount,
            'declaredExactSurfaceCount' => $this->declaredExactSurfaceCount,
            'exactSurfaceUsedCount' => $this->exactSurfaceUsedCount,
            'fieldMoveCount' => $this->fieldMoveCount,
            ...$this->declarationCounts(),
        ];

        Fs::write($path, json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR) . "\n");
    }

    /** @return array<string, int> every declaration count, zero where none was declared */
    private function declarationCounts(): array
    {
        $counts = [];

        foreach (array_keys(self::DECLARATION_COUNTS) as $reportKey) {
            $counts[$reportKey] = $this->declarationCounts[$reportKey] ?? 0;
        }

        return $counts;
    }

    private function otherDeclarations(): string
    {
        $named = [];

        foreach ($this->declarationCounts() as $reportKey => $count) {
            if ($count !== 0) {
                $named[] = $count . ' ' . self::DECLARATION_COUNTS[$reportKey];
            }
        }

        return $named === [] ? '' : ', with ' . implode(', ', $named);
    }

    private static function scalar(mixed $value): string
    {
        if (\is_array($value)) {
            return implode(', ', array_map(self::scalar(...), $value));
        }

        return \is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
    }
}
