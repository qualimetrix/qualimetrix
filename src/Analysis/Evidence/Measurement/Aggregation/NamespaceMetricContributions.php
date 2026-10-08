<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Aggregation;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Resolves raw metric contributions owned by namespaces and their symbols.
 *
 * Namespace aggregation prefers explicit namespace-owned contributions for
 * file-collected metrics. Project aggregation remains physical-file-derived.
 */
final class NamespaceMetricContributions
{
    /** @param list<int|float|array{total: int|float, files: int}> $values */
    public static function applyFileContributions(AggregationStrategy $strategy, array $values): int|float
    {
        $total = 0;
        $files = 0;
        foreach ($values as $value) {
            if (\is_array($value)) {
                $total += $value['total'];
                $files += $value['files'];
            } else {
                $total += $value;
                ++$files;
            }
        }

        return match ($strategy) {
            AggregationStrategy::Sum => $total,
            AggregationStrategy::Count => $files,
            AggregationStrategy::Average => $files > 0 ? $total / $files : 0,
            default => throw new LogicException('Unsupported namespace file-contribution strategy'),
        };
    }

    /**
     * @param list<SymbolInfo> $symbolInfos
     * @param list<SymbolInfo> $fileSymbols
     * @param list<MetricDefinition> $definitions
     *
     * @return array<string, list<int|float|array{total: int|float, files: int}>>
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
     * @return array<string, list<SymbolInfo>>
     */
    public static function mapNamespacesToFileSymbols(
        MetricRepositoryInterface $repository,
        FileNamespaceIndex $fileNamespaces,
    ): array {
        $map = [];

        foreach ($repository->all(SymbolLevel::File) as $fileInfo) {
            if ($fileInfo->file === null) {
                continue;
            }

            foreach ($fileNamespaces->namespacesOf($fileInfo->file) as $namespace) {
                $map[$namespace][] = $fileInfo;
            }
        }

        return $map;
    }

    /**
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float|array{total: int|float, files: int}>> $values
     */
    private static function collectFromSymbols(
        MetricRepositoryInterface $repository,
        array $symbolInfos,
        array $definitions,
        array &$values,
    ): void {
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            $declarationType = $path->getType();

            // Only leaf declarations contribute upward; a symbol already at
            // file, namespace or project level is an aggregate, not a source.
            if (!\in_array($declarationType, [SymbolType::Function_, SymbolType::Method, SymbolType::Class_], true)) {
                continue;
            }

            if ($declarationType === SymbolType::Class_ && $info->subject?->declarationPath() === null) {
                continue;
            }

            $sourceLevel = SymbolLevelProjection::ofDeclaration($declarationType);

            self::appendValues($repository, $info, $definitions, $values, $sourceLevel);
        }
    }

    /**
     * @param list<SymbolInfo> $fileSymbols
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float|array{total: int|float, files: int}>> $values
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
                    $values[$definition->name][] = $definition->namespaceFileContribution
                        ? ['total' => $value, 'files' => 1]
                        : $value;
                }
            }
        }
    }

    /**
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float|array{total: int|float, files: int}>> $values
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
                if ($definition->namespaceFileContribution
                    && self::appendExplicitNamespaceContributions($bag, $definition, $targetLevel, $values)
                ) {
                    $provided[$definition->name] = true;
                }
            }
        }

        return $provided;
    }

    /**
     * @param array<string, list<int|float|array{total: int|float, files: int}>> $values
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

        $files = 0;
        foreach ($bag->entries(MetricName::NAMESPACE_FILE_CONTRIBUTION) as $entry) {
            if (($entry['metric'] ?? null) === $definition->name) {
                ++$files;
            }
        }
        $values[$definition->name][] = ['total' => $total, 'files' => $files];

        return true;
    }

    /**
     * @param list<MetricDefinition> $definitions
     * @param array<string, list<int|float|array{total: int|float, files: int}>> $values
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
