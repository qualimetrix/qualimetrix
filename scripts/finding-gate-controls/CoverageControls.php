<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

/**
 * The controls on the corpus as the gate's own input: a fixture removed, and one level of a channel lost.
 */
final class CoverageControls
{
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
     * both, and no surface or count can differ.
     *
     * A `map-stale` toleration comes and goes with the step's own map, and it is
     * gone again. It sits here only while some declared row is translated by
     * this fixture alone: the map declares a row per metric key, and
     * `unreachableCode.firstLine` is published by this fixture and by nothing
     * else, so removing the fixture leaves that row translating nothing. Other
     * rows do not name the channel, and an empty map renames nothing, so under an
     * empty map no row can go stale — and a toleration nothing matches fails the
     * control, because it claims a blast radius the run did not prove. Add it
     * back when a step's map declares a row this fixture alone translates.
     *
     * The `layers` case would not do:
     * its layer-policy diagnostics are computed from the policy and the import
     * edge rather than from the target file, so removing a fixture there does
     * not always shrink the channel set.
     */
    public static function removedFixture(): Control
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
     * The other half of the input control: one LEVEL of a multi-level channel
     * loses its evidence while the channel keeps firing.
     *
     * Why this is not covered by `removed-fixture`. A claim used to be a set of
     * channel names, and the observed set was keyed by channel too, so a channel
     * firing at two levels inside one case was one entry on both sides. Take away
     * the evidence for one of those levels and every check still passed: the
     * channel fires, the claim lists it, the coverage union is unchanged — and
     * because both trees read the corpus out of the candidate's case directory,
     * no surface differs either. That is the shape the collapse of the level
     * channels walks straight into.
     *
     * What the mutation is, and why it is not a deleted file. Measured on
     * 2026-08-24 over the whole corpus: the only channels firing at more than one
     * level in one case are the seven `computed.health` ones, and every one of
     * them is computed for every class, so deleting any single fixture of that
     * case leaves the level set untouched (measured for all seven of its files),
     * while deleting the two that carry `health.cohesion` removes the channel
     * outright — which is the old detector, not this one. The level's evidence in
     * this corpus is the `levels:` list of the case's own user-defined computed
     * metric, so that is what is taken away. It is the same kind of loss the
     * plan's wording points at: a case is its fixtures *and* the configuration
     * that fires them, and this drops one level of one channel and nothing else —
     * measured, the channel set is identical before and after.
     *
     * Nothing is tolerated, and the absence of `coverage-shortfall` from the
     * expectations is the assertion. Coverage now counts declared
     * channel-and-level pairs, and for the run-time family the declaration *is*
     * the case's own resolved configuration — the very thing this mutation edits
     * — so the declared pair and its evidence leave together and no shortfall can
     * arise. The claim, written by hand in `case.json`, is the only place the
     * loss shows. For a *static* channel the two do not move together: the levels
     * come from product code and the fixtures from the corpus, and there a lost
     * level is a `coverage-shortfall`. The control therefore
     * control for it needs a static multi-level channel, which this corpus does
     * not have yet.
     */
    public static function lostLevelFixture(): Control
    {
        return Control::red(
            'lost-level-fixture',
            'the corpus stops producing one level of a channel that keeps firing at its other levels',
            Mutation::edit(
                'finding-gate/cases/health/qmx.yaml',
                ['    levels: [class, namespace, project]' => '    levels: [namespace, project]'],
                'the corpus\' user-defined computed metric stops being computed per class, and keeps firing per namespace and project',
            ),
            [new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:health')],
        );
    }
}
