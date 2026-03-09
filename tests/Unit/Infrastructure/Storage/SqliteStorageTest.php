<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Storage;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Infrastructure\Storage\FileRecord;
use AiMessDetector\Infrastructure\Storage\SqliteStorage;
use PHPUnit\Framework\TestCase;

final class SqliteStorageTest extends TestCase
{
    private SqliteStorage $storage;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite extension is not available');
        }

        // Use in-memory SQLite for tests
        $this->storage = new SqliteStorage(':memory:');
    }

    public function testGetFileIdReturnsIdForExistingFile(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $storedId = $this->storage->storeFile($record);

        $this->assertSame($storedId, $this->storage->getFileId('/src/Foo.php'));
    }

    public function testGetFileIdReturnsNullForNonExistentFile(): void
    {
        $this->assertNull($this->storage->getFileId('/src/NonExistent.php'));
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
        $this->assertSame(1234567890, $retrieved->mtime);
        $this->assertSame(1000, $retrieved->size);
        $this->assertSame('App', $retrieved->namespace);
    }

    public function testHasFileChangedReturnsTrueForNewFile(): void
    {
        $this->assertTrue($this->storage->hasFileChanged('/src/Foo.php', 'abc123'));
    }

    public function testHasFileChangedReturnsFalseForUnchangedFile(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $this->storage->storeFile($record);

        $this->assertFalse($this->storage->hasFileChanged('/src/Foo.php', 'abc123'));
    }

    public function testHasFileChangedReturnsTrueForChangedFile(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $this->storage->storeFile($record);

        $this->assertTrue($this->storage->hasFileChanged('/src/Foo.php', 'def456'));
    }

    public function testStoreAndRetrieveFileMetrics(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);
        $metrics = ['ccn' => 5, 'loc' => 100];

        $this->storage->storeMetrics(
            SymbolPath::forFile('/src/Foo.php'),
            $metrics,
            $fileId,
        );

        $retrieved = $this->storage->getMetrics(SymbolPath::forFile('/src/Foo.php'));

        $this->assertSame($metrics, $retrieved);
    }

    public function testStoreAndRetrieveClassMetrics(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);
        $classPath = SymbolPath::forClass('App', 'Foo');
        $metrics = ['ccn.sum' => 15, 'loc' => 200];

        $this->storage->storeMetrics($classPath, $metrics, $fileId, 10);

        $retrieved = $this->storage->getMetrics($classPath);

        $this->assertSame($metrics, $retrieved);
    }

    public function testStoreAndRetrieveMethodMetrics(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);
        $methodPath = SymbolPath::forMethod('App', 'Foo', 'bar');
        $metrics = ['ccn' => 5, 'loc' => 20];

        $this->storage->storeMetrics($methodPath, $metrics, $fileId, 15);

        $retrieved = $this->storage->getMetrics($methodPath);

        $this->assertSame($metrics, $retrieved);
    }

    public function testAllMetricsReturnsIteratorForType(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);

        // Store class metrics
        $classPath1 = SymbolPath::forClass('App', 'Foo');
        $this->storage->storeMetrics($classPath1, ['loc' => 100], $fileId, 10);

        $classPath2 = SymbolPath::forClass('App', 'Bar');
        $this->storage->storeMetrics($classPath2, ['loc' => 200], $fileId, 50);

        // Store method metrics
        $methodPath = SymbolPath::forMethod('App', 'Foo', 'baz');
        $this->storage->storeMetrics($methodPath, ['ccn' => 5], $fileId, 15);

        // Retrieve only class metrics
        $classMetrics = iterator_to_array($this->storage->allMetrics(SymbolType::Class_));

        $this->assertCount(2, $classMetrics);
        $this->assertArrayHasKey('class:App\Foo', $classMetrics);
        $this->assertArrayHasKey('class:App\Bar', $classMetrics);
    }

    public function testRemoveFileDeletesMetricsCascade(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);

        $classPath = SymbolPath::forClass('App', 'Foo');
        $this->storage->storeMetrics($classPath, ['loc' => 100], $fileId, 10);

        $methodPath = SymbolPath::forMethod('App', 'Foo', 'bar');
        $this->storage->storeMetrics($methodPath, ['ccn' => 5], $fileId, 15);

        // Remove file
        $this->storage->removeFile('/src/Foo.php');

        // Check that file and metrics are deleted
        $this->assertNull($this->storage->getFile('/src/Foo.php'));
        $this->assertNull($this->storage->getMetrics($classPath));
        $this->assertNull($this->storage->getMetrics($methodPath));
    }

    public function testStoreAndRetrieveDependencies(): void
    {
        $this->storage->storeDependency('App\Foo', 'App\Bar', 'extends');
        $this->storage->storeDependency('App\Foo', 'App\Baz', 'uses');

        $deps = $this->storage->getDependencies('App\Foo');

        $this->assertCount(2, $deps);
        $this->assertContains(['to' => 'App\Bar', 'type' => 'extends'], $deps);
        $this->assertContains(['to' => 'App\Baz', 'type' => 'uses'], $deps);
    }

    public function testGetDependentsReturnsReverseDependencies(): void
    {
        $this->storage->storeDependency('App\Foo', 'App\Bar', 'extends');
        $this->storage->storeDependency('App\Baz', 'App\Bar', 'uses');

        $dependents = $this->storage->getDependents('App\Bar');

        $this->assertCount(2, $dependents);
        $this->assertContains(['from' => 'App\Foo', 'type' => 'extends'], $dependents);
        $this->assertContains(['from' => 'App\Baz', 'type' => 'uses'], $dependents);
    }

    public function testStoreAndRetrieveAggregatedMetrics(): void
    {
        $metrics = ['ccn.avg' => 5.5, 'loc.sum' => 1000];

        $this->storage->storeAggregated('namespace:App\Service', $metrics);

        $retrieved = $this->storage->getAggregated('namespace:App\Service');

        $this->assertSame($metrics, $retrieved);
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

        $retrieved = $this->storage->getFile('/src/Foo.php');

        $this->assertNotNull($retrieved);
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

        $retrieved = $this->storage->getFile('/src/Foo.php');

        $this->assertNull($retrieved);
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
        $this->storage->storeDependency('App\Foo', 'App\Bar', 'uses');

        $this->storage->clear();

        $this->assertNull($this->storage->getFile('/src/Foo.php'));
        $this->assertNull($this->storage->getMetrics(SymbolPath::forFile('/src/Foo.php')));
        $this->assertEmpty($this->storage->getDependencies('App\Foo'));
    }

    public function testGetStatsReturnsCorrectCounts(): void
    {
        $record = new FileRecord(
            path: '/src/Foo.php',
            contentHash: 'abc123',
            mtime: 1234567890,
            size: 1000,
        );

        $fileId = $this->storage->storeFile($record);
        $this->storage->storeMetrics(SymbolPath::forClass('App', 'Foo'), ['loc' => 100], $fileId);
        $this->storage->storeMetrics(SymbolPath::forMethod('App', 'Foo', 'bar'), ['ccn' => 5], $fileId);
        $this->storage->storeDependency('App\Foo', 'App\Bar', 'uses');

        $stats = $this->storage->getStats();

        $this->assertSame(1, $stats['files']);
        $this->assertSame(1, $stats['classes']);
        $this->assertSame(1, $stats['methods']);
        $this->assertSame(1, $stats['dependencies']);
    }

    public function testMetricsRoundTripWithScalarArrays(): void
    {
        $record = new FileRecord(
            path: '/src/Test.php',
            contentHash: 'hash123',
            mtime: 1234567890,
            size: 500,
        );

        $fileId = $this->storage->storeFile($record);

        // Metrics are scalar-only arrays (float|int) - should round-trip correctly
        $metrics = [
            'ccn' => 5,
            'loc' => 100,
            'ratio' => 0.85,
        ];

        $this->storage->storeMetrics(SymbolPath::forFile('/src/Test.php'), $metrics, $fileId);

        $retrieved = $this->storage->getMetrics(SymbolPath::forFile('/src/Test.php'));

        $this->assertSame($metrics, $retrieved);
    }

    public function testAggregatedMetricsRoundTripWithScalarArrays(): void
    {
        $metrics = ['ccn.avg' => 5.5, 'loc.sum' => 1000];

        $this->storage->storeAggregated('project:global', $metrics);

        $retrieved = $this->storage->getAggregated('project:global');

        $this->assertSame($metrics, $retrieved);
    }
}
