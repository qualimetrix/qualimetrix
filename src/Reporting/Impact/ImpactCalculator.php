<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Impact;

use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;

/**
 * Scores and ranks violations by estimated refactoring impact.
 *
 * Impact is computed as: classRank * severityWeight * debtMinutes.
 * This prioritizes violations in highly-connected classes that are severe and costly to fix.
 *
 * When classRank is unavailable for a violation, the project's median classRank is used
 * as fallback. This avoids inflating unranked violations (fallback 1.0 would dominate
 * real hotspots since typical classRank values are 0.001–0.05).
 */
final readonly class ImpactCalculator
{
    public function __construct(
        private ClassRankResolver $classRankResolver,
        private RemediationTimeRegistry $remediationTimeRegistry,
    ) {}

    /**
     * Computes and returns violations ranked by impact score (descending).
     *
     * Builds a classRank index once for O(1) namespace/file lookups,
     * then scores all violations and returns them sorted.
     *
     * @param list<Violation> $violations
     *
     * @return list<RankedIssue>
     */
    public function computeTopIssues(array $violations, MetricRepositoryInterface $metrics): array
    {
        if ($violations === []) {
            return [];
        }

        $index = $this->classRankResolver->buildIndex($metrics);
        $medianFallback = $index->getMedianRank();
        $ranked = [];

        foreach ($violations as $violation) {
            $classRank = $this->classRankResolver->resolve($violation, $metrics, $index);
            $debtMinutes = $this->remediationTimeRegistry->getMinutesForViolation($violation);
            $severityWeight = $violation->severity === Severity::Error ? 3 : 1;

            // Use median classRank as fallback, or 0 if no classes have classRank at all
            $effectiveRank = $classRank ?? $medianFallback ?? 0.0;
            $impact = $effectiveRank * $severityWeight * $debtMinutes;

            $ranked[] = new RankedIssue(
                violation: $violation,
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
            $cmp = $a->violation->location->file <=> $b->violation->location->file;
            if ($cmp !== 0) {
                return $cmp;
            }

            // Tertiary: line ascending
            return ($a->violation->location->line ?? 0) <=> ($b->violation->location->line ?? 0);
        });

        return $ranked;
    }
}
