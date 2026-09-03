<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;
use RuntimeException;

/** What one control's gate run produced, and whether that is what it declared. */
final class Outcome
{
    /** @var list<string> */
    public array $reasons = [];

    /** @var list<string> */
    public array $matched = [];

    /** @var list<string> */
    public array $tolerated = [];

    /** @var list<string> */
    public array $unexpected = [];

    /**
     * Declared tolerations no failure of this run matched.
     *
     * A toleration is a claim about a mutation's blast radius, and until now
     * nothing checked that the radius was real. An idle one is the same defect
     * as `map-stale` and `normalization-stale`: an unfalsifiable claim that
     * quietly widens what the control is willing to swallow — and it widens it
     * in the one direction that matters, because whatever it names stops being
     * an unexpected failure the day the product starts producing it.
     *
     * A toleration whose only overlap is with a *required* expectation counts as
     * idle too: the required expectation is what absorbed those failures, so the
     * toleration proved nothing.
     *
     * @var list<string>
     */
    public array $idleTolerations = [];

    private function __construct(
        public readonly Control $control,
        public readonly int $exitCode,
        public readonly bool $asDeclared,
    ) {}

    public static function crashed(Control $control, string $reason): self
    {
        $outcome = new self($control, -1, asDeclared: false);
        $outcome->reasons[] = $reason;

        return $outcome;
    }

    /**
     * @param array{stdout: string, stderr: string, exit: int} $run
     * @param list<string> $declaredSurfaces the surfaces the step declares a delta for
     * @param bool $declarationReplaced whether this control's mutation rewrote the declaration index
     * @param list<string> $touched declared survivors the run changed after all
     */
    public static function of(
        Control $control,
        array $run,
        string $reportPath,
        array $declaredSurfaces = [],
        bool $declarationReplaced = false,
        array $touched = [],
    ): self {
        $failures = self::failures($reportPath, $run);
        $reasons = [];
        $matched = $tolerated = $unexpected = [];

        foreach ($failures as $pair) {
            [$failureClass, $scope] = $pair;
            $label = $failureClass . ' @ ' . $scope;

            if (self::satisfiesAny($control->required, $failureClass, $scope)) {
                $matched[] = $label;
            } elseif (!$control->expectsGreen && $control->tolerates($failureClass, $scope)) {
                $tolerated[] = $label;
            } elseif (!$control->expectsGreen && self::isDeclarationNoise($failureClass, $scope, $declaredSurfaces, $declarationReplaced)) {
                // The step declares this surface, and this class is a statement
                // about that declaration rather than about the mechanism under
                // test. Bounded by class on purpose: see isDeclarationNoise().
                $tolerated[] = $label . ' (declared surface)';
            } else {
                $unexpected[] = $label;
            }
        }

        foreach ($control->required as $expectation) {
            if (!self::satisfies($expectation, $failures)) {
                $reasons[] = 'expected failure absent: ' . $expectation->label();
            }
        }

        // Unconditional on purpose: a green tree exits 0, full stop. Any waiver
        // here — known noise, an explained diff, anything — turns the positive
        // control into one that passes on a red gate.
        if ($control->expectsGreen && $run['exit'] !== 0) {
            $reasons[] = \sprintf('expected exit 0, got %d', $run['exit']);
        }

        // A green control asserts that the *map row* absorbed the change, and
        // the exit code cannot tell that apart from a surface absorbed by a
        // declared delta — GREEN is GREEN either way. The assertion is therefore
        // on the count, and the count it is held to is the one the repository
        // already declares, not zero: once a step declares a delta of its own,
        // an unmutated tree legitimately compares those surfaces against it, and
        // demanding zero would fail the positive control for being correct.
        // What stays forbidden is a green control introducing a delta beyond
        // that baseline. A control that replaced the index declares its own
        // baseline, so there is nothing to hold it to.
        if ($control->expectsGreen && !$declarationReplaced) {
            $declared = self::declaredDeltaCount($reportPath);
            $baseline = \count($declaredSurfaces);

            if ($declared !== $baseline) {
                $reasons[] = $declared === null
                    ? 'the gate report does not state how many surfaces were compared against a declared delta, so'
                        . ' "green without a delta of its own" cannot be asserted'
                    : \sprintf(
                        'expected the %d declared delta(s) this repository states and no more; %d surface(s) were'
                        . ' compared against one',
                        $baseline,
                        $declared,
                    );
            }
        }

        if (!$control->expectsGreen && $run['exit'] === 0) {
            $reasons[] = 'the gate stayed green on a planted breakage';
        }

        if ($unexpected !== []) {
            $reasons[] = 'failure(s) the mutation does not explain: ' . implode('; ', $unexpected);
        }

        // A write mode's whole subject. The report can say "nothing was
        // written" while the tree holds what was written, and no reading of the
        // report can tell the two apart.
        if ($touched !== []) {
            $reasons[] = 'the run rewrote what it declared it would leave alone: ' . implode(', ', $touched);
        }

        $idle = self::idleTolerations($control, $failures);

        if ($idle !== []) {
            $reasons[] = 'declared toleration(s) nothing matched, so the stated blast radius is unproven: '
                . implode('; ', $idle);
        }

        $outcome = new self($control, $run['exit'], $reasons === []);
        $outcome->reasons = $reasons;
        $outcome->matched = $matched;
        $outcome->tolerated = $tolerated;
        $outcome->unexpected = $unexpected;
        $outcome->idleTolerations = $idle;

        return $outcome;
    }

