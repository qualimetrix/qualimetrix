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
 */
final class Probes
{
    private const string AUDIT = 'src/Analysis/Policy/Inline/Directive/Audit/ThresholdDirectiveAudit.php';

    private const string COALITION = 'src/Analysis/Policy/Inline/Directive/Audit/DirectiveMaskingCoalition.php';

    private const string FINGERPRINT = 'src/Analysis/Policy/Inline/Directive/Audit/ExecutionFingerprint.php';

    private const string PIPELINE = 'src/Analysis/Run/Pipeline/AnalysisPipeline.php';

    private const string USAGE = 'src/Analysis/Policy/Inline/Directive/Audit/DirectiveUsage.php';

    private const string BAN = 'src/Analysis/Policy/Inline/Directive/DirectiveChannelBan.php';

    private const string ADDRESSABILITY = 'src/Analysis/Policy/Inline/Directive/DirectiveAddressability.php';

    private const string FILTER = 'src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php';

    private const string PROJECTOR = 'src/Reporting/FindingProjection/FindingProjector.php';

    private const string LEVEL_ACTIVITY = 'src/Analysis/Finding/Contract/LevelActivity.php';

    private const string COMMAND = 'src/Infrastructure/Console/Command/DirectivesCommand.php';

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
                'data set "plain"',
                'data set "glued to the docblock star"',
                'data set "after a multiline backtick region"',
                'data set "two on one line"',
                'data set "target cut at a call"',
                'data set "star"',
                'data set "hash"',
                'data set "colon"',
                'data set "digit"',
                'data set "capital"',
                'data set "cut target then a second directive"',
                'data set "single-line docblock"',
                'data set "comma"',
                'itMeasuresTheSamePopulationOverTheWholeFixture',
                'itScansATreeAndSkipsWhatIsNotPhp',
            ],
        ],
        'drops-the-underscore' => [
            'the second measure cuts a target at an underscore the product admits',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.*#:-',
            ['data set "underscore"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-digits' => [
            'the second measure cuts a target at a digit the product admits',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_.*#:-',
            ['data set "digit"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-capitals' => [
            'the second measure cuts a target at a capital the product admits',
            'abcdefghijklmnopqrstuvwxyz0123456789_.*#:-',
            ['data set "capital"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-the-star' => [
            'the second measure cuts a wildcard target short',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.#:-',
            ['data set "star"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-the-hash' => [
            'the second measure cuts the retired rule#code spelling short',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*:-',
            ['data set "hash"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-the-colon' => [
            'the second measure cuts a channel:level pair short, which is the pair the product refuses by name',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#-',
            ['data set "colon"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'drops-the-hyphen' => [
            'the second measure cuts a hyphenated channel short',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#:',
            ['data set "hyphen"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'admits-a-slash' => [
            'the second measure reads past a slash the product stops at',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#:-/',
            ['data set "slash"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
        ],
        'admits-a-plus' => [
            'the second measure reads past a plus the product stops at',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.*#:-+',
            ['data set "plus"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
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
                    'itAsksForEveryVerdictTheProductCanPublishAndNoOther',
                    'itNamesEveryRequirementAHomogeneousPopulationMisses',
                ],
            ),
            Probe::breaking(
                'heterogeneity-forgets-a-refusal',
                'the floor stops asking for the masking coalition, the branch it was written for',
                self::HETEROGENEITY,
                ["        'masked',\n" => ''],
                [
                    'itAsksForEveryRefusalTheProductCanPublishAndNoOther',
                    'itNamesEveryRequirementAHomogeneousPopulationMisses',
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
                ['itNamesEveryRequirementAHomogeneousPopulationMisses'],
            ),
            Probe::breaking(
                'reason-key-defaulted',
                'a report that stopped publishing refusals is read as a population carrying none',
                self::ENTRY,
                [
                    "            throw new AuditReportError(\sprintf('%s: \"%s\" is missing.', \$where, \$key));"
                    => '            return null;',
                ],
                ['itRefusesAnEntryWithoutAReasonKey'],
            ),
            Probe::breaking(
                'heterogeneity-counts-a-verdict-the-sweep-cannot-move',
                'a verdict carried only by a suppression satisfies the verdict axis',
                self::HETEROGENEITY,
                [
                    '        foreach ($report->thresholdVerdicts() as $verdict) {'
                    => '        foreach ($report->verdicts() as $verdict) {',
                ],
                ['itRefusesAVerdictCarriedOnlyByASuppression'],
            ),
            Probe::planting(
                'seeded-fixture-copied-into-src',
                'a copy of the seeded directive fixture appears under src/ and the tree enumeration swallows it',
                [self::SEEDED_LEAK => self::seededFile(self::SEEDED)],
                ['itKeepsTheSeededDirectivesOutOfTheEnumerationOverSrc'],
            ),
            Probe::planting(
                'seeded-suppression-copied-into-src',
                'a seeded @qmx-ignore appears under src/, where no enumeration would ever notice it',
                [self::SEEDED_SUPPRESSION_LEAK => self::seededFile(self::SEEDED_SUPPRESSION)],
                ['itKeepsEverySeededFixtureFileOutOfSrc'],
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
                    'itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday',
                    'itRefusesAReportWhoseThresholdVerdictsAreAllUnmeasured',
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
                    'itRefusesAVerdictValueTheFloorDoesNotName',
                    'itRefusesAnUnknownVerdictOnTheSuppressionHalf',
                    'itRefusesToJudgeAVerdictValueTheFloorCannotWeigh',
                    'itRefusesAnUnknownVerdictOnASuppressionSite',
                    'itRefusesAnUnknownVerdictWhereTheFloorIsNeverReached',
                ],
            ),
            Probe::breaking(
                'table-forgets-a-verdict',
                'the frozen table stops naming a verdict the product publishes',
                self::FLOOR,
                ["        'inert' => true,\n" => ''],
                [
                    'itNamesEveryVerdictTheProductCanPublishAndNoOther',
                    'itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday',
                ],
            ),
            Probe::breaking(
                'floor-removed',
                'a population that matches exactly is accepted even when nothing in it was measured',
                self::GATE,
                ['        if ($measured === 0) {' => '        if (false) {'],
                ['itRefusesAReportWhoseThresholdVerdictsAreAllUnmeasured'],
            ),
            Probe::breaking(
                'population-never-mismatches',
                'the two measures of the population are compared and the answer discarded',
                self::GATE,
                ['        if ($onlyAudited !== [] || $onlyEnumerated !== []) {' => '        if (false) {'],
                ['itReportsAPopulationMismatch'],
            ),
            Probe::breaking(
                'empty-population-floored',
                'a tree with no threshold directive is failed for having measured nothing',
                self::GATE,
                ['        if ($auditedSites === []) {' => '        if (false) {'],
                ['itFloorsNothingWhenNoThresholdSiteIsInScope'],
            ),
            Probe::breaking(
                'disqualified-run-judged',
                'a run the command already disqualified is judged anyway',
                self::GATE,
                ['        if ($auditExit !== 0 && $auditExit !== 2) {' => '        if (false) {'],
                ['itPropagatesARunThatWasAlreadyDisqualified'],
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
                ['itRefusesAnEnumerationThatWouldNotRun'],
            ),
            Probe::breaking(
                'no-report-read-as-a-report',
                'a run that wrote no JSON at all is answered as a malformed report',
                self::GATE,
                ['        if (!\is_array(json_decode($auditStdout, true))) {' => '        if (false) {'],
                ['itRefusesAnAuditThatProducedNoJson'],
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
                    'data set "effect missing"',
                    'data set "form missing"',
                    'data set "file missing"',
                    'data set "target missing"',
                    'data set "effect null"',
                    'data set "effect not a string"',
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
                ['data set "line not a number"'],
            ),
            Probe::breaking(
                'verdict-list-unchecked',
                'whatever stands where the verdict list should be is read as one',
                self::READER,
                [
                    '        $directives = self::directiveListOf($decoded);'
                    => "        \$directives = (array) (\$decoded['directives'] ?? []);",
                ],
                ['itRefusesAReportWhoseDirectivesAreNotAList', 'itRefusesAReportWithNoDirectivesAtAll'],
            ),
            Probe::breaking(
                'envelope-read-as-a-measurement',
                'an error envelope is read as a report that should have carried verdicts',
                self::READER,
                ["        \$errorEnvelope = isset(\$decoded['error']);" => '        $errorEnvelope = false;'],
                [
                    'itReadsAnErrorEnvelopeWithoutDemandingVerdicts',
                    'itPropagatesTheCommandsOwnCodeThroughAnErrorEnvelope',
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
                    'itReadsAWellFormedReportAsOneMeasurementAndItsContext',
                    'itAcceptsATreeWhoseSitesMatchAndWhereSomethingWasMeasured',
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
                ['itKeepsEveryEntryOfASiteAuthoredTwice'],
            ),
            Probe::breaking(
                'population-as-a-set',
                'two directives authored on one site are compared as one',
                self::POPULATION,
                [
                    '            $delta = ($leftCounts[$site] ?? 0) - ($rightCounts[$site] ?? 0);'
                    => '            $delta = min(1, $leftCounts[$site] ?? 0) - min(1, $rightCounts[$site] ?? 0);',
                ],
                ['itCountsEveryOccurrenceOfARepeatedSite', 'itSeesOneOfTwoDirectivesOnASiteGoMissing'],
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
                ['itReadsEveryEnumeratedSiteAndKeepsATabInsideItsValues'],
            ),
            Probe::breaking(
                'tsv-columns-unchecked',
                'a row short of a column is padded out instead of refused',
                self::ENUMERATION,
                [
                    '            [$file, $number, $target, $values] = self::columnsOf($line, $offset + 1);'
                    => '            [$file, $number, $target, $values] = array_pad(explode("\t", $line, 4), 4, \'\');',
                ],
                ['itRefusesAnEnumerationRowShortOfAColumn'],
            ),
            Probe::breaking(
                'tsv-line-number-untyped',
                'whatever stands in the line-number column is cast to a number',
                self::ENUMERATION,
                ["            if (preg_match('/^\d+$/', \$number) !== 1) {" => '            if (false) {'],
                ['itRefusesAnEnumerationRowWhoseLineIsNotANumber'],
            ),
            Probe::breaking(
                'tsv-empty-target-accepted',
                'a row addressing nothing is admitted to the population',
                self::ENUMERATION,
                ["            if (\$target === '') {" => '            if (false) {'],
                ['itRefusesAnEnumerationRowThatAddressesNothing'],
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
                ['itPublishesOneSummaryKeyPerVerdictTheVocabularyDefines'],
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
                ['itPrintsOneTallyPerVerdictTheVocabularyDefinesInTheTextSummary'],
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
                ['data set "glued to the docblock star"'],
            ),
            Probe::breaking(
                'scan-accepts-a-tag-with-a-suffix',
                'a word that merely contains the tag is read as a directive',
                self::SCAN,
                ['!str_ends_with($word, self::DIRECTIVE)' => '!str_contains($word, self::DIRECTIVE)'],
                ['data set "tag with a suffix"'],
            ),
            Probe::breaking(
                'scan-reads-backticked-documentation',
                'a documented example is counted as an authored directive',
                self::SCAN,
                [
                    'foreach (explode("\n", self::blankBacktickRegions($token[1])) as $offset => $line) {'
                    => 'foreach (explode("\n", $token[1]) as $offset => $line) {',
                ],
                ['data set "backticked"'],
            ),
            Probe::breaking(
                'scan-cuts-a-backtick-region-out',
                'a backtick region is removed rather than blanked, so everything below it moves up',
                self::SCAN,
                [
                    "            static fn(array \$match): string => preg_replace('/[^\\r\\n]/', ' ', \$match[0]) ?? \$match[0],"
                    => "            static fn(array \$match): string => '',",
                ],
                ['data set "after a multiline backtick region"'],
            ),
            Probe::breaking(
                'scan-splits-on-a-comma',
                'the second measure treats a comma as whitespace, so it reads values the product never saw',
                self::SCAN,
                ["    private const string WORD_SEPARATORS = \" \\t\";"
                    => "    private const string WORD_SEPARATORS = \" \\t,\";"],
                ['data set "comma"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
            ),
            Probe::breaking(
                'scan-keeps-the-docblock-terminator',
                'the values of a one-line docblock carry its terminator, which the product strips before parsing',
                self::SCAN,
                [
                    "        if (!str_ends_with(\$trimmed, '*/')) {" => '        if (true) {',
                ],
                [
                    'data set "single-line docblock"',
                    'itMeasuresTheSamePopulationOverTheWholeFixture',
                    'itScansATreeAndSkipsWhatIsNotPhp',
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
                    'data set "cut target then a second directive"',
                    'itMeasuresTheSamePopulationOverTheWholeFixture',
                ],
            ),
            Probe::breaking(
                'scan-reads-any-file-in-the-tree',
                'the tree scan stops filtering on the extension, so prose in a text file becomes a site',
                self::SCAN,
                ["            if (!\$file->isFile() || \$file->getExtension() !== 'php') {" => '            if (false) {'],
                ['itScansATreeAndSkipsWhatIsNotPhp'],
            ),
            Probe::breaking(
                'scan-skips-what-it-cannot-read',
                'a file the tree scan cannot read is passed over instead of refused, leaving a hole in the population',
                self::SCAN,
                [
                    "                throw new RuntimeException(\sprintf('unreadable: %s', \$file->getPathname()));"
                    => '                continue;',
                ],
                ['itRefusesToScanATreeItCannotRead'],
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
                ['itNamesEveryFormTheFixtureDeclares'],
            ),
            Probe::breaking(
                'scan-keeps-reading-past-a-directive',
                'the reason text of a complete directive is scanned for another one, so a quoted tag becomes a site',
                self::SCAN,
                [
                    "            if (\$address['values'] !== '' || \$address['carriesValues']) {"
                    => '            if (false) {',
                ],
                ['data set "two on one line"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
            ),
            Probe::breaking(
                'scan-admits-an-empty-target',
                'a tag followed by something no channel starts with is admitted as a site addressing nothing',
                self::SCAN,
                ["        if (\$target === '') {" => '        if (false) {'],
                ['data set "target wrapped in parens"'],
            ),
            Probe::breaking(
                'scan-reads-ordinary-comments',
                'a tag in a line comment is counted, where the product reads docblocks only',
                self::SCAN,
                [
                    '            if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {'
                    => '            if (!\is_array($token) || !\in_array($token[0], [\T_DOC_COMMENT, \T_COMMENT], true)) {',
                ],
                ['data set "outside a docblock"', 'itMeasuresTheSamePopulationOverTheWholeFixture'],
            ),
            Probe::breaking(
                'extractor-class-drops-punctuation',
                "the product's own target class stops admitting the separators it captures in order to refuse",
                self::EXTRACTOR,
                [
                    "'/@qmx-threshold\\s+([\\w.*#:-]+)(?:[ \\t]+([^\\n\\r]*))?/'"
                    => "'/@qmx-threshold\\s+([\\w.-]+)(?:[ \\t]+([^\\n\\r]*))?/'",
                ],
                ['data set "star"', 'data set "hash"', 'data set "colon"'],
            ),
            Probe::breaking(
                'extractor-class-drops-word-characters',
                "the product's own target class stops admitting digits, underscores and capitals",
                self::EXTRACTOR,
                [
                    "'/@qmx-threshold\\s+([\\w.*#:-]+)(?:[ \\t]+([^\\n\\r]*))?/'"
                    => "'/@qmx-threshold\\s+([a-z.*#:-]+)(?:[ \\t]+([^\\n\\r]*))?/'",
                ],
                ['data set "digit"', 'data set "underscore"', 'data set "capital"'],
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
                [\sprintf('itSeesEveryFieldItNames with data set "%s"', $field)],
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
                ['itNamesEveryFieldAFindingCarries'],
            ),
            Probe::breaking(
                'report-forgets-the-run',
                'the report carries an empty coverage instead of the run the verdicts came from',
                self::PIPELINE,
                [
                    "            verdicts: \$verdicts,\n            coverage: \$prepared->coverage,"
                    => "            verdicts: \$verdicts,\n            coverage: new AnalysisCoverage([], [], []),",
                ],
                ['itReportsWhatTheRunMeasuredAlongsideTheVerdicts'],
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
                ['itCallsADirectiveEffectiveWhenRemovingItChangesWhatTheRulesProduced'],
            ),
            Probe::blanket(
                'outcome-never-matched',
                'the comparison of two runs always answers "something moved"',
                self::FINGERPRINT,
                [$sameness => '        if (false) {'],
                ['itCallsADirectiveInertWhenRemovingItChangesNothing'],
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
                ['itCallsADirectiveEffectiveWhenRemovingItChangesWhatTheRulesProduced'],
            ),
            Probe::breaking(
                'first-binding-only',
                'the unit of removal is the first binding rather than the authored directive',
                self::AUDIT,
                [$filter => "                static fn(ThresholdOverride \$override): bool => \$override !== \$group->bindings[0],"],
                ['itRemovesEveryBindingOfOneAuthoredSite'],
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
                ['itCallsADirectiveOverrunWhenOnlyTheBoundaryMoved'],
            ),
            Probe::breaking(
                'recommendation-as-identity',
                'the advice a finding gives counts as part of what the finding is rather than as prose',
                self::FINGERPRINT,
                ["            \$finding->recommendation ?? '',\n        ]);" => '        ]);'],
                ['itSeesEveryFieldItNames with data set "recommendation"'],
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
                ['itSeesEveryFieldItNames with data set "severity"'],
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
                ['itRefusesToJudgeEitherDirectiveOfAMaskingPair'],
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
                ['itDoesNotCallAPairMaskedWhereTheRuleNeverReports'],
            ),
            Probe::breaking(
                'pairwise-masking',
                'only the first masker leaves the comparison, not every one',
                self::COALITION,
                [$maskerRun => '        $withoutMaskers = ExecutionFingerprint::of('
                    . '($this->without)([$maskers[0]], $restrictToProducer));'],
                ['itTakesEveryMaskerOutOfTheComparison'],
            ),
            Probe::breaking(
                'coalition-against-the-run',
                'the coalition is compared against the run instead of against itself without this directive',
                self::COALITION,
                [$maskerRun => '        $withoutMaskers = ExecutionFingerprint::of('
                    . '($this->without)([], $restrictToProducer));'],
                ['itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne'],
            ),
            Probe::breaking(
                'masker-named-by-position',
                'the neighbour reported as the masker is the first in the list rather than the measured one',
                self::COALITION,
                ['        if (\count($maskers) === 1) {' => '        if (true) {'],
                ['itNamesTheNeighbourThatActuallyHidesIt'],
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
                ['itRefusesEveryVerdictWhenTheFirstControlDoesNotReproduceTheRun'],
            ),
            Probe::breaking(
                'no-control-after',
                'the sweep ends without asking again',
                self::AUDIT,
                [
                    "        \$this->assertReproducible(\$input, \$baseline, 'after');\n\n"
                    . '        return $judged;' => '        return $judged;',
                ],
                ['itRefusesEveryVerdictWhenTheLastControlDoesNotReproduceTheRun'],
            ),
            Probe::breaking(
                'control-skips-the-rebuild',
                'the control executes against the run\'s own context instead of a rebuilt one',
                self::AUDIT,
                [
                    '$repeat = ExecutionFingerprint::of($this->without($input, []));'
                    => '$repeat = ExecutionFingerprint::of($input->executor->execute($input->baseline)->produced);',
                ],
                ['itControlsTheRunThroughTheSamePathTheCounterfactualsTake'],
            ),
            Probe::breaking(
                'no-control-narrowing',
                'the narrowed sweep is never checked against how the rule behaved inside the full run',
                self::AUDIT,
                [
                    "        \$this->assertNarrowingChangedNothing(\$input, \$narrowed, \$rule);\n\n"
                    . '        return ExecutionFingerprint::of($narrowed);' => '        return ExecutionFingerprint::of($narrowed);',
                ],
                ['itRefusesTheNarrowedSweepWhenARuleBehavesDifferentlyInIsolation'],
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
                ['itCallsADirectiveInertWhenRemovingItChangesNothing'],
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
                ['itMarksTheBoundaryUnobservableWhenTheRulePublishedNone'],
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
                ['itCallsASuppressionEffectiveWhenItSilencedAFinding'],
            ),
            Probe::breaking(
                'exit-on-an-unaskable-inert',
                'an inert verdict whose boundary was never observable still fails the build',
                self::COMMAND,
                ['if ($verdict->effect === DirectiveEffect::Inert && $verdict->boundaryObservable) {'
                    => 'if ($verdict->effect === DirectiveEffect::Inert) {'],
                ['itDoesNotFailOnAnInertVerdictWhoseBoundaryWasNotObservable'],
            ),
            Probe::breaking(
                'command-drops-the-discovery',
                'the audited file set is not the one an analysis of the same configuration would measure',
                self::COMMAND,
                ['            $prepared->fileDiscovery,' => '            null,'],
                ['itAnalysesTheSameFilesAsCheckUnderTheSameExcludes'],
            ),
            Probe::breaking(
                'suppression-never-inert',
                'a suppression that covered nothing produced is reported as doing something',
                self::USAGE,
                ['                    default => DirectiveEffect::Inert,' => '                    default => DirectiveEffect::Effective,'],
                ['itCallsASuppressionInertWhenNothingItCoversWasProduced'],
            ),
            Probe::breaking(
                'verdict-forgets-where-it-was-written',
                'the verdict names a line other than the one the author wrote on',
                self::USAGE,
                ['                            line: $directive->line,' => '                            line: 1,'],
                ['itCarriesTheSiteTheDirectiveWasWrittenAt'],
            ),
            Probe::breaking(
                'grouping-ignores-the-tag',
                'two directive forms written on one line are counted as one authored site',
                self::USAGE,
                ['            $groups[$suppression->line . "\0" . $suppression->type->value . "\0" . $suppression->rule][] = $suppression;'
                    => '            $groups[$suppression->line . "\0" . $suppression->rule][] = $suppression;'],
                ['itKeepsTwoDirectiveFormsWrittenOnOneLineApart'],
            ),
            Probe::breaking(
                'grouping-splits-one-site',
                'the bindings of one authored directive are counted as several directives',
                self::USAGE,
                ['            $groups[$suppression->line . "\0" . $suppression->type->value . "\0" . $suppression->rule][] = $suppression;'
                    => '            $groups[spl_object_id($suppression)][] = $suppression;'],
                ['itGroupsAuthoredSitesTheSameWayThePolicyDoes'],
            ),
            Probe::breaking(
                'suppression-judges-the-unaddressable-pair',
                'a channel:level pair addressability already refused is judged again',
                self::USAGE,
                ['        if ($this->levels->problemWith((string) $target) !== null) {' => '        if (false) {'],
                ['itRefusesToJudgeAChannelLevelPairAddressabilityAlreadyRefused'],
            ),
            Probe::breaking(
                'suppression-judges-every-channel',
                'a suppression with no rule filter is judged as though it named one',
                self::USAGE,
                ['        if ($target->appliesToEveryChannel()) {' => '        if (false) {'],
                ['itRefusesToJudgeADirectiveWithoutARuleFilter'],
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
                    'itRefusesToJudgeADirectiveWhoseProducerASelectorSwitchedOff',
                    'itRefusesToJudgeADirectiveWhoseProducerOptionsSwitchedOff',
                    'itRefusesToJudgeASelectorThatNamesNoChannelAtAll',
                ],
            ),
            Probe::breaking(
                'ban-refuses-nothing',
                'a directive addressing the channel that reports what directives did is accepted after all',
                self::BAN,
                ['        foreach ($this->identity->expand($selector) as $channel) {'
                    => '        foreach ([] as $channel) {'],
                [
                    'itRefusesEveryDirectiveFormThatReachesTheBannedChannel',
                    'itRefusesToJudgeADirectiveThatReachesTheBannedChannel',
                ],
            ),
            Probe::breaking(
                'ban-spreads-to-configuration-errors',
                'the ban creeps onto the three neighbouring channels, which are accepted and judged inert',
                self::BAN,
                ['        return $code === InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;'
                    => "        return str_starts_with(\$code, 'annotation.');"],
                ['itDoesNotCallASuppressionOfAConfigurationErrorEffective'],
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
                ['itAnswersAnImpossiblePairAboutTheLevelRatherThanTheBan'],
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
                ['itNoLongerLetsAFormWithoutARuleFilterSilenceTheBannedChannel'],
            ),
            Probe::breaking(
                'banned-channel-lifted-out-of-the-pipeline',
                'the banned channel is treated as a configuration error, so no exclusion or baseline reaches it',
                self::PROJECTOR,
                ['        return $this->declarations->declarationFor($finding->channel())?->isConfigurationError() === true;'
                    => "        return \$this->declarations->declarationFor(\$finding->channel())?->isConfigurationError() === true
"
                        . "            || \$finding->code === 'annotation.unused-directive';"],
                ['itLeavesTheBannedChannelInsideEveryStageAfterSuppression'],
            ),
            Probe::breaking(
                'suppression-silences-a-configuration-error',
                'a directive is called live for silencing a finding no annotation can silence',
                self::USAGE,
                ['        $findings = $this->suppressible($findings);' => ''],
                ['itDoesNotCallASuppressionOfAConfigurationErrorEffective'],
            ),
            Probe::breaking(
                'guard-counts-discovered-not-analysed',
                'a scope of nothing but skipped files counts as a scope that was read',
                self::COMMAND,
                ['if ($report->coverage->analyzedFilesCount() === 0 && $report->coverage->isComplete()) {'
                    => 'if ($report->coverage->discoveredFiles() === 0) {'],
                ['itRefusesAScopeOfNothingButGeneratedFiles'],
            ),
            Probe::breaking(
                'command-accepts-any-format',
                'the command renders an unrecognised --format instead of refusing it',
                self::COMMAND,
                ['        if (!\in_array($format, self::SUPPORTED_FORMATS, true)) {' => '        if (false) {'],
                ['itRefusesAnUnknownFormat'],
            ),
            Probe::breaking(
                'command-accepts-any-sweep',
                'an unrecognised --sweep value is defaulted through instead of refused',
                self::COMMAND,
                [
                    '$sweep = DirectiveSweepScope::tryFrom($requestedSweep);'
                    => '$sweep = DirectiveSweepScope::tryFrom($requestedSweep) ?? DirectiveSweepScope::Narrow;',
                ],
                ['itRefusesAnUnknownSweep'],
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
                ['itPrintsTheErrorEnvelopeInJson'],
            ),
            Probe::breaking(
                'unreadable-config-is-not-a-config-error',
                'a configuration that failed to load is reported as an internal failure',
                self::FAILURE_TAXONOMY,
                ['            $failure instanceof ConfigLoadException,' => '            false,'],
                ['itReportsAnUnreadableConfigAsAConfigurationError'],
            ),
            Probe::breaking(
                'scope-that-read-nothing-is-clean',
                'a run that discovered no file at all reports the tree clean',
                self::COMMAND,
                ['if ($report->coverage->analyzedFilesCount() === 0 && $report->coverage->isComplete()) {'
                    => 'if (false) {'],
                ['itRefusesAScopeThatAnalysedNoFiles', 'itRefusesAScopeOfNothingButGeneratedFiles'],
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
                ['itRefusesToJudgeADirectiveNamingNoRule'],
            ),
            Probe::breaking(
                'ignore-disabled-producer',
                'a directive addressing a switched-off producer is judged anyway',
                self::AUDIT,
                ['return $enabled ? null : DirectiveUnmeasurableReason::ProducerDisabled;' => 'return null;'],
                [
                    'itRefusesToJudgeADirectiveWhoseProducerIsDisabled',
                    'itRefusesToJudgeADirectiveWhoseProducerIsOffThroughItsOptions',
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
                ['itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff'],
            ),
            Probe::breaking(
                'judge-by-published',
                'the universe is what the report publishes rather than what the rules produced',
                self::AUDIT,
                ['        ), $restrictToProducer)->produced;' => '        ), $restrictToProducer)->published;'],
                ['itJudgesByWhatTheRulesProducedRatherThanWhatTheyPublished'],
            ),
        ];
    }
}
