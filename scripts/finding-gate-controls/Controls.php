<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

/**
 * The controls, as a list.
 *
 * The suite combines negative controls for declared deltas, reference
 * vocabulary, excessive deltas, lost multi-level coverage, fingerprints,
 * splits, aggregate spellings, licensed field moves, derivation failures and
 * undeclared report-value renames. Green controls prove the environment works
 * and that declared renames are absorbed only by their declarations. They also
 * cover root configuration keys, report-value translations, occurrence hashes
 * that must remain stable
 * ({@see FingerprintControls::occurrenceFrozenUnderDeclaredRename()}), and
 * published-order permutations ({@see FindingControls::publishedOrderPermuted()}).
 * Each subject's controls live in its own class; this one fixes their order,
 * which is the order of the harness's table.
 *
 * {@see DeclaredDeltaControls::deriveRefusesBrokenRun()} and
 * {@see DeclaredDeltaControls::deriveWritesOnAGreenRun()} are the only controls
 * whose subject is not in the report at all. A derivation that
 * failed prints "nothing was written", and what had to be checked was whether
 * that was true ({@see Control::writing()}); a derivation that succeeded
 * prints what it wrote, and what had to be checked was whether it wrote it
 * ({@see Control::rewriting()}).
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
            DeclaredDeltaControls::deltaStale(),
            DeclaredDeltaControls::deltaOverreach(),
            DeclaredDeltaControls::deltaTooLarge(),
            RenameControls::referenceInputUntranslated(),
            FingerprintControls::fingerprintUnexplained(),
            FingerprintControls::fingerprintSelfDisagreement(),
            FingerprintControls::fingerprintDeclaredRename(),
            FingerprintControls::occurrenceFrozenUnderDeclaredRename(),
            FindingControls::publishedOrderPermuted(),
            RenameControls::splitRowIdle(),
            RenameControls::splitWithoutRow(),
            RenameControls::movedAggregatedSpelling(),
            DeclaredDeltaControls::fieldMoveStale(),
            DeclaredDeltaControls::deriveRefusesBrokenRun(),
            DeclaredDeltaControls::deriveWritesOnAGreenRun(),
            RenameControls::rootKeyRenamed(),
            RenameControls::reportValueRenamed(),
            RenameControls::reportValueWithoutRow(),
        ];

        return array_map(
            static fn(Control $control): Control => self::force($control, $forcedExpectations),
            $controls,
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
