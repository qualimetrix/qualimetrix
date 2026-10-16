<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\SymbolInfo;

/**
 * Deterministic merge policy for in-memory metric repository storage.
 */
final class RepositoryMerge
{
    /**
     * @param array<string, MetricBag> $metrics
     * @param array<string, SymbolInfo> $infos
     */
    public static function store(string $canonical, SymbolInfo $info, MetricBag $incoming, array &$metrics, array &$infos): SymbolInfo
    {
        if (isset($metrics[$canonical])) {
            $metrics[$canonical] = self::metrics($metrics[$canonical], $incoming);
            $infos[$canonical] = self::subjectInfo($infos[$canonical], $info);
        } else {
            $metrics[$canonical] = $incoming;
            $infos[$canonical] = $info;
        }

        return $infos[$canonical];
    }

    public static function metrics(MetricBag $left, MetricBag $right): MetricBag
    {
        return $left->merge($right);
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
            $left->anonymousClassContext,
        );
    }

    private static function assertSameCallableMetadata(SymbolInfo $left, SymbolInfo $right): void
    {
        if ($left->callableKind === $right->callableKind
            && self::sameDeclaration($left->classAggregationOwner, $right->classAggregationOwner)
            && $left->anonymousClassContext === $right->anonymousClassContext
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

    private static function sameDeclaration(?DeclarationPath $left, ?DeclarationPath $right): bool
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
