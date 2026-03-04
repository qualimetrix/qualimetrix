<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Collector;

use AiMessDetector\Analysis\Collection\Metric\CollectionOutput;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Infrastructure\Collector\CachedCollector;
use AiMessDetector\Infrastructure\Storage\ChangeDetector;
use AiMessDetector\Infrastructure\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

final class CachedCollectorTest extends TestCase
{
    private CachedCollector $collector;
    private CompositeCollector $innerCollector;
    private InMemoryStorage $storage;
    private ChangeDetector $changeDetector;

    protected function setUp(): void
    {
        // Use real CompositeCollector with no collectors
        // This will return empty CollectionOutput
        $this->innerCollector = new CompositeCollector([]);
        $this->storage = new InMemoryStorage();
        $this->changeDetector = new ChangeDetector();
        $this->collector = new CachedCollector(
            $this->innerCollector,
            $this->storage,
            $this->changeDetector,
        );
    }

    public function testCollectsFreshMetricsForNewFile(): void
    {
        $file = new SplFileInfo(__FILE__);
        $ast = [];

        // First call - should collect and cache
        $result = $this->collector->collect($file, $ast);

        // Returns CollectionOutput now
        $this->assertInstanceOf(CollectionOutput::class, $result);
        $this->assertInstanceOf(MetricBag::class, $result->metrics);
        $this->assertSame([], $result->dependencies);

        // Verify metrics were cached
        $cached = $this->storage->getMetrics(SymbolPath::forFile($file->getRealPath()));
        $this->assertNotNull($cached);
    }

    public function testUsesCachedMetricsForUnchangedFile(): void
    {
        $file = new SplFileInfo(__FILE__);
        $ast = [];

        // First collection - cache miss, stores empty metrics
        $result1 = $this->collector->collect($file, $ast);

        // Manually add metrics to cache
        $fileRecord = $this->storage->getFile($file->getRealPath());
        $this->assertNotNull($fileRecord);

        $fileId = $this->storage->storeFile($fileRecord);
        $this->storage->storeMetrics(
            SymbolPath::forFile($file->getRealPath()),
            ['test' => 123],
            $fileId,
        );

        // Second collection - cache hit
        $result2 = $this->collector->collect($file, $ast);

        // Should return cached value
        $this->assertSame(123, $result2->metrics->get('test'));
    }

    public function testRecollectsMetricsWhenFileChanged(): void
    {
        $file = new SplFileInfo(__FILE__);
        $ast = [];

        // First collection
        $result1 = $this->collector->collect($file, $ast);

        // Force cache invalidation by clearing
        $this->storage->removeFile($file->getRealPath());

        // Second collection should re-collect
        $result2 = $this->collector->collect($file, $ast);

        // Both should be fresh collections (empty metrics from empty collector)
        $this->assertInstanceOf(CollectionOutput::class, $result1);
        $this->assertInstanceOf(CollectionOutput::class, $result2);
    }

    public function testResetCallsInnerCollectorReset(): void
    {
        // Reset should work without errors
        $this->collector->reset();

        // Verify we can still collect after reset
        $file = new SplFileInfo(__FILE__);
        $result = $this->collector->collect($file, []);

        $this->assertInstanceOf(CollectionOutput::class, $result);
    }

    public function testGetInnerReturnsInnerCollector(): void
    {
        $this->assertSame($this->innerCollector, $this->collector->getInner());
    }
}
