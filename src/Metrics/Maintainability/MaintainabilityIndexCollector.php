<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Maintainability;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;

/**
 * Derived collector that calculates Maintainability Index.
 *
 * MI is computed from:
 * - Halstead Volume (from halstead collector)
 * - Cyclomatic Complexity (from cyclomatic-complexity collector)
 * - Lines of Code (from halstead collector's methodLoc, or estimated)
 *
 * Formula: MI = 171 - 5.2×ln(V) - 0.23×CCN - 16.2×ln(LOC)
 * Normalized to 0-100 scale.
 */
final class MaintainabilityIndexCollector implements DerivedCollectorInterface
{
    private const NAME = 'maintainability-index';
    private const METRIC_MI = 'mi';

    private MaintainabilityIndexCalculator $calculator;

    public function __construct()
    {
        $this->calculator = new MaintainabilityIndexCalculator();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['halstead', 'cyclomatic-complexity'];
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [self::METRIC_MI];
    }

    public function calculate(MetricBag $sourceBag): MetricBag
    {
        // Get required metrics
        $volume = $sourceBag->get('halstead.volume') ?? 0.0;
        $ccn = $sourceBag->get('ccn') ?? 1;

        // Get method LOC - use real value from HalsteadVisitor if available
        $methodLoc = $sourceBag->get('methodLoc');
        if ($methodLoc !== null && $methodLoc > 0) {
            $loc = (float) $methodLoc;
        } else {
            // Fallback to estimate if methodLoc is not available
            $loc = $this->estimateLoc($volume, $ccn);
        }

        $mi = $this->calculator->calculate(
            halsteadVolume: (float) $volume,
            cyclomaticComplexity: $ccn,
            linesOfCode: $loc,
        );

        return (new MetricBag())->with(self::METRIC_MI, $mi);
    }

    /**
     * @return list<MetricDefinition>
     */
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: self::METRIC_MI,
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                    ],
                ],
            ),
        ];
    }

    /**
     * Estimates LOC from Halstead Volume and CCN.
     *
     * This is a rough estimate. In a complete implementation,
     * actual LOC would be measured by the LOC collector.
     *
     * Heuristic: Volume correlates with LOC, CCN adds branching overhead.
     */
    private function estimateLoc(float|int $volume, float|int $ccn): float
    {
        if ($volume <= 0) {
            // Empty method
            return 1.0;
        }

        // Rough estimate: Volume ~ LOC * log2(vocabulary)
        // For typical code, vocabulary ~ 10-50, so log2 ~ 3-6
        // We use a simplified estimate
        $baseLoc = $volume / 5.0;

        // Add some lines for control flow structures
        $controlFlowLines = max(0, ($ccn - 1) * 2);

        return max(1.0, $baseLoc + $controlFlowLines);
    }
}
