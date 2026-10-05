<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;

/**
 * Builds a {@see BoundaryExplanation} for `bin/qmx baseline:explain`, as
 * specified by ADR 0017: the effective boundary for a symbol, and where
 * every part of it comes from.
 *
 * Annotation ownership is resolved by exact typed subject. A current
 * finding is the first source even when its occurrence or dependency edge
 * differs from the baseline identity; the repository supplies the same exact
 * subject when no current finding does. Logical projections never invent a
 * declaration subject. Which identities and which subject those are is
 * {@see ExplainedSubject}'s answer, not this class's.
 *
 * @phpstan-import-type SubjectRecord from ExplainedSubject
 */
final readonly class BoundaryExplanationService
{
    /**
     * @param ChannelIdentityInterface $channels the registry edge from a channel to the rule
     *                                           that produces it — a channel is one name now,
     *                                           and `@qmx-threshold` addresses the producing
     *                                           rule, so the two are joined here rather than
     *                                           read off two halves of a key
     */
    public function __construct(
        private ChannelIdentityInterface $channels,
        private RunRuleCoverage $ruleCoverage,
    ) {}

    /**
     * @qmx-threshold code-smell.long-parameter-list 10 -- Nine independent invocation facts preserve exact subject, channel and coverage evidence; combining them introduces shared state. The next parameter reports again.
     *
     * @param list<Finding> $measuredFindings the measured set (ADR 0017) this run produced —
     *                                        both the "currently compared" magnitudes of
     *                                        ADR 0017 and the first exact typed subject for
     *                                        annotation ownership come from here
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile per-file
     *                                                                         `@qmx-threshold`
     *                                                                         overrides — read
     *                                                                         straight off
     *                                                                         `DirectiveObservations::$thresholdOverrides`
     * @param array<string, array<string, int|float>> $configuredThresholds the rule's `qmx.yaml`-configured
     *                                                                      boundary, keyed by channel name;
     *                                                                      a channel absent from this map
     *                                                                      reports {@see EffectiveBoundary::$configuredThreshold}
     *                                                                      as `null`
     * @param ?MetricRepositoryInterface $symbolLocations the run's measured exact subjects;
     *                                                    repository evidence is the fallback
     *                                                    when no current finding has that
     *                                                    canonical subject
     */
    public function explain(
        string $subjectKey,
        ?FindingChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredFindings,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ChannelDeclarationRegistryInterface $declarations,
        RunCoverage $coverage,
        ?MetricRepositoryInterface $symbolLocations = null,
    ): BoundaryExplanation {
        $identities = ExplainedSubject::identities($subjectKey, $channelFilter, $baseline, $measuredFindings);
        $repositoryRecord = ExplainedSubject::recordFor($subjectKey, ExplainedSubject::index($symbolLocations));
        $groups = self::groupsByIdentity($measuredFindings);
        $evidence = (new CurrentBoundaryMeasurement($this->ruleCoverage))->measure($baseline, $identities, $groups, $measuredFindings, $declarations, $coverage);
        $identityExplanation = new IdentityBoundaryExplanation($this->channels);
        $boundaries = [];
        foreach ($identities as $index => $identity) {
            [$now, $baselineSource] = $evidence[$index];
            $subject = ExplainedSubject::subjectFor($identity, $measuredFindings, $repositoryRecord);
            $boundaries[] = $identityExplanation->explain(
                $identity,
                $groups[$identity->key()] ?? [],
                $subject,
                $thresholdOverridesByFile,
                $configuredThresholds,
                $now,
                $baselineSource,
            );
        }

        return new BoundaryExplanation(
            $subjectKey,
            $boundaries,
            self::statusFor($subjectKey, $baseline, $measuredFindings, $repositoryRecord),
            ExplainedSubject::unidentifiedEntries($subjectKey, $channelFilter, $baseline),
        );
    }

    /**
     * @qmx-threshold code-smell.long-parameter-list 10 -- Nine independent invocation facts preserve exact subject, channel and coverage evidence; combining them introduces shared state. The next parameter reports again.
     *
     * @param list<Finding> $measuredFindings
     * @param ?SubjectRecord $repositoryRecord
     */
    private static function statusFor(
        string $symbolKey,
        ?Baseline $baseline,
        array $measuredFindings,
        ?array $repositoryRecord,
    ): BoundaryExplanationStatus {
        if ($repositoryRecord !== null || array_any(
            $measuredFindings,
            static fn(Finding $finding): bool => $finding->subject->toCanonical() === $symbolKey,
        )) {
            return BoundaryExplanationStatus::Current;
        }

        if ($baseline !== null && (array_any(
            $baseline->entries,
            static fn(BaselineEntry $entry): bool => $entry->identity->subjectKey === $symbolKey,
        ) || array_any(
            $baseline->inertEntries,
            static fn(InertBaselineEntry $entry): bool => $entry->subjectKey === $symbolKey,
        ))) {
            return BoundaryExplanationStatus::BaselineOnly;
        }

        return BoundaryExplanationStatus::Unknown;
    }

    /**
     * The measured findings of each identity, in run order. Built once per
     * explanation: a block of N duplicate copies is N identities, and
     * filtering the whole measured set for each would cost N² identity keys.
     *
     * @param list<Finding> $measuredFindings
     *
     * @return array<string, list<Finding>>
     */
    private static function groupsByIdentity(array $measuredFindings): array
    {
        $groups = [];
        foreach ($measuredFindings as $finding) {
            $groups[BaselineIdentity::forFinding($finding)->key()][] = $finding;
        }

        return $groups;
    }

}
