<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

/**
 * The controls, as a list.
 *
 * Ten negative controls — the four the Ш1 DoD names, the four Ш4a adds for the
 * declared delta and the reference's vocabulary, the one Ш4b adds for
 * `delta-too-large` and the one P5.0 adds for a lost level of a multi-level
 * channel — plus the positive one without which ten reds could all be reds for
 * an environmental reason.
 *
 * `delta-too-large` was the one class of the five that no control had ever seen
 * red. Ш4a named the gap in its own record; Ш4b rewrote the code that computes
 * the count, which is the worst moment to still be relying on the name of a
 * class nobody has watched fire.
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
 * fails the run exactly as `map-stale` does — so the lists below are what the
 * mutations measurably produce, not what they might. See
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
            self::positive(),
            self::renameWithoutMap(),
            self::substitutedCeiling(),
            self::changedFindingCount(),
            self::removedFixture(),
            self::lostLevelFixture(),
            self::deltaMismatch(),
            self::deltaStale(),
            self::deltaOverreach(),
            self::deltaTooLarge(),
            self::referenceInputUntranslated(),
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
     * The rule name is deliberately left alone: the `complexity` case addresses
     * `cohesion.lcom` through `--rule-opt`, and renaming the rule would make
     * the run fail on an unknown rule instead of comparing two vocabularies.
     * That failure is a mechanism of its own, and the `reference-input` control
     * is where it is proved.
     *
     * The blast radius, enumerated rather than gestured at — and trimmed to what
     * a run actually produces, because a toleration nothing matches is now a
     * failed control (see Outcome::idleTolerations()). `cohesion.lcom` is claimed
     * by exactly one case, `complexity`, so the surface diff and the broken
     * `channels` claim both land there, and the container stops agreeing with the
     * tracked declaration fixture.
     *
     * Three tolerations were declared here and never fired, measured over a full
     * PASS run on 2026-08-24: `coverage-shortfall`, `coverage-surplus` and a
     * surface diff on the `qmx rules` listing. The first two were an argument
     * about a different mutation: this one renames the channel at its
     * *declaration*, so the declared set moves with the observed one and the
     * corpus stays balanced in both directions. The third assumed the rules
     * listing prints channel codes; it prints rule names and option tokens, and
     * the rule name is deliberately left alone here.
     */
    private static function renameWithoutMap(): Control
    {
        return Control::red(
            'rename-no-map',
            'a channel renamed in product code with finding-gate/maps/channels.tsv left empty',
            self::lcomChannelMutation(),
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity')],
            [
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:complexity'),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
                ),
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
     * expectations is the assertion: the channel is still declared and still
     * observed, so the claim is the only place this can be seen.
     */
    private static function lostLevelFixture(): Control
    {
        return Control::red(
            'lost-level-fixture',
            'the corpus stops producing one level of a channel that keeps firing at its other levels',
            Mutation::edit(
                'finding-gate/cases/health/qmx.yaml',
                ['    levels: [class, namespace, project]' => '    levels: [namespace, project]'],
                'computed.branch_load stops being computed per class, and keeps firing per namespace and project',
            ),
            [new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:health')],
        );
    }

    /**
     * A declared delta that does not state the difference it covers.
     *
     * The product perturbation is the ceiling control's, because it is the one
     * whose blast radius is already measured. The declaration planted next to it
     * covers the baseline file — where the perturbation lands — with a diff no
     * measurement produced. A delta the gate does not recompute would make every
     * later step's delta a rubber stamp, so the mismatch has to be a failure of
     * its own rather than an absent surface diff.
     */
    private static function deltaMismatch(): Control
    {
        return Control::red(
            'delta-mismatch',
            'a declared delta whose diff is not the one the run measures',
            self::ceilingMutation()->and(self::declare(
                'case:smells|baseline-file',
                'the ceiling control\'s perturbation, declared with a diff nothing measured',
            )),
            [new Expectation(FailureClass::DELTA_MISMATCH, 'case:smells|baseline-file')],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells')],
        );
    }

    /**
     * A declared delta on a surface the two trees agree on.
     *
     * No product code is perturbed, so this is the positive control with the
     * declaration *replaced*: one row added on a surface that does not differ,
     * and the step's own rows removed with it. Both halves show up in the run —
     * the added row as `delta-stale`, the removed ones as the declared surfaces
     * being compared for equality again — which is why this control tolerates
     * them there (see Outcome::isDeclarationNoise()). The lie under test is the
     * added row: the same lie as a map row that translated nothing, and it has
     * to fail the same way or a delta could outlive the change it described.
     */
    private static function deltaStale(): Control
    {
        return Control::red(
            'delta-stale',
            'a delta declared for a surface that did not change',
            self::declare('case:smells|baseline-file', 'a delta declared where nothing differs'),
            [new Expectation(FailureClass::DELTA_STALE, 'case:smells|baseline-file')],
        );
    }

    /**
     * A declared delta reaching a field the equivalence tuple compares.
     *
     * The channel rename is the mutation, because the half it moves *is* the
     * `code` field of every finding it produces — and with no split declared,
     * nothing explains that record. Without this seam the first user of a
     * declared delta would have had to breach it: the plan counts nine bare
     * occurrences of one renamed half across `json` and `html`, all of them the
     * `rule` field. The delta is also not the measured one, which is tolerated on
     * that same surface: reach is judged on what the run measures, so a
     * declaration that overreaches must fail for overreaching rather than be
     * excused by also failing to match.
     *
     * It shares the rename control's mutation, so it shared that control's three
     * tolerations that never fired; they are removed here for the same measured
     * reasons, which are stated there.
     */
    private static function deltaOverreach(): Control
    {
        return Control::red(
            'delta-overreach',
            'a declared delta covering a compared field with no split to explain it',
            self::lcomChannelMutation()->and(self::declare(
                'case:complexity|format:json',
                'a renamed code half declared as a delta instead of a map row',
            )),
            [new Expectation(FailureClass::DELTA_OVERREACH, 'case:complexity|format:json')],
            [
                new Expectation(FailureClass::DELTA_MISMATCH, 'case:complexity|format:json'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity'),
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:complexity'),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
                ),
            ],
        );
    }

    /**
     * A declared delta bigger than a declaration may be.
     *
     * The perturbation is a formatter, not a rule: `JsonViolationSection` gains
     * a field on every finding, so every line of the `json` surface of a case
     * moves and the measured diff runs to hundreds of changed lines. The
     * declaration planted beside it names that surface, so the run reaches the
     * size check rather than stopping at "undeclared surface" — and the size
     * check is the whole point, because it is the class this harness had never
     * seen red.
     *
     * The `health` case is the target because it is the largest: two moved
     * lines per finding over its 69 message-bearing findings measure 256 changed
     * lines against a limit of 200, so the control is not sitting on the edge of
     * the threshold it is testing. It is also the one case NOT tolerated for a
     * surface diff: its `json` surface is the declared one, so the failures
     * there are delta classes, and the twelve tolerations name the twelve other
     * cases whose `json` surface moves with the formatter. That toleration was
     * declared and never fired; measured on a full PASS run, 2026-08-24.
     *
     * `delta-mismatch` and `delta-overreach` are tolerated on the same surface
     * for the reason the overreach control gives in reverse: reach and size are
     * judged on the diff the run measures, so a declaration that is too large
     * must fail for being too large and not be excused by also failing to match
     * — and a diff this wide inevitably pairs a moved field against a line that
     * does not carry it, which is overreach by the record-level rule.
     */
    private static function deltaTooLarge(): Control
    {
        return Control::red(
            'delta-too-large',
            'a declared delta whose measured diff is past the limit a declaration may be',
            Mutation::edit(
                'src/Reporting/Formatter/Json/JsonViolationSection.php',
                [
                    "'message' => \$violation->message," => "'message' => '(padded) ' . \$violation->message,",
                    "'recommendation' => \$violation->recommendation," => "'recommendation' => '(padded) ' . \$violation->recommendation,",
                ],
                'two lines of every JSON finding move, which on the largest case is past the declaration limit',
            )->and(self::declare(
                'case:health|format:json',
                'a delta declared for a surface whose measured diff is hundreds of lines',
            )),
            [new Expectation(FailureClass::DELTA_TOO_LARGE, 'case:health|format:json')],
            [
                new Expectation(FailureClass::DELTA_MISMATCH, 'case:health|format:json'),
                new Expectation(FailureClass::DELTA_OVERREACH, 'case:health|format:json'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:annotations'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:coupling'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:cycle'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:design'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:disabled-rule'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:duplication'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:excluded-path'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:layers'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:only-rules'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:security'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells'),
            ],
        );
    }

    /**
     * The reference addressed in a vocabulary it does not have.
     *
     * A rule renamed in product code, and the one case that addresses that rule
     * through `--rule-opt` repointed onto the new name — which is what a step
     * that renames a rule has to do — with no `inputs.tsv` row to restate it for
     * the reference. The reference then refuses its input with the product's
     * config-error exit code, and the point of the control is that this says so
     * instead of arriving as eleven surface diffs and an empty findings section,
     * which would read as a product change.
     *
     * The case's `channels` claim is repointed with the arguments on purpose: the
     * claim is not what is under test here, and leaving it stale would add a
     * failure of a different mechanism to every line of the table. Our own
     * `qmx.yaml` is repointed for a harder reason, measured: the channel probe
     * resolves the candidate tree's own configuration, and that configuration
     * names `cohesion.lcom` — so without this the probe dies on an unknown rule
     * and the gate never gets as far as running anything.
     */
    private static function referenceInputUntranslated(): Control
    {
        return Control::red(
            'reference-input',
            'a case input that needs translating, with no inputs.tsv row to translate it',
            Mutation::edit(
                'src/Analysis/Evidence/Cohesion/LcomRule.php',
                ["public const string NAME = 'cohesion.lcom';" => "public const string NAME = 'cohesion.lcom4';"],
                'the rule and its channel are renamed to cohesion.lcom4',
            )->and(Mutation::edit(
                'finding-gate/cases/complexity/case.json',
                [
                    '"--rule-opt=cohesion.lcom:warning=2"' => '"--rule-opt=cohesion.lcom4:warning=2"',
                    '"--rule-opt=cohesion.lcom:error=4"' => '"--rule-opt=cohesion.lcom4:error=4"',
                    '"--rule-opt=cohesion.lcom:minMethods=2"' => '"--rule-opt=cohesion.lcom4:minMethods=2"',
                    // The claim carries a level, and the mutation deliberately
                    // stops short of it: a control coupled to the level
                    // vocabulary would go stale every time that vocabulary
                    // moves, which is exactly what Ш5 does to it. Matching the
                    // pair's channel half keeps "exactly one occurrence" sharp
                    // without asserting anything about levels.
                    '"cohesion.lcom#cohesion.lcom' => '"cohesion.lcom4#cohesion.lcom4',
                ],
                'the case addresses the new name',
            ))->and(Mutation::edit(
                'qmx.yaml',
                ['  cohesion.lcom:' => '  cohesion.lcom4:'],
                'our own configuration addresses the new name too',
            )),
            [new Expectation(FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'reference / case:complexity')],
            [
                new Expectation(FailureClass::RUN_FAILED, 'reference / complexity'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity'),
                new Expectation(FailureClass::FINDING_COUNT_MISMATCH, 'case:complexity'),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
                ),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'tree|rules'),
            ],
        );
    }

    /** The ceiling perturbation, shared by the control that measured it and the delta control. */
    private static function ceilingMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/CodeSmell/UnusedPrivateRule.php',
            ['metricValue: $total,' => 'metricValue: $total + 1,'],
            'the unused-private count each finding records is one higher',
        );
    }

    /** The channel rename, shared by the map control and the overreach control. */
    private static function lcomChannelMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/Cohesion/LcomRule.php',
            [
                '(new ViolationChannel(self::NAME, self::NAME))' => "(new ViolationChannel(self::NAME, 'cohesion.lcom4'))",
                'violationCode: self::NAME,' => "violationCode: 'cohesion.lcom4',",
            ],
            'channel cohesion.lcom#cohesion.lcom -> cohesion.lcom#cohesion.lcom4',
        );
    }

    /**
     * Plants a declared delta for one surface: the index row plus a diff file no
     * measurement produced.
     */
    /**
     * One declaration, and only it: the index is replaced, so the step's own
     * declared surfaces are removed by the same call. That is deliberate — a
     * control on the declaration mechanism must not also be judged against the
     * step's declaration — and it is why the delta controls' reports carry
     * tolerated failures on those surfaces.
     */
    private static function declare(string $surface, string $reason): Mutation
    {
        $slug = trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', $surface), '-');
        $file = 'declared-delta/' . $slug . '.diff';

        // The index is REPLACED, not created: a step that declares a delta of
        // its own already committed one, and a control's declaration has to be
        // the only row in it whether or not that is so.
        return Mutation::replace(
            ['finding-gate/declared-delta.tsv' => "surface\tfile\treason\n" . $surface . "\t" . $file . "\t" . $reason . "\n"],
            'a delta declared for ' . $surface,
        )->and(Mutation::create(
            [
                'finding-gate/' . $file => "--- candidate\n+++ reference (mapped)\n@@ -1,1 +1,1 @@\n"
                    . "-a line no measurement produced\n+nor did it produce this one\n",
            ],
            'with a diff no measurement produced',
        ));
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
