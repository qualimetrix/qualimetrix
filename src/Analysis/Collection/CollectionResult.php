<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection;

use AiMessDetector\Baseline\Suppression\Suppression;
use AiMessDetector\Core\Dependency\Dependency;

/**
 * Result of the collection phase.
 *
 * Contains summary information about files processed during collection,
 * plus all collected dependencies.
 */
final readonly class CollectionResult
{
    /**
     * @param int $filesAnalyzed Number of files successfully analyzed
     * @param int $filesSkipped Number of files skipped due to errors
     * @param list<Dependency> $dependencies All collected dependencies
     * @param array<string, list<Suppression>> $suppressions Per-file suppression tags (file => suppressions)
     */
    public function __construct(
        public int $filesAnalyzed,
        public int $filesSkipped,
        public array $dependencies = [],
        public array $suppressions = [],
    ) {}

    /**
     * Returns total number of files processed (analyzed + skipped).
     */
    public function totalFiles(): int
    {
        return $this->filesAnalyzed + $this->filesSkipped;
    }

    /**
     * Returns whether any files failed to process.
     */
    public function hasErrors(): bool
    {
        return $this->filesSkipped > 0;
    }
}
