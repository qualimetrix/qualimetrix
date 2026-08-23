<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

/**
 * The controls, as a list.
 *
 * Four negative controls — the four the Ш1 DoD names — plus the positive one
 * without which four reds could all be reds for an environmental reason.
 *
 * Every expectation — required and tolerated alike — pins the surface it must
 * land on. An unpinned class would let an unrelated failure elsewhere in the
 * corpus satisfy the control, or be absorbed by it, which is exactly the "green
 * for the wrong reason" this harness exists to rule out. Pins are substrings, so
 * `case:design` covers every surface of that case including its baseline file,
 * while `case:smells|baseline-file` covers one artifact and nothing else.
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
            self::positive(),
            self::renameWithoutMap(),
            self::substitutedCeiling(),
            self::changedFindingCount(),
            self::removedFixture(),
        ];

        return array_map(
            static fn(Control $control): Control => self::force($control, $forcedExpectations),
            $controls,
        );
    }

    private static function positive(): Control
    {
        return Control::green('positive', 'an unmutated hardlink clone of this working tree');
    }

    /**
     * The channel key moves and no map declares it. Both halves of the key's
     * construction are mutated together, so this is a rename of the channel
     * rather than a rule that emits something it does not declare.
     *
     * The rule name is deliberately left alone: several cases address
     * `cohesion.lcom` through `--rule-opt`, and renaming the rule would make
     * the run fail on an unknown rule instead of comparing two vocabularies.
     *
     * The blast radius, enumerated rather than gestured at. `cohesion.lcom` is
     * claimed by exactly one case, `complexity`, so the surface diff and the
     * broken `channels` claim both land there. Beyond that case the rename
     * takes the corpus out of balance in both directions at once — one declared
     * channel now fires nowhere, one fired channel is declared nowhere — and
     * puts the container at odds with the tracked declaration fixture. The
     * global `qmx rules` listing is tolerated too, because it enumerates the
     * vocabulary independently of any case.
     */
    private static function renameWithoutMap(): Control
    {
        return Control::red(
            'rename-no-map',
            'a channel renamed in product code with finding-gate/maps/channels.tsv left empty',
            Mutation::edit(
                'src/Analysis/Evidence/Cohesion/LcomRule.php',
                [
                    '(new ViolationChannel(self::NAME, self::NAME))' => "(new ViolationChannel(self::NAME, 'cohesion.lcom4'))",
                    'violationCode: self::NAME,' => "violationCode: 'cohesion.lcom4',",
                ],
                'channel cohesion.lcom#cohesion.lcom -> cohesion.lcom#cohesion.lcom4',
            ),
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity')],
            [
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:complexity'),
                new Expectation(FailureClass::COVERAGE_SHORTFALL, 'corpus'),
                new Expectation(FailureClass::COVERAGE_SURPLUS, 'corpus'),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
                ),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'tree|rules'),
            ],
        );
    }

    /**
     * The sharpest of the four: the *set* of findings is untouched and only the
     * magnitude `baseline:generate` records as the accepted ceiling moves, so
     * only a gate that compares more than finding identity can see it.
     *
     * The magnitude perturbed is `code-smell.unused-private`'s class-wide
     * count, because that channel is the one place in the corpus where the
     * recorded magnitude provably cannot decide whether a finding exists: the
     * rule emits one finding per unused member and its severity is the fixed
     * constant `Severity::Warning`, so no threshold reads the number at all.
     * That is why FINDING_COUNT_MISMATCH is *not* tolerated here — a count
     * change would mean the mutation was the wrong one, not that the gate
     * misbehaved.
     *
     * The magnitude is published, not only recorded, so the `smells` case's
     * finding surfaces carry it as well as its baseline file. That is one
     * toleration pinned to that one case; a surface diff in any other case
     * would mean this mutation reached further than it claims.
     *
     * Rejected alternative, measured 2026-08-23: perturbing the Maintainability
     * Index coefficient (5.2 -> 5.3) did move the ceiling, but it also *added*
     * a finding — `computed.health#health.maintainability @ ns:CorpusStore` sat
     * just above the `health` case's threshold of 100 and dropped under it. Any
     * perturbation of a metric that feeds a computed dimension has that
     * boundary problem; a magnitude nothing compares does not.
     */
    private static function substitutedCeiling(): Control
    {
        return Control::red(
            'substituted-ceiling',
            'the recorded ceiling moves while the set of findings stays the same',
            Mutation::edit(
                'src/Analysis/Evidence/CodeSmell/UnusedPrivateRule.php',
                ['metricValue: $total,' => 'metricValue: $total + 1,'],
                'the unused-private count each finding records is one higher',
            ),
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells|baseline-file')],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells')],
        );
    }

    /**
     * One finding fewer, everything else equal: the rule keeps firing on the
     * remaining two subjects of the `design` case, so no channel disappears and
     * no claim changes — only the count. A finding that stops being published
     * stops being recorded too, so that case's surfaces and its baseline file
     * move with it; both are inside the single `case:design` toleration.
     */
    private static function changedFindingCount(): Control
    {
        return Control::red(
            'changed-finding-count',
            'one finding fewer, with the channel set unchanged',
            Mutation::edit(
                'src/Analysis/Evidence/Size/PropertyCountRule.php',
                ['        return $violations;' => '        return \array_slice($violations, 1);'],
                'size.property-count drops its first finding',
            ),
            [new Expectation(FailureClass::FINDING_COUNT_MISMATCH, 'case:design')],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:design')],
        );
    }

    /**
     * The control on the gate's *input*. Both sides run the candidate's corpus,
     * so a shrunken corpus produces no surface difference at all: this can only
     * be caught by the coverage and case-claim checks, which is the whole point
     * of their existing.
     *
     * `smells/src/Dead.php` is the removal target because it is the only fixture
     * in the corpus that fires `code-smell.unreachable-code`, so its loss
     * genuinely narrows what the gate proves. Nothing is tolerated: both sides
     * run the candidate's corpus, so a fixture missing from it is missing from
     * both, and no surface or count can differ. The `layers` case would not do:
     * its layer-policy diagnostics are computed from the policy and the import
     * edge rather than from the target file, so removing a fixture there does
     * not always shrink the channel set.
     */
    private static function removedFixture(): Control
    {
        return Control::red(
            'removed-fixture',
            'a fixture removed from the corpus, i.e. the gate\'s own input narrowed',
            Mutation::delete(
                'finding-gate/cases/smells/src/Dead.php',
                'the only fixture firing code-smell.unreachable-code is gone',
            ),
            [
                new Expectation(FailureClass::COVERAGE_SHORTFALL, 'corpus'),
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:smells'),
            ],
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
