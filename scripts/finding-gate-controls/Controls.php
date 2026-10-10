<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use LogicException;
use QmxFindingGate\Declarations;
use QmxFindingGate\DeclaredFields;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Wiring;

/**
 * The controls, as a list.
 *
 * The suite combines negative controls for declared deltas, reference
 * vocabulary, excessive deltas, lost multi-level coverage, unmapped channels,
 * splits, aggregate spellings, licensed field moves, derivation failures and
 * undeclared report-value renames. Green controls prove the environment works
 * and that declared renames are absorbed only by their declarations. They also
 * cover root configuration keys and published-order permutations
 * ({@see FindingControls::publishedOrderPermuted()}). The declared report-value translation remains effective
 * for JSON record pairs; a declared whole publication keeps its own exact comparison.
 * Each subject's controls live in its own class; this one fixes their order,
 * which is the order of the harness's table.
 *
 * {@see DeclaredDeltaControls::deriveRefusesBrokenRun()} physically checks
 * unchanged declarations after an invalid captured publication
 * ({@see Control::writing()}). A complete comparison with semantic residuals
 * can still write independent measurements. Its counterpart
 * {@see DeclaredDeltaControls::deriveWritesOnAGreenRun()} checks that a
 * successful derivation wrote the expected files ({@see Control::rewriting()}).
 *
 * `delta-too-large` must be observed red by a control because the code computes
 * its count; merely naming the failure class does not prove that branch works.
 *
 * Every expectation — required and tolerated alike — pins the surface it must
 * land on. An unpinned class would let an unrelated failure elsewhere in the
 * corpus satisfy the control, or be absorbed by it, which is exactly the "green
 * for the wrong reason" this harness exists to rule out. Pins are substrings, so
 * `case:design` covers every surface of that case including its baseline file,
 * while `case:smells|baseline-file` covers one artifact and nothing else.
 *
 * A toleration also has to be *used*. One that matched nothing in its own
 * control's run is a claim about a blast radius that nothing supports, and it
 * fails the run exactly as `map-stale` does — so each control's lists are what
 * the mutations measurably produce, not what they might. See
 * Outcome::idleTolerations().
 */
final class Controls
{
    /**
     * @param array<string, string> $forcedExpectations control id => failure class
     *
     * @return list<Control>
     */
    public static function all(array $forcedExpectations = []): array
    {
        $controls = [
            FindingControls::positive(),
            RenameControls::renameWithoutMap(),
            FindingControls::substitutedCeiling(),
            FindingControls::changedFindingCount(),
            CoverageControls::removedFixture(),
            CoverageControls::lostLevelFixture(),
            DeclaredDeltaControls::deltaMismatch(),
            DeclaredDeltaControls::deltaTooLarge(),
            RenameControls::referenceInputUntranslated(),
            FingerprintControls::fingerprintUnexplained(),
            FindingControls::publishedOrderPermuted(),
            RenameControls::splitRowIdle(),
            RenameControls::movedAggregatedSpelling(),
            DeclaredDeltaControls::fieldMoveStale(),
            DeclaredDeltaControls::deriveRefusesBrokenRun(),
            DeclaredDeltaControls::deriveWritesOnAGreenRun(),
            RenameControls::rootKeyRenamed(),
            RenameControls::reportValueRenamed(),
            RenameControls::reportValueWithoutRow(),
        ];

        // Each declaration form's own controls follow the fixed table, from its
        // wiring file, in the order the forms are listed there.
        foreach (Wiring::gate()->factories('controls', __NAMESPACE__) as $factory) {
            $control = $factory();

            if (!$control instanceof Control) {
                throw new LogicException('A control registered in a wiring file returned no Control.');
            }

            $controls[] = $control;
        }

        return array_map(
            static fn(Control $control): Control => self::force($control, $forcedExpectations),
            $controls,
        );
    }

    /** @return list<Expectation> */
    public static function fieldValueToleration(?string $root = null): array
    {
        $fields = DeclaredFields::load(($root ?? \dirname(__DIR__, 2)) . '/finding-gate');
        foreach (DeclaredFields::REPORTS as $report) {
            foreach ($fields->views($report) as $view) {
                if ($fields->derived($report, $view) !== []) {
                    return [new Expectation(FailureClass::FIELD_VALUES_MISMATCH, DeclaredFields::DERIVED, exactScope: true)];
                }
            }
        }
        return [];
    }

    /** @return list<Expectation> */
    public static function valueToleration(?string $root = null): array
    {
        return DeclaredValues::load(($root ?? \dirname(__DIR__, 2)) . '/finding-gate')->derived() === []
            ? []
            : [new Expectation(FailureClass::VALUE_MISMATCH, DeclaredValues::DERIVED, exactScope: true)];
    }

    public static function hasSurfaceDeclaration(string $scope, ?string $root = null): bool
    {
        $declarations = Declarations::load($root ?? \dirname(__DIR__, 2));
        return $declarations->delta->hasSurfaceIntention($scope) || $declarations->exactSurfaces->has($scope);
    }

    public static function changedSurface(string $scope, ?string $root = null): Expectation
    {
        return new Expectation(
            self::hasSurfaceDeclaration($scope, $root) ? FailureClass::DELTA_MISMATCH : FailureClass::SURFACE_MISMATCH,
            $scope,
            exactScope: true,
        );
    }

    /** @param list<string> $scopes
     * @return list<Expectation>
     */
    public static function oversizedDeclaredSurfaces(array $scopes, ?string $root = null): array
    {
        $delta = Declarations::load($root ?? \dirname(__DIR__, 2))->delta;
        return array_map(
            static fn(string $scope): Expectation => new Expectation(FailureClass::DELTA_TOO_LARGE, $scope, exactScope: true),
            array_values(array_filter($scopes, static fn(string $scope): bool => $delta->hasSurfaceIntention($scope))),
        );
    }

    /**
     * Replaces a control's declared expectation with another class, so the
     * harness can be shown to fail on a deliberately wrong expectation. A
     * harness nobody has seen fail proves as little as a gate nobody has seen
     * go red.
     *
     * @param array<string, string> $forced
     */
    private static function force(Control $control, array $forced): Control
    {
        $failureClass = $forced[$control->id] ?? null;

        if ($failureClass === null) {
            return $control;
        }

        return Control::red(
            $control->id,
            $control->subject . ' [forced expectation]',
            $control->mutation,
            [new Expectation($failureClass)],
            $control->tolerated,
        );
    }
}
