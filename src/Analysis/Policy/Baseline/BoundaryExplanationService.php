<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\SourceText\SourceBytes;

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
        private ChannelDeclarationRegistryInterface $declarations,
    ) {}

    public function explain(
        string $subjectKey,
        ?FindingChannel $channelFilter,
        ?Baseline $baseline,
        BoundaryThresholdSources $thresholds,
        BoundaryRunFacts $run,
    ): BoundaryExplanation {
        $measuredFindings = $run->measuredFindings;
        $identities = ExplainedSubject::identities($subjectKey, $channelFilter, $baseline, $measuredFindings);
        $subjects = ExplainedSubject::index($run->symbolLocations);
        $repositoryRecord = ExplainedSubject::recordFor($subjectKey, $subjects);
        $groups = self::groupsByIdentity($measuredFindings);
        $evidence = (new CurrentBoundaryMeasurement($this->ruleCoverage))->measure($baseline, $identities, $groups, $measuredFindings, $this->declarations, $run->coverage);
        $identityExplanation = new IdentityBoundaryExplanation($this->channels);
        $boundaries = [];
        foreach ($identities as $index => $identity) {
            [$now, $baselineSource] = $evidence[$index];
            $subject = ExplainedSubject::subjectFor($identity, $measuredFindings, $repositoryRecord);
            $boundaries[] = $identityExplanation->explain(
                $identity,
                $groups[$identity->key()] ?? [],
                $subject,
                $thresholds->thresholdOverridesByFile,
                $thresholds->configuredThresholds,
                $now,
                $baselineSource,
            );
        }

        $status = self::statusFor($subjectKey, $baseline, $measuredFindings, $repositoryRecord);
        $canonicalSpelling = null;
        $separator = strpos($subjectKey, ':');
        if ($status === BoundaryExplanationStatus::Unknown && $separator !== false) {
            $candidate = substr($subjectKey, 0, $separator + 1) . SourceBytes::escape(substr($subjectKey, $separator + 1));
            if ($candidate !== $subjectKey && self::statusFor(
                $candidate,
                $baseline,
                $measuredFindings,
                ExplainedSubject::recordFor($candidate, $subjects),
            ) !== BoundaryExplanationStatus::Unknown) {
                $canonicalSpelling = $candidate;
            }
        }

        return new BoundaryExplanation(
            $subjectKey,
            $boundaries,
            $status,
            ExplainedSubject::unidentifiedEntries($subjectKey, $channelFilter, $baseline),
            $canonicalSpelling,
        );
    }

    /**
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
