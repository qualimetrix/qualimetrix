<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

use Qualimetrix\Core\ComputedMetric\ComputedMetricDefaults;

/**
 * Builds a human-readable reason string from health dimension scores.
 */
final readonly class HealthReasonBuilder
{
    public function __construct(
        private MetricHintProvider $hintProvider,
    ) {}

    /**
     * Builds a reason string listing up to 2 worst health dimensions
     * that fall below their warning thresholds.
     *
     * @param array<string, float> $dimensionScores Dimension name => score (e.g., ['complexity' => 35.2])
     */
    public function buildReason(array $dimensionScores): string
    {
        if ($dimensionScores === []) {
            return '';
        }

        $defaults = ComputedMetricDefaults::getDefaults();
        $ranked = [];

        foreach ($dimensionScores as $dim => $score) {
            $defKey = 'health.' . $dim;
            $warnThreshold = isset($defaults[$defKey]) ? ($defaults[$defKey]->warningThreshold ?? 50.0) : 50.0;
            // How far above the warning threshold (negative = bad, zero = at threshold = bad)
            $delta = $score - $warnThreshold;
            $ranked[] = ['dim' => $dim, 'delta' => $delta];
        }

        // Sort by delta ascending (worst first)
        usort($ranked, static fn(array $a, array $b): int => $a['delta'] <=> $b['delta']);

        $reasons = [];

        foreach (\array_slice($ranked, 0, 2) as $item) {
            if ($item['delta'] > 0) {
                // Above warning threshold — not a problem
                continue;
            }

            $reasons[] = $this->hintProvider->getHealthDimensionLabel($item['dim'], true);
        }

        return implode(', ', $reasons);
    }
}
