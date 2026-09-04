<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

use RuntimeException;

/**
 * The controls, as a list.
 *
 * A positive probe and one breakage per claim the directive audit makes — about
 * what it decides, about what the command renders, and about the CI step that
 * reads the answer. The list is not a design: every entry here was planted by
 * hand and every one of them reddened something. Several exist because a
 * reviewer found the claim broken — the masking mechanism alone was rewritten
 * twice, and each edition passed the previous edition's controls. How many
 * there are is not written here on purpose: the count lives in {@see all()},
 * and prose repeating it is the defect this bench exists to catch.
 *
 * Each probe names the cases it must redden. That is stricter than "reddens
 * something": a breakage that fails the suite somewhere else proves the suite
 * notices damage, not that the claim it broke is guarded.
 *
 * Twenty of those declarations moved from a cascade ({@see Probe::alsoReddens()})
 * straight into a probe's own `reddens` on 2026-09-04, once the coverage
 * condition (package B, X7-tails) made every such case matter on its own
 * rather than through the claim its cascade belonged to. Each of the twenty
 * was checked by running that probe alone and confirming it still reddens
 * exactly the declared set — the adjudication for each line, not repeated
 * here, is `docs/internal/plans/rule-vocabulary/X7-tails/enumeration-unguarded-cases.tsv`.
 */
final class Probes
{
    private const string AUDIT = 'src/Analysis/Policy/Inline/Directive/Audit/ThresholdDirectiveAudit.php';

    private const string COALITION = 'src/Analysis/Policy/Inline/Directive/Audit/DirectiveMaskingCoalition.php';

    private const string FINGERPRINT = 'src/Analysis/Policy/Inline/Directive/Audit/ExecutionFingerprint.php';

    private const string PIPELINE = 'src/Analysis/Run/Pipeline/AnalysisPipeline.php';

    private const string USAGE = 'src/Analysis/Policy/Inline/Directive/Audit/DirectiveUsage.php';

    private const string PRODUCER_RULE = 'src/Analysis/Policy/Inline/Directive/InlineDirectivePolicy.php';

    private const string BAN = 'src/Analysis/Policy/Inline/Directive/DirectiveChannelBan.php';

    private const string ADDRESSABILITY = 'src/Analysis/Policy/Inline/Directive/DirectiveAddressability.php';

    private const string FILTER = 'src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php';

    private const string PROJECTOR = 'src/Reporting/FindingProjection/FindingProjector.php';

    private const string LEVEL_ACTIVITY = 'src/Analysis/Finding/Contract/LevelActivity.php';

    private const string COMMAND = 'src/Infrastructure/Console/Command/DirectivesCommand.php';

    private const string PRESENTER = 'src/Infrastructure/Console/DirectiveAuditPresenter.php';

    private const string FAILURE_TAXONOMY = 'src/Infrastructure/Console/ConfigurationFailure.php';

    private const string TALLY = 'src/Infrastructure/Console/DirectiveVerdictTally.php';

    private const string FLOOR = 'scripts/directive-audit/MeasuredEffects.php';

    private const string HETEROGENEITY = 'scripts/directive-audit/HeterogeneityFloor.php';

    private const string SEEDED = 'tests/Analysis/Policy/Inline/Fixtures/NarrowControl/OverrunBoundary.php';

    private const string SEEDED_SUPPRESSION =
        'tests/Analysis/Policy/Inline/Fixtures/NarrowControl/EveryChannelSuppression.php';

    /** Where a copy is planted: paths `src/` does not have, so creating one states the mistake. */
    private const string SEEDED_LEAK = 'src/NarrowControlLeak/OverrunBoundary.php';

    private const string SEEDED_SUPPRESSION_LEAK = 'src/NarrowControlLeak/EveryChannelSuppression.php';

    private const string READER = 'scripts/directive-audit/VerdictReport.php';

    private const string ENTRY = 'scripts/directive-audit/AuditedVerdict.php';

    private const string ENUMERATION = 'scripts/directive-audit/SiteEnumeration.php';

    private const string POPULATION = 'scripts/directive-audit/Population.php';

    private const string GATE = 'scripts/directive-audit/Gate.php';

    private const string SCAN = 'scripts/directive-audit/ThresholdDirectiveScan.php';

    private const string FIXTURE = 'tests/Unit/RuleVocabulary/Fixtures/AuthoredThresholdForms.php';

    private const string EXTRACTOR = 'src/Analysis/Policy/Inline/Contract/ThresholdOverrideExtractor.php';

    /**
     * Field of a finding => the line the fingerprint reads it with.
     *
     * The map is the bench's, not the product's, and deliberately: a probe
     * generated from the product's own list would break in the same direction
     * as the product and prove nothing. `Mutation` refuses an anchor that does
     * not occur exactly once, so a line that moves is a loud refusal.
     *
     * @var array<string, string>
     */
    private const array FIELD_READS = [
        'location' => '            self::location($finding->location),',
        'subject' => '            $finding->subject->toCanonical(),',
        'symbolPath' => '            $finding->symbolPath->toString(),',
        'ruleName' => '            $finding->ruleName,',
        'code' => '            $finding->code,',
        'severity' => '            $finding->severity->value,',
        'metricValue' => '            self::number($finding->metricValue),',
        'relatedLocations' => "            implode('|', array_map(self::location(...), \$finding->relatedLocations)),",
        'dependencyTarget' => "            \$finding->dependencyTarget?->toCanonical() ?? '',",
        'dependencyType' => "            \$finding->dependencyType->name ?? '',",
        'acceptedLevel' => "            \$finding->acceptedLevel?->describe() ?? '',",
        'occurrenceKey' => "            \$finding->occurrenceKey->value ?? '',",
        'threshold' => '            self::number($finding->threshold),',
        'message' => '            $finding->message,',
        'recommendation' => "            \$finding->recommendation ?? '',",
    ];

    /**
     * Field of a finding => the further cases dropping it denies directly.
     *
     * One field only, and that is the measurement rather than an oversight:
     * the fingerprint is read positionally by the coverage cases, so most
     * fields move nothing else, while a claim written about severity alone
     * rests on the fingerprint seeing severity — denied by the same mutation
     * as the coverage case, not by a cascade from it, so both belong to
     * `reddens` rather than to {@see Probe::alsoReddens()}.
     *
     * @var array<string, list<string>>
     */
    private const array FIELD_CASCADES = [
        'severity' => [
            'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveEffectiveWhenItOnlyMovedTheSeverity',
        ],
    ];

    /**
     * One character of the target list => what breaking it claims, the list it
     * leaves behind, and the cases that must notice.
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private const array CHARACTER_CLASS_EDITS = [
        'drops-the-dot' => [
            'the second measure stops reading a dotted channel whole',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_*#:-',
            [
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "plain"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "glued to the docblock star"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "after a multiline backtick region"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "two on one line"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "target cut at a call"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "star"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "hash"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "colon"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "digit"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "capital"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "cut target then a second directive"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "single-line docblock"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "comma"',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itScansATreeAndSkipsWhatIsNotPhp',
            ],
        ],
        'drops-the-underscore' => [
            'the second measure cuts a target at an underscore the product admits',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.*#:-',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "underscore"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-digits' => [
            'the second measure cuts a target at a digit the product admits',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_.*#:-',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "digit"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-capitals' => [
            'the second measure cuts a target at a capital the product admits',
            'abcdefghijklmnopqrstuvwxyz0123456789_.*#:-',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "capital"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-the-star' => [
            'the second measure cuts a wildcard target short',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.#:-',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "star"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-the-hash' => [
            'the second measure cuts the retired rule#code spelling short',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*:-',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "hash"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-the-colon' => [
            'the second measure cuts a channel:level pair short, which is the pair the product refuses by name',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#-',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "colon"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-the-hyphen' => [
            'the second measure cuts a hyphenated channel short',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#:',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "hyphen"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'admits-a-slash' => [
            'the second measure reads past a slash the product stops at',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#:-/',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "slash"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'admits-a-plus' => [
            'the second measure reads past a plus the product stops at',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#:-+',
            ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "plus"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
    ];

    /** @return list<Probe> */
    public static function all(): array
    {
        return [
            Probe::positive(),
            ...self::comparison(),
            ...self::removal(),
            ...self::fingerprint(),
            ...self::masking(),
            ...self::reproducibility(),
            ...self::verdicts(),
            ...self::refusals(),
            ...self::sweep(),
            ...self::suppressions(),
            ...self::report(),
            ...self::fields(),
            ...self::floor(),
            ...self::reading(),
            ...self::enumeration(),
            ...self::projections(),
            ...self::agreement(),
            ...self::heterogeneity(),
        ];
    }

