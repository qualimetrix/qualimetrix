<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Aggregation;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;

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

            $strategies = $definition->getStrategiesForLevel($targetLevel);

            foreach ($strategies as $strategy) {
                $aggregatedValue = self::applyStrategy($strategy, $values);
                $aggregatedName = $definition->aggregatedName($strategy);
                $bag = $bag->with($aggregatedName, $aggregatedValue);
            }

            // Auto-store count alongside Average so higher levels know the sample size.
            if (\in_array(AggregationStrategy::Average, $strategies, true)
                && !\in_array(AggregationStrategy::Count, $strategies, true)
            ) {
                $countName = $definition->aggregatedName(AggregationStrategy::Count);
                $bag = $bag->with($countName, \count($values));
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
        return self::calculatePercentile($values, 0.95);
    }

    /**
     * Calculates the 5th percentile using linear interpolation.
     *
     * @param list<int|float> $values Non-empty list of values
     */
    private static function calculatePercentile5(array $values): float
    {
        return self::calculatePercentile($values, 0.05);
    }

    /**
     * Calculates the given percentile using linear interpolation.
     *
     * @param list<int|float> $values Non-empty list of values
     * @param float $percentile Percentile to calculate (0.0–1.0)
     */
    private static function calculatePercentile(array $values, float $percentile): float
    {
        sort($values);
        $count = \count($values);

        if ($count === 1) {
            return (float) $values[0];
        }

        $index = $percentile * ($count - 1);
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
     * Functions are counted in `size.symbol-method-count` because health formulas
     * divide `m["complexity.ccn.sum"]` by it for per-callable averages, and a
     * standalone function is a callable just like a method.
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
            ->with(MetricName::SIZE_SYMBOL_METHOD_COUNT, $methodCount)
            ->with(MetricName::SIZE_SYMBOL_CLASS_COUNT, $classCount);
    }

    /**
     * Adds the number of namespaces that declare at least one type.
     *
     * This is the population a namespace-collected aggregate is offered, and
     * Health reads it as a coverage denominator. It is counted from the symbols
     * because the alternative — the walk the aggregate itself makes — cannot
     * show a namespace that walk never reached, which is the only thing the
     * ratio is there to show.
     *
     * Project-level only: on a namespace bag the answer is always one.
     *
     * @param list<SymbolInfo> $symbolInfos
     */
    public static function addDeclaringNamespaceCount(MetricBag $bag, array $symbolInfos): MetricBag
    {
        $declaring = [];

        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            if ($path->type !== null && $path->member === null) {
                $declaring[$path->namespace ?? ''] = true;
            }
        }

        return $bag->with(MetricName::SIZE_SYMBOL_DECLARING_NAMESPACE_COUNT, \count($declaring));
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
}
