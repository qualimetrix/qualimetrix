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

    /**
     * How many surfaces this run compared against a declaration rather than for
     * equality, so the verdict sentence can name them.
     */
    private int $declaredDeltaCount = 0;

    /**
     * How many moves of a compared field this run licensed rather than refused.
     *
     * Counted for the same reason the deltas are, and it was prose before: a
     * declaration that lets a surface differ has to be visible to a machine, or
     * a control cannot hold a green run to the number the repository declares.
     */
    private int $fieldMoveCount = 0;

    public function countDeclaredDeltas(int $count): void
    {
        $this->declaredDeltaCount = $count;
    }

    public function countFieldMoves(int $count): void
    {
        $this->fieldMoveCount = $count;
    }

    /** @param list<string> $diff */
    public function fail(string $failureClass, string $scope, string $detail, array $diff = []): void
    {
        if (!\in_array($failureClass, FailureClass::ALL, true)) {
            throw new GateError(\sprintf('Unknown failure class "%s".', $failureClass));
        }

        $this->failures[] = ['class' => $failureClass, 'scope' => $scope, 'detail' => $detail, 'diff' => $diff];
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
                '  GREEN — the two trees are finding-equivalent under the declared maps%s.',
                $this->declaredDeltaCount === 0 && $this->fieldMoveCount === 0
                    ? ''
                    : \sprintf(
                        ' and %d declared delta(s), %d licensed field move(s)',
                        $this->declaredDeltaCount,
                        $this->fieldMoveCount,
                    ),
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
            'fieldMoveCount' => $this->fieldMoveCount,
        ];

        Fs::write($path, json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
    }

    private static function scalar(mixed $value): string
    {
        if (\is_array($value)) {
            return implode(', ', array_map(self::scalar(...), $value));
        }

        return \is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
    }
}
