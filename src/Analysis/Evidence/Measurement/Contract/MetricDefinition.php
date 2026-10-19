<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use LogicException;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Describes how a metric is collected and how it should be aggregated
 * when rolling up to higher symbol levels.
 *
 * Example for CCN (Cyclomatic Complexity):
 *   - Collected at: Callable level
 *   - Aggregations: Class→[Sum,Avg,Max], Namespace→[Sum,Avg,Max], Project→[Sum,Avg,Max]
 *
 * Example for classCount:
 *   - Collected at: File level
 *   - Aggregations: Namespace→[Sum], Project→[Sum]
 */
final readonly class MetricDefinition
{
    /**
     * @param string $name Base metric name (e.g., 'complexity.ccn', 'size.loc', 'size.class-count')
     * @param SymbolLevel $collectedAt Level where the metric is originally collected
     * @param array<string, list<AggregationStrategy>> $aggregations
     *                                                               Map of target level (SymbolLevel->value) to list of aggregation strategies.
     *                                                               Example: ['class' => [Sum, Average, Max], 'namespace' => [Sum, Average]]
     * @param list<SymbolLevel> $directPublicationLevels Additional levels that publish the base key directly
     */
    public function __construct(
        public string $name,
        public SymbolLevel $collectedAt,
        public array $aggregations = [],
        public bool $namespaceFileContribution = false,
        public ?ClassKeyScope $classKeyScope = null,
        public array $directPublicationLevels = [],
    ) {
        $seenLevels = [$collectedAt->value => true];
        foreach ($directPublicationLevels as $level) {
            if (isset($seenLevels[$level->value])) {
                throw new LogicException('Direct publication levels must be unique and distinct from the collected level');
            }
            $seenLevels[$level->value] = true;
        }

        if ($namespaceFileContribution) {
            foreach ($this->getStrategiesForLevel(SymbolLevel::Namespace_) as $strategy) {
                if (\in_array($strategy, [AggregationStrategy::Max, AggregationStrategy::Min, AggregationStrategy::Percentile95, AggregationStrategy::Percentile5], true)) {
                    throw new LogicException('Namespace file contributions support only sum, count and average');
                }
            }
        }
    }

    /** @return list<SymbolLevel> */
    public function publicationLevels(): array
    {
        return [$this->collectedAt, ...$this->directPublicationLevels];
    }

    /** @return list<string> */
    public function publishedSuffixes(SymbolLevel $level): array
    {
        $strategies = $this->getStrategiesForLevel($level);
        if (\in_array(AggregationStrategy::Average, $strategies, true)
            && !\in_array(AggregationStrategy::Count, $strategies, true)) {
            $strategies[] = AggregationStrategy::Count;
        }

        return array_map(static fn(AggregationStrategy $strategy): string => $strategy->value, $strategies);
    }

    /**
     * Returns the name for an aggregated metric.
     *
     * Examples:
     *   - ('complexity.ccn', Sum) → 'complexity.ccn.sum'
     *   - ('size.loc', Average) → 'size.loc.avg'
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
