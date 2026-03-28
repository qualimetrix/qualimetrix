<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

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
    /** @var list<string> */
    private const array HEALTH_KEYS = [
        'health.overall',
        'health.complexity',
        'health.cohesion',
        'health.coupling',
        'health.typing',
        'health.maintainability',
    ];

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
        if (isset($node->metrics['loc.sum'])) {
            return;
        }

        $sum = 0;
        $hasValue = false;

        foreach ($node->children as $child) {
            $childLoc = $child->metrics['loc.sum'] ?? null;
            if ($childLoc !== null) {
                $sum += $childLoc;
                $hasValue = true;
            }
        }

        if ($hasValue) {
            $node->metrics['loc.sum'] = $sum;
        }
    }

    private function aggregateHealthScores(HtmlTreeNode $node): void
    {
        foreach (self::HEALTH_KEYS as $key) {
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

                $weight = (float) ($child->metrics['loc.sum'] ?? 1);
                $weightedSum += $score * $weight;
                $totalWeight += $weight;
            }

            if ($totalWeight > 0) {
                $node->metrics[$key] = $weightedSum / $totalWeight;
            }
        }
    }
}