    /**
     * Whether a failure on a declared surface is noise from the declaration
     * rather than evidence about the control.
     *
     * Two conditions, and the second one is the repair. Scope alone was not
     * enough: four classes carry the bare surface key as their scope, so
     * tolerating "anything on a declared surface" swallowed
     * `delta-too-large` — the one class of the five that no control has ever
     * seen red, and whose code this step rewrote — along with
     * `nondeterminism-undeclared` and a `surface-mismatch` meaning "one tree
     * produced this surface and the other did not". None of those is a
     * statement about a declaration.
     *
     * `Control` already argues at length that class-only toleration was a hole,
     * and refuses an unpinned toleration in its constructor. This is the mirror
     * of that argument: scope-only toleration is the same hole facing the other
     * way, and both halves have to be named.
     *
     * `surface-mismatch` is the one class that needs the third condition. It
     * lands on a declared surface for two unrelated reasons: because the
     * control *replaced* the declaration index, leaving the surface undeclared
     * and therefore compared for equality — noise — or because a tree failed to
     * produce that artifact at all, which is exactly the kind of failure a
     * control must not swallow. The two are told apart by asking whether this
     * control's own mutation rewrote the index.
     *
     * @param list<string> $declaredSurfaces
     */
    private static function isDeclarationNoise(
        string $failureClass,
        string $scope,
        array $declaredSurfaces,
        bool $declarationReplaced,
    ): bool {
        if (!\in_array($scope, $declaredSurfaces, true)) {
            return false;
        }

        // `field-move-stale` joins `surface-mismatch` on the third condition
        // rather than the second, and for the same reason: a control that
        // replaced the declaration index left the surface compared for equality,
        // so no diff line reaches the licence and every row of the step's own
        // `declared-field-moves.tsv` reads as stale through no fault of the
        // mechanism under test. With the index intact a stale licence is real.
        if ($failureClass === FailureClass::SURFACE_MISMATCH || $failureClass === FailureClass::FIELD_MOVE_STALE) {
            return $declarationReplaced;
        }

        return \in_array($failureClass, [
            FailureClass::DELTA_MISMATCH,
            FailureClass::DELTA_STALE,
            FailureClass::DELTA_OVERREACH,
        ], true);
    }

    /**
     * @param list<array{0: string, 1: string}> $failures
     *
     * @return list<string>
     */
    private static function idleTolerations(Control $control, array $failures): array
    {
        if ($control->expectsGreen) {
            return [];
        }

        $idle = [];

        foreach ($control->tolerated as $expectation) {
            foreach ($failures as [$failureClass, $scope]) {
                if ($expectation->matches($failureClass, $scope)
                    && !self::satisfiesAny($control->required, $failureClass, $scope)
                ) {
                    continue 2;
                }
            }

            $idle[] = $expectation->label();
        }

        return $idle;
    }

    /** @return list<string> */
    public function observedClasses(): array
    {
        $classes = [];

        foreach ([...$this->matched, ...$this->tolerated, ...$this->unexpected] as $label) {
            $classes[] = substr($label, 0, (int) strpos($label, ' @ '));
        }

        return array_values(array_unique($classes));
    }

    /** @param list<Expectation> $expectations */
    private static function satisfiesAny(array $expectations, string $failureClass, string $scope): bool
    {
        foreach ($expectations as $expectation) {
            if ($expectation->matches($failureClass, $scope)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: string, 1: string}> $failures */
    private static function satisfies(Expectation $expectation, array $failures): bool
    {
        foreach ($failures as [$failureClass, $scope]) {
            if ($expectation->matches($failureClass, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many surfaces the run compared against a declaration rather than for
     * equality, or `null` when the report does not say.
     *
     * `null` is not "none": a renamed field would otherwise read as a run with
     * no declared delta, which is exactly the claim this number exists to
     * support.
     */
    private static function declaredDeltaCount(string $reportPath): ?int
    {
        if (!is_file($reportPath)) {
            return null;
        }

        $decoded = json_decode(Shell::read($reportPath), true);
        $count = \is_array($decoded) ? $decoded['declaredDeltaCount'] ?? null : null;

        return \is_int($count) ? $count : null;
    }

    /**
     * @param array{stdout: string, stderr: string, exit: int} $run
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function failures(string $reportPath, array $run): array
    {
        if (!is_file($reportPath)) {
            throw new RuntimeException(\sprintf(
                "The gate wrote no report (exit %d). Its output was:\n%s\n%s",
                $run['exit'],
                $run['stdout'],
                $run['stderr'],
            ));
        }

        $decoded = json_decode(Shell::read($reportPath), true);

        if (!\is_array($decoded) || !\is_array($decoded['failures'] ?? null)) {
            throw new RuntimeException('The gate report has no failures section.');
        }

        $failures = [];

        foreach ($decoded['failures'] as $failure) {
            if (\is_array($failure)) {
                $failures[] = [(string) ($failure['class'] ?? '?'), (string) ($failure['scope'] ?? '?')];
            }
        }

        return $failures;
    }
}
