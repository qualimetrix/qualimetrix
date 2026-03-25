<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Storage\InMemoryStorage;
use Qualimetrix\Infrastructure\Storage\SqliteStorage;
use Qualimetrix\Infrastructure\Storage\StorageFactory;

final class StorageFactoryTest extends TestCase
{
    private StorageFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new StorageFactory();
    }

    public function testAutoSelectsMemoryForSmallProjects(): void
    {
        $storage = $this->factory->create(fileCount: 100);

        $this->assertInstanceOf(InMemoryStorage::class, $storage);
    }

    public function testAutoSelectsSqliteForLargeProjects(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite extension is not available');
        }

        $storage = $this->factory->create(fileCount: 2000, cacheDir: sys_get_temp_dir());

        $this->assertInstanceOf(SqliteStorage::class, $storage);
    }

    public function testRespectsExplicitSqliteConfig(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite extension is not available');
        }

        $storage = $this->factory->create(
            fileCount: 100,
            configuredType: 'sqlite',
            cacheDir: sys_get_temp_dir(),
        );

        $this->assertInstanceOf(SqliteStorage::class, $storage);
    }

    public function testRespectsExplicitMemoryConfig(): void
    {
        $storage = $this->factory->create(fileCount: 2000, configuredType: 'memory');

        $this->assertInstanceOf(InMemoryStorage::class, $storage);
    }
}
