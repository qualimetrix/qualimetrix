<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Maintainability;

use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\ParallelSafeCollectorInterface;
use Qualimetrix\Core\Metric\SymbolLevel;

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
final class MaintainabilityIndexCollector implements DerivedCollectorInterface, ParallelSafeCollectorInterface
{
    private const NAME = 'maintainability-index';

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
        return [MetricName::MAINTAINABILITY_MI];
    }

    public function calculate(MetricBag $sourceBag): MetricBag
    {
        // MI is only meaningful at method level where Halstead metrics exist.
        // At class level, TypeCoverage creates FQN entries without Halstead data,
        // causing MI to be calculated with volume=0 → MI=100 (false perfect score).
        $volume = $sourceBag->get(MetricName::HALSTEAD_VOLUME);
        if ($volume === null) {
            return new MetricBag();
        }

        $ccn = $sourceBag->get(MetricName::COMPLEXITY_CCN) ?? 1;

        // Get method LOC - use real value from HalsteadVisitor if available
        $methodLoc = $sourceBag->get(MetricName::HALSTEAD_METHOD_LOC);
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

        return (new MetricBag())->with(MetricName::MAINTAINABILITY_MI, $mi);
    }

    /**
     * @return list<MetricDefinition>
     */
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::MAINTAINABILITY_MI,
                collectedAt: SymbolLevel::Method,
                aggregations: [
                    SymbolLevel::Class_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                    ],
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                        AggregationStrategy::Percentile5,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Min,
                        AggregationStrategy::Percentile5,
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
