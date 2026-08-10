<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Repository;

use InvalidArgumentException;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolInfo;

/**
 * Deterministic merge policy for in-memory metric repository storage.
 */
final class RepositoryMerge
{
    public static function metrics(MetricBag $left, MetricBag $right): MetricBag
    {
        return $left->merge($right);
    }

    /**
     * @param array<string, MetricBag> $leftMetrics
     * @param array<string, SymbolInfo> $leftInfos
     * @param array<string, MetricBag> $rightMetrics
     * @param array<string, SymbolInfo> $rightInfos
     *
     * @return array{metrics: array<string, MetricBag>, infos: array<string, SymbolInfo>}
     */
    public static function plain(
        array $leftMetrics,
        array $leftInfos,
        array $rightMetrics,
        array $rightInfos,
    ): array {
        $metrics = $leftMetrics;
        $infos = $leftInfos;

        foreach ($rightInfos as $canonical => $info) {
            if (isset($metrics[$canonical])) {
                $metrics[$canonical] = self::metrics($metrics[$canonical], $rightMetrics[$canonical]);
                $infos[$canonical] = self::plainInfo($infos[$canonical], $info);

                continue;
            }

            $metrics[$canonical] = $rightMetrics[$canonical];
            $infos[$canonical] = $info;
        }

        return ['metrics' => $metrics, 'infos' => $infos];
    }

    public static function plainInfo(SymbolInfo $left, SymbolInfo $right): SymbolInfo
    {
        $line = $left->line;
        if (($line === null || $line === 0) && $right->line !== null && $right->line > 0) {
            $line = $right->line;
        }

        return new SymbolInfo(
            $left->subject ?? $left->symbolPath,
            $left->file ?? $right->file,
            $line,
        );
    }

    public static function subjectInfo(SymbolInfo $left, SymbolInfo $right): SymbolInfo
    {
        if ($left->callableKind === null && $right->callableKind === null) {
            return self::plainInfo($left, $right);
        }

        if ($left->callableKind === null) {
            return $right;
        }

        if ($right->callableKind === null) {
            return $left;
        }

        self::assertSameCallableMetadata($left, $right);

        return new SymbolInfo(
            $left->subject ?? $left->symbolPath,
            $left->file,
            $left->line ?? $right->line,
            $left->callableKind,
            $left->classAggregationOwner,
        );
    }

    private static function assertSameCallableMetadata(SymbolInfo $left, SymbolInfo $right): void
    {
        if ($left->callableKind === $right->callableKind
            && self::sameLogicalClass($left->classAggregationOwner, $right->classAggregationOwner)
            && self::sameFile($left->file, $right->file)
            && self::sameSourceLine($left->line, $right->line)
        ) {
            return;
        }

        throw new InvalidArgumentException(\sprintf(
            'Conflicting callable metadata for %s',
            $left->subject?->toCanonical() ?? $left->symbolPath->toCanonical(),
        ));
    }

    private static function sameLogicalClass(?LogicalClassPath $left, ?LogicalClassPath $right): bool
    {
        return $left?->toCanonical() === $right?->toCanonical();
    }

    private static function sameFile(?RelativePath $left, ?RelativePath $right): bool
    {
        return $left?->value() === $right?->value();
    }

    private static function sameSourceLine(?int $left, ?int $right): bool
    {
        return $left === null || $right === null || $left === $right;
    }

}
