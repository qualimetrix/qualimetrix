<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\Corpus;
use QmxFindingGate\DeclaredRecords;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\FailureClass;

/**
 * The controls on fingerprints: an identity that moves without a declaration, a hash that disagrees with
 * the identity beside it, and declared renames that must leave fingerprints and occurrences equivalent.
 */
final class FingerprintControls
{
    /**
     * The identity a consumer tracks moves, and no declared row explains it.
     *
     * The mutation is a channel rename, and the point of this control is *where*
     * the required failure is pinned: on the two surfaces that publish the
     * fingerprint. The guard compares the identity behind the GitLab hash, so a
     * substitution that quietly redacted
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
     * `case:complexity|format:sarif`, for which a delta is already declared. A declared
     * surface is compared against that exact diff and never for equality, so the
     * rename arrived there as `delta-mismatch` and the sarif half of the pair
     * could not fire at all. Two repairs were possible and only one keeps the
     * subject: repinning the sarif expectation onto `delta-mismatch` would assert
     * something about the declaration, so the mutation moves to a channel whose
     * fingerprint surfaces declare no delta. `code-smell.unused-private` in
     * the `smells` case remains published on both fingerprint surfaces.
     * Other cases claim the same channel, but their declaration effects are
     * accounted for separately.
     *
     * The mutation is {@see ChannelRenamePlants::unusedPrivateChannelMutation()}, the very one {@see
     * fingerprintDeclaredRename()} declares a row for. The pair differs in its
     * declaration and in nothing else — one product change, two declarations,
     * opposite verdicts — and any other arrangement would have the two controls
     * comparing two different changes. {@see FindingControls::ceilingMutation()} perturbs the
     * reported value of the same rule; it touches another fragment of the file
     * and each control runs in its own clone.
     *
     * `tree|rules` is the mutation's other measured reach — it renames a
     * producer and `qmx rules` publishes producer names — and whether that
     * reach is tolerated is not this control's to decide: it depends on what
     * the step under test declares. {@see ChannelRenamePlants::producerListingToleration()}. It is
     * the one reach the green twin does not have to name, because there the
     * row translates that listing too.
     */
    public static function fingerprintUnexplained(): Control
    {
        return Control::red(
            'fingerprint-no-map',
            'the fingerprinted identity moves with no finding-gate/maps/channels.tsv row naming it',
            ChannelRenamePlants::unusedPrivateChannelMutation(),
            [
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells|format:gitlab'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells|format:sarif'),
                new Expectation(FailureClass::RECORD_UNDECLARED, 'case:smells|format:json', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:baseline-cycle|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:config-precedence|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:directive-placement|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:discovery|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:duplication-size|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:incomplete-directory-symlink|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:only-rules|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:parallel-files|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:scoped-layers|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:stderr-warning|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:suppression|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:threshold-raising|format:suppressed', exactScope: true),
                ...ChannelRenamePlants::caseListingFailures(),
            ],
            [
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells'),
                ...ChannelRenamePlants::unusedPrivateClaimFailures(),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'governance/Channel/Fixtures/declared.txt',
                ),
                ...ChannelRenamePlants::producerListingToleration(),
            ],
        );
    }

    /**
     * A channel rename moves every fingerprint of every finding on it, and one
     * declared row is what makes that green. Registered under "no undeclared
     * deltas" so that a delta creeping back in fails it.
     *
     * **The channel is chosen, not incidental.** Two measured constraints apply:
     *
     * - *the case must declare no delta of its own.* Existing declarations cover
     *   `case:complexity|format:sarif` and one on `case:coupling|format:sarif`
     *   — SARIF publishes one rule descriptor per channel, so collapsing the
     *   level pairs removes descriptors and renumbers every `ruleIndex`. A
     *   declared delta is compared as an **exact** diff, so mutating a rule of
     *   either case rewrites that diff and the control would fail as
     *   `delta-mismatch`, saying nothing about fingerprints. That is why this
     *   control no longer lives on `complexity.cyclomatic`, where it used to;
     * - *the code and the published `rule` field must move together.* They used
     *   to be told apart by the level suffix: renaming only the code half was
     *   expressible because the rule half was a shorter, different string. No
     *   static channel's code now differs from its rule field, so the only
     *   rename a whole-name row can absorb is one that moves both — which is
     *   what renaming the rule's own `NAME` does, in one place, and which one
     *   row then translates on the reference side. Renaming the code alone
     *   leaves the `rule` field standing while the row rewrites it on the
     *   reference side; measured, that failed here on the smells case's `html`,
     *   `json` and `text-verbose` surfaces and on `tree|rules`, none of which
     *   says anything about fingerprints. The lcom objection this replaces was
     *   never about lcom: it was about a map row rewriting a field the mutation
     *   had left alone. What makes the rename expressible at all is stated where
     *   it is measured, on {@see ChannelRenamePlants::unusedPrivateChannelMutation()}: the new name is
     *   the same length as the old one, because two of the surfaces pad the
     *   channel column and a row cannot declare padding.
     *
     * `code-smell.unused-private` reports twelve findings in the `smells` case.
     * Every case claim that names it moves with the declaration, so a green run
     * checks the rename across the current corpus. {@see FindingControls::ceilingMutation()}
     * perturbs the same rule's
     * reported value from another fragment of the same file, and each control
     * runs in its own clone.
     *
     * One thing this channel does **not** bring, corrected from the claim that
     * stood here: it carries no occurrence key. Measured over the `smells` case's
     * SARIF surface, its twelve findings publish four identities of the form
     * `channel:subject`, three findings to each class. So the substitution is
     * exercised on the two-part shape, and the shape *with* an occurrence is
     * exercised by {@see occurrenceFrozenUnderDeclaredRename()} over
     * `security.sensitive-parameter` for exactly that reason.
     *
     * The claim and the tracked declaration fixture move with the rename because
     * they are declarations of the channel, not evidence about it: leaving them
     * stale would make this control fail on two other mechanisms and say nothing
     * about fingerprints. The map is written **whole**, holding this control's one
     * row: a step that renames nothing tracks an empty map, so there is neither a
     * row to anchor an insertion on nor a declaration to withdraw.
     */
    public static function fingerprintDeclaredRename(): Control
    {
        return Control::greenWith(
            'fingerprint-declared-rename',
            'a channel rename that moves every fingerprint, declared as one channels.tsv row',
            // The SAME product change as fingerprint-no-map, which declares no
            // row and must go red. That symmetry is the control: one mutation,
            // two declarations, opposite verdicts — anything else and the pair
            // would be comparing two different changes.
            ChannelRenamePlants::unusedPrivateChannelMutation()
                ->and(ChannelRenamePlants::unusedPrivateRenameDeclarations())
                ->and(ChannelRenamePlants::trackedChannelMapPlus(
                    ["code-smell.unused-private\tcode-smell.unused-privat2\tthe control renames the channel's code"],
                    "the step's own rows, plus the one row that declares this control's rename",
                )),
        );
    }

    /**
     * A channel rename that is declared, on a channel whose findings carry an
     * `occurrence`: the channel code moves and the hash beside it does not.
     *
     * This is the gap {@see fingerprintDeclaredRename()} names in its own
     * record. That control renames `code-smell.unused-private`, whose findings
     * publish the two-part identity `channel:subject`, so no control had ever
     * watched a rename cross an identity that *has* a third part. The third
     * part is the interesting one: `OccurrenceKey::semantic()` hashes a
     * discriminator plus the evidence. Six families once passed their channel
     * code as that discriminator, so a rename silently moved the
     * `occurrence` of every finding on the channel, and no consumer can
     * recompute it, because an accepted baseline entry stores the digest and
     * never the evidence.
     *
     * What a GREEN run here asserts, and why each half needs the other:
     *
     * - the mutation bit — a declared row that translated nothing is
     *   `map-stale`, so green means the row did the absorbing and the rename
     *   reached the product;
     * - the hash did not move — a moved `occurrence` is untranslatable (no row
     *   can declare `9477b3c7… -> 1228709c…`, {@see \QmxFindingGate\Fingerprints}),
     *   so it arrives as `surface-mismatch` on a publishing case;
     * - each claiming case still reports something — its claim moves with the
     *   rename, so zero findings on the channel is `case-claim-mismatch`;
     * - the run happened — the harness holds a green control to exit 0, which a
     *   rename that failed to survive the container build cannot reach.
     *
     * `security.sensitive-parameter` is the family with the least coupling
     * between its channel code and any case's configuration: no `rules:`, no
     * `suppress_*`, no `--disable-rule` skip path (which `duplication` and
     * `architecture.circular-dependency` both have), no layer policy, and no
     * directive addressing it. The rule reads its collector entries through
     * `MetricName::SECURITY_SENSITIVE_PARAMETER`, a literal of its own, so
     * renaming `NAME` moves the published channel without emptying the run.
     * The `security` case reports two findings from distinct `paramName`
     * evidence, so the compared hashes are not one constant.
     *
     * The claim and the tracked declaration fixture move with the rename for
     * the same reason they do in the fingerprint pair: they are declarations of
     * the channel, not evidence about it.
     *
     * Measured counterfactually against `01f02856` — the tree before the freeze
     * — with the same rename over the same case: the two findings' `occurrence`
     * moved from `9477b3c7e0459483`, `d8c7c1c77a1276d8` to `1228709cb1ed5411`,
     * `1a56f589a6c3dd25` while every other key group held. On that tree this
     * control cannot be green, and no declaration could make it so.
     */
    public static function occurrenceFrozenUnderDeclaredRename(): Control
    {
        return Control::greenWith(
            'occurrence-declared-rename',
            'a declared rename of a channel whose findings carry an occurrence hash',
            self::sensitiveParameterChannelMutation()
                ->and(self::sensitiveParameterRenameDeclarations())
                ->and(ChannelRenamePlants::trackedChannelMapPlus(
                    [
                        "security.sensitive-parameter\tsecurity.sensitive-paramete2\t"
                            . "the control renames the channel's code",
                    ],
                    "the step's own rows, plus the one row that declares this control's rename",
                )),
        );
    }

    /**
     * The rename itself: `NAME` only.
     *
     * `OCCURRENCE_KIND` is deliberately left alone — it is the constant under
     * test, and moving it with the channel would restore exactly the coupling
     * this control exists to disprove. The new spelling is the same length as
     * the old one because two surfaces pad the channel column and a row cannot
     * declare padding.
     */
    private static function sensitiveParameterChannelMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Evidence/Security/SensitiveParameterRule.php',
            [
                "public const string NAME = 'security.sensitive-parameter';"
                    => "public const string NAME = 'security.sensitive-paramete2';",
            ],
            'channel security.sensitive-parameter -> security.sensitive-paramete2, its code and published rule'
                . ' field together, leaving OCCURRENCE_KIND where it is',
        );
    }

    private static function sensitiveParameterRenameDeclarations(): Mutation
    {
        $old = 'security.sensitive-parameter';
        $new = 'security.sensitive-paramete2';
        $root = \dirname(__DIR__, 2);
        $mutation = Mutation::edit(
            'governance/Channel/Fixtures/declared.txt',
            [$old . ' - callable' => $new . ' - callable'],
            'the tracked declaration fixture names the new channel',
        );

        foreach (Corpus::load($root)->cases as $case) {
            foreach ($case->channels as $channel) {
                if (!str_starts_with($channel, $old . '@')) {
                    continue;
                }

                $mutation = $mutation->and(ChannelRenamePlants::renamedCaseClaims($case, $old, $new));
                break;
            }
        }

        $values = [];
        foreach (DeclaredValues::load($root . '/finding-gate')->derived() as $row) {
            if ($row['kind'] !== DeclaredValues::FIELD || !str_contains($row['subject'], '|record:')) {
                continue;
            }

            [$surface, $identity] = explode('|record:', $row['subject'], 2);
            $record = json_decode($identity, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($record) || ($record['channel'] ?? null) !== $old) {
                continue;
            }

            $before = implode("\t", array_values($row)) . "\n";
            $record['channel'] = $new;
            $row['subject'] = $surface . '|record:' . DeclaredRecords::canonical($record);
            $values[$before] = implode("\t", array_values($row)) . "\n";
        }
        if ($values !== []) {
            $mutation = $mutation->and(Mutation::edit(
                'finding-gate/' . DeclaredValues::DERIVED,
                $values,
                'value declarations follow the renamed record identity',
            ));
        }

        return $mutation->and(Mutation::renameInDerivedDeclarations(
            [$old => $new],
            'any derived declaration that names the channel names the new one',
        ));
    }
}
