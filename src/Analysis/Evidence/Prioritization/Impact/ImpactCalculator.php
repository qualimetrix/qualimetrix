<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Prioritization\Impact;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Scores and ranks findings by estimated refactoring impact.
 *
 * Impact is computed as: classRankShare * severityWeight * debtMinutes.
 * This prioritizes findings in highly-connected classes that are severe and costly to fix.
 *
 * When the share is unavailable for a finding, the project's measured median share
 * supplies a typical influence weight in the same units as ranked findings.
 */
final readonly class ImpactCalculator
{
    public function __construct(
        private ClassRankResolver $classRankResolver,
        private RemediationTimeRegistry $remediationTimeRegistry,
    ) {}

    /**
     * Computes and returns findings ranked by impact score (descending).
     *
     * Builds a classRank index once for O(1) namespace/file lookups,
     * then scores all findings and returns them sorted.
     *
     * @param list<Finding> $findings
     *
     * @return list<RankedIssue>
     */
    public function computeTopIssues(array $findings, MetricRepositoryInterface $metrics, ?NamespaceTree $tree = null): array
    {
        if ($findings === []) {
            return [];
        }

        $index = $this->classRankResolver->buildIndex($metrics, $tree);
        $medianFallback = $index->getMedianRank();
        $ranked = [];

        foreach ($findings as $finding) {
            $classRank = $this->classRankResolver->resolve($finding, $metrics, $index);
            $debtMinutes = $this->remediationTimeRegistry->getMinutesForFinding($finding);
            $severityWeight = match ($finding->severity) {
                Severity::Error => 3,
                Severity::Warning => 1,
                // Info is purely advisory — keep impact contribution minimal so
                // it never crowds out real Warning/Error issues in top-N lists.
                Severity::Info => 0,
            };

            $effectiveRank = $classRank ?? $medianFallback ?? 0.0;
            $impact = $effectiveRank * $severityWeight * $debtMinutes;

            $ranked[] = new RankedIssue(
                finding: $finding,
                impactScore: $impact,
                classRankShare: $classRank,
                debtMinutes: $debtMinutes,
                severityWeight: $severityWeight,
            );
        }

        usort($ranked, static function (RankedIssue $a, RankedIssue $b): int {
            // Primary: impact score descending
            $cmp = $b->impactScore <=> $a->impactScore;
            if ($cmp !== 0) {
                return $cmp;
            }

            // Secondary: file ascending
            $cmp = $a->finding->location->pathString() <=> $b->finding->location->pathString();
            if ($cmp !== 0) {
                return $cmp;
            }

            // Tertiary: line ascending
            return ($a->finding->location->line ?? 0) <=> ($b->finding->location->line ?? 0);
        });

        return $ranked;
    }
}
