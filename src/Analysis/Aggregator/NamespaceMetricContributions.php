<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

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

        self::collectFromMethods($repository, $symbolInfos, $definitions, $values);
        self::collectFromClasses($repository, $symbolInfos, $definitions, $values);
        self::collectFromFunctions($repository, $symbolInfos, $definitions, $values);
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

        foreach ([SymbolType::Class_, SymbolType::Method, SymbolType::Function_] as $symbolType) {
            foreach ($repository->all($symbolType) as $info) {
                $namespace = $info->symbolPath->namespace;

                if ($namespace !== null && $info->file !== null) {
                    $map[$info->file->value()][$namespace] = $namespace;
                }
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
    private static function collectFromMethods(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
        array &$values,
    ): void {
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            if ($path->type === null || $path->member === null) {
                continue;
            }

            self::appendValues($repository, $info, $definitions, $values, SymbolLevel::Method);
        }
    }

    /**
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float>> $values
     */
    private static function collectFromClasses(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
        array &$values,
    ): void {
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            if ($path->type === null || $path->member !== null) {
                continue;
            }

            self::appendValues($repository, $info, $definitions, $values, SymbolLevel::Class_);
        }
    }

    /**
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
            if ($info->symbolPath->getType() !== SymbolType::Function_) {
                continue;
            }

            self::appendValues($repository, $info, $definitions, $values, SymbolLevel::Method);
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
                if ($definition->collectedAt !== SymbolLevel::File) {
                    continue;
                }

                $total = $bag->get($definition->name);

                if ($total === null) {
                    continue;
                }

                $count = (int) ($bag->get($definition->name . '.count') ?? 1);
                $perContribution = $count > 0 ? $total / $count : $total;

                for ($i = 0; $i < max(1, $count); ++$i) {
                    $values[$definition->name][] = $perContribution;
                }

                $provided[$definition->name] = true;
            }
        }

        return $provided;
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
        $bag = $repository->get($info->symbolPath);

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
