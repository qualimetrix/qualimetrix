<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\DeclaredDelta;
use QmxFindingGate\FailureClass;
use RuntimeException;

/**
 * The controls, as a list.
 *
 * Fifteen negative controls — the four the Ш1 DoD names, the four Ш4a adds for
 * the declared delta and the reference's vocabulary, the one Ш4b adds for
 * `delta-too-large`, the one P5.0 adds for a lost level of a multi-level channel,
 * the two Ш5b0 adds for the fingerprint mechanism, the two Ш5d0 adds for the
 * split mechanism and the one Ш5e3-0 adds for a moved aggregated spelling —
 * plus two green ones: the positive control, without which fifteen reds could
 * all be reds for an environmental reason, and Ш5b0's declared rename, which
 * asserts that a change the maps declare is absorbed by the declaration and by
 * nothing else.
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
            self::fingerprintUnexplained(),
            self::fingerprintSelfDisagreement(),
            self::fingerprintDeclaredRename(),
            self::splitRowIdle(),
            self::splitWithoutRow(),
            self::movedAggregatedSpelling(),
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
            'a channel renamed in product code with no finding-gate/maps/channels.tsv row naming it',
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
                ['        return $findings;' => '        return \array_slice($findings, 1);'],
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
     * both, and no surface or count can differ.
     *
     * A `map-stale` toleration comes and goes with the step's own map, and it is
     * gone again. It sits here only while some declared row is translated by
     * this fixture alone: Ш5e3 declared a row per metric key, and
     * `unreachableCode.firstLine` is published by this fixture and by nothing
     * else, so removing the fixture left that row translating nothing. Ш5c's ten
     * rows never named the channel, and Ш6 renames nothing at all, so under an
     * empty map no row can go stale — and a toleration nothing matches fails the
     * control, because it claims a blast radius the run did not prove. Add it
     * back when a step's map declares a row this fixture alone translates.
     *
     * The `layers` case would not do:
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
     * expectations is the assertion. Coverage now counts declared
     * channel-and-level pairs, and for the run-time family the declaration *is*
     * the case's own resolved configuration — the very thing this mutation edits
     * — so the declared pair and its evidence leave together and no shortfall can
     * arise. The claim, written by hand in `case.json`, is the only place the
     * loss shows. For a *static* channel the two do not move together: the levels
     * come from product code and the fixtures from the corpus, and there a lost
     * level is a `coverage-shortfall`. That is the case Ш5c creates, and the
     * control for it needs a static multi-level channel, which this corpus does
     * not have yet.
     */
    private static function lostLevelFixture(): Control
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
     * The perturbation is a formatter, not a rule: `JsonFindingSection` gains
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
                'src/Reporting/Formatter/Json/JsonFindingSection.php',
                [
                    "'message' => \$finding->message," => "'message' => '(padded) ' . \$finding->message,",
                    "'recommendation' => \$finding->recommendation," => "'recommendation' => '(padded) ' . \$finding->recommendation,",
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
                ...self::surfaceMismatchOnEveryCaseButHealth(),
            ],
        );
    }

    /**
     * The mutation moves two lines of every JSON finding, so every case's JSON
     * surface differs; only `health` is big enough to pass the declaration
     * limit, and only it is required. The rest are tolerated.
     *
     * Derived from the corpus rather than listed, and that is the whole point:
     * the list was written by hand, Ш6 added the `rule-exclusion-ledger` case,
     * and the control failed on a surface the mutation explains perfectly well —
     * "failure(s) the mutation does not explain" pointing at a case the
     * declaration had simply never heard of. A case is a directory holding a
     * `case.json`, the same definition {@see \QmxFindingGate\Corpus::load()}
     * uses, so a corpus that grows again does not invalidate this control.
     *
     * @return list<Expectation>
     */
    private static function surfaceMismatchOnEveryCaseButHealth(): array
    {
        $root = \dirname(__DIR__, 2) . '/finding-gate/cases';
        $entries = scandir($root);

        if ($entries === false) {
            throw new RuntimeException(\sprintf('No corpus at %s, so this control cannot state its blast radius.', $root));
        }

        $tolerated = [];

        foreach ($entries as $entry) {
            if ($entry === 'health' || !is_file($root . '/' . $entry . '/case.json')) {
                continue;
            }

            $tolerated[] = new Expectation(FailureClass::SURFACE_MISMATCH, 'case:' . $entry);
        }

        return $tolerated;
    }

    /**
     * The reference addressed in a vocabulary it does not have.
     *
     * A name the step renamed is written into a case's input with no
     * `inputs.tsv` row to restate it, so the reference — which predates the
     * rename — is handed a token it cannot resolve. It answers with the
     * product's config-error exit code, and the point of the control is that
     * this is reported as its own class instead of arriving as twelve surface
     * diffs and an empty findings section, which reads as a product change.
     *
     * **Why a CHANNEL of a multi-channel rule, and not a rule.** This control
     * used to rename `design.noc`, and Ш5d made that unrunnable: the step
     * declares a delta on `tree|rules`, a renamed producer moves that listing,
     * and an expectation pinned to a surface a declaration covers can never be
     * met — the harness refuses such a control before it clones anything
     * ({@see Control::assertNotPinnedToDeclaredDelta()}). Repinning onto a
     * `delta-*` class was rejected outright: that would assert about the
     * declaration rather than about the reference's vocabulary, which is what
     * this control is for. So the mutation stops reaching the listing at all.
     *
     * It can, because the listing and the channel vocabulary are different sets
     * of names. `bin/qmx rules` prints producer names, their descriptions and
     * their option tokens; it prints no channel codes. `architecture.potential-shadow`
     * is a diagnostic the `architecture.layer-violation` rule emits under a rule
     * name of its own and is not a registered rule, so it appears nowhere in the
     * listing. Measured, not assumed: the listing was captured before and after
     * the one-literal edit below and the two files are byte-identical.
     *
     * **Why the input is a selector, and why in this case.** A selector resolves
     * a producer, a group **or a channel** — measured from the product's own
     * refusal, `Rule selector "…" does not match any registered producer, group,
     * or channel`, which exits 3. `disabled-rule` is the auxiliary case that
     * exists to carry a selector on the gate's input, and no architecture
     * channel fires in it (its config declares no layer policy), so the
     * candidate's own output there is unchanged and the only thing this
     * mutation does to that case is make the *reference* refuse its input.
     *
     * A CLI flag would have been the other shape an `inputs.tsv` row can carry,
     * and it was measured and rejected: an unknown option exits **1**, not 3, so
     * a renamed flag proves `run-failed` rather than this class.
     *
     * The new name keeps the old one's length, as every rename in this file
     * does: two surfaces pad a name-bearing column, and a row cannot declare
     * padding.
     *
     * The `layers` case's claim is repointed with the rename because the claim
     * is not what is under test, and a stale one would add a failure of another
     * mechanism to every line of the table. The tracked declaration fixture is
     * deliberately NOT repointed: the disagreement is this mutation's honest
     * radius and is tolerated as such, exactly as it was when this control
     * renamed a rule.
     */
    private static function referenceInputUntranslated(): Control
    {
        return Control::red(
            'reference-input',
            'a case input that needs translating, with no inputs.tsv row to translate it',
            Mutation::edit(
                'src/Analysis/Policy/Architecture/Contract/LayerPolicyPreparationInterface.php',
                [
                    "POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shadow';"
                        => "POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shado2';",
                ],
                'the channel architecture.potential-shadow is renamed, and the producing rule is left alone',
            )->and(Mutation::edit(
                'finding-gate/cases/disabled-rule/case.json',
                [
                    // The trailing bracket is part of the fragment on purpose: a
                    // Mutation may not leave its own anchor behind, so an added
                    // line has to consume something. The comma is what changes.
                    "\"--disable-rule=code-smell.eval\"\n    ],"
                        => "\"--disable-rule=code-smell.eval\",\n        \"--disable-rule=architecture.potential-shado2\"\n    ],",
                ],
                'the auxiliary selector case addresses the new channel name',
            ))->and(Mutation::edit(
                'finding-gate/cases/layers/case.json',
                ['"architecture.potential-shadow@project"' => '"architecture.potential-shado2@project"'],
                'the case that fires the channel claims it under its new name',
            )),
            [new Expectation(FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'reference / case:disabled-rule')],
            [
                new Expectation(FailureClass::RUN_FAILED, 'reference / disabled-rule'),
                // The reference's run for that case dies, so its HTML artifact
                // has no payload to read. The dead run is the finding; this is
                // its downstream symptom, and it lands on the same case.
                new Expectation(FailureClass::REPORT_PAYLOAD_UNREADABLE, 'case:disabled-rule'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:disabled-rule'),
                new Expectation(FailureClass::FINDING_COUNT_MISMATCH, 'case:disabled-rule'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:layers'),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
                ),
            ],
        );
    }

    /**
     * The identity a consumer tracks moves, and no declared row explains it.
     *
     * The mutation is a channel rename, and the point of this control is *where*
     * the required failure is pinned: on the two surfaces that publish the
     * fingerprint. Ш5b0 stopped comparing the GitLab hash as hex and started
     * comparing the identity it hashes, and a substitution that quietly redacted
     * instead of substituting would leave that surface agreeing with itself
     * under any rename. That is the guard, and this is what watches it.
     *
     * SARIF is required beside it because SARIF publishes the same composition in
     * plain text: the two publications are one mechanism, and a step that hashed
     * the SARIF one too would have to notice that this control now watches only
     * half of it.
     *
     * **Why this control stopped sharing the lcom mutation.** Both required
     * expectations name an exact surface, and one of them named
     * `case:complexity|format:sarif` — which Ш5c declares a delta for. A declared
     * surface is compared against that exact diff and never for equality, so the
     * rename arrived there as `delta-mismatch` and the sarif half of the pair
     * could not fire at all. Two repairs were possible and only one keeps the
     * subject: repinning the sarif expectation onto `delta-mismatch` would assert
     * something about the declaration, so the mutation moves to a channel whose
     * case declares nothing. `code-smell.unused-private` in the `smells` case is
     * that channel — claimed by that one case and named nowhere else in the
     * corpus, and reported there often enough that both publications carry it.
     *
     * The mutation is {@see unusedPrivateChannelMutation()}, the very one {@see
     * fingerprintDeclaredRename()} declares a row for. The pair differs in its
     * declaration and in nothing else — one product change, two declarations,
     * opposite verdicts — and any other arrangement would have the two controls
     * comparing two different changes. {@see ceilingMutation()} perturbs the
     * reported value of the same rule; it touches another fragment of the file
     * and each control runs in its own clone.
     *
     * `tree|rules` is the mutation's other measured reach — it renames a
     * producer and `qmx rules` publishes producer names — and whether that
     * reach is tolerated is not this control's to decide: it depends on what
     * the step under test declares. {@see producerListingToleration()}. It is
     * the one reach the green twin does not have to name, because there the
     * row translates that listing too.
     */
    private static function fingerprintUnexplained(): Control
    {
        return Control::red(
            'fingerprint-no-map',
            'the fingerprinted identity moves with no finding-gate/maps/channels.tsv row naming it',
            self::unusedPrivateChannelMutation(),
            [
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells|format:gitlab'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells|format:sarif'),
            ],
            [
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells'),
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:smells'),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
                ),
                ...self::producerListingToleration(),
            ],
        );
    }

    /**
     * A side that does not agree with itself: the published hash is not the hash
     * of the identity published beside it.
     *
     * This is the class the substitution rests on. The gate replaces a hash with
     * an identity only because it has just proved that this side hashes that
     * identity; salt the hash and the proof fails, which has to be its own
     * failure rather than a surface diff somebody reads as a rename.
     *
     * `fingerprint-opaque` is required next to it, and the pair is the whole
     * argument: the mismatch says the hash is not what it claims, and the opaque
     * class says the comparison therefore could not stop being a comparison of
     * hex. A run producing only the first would mean the substitution went ahead
     * on an unproven pair.
     */
    private static function fingerprintSelfDisagreement(): Control
    {
        return Control::red(
            'fingerprint-self-disagreement',
            'the GitLab fingerprint hashes something other than the identity published beside it',
            Mutation::edit(
                'src/Reporting/Formatter/GitLabCodeQualityFormatter.php',
                ['return md5($finding->getFingerprint());' => "return md5(\$finding->getFingerprint() . '-salted');"],
                'the published hash is the hash of a salted identity',
            ),
            [
                new Expectation(FailureClass::FINGERPRINT_MISMATCH, 'candidate /'),
                new Expectation(FailureClass::FINGERPRINT_OPAQUE, 'candidate /'),
            ],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'format:gitlab')],
        );
    }

    /**
     * A channel rename moves every fingerprint of every finding on it, and one
     * declared row is what makes that green. Registered under "no undeclared
     * deltas" so that a delta creeping back in fails it.
     *
     * **The channel is chosen, not incidental, and Ш5c changed what the choice
     * has to satisfy.** Two constraints, both measured:
     *
     * - *the case must declare no delta of its own.* Ш5c declares one on
     *   `case:complexity|format:sarif` and one on `case:coupling|format:sarif`
     *   — SARIF publishes one rule descriptor per channel, so collapsing the
     *   level pairs removes descriptors and renumbers every `ruleIndex`. A
     *   declared delta is compared as an **exact** diff, so mutating a rule of
     *   either case rewrites that diff and the control would fail as
     *   `delta-mismatch`, saying nothing about fingerprints. That is why this
     *   control no longer lives on `complexity.cyclomatic`, where it used to;
     * - *the code and the published `rule` field must move together.* They used
     *   to be told apart by the level suffix: renaming only the code half was
     *   expressible because the rule half was a shorter, different string. After
     *   Ш5c no static channel's code differs from its rule field, so the only
     *   rename a whole-name row can absorb is one that moves both — which is
     *   what renaming the rule's own `NAME` does, in one place, and which one
     *   row then translates on the reference side. Renaming the code alone
     *   leaves the `rule` field standing while the row rewrites it on the
     *   reference side; measured, that failed here on the smells case's `html`,
     *   `json` and `text-verbose` surfaces and on `tree|rules`, none of which
     *   says anything about fingerprints. The lcom objection this replaces was
     *   never about lcom: it was about a map row rewriting a field the mutation
     *   had left alone. What makes the rename expressible at all is stated where
     *   it is measured, on {@see unusedPrivateChannelMutation()}: the new name is
     *   the same length as the old one, because two of the surfaces pad the
     *   channel column and a row cannot declare padding.
     *
     * `code-smell.unused-private` is claimed by one case and named nowhere else
     * in the corpus, and reports twelve findings in it. Both facts are checked by
     * the gate itself rather than recalled: claims and observations are compared
     * per case in both directions, so a GREEN run is what says no other case
     * fires this channel. {@see ceilingMutation()} perturbs the same rule's
     * reported value from another fragment of the same file, and each control
     * runs in its own clone.
     *
     * One thing this channel does **not** bring, corrected from the claim that
     * stood here: it carries no occurrence key. Measured over the `smells` case's
     * SARIF surface, its twelve findings publish four identities of the form
     * `channel:subject`, three findings to each class. So the substitution is
     * exercised on the two-part shape, and the shape *with* an occurrence is
     * exercised by no control — a gap worth naming rather than a reason to move
     * again.
     *
     * The claim and the tracked declaration fixture move with the rename because
     * they are declarations of the channel, not evidence about it: leaving them
     * stale would make this control fail on two other mechanisms and say nothing
     * about fingerprints. The map is written **whole**, holding this control's one
     * row: a step that renames nothing tracks an empty map, so there is neither a
     * row to anchor an insertion on nor a declaration to withdraw.
     */
    private static function fingerprintDeclaredRename(): Control
    {
        return Control::greenWith(
            'fingerprint-declared-rename',
            'a channel rename that moves every fingerprint, declared as one channels.tsv row',
            // The SAME product change as fingerprint-no-map, which declares no
            // row and must go red. That symmetry is the control: one mutation,
            // two declarations, opposite verdicts — anything else and the pair
            // would be comparing two different changes.
            self::unusedPrivateChannelMutation()
                ->and(self::unusedPrivateRenameDeclarations())
                ->and(self::trackedChannelMapPlus(
                    ["code-smell.unused-private\tcode-smell.unused-privat2\tthe control renames the channel's code"],
                    "the step's own rows, plus the one row that declares this control's rename",
                )),
        );
    }

    /**
     * A row of a declared split that explains nothing, beside one that does.
     *
     * The relaxation this watches: a channel row is credited by the records it
     * explained as well as by the text it substituted, because a row that moves
     * a producer and leaves the code alone has nothing to substitute anywhere —
     * its rule half is one side of the split and is deliberately left
     * untranslated, its code half is the same string on both sides, and no
     * surface prints the whole `rule#code` key. Without the credit `map-stale`
     * would refuse the only shape such a declaration has.
     *
     * The boundary is the point. Credit travels per row and per matched record,
     * so a second row of the same split — declared over a code the product never
     * emits — is idle and must fail, even though the split it belongs to is
     * live. A relaxation granted per split rather than per row would make this
     * control green, which is why it is required rather than derived from the
     * self-test: {@see \QmxFindingGate\SelfTest} proves the accounting on
     * synthetic pairs, and this proves the gate carries it through a real run.
     *
     * The product change is {@see unusedPrivateChannelMutation()}, the one the
     * fingerprint pair already measured, so the only thing this control varies
     * is the declaration. The map declares the rename as a **split**, which is
     * what makes the rule half untranslatable: with no substitution left, the
     * `smells` case's surfaces and the `qmx rules` listing differ, and those two
     * are the mutation's whole measured radius here — the claim and the tracked
     * declaration fixture move with the rename, exactly as the green twin moves
     * them, so neither the claim check nor the witness has anything to say.
     */
    private static function splitRowIdle(): Control
    {
        return Control::red(
            'split-row-idle',
            'a row of a declared split that explained nothing, beside one that explained every record',
            self::unusedPrivateChannelMutation()
                ->and(self::unusedPrivateRenameDeclarations())
                ->and(self::trackedChannelMapPlus(
                    [
                        "code-smell.unused-private#code-smell.unused-private\t"
                            . "code-smell.unused-privat2#code-smell.unused-privat2\t"
                            . 'the producer and its code move together, and this row explains every record of them',
                        "code-smell.unused-private#code-smell.never-emitted\t"
                            . "code-smell.unused-privat3#code-smell.unused-privat3\t"
                            . 'a second half of the same split, over a code the product never emits',
                    ],
                    'the rename is declared as a split, one of whose two rows can explain nothing',
                )),
            [new Expectation(FailureClass::MAP_STALE, 'code-smell.never-emitted')],
            // `tree|rules` moves here too — the mutation renames a producer and
            // the listing prints producer names — and whether that shows up as
            // a `surface-mismatch` depends on the step under test rather than on
            // this control. {@see producerListingToleration()}.
            [
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells'),
                ...self::producerListingToleration(),
            ],
        );
    }

    /**
     * A finding carrying a split half that no declared row names.
     *
     * `split-unmapped` is the class the whole delta of the `rule` field passes
     * through — a split stops the map from translating the half, and what stands
     * in for the translation is a per-record explanation — and no control had
     * ever watched it fire.
     *
     * No product code is perturbed, and that is the sharpest form available: the
     * two trees agree on every surface, so the only thing that can fail is the
     * explanation. The map declares a split of `code-smell.unused-private` into
     * two halves whose *codes* the product never emits, so the twelve findings
     * the `smells` case reports on that channel carry a split half for which no
     * declared row names their key. That is the same state a real step reaches
     * by dropping one row of its map while the finding it accounted for stays,
     * with none of the blast radius a product rename brings.
     *
     * Both rows are `map-stale` too, and tolerated rather than required: they
     * substitute nothing and explain nothing, which is the accounting the idle-row
     * control is about. Requiring it here as well would let this control pass on
     * a run where staleness fired and the explanation did not.
     */
    private static function splitWithoutRow(): Control
    {
        return Control::red(
            'split-no-row',
            'a finding whose rule is a declared split half, with no declared row naming its key',
            self::trackedChannelMapPlus(
                [
                    "code-smell.unused-private#code-smell.never-emitted\t"
                        . "code-smell.split-one#code-smell.split-one\t"
                        . 'one half of a declared split, over a code the product never emits',
                    "code-smell.unused-private#code-smell.never-emitted-either\t"
                        . "code-smell.split-two#code-smell.split-two\t"
                        . 'the other half, so the producer is split and its own key is declared by neither',
                ],
                'a split declared over codes the product never emits, leaving the emitted one unaccounted',
            ),
            [new Expectation(FailureClass::SPLIT_UNMAPPED, 'case:smells')],
            [new Expectation(FailureClass::MAP_STALE, 'channels.tsv')],
        );
    }

    /**
     * A published aggregated spelling moves, and the base key stays exactly
     * where it is.
     *
     * The control on the suffix expansion. A `metric-keys.tsv` row translates
     * `<key>.<strategy>` as well as `<key>`, which is what makes 212 published
     * spellings declarable in 83 rows — and also what could make the expansion a
     * rubber stamp, absorbing a movement in the suffix that no row states. Only
     * the suffix moves here, and it moves for every key that carries it: `cbo`,
     * `ccn` and `cbo_app` stay exactly as they are on every surface, while
     * `cbo.p95` is published as `cbo.pct95`. No row declares that, so it has to
     * be red.
     *
     * The mutation moves the spelling where it is PUBLISHED, and that is the
     * point rather than a convenience. Measured 2026-08-26: changing the
     * separator in `MetricName::agg()` instead — the composition every writer and
     * every reader shares — takes the product down altogether (`run-failed` on
     * all fourteen cases, "the JSON surface carries no findings section", zero
     * observed channels), because the aggregated name is also how the
     * aggregation reads its own weights back. A control that kills the product
     * proves the corpse differs, not that the gate compares metric keys.
     *
     * The dot is kept, and that is the difference between this control and a
     * sloppier one: dropping it (`cbopct95`) would move the boundary as well as
     * the suffix, and then the control would no longer be about a suffix at all.
     * It read `cbopct95` when this control was first written, and two reviewers
     * found it before a run did.
     *
     * `p95` is the strategy moved, and no value changes with it: the built-in
     * health formulas read `coupling.cbo.p95` and `complexity.cognitive.p95` out
     * of the metric bag,
     * not out of this formatter, so the findings, the counts, the claims and the
     * baselines are all identical on both sides. What differs is one published
     * name on one surface — which is exactly the difference the suffix expansion
     * could otherwise absorb.
     */
    private static function movedAggregatedSpelling(): Control
    {
        return Control::red(
            'moved-aggregated-spelling',
            'a published aggregated spelling moves while its base key stays put',
            Mutation::edit(
                'src/Reporting/Formatter/MetricsJsonFormatter.php',
                [
                    "'metrics' => \$metricsArray," => "'metrics' => array_combine(array_map("
                        . "static fn(string \$key): string => str_ends_with(\$key, '.p95')"
                        . " ? substr(\$key, 0, -4) . '.pct95' : \$key,"
                        . " array_keys(\$metricsArray)), \$metricsArray),",
                ],
                'the metrics surface publishes "<key>.pct95" where the product computed "<key>.p95"',
            ),
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity|format:metrics')],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'format:metrics')],
        );
    }

    /**
     * The map a control declares: every row the step tracks, plus the control's
     * own.
     *
     * A control that writes the map whole has to write the step's rows too, and
     * copying them into this file would be a second, silently ageing copy of a
     * tracked declaration. They are read from the tracked file instead, so the
     * only thing stated here is what this control adds.
     *
     * Whole-file, not an insertion: {@see Mutation} refuses an edit whose own
     * anchor survives it, and an appended row leaves whatever it anchored on in
     * place. The reason the step's rows must survive is measured rather than
     * tidy — they declare the split that explains the producer move, and without
     * them the health surfaces the step declares a delta for fail as
     * `delta-overreach`, which for the green control means no green at all.
     *
     * @param list<string> $rows tab-separated old, new, reason
     */
    private static function trackedChannelMapPlus(array $rows, string $description): Mutation
    {
        $path = 'finding-gate/maps/channels.tsv';
        $tracked = @file_get_contents(\dirname(__DIR__, 2) . '/' . $path);

        if ($tracked === false || trim($tracked) === '') {
            throw new RuntimeException(\sprintf(
                'Cannot read %s, so a control cannot state its declaration on top of the step\'s own rows.',
                $path,
            ));
        }

        return Mutation::replace(
            [$path => rtrim($tracked, "\n") . "\n" . implode("\n", $rows) . "\n"],
            $description,
        );
    }

    /** The scope the `bin/qmx rules` listing is captured under. {@see \QmxFindingGate\TreeRun::rules()}. */
    private const PRODUCER_LISTING_SURFACE = 'tree|rules';

    /**
     * The `qmx rules` toleration a control whose mutation renames a **producer**
     * needs — and only such a control, because the listing prints producer
     * names and nothing else: no channel code, no option value. Two controls
     * qualify, both built on {@see unusedPrivateChannelMutation()}; every other
     * mutation in this file either leaves the producing rule's name alone
     * ({@see lcomChannelMutation()}, {@see referenceInputUntranslated()}) or
     * touches no name at all.
     *
     * Whether the reach is a `surface-mismatch` is not a property of the
     * mutation: it is a property of the step under test. A step that declares a
     * delta for the listing has that surface compared against its exact diff and
     * never for equality, so the run reports a delta class there and
     * {@see Outcome::isDeclarationNoise()} absorbs it — and a toleration would
     * then match nothing and fail the control as an unmeasured radius
     * ({@see Outcome::idleTolerations()}). A step that declares nothing gets the
     * plain surface diff, which without a toleration is an unexplained failure.
     * Both readings were reached by measurement, one step apart: Ш5d declared
     * the delta, Ш5e1 withdrew it, and a toleration pinned to either answer is
     * wrong on the other step.
     *
     * So the answer is read from the step's own tracked declaration, the way
     * {@see trackedChannelMapPlus()} reads its rows — through the gate's own
     * loader, and from the repository rather than a scratch tree, exactly as
     * {@see Harness::declaredSurfaces()} does. Membership is exact because that
     * is the rule both the absorber and {@see Control::assertNotPinnedToDeclaredDelta()}
     * apply, and because this pin names one whole surface rather than a prefix
     * of several.
     *
     * One gap is named rather than closed: the answer comes from the
     * repository's tracked declaration, so it does not know about a control
     * that plants a declaration of its own over that file — the exemption
     * {@see Harness::replacesDeclaration()} computes and
     * {@see Control::assertNotPinnedToDeclaredDelta()} honours. Neither
     * control built on {@see unusedPrivateChannelMutation()} touches
     * `declared-delta.tsv`, so today the two answers coincide. A control that
     * combined this mutation with a planted declaration on the producer
     * listing surface would be compared against the planted diff while this
     * helper still read the tracked file: the toleration would match nothing
     * and fail the control as an idle toleration. Such a control has to derive
     * its expectation from what it plants, not from what the repository
     * tracks.
     *
     * @return list<Expectation>
     */
    private static function producerListingToleration(): array
    {
        $root = \dirname(__DIR__, 2) . '/finding-gate';

        $declared = is_file($root . '/' . DeclaredDelta::INDEX)
            ? DeclaredDelta::load($root)->surfaces()
            : [];

        return \in_array(self::PRODUCER_LISTING_SURFACE, $declared, true)
            ? []
            : [new Expectation(FailureClass::SURFACE_MISMATCH, self::PRODUCER_LISTING_SURFACE)];
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

    /**
     * What has to be re-declared when {@see unusedPrivateChannelMutation()}
     * renames the channel: the case's claim and the tracked declaration fixture.
     *
     * Neither is evidence about the channel — both are declarations of it — so a
     * control that left them stale would fail on the claim check and the witness
     * and say nothing about the mechanism it is for. Shared by every control
     * built on that rename so the three cannot drift apart over which
     * declaration they carry.
     */
    private static function unusedPrivateRenameDeclarations(): Mutation
    {
        return Mutation::edit(
            'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
            ['code-smell.unused-private higher class' => 'code-smell.unused-privat2 higher class'],
            'the tracked declaration fixture names the new channel',
        )->and(Mutation::edit(
            'finding-gate/cases/smells/case.json',
            ['"code-smell.unused-private@class"' => '"code-smell.unused-privat2@class"'],
            'the case claims the new channel',
        ));
    }

    /**
     * The channel rename both fingerprint controls apply: one product edit, and
     * the channel code, its declaration key and the published `rule` field all
     * move with it.
     *
     * The rule's `NAME` is that one place — the declaration key, the emitted
     * `code` and the emitted `ruleName` all read it — so renaming it renames the
     * channel without letting the two published fields drift apart. A code-only
     * rename is not expressible: a whole-name row would go on to rewrite the
     * `rule` field the mutation had left alone. Measured on the green control:
     * that variant failed on the smells case's `html`, `json` and `text-verbose`
     * surfaces and on `tree|rules`.
     *
     * **The new name is the same length as the old one, and that is load-bearing
     * rather than tidy.** `qmx rules` and `--format=text-verbose` pad the channel
     * column to a fixed width, so a name one character longer shifts the text
     * beside it by one space — a shift no row can declare, because a row
     * translates a name and not the padding after it. Measured on `qmx rules`
     * alone: `code-smell.unused-private2` leaves exactly one line differing by
     * one space, and `code-smell.unused-privat2` leaves the whole output
     * identical under a single substitution. That is also why this is the shape
     * a real step's rename has to have, or declare a delta for.
     *
     * A private mutation rather than a second caller of {@see
     * lcomChannelMutation()}: the fingerprint pair needs a case that declares no
     * delta, and the two controls that share the lcom mutation both pin their
     * expectations to the whole `case:complexity`, where the declared sarif
     * surface is one format among twelve and cannot swallow the control.
     */
    private static function unusedPrivateChannelMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/CodeSmell/UnusedPrivateRule.php',
            ["public const string NAME = 'code-smell.unused-private';" => "public const string NAME = 'code-smell.unused-privat2';"],
            'channel code-smell.unused-private -> code-smell.unused-privat2, its code and published rule field together',
        );
    }

    /** The channel rename, shared by the map control and the overreach control. */
    private static function lcomChannelMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/Cohesion/LcomRule.php',
            [
                'self::NAME => ChannelDeclaration::magnitude(' => "'cohesion.lcom4' => ChannelDeclaration::magnitude(",
                'code: self::NAME,' => "code: 'cohesion.lcom4',",
            ],
            'channel cohesion.lcom -> cohesion.lcom4, the producing rule name left alone',
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
        // `control-` prefixed on purpose: a step may track a diff for the very
        // surface a control plants one for, and Mutation refuses to CREATE a
        // file the repository already has — measured on Ш5d, whose
        // `case:health|format:json` diff collided with delta-too-large's slug
        // and would have crashed that control at mutation time.
        $slug = trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', $surface), '-');
        $file = 'declared-delta/control-' . $slug . '.diff';

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
