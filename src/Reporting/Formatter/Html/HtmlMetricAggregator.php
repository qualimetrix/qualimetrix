<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Qualimetrix\Core\ComputedMetric\HealthDimension;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricName;

/**
 * Aggregates metrics bottom-up through the HTML tree hierarchy.
 *
 * - loc.sum: summed from children
 * - health.*: weighted average by loc.sum (so larger modules weigh more)
 *
 * @internal
 */
final readonly class HtmlMetricAggregator
{
    /**
     * Aggregates metrics bottom-up for intermediate namespace nodes (post-order traversal).
     */
    public function aggregateBottomUp(HtmlTreeNode $node): void
    {
        // Recurse into children first (post-order)
        foreach ($node->children as $child) {
            $this->aggregateBottomUp($child);
        }

        if ($node->children === []) {
            return;
        }

        $this->aggregateLocSum($node);
        $this->aggregateHealthScores($node);
    }

    private function aggregateLocSum(HtmlTreeNode $node): void
    {
        if (isset($node->metrics[MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)])) {
            return;
        }

        $sum = 0;
        $hasValue = false;

        foreach ($node->children as $child) {
            $childLoc = $child->metrics[MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)] ?? null;
            if ($childLoc !== null) {
                $sum += $childLoc;
                $hasValue = true;
            }
        }

        if ($hasValue) {
            $node->metrics[MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)] = $sum;
        }
    }

    private function aggregateHealthScores(HtmlTreeNode $node): void
    {
        foreach (HealthDimension::all() as $dim) {
            $key = $dim->value;
            if (isset($node->metrics[$key])) {
                continue; // Already has this metric from the repository
            }

            $weightedSum = 0.0;
            $totalWeight = 0.0;

            foreach ($node->children as $child) {
                $score = $child->metrics[$key] ?? null;
                if ($score === null) {
                    continue;
                }

                $weight = (float) ($child->metrics[MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum)] ?? 1);
                $weightedSum += $score * $weight;
                $totalWeight += $weight;
            }

            if ($totalWeight > 0) {
                $node->metrics[$key] = $weightedSum / $totalWeight;
            }
        }
    }
}
