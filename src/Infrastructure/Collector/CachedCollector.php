<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Collector;

use AiMessDetector\Analysis\Collection\Metric\CollectionOutput;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Infrastructure\Storage\ChangeDetector;
use AiMessDetector\Infrastructure\Storage\FileRecord;
use AiMessDetector\Infrastructure\Storage\StorageInterface;
use PhpParser\Node;
use SplFileInfo;

/**
 * Caching wrapper for CompositeCollector.
 * Uses storage to avoid re-analyzing unchanged files.
 *
 * Note: Dependencies are NOT cached - only file-level metrics are cached.
 * Dependencies must be re-collected on each analysis to ensure consistency.
 */
final class CachedCollector
{
    public function __construct(
        private readonly CompositeCollector $inner,
        private readonly StorageInterface $storage,
        private readonly ChangeDetector $changeDetector,
    ) {}

    /**
     * Collects metrics with caching.
     * Returns cached metrics if file unchanged, otherwise collects fresh and updates cache.
     *
     * Note: Only metrics are cached, dependencies are always collected fresh
     * (this is intentional - dependency collection is fast and caching them
     * would require complex invalidation logic for cross-file dependencies).
     *
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): CollectionOutput
    {
        $path = $file->getRealPath();
        $contentHash = $this->changeDetector->getContentHash($file);

        // Cache hit for metrics?
        if (!$this->storage->hasFileChanged($path, $contentHash)) {
            $cached = $this->storage->getMetrics(SymbolPath::forFile($path));
            if ($cached !== null) {
                // Still need to collect dependencies (not cached)
                $output = $this->inner->collect($file, $ast);

                return new CollectionOutput(
                    metrics: MetricBag::fromArray($cached),
                    dependencies: $output->dependencies,
                );
            }
        }

        // Cache miss — collect fresh metrics and dependencies
        $output = $this->inner->collect($file, $ast);

        // Store metrics in cache (not dependencies)
        $fileId = $this->storage->storeFile(new FileRecord(
            path: $path,
            contentHash: $contentHash,
            mtime: $file->getMTime(),
            size: $file->getSize(),
        ));

        $this->storage->storeMetrics(
            SymbolPath::forFile($path),
            $output->metrics->all(),
            $fileId,
        );

        return $output;
    }

    /**
     * Resets inner collector state between files.
     */
    public function reset(): void
    {
        $this->inner->reset();
    }

    /**
     * Returns the inner composite collector.
     */
    public function getInner(): CompositeCollector
    {
        return $this->inner;
    }
}
