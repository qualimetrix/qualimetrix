<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;
use RuntimeException;

/**
 * The controls on the declared maps: a rename without its row, a row that explains nothing, and every kind
 * of name a row translates — channels, split halves, aggregated spellings, root keys, report values, case
 * inputs.
 */
final class RenameControls
{
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
     * Two tolerations were declared here and never fired, measured over a full
     * PASS run on 2026-08-24: `coverage-shortfall` and `coverage-surplus`. Both
     * were an argument about a different mutation: this one renames the channel
     * at its *declaration*, so the declared set moves with the observed one and
     * the corpus stays balanced in both directions.
     *
     * A third was declared and DID fire, on the opposite reading from the one
     * that named it. `RulesCommand` now also prints each producer's own channels —
     * `cohesion.lcom4 judges cohesion.lcom` — so the renamed half of that line
     * moves `tree|rules` too where it did not before; and the tree that
     * measurement ran against had a declared delta for `tree|rules`
     * (`finding-gate/declared-delta.tsv`, withdrawn on this branch at
     * `e7d8c9ae`), which {@see ChannelRenamePlants::producerListingToleration()} reads to decide
     * whether the reach is tolerated at all. The 2026-08-24 omission was
     * correct for ITS tree, where the declaration covered the reach; it is not
     * a false premise being corrected, but a fact whose value changed on both
     * axes. With the delta absent, {@see ChannelRenamePlants::producerListingToleration()} is reused
     * rather than a plain `Expectation`
     * hardcoded here — its docblock's premise ("only a control renaming a
     * producer needs this") was true of the two controls it was written for
     * and false of this one, but its actual *logic* — the reach is
     * `surface-mismatch` only where the step under test declares nothing for
     * `tree|rules` — does not depend on which half of a printed line moved, and
     * is exactly what this control needs too.
     */
    public static function renameWithoutMap(): Control
    {
        return Control::red(
            'rename-no-map',
            'a channel renamed in product code with no finding-gate/maps/channels.tsv row naming it',
            ChannelRenamePlants::lcomChannelMutation(),
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity')],
            [
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:complexity'),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'governance/Channel/Fixtures/declared.txt',
                ),
                ...ChannelRenamePlants::producerListingToleration(),
            ],
        );
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
     * cannot rename `design.noc` when the declaration already covers a delta on
     * `tree|rules`: a renamed producer moves that listing,
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
    public static function referenceInputUntranslated(): Control
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
                    'governance/Channel/Fixtures/declared.txt',
                ),
            ],
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
     * The product change is {@see ChannelRenamePlants::unusedPrivateChannelMutation()}, the one the
     * fingerprint pair already measured, so the only thing this control varies
     * is the declaration. The map declares the rename as a **split**, which is
     * what makes the rule half untranslatable: with no substitution left, the
     * `smells` case's surfaces and the `qmx rules` listing differ, and those two
     * are the mutation's whole measured radius here — the claim and the tracked
     * declaration fixture move with the rename, exactly as the green twin moves
     * them, so neither the claim check nor the witness has anything to say.
     */
    public static function splitRowIdle(): Control
    {
        return Control::red(
            'split-row-idle',
            'a row of a declared split that explained nothing, beside one that explained every record',
            ChannelRenamePlants::unusedPrivateChannelMutation()
                ->and(ChannelRenamePlants::unusedPrivateRenameDeclarations())
                ->and(ChannelRenamePlants::trackedChannelMapPlus(
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
            // this control. {@see ChannelRenamePlants::producerListingToleration()}.
            [
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells'),
                ...ChannelRenamePlants::producerListingToleration(),
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
    public static function splitWithoutRow(): Control
    {
        return Control::red(
            'split-no-row',
            'a finding whose rule is a declared split half, with no declared row naming its key',
            ChannelRenamePlants::trackedChannelMapPlus(
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
    public static function movedAggregatedSpelling(): Control
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
     * A root configuration key renamed in product code, translated by the
     * fourth `inputs.tsv` shape — a document key with its trailing colon.
     *
     * `suppress_namespaces` is the cheapest key to rename: its root-level
     * writing occurs exactly once in the corpus,
     * `finding-gate/cases/rule-exclusion-ledger/qmx.yaml`, so the mutation
     * reaches one product file plus that one corpus file — `suppress_paths`
     * would have needed fifteen. The corpus file is edited alongside the schema
     * because the corpus belongs to the candidate: a mutated schema left facing
     * an un-renamed root key in its own corpus would refuse it with exit 3
     * before the reference side is even reached.
     *
     * What the schema edit renames, and what it deliberately leaves alone.
     * `ConfigSchema::SUPPRESS_NAMESPACES` is the flat RESULT key the loader
     * produces after normalization — an internal identifier, not what a
     * document writes — so it stays `suppress_namespaces` untouched. What a
     * document writes is `ENTRIES`' camelCase SOURCE path, matched after the
     * loader's own generic snake_case-to-camelCase pass, and `sectionPolicies()`
     * keys its entry by that same source path: renaming one without the other
     * throws `LogicException` the moment the root key is read at all, which is
     * how this was measured to need both.
     *
     * The per-rule key of the same spelling, nested under
     * `rules: {code-smell.long-parameter-list: {...}}` in the same file, is
     * deliberately left alone: it is a different mechanism, keyed by
     * `RuleOptionsFactory`'s own alias resolution rather than by `ENTRIES`, and
     * the mutation does not touch it.
     */
    public static function rootKeyRenamed(): Control
    {
        return Control::greenWith(
            'root-key-renamed',
            'the root configuration key suppress_namespaces is renamed, translated by an inputs.tsv row written as the document spells it',
            self::rootKeyMutation()->and(ChannelRenamePlants::trackedMapPlus(
                'inputs.tsv',
                ["suppress_namespaces:\tsuppress_ns:\tthe root configuration key's document spelling is renamed"],
                "the step's own rows, plus the row that declares this control's renamed root key",
            )),
        );
    }

    /** The rename {@see rootKeyRenamed()} declares and {@see reportValueWithoutRow()}'s sibling controls do not need. */
    private static function rootKeyMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Configuration/ConfigSchema.php',
            [
                "['suppressNamespaces', self::SUPPRESS_NAMESPACES, self::LIST, null]," => "['suppressNs', self::SUPPRESS_NAMESPACES, self::LIST, null],",
                "'suppressNamespaces' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE," => "'suppressNs' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,",
            ],
            "the root key's document spelling is renamed: the ENTRIES source path and its normalization policy, both keyed by that spelling rather than by the SUPPRESS_NAMESPACES result-key constant",
        )->and(Mutation::renameRootKeyInCorpus(
            'suppress_namespaces',
            'suppress_ns',
            'every case addressing the root key writes the new name at root indent; the per-rule key of the same'
                . ' spelling, nested under rules:, is untouched',
        ));
    }

    /**
     * A suppressed report value renamed in product code, translated by
     * `report-values.tsv`'s quoted-only substitution.
     *
     * `SuppressionMechanism::NamespaceSuppression` is renamed rather than a
     * mechanism the corpus actually fires, because the declaration does not need
     * one: {@see \Qualimetrix\Reporting\Formatter\Suppressed\SuppressedFormatter}
     * prints every mechanism's value in `mechanisms` and as a key of
     * `byMechanism` in every case's `format:suppressed` surface, whether or not
     * that case's own suppressions ever use it — so the row fires everywhere and
     * cannot go stale.
     */
    public static function reportValueRenamed(): Control
    {
        return Control::greenWith(
            'report-value-renamed',
            'a suppressed report value is renamed, translated by a quoted-only report-values.tsv row',
            self::reportValueMutation()->and(ChannelRenamePlants::trackedMapPlus(
                'report-values.tsv',
                ["namespace-suppression\tnamespace-block\tthe control renames the mechanism value"],
                'a report-values row declaring the control\'s renamed value',
            )),
        );
    }

    /** The rename {@see reportValueRenamed()} declares and {@see reportValueWithoutRow()} leaves undeclared. */
    private static function reportValueMutation(): Mutation
    {
        return Mutation::edit(
            'src/Reporting/FindingProjection/SuppressionMechanism.php',
            ["case NamespaceSuppression = 'namespace-suppression';" => "case NamespaceSuppression = 'namespace-block';"],
            'the NamespaceSuppression report value is renamed',
        );
    }

    /**
     * The same report-value rename as {@see reportValueRenamed()}, with no
     * `report-values.tsv` row — the class no control had watched fire for this
     * map.
     *
     * The blast radius is every case's `format:suppressed` surface, and only
     * that surface: `mechanisms` and `byMechanism` print every value in every
     * case regardless of whether that case's own findings were ever suppressed
     * by it, so the mutation reaches all fourteen cases uniformly and nothing
     * else — no finding, no count, no other format reads this string.
     */
    public static function reportValueWithoutRow(): Control
    {
        return Control::red(
            'report-value-no-row',
            'a suppressed report value renamed with no report-values.tsv row naming it',
            self::reportValueMutation(),
            self::surfaceMismatchOnEverySuppressedFormat(),
        );
    }

    /**
     * Every case's `format:suppressed` surface, derived from the corpus rather
     * than listed — for the reason {@see DeclaredDeltaControls::surfaceMismatchOnEveryCaseButHealth()}
     * states: a hand-written list goes stale the day the corpus grows. Unlike
     * that method, no case is excluded: every case's `format:suppressed` output
     * prints the whole mechanism vocabulary, `health` included.
     *
     * @return list<Expectation>
     */
    private static function surfaceMismatchOnEverySuppressedFormat(): array
    {
        $root = \dirname(__DIR__, 2) . '/finding-gate/cases';
        $entries = scandir($root);

        if ($entries === false) {
            throw new RuntimeException(\sprintf('No corpus at %s, so this control cannot state its blast radius.', $root));
        }

        $required = [];

        foreach ($entries as $entry) {
            if (!is_file($root . '/' . $entry . '/case.json')) {
                continue;
            }

            $required[] = new Expectation(FailureClass::SURFACE_MISMATCH, 'case:' . $entry . '|format:suppressed');
        }

        return $required;
    }
}
