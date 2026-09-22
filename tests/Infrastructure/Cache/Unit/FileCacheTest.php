<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
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
        $this->cacheDir = sys_get_temp_dir() . '/qmx-cache-test-' . bin2hex(random_bytes(6));
        $this->cache = new FileCache(AbsolutePath::fromString($this->cacheDir));
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
        $cache = new FileCache(AbsolutePath::fromString($newDir));

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
        $cache = new FileCache(AbsolutePath::fromString('/non/existent/directory'));

        // Should not throw
        $cache->clear();

        // If we get here, the test passed (no exception thrown)
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itReturnsDirectory(): void
    {
        self::assertSame($this->cacheDir, $this->cache->getDirectory()->value());
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
        $cache1 = new FileCache(AbsolutePath::fromString($this->cacheDir), new PhpSerializer());
        $cache1->set('key1', 'value1');
        self::assertSame('value1', $cache1->get('key1'));

        // Create a fake serializer with a different name
        $otherSerializer = $this->createFakeSerializer('other');

        // Create a new cache with the different serializer — should clear old data
        $cache2 = new FileCache(AbsolutePath::fromString($this->cacheDir), $otherSerializer);
        self::assertNull($cache2->get('key1'));

        // New writes should work
        $cache2->set('key2', 'value2');
        self::assertSame('value2', $cache2->get('key2'));
    }

    #[Test]
    public function itPreservesCacheWhenSerializerIsSame(): void
    {
        $cache1 = new FileCache(AbsolutePath::fromString($this->cacheDir), new PhpSerializer());
        $cache1->set('key1', 'value1');

        // Same serializer — cache should be preserved
        $cache2 = new FileCache(AbsolutePath::fromString($this->cacheDir), new PhpSerializer());
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

    /**
     * Same discipline as an entry: written elsewhere, then renamed into place.
     * What a reader can see of that from here is the absence of residue — a
     * write that landed by rename leaves no partial file behind. The race the
     * discipline exists for (several worker processes, each clearing the whole
     * directory on a marker it read as a mismatch) needs more than one process
     * and is not asserted here.
     */
    #[Test]
    public function itLeavesNoTemporaryResidueWhenWritingTheSerializerMarker(): void
    {
        $this->cache->set('key', 'value');

        $entries = scandir($this->cacheDir);
        $residue = array_values(array_filter(
            $entries === false ? [] : $entries,
            static fn(string $entry): bool => str_contains($entry, '.tmp.'),
        ));

        self::assertFileExists($this->cacheDir . '/.serializer');
        self::assertSame([], $residue);
    }

    #[Test]
    public function itSurvivesAnUnreadableSubdirectoryWhenClearing(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not refuse root');
        }

        $this->cache->set('key', 'value');
        mkdir($this->cacheDir . '/sealed', 0755, true);
        file_put_contents($this->cacheDir . '/sealed/entry', 'x');
        chmod($this->cacheDir . '/sealed', 0000);

        try {
            // clear() runs from get() and set(), so an exception escaping the
            // walk would turn a cache problem into a failed file.
            $this->cache->clear();
            self::assertNull($this->cache->get('key'));
        } finally {
            chmod($this->cacheDir . '/sealed', 0755);
        }
    }

    /**
     * The marker is a claim about what the directory holds, so a clear that
     * could not empty it must not be followed by one. The old name staying in
     * place is what keeps the next process clearing instead of trusting.
     */
    #[Test]
    public function itKeepsTheOldSerializerMarkerWhenTheClearCouldNotFinish(): void
    {
        $this->skipWhenPermissionBitsDoNotRefuse();

        $first = new FileCache(AbsolutePath::fromString($this->cacheDir), $this->createFakeSerializer('first'));
        $first->set('key', 'value');

        mkdir($this->cacheDir . '/sealed', 0755, true);
        file_put_contents($this->cacheDir . '/sealed/entry', 'x');
        chmod($this->cacheDir . '/sealed', 0000);

        try {
            $second = new FileCache(AbsolutePath::fromString($this->cacheDir), $this->createFakeSerializer('second'));
            $second->get('key');

            self::assertSame(
                'first',
                trim((string) file_get_contents($this->cacheDir . '/.serializer')),
                'the marker claimed a format the directory does not hold',
            );
        } finally {
            chmod($this->cacheDir . '/sealed', 0755);
        }
    }

    /**
     * The second door onto the same lie. Had the failed clear removed the
     * marker, the next process would read "nothing says", skip the clear
     * branch on that ground alone and write its own name over the surviving
     * entries — the same false claim, one process later.
     */
    #[Test]
    public function itKeepsRefusingToClaimTheFormatOnEveryLaterProcess(): void
    {
        $this->skipWhenPermissionBitsDoNotRefuse();

        $first = new FileCache(AbsolutePath::fromString($this->cacheDir), $this->createFakeSerializer('first'));
        $first->set('key', 'value');

        mkdir($this->cacheDir . '/sealed', 0755, true);
        file_put_contents($this->cacheDir . '/sealed/entry', 'x');
        chmod($this->cacheDir . '/sealed', 0000);

        try {
            foreach (['second', 'third'] as $name) {
                $cache = new FileCache(AbsolutePath::fromString($this->cacheDir), $this->createFakeSerializer($name));
                $cache->get('key');
            }

            self::assertSame(
                'first',
                trim((string) file_get_contents($this->cacheDir . '/.serializer')),
                'a later process claimed the format the clear never reached',
            );
        } finally {
            chmod($this->cacheDir . '/sealed', 0755);
        }
    }

    private function skipWhenPermissionBitsDoNotRefuse(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not refuse root');
        }
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
