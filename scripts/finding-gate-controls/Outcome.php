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
     */
    public static function of(
        Control $control,
        array $run,
        string $reportPath,
        array $declaredSurfaces = [],
        bool $declarationReplaced = false,
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

        if (!$control->expectsGreen && $run['exit'] === 0) {
            $reasons[] = 'the gate stayed green on a planted breakage';
        }

        if ($unexpected !== []) {
            $reasons[] = 'failure(s) the mutation does not explain: ' . implode('; ', $unexpected);
        }

        $outcome = new self($control, $run['exit'], $reasons === []);
        $outcome->reasons = $reasons;
        $outcome->matched = $matched;
        $outcome->tolerated = $tolerated;
        $outcome->unexpected = $unexpected;

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

        if ($failureClass === FailureClass::SURFACE_MISMATCH) {
            return $declarationReplaced;
        }

        return \in_array($failureClass, [
            FailureClass::DELTA_MISMATCH,
            FailureClass::DELTA_STALE,
            FailureClass::DELTA_OVERREACH,
        ], true);
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
