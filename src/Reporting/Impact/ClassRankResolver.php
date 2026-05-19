<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Impact;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Violation;

/**
 * Resolves a classRank value for a given violation, regardless of its SymbolPath type.
 *
 * For method/class-level violations, the classRank is looked up directly.
 * For namespace/file-level violations, a pre-built index is used for O(1) lookup.
 */
final readonly class ClassRankResolver
{
    /**
     * Builds a lookup index for fast classRank resolution.
     *
     * Iterates all classes once, building:
     * - file → max classRank among classes in that file
     * - namespace → max classRank among classes in that namespace (prefix-aware)
     * - median classRank across all classes (for fallback when classRank is unavailable)
     */
    public function buildIndex(MetricRepositoryInterface $metrics, ?NamespaceTree $tree = null): ClassRankIndex
    {
        /** @var array<string, float> $fileIndex file path → max classRank */
        $fileIndex = [];
        /** @var array<string, float> $nsIndex namespace → max classRank */
        $nsIndex = [];
        /** @var list<float> $allRanks all classRank values for median calculation */
        $allRanks = [];

        $tree = $tree ?? new NamespaceTree($metrics->getNamespaces());

        foreach ($metrics->all(SymbolType::Class_) as $symbolInfo) {
            if ($symbolInfo->symbolPath->type === null) {
                continue;
            }

            $classPath = SymbolPath::forClass(
                $symbolInfo->symbolPath->namespace ?? '',
                $symbolInfo->symbolPath->type,
            );

            $value = $metrics->get($classPath)->get(MetricName::COUPLING_CLASS_RANK);
            $rank = $this->sanitize($value);

            if ($rank === null) {
                continue;
            }

            $allRanks[] = $rank;
            $this->updateFileIndex($fileIndex, $symbolInfo->file, $rank);
            $this->updateNamespaceIndex($nsIndex, $tree, $symbolInfo->symbolPath->namespace ?? '', $rank);
        }

        return new ClassRankIndex($fileIndex, $nsIndex, $this->computeMedian($allRanks));
    }

    /**
     * @param array<string, float> $fileIndex
     */
    private function updateFileIndex(array &$fileIndex, ?\Qualimetrix\Core\Path\RelativePath $file, float $rank): void
    {
        if ($file === null) {
            return;
        }

        $key = $file->value();
        if (!isset($fileIndex[$key]) || $rank > $fileIndex[$key]) {
            $fileIndex[$key] = $rank;
        }
    }

    /**
     * @param array<string, float> $nsIndex
     */
    private function updateNamespaceIndex(array &$nsIndex, NamespaceTree $tree, string $namespace, float $rank): void
    {
        if ($namespace === '') {
            if (!isset($nsIndex['']) || $rank > $nsIndex['']) {
                $nsIndex[''] = $rank;
            }

            return;
        }

        if (!isset($nsIndex[$namespace]) || $rank > $nsIndex[$namespace]) {
            $nsIndex[$namespace] = $rank;
        }

        foreach ($tree->getAncestors($namespace) as $ancestor) {
            if (!isset($nsIndex[$ancestor]) || $rank > $nsIndex[$ancestor]) {
                $nsIndex[$ancestor] = $rank;
            }
        }
    }

    /**
     * @param list<float> $allRanks
     */
    private function computeMedian(array $allRanks): ?float
    {
        if ($allRanks === []) {
            return null;
        }

        sort($allRanks);
        $count = \count($allRanks);
        $mid = intdiv($count, 2);

        return $count % 2 === 0
            ? ($allRanks[$mid - 1] + $allRanks[$mid]) / 2.0
            : $allRanks[$mid];
    }

    /**
     * Resolves the classRank metric for the class associated with a violation.
     *
     * Uses a pre-built index for O(1) namespace/file lookups.
     *
     * @return float|null The classRank value, or null if not available
     */
    public function resolve(Violation $violation, MetricRepositoryInterface $metrics, ClassRankIndex $index): ?float
    {
        $sp = $violation->symbolPath;

        return match ($sp->getType()) {
            SymbolType::Method, SymbolType::Class_ => $sp->type !== null
                ? $this->resolveForClassPath(SymbolPath::forClass($sp->namespace ?? '', $sp->type), $metrics)
                : null,
            SymbolType::Namespace_ => $index->getMaxForNamespace($sp->namespace ?? ''),
            SymbolType::File => $index->getMaxForFile($sp->filePath?->value() ?? ''),
            SymbolType::Function_, SymbolType::Project => null,
        };
    }

    private function resolveForClassPath(SymbolPath $classPath, MetricRepositoryInterface $metrics): ?float
    {
        $value = $metrics->get($classPath)->get(MetricName::COUPLING_CLASS_RANK);

        return $this->sanitize($value);
    }

    /**
     * Sanitizes a metric value, returning null for NaN, Infinite, or non-float values.
     */
    private function sanitize(int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $floatValue = (float) $value;

        if (is_nan($floatValue) || is_infinite($floatValue)) {
            return null;
        }

        return $floatValue;
    }
}
