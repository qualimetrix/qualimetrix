<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Symbol\SymbolInfo;

final class AggregationHelper
{
    /**
     * Applies aggregation strategies to collected values.
     *
     * @param array<string, list<int|float>> $metricValues
     * @param list<MetricDefinition> $definitions
     */
    public static function applyAggregations(
        array $metricValues,
        array $definitions,
        SymbolLevel $targetLevel,
    ): MetricBag {
        $bag = new MetricBag();

        foreach ($definitions as $definition) {
            $values = $metricValues[$definition->name] ?? [];

            if ($values === []) {
                continue;
            }

            foreach ($definition->getStrategiesForLevel($targetLevel) as $strategy) {
                $aggregatedValue = self::applyStrategy($strategy, $values);
                $aggregatedName = $definition->aggregatedName($strategy);
                $bag = $bag->with($aggregatedName, $aggregatedValue);
            }
        }

        return $bag;
    }

    /**
     * Applies a single aggregation strategy to a list of values.
     *
     * @param list<int|float> $values
     */
    public static function applyStrategy(AggregationStrategy $strategy, array $values): int|float
    {
        if ($values === []) {
            return 0;
        }

        return match ($strategy) {
            AggregationStrategy::Sum => array_sum($values),
            AggregationStrategy::Average => array_sum($values) / \count($values),
            AggregationStrategy::Max => max($values),
            AggregationStrategy::Min => min($values),
            AggregationStrategy::Count => \count($values),
        };
    }

    /**
     * Adds method and class counts to the metric bag.
     *
     * @param list<SymbolInfo> $symbolInfos
     */
    public static function addSymbolCounts(MetricBag $bag, array $symbolInfos): MetricBag
    {
        $methodCount = 0;
        $classCount = 0;

        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            if ($path->type !== null && $path->member !== null) {
                $methodCount++;
            } elseif ($path->type !== null && $path->member === null) {
                $classCount++;
            }
        }

        return $bag
            ->with('symbolMethodCount', $methodCount)
            ->with('symbolClassCount', $classCount);
    }

    /**
     * Collects all metric definitions from all collectors.
     *
     * @param list<MetricCollectorInterface> $collectors
     *
     * @return list<MetricDefinition>
     */
    public static function collectDefinitions(array $collectors): array
    {
        $definitions = [];

        foreach ($collectors as $collector) {
            foreach ($collector->getMetricDefinitions() as $definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Collects raw metric values from symbols.
     *
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     *
     * @return array<string, list<int|float>> metric name => values
     */
    public static function collectMetricValues(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
    ): array {
        $values = [];

        foreach ($definitions as $definition) {
            $values[$definition->name] = [];
        }

        foreach ($symbolInfos as $info) {
            $bag = $repository->get($info->symbolPath);

            foreach ($definitions as $definition) {
                $value = $bag->get($definition->name);

                if ($value !== null) {
                    $values[$definition->name][] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Collects metric values for namespace/project aggregation.
     *
     * For Method-collected metrics, reads from Class level (ccn.sum, ccn.avg, etc.)
     * For Class-collected metrics, reads directly from Class level.
     * For File-collected metrics, reads directly from File level.
     *
     * @param list<SymbolInfo> $symbolInfos Class/Method symbols
     * @param list<SymbolInfo> $fileSymbols File symbols for this namespace
     * @param list<MetricDefinition> $definitions
     *
     * @return array<string, list<int|float>> metric name => values
     */
    public static function collectNamespaceMetricValues(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $fileSymbols,
        array $definitions,
    ): array {
        $values = [];

        foreach ($definitions as $definition) {
            $values[$definition->name] = [];
        }

        // Collect from class/method symbols
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;
            $bag = $repository->get($path);

            foreach ($definitions as $definition) {
                // Must be a class symbol (not method) for class/method-level metrics
                if ($path->type === null || $path->member !== null) {
                    continue;
                }

                // By design: Sum aggregates method-level metrics to class level
                // (e.g., total CCN per class). This is the correct strategy for
                // namespace-level aggregation of method-collected metrics.
                if ($definition->collectedAt === SymbolLevel::Method) {
                    $sumValue = $bag->get($definition->aggregatedName(AggregationStrategy::Sum));

                    if ($sumValue !== null) {
                        $values[$definition->name][] = $sumValue;
                    }
                }

                // For Class-collected metrics, read directly from class
                if ($definition->collectedAt === SymbolLevel::Class_) {
                    $value = $bag->get($definition->name);

                    if ($value !== null) {
                        $values[$definition->name][] = $value;
                    }
                }
            }
        }

        // Collect from file symbols
        foreach ($fileSymbols as $fileInfo) {
            $bag = $repository->get($fileInfo->symbolPath);

            foreach ($definitions as $definition) {
                // Only read from File symbols for File-collected metrics
                if ($definition->collectedAt !== SymbolLevel::File) {
                    continue;
                }

                $value = $bag->get($definition->name);

                if ($value !== null) {
                    $values[$definition->name][] = $value;
                }
            }
        }

        return $values;
    }
}
