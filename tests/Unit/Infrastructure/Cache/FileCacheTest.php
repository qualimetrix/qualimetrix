<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Cache;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Cache\FileCache;
use Qualimetrix\Infrastructure\Serializer\PhpSerializer;
use Qualimetrix\Infrastructure\Serializer\SerializerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

#[CoversClass(FileCache::class)]
final class FileCacheTest extends TestCase
{
    private string $cacheDir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/qmx-cache-test-' . uniqid();
        $this->cache = new FileCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    #[Test]
    public function itStoresAndRetrievesValues(): void
    {
        $this->cache->set('test-key', ['foo' => 'bar']);

        $value = $this->cache->get('test-key');

        self::assertSame(['foo' => 'bar'], $value);
    }

    #[Test]
    public function itReturnsNullForMissingKey(): void
    {
        $value = $this->cache->get('non-existent-key');

        self::assertNull($value);
    }

    #[Test]
    public function itChecksKeyExistence(): void
    {
        self::assertFalse($this->cache->has('test-key'));

        $this->cache->set('test-key', 'value');

        self::assertTrue($this->cache->has('test-key'));
    }

    #[Test]
    public function itDeletesKey(): void
    {
        $this->cache->set('test-key', 'value');
        self::assertTrue($this->cache->has('test-key'));

        $this->cache->delete('test-key');

        self::assertFalse($this->cache->has('test-key'));
    }

    #[Test]
    public function itClearsAllEntries(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');

        $this->cache->clear();

        self::assertFalse($this->cache->has('key1'));
        self::assertFalse($this->cache->has('key2'));
        self::assertFalse($this->cache->has('key3'));
    }

    #[Test]
    public function itCreatesDirectoryIfNotExists(): void
    {
        $newDir = $this->cacheDir . '/nested/dir';
        $cache = new FileCache($newDir);

        $cache->set('test-key', 'value');

        self::assertSame('value', $cache->get('test-key'));
    }

    #[Test]
    public function itUsesShardingForStorage(): void
    {
        // Key starting with "ab" should be stored in ab/ subdirectory
        $this->cache->set('ab123456789', 'value');

        $expectedPath = $this->cacheDir . '/ab/ab123456789.cache';
        self::assertFileExists($expectedPath);
    }

    #[Test]
    public function itStoresComplexDataTypes(): void
    {
        $data = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'array' => [1, 2, 3],
            'nested' => ['a' => ['b' => 'c']],
        ];

        $this->cache->set('complex', $data);

        self::assertSame($data, $this->cache->get('complex'));
    }

    #[Test]
    public function itHandlesObjectSerialization(): void
    {
        // FileCache delegates to PhpSerializer which allows objects
        // (needed for AST cache with PhpParser nodes)
        $object = new stdClass();
        $object->name = 'test';
        $object->value = 123;

        $this->cache->set('object', $object);

        $retrieved = $this->cache->get('object');
        self::assertInstanceOf(stdClass::class, $retrieved);
        self::assertSame('test', $retrieved->name);
    }

    #[Test]
    public function itDeletesNonExistentKeyGracefully(): void
    {
        // Should not throw
        $this->cache->delete('non-existent');

        self::assertFalse($this->cache->has('non-existent'));
    }

    #[Test]
    public function itClearsNonExistentDirectoryGracefully(): void
    {
        $cache = new FileCache('/non/existent/directory');

        // Should not throw
        $cache->clear();

        // If we get here, the test passed (no exception thrown)
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itReturnsDirectory(): void
    {
        self::assertSame($this->cacheDir, $this->cache->getDirectory());
    }

    #[Test]
    public function itHandlesCorruptedCacheEntry(): void
    {
        // Manually create a corrupted cache file
        $key = 'corrupted-key';
        $dir = $this->cacheDir . '/' . substr($key, 0, 2);
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . $key . '.cache', 'not valid serialized data');

        // Should return null and delete the corrupted entry
        $value = $this->cache->get($key);

        self::assertNull($value);
        self::assertFalse($this->cache->has($key));
    }

    #[Test]
    public function itWritesSerializerMarkerOnFirstAccess(): void
    {
        $this->cache->set('key', 'value');

        $markerPath = $this->cacheDir . '/.serializer';
        self::assertFileExists($markerPath);
        $content = file_get_contents($markerPath);
        self::assertIsString($content);
        self::assertNotEmpty(trim($content));
    }

    #[Test]
    public function itClearsCacheWhenSerializerChanges(): void
    {
        // Write data with default serializer
        $cache1 = new FileCache($this->cacheDir, new PhpSerializer());
        $cache1->set('key1', 'value1');
        self::assertSame('value1', $cache1->get('key1'));

        // Create a fake serializer with a different name
        $otherSerializer = $this->createFakeSerializer('other');

        // Create a new cache with the different serializer — should clear old data
        $cache2 = new FileCache($this->cacheDir, $otherSerializer);
        self::assertNull($cache2->get('key1'));

        // New writes should work
        $cache2->set('key2', 'value2');
        self::assertSame('value2', $cache2->get('key2'));
    }

    #[Test]
    public function itPreservesCacheWhenSerializerIsSame(): void
    {
        $cache1 = new FileCache($this->cacheDir, new PhpSerializer());
        $cache1->set('key1', 'value1');

        // Same serializer — cache should be preserved
        $cache2 = new FileCache($this->cacheDir, new PhpSerializer());
        self::assertSame('value1', $cache2->get('key1'));
    }

    #[Test]
    public function itRewritesMarkerAfterManualClear(): void
    {
        $this->cache->set('key', 'value');
        $markerPath = $this->cacheDir . '/.serializer';
        self::assertFileExists($markerPath);

        $this->cache->clear();
        self::assertFileDoesNotExist($markerPath);

        // Next access should re-create the marker
        $this->cache->set('key2', 'value2');
        self::assertFileExists($markerPath);
    }

    private function createFakeSerializer(string $name): SerializerInterface
    {
        $php = new PhpSerializer();

        return new class ($name, $php) implements SerializerInterface {
            public function __construct(
                private readonly string $name,
                private readonly PhpSerializer $inner,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getPriority(): int
            {
                return 0;
            }

            public function serialize(mixed $data): string
            {
                return $this->inner->serialize($data);
            }

            public function unserialize(string $data): mixed
            {
                return $this->inner->unserialize($data);
            }
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
