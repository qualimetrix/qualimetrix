<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Storage;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Infrastructure\Storage\FileRecord;
use AiMessDetector\Infrastructure\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class InMemoryStorageTest extends TestCase
{
    private InMemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
    }

    public function testStoreAndRetrieveFile(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
            namespace: 'App',
        );

        $fileId = $this->storage->storeFile($record);

        $this->assertGreaterThan(0, $fileId);

        $retrieved = $this->storage->getFile('/src/Foo.php');

        $this->assertNotNull($retrieved);
        $this->assertSame('/src/Foo.php', $retrieved->path);
        $this->assertSame('abc123', $retrieved->contentHash);
    }

    public function testHasFileChanged(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $this->storage->storeFile($record);

        $this->assertFalse($this->storage->hasFileChanged('/src/Foo.php', 'abc123'));
        $this->assertTrue($this->storage->hasFileChanged('/src/Foo.php', 'def456'));
    }

    public function testStoreAndRetrieveMetrics(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);
        $classPath = SymbolPath::forClass('App', 'Foo');
        $metrics = ['ccn' => 5, 'loc' => 100];

        $this->storage->storeMetrics($classPath, $metrics, $fileId);

        $retrieved = $this->storage->getMetrics($classPath);

        $this->assertSame($metrics, $retrieved);
    }

    public function testAllMetricsFiltersCorrectly(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);

        $this->storage->storeMetrics(SymbolPath::forClass('App', 'Foo'), ['loc' => 100], $fileId);
        $this->storage->storeMetrics(SymbolPath::forClass('App', 'Bar'), ['loc' => 200], $fileId);
        $this->storage->storeMetrics(SymbolPath::forMethod('App', 'Foo', 'baz'), ['ccn' => 5], $fileId);

        $classMetrics = iterator_to_array($this->storage->allMetrics(SymbolType::Class_));

        $this->assertCount(2, $classMetrics);
    }

    public function testRemoveFileCascadesMetrics(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);
        $classPath = SymbolPath::forClass('App', 'Foo');
        $this->storage->storeMetrics($classPath, ['loc' => 100], $fileId);

        $this->storage->removeFile('/src/Foo.php');

        $this->assertNull($this->storage->getFile('/src/Foo.php'));
        $this->assertNull($this->storage->getMetrics($classPath));
    }

    public function testTransactionRollback(): void
    {
        $this->storage->beginTransaction();

        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $this->storage->storeFile($record);
        $this->storage->rollback();

        $this->assertNull($this->storage->getFile('/src/Foo.php'));
    }

    public function testTransactionRollbackRestoresFileSymbols(): void
    {
        // Store a file and metrics before transaction
        $record = new FileRecord(
            path: '/src/Before.php',
            contentHash: 'before',
            mtime: 1234567890,
            size: 500,
        );
        $fileId = $this->storage->storeFile($record);
        $classPath = SymbolPath::forClass('App', 'Before');
        $this->storage->storeMetrics($classPath, ['loc' => 50], $fileId);

        // Begin transaction and store more metrics
        $this->storage->beginTransaction();

        $record2 = new FileRecord(
            path: '/src/During.php',
            contentHash: 'during',
            mtime: 1234567891,
            size: 600,
        );
        $fileId2 = $this->storage->storeFile($record2);
        $classPath2 = SymbolPath::forClass('App', 'During');
        $this->storage->storeMetrics($classPath2, ['loc' => 100], $fileId2);

        // Rollback
        $this->storage->rollback();

        // Pre-transaction metrics should still be accessible
        $this->assertNotNull($this->storage->getMetrics($classPath));

        // Remove original file — should cascade and delete its metrics
        // This proves fileSymbols was restored correctly
        $this->storage->removeFile('/src/Before.php');
        $this->assertNull($this->storage->getMetrics($classPath));
    }

    public function testTransactionCommit(): void
    {
        $this->storage->beginTransaction();

        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $this->storage->storeFile($record);
        $this->storage->commit();

        $this->assertNotNull($this->storage->getFile('/src/Foo.php'));
    }

    public function testDependencies(): void
    {
        $this->storage->storeDependency('App\Foo', 'App\Bar', 'extends');
        $this->storage->storeDependency('App\Foo', 'App\Baz', 'uses');

        $deps = $this->storage->getDependencies('App\Foo');
        $this->assertCount(2, $deps);

        $dependents = $this->storage->getDependents('App\Bar');
        $this->assertCount(1, $dependents);
        $this->assertSame('App\Foo', $dependents[0]['from']);
    }

    public function testAggregatedMetrics(): void
    {
        $metrics = ['ccn.avg' => 5.5, 'loc.sum' => 1000];

        $this->storage->storeAggregated('namespace:App', $metrics);

        $retrieved = $this->storage->getAggregated('namespace:App');

        $this->assertSame($metrics, $retrieved);
    }

    public function testClearRemovesAllData(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);
        $this->storage->storeMetrics(SymbolPath::forFile('/src/Foo.php'), ['loc' => 100], $fileId);

        $this->storage->clear();

        $this->assertNull($this->storage->getFile('/src/Foo.php'));
        $this->assertNull($this->storage->getMetrics(SymbolPath::forFile('/src/Foo.php')));
    }

    public function testGetStats(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);
        $this->storage->storeMetrics(SymbolPath::forClass('App', 'Foo'), ['loc' => 100], $fileId);

        $stats = $this->storage->getStats();

        $this->assertSame(1, $stats['files']);
        $this->assertSame(1, $stats['metrics']);
    }
}