    /**
     * The floor the narrow/full comparison puts under its population, and the
     * barrier that keeps the seeded fixture out of the tree's own measurement.
     *
     * Both are claims about what a *green* run means, which is the kind a
     * control bench exists for: nothing about them fails on its own, and an
     * agreement measured over a population that could not disagree looks
     * exactly like an agreement that proves something.
     *
     * @return list<Probe>
     */
    private static function heterogeneity(): array
    {
        return [
            Probe::breaking(
                'heterogeneity-forgets-a-verdict',
                'the floor stops asking for a verdict the product can publish',
                self::HETEROGENEITY,
                ["'effective', 'overrun', 'inert', 'unmeasured'" => "'effective', 'overrun', 'unmeasured'"],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itAsksForEveryVerdictTheProductCanPublishAndNoOther',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itNamesEveryRequirementAHomogeneousPopulationMisses',
                ],
            )->alsoReddens(
                'the floor\'s requirement list is read both when it refuses and when it reports what the population carries',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itPrintsWhatThePopulationCarriesWhetherOrNotTheFloorIsMet',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictCarriedOnlyByASuppression',
                ],
            ),
            Probe::breaking(
                'heterogeneity-forgets-a-refusal',
                'the floor stops asking for the masking coalition, the branch it was written for',
                self::HETEROGENEITY,
                ["        'masked',\n" => ''],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itAsksForEveryRefusalTheProductCanPublishAndNoOther',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itNamesEveryRequirementAHomogeneousPopulationMisses',
                ],
            )->alsoReddens(
                'the floor\'s requirement list is read both when it refuses and when it reports what the population carries',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itPrintsWhatThePopulationCarriesWhetherOrNotTheFloorIsMet',
                ],
            ),
            Probe::breaking(
                'heterogeneity-reports-one-shortfall',
                'the floor answers with the first thing missing instead of everything missing',
                self::HETEROGENEITY,
                [
                    "                \$shortfalls[] = \sprintf('no @qmx-threshold was judged \"%s\".', \$effect);"
                    => "                return [\sprintf('no @qmx-threshold was judged \"%s\".', \$effect)];",
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itNamesEveryRequirementAHomogeneousPopulationMisses'],
            ),
            Probe::breaking(
                'reason-key-defaulted',
                'a report that stopped publishing refusals is read as a population carrying none',
                self::ENTRY,
                [
                    "            throw new AuditReportError(\sprintf('%s: \"%s\" is missing.', \$where, \$key));"
                    => '            return null;',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAnEntryWithoutAReasonKey'],
            ),
            Probe::breaking(
                'heterogeneity-counts-a-verdict-the-sweep-cannot-move',
                'a verdict carried only by a suppression satisfies the verdict axis',
                self::HETEROGENEITY,
                [
                    '        foreach ($report->thresholdVerdicts() as $verdict) {'
                    => '        foreach ($report->verdicts() as $verdict) {',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictCarriedOnlyByASuppression'],
            )->alsoReddens(
                'the floor\'s requirement list is read both when it refuses and when it reports what the population carries',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itPrintsWhatThePopulationCarriesWhetherOrNotTheFloorIsMet',
                ],
            ),
            Probe::planting(
                'seeded-fixture-copied-into-src',
                'a copy of the seeded directive fixture appears under src/ and the tree enumeration swallows it',
                [self::SEEDED_LEAK => self::seededFile(self::SEEDED)],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itKeepsTheSeededDirectivesOutOfTheEnumerationOverSrc'],
            )->alsoReddens(
                'both cases forbid a seeded fixture under src/, and the planted copy is one file breaking both',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itKeepsEverySeededFixtureFileOutOfSrc',
                ],
            ),
            Probe::planting(
                'seeded-suppression-copied-into-src',
                'a seeded @qmx-ignore appears under src/, where no enumeration would ever notice it',
                [self::SEEDED_SUPPRESSION_LEAK => self::seededFile(self::SEEDED_SUPPRESSION)],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itKeepsEverySeededFixtureFileOutOfSrc'],
            ),
        ];
    }

    /**
     * A seeded fixture file as it stands, read rather than copied.
     *
     * A literal here would keep reddening its case after the fixture moved a
     * line, which is the one thing the cases are about: both identities they
     * compare — the threshold site's line, and the file's hash — change with
     * any edit, so a stale copy would stop matching and the probe would report
     * the claim unguarded, loudly and for the wrong reason.
     */
    private static function seededFile(string $path): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 2) . '/' . $path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Cannot read %s, so the leak cannot be planted.', $path));
        }

        return $contents;
    }

    /**
     * The CI step that reads the audit's answer, and the floor it puts under it.
     *
     * One probe per validator rather than per case: a broken validator reddens
     * every case written for it at once, and a probe per case would be the same
     * breakage planted several times under different names.
     *
     * @return list<Probe>
     */
    private static function floor(): array
    {
        return [
            Probe::breaking(
                'measured-table-flipped',
                'the frozen table calls an unmeasured verdict a measurement',
                self::FLOOR,
                ["        'unmeasured' => false," => "        'unmeasured' => true,"],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itRefusesAReportWhoseThresholdVerdictsAreAllUnmeasured',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itPrintsWhatThePopulationCarriesWhetherOrNotTheFloorIsMet',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAPopulationShortOfMeasuredThresholdVerdicts',
                ],
            ),
            Probe::breaking(
                'unknown-verdict-guessed',
                'a verdict value nobody named is guessed at instead of refused',
                self::FLOOR,
                [
                    '        return self::TABLE[$effect] ?? self::refuse($effect);'
                    => "        return self::TABLE[\$effect] ?? \$effect !== 'unmeasured';",
                ],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictValueTheFloorDoesNotName',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAnUnknownVerdictOnTheSuppressionHalf',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itRefusesToJudgeAVerdictValueTheFloorCannotWeigh',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itRefusesAnUnknownVerdictOnASuppressionSite',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itRefusesAnUnknownVerdictWhereTheFloorIsNeverReached',
                ],
            ),
            Probe::breaking(
                'table-forgets-a-verdict',
                'the frozen table stops naming a verdict the product publishes',
                self::FLOOR,
                ["        'inert' => true,\n" => ''],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itNamesEveryVerdictTheProductCanPublishAndNoOther',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itAcceptsAPopulationCarryingEveryVerdictAndEveryRefusal',
                ],
            )->alsoReddens(
                'the frozen table is the vocabulary the reader, the floor and the gate all validate a report against',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itAcceptsATreeWhoseSitesMatchAndWhereSomethingWasMeasured',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itKeepsEveryEntryOfASiteAuthoredTwice',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itPrintsWhatThePopulationCarriesWhetherOrNotTheFloorIsMet',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itReadsAWellFormedReportAsOneMeasurementAndItsContext',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAPopulationShortOfMeasuredThresholdVerdicts',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictCarriedOnlyByASuppression',
                ],
            ),
            Probe::breaking(
                'floor-removed',
                'a population that matches exactly is accepted even when nothing in it was measured',
                self::GATE,
                ['        if ($measured === 0) {' => '        if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itRefusesAReportWhoseThresholdVerdictsAreAllUnmeasured'],
            ),
            Probe::breaking(
                'population-never-mismatches',
                'the two measures of the population are compared and the answer discarded',
                self::GATE,
                ['        if ($onlyAudited !== [] || $onlyEnumerated !== []) {' => '        if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itReportsAPopulationMismatch'],
            )->alsoReddens(
                'the mismatch the gate discards is what the case about a directive gone missing reads',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itSeesOneOfTwoDirectivesOnASiteGoMissing',
                ],
            ),
            Probe::breaking(
                'empty-population-floored',
                'a tree with no threshold directive is failed for having measured nothing',
                self::GATE,
                ['        if ($auditedSites === []) {' => '        if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itFloorsNothingWhenNoThresholdSiteIsInScope'],
            ),
            Probe::breaking(
                'disqualified-run-judged',
                'a run the command already disqualified is judged anyway',
                self::GATE,
                ['        if ($auditExit !== 0 && $auditExit !== 2) {' => '        if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itPropagatesARunThatWasAlreadyDisqualified'],
            ),
            Probe::breaking(
                'enumeration-failure-is-not-a-refusal',
                'an enumeration that would not run dies with a stack trace instead of this step\'s own code',
                self::GATE,
                [
                    '            throw new AuditReportError(\sprintf(' . "\n"
                    . '                "enumerate-inline-directives.php failed (exit %d):\n%s",'
                    => '            throw new RuntimeException(\sprintf(' . "\n"
                    . '                "enumerate-inline-directives.php failed (exit %d):\n%s",',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itRefusesAnEnumerationThatWouldNotRun'],
            ),
            Probe::breaking(
                'no-report-read-as-a-report',
                'a run that wrote no JSON at all is answered as a malformed report',
                self::GATE,
                ['        if (!\is_array(json_decode($auditStdout, true))) {' => '        if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itRefusesAnAuditThatProducedNoJson'],
            ),
        ];
    }

    /**
     * The reader both scripts share: what it refuses to read.
     *
     * @return list<Probe>
     */
    private static function reading(): array
    {
        return [
            Probe::breaking(
                'missing-field-defaulted',
                'a string field the audit did not publish is defaulted rather than refused',
                self::ENTRY,
                [
                    "            throw new AuditReportError(self::wrongType(\$where, \$key, 'a string', \$value));"
                    => "            return 'unmeasured';",
                ],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "effect missing"',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "form missing"',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "file missing"',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "target missing"',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "effect null"',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "effect not a string"',
                ],
            ),
            Probe::breaking(
                'missing-line-defaulted',
                'a line number published as something other than a number is defaulted rather than refused',
                self::ENTRY,
                [
                    "            throw new AuditReportError(self::wrongType(\$where, \$key, 'an integer', \$value));"
                    => '            return 0;',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes with data set "line not a number"'],
            ),
            Probe::breaking(
                'verdict-list-unchecked',
                'whatever stands where the verdict list should be is read as one',
                self::READER,
                [
                    '        $directives = self::directiveListOf($decoded);'
                    => "        \$directives = (array) (\$decoded['directives'] ?? []);",
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAReportWhoseDirectivesAreNotAList', 'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAReportWithNoDirectivesAtAll'],
            ),
            Probe::breaking(
                'envelope-read-as-a-measurement',
                'an error envelope is read as a report that should have carried verdicts',
                self::READER,
                ["        \$errorEnvelope = isset(\$decoded['error']);" => '        $errorEnvelope = false;'],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itReadsAnErrorEnvelopeWithoutDemandingVerdicts',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itPropagatesTheCommandsOwnCodeThroughAnErrorEnvelope',
                ],
            ),
            Probe::breaking(
                'population-holds-both-halves',
                'the suppression half is counted into the population the enumeration measures',
                self::READER,
                [
                    '            static fn(AuditedVerdict $verdict): bool => $verdict->isThreshold(),'
                    => '            static fn(AuditedVerdict $verdict): bool => true,',
                ],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itReadsAWellFormedReportAsOneMeasurementAndItsContext',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itAcceptsATreeWhoseSitesMatchAndWhereSomethingWasMeasured',
                ],
            )->alsoReddens(
                'the population the enumeration measures is the one the floor weighs and the gate reports on',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itFloorsNothingWhenNoThresholdSiteIsInScope',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itPrintsWhatThePopulationCarriesWhetherOrNotTheFloorIsMet',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAVerdictCarriedOnlyByASuppression',
                ],
            ),
            Probe::breaking(
                'verdict-map-drops-a-duplicate',
                'the by-site map keeps one entry per site, so a site authored twice loses one of them',
                self::READER,
                [
                    '            $bySite[$verdict->keyedSite()][] = $this->rawVerdicts[$index];'
                    => '            $bySite[$verdict->keyedSite()] = [$this->rawVerdicts[$index]];',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itKeepsEveryEntryOfASiteAuthoredTwice'],
            ),
            Probe::breaking(
                'population-as-a-set',
                'two directives authored on one site are compared as one',
                self::POPULATION,
                [
                    '            $delta = ($leftCounts[$site] ?? 0) - ($rightCounts[$site] ?? 0);'
                    => '            $delta = min(1, $leftCounts[$site] ?? 0) - min(1, $rightCounts[$site] ?? 0);',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itCountsEveryOccurrenceOfARepeatedSite', 'Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditGateTest::itSeesOneOfTwoDirectivesOnASiteGoMissing'],
            ),
        ];
    }

    /**
     * The authored population, as read out of the enumeration's TSV.
     *
     * @return list<Probe>
     */
    private static function enumeration(): array
    {
        return [
            Probe::breaking(
                'tsv-split-unbounded',
                'a tab inside the authored values is read as a column of its own',
                self::ENUMERATION,
                ['        $columns = explode("\t", $line, 4);' => '        $columns = explode("\t", $line);'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itReadsEveryEnumeratedSiteAndKeepsATabInsideItsValues'],
            ),
            Probe::breaking(
                'tsv-columns-unchecked',
                'a row short of a column is padded out instead of refused',
                self::ENUMERATION,
                [
                    '            [$file, $number, $target, $values] = self::columnsOf($line, $offset + 1);'
                    => '            [$file, $number, $target, $values] = array_pad(explode("\t", $line, 4), 4, \'\');',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAnEnumerationRowShortOfAColumn'],
            ),
            Probe::breaking(
                'tsv-line-number-untyped',
                'whatever stands in the line-number column is cast to a number',
                self::ENUMERATION,
                ["            if (preg_match('/^\d+$/', \$number) !== 1) {" => '            if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAnEnumerationRowWhoseLineIsNotANumber'],
            ),
            Probe::breaking(
                'tsv-empty-target-accepted',
                'a row addressing nothing is admitted to the population',
                self::ENUMERATION,
                ["            if (\$target === '') {" => '            if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.DirectiveAuditReportReadingTest::itRefusesAnEnumerationRowThatAddressesNothing'],
            ),
        ];
    }

    /**
     * The two projections of one audit, which must tally one vocabulary.
     *
     * @return list<Probe>
     */
    private static function projections(): array
    {
        return [
            Probe::breaking(
                'json-summary-by-hand',
                'the machine summary names its keys by hand, so a verdict is counted and published nowhere',
                self::TALLY,
                [
                    "        return ['total' => \$this->total, ...\$this->counts];"
                    => "        return [\n            'total' => \$this->total,\n"
                    . "            'effective' => \$this->counts['effective'],\n"
                    . "            'overrun' => \$this->counts['overrun'],\n"
                    . "            'inert' => \$this->counts['inert'],\n        ];",
                ],
                ['Qualimetrix.Tests.Infrastructure.Console.Unit.DirectiveAuditSummaryProjectionTest::itPublishesOneSummaryKeyPerVerdictTheVocabularyDefines'],
            )->alsoReddens(
                'a summary key named by hand is missing for the verdict the clean-exit case counts',
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenADirectiveCouldNotBeMeasured',
                ],
            ),
            Probe::breaking(
                'text-summary-by-hand',
                'the text summary tallies a hand-written list of verdicts rather than the vocabulary',
                self::TALLY,
                [
                    "            \$tallied[] = \sprintf('%d %s', \$this->counts[\$effect->value], self::label(\$effect));"
                    => '            $tallied[] = $effect === DirectiveEffect::Unmeasured'
                    . " ? '' : \sprintf('%d %s', \$this->counts[\$effect->value], self::label(\$effect));",
                ],
                ['Qualimetrix.Tests.Infrastructure.Console.Unit.DirectiveAuditSummaryProjectionTest::itPrintsOneTallyPerVerdictTheVocabularyDefinesInTheTextSummary',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itSaysTheSameThingInBothFormats'],
            ),
        ];
    }

    /**
     * The second measure's character list, one character at a time.
     *
     * One probe per character rather than one for the whole list, in both
     * directions. A single probe narrowing the list to letters reddened twelve
     * cases while declaring six, which makes it a blanket breakage wearing a
     * specific claim: it was the only guard two of those cases had, and nothing
     * said which character each of them was about. And a list that *grows* was
     * guarded by nothing at all — a measure admitting `/` or `+` reads further
     * than the product does, and every narrowing probe stays green through it.
     *
     * @return list<Probe>
     */
    private static function characterClass(): array
    {
        $whole = "        'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#:-';";
        $probes = [];

        foreach (self::CHARACTER_CLASS_EDITS as $id => [$claim, $list, $reddens]) {
            $probes[] = Probe::breaking(
                'scan-class-' . $id,
                $claim,
                self::SCAN,
                [$whole => "        '" . $list . "';"],
                $reddens,
            );
        }

        return $probes;
    }

    /**
     * The second measure of the authored population, and the product measure it
     * has to agree with.
     *
     * One probe per rule of the scan rather than per authored form: a rule
     * broken once reddens every form written for it, and planting the same
     * breakage under several names would inflate the table without covering
     * anything more. The last two break the *product's* character class
     * instead — that is the defect this pair exists to catch, and the one the
     * live tree cannot show, because no target in `src/` is spelled with
     * anything but lowercase letters, dots and hyphens.
     *
     * @return list<Probe>
     */
    private static function agreement(): array
    {
        return [
            ...self::characterClass(),
            Probe::breaking(
                'scan-demands-a-whole-word',
                'a tag written against the docblock star is read as no directive at all',
                self::SCAN,
                ['!str_ends_with($word, self::DIRECTIVE)' => '$word !== self::DIRECTIVE'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "glued to the docblock star"'],
            )->alsoReddens(
                'the whole-fixture agreement reads the same scan as the per-form case, so any misread form moves it too',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
            Probe::breaking(
                'scan-accepts-a-tag-with-a-suffix',
                'a word that merely contains the tag is read as a directive',
                self::SCAN,
                ['!str_ends_with($word, self::DIRECTIVE)' => '!str_contains($word, self::DIRECTIVE)'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "tag with a suffix"'],
            )->alsoReddens(
                'the whole-fixture agreement reads the same scan as the per-form case, so any misread form moves it too',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
            Probe::breaking(
                'scan-reads-backticked-documentation',
                'a documented example is counted as an authored directive',
                self::SCAN,
                [
                    'foreach (explode("\n", self::blankBacktickRegions($token[1])) as $offset => $line) {'
                    => 'foreach (explode("\n", $token[1]) as $offset => $line) {',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "backticked"'],
            )->alsoReddens(
                'the whole-fixture agreement reads the same scan as the per-form case, so any misread form moves it too',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
            Probe::breaking(
                'scan-cuts-a-backtick-region-out',
                'a backtick region is removed rather than blanked, so everything below it moves up',
                self::SCAN,
                [
                    "            static fn(array \$match): string => preg_replace('/[^\\r\\n]/', ' ', \$match[0]) ?? \$match[0],"
                    => "            static fn(array \$match): string => '',",
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "after a multiline backtick region"'],
            )->alsoReddens(
                'the whole-fixture agreement reads the same scan as the per-form case, so any misread form moves it too',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
            Probe::breaking(
                'scan-splits-on-a-comma',
                'the second measure treats a comma as whitespace, so it reads values the product never saw',
                self::SCAN,
                ["    private const string WORD_SEPARATORS = \" \\t\";"
                    => "    private const string WORD_SEPARATORS = \" \\t,\";"],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "comma"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
            ),
            Probe::breaking(
                'scan-keeps-the-docblock-terminator',
                'the values of a one-line docblock carry its terminator, which the product strips before parsing',
                self::SCAN,
                [
                    "        if (!str_ends_with(\$trimmed, '*/')) {" => '        if (true) {',
                ],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "single-line docblock"',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itScansATreeAndSkipsWhatIsNotPhp',
                ],
            ),
            Probe::breaking(
                'scan-stops-at-a-valueless-directive',
                'a second directive behind a target the product cut short is dropped',
                self::SCAN,
                [
                    "            if (\$address['values'] !== '' || \$address['carriesValues']) {"
                    => '            if (true) {',
                ],
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "cut target then a second directive"',
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
            Probe::breaking(
                'scan-reads-any-file-in-the-tree',
                'the tree scan stops filtering on the extension, so prose in a text file becomes a site',
                self::SCAN,
                ["            if (!\$file->isFile() || \$file->getExtension() !== 'php') {" => '            if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itScansATreeAndSkipsWhatIsNotPhp'],
            ),
            Probe::breaking(
                'scan-skips-what-it-cannot-read',
                'a file the tree scan cannot read is passed over instead of refused, leaving a hole in the population',
                self::SCAN,
                [
                    "                throw new RuntimeException(\sprintf('unreadable: %s', \$file->getPathname()));"
                    => '                continue;',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itRefusesToScanATreeItCannotRead'],
            ),
            Probe::breaking(
                'fixture-grows-an-unnamed-form',
                'a form is added to the fixture and no row names it, so only the two measures judge it',
                self::FIXTURE,
                [
                    "    public function targetFollowedByAComma(): void {}\n}"
                    => "    public function targetFollowedByAComma(): void {}\n\n    /**\n"
                    . "     * @qmx-threshold unnamed.form 20\n     */\n"
                    . "    public function unnamedForm(): void {}\n}",
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itNamesEveryFormTheFixtureDeclares'],
            ),
            Probe::breaking(
                'scan-keeps-reading-past-a-directive',
                'the reason text of a complete directive is scanned for another one, so a quoted tag becomes a site',
                self::SCAN,
                [
                    "            if (\$address['values'] !== '' || \$address['carriesValues']) {"
                    => '            if (false) {',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "two on one line"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
            ),
            Probe::breaking(
                'scan-admits-an-empty-target',
                'a tag followed by something no channel starts with is admitted as a site addressing nothing',
                self::SCAN,
                ["        if (\$target === '') {" => '        if (false) {'],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "target wrapped in parens"'],
            )->alsoReddens(
                'the whole-fixture agreement reads the same scan as the per-form case, so any misread form moves it too',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
            Probe::breaking(
                'scan-reads-ordinary-comments',
                'a tag in a line comment is counted, where the product reads docblocks only',
                self::SCAN,
                [
                    '            if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {'
                    => '            if (!\is_array($token) || !\in_array($token[0], [\T_DOC_COMMENT, \T_COMMENT], true)) {',
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "outside a docblock"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture'],
            ),
            Probe::breaking(
                'extractor-class-drops-punctuation',
                "the product's own target class stops admitting the separators it captures in order to refuse",
                self::EXTRACTOR,
                [
                    "'/@qmx-threshold\\s+([\\w.*#:-]+)(?:[ \\t]+([^\\n\\r]*))?/'"
                    => "'/@qmx-threshold\\s+([\\w.-]+)(?:[ \\t]+([^\\n\\r]*))?/'",
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "star"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "hash"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "colon"'],
            )->alsoReddens(
                'the whole-fixture agreement reads the same target class as the per-form cases, so narrowing it moves that too',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
            Probe::breaking(
                'extractor-class-drops-word-characters',
                "the product's own target class stops admitting digits, underscores and capitals",
                self::EXTRACTOR,
                [
                    "'/@qmx-threshold\\s+([\\w.*#:-]+)(?:[ \\t]+([^\\n\\r]*))?/'"
                    => "'/@qmx-threshold\\s+([a-z.*#:-]+)(?:[ \\t]+([^\\n\\r]*))?/'",
                ],
                ['Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "digit"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "underscore"', 'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itReadsAnAuthoredFormTheWayTheProductDoes with data set "capital"'],
            )->alsoReddens(
                'the whole-fixture agreement reads the same target class as the per-form cases, so narrowing it moves that too',
                [
                    'Qualimetrix.Tests.Unit.RuleVocabulary.ThresholdPopulationAgreementTest::itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
        ];
    }

    /**
     * One probe per field of a finding: the line that reads it, gone.
     *
     * Written as a table rather than by hand because the claim is per field —
     * "a difference in this field is a difference in the outcome" — and a
     * hand-written subset is how eleven of the fifteen came to rest on the two
     * blanket probes. Each anchor is the exact line the fingerprint reads that
     * field with, so a field that moves in the product refuses its probe
     * instead of silently losing it.
     *
     * @return list<Probe>
     */
    private static function fields(): array
    {
        $probes = [];

        foreach (self::FIELD_READS as $field => $read) {
            $probes[] = Probe::breaking(
                'field-' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $field) ?? $field),
                \sprintf('the fingerprint stops reading %s', $field),
                self::FINGERPRINT,
                [$read . "\n" => ''],
                [
                    \sprintf('Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "%s"', $field),
                    ...self::FIELD_CASCADES[$field] ?? [],
                ],
            );
        }

        return $probes;
    }

    /**
     * The two probes that guard what a single run of the audit hands back,
     * rather than what it decided about a directive.
     *
     * @return list<Probe>
     */
    private static function report(): array
    {
        return [
            Probe::breaking(
                'field-list-uncovered',
                'the declared field lists stop covering what a finding carries',
                self::FINGERPRINT,
                ["        'severity',
" => ''],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itNamesEveryFieldAFindingCarries'],
            ),
            Probe::breaking(
                'report-forgets-the-run',
                'the report carries an empty coverage instead of the run the verdicts came from',
                self::PIPELINE,
                [
                    "            verdicts: \$verdicts,\n            coverage: \$prepared->coverage,"
                    => "            verdicts: \$verdicts,\n            coverage: new AnalysisCoverage([], [], []),",
                ],
                ['Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest::itReportsWhatTheRunMeasuredAlongsideTheVerdicts',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsFourWhenTheRunCouldNotParsePartOfTheTree'],
            )->alsoReddens(
                'every case of the command reads the coverage the pipeline hands back, so emptying it moves the whole rendered report, not one line of it',
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAcceptsAnExplicitFullSweep',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAnalysesTheSameFilesAsCheckUnderTheSameExcludes',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itCallsASuppressionEffectiveWhenItSilencedAFinding',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDefaultsToTheNarrowSweep',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallAProducerDisabledAtALevelItNeverReportsAt',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "a rule that declares no override support"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "an unparsable payload"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "an unresolvable name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenADirectiveCouldNotBeMeasured',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenEveryDirectiveStillDoesSomething',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenTheOnlyFindingIsAnAppliedBoundaryThatMovedNothingElse',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsTwoOnAnInertDirective',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff with data set "every level of it"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff with data set "the level the directive sits on"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff with data set "the whole rule"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itNoLongerLetsAFormWithoutARuleFilterSilenceTheBannedChannel',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itPrintsTheSweepScopeInBothFormats',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "file, a group that covers it"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "file, that group at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "file, the exact name at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "file, the exact name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "next-line, a group that covers it"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "next-line, that group at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "next-line, the exact name at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "next-line, the exact name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "symbol, a group that covers it"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "symbol, that group at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "symbol, the exact name at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "symbol, the exact name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesOneDirectiveWithoutTouchingAnotherStaleOneBesideIt',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itSaysTheSameThingInBothFormats',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itStillJudgesSuppressionsWhenTheDirectiveRuleIsDisabled',
                ],
            ),
        ];
    }

    /** @return list<Probe> */
    private static function comparison(): array
    {
        $sameness = '        if ($removed === [] && $added === []) {';

        return [
            Probe::blanket(
                'outcome-always-matched',
                'the comparison of two runs always answers "nothing moved"',
                self::FINGERPRINT,
                [$sameness => '        if (true) {'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveEffectiveWhenRemovingItChangesWhatTheRulesProduced'],
            )->alsoReddens(
                'a blanket denial of the comparison every verdict rests on; the flag exempts it from the upper bound, not from naming what it reaches',
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAcceptsAnExplicitFullSweep',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveEffectiveWhenItOnlyMovedTheSeverity',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveOverrunWhenOnlyTheBoundaryMoved',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenEveryDirectiveStillDoesSomething',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenTheOnlyFindingIsAnAppliedBoundaryThatMovedNothingElse',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itJudgesByWhatTheRulesProducedRatherThanWhatTheyPublished',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itNamesTheNeighbourThatActuallyHidesIt',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itProducesTheSameVerdictsUnderANarrowAndAFullSweepOnATreeWithSeveralRules',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeADirectiveMaskedOnlyByTwoNeighboursTogether',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeEitherDirectiveOfAMaskingPair',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRemovesEveryBindingOfOneAuthoredSite',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itSaysTheSameThingInBothFormats',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "acceptedLevel"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "code"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "dependencyTarget"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "dependencyType"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "location"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "message"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "metricValue"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "occurrenceKey"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "recommendation"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "relatedLocations"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "ruleName"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "severity"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "subject"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "symbolPath"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "threshold"',
                    'Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest::itSeparatesTheLiveDirectiveFromTheDeadOneOnOneAnchor',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillJudgesTheOutcomeOfARuleThatPublishesNoBoundary',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itTakesEveryMaskerOutOfTheComparison',
                ],
            ),
            Probe::blanket(
                'outcome-never-matched',
                'the comparison of two runs always answers "something moved"',
                self::FINGERPRINT,
                [$sameness => '        if (false) {'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveInertWhenRemovingItChangesNothing'],
            )->alsoReddens(
                'a blanket denial of the comparison every verdict rests on; the flag exempts it from the upper bound, not from naming what it reaches',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itComparesTheCounterfactualAgainstAReferenceTakenByTheSameNarrowing',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itDoesNotCallAPairMaskedWhereTheRuleNeverReports',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallAProducerDisabledAtALevelItNeverReportsAt',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsTwoOnAnInertDirective',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itMarksTheBoundaryUnobservableWhenTheRulePublishedNone',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itNamesTheNeighbourThatActuallyHidesIt',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itProducesTheSameVerdictsUnderANarrowAndAFullSweepOnATreeWithSeveralRules',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeADirectiveMaskedOnlyByTwoNeighboursTogether',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeEitherDirectiveOfAMaskingPair',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itSaysTheSameThingInBothFormats',
                    'Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest::itSeparatesTheLiveDirectiveFromTheDeadOneOnOneAnchor',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillJudgesEachDirectiveOfAThreeWayCoalitionThatChangesNothingTogether',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itTakesEveryMaskerOutOfTheComparison',
                ],
            ),
        ];
    }

    /** @return list<Probe> */
    private static function removal(): array
    {
        // Written as a concatenation rather than a heredoc: an indented
        // closing marker strips that indentation from every line, and this
        // fragment has to match the product byte for byte.
        $filter = "                static fn(ThresholdOverride \$override): bool => \$override->line !== \$group->line\n"
            . "                    || \$override->rulePattern !== \$group->rule,";

        return [
            Probe::breaking(
                'removal-removes-nothing',
                'removing a directive leaves the override map untouched',
                self::AUDIT,
                [$filter => '                static fn(ThresholdOverride $override): bool => true,'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveEffectiveWhenRemovingItChangesWhatTheRulesProduced',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAcceptsAnExplicitFullSweep',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenEveryDirectiveStillDoesSomething',
                    'Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest::itSeparatesTheLiveDirectiveFromTheDeadOneOnOneAnchor'],
            )->alsoReddens(
                'removal is the counterfactual every verdict is measured against, so a removal that removes nothing moves every measured case',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveEffectiveWhenItOnlyMovedTheSeverity',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveOverrunWhenOnlyTheBoundaryMoved',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenTheOnlyFindingIsAnAppliedBoundaryThatMovedNothingElse',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itJudgesByWhatTheRulesProducedRatherThanWhatTheyPublished',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itNamesTheNeighbourThatActuallyHidesIt',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itProducesTheSameVerdictsUnderANarrowAndAFullSweepOnATreeWithSeveralRules',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeADirectiveMaskedOnlyByTwoNeighboursTogether',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeEitherDirectiveOfAMaskingPair',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRemovesEveryBindingOfOneAuthoredSite',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itSaysTheSameThingInBothFormats',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillJudgesTheOutcomeOfARuleThatPublishesNoBoundary',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itTakesEveryMaskerOutOfTheComparison',
                ],
            ),
            Probe::breaking(
                'first-binding-only',
                'the unit of removal is the first binding rather than the authored directive',
                self::AUDIT,
                [$filter => "                static fn(ThresholdOverride \$override): bool => \$override !== \$group->bindings[0],"],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRemovesEveryBindingOfOneAuthoredSite'],
            )->alsoReddens(
                'removing a binding rather than an authored site changes what every masking and outcome case removes',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeEitherDirectiveOfAMaskingPair',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillJudgesTheOutcomeOfARuleThatPublishesNoBoundary',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itTakesEveryMaskerOutOfTheComparison',
                ],
            ),
        ];
    }

    /** @return list<Probe> */
    private static function fingerprint(): array
    {
        return [
            Probe::breaking(
                'boundary-out-of-fingerprint',
                'the fingerprint forgets the boundary a finding names',
                self::FINGERPRINT,
                ['$key = $identityKey . "\0" . self::boundaryOf($finding);' => '$key = $identityKey;'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveOverrunWhenOnlyTheBoundaryMoved',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenTheOnlyFindingIsAnAppliedBoundaryThatMovedNothingElse'],
            )->alsoReddens(
                'the fingerprint is read positionally by the field-coverage cases, so dropping one field shifts the ones written after it',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "message"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "recommendation"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "threshold"',
                ],
            ),
            Probe::breaking(
                'recommendation-as-identity',
                'the advice a finding gives counts as part of what the finding is rather than as prose',
                self::FINGERPRINT,
                ["            \$finding->recommendation ?? '',\n        ]);" => '        ]);'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "recommendation"'],
            ),
            Probe::breaking(
                'field-lists-drift-from-the-code',
                'a field name moves between the declared identity and boundary lists',
                self::FINGERPRINT,
                [
                    "        'severity',\n        'metricValue',"
                    => "        'metricValue',",
                    "    public const array BOUNDARY_FIELDS = ['threshold', 'message', 'recommendation'];"
                    => "    public const array BOUNDARY_FIELDS = ['threshold', 'message', 'recommendation', 'severity'];",
                ],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Unit.Directive.ExecutionFingerprintFieldCoverageTest::itSeesEveryFieldItNames with data set "severity"'],
            ),
        ];
    }

    /** @return list<Probe> */
    private static function masking(): array
    {
        // DirectiveMaskingCoalition::maskedBy() closes over `$without` rather
        // than taking `$input`, so the counterfactual call reads
        // `($this->without)(...)` — a property-held closure invocation, not a
        // method call on `$this` — and it has no `$input` to reach for a raw
        // baseline. Removing nothing (`[]`) reproduces that same baseline
        // through the closure instead.
        $maskerRun = '        $withoutMaskers = ExecutionFingerprint::of('
            . '($this->without)($maskers, $restrictToProducer));';

        return [
            Probe::breaking(
                'no-masking',
                'coalitions are not examined at all',
                self::AUDIT,
                [
                    "            \$maskedBy = \$effect === DirectiveEffect::Inert\n"
                    . "                ? \$this->maskedBy(\$input, \$measurable, \$index)\n"
                    . '                : null;' => '            $maskedBy = null;',
                ],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeEitherDirectiveOfAMaskingPair',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeADirectiveMaskedOnlyByTwoNeighboursTogether'],
            )->alsoReddens(
                'the coalition pass is what both neighbour cases read; skipping it denies them together',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itNamesTheNeighbourThatActuallyHidesIt',
                ],
            ),
            Probe::breaking(
                'structural-masking',
                'overlap alone is taken as the fact of masking, without running anything',
                self::COALITION,
                [
                    "        if (\$withoutMaskers->compareTo(\$withoutAll) === DirectiveEffect::Inert) {\n"
                    . "            return null;\n"
                    . '        }' => "        if (false) {\n            return null;\n        }",
                ],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itDoesNotCallAPairMaskedWhereTheRuleNeverReports',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillJudgesEachDirectiveOfAThreeWayCoalitionThatChangesNothingTogether'],
            )->alsoReddens(
                'masking decided by overlap alone changes every coalition case, which read the same decision',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itTakesEveryMaskerOutOfTheComparison',
                ],
            ),
            Probe::breaking(
                'pairwise-masking',
                'only the first masker leaves the comparison, not every one',
                self::COALITION,
                [$maskerRun => '        $withoutMaskers = ExecutionFingerprint::of('
                    . '($this->without)([$maskers[0]], $restrictToProducer));'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itTakesEveryMaskerOutOfTheComparison'],
            ),
            Probe::breaking(
                'coalition-against-the-run',
                'the coalition is compared against the run instead of against itself without this directive',
                self::COALITION,
                [$maskerRun => '        $withoutMaskers = ExecutionFingerprint::of('
                    . '($this->without)([], $restrictToProducer));'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne'],
            )->alsoReddens(
                'the comparison the coalition is taken against is the one the masker-removal case reads',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itTakesEveryMaskerOutOfTheComparison',
                ],
            ),
            Probe::breaking(
                'masker-named-by-position',
                'the neighbour reported as the masker is the first in the list rather than the measured one',
                self::COALITION,
                ['        if (\count($maskers) === 1) {' => '        if (true) {'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itNamesTheNeighbourThatActuallyHidesIt'],
            ),
        ];
    }

    /** @return list<Probe> */
    private static function reproducibility(): array
    {
        return [
            Probe::breaking(
                'no-control-before',
                'the sweep starts without proving the run reproduces',
                self::AUDIT,
                ["        \$this->assertReproducible(\$input, \$baseline, 'before');\n" => ''],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesEveryVerdictWhenTheFirstControlDoesNotReproduceTheRun'],
            )->alsoReddens(
                'the control run is the reference every later comparison of the sweep is taken against',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itControlsTheRunThroughTheSamePathTheCounterfactualsTake',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itProducesTheSameVerdictsUnderANarrowAndAFullSweepOnATreeWithSeveralRules',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesEveryVerdictWhenTheLastControlDoesNotReproduceTheRun',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRemovesEveryBindingOfOneAuthoredSite',
                ],
            ),
            Probe::breaking(
                'no-control-after',
                'the sweep ends without asking again',
                self::AUDIT,
                [
                    "        \$this->assertReproducible(\$input, \$baseline, 'after');\n\n"
                    . '        return $judged;' => '        return $judged;',
                ],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesEveryVerdictWhenTheLastControlDoesNotReproduceTheRun'],
            )->alsoReddens(
                'the closing control is the second half of the reference the whole sweep is compared against',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itProducesTheSameVerdictsUnderANarrowAndAFullSweepOnATreeWithSeveralRules',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRemovesEveryBindingOfOneAuthoredSite',
                ],
            ),
            Probe::breaking(
                'control-skips-the-rebuild',
                'the control executes against the run\'s own context instead of a rebuilt one',
                self::AUDIT,
                [
                    '$repeat = ExecutionFingerprint::of($this->without($input, []));'
                    => '$repeat = ExecutionFingerprint::of($input->executor->execute($input->baseline)->produced);',
                ],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itControlsTheRunThroughTheSamePathTheCounterfactualsTake'],
            ),
            Probe::breaking(
                'no-control-narrowing',
                'the narrowed sweep is never checked against how the rule behaved inside the full run',
                self::AUDIT,
                [
                    "        \$this->assertNarrowingChangedNothing(\$input, \$narrowed, \$rule);\n\n"
                    . '        return ExecutionFingerprint::of($narrowed);' => '        return ExecutionFingerprint::of($narrowed);',
                ],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesTheNarrowedSweepWhenARuleBehavesDifferentlyInIsolation'],
            ),
        ];
    }

    /**
     * What the sweep reports, as opposed to what it measured.
     *
     * @return list<Probe>
     */
    private static function verdicts(): array
    {
        return [
            Probe::breaking(
                'verdict-ignores-the-measurement',
                'the verdict is not the effect the sweep measured',
                self::AUDIT,
                ['            $effect = $effects[$index];' => '            $effect = DirectiveEffect::Effective;'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveInertWhenRemovingItChangesNothing',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itComparesTheCounterfactualAgainstAReferenceTakenByTheSameNarrowing',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallAProducerDisabledAtALevelItNeverReportsAt',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsTwoOnAnInertDirective',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itProducesTheSameVerdictsUnderANarrowAndAFullSweepOnATreeWithSeveralRules'],
            )->alsoReddens(
                'the verdict is what every audit and every command case reads, so denying the measurement behind it moves all of them',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itCallsADirectiveOverrunWhenOnlyTheBoundaryMoved',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itDoesNotCallAPairMaskedWhereTheRuleNeverReports',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenTheOnlyFindingIsAnAppliedBoundaryThatMovedNothingElse',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itMarksTheBoundaryUnobservableWhenTheRulePublishedNone',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itNamesTheNeighbourThatActuallyHidesIt',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeADirectiveMaskedOnlyByTwoNeighboursTogether',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeEitherDirectiveOfAMaskingPair',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itSaysTheSameThingInBothFormats',
                    'Qualimetrix.Tests.Analysis.Run.Integration.DirectiveAuditPipelineTest::itSeparatesTheLiveDirectiveFromTheDeadOneOnOneAnchor',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillJudgesEachDirectiveOfAThreeWayCoalitionThatChangesNothingTogether',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itTakesEveryMaskerOutOfTheComparison',
                ],
            ),
            Probe::breaking(
                'boundary-always-observable',
                'every verdict claims the boundary could have been seen',
                self::AUDIT,
                [
                    "                boundaryObservable: \$entry->effect === DirectiveEffect::Overrun
"
                    . "                    || self::boundaryObservable(\$entry->group, \$produced),"
                    => '                boundaryObservable: true,',
                ],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itMarksTheBoundaryUnobservableWhenTheRulePublishedNone',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itStillJudgesTheOutcomeOfARuleThatPublishesNoBoundary'],
            ),
        ];
    }

    /**
     * The two cases about `--sweep` that no breakage among the probes
     * elsewhere in this list happened to reach — measured empty in
     * `enumeration-unguarded-cases.tsv` (package B, X7-tails) — plus a third,
     * `sweep-request-ignored`, added because `itAcceptsAnExplicitFullSweep`
     * carries two assertions and `removal-removes-nothing` (the only other
     * probe naming it in `alsoReddens`) denies only the second
     * (`effect === 'effective'`); the first, `sweep === 'full'`, is what the
     * case is named for, and nothing else in this list turns an explicit
     * `--sweep=full` back into narrow.
     *
     * @return list<Probe>
     */
    private static function sweep(): array
    {
        return [
            Probe::breaking(
                'sweep-defaults-to-full',
                'no --sweep runs the expensive full sweep instead of the narrow default',
                self::COMMAND,
                ['DirectiveSweepScope::Narrow->value,' => 'DirectiveSweepScope::Full->value,'],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDefaultsToTheNarrowSweep'],
            ),
            Probe::breaking(
                'sweep-line-dropped-from-text',
                'the text report stops printing which sweep scope measured it',
                self::PRESENTER,
                ["        \$lines[] = \\sprintf('  Sweep        %s', self::sweepLine(\$report->sweep));\n" => ''],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itPrintsTheSweepScopeInBothFormats'],
            ),
            Probe::breaking(
                'sweep-request-ignored',
                'an explicit --sweep is discarded, so the command always resolves the narrow default',
                self::COMMAND,
                ["\$requestedSweep = \$input->getOption('sweep');" => '$requestedSweep = DirectiveSweepScope::Narrow->value;'],
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAcceptsAnExplicitFullSweep',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itPrintsTheSweepScopeInBothFormats',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAnUnknownSweep',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAnUnknownSweepInJson',
                ],
            ),
        ];
    }

    /**
     * The other half of the subject: what a `@qmx-ignore` did, and what the
     * command does with the answer.
     *
     * @return list<Probe>
     */
    private static function suppressions(): array
    {
        return [
            Probe::breaking(
                'suppression-never-fires',
                'a suppression that silenced a real finding is reported as silencing nothing',
                self::USAGE,
                ['                    self::anyOfTheGroupFired($file, $group, $findings) => DirectiveEffect::Effective,'
                    => '                    false => DirectiveEffect::Effective,'],
                // Every case that reads a live suppression, not just the one
                // written for the claim: the arm this breaks is the only place
                // an effective suppression is recognised, so the list is what
                // it actually reddens rather than a selection from it.
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itCallsASuppressionEffectiveWhenItSilencedAFinding',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itCallsASuppressionEffectiveWhenSomethingItCoversWasProduced',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itProjectsExactlyTheInertVerdictsIntoStaleFindings',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itReportsOneVerdictForAClassDocblockThatBoundSixDeclarations',
                ],
            ),
            Probe::breaking(
                'usage-reporting-gate-silences-verdicts',
                'the audit\'s own suppression verdicts are gated by the rule\'s post-execution reporting flag, so disabling the directive rule silences them too',
                self::PRODUCER_RULE,
                [
                    "    public function directiveVerdicts(array \$producedFindings, LevelActivity \$levelActivity): array\n"
                    . "    {\n"
                    . "        return \$this->usage->verdicts(\$this->suppressions, \$producedFindings, \$levelActivity);\n"
                    . '    }'
                    => "    public function directiveVerdicts(array \$producedFindings, LevelActivity \$levelActivity): array\n"
                    . "    {\n"
                    . "        if (\$this->usageReportingSeverity === null) {\n"
                    . "            return [];\n"
                    . "        }\n\n"
                    . "        return \$this->usage->verdicts(\$this->suppressions, \$producedFindings, \$levelActivity);\n"
                    . '    }',
                ],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itStillJudgesSuppressionsWhenTheDirectiveRuleIsDisabled'],
            ),
            Probe::breaking(
                'exit-on-an-unaskable-inert',
                'an inert verdict whose boundary was never observable still fails the build',
                self::COMMAND,
                ['if ($verdict->effect === DirectiveEffect::Inert && $verdict->boundaryObservable) {'
                    => 'if ($verdict->effect === DirectiveEffect::Inert) {'],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotFailOnAnInertVerdictWhoseBoundaryWasNotObservable'],
            ),
            Probe::breaking(
                'command-drops-the-discovery',
                'the audited file set is not the one an analysis of the same configuration would measure',
                self::COMMAND,
                ['            $prepared->fileDiscovery,' => '            null,'],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAnalysesTheSameFilesAsCheckUnderTheSameExcludes'],
            ),
            Probe::breaking(
                'suppression-never-inert',
                'a suppression that covered nothing produced is reported as doing something',
                self::USAGE,
                ['                    default => DirectiveEffect::Inert,' => '                    default => DirectiveEffect::Effective,'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itCallsASuppressionInertWhenNothingItCoversWasProduced'],
            )->alsoReddens(
                'the suppression half of the usage verdict is what the command, the ban and the stale-projection cases read',
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "a rule that declares no override support"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "an unparsable payload"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "an unresolvable name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesTheBannedChannelInsideEveryStageAfterSuppression',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itNoLongerLetsAFormWithoutARuleFilterSilenceTheBannedChannel',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itProjectsExactlyTheInertVerdictsIntoStaleFindings',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesOneDirectiveWithoutTouchingAnotherStaleOneBesideIt',
                ],
            ),
            Probe::breaking(
                'verdict-forgets-where-it-was-written',
                'the verdict names a line other than the one the author wrote on',
                self::USAGE,
                ['                            line: $directive->line,' => '                            line: 1,'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itCarriesTheSiteTheDirectiveWasWrittenAt'],
            )->alsoReddens(
                'the site is the identity a verdict is grouped, projected and refused by, so moving it moves each of those',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itGroupsAuthoredSitesTheSameWayThePolicyDoes',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itNoLongerLetsAFormWithoutARuleFilterSilenceTheBannedChannel',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itProjectsExactlyTheInertVerdictsIntoStaleFindings',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesOneDirectiveWithoutTouchingAnotherStaleOneBesideIt',
                ],
            ),
            Probe::breaking(
                'grouping-ignores-the-tag',
                'two directive forms written on one line are counted as one authored site',
                self::USAGE,
                ['            $groups[$suppression->line . "\0" . $suppression->type->value . "\0" . $suppression->rule][] = $suppression;'
                    => '            $groups[$suppression->line . "\0" . $suppression->rule][] = $suppression;'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itKeepsTwoDirectiveFormsWrittenOnOneLineApart'],
            )->alsoReddens(
                'the grouping this breaks is the one the policy-agreement case reads as well',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itGroupsAuthoredSitesTheSameWayThePolicyDoes',
                ],
            ),
            Probe::breaking(
                'grouping-splits-one-site',
                'the bindings of one authored directive are counted as several directives',
                self::USAGE,
                ['            $groups[$suppression->line . "\0" . $suppression->type->value . "\0" . $suppression->rule][] = $suppression;'
                    => '            $groups[spl_object_id($suppression)][] = $suppression;'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itGroupsAuthoredSitesTheSameWayThePolicyDoes'],
            )->alsoReddens(
                'the grouping this breaks is the one both the policy-agreement case and the class-docblock case read',
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itReportsOneVerdictForAClassDocblockThatBoundSixDeclarations',
                ],
            ),
            Probe::breaking(
                'suppression-judges-the-unaddressable-pair',
                'a channel:level pair addressability already refused is judged again',
                self::USAGE,
                ['        if ($this->levels->problemWith((string) $target) !== null) {' => '        if (false) {'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itRefusesToJudgeAChannelLevelPairAddressabilityAlreadyRefused'],
            ),
            Probe::breaking(
                'suppression-judges-every-channel',
                'a suppression with no rule filter is judged as though it named one',
                self::USAGE,
                ['        if ($target->appliesToEveryChannel()) {' => '        if (false) {'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itRefusesToJudgeADirectiveWithoutARuleFilter',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itExitsCleanWhenADirectiveCouldNotBeMeasured'],
            )->alsoReddens(
                'a suppression judged without its rule filter reaches the banned channel and the unmeasured verdict alike',
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itNoLongerLetsAFormWithoutARuleFilterSilenceTheBannedChannel',
                ],
            ),
            Probe::breaking(
                'suppression-ignores-a-disabled-producer',
                'a suppression addressing a switched-off producer is judged anyway',
                self::USAGE,
                ["        return \$sawDisabledProducer
"
                    . "            ? DirectiveUnmeasurableReason::ProducerDisabled
"
                    . '            : DirectiveUnmeasurableReason::AlreadyRefused;' => '        return null;'],
                // The same return decides the third case: a selector that
                // expands to no channel leaves the loop untouched and leaves
                // through this line, not through the pair check above.
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itRefusesToJudgeADirectiveWhoseProducerASelectorSwitchedOff',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itRefusesToJudgeADirectiveWhoseProducerOptionsSwitchedOff',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itRefusesToJudgeASelectorThatNamesNoChannelAtAll',
                ],
            ),
            Probe::breaking(
                'ban-refuses-nothing',
                'a directive addressing the channel that reports what directives did is accepted after all',
                self::BAN,
                ['        foreach ($this->identity->expand($selector) as $channel) {'
                    => '        foreach ([] as $channel) {'],
                // Declared form by form, and not by method name: the refusal
                // is one loop with no branch per form, so no narrower anchor
                // denies one spelling and leaves the rest standing. A method
                // name would be matched by any one of its twelve data sets
                // going red, and the probe would read as specific while
                // proving only that some form still refuses.
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "file, the exact name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "file, the exact name at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "file, a group that covers it"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "file, that group at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "next-line, the exact name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "next-line, the exact name at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "next-line, a group that covers it"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "next-line, that group at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "symbol, the exact name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "symbol, the exact name at file level"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "symbol, a group that covers it"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesEveryDirectiveFormThatReachesTheBannedChannel with data set "symbol, that group at file level"',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.DirectiveUsageTest::itRefusesToJudgeADirectiveThatReachesTheBannedChannel',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesOneDirectiveWithoutTouchingAnotherStaleOneBesideIt',
                ],
            ),
            Probe::breaking(
                'ban-spreads-to-configuration-errors',
                'the ban creeps onto the three neighbouring channels, which are accepted and judged inert',
                self::BAN,
                ['        return $code === InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;'
                    => "        return str_starts_with(\$code, 'annotation.');"],
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "an unresolvable name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "a rule that declares no override support"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "an unparsable payload"',
                ],
            ),
            Probe::breaking(
                'ban-yields-to-the-pair-grammar',
                'the ban is asked before the channel:level grammar, so a wrong level is answered as a ban',
                self::ADDRESSABILITY,
                [
                    '        $pairProblem = $this->levels->problemWith($raw, \sprintf(\'Suppression "%s"\', $raw));'
                    => "        \$banFirst = \$target->selector();
"
                        . "        if (\$banFirst !== null && \$this->ban->problemWith(\$raw, \$banFirst->channel()) !== null) {
"
                        . "            return \$this->ban->problemWith(\$raw, \$banFirst->channel());
"
                        . "        }
"
                        . '        $pairProblem = $this->levels->problemWith($raw, \'Suppression "\' . $raw . \'"\');',
                ],
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAnswersAnImpossiblePairAboutTheLevelRatherThanTheBan with data set "the exact name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAnswersAnImpossiblePairAboutTheLevelRatherThanTheBan with data set "a group that covers it"',
                ],
            ),
            Probe::breaking(
                'publication-silences-the-banned-channel',
                'a directive with no rule filter goes on hiding the channel it cannot name',
                self::FILTER,
                ["        if (DirectiveChannelBan::covers(\$finding->code)) {
            return false;
        }

"
                    => ''],
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itNoLongerLetsAFormWithoutARuleFilterSilenceTheBannedChannel',
                    // A refused directive is still registered with the filter,
                    // so without this branch it silences the complaint about
                    // the line below it as readily as the bare form does.
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesOneDirectiveWithoutTouchingAnotherStaleOneBesideIt',
                ],
            ),
            Probe::breaking(
                'banned-channel-lifted-out-of-the-pipeline',
                'the banned channel is treated as a configuration error, so no exclusion or baseline reaches it',
                self::PROJECTOR,
                ['        return $this->declarations->declarationFor($finding->channel())?->isConfigurationError() === true;'
                    => "        return \$this->declarations->declarationFor(\$finding->channel())?->isConfigurationError() === true
"
                        . "            || \$finding->code === 'annotation.unused-directive';"],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesTheBannedChannelInsideEveryStageAfterSuppression'],
            ),
            Probe::breaking(
                'suppression-silences-a-configuration-error',
                'a directive is called live for silencing a finding no annotation can silence',
                self::USAGE,
                ['        $findings = $this->suppressible($findings);' => ''],
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "an unresolvable name"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "a rule that declares no override support"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itDoesNotCallASuppressionOfAConfigurationErrorEffective with data set "an unparsable payload"',
                ],
            ),
            Probe::breaking(
                'guard-counts-discovered-not-analysed',
                'a scope of nothing but skipped files counts as a scope that was read',
                self::COMMAND,
                ['if ($report->coverage->analyzedFilesCount() === 0 && $report->coverage->isComplete()) {'
                    => 'if ($report->coverage->discoveredFiles() === 0) {'],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAScopeOfNothingButGeneratedFiles'],
            ),
            Probe::breaking(
                'command-accepts-any-format',
                'the command renders an unrecognised --format instead of refusing it',
                self::COMMAND,
                ['        if (!\in_array($format, self::SUPPORTED_FORMATS, true)) {' => '        if (false) {'],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAnUnknownFormat'],
            ),
            Probe::breaking(
                'command-accepts-any-sweep',
                'an unrecognised --sweep value is defaulted through instead of refused',
                self::COMMAND,
                [
                    '$sweep = DirectiveSweepScope::tryFrom($requestedSweep);'
                    => '$sweep = DirectiveSweepScope::tryFrom($requestedSweep) ?? DirectiveSweepScope::Narrow;',
                ],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAnUnknownSweep', 'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAnUnknownSweepInJson'],
            ),
            Probe::breaking(
                'command-errors-in-prose-under-json',
                'an error under --format=json is written as an <error> line rather than an envelope',
                self::COMMAND,
                ["        if (\$format === 'json') {
"
                    . '            OutputHelper::write($output, DirectiveAuditPresenter::jsonError($message, $exitCode));'
                    => "        if (false) {
"
                    . '            OutputHelper::write($output, DirectiveAuditPresenter::jsonError($message, $exitCode));'],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itPrintsTheErrorEnvelopeInJson'],
            )->alsoReddens(
                'the unknown-sweep-in-JSON case is refused through the same envelope this breakage removes',
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAnUnknownSweepInJson',
                ],
            ),
            Probe::breaking(
                'unreadable-config-is-not-a-config-error',
                'a configuration that failed to load is reported as an internal failure',
                self::FAILURE_TAXONOMY,
                ['            $failure instanceof ConfigLoadException,' => '            false,'],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itReportsAnUnreadableConfigAsAConfigurationError'],
            )->alsoReddens(
                'the JSON envelope case reaches the same failure taxonomy this breakage rewrites',
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itPrintsTheErrorEnvelopeInJson',
                ],
            ),
            Probe::breaking(
                'scope-that-read-nothing-is-clean',
                'a run that discovered no file at all reports the tree clean',
                self::COMMAND,
                ['if ($report->coverage->analyzedFilesCount() === 0 && $report->coverage->isComplete()) {'
                    => 'if (false) {'],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAScopeThatAnalysedNoFiles', 'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesAScopeOfNothingButGeneratedFiles'],
            ),
        ];
    }

    /** @return list<Probe> */
    private static function refusals(): array
    {
        return [
            Probe::breaking(
                'judge-the-unaskable',
                'a directive the addressability check already refused is judged anyway',
                self::AUDIT,
                ['if ($this->addressability->problemWithThreshold($override) !== null) {' => 'if (false) {'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeADirectiveNamingNoRule'],
            ),
            Probe::breaking(
                'ignore-disabled-producer',
                'a directive addressing a switched-off producer is judged anyway',
                self::AUDIT,
                ['return $enabled ? null : DirectiveUnmeasurableReason::ProducerDisabled;' => 'return null;'],
                [
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeADirectiveWhoseProducerIsDisabled',
                    'Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itRefusesToJudgeADirectiveWhoseProducerIsOffThroughItsOptions',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff with data set "every level of it"',
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff with data set "the whole rule"',
                ],
            )->alsoReddens(
                'the enablement check is the single read behind every case about a producer that is switched off',
                [
                    'Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff with data set "the level the directive sits on"',
                ],
            ),
            Probe::breaking(
                'producer-granularity-instead-of-level',
                'enablement is judged per producer, so a rule switched off only at the'
                . ' directive\'s level still reads as running',
                self::LEVEL_ACTIVITY,
                [
                    'return $declared ? false : !$this->disabledEverywhere($producer);' =>
                        'return !$this->disabledEverywhere($producer);',
                ],
                ['Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff with data set "the level the directive sits on"'],
            ),
            Probe::breaking(
                'judge-by-published',
                'the universe is what the report publishes rather than what the rules produced',
                self::AUDIT,
                ['        ), $restrictToProducer)->produced;' => '        ), $restrictToProducer)->published;'],
                ['Qualimetrix.Tests.Analysis.Policy.Inline.Integration.ThresholdDirectiveAuditTest::itJudgesByWhatTheRulesProducedRatherThanWhatTheyPublished'],
            ),
        ];
    }
}
