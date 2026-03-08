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
 * Both metrics and dependencies are cached per file. On cache hit,
 * no AST traversal is performed — the cached data is returned directly.
 * Dependencies are deterministic based on file content, so if the
 * contentHash hasn't changed, neither have the dependencies.
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
     * Returns cached metrics and dependencies if file unchanged,
     * otherwise collects fresh and updates cache.
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
                // Try to get cached dependencies too
                $fileRecord = $this->storage->getFile($path);
                if ($fileRecord !== null) {
                    $fileId = $this->storage->storeFile($fileRecord);
                    $cachedDeps = $this->storage->getFileDependencies($fileId);

                    if ($cachedDeps !== null) {
                        // Full cache hit — no AST traversal needed
                        return new CollectionOutput(
                            metrics: MetricBag::fromArray($cached),
                            dependencies: $cachedDeps,
                        );
                    }
                }

                // Backward compat: metrics cached but dependencies not.
                // Fall through to full collection.
            }
        }

        // Cache miss — collect fresh metrics and dependencies
        $output = $this->inner->collect($file, $ast);

        // Store file record, metrics, and dependencies in cache
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

        $this->storage->storeFileDependencies($fileId, $output->dependencies);

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
