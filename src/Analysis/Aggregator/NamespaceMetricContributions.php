<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Resolves raw metric contributions owned by namespaces and their symbols.
 *
 * Namespace aggregation prefers explicit namespace-owned contributions for
 * file-collected metrics. Project aggregation remains physical-file-derived.
 */
final class NamespaceMetricContributions
{
    /**
     * @param list<SymbolInfo> $symbolInfos
     * @param list<SymbolInfo> $fileSymbols
     * @param list<MetricDefinition> $definitions
     *
     * @return array<string, list<int|float>>
     */
    public static function collectValues(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $fileSymbols,
        array $definitions,
        SymbolLevel $targetLevel,
    ): array {
        $values = [];

        foreach ($definitions as $definition) {
            $values[$definition->name] = [];
        }

        self::collectFromSymbols($repository, $symbolInfos, $definitions, $values);
        $namespaceProvided = self::collectExplicitNamespaceValues(
            $repository,
            $symbolInfos,
            $definitions,
            $values,
            $targetLevel,
        );
        self::collectFromFiles($repository, $fileSymbols, $definitions, $values, $namespaceProvided);

        return $values;
    }

    /**
     * @return array<string, list<string>> file path => namespaces
     */
    public static function mapFilesToNamespaces(MetricRepositoryInterface $repository): array
    {
        $map = [];

        foreach ($repository->allDeclarations() as $info) {
            $namespace = $info->subject?->toSymbolPath()->namespace;

            if ($namespace !== null && $info->file !== null) {
                $map[$info->file->value()][$namespace] = $namespace;
            }
        }

        // Aggregate-only class records still own their physical file. They have
        // no declaration subject, but must keep that file eligible for file LOC.
        foreach ($repository->allLogicalClasses() as $info) {
            $namespace = $info->subject?->toSymbolPath()->namespace;

            if ($namespace !== null && $info->file !== null) {
                $map[$info->file->value()][$namespace] = $namespace;
            }
        }

        return array_map(static fn(array $namespaces): array => array_values($namespaces), $map);
    }

    /**
     * @param array<string, list<string>> $fileToNamespaces
     *
     * @return array<string, list<SymbolInfo>>
     */
    public static function mapNamespacesToFileSymbols(
        MetricRepositoryInterface $repository,
        array $fileToNamespaces,
    ): array {
        $map = [];

        foreach ($repository->all(SymbolType::File) as $fileInfo) {
            if ($fileInfo->file === null) {
                continue;
            }

            foreach ($fileToNamespaces[$fileInfo->file->value()] ?? [] as $namespace) {
                $map[$namespace][] = $fileInfo;
            }
        }

        return $map;
    }

    /**
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     */
    private static function collectFromSymbols(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
        array &$values,
    ): void {
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            $sourceLevel = match (true) {
                $path->getType() === SymbolType::Function_ => SymbolLevel::Callable,
                $path->type !== null && $path->member !== null => SymbolLevel::Callable,
                $path->type !== null => SymbolLevel::Class_,
                default => null,
            };
            if ($sourceLevel === null) {
                continue;
            }

            self::appendValues($repository, $info, $definitions, $values, $sourceLevel);
        }
    }

    /**
     * @param list<SymbolInfo> $fileSymbols
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     * @param array<string, true> $namespaceProvided
     */
    private static function collectFromFiles(
        MetricRepositoryInterface $repository,
        array $fileSymbols,
        array $definitions,
        array &$values,
        array $namespaceProvided,
    ): void {
        foreach ($fileSymbols as $fileInfo) {
            $bag = $repository->get($fileInfo->symbolPath);

            foreach ($definitions as $definition) {
                if ($definition->collectedAt !== SymbolLevel::File || isset($namespaceProvided[$definition->name])) {
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
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     *
     * @return array<string, true>
     */
    private static function collectExplicitNamespaceValues(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
        array &$values,
        SymbolLevel $targetLevel,
    ): array {
        if ($targetLevel !== SymbolLevel::Namespace_) {
            return [];
        }

        $provided = [];

        foreach ($symbolInfos as $info) {
            if ($info->symbolPath->getType() !== SymbolType::Namespace_) {
                continue;
            }

            $bag = $repository->get($info->symbolPath);

            foreach ($definitions as $definition) {
                if ($definition->collectedAt === SymbolLevel::File
                    && self::appendExplicitNamespaceContributions($bag, $definition, $targetLevel, $values)
                ) {
                    $provided[$definition->name] = true;
                }
            }
        }

        return $provided;
    }

    /**
     * @param array<string, list<int|float>> $values
     */
    private static function appendExplicitNamespaceContributions(
        MetricBag $bag,
        MetricDefinition $definition,
        SymbolLevel $targetLevel,
        array &$values,
    ): bool {
        $total = $bag->get($definition->name);

        if ($total === null) {
            return false;
        }

        // A sum-only aggregation needs the namespace total once, not a
        // synthetic value per contributing file. Splitting an integer total
        // such as 1 across six contributions creates repeating fractions whose
        // sum can be 0.999..., corrupting count metrics after an integer cast.
        if ($definition->getStrategiesForLevel($targetLevel) === [AggregationStrategy::Sum]) {
            $values[$definition->name][] = $total;

            return true;
        }

        $count = (int) ($bag->get($definition->name . '.count') ?? 1);
        $perContribution = $count > 0 ? $total / $count : $total;

        for ($i = 0; $i < max(1, $count); ++$i) {
            $values[$definition->name][] = $perContribution;
        }

        return true;
    }

    /**
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     */
    private static function appendValues(
        MetricRepositoryInterface $repository,
        SymbolInfo $info,
        array $definitions,
        array &$values,
        SymbolLevel $sourceLevel,
    ): void {
        $bag = $info->subject === null
            ? $repository->get($info->symbolPath)
            : $repository->getSubject($info->subject);

        foreach ($definitions as $definition) {
            if ($definition->collectedAt !== $sourceLevel) {
                continue;
            }

            $value = $bag->get($definition->name);

            if ($value !== null) {
                $values[$definition->name][] = $value;
            }
        }
    }
}
