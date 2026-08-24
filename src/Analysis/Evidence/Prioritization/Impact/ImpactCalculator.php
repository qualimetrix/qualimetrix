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
 * Impact is computed as: classRank * severityWeight * debtMinutes.
 * This prioritizes findings in highly-connected classes that are severe and costly to fix.
 *
 * When classRank is unavailable for a finding, the project's median classRank is used
 * as fallback. This avoids inflating unranked findings (fallback 1.0 would dominate
 * real hotspots since typical classRank values are 0.001–0.05).
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

            // Use median classRank as fallback, or 0 if no classes have classRank at all
            $effectiveRank = $classRank ?? $medianFallback ?? 0.0;
            $impact = $effectiveRank * $severityWeight * $debtMinutes;

            $ranked[] = new RankedIssue(
                finding: $finding,
                impactScore: $impact,
                classRank: $classRank,
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
