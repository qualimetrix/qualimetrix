<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Maintainability;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ParallelSafeCollectorInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Derived collector that calculates Maintainability Index.
 *
 * MI is computed from:
 * - Halstead Volume (from halstead collector)
 * - Cyclomatic Complexity (from cyclomatic-complexity collector)
 * - Method statement count (from method-statement-count collector)
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
        return ['halstead', 'cyclomatic-complexity', 'method-statement-count'];
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
        // MI is only meaningful at callable level where Halstead metrics exist.
        // At class level, TypeCoverage creates FQN entries without Halstead data,
        // causing MI to be calculated with volume=0 → MI=100 (false perfect score).
        $volume = $sourceBag->get(MetricName::MAINTAINABILITY_HALSTEAD_VOLUME);
        if ($volume === null) {
            return new MetricBag();
        }

        $statementCount = $sourceBag->get(MetricName::SIZE_METHOD_STATEMENT_COUNT);
        if ($statementCount === null) {
            return new MetricBag();
        }

        $ccn = $sourceBag->get(MetricName::COMPLEXITY_CCN) ?? 1;

        $mi = $this->calculator->calculate(
            halsteadVolume: (float) $volume,
            cyclomaticComplexity: $ccn,
            // The raw metric preserves an empty method's zero. Only the
            // logarithm boundary is clamped because ln(0) is undefined.
            linesOfCode: max(1.0, (float) $statementCount),
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
                collectedAt: SymbolLevel::Callable,
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

}
