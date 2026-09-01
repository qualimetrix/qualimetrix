<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

/**
 * The controls, as a list.
 *
 * A positive probe and seventeen breakages, one per claim the threshold audit
 * makes. The list is not a design: every entry here was planted by hand during
 * P2 and its three rounds of execution review, and every one of them reddened
 * something. Eight of the seventeen exist because a reviewer found the claim
 * broken — the masking mechanism alone was rewritten twice, and each edition
 * passed the previous edition's controls.
 *
 * Each probe names the cases it must redden. That is stricter than "reddens
 * something": a breakage that fails the suite somewhere else proves the suite
 * notices damage, not that the claim it broke is guarded.
 */
final class Probes
{
    private const string AUDIT = 'src/Analysis/Policy/Inline/Directive/ThresholdDirectiveAudit.php';

    private const string FINGERPRINT = 'src/Analysis/Policy/Inline/Directive/ExecutionFingerprint.php';

    private const string PIPELINE = 'src/Analysis/Run/Pipeline/AnalysisPipeline.php';

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
            ...self::refusals(),
            ...self::report(),
        ];
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
            Probe::breaking(
                'outcome-always-matched',
                'the comparison of two runs always answers "nothing moved"',
                self::FINGERPRINT,
                [$sameness => '        if (true) {'],
                ['itCallsADirectiveEffectiveWhenRemovingItChangesWhatTheRulesProduced'],
            ),
            Probe::breaking(
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
                'the advice a finding gives counts as part of what the finding is',
                self::FINGERPRINT,
                ["            \$finding->recommendation ?? '',\n        ]);" => '        ]);'],
                ['itSeesEveryFieldItNames with data set "recommendation"'],
            ),
            Probe::breaking(
                'identity-without-related-locations',
                'one field of a finding is missing from the key',
                self::FINGERPRINT,
                ["            implode('|', array_map(self::location(...), \$finding->relatedLocations))," => "            '',"],
                ['itSeesEveryFieldItNames with data set "relatedLocations"'],
            ),
        ];
    }

    /** @return list<Probe> */
    private static function masking(): array
    {
        $maskerRun = '        $withoutMaskers = ExecutionFingerprint::of($this->without($input, $maskers));';

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
                self::AUDIT,
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
                self::AUDIT,
                [$maskerRun => '        $withoutMaskers = ExecutionFingerprint::of($this->without($input, [$maskers[0]]));'],
                ['itTakesEveryMaskerOutOfTheComparison'],
            ),
            Probe::breaking(
                'coalition-against-the-run',
                'the coalition is compared against the run instead of against itself without this directive',
                self::AUDIT,
                [$maskerRun => '        $withoutMaskers = ExecutionFingerprint::of($input->baselineResult->produced);'],
                ['itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne'],
            ),
            Probe::breaking(
                'masker-named-by-position',
                'the neighbour reported as the masker is the first in the list rather than the measured one',
                self::AUDIT,
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
                'judge-by-published',
                'the universe is what the report publishes rather than what the rules produced',
                self::AUDIT,
                ['        ))->produced;' => '        ))->published;'],
                ['itJudgesByWhatTheRulesProducedRatherThanWhatTheyPublished'],
            ),
        ];
    }
}
