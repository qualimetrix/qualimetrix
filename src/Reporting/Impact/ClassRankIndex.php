<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Impact;

/**
 * Pre-computed index for fast classRank lookups.
 *
 * Built once by ClassRankResolver::buildIndex(), used for O(1) resolution
 * of namespace-level and file-level violations.
 */
final readonly class ClassRankIndex
{
    /**
     * @param array<string, float> $fileMaxRank file path → max classRank
     * @param array<string, float> $nsMaxRank namespace → max classRank (includes parent namespaces)
     * @param float|null $medianRank median classRank across all classes (fallback for unknown)
     */
    public function __construct(
        private array $fileMaxRank,
        private array $nsMaxRank,
        private ?float $medianRank,
    ) {}

    public function getMaxForFile(string $filePath): ?float
    {
        return $this->fileMaxRank[$filePath] ?? null;
    }

    public function getMaxForNamespace(string $namespace): ?float
    {
        return $this->nsMaxRank[$namespace] ?? null;
    }

    /**
     * Returns the median classRank across all classes, or null if no classes have classRank.
     *
     * Used as fallback when a violation's classRank cannot be resolved.
     */
    public function getMedianRank(): ?float
    {
        return $this->medianRank;
    }
}
