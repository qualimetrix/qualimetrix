<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

/**
 * Describes how a metric is collected and how it should be aggregated
 * when rolling up to higher symbol levels.
 *
 * Example for CCN (Cyclomatic Complexity):
 *   - Collected at: Method level
 *   - Aggregations: Class→[Sum,Avg,Max], Namespace→[Sum,Avg,Max], Project→[Sum,Avg,Max]
 *
 * Example for classCount:
 *   - Collected at: File level
 *   - Aggregations: Namespace→[Sum], Project→[Sum]
 */
final readonly class MetricDefinition
{
    /**
     * @param string $name Base metric name (e.g., 'ccn', 'loc', 'classCount')
     * @param SymbolLevel $collectedAt Level where the metric is originally collected
     * @param array<string, list<AggregationStrategy>> $aggregations
     *                                                               Map of target level (SymbolLevel->value) to list of aggregation strategies.
     *                                                               Example: ['class' => [Sum, Average, Max], 'namespace' => [Sum, Average]]
     */
    public function __construct(
        public string $name,
        public SymbolLevel $collectedAt,
        public array $aggregations = [],
    ) {}

    /**
     * Returns the name for an aggregated metric.
     *
     * Examples:
     *   - ('ccn', Sum) → 'ccn.sum'
     *   - ('loc', Average) → 'loc.avg'
     *
     * @param AggregationStrategy $strategy The aggregation strategy applied
     *
     * @return string The aggregated metric name in format '{name}.{strategy}'
     */
    public function aggregatedName(AggregationStrategy $strategy): string
    {
        return \sprintf('%s.%s', $this->name, $strategy->value);
    }

    /**
     * Returns list of aggregation strategies for a given target level.
     *
     * @param SymbolLevel $targetLevel The level to aggregate to
     *
     * @return list<AggregationStrategy> Strategies to apply (empty if not defined)
     */
    public function getStrategiesForLevel(SymbolLevel $targetLevel): array
    {
        return $this->aggregations[$targetLevel->value] ?? [];
    }

    /**
     * Checks if this metric has any aggregations defined for the given level.
     */
    public function hasAggregationsForLevel(SymbolLevel $targetLevel): bool
    {
        return isset($this->aggregations[$targetLevel->value])
            && \count($this->aggregations[$targetLevel->value]) > 0;
    }
}
