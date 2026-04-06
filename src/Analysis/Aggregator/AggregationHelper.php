<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\AggregationMeta;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

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
            ->with(AggregationMeta::SYMBOL_METHOD_COUNT, $methodCount)
            ->with(AggregationMeta::SYMBOL_CLASS_COUNT, $classCount);
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
     * For Method-collected metrics, reads raw values from method symbols directly.
     * This ensures .max, .avg, .p95 reflect per-method statistics (not per-class sums).
     * For Class-collected metrics, reads directly from Class level.
     * For File-collected metrics, reads directly from File level.
     *
     * @param list<SymbolInfo> $symbolInfos Class/Method/Function symbols
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

        self::collectFromMethods($repository, $symbolInfos, $definitions, $values);
        self::collectFromClassSymbols($repository, $symbolInfos, $definitions, $values);
        self::collectFromFunctions($repository, $symbolInfos, $definitions, $values);
        self::collectFromFileSymbols($repository, $fileSymbols, $definitions, $values);

        return $values;
    }

    /**
     * Collects raw metric values from method symbols (class methods).
     *
     * For method-collected metrics, reads the raw per-method values directly.
     * This ensures namespace-level .max, .avg, .p95 reflect per-method statistics
     * (e.g., ccn.max = highest single method complexity) rather than per-class sums.
     *
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     */
    private static function collectFromMethods(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
        array &$values,
    ): void {
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            // Only class methods (type set, member set); standalone functions handled separately
            if ($path->type === null || $path->member === null) {
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
                }
            }
        }
    }

    /**
     * Collects metric values from class symbols (class-collected metrics only).
     *
     * For class-collected metrics, reads raw values directly from the class bag.
     *
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     */
    private static function collectFromClassSymbols(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
        array &$values,
    ): void {
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            // Must be a class symbol (not method/function)
            if ($path->type === null || $path->member !== null) {
                continue;
            }

            $bag = $repository->get($path);

            foreach ($definitions as $definition) {
                if ($definition->collectedAt !== SymbolLevel::Class_) {
                    continue;
                }

                $value = $bag->get($definition->name);

                if ($value !== null) {
                    $values[$definition->name][] = $value;
                }
            }
        }
    }

    /**
     * Collects metric values from standalone functions.
     *
     * Functions have no parent class, so no pre-aggregated .sum/.avg exists.
     * Reads raw metric values directly from the function's bag.
     *
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     */
    private static function collectFromFunctions(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
        array &$values,
    ): void {
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
                }
            }
        }
    }

    /**
     * Collects metric values from file symbols.
     *
     * Only reads File-collected metrics from file-level bags.
     *
     * @param list<SymbolInfo> $fileSymbols
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     */
    private static function collectFromFileSymbols(
        MetricRepositoryInterface $repository,
        array $fileSymbols,
        array $definitions,
        array &$values,
    ): void {
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
    }

    /**
     * Builds a map of file path to namespace based on class/method/function symbols.
     *
     * @return array<string, string> file path => namespace
     */
    public static function buildFileToNamespaceMap(MetricRepositoryInterface $repository): array
    {
        $map = [];

        foreach ($repository->all(SymbolType::Class_) as $classInfo) {
            $namespace = $classInfo->symbolPath->namespace;

            if ($namespace !== null) {
                $map[$classInfo->file] = $namespace;
            }
        }

        foreach ($repository->all(SymbolType::Method) as $methodInfo) {
            $namespace = $methodInfo->symbolPath->namespace;

            if ($namespace !== null && !isset($map[$methodInfo->file])) {
                $map[$methodInfo->file] = $namespace;
            }
        }

        foreach ($repository->all(SymbolType::Function_) as $funcInfo) {
            $namespace = $funcInfo->symbolPath->namespace;

            if ($namespace !== null && !isset($map[$funcInfo->file])) {
                $map[$funcInfo->file] = $namespace;
            }
        }

        return $map;
    }

    /**
     * Builds a map of namespace to list of File symbols.
     *
     * @param array<string, string> $fileToNamespace
     *
     * @return array<string, list<SymbolInfo>>
     */
    public static function buildNamespaceToFileSymbolsMap(
        MetricRepositoryInterface $repository,
        array $fileToNamespace,
    ): array {
        $map = [];

        foreach ($repository->all(SymbolType::File) as $fileInfo) {
            $filePath = $fileInfo->file;

            if (isset($fileToNamespace[$filePath])) {
                $namespace = $fileToNamespace[$filePath];
                $map[$namespace][] = $fileInfo;
            }
        }

        return $map;
    }
}
