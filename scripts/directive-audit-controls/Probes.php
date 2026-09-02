<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

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
    private const string AUDIT = 'src/Analysis/Policy/Inline/Directive/ThresholdDirectiveAudit.php';

    private const string COALITION = 'src/Analysis/Policy/Inline/Directive/DirectiveMaskingCoalition.php';

    private const string FINGERPRINT = 'src/Analysis/Policy/Inline/Directive/ExecutionFingerprint.php';

    private const string PIPELINE = 'src/Analysis/Run/Pipeline/AnalysisPipeline.php';

    private const string USAGE = 'src/Analysis/Policy/Inline/Directive/DirectiveUsage.php';

    private const string LEVEL_ACTIVITY = 'src/Analysis/Finding/Contract/LevelActivity.php';

    private const string COMMAND = 'src/Infrastructure/Console/Command/DirectivesCommand.php';

    private const string FAILURE_TAXONOMY = 'src/Infrastructure/Console/ConfigurationFailure.php';

    private const string TALLY = 'src/Infrastructure/Console/DirectiveVerdictTally.php';

    private const string FLOOR = 'scripts/directive-audit/MeasuredEffects.php';

    private const string READER = 'scripts/directive-audit/VerdictReport.php';

    private const string ENTRY = 'scripts/directive-audit/AuditedVerdict.php';

    private const string ENUMERATION = 'scripts/directive-audit/SiteEnumeration.php';

    private const string POPULATION = 'scripts/directive-audit/Population.php';

    private const string GATE = 'scripts/directive-audit/Gate.php';

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
        ];
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
        $filter = "                static fn(ThresholdOverride \$override): bool => \$override->line !== \$group['line']\n"
            . "                    || \$override->rulePattern !== \$group['rule'],";

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
                [$filter => "                static fn(ThresholdOverride \$override): bool => \$override !== \$group['bindings'][0],"],
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
                    "                boundaryObservable: \$entry['effect'] === DirectiveEffect::Overrun
"
                    . "                    || self::boundaryObservable(\$group, \$produced),"
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
                ['                    self::anyOfTheGroupFired($file, $group, self::withoutOwnComplaint($file, $directive, $findings))'
                    => '                    false'],
                [
                    'itCallsASuppressionEffectiveWhenItSilencedAFinding',
                    'itJudgesASuppressionOfTheChannelProducedAfterRuleExecution',
                ],
            ),
            Probe::breaking(
                'universe-drops-the-late-channel',
                'the universe is the executor\'s own set, so the channel assembled after execution is invisible',
                self::PIPELINE,
                ["        \$produced = [\n"
                    . "            ...\$prepared->ruleExecution->produced,\n"
                    . "            ...\$this->ruleProducerPreparation->auditInlineDirectives(\n"
                    . "                \$prepared->ruleExecution->produced,\n"
                    . "                \$prepared->ruleExecution->levelActivity,\n"
                    . "            ),\n"
                    . '        ];' => '        $produced = $prepared->ruleExecution->produced;'],
                ['itJudgesASuppressionOfTheChannelProducedAfterRuleExecution'],
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
                'directive-justifies-itself',
                'a directive is credited with silencing the complaint it produced by being dead',
                self::USAGE,
                ['                    self::anyOfTheGroupFired($file, $group, self::withoutOwnComplaint($file, $directive, $findings))'
                    => '                    self::anyOfTheGroupFired($file, $group, $findings)'],
                ['itDoesNotLetADirectiveJustifyItselfWithItsOwnComplaint'],
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
