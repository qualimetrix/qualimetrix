<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

/**
 * The controls on the findings themselves: how many there are, what they record and in which order they
 * are published — and the positive control, where none of that moves.
 */
final class FindingControls
{
    /**
     * A candidate that publishes two of its own findings in each other's places.
     *
     * The gate now translates the reference's names and puts its records back
     * into the order those names give ({@see \QmxFindingGate\PublishedOrder}),
     * because a channel rename moves a finding past its neighbours and the
     * translated reference would otherwise carry the new vocabulary in the old
     * order. That mechanism is the reason this control has to exist: until it
     * landed, a permutation could only ever be a byte difference, and no control
     * had to ask what the gate does when one appears.
     *
     * The mutation swaps the two findings of `Router::normalise` — same subject,
     * same (absent) occurrence, same (absent) edge, different channel — so the
     * only thing that distinguishes the mutated candidate from an honest one is
     * where those two records sit. A gate that re-sorted *both* sides instead of
     * asserting each side's own order would put them back and go green here, and
     * that is the hole this control is pointed at. What must happen instead is
     * `published-order-drift`: the candidate is not in the order of its own sort
     * key, which is a product fact, and the gate says so rather than sorting it
     * away.
     *
     * The byte difference on the same surface is tolerated rather than required:
     * it is what the permutation looked like before the mechanism existed, and
     * it is still produced, because a side that failed the order assertion is
     * deliberately compared unsorted.
     */
    public static function publishedOrderPermuted(): Control
    {
        $surface = 'case:complexity|format:json';
        // Anchored on the return rather than on the `usort` call, and the
        // replacement returns through `array_values` rather than restating the
        // matched line: a fragment edit is asserted to have *removed* what it
        // matched, so a replacement containing its own anchor would read as a
        // mutation that never landed.
        $anchor = '        return $findings;';
        $swap = <<<'PHP'
                    $first = null;

                    foreach ($findings as $index => $finding) {
                        if (!\str_contains($finding->subject->toCanonical(), 'Corpus\\Complexity\\Router::normalise')) {
                            continue;
                        }

                        if ($first === null) {
                            $first = $index;

                            continue;
                        }

                        [$findings[$first], $findings[$index]] = [$findings[$index], $findings[$first]];

                        break;
                    }

                    return \array_values($findings);
            PHP;

        return Control::red(
            'published-order-permuted',
            'a candidate publishing two findings of one subject in each other\'s places',
            Mutation::edit(
                'src/Reporting/Formatter/Json/JsonFindingSection.php',
                [$anchor => $swap],
                'the JSON report publishes the two findings of Corpus\\Complexity\\Router::normalise'
                . " in each other's places",
            ),
            [new Expectation(FailureClass::PUBLISHED_ORDER_DRIFT, $surface)],
            [new Expectation(FailureClass::SURFACE_MISMATCH, $surface)],
        );
    }

    public static function positive(): Control
    {
        return Control::green('positive', 'an unmutated hardlink clone of this working tree');
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
    public static function substitutedCeiling(): Control
    {
        return Control::red(
            'substituted-ceiling',
            'the recorded ceiling moves while the set of findings stays the same',
            self::ceilingMutation(),
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
    public static function changedFindingCount(): Control
    {
        return Control::red(
            'changed-finding-count',
            'one finding fewer, with the channel set unchanged',
            self::droppedFindingMutation(),
            [new Expectation(FailureClass::FINDING_COUNT_MISMATCH, 'case:design')],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:design')],
        );
    }

    public static function droppedFindingMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/Size/PropertyCountRule.php',
            ['        return $findings;' => '        return \array_slice($findings, 1);'],
            'size.property-count drops its first finding',
        );
    }

    /** The ceiling perturbation, shared by the control that measured it and the delta control. */
    public static function ceilingMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/CodeSmell/UnusedPrivateRule.php',
            ['metricValue: $total,' => 'metricValue: $total + 1,'],
            'the unused-private count each finding records is one higher',
        );
    }
}
