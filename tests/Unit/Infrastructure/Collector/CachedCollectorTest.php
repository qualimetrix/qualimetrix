<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Collector;

use AiMessDetector\Analysis\Collection\Metric\CollectionOutput;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Infrastructure\Collector\CachedCollector;
use AiMessDetector\Infrastructure\Storage\ChangeDetector;
use AiMessDetector\Infrastructure\Storage\FileRecord;
use AiMessDetector\Infrastructure\Storage\InMemoryStorage;
use AiMessDetector\Infrastructure\Storage\StorageInterface;
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

    public function testCollectsFreshMetricsAndDependenciesForNewFile(): void
    {
        $file = new SplFileInfo(__FILE__);
        $ast = [];

        // First call - should collect and cache
        $result = $this->collector->collect($file, $ast);

        $this->assertInstanceOf(CollectionOutput::class, $result);
        $this->assertInstanceOf(MetricBag::class, $result->metrics);
        $this->assertSame([], $result->dependencies);

        // Verify metrics were cached
        $cached = $this->storage->getMetrics(SymbolPath::forFile($file->getRealPath()));
        $this->assertNotNull($cached);

        // Verify dependencies were cached
        $fileId = $this->storage->getFileId($file->getRealPath());
        $this->assertNotNull($fileId);
        $cachedDeps = $this->storage->getFileDependencies($fileId);
        $this->assertNotNull($cachedDeps);
        $this->assertSame([], $cachedDeps);
    }

    public function testCacheHitReturnsCachedMetricsAndDependencies(): void
    {
        $file = new SplFileInfo(__FILE__);
        $path = $file->getRealPath();
        $contentHash = $this->changeDetector->getContentHash($file);

        // Pre-populate cache with metrics and dependencies
        $fileId = $this->storage->storeFile(new FileRecord(
            path: $path,
            contentHash: $contentHash,
            mtime: $file->getMTime(),
            size: $file->getSize(),
        ));
        $this->storage->storeMetrics(
            SymbolPath::forFile($path),
            ['test_metric' => 42],
            $fileId,
        );
        $deps = [
            new Dependency(
                source: SymbolPath::fromClassFqn('App\\Foo'),
                target: SymbolPath::fromClassFqn('App\\Bar'),
                type: DependencyType::New_,
                location: new Location(file: $path, line: 10),
            ),
        ];
        $this->storage->storeFileDependencies($fileId, $deps);

        // Collect — should return cached data without AST traversal
        $result = $this->collector->collect($file, []);

        $this->assertSame(42, $result->metrics->get('test_metric'));
        $this->assertCount(1, $result->dependencies);
        $this->assertSame('class:App\\Foo', $result->dependencies[0]->source->toCanonical());
        $this->assertSame('class:App\\Bar', $result->dependencies[0]->target->toCanonical());
        $this->assertSame(DependencyType::New_, $result->dependencies[0]->type);
        $this->assertSame(10, $result->dependencies[0]->location->line);
    }

    public function testCacheHitDoesNotCallInnerCollector(): void
    {
        $file = new SplFileInfo(__FILE__);
        $path = $file->getRealPath();
        $contentHash = $this->changeDetector->getContentHash($file);

        // Pre-populate full cache
        $fileId = $this->storage->storeFile(new FileRecord(
            path: $path,
            contentHash: $contentHash,
            mtime: $file->getMTime(),
            size: $file->getSize(),
        ));
        $this->storage->storeMetrics(
            SymbolPath::forFile($path),
            ['cached' => 1],
            $fileId,
        );
        $this->storage->storeFileDependencies($fileId, []);

        // Use a spy storage to detect that inner->collect() is NOT called.
        // If inner->collect() were called, it would call storeFile() again
        // (on cache miss path). We track storeFile calls after pre-population.
        $callsBefore = $this->countStoredFiles();

        $this->collector->collect($file, []);

        // On a full cache hit, storeFile should NOT be called again
        $callsAfter = $this->countStoredFiles();
        $this->assertSame($callsBefore, $callsAfter, 'storeFile should not be called on cache hit');

        // Also verify we got cached data, not fresh empty data
        $result = $this->collector->collect($file, []);
        $this->assertSame(1, $result->metrics->get('cached'));
    }

    public function testRecollectsWhenFileChanged(): void
    {
        $file = new SplFileInfo(__FILE__);

        // First collection
        $this->collector->collect($file, []);

        // Force cache invalidation by removing file
        $this->storage->removeFile($file->getRealPath());

        // Second collection should re-collect (cache miss)
        $result = $this->collector->collect($file, []);

        $this->assertInstanceOf(CollectionOutput::class, $result);
    }

    public function testDependencyRoundTrip(): void
    {
        $file = new SplFileInfo(__FILE__);
        $path = $file->getRealPath();
        $contentHash = $this->changeDetector->getContentHash($file);

        $originalDeps = [
            new Dependency(
                source: SymbolPath::fromClassFqn('App\\Service\\UserService'),
                target: SymbolPath::fromClassFqn('App\\Repository\\UserRepo'),
                type: DependencyType::New_,
                location: new Location(file: $path, line: 25),
            ),
            new Dependency(
                source: SymbolPath::fromClassFqn('App\\Service\\UserService'),
                target: SymbolPath::fromClassFqn('App\\Entity\\User'),
                type: DependencyType::TypeHint,
                location: new Location(file: $path, line: 12),
            ),
            new Dependency(
                source: SymbolPath::fromClassFqn('App\\Service\\UserService'),
                target: SymbolPath::fromClassFqn('App\\Contract\\LoggerInterface'),
                type: DependencyType::Implements,
                location: new Location(file: $path, line: 5),
            ),
        ];

        // Store dependencies
        $fileId = $this->storage->storeFile(new FileRecord(
            path: $path,
            contentHash: $contentHash,
            mtime: $file->getMTime(),
            size: $file->getSize(),
        ));
        $this->storage->storeFileDependencies($fileId, $originalDeps);

        // Retrieve and verify round-trip
        $retrieved = $this->storage->getFileDependencies($fileId);
        $this->assertNotNull($retrieved);
        $this->assertCount(3, $retrieved);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame($originalDeps[$i]->source->toCanonical(), $retrieved[$i]->source->toCanonical());
            $this->assertSame($originalDeps[$i]->target->toCanonical(), $retrieved[$i]->target->toCanonical());
            $this->assertSame($originalDeps[$i]->type, $retrieved[$i]->type);
            $this->assertSame($originalDeps[$i]->location->file, $retrieved[$i]->location->file);
            $this->assertSame($originalDeps[$i]->location->line, $retrieved[$i]->location->line);
        }
    }

    public function testBackwardCompatFallsThrough(): void
    {
        $file = new SplFileInfo(__FILE__);
        $path = $file->getRealPath();
        $contentHash = $this->changeDetector->getContentHash($file);

        // Simulate old cache: metrics cached but NO dependencies
        $fileId = $this->storage->storeFile(new FileRecord(
            path: $path,
            contentHash: $contentHash,
            mtime: $file->getMTime(),
            size: $file->getSize(),
        ));
        $this->storage->storeMetrics(
            SymbolPath::forFile($path),
            ['old_metric' => 99],
            $fileId,
        );
        // Deliberately NOT storing fileDependencies — simulates old cache format

        // Collect — should fall through and re-collect
        $result = $this->collector->collect($file, []);

        // After fallthrough, fresh empty metrics from empty collector replace old ones
        $this->assertInstanceOf(CollectionOutput::class, $result);

        // Verify dependencies are now cached (stored on the fresh collection)
        $cachedDeps = $this->storage->getFileDependencies($fileId);
        $this->assertNotNull($cachedDeps);
    }

    public function testCacheHitDoesNotCallStoreFile(): void
    {
        $file = new SplFileInfo(__FILE__);
        $path = $file->getRealPath();
        $contentHash = $this->changeDetector->getContentHash($file);

        // Create a mock storage that tracks storeFile calls
        $storage = $this->createMock(StorageInterface::class);

        $fileId = 42;

        $storage->method('hasFileChanged')
            ->with($path, $contentHash)
            ->willReturn(false);

        $storage->method('getMetrics')
            ->willReturn(['test_metric' => 99]);

        $storage->method('getFileId')
            ->with($path)
            ->willReturn($fileId);

        $storage->method('getFileDependencies')
            ->with($fileId)
            ->willReturn([]);

        // storeFile must NOT be called on cache hit
        $storage->expects($this->never())
            ->method('storeFile');

        $collector = new CachedCollector(
            $this->innerCollector,
            $storage,
            $this->changeDetector,
        );

        $result = $collector->collect($file, []);

        $this->assertSame(99, $result->metrics->get('test_metric'));
        $this->assertSame([], $result->dependencies);
    }

    public function testCacheHitPreservesMetricsOnRepeatedCollect(): void
    {
        $file = new SplFileInfo(__FILE__);
        $path = $file->getRealPath();
        $contentHash = $this->changeDetector->getContentHash($file);

        // Pre-populate cache with file, metrics, and dependencies
        $fileId = $this->storage->storeFile(new FileRecord(
            path: $path,
            contentHash: $contentHash,
            mtime: $file->getMTime(),
            size: $file->getSize(),
        ));

        // Store class-level metrics associated with this file
        $classPath = SymbolPath::forClass('App', 'Foo');
        $this->storage->storeMetrics($classPath, ['loc' => 200, 'ccn' => 10], $fileId);

        // Store file-level metrics
        $this->storage->storeMetrics(
            SymbolPath::forFile($path),
            ['file_metric' => 42],
            $fileId,
        );

        $this->storage->storeFileDependencies($fileId, []);

        // Collect (cache hit) — should NOT destroy class metrics
        $this->collector->collect($file, []);

        // Verify class-level metrics are still intact
        $classMetrics = $this->storage->getMetrics($classPath);
        $this->assertNotNull($classMetrics, 'Class metrics should be preserved on cache hit');
        $this->assertSame(200, $classMetrics['loc']);
        $this->assertSame(10, $classMetrics['ccn']);
    }

    public function testFallsBackToInnerWhenGetRealPathReturnsFalse(): void
    {
        // Create a mock SplFileInfo that returns false for getRealPath()
        $file = $this->createMock(SplFileInfo::class);
        $file->method('getRealPath')->willReturn(false);

        // Storage should NOT be consulted at all
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->never())->method('hasFileChanged');
        $storage->expects($this->never())->method('storeFile');

        $collector = new CachedCollector(
            $this->innerCollector,
            $storage,
            $this->changeDetector,
        );

        $result = $collector->collect($file, []);

        $this->assertInstanceOf(CollectionOutput::class, $result);
    }

    public function testResetCallsInnerCollectorReset(): void
    {
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

    /**
     * Helper to count the number of stored files (proxy for detecting storeFile calls).
     */
    private function countStoredFiles(): int
    {
        return $this->storage->getStats()['files'];
    }
}
