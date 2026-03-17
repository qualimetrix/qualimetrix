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
use AiMessDetector\Core\Symbol\SymbolType;

final class AggregationHelper
{
    /**
     * Applies aggregation strategies to collected values.
     *
     * @param array<string, list<int|float>> $metricValues
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<float>>|null $metricWeights parallel weights for weighted average
     */
    public static function applyAggregations(
        array $metricValues,
        array $definitions,
        SymbolLevel $targetLevel,
        ?array $metricWeights = null,
    ): MetricBag {
        $bag = new MetricBag();

        foreach ($definitions as $definition) {
            $values = $metricValues[$definition->name] ?? [];

            if ($values === []) {
                continue;
            }

            $weights = $metricWeights[$definition->name] ?? null;
            $strategies = $definition->getStrategiesForLevel($targetLevel);

            foreach ($strategies as $strategy) {
                $aggregatedValue = self::applyStrategy($strategy, $values, $weights);
                $aggregatedName = $definition->aggregatedName($strategy);
                $bag = $bag->with($aggregatedName, $aggregatedValue);
            }

            // Auto-store count alongside Average for weighted average at higher levels.
            // At Method→Class level ($weights=null): count = number of methods.
            // At Class→Namespace level ($weights present): count = sum of class-level method counts,
            // giving the total number of methods across all classes in the namespace.
            if (\in_array(AggregationStrategy::Average, $strategies, true)
                && !\in_array(AggregationStrategy::Count, $strategies, true)
            ) {
                $countName = $definition->aggregatedName(AggregationStrategy::Count);
                $weightSum = $weights !== null ? array_sum($weights) : 0.0;
                $count = $weightSum > 0.0 ? (int) $weightSum : \count($values);
                $bag = $bag->with($countName, $count);
            }
        }

        return $bag;
    }

    /**
     * Applies a single aggregation strategy to a list of values.
     *
     * @param list<int|float> $values
     * @param list<float>|null $weights parallel weights for weighted average (same length as $values)
     */
    public static function applyStrategy(AggregationStrategy $strategy, array $values, ?array $weights = null): int|float
    {
        if ($values === []) {
            return 0;
        }

        if ($strategy === AggregationStrategy::Average && $weights !== null) {
            $totalWeight = array_sum($weights);

            if ($totalWeight <= 0.0) {
                return array_sum($values) / \count($values);
            }

            $weightedSum = 0.0;

            foreach ($values as $i => $value) {
                $weightedSum += $value * ($weights[$i] ?? 1.0);
            }

            return $weightedSum / $totalWeight;
        }

        return match ($strategy) {
            AggregationStrategy::Sum => array_sum($values),
            AggregationStrategy::Average => array_sum($values) / \count($values),
            AggregationStrategy::Max => max($values),
            AggregationStrategy::Min => min($values),
            AggregationStrategy::Count => \count($values),
            AggregationStrategy::Percentile95 => self::calculatePercentile95($values),
            AggregationStrategy::Percentile5 => self::calculatePercentile5($values),
        };
    }

    /**
     * Calculates the 95th percentile using linear interpolation.
     *
     * @param list<int|float> $values Non-empty list of values
     */
    private static function calculatePercentile95(array $values): float
    {
        sort($values);
        $count = \count($values);

        if ($count === 1) {
            return (float) $values[0];
        }

        $index = 0.95 * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        return $values[$lower] + $fraction * ($values[$upper] - $values[$lower]);
    }

    /**
     * Calculates the 5th percentile using linear interpolation.
     *
     * @param list<int|float> $values Non-empty list of values
     */
    private static function calculatePercentile5(array $values): float
    {
        sort($values);
        $count = \count($values);

        if ($count === 1) {
            return (float) $values[0];
        }

        $index = 0.05 * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        return $values[$lower] + $fraction * ($values[$upper] - $values[$lower]);
    }

    /**
     * Adds method and class counts to the metric bag.
     *
     * Functions are counted in symbolMethodCount because health formulas use
     * ccn__sum / symbolMethodCount for per-callable averages, and standalone
     * functions are callables just like methods.
     *
     * @param list<SymbolInfo> $symbolInfos
     */
    public static function addSymbolCounts(MetricBag $bag, array $symbolInfos): MetricBag
    {
        $methodCount = 0;
        $classCount = 0;

        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            if ($path->member !== null) {
                // Both methods (type !== null) and functions (type === null) are callables
                $methodCount++;
            } elseif ($path->type !== null) {
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
     * Also collects weights for weighted average: when reading .avg fallback,
     * the corresponding .count is used as weight (falls back to 1.0 for backward compatibility).
     *
     * @param list<SymbolInfo> $symbolInfos Class/Method/Function symbols
     * @param list<SymbolInfo> $fileSymbols File symbols for this namespace
     * @param list<MetricDefinition> $definitions
     */
    public static function collectNamespaceMetricValues(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $fileSymbols,
        array $definitions,
    ): AggregationValues {
        $values = [];
        $weights = [];

        foreach ($definitions as $definition) {
            $values[$definition->name] = [];
            $weights[$definition->name] = [];
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

                // For method-collected metrics, read aggregated values from class level.
                // Sum is preferred for additive metrics (CCN, LOC) — total per class
                // feeds into namespace-level aggregation. For non-additive metrics (MI)
                // that only have Average at class level, fall back to Average.
                if ($definition->collectedAt === SymbolLevel::Method) {
                    $sumValue = $bag->get($definition->aggregatedName(AggregationStrategy::Sum));

                    if ($sumValue !== null) {
                        $values[$definition->name][] = $sumValue;
                        $weights[$definition->name][] = 1.0;
                    } else {
                        $avgValue = $bag->get($definition->aggregatedName(AggregationStrategy::Average));

                        if ($avgValue !== null) {
                            $values[$definition->name][] = $avgValue;
                            // Use .count as weight for weighted average; fall back to 1.0
                            $count = $bag->get($definition->aggregatedName(AggregationStrategy::Count));
                            $weights[$definition->name][] = $count !== null && $count > 0 ? (float) $count : 1.0;
                        }
                    }
                }

                // For Class-collected metrics, read directly from class
                if ($definition->collectedAt === SymbolLevel::Class_) {
                    $value = $bag->get($definition->name);

                    if ($value !== null) {
                        $values[$definition->name][] = $value;
                        $weights[$definition->name][] = 1.0;
                    }
                }
            }
        }

        // Collect from standalone functions (type === null, member !== null).
        // Functions have no parent class, so no pre-aggregated .sum/.avg exists.
        // Read raw metric values directly from the function's bag.
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            if ($path->getType() !== SymbolType::Function_) {
                continue;
            }

            $bag = $repository->get($path);

            foreach ($definitions as $definition) {
                if ($definition->collectedAt !== SymbolLevel::Method) {
                    continue;
                }

                $value = $bag->get($definition->name);

                if ($value !== null) {
                    $values[$definition->name][] = $value;
                    $weights[$definition->name][] = 1.0;
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
                    $weights[$definition->name][] = 1.0;
                }
            }
        }

        return new AggregationValues($values, $weights);
    }
}
