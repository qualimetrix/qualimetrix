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
use Qualimetrix\Subprocess\ChildProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(FileCache::class)]
final class FileCacheTest extends TestCase
{
    private string $cacheDir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = realpath(sys_get_temp_dir()) . '/qmx-cache-test-' . bin2hex(random_bytes(6));
        $this->cache = new FileCache(AbsolutePath::fromString($this->cacheDir));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    #[Test]
    public function itReusesOwnershipOfDirectoriesItCreatedForRepeatedEntries(): void
    {
        if (!\function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX owner lookup is unavailable');
        }
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
            namespace Qualimetrix\Core\FileTarget {
                function posix_geteuid(): int {
                    foreach (debug_backtrace(0) as $frame) {
                        if (($frame['function'] ?? '') === 'effectiveUid') {
                            $directory = $frame['args'][0];
                            if (str_starts_with($directory, $GLOBALS['cacheDirectory'])) {
                                ++$GLOBALS['ownerCalls'];
                            }
                        }
                    }
                    return \posix_geteuid();
                }
            }
            namespace {
                require $argv[1];
                $GLOBALS['cacheDirectory'] = $argv[2];
                $GLOBALS['ownerCalls'] = 0;
                $cache = new \Qualimetrix\Infrastructure\Cache\FileCache(
                    \Qualimetrix\Core\Path\AbsolutePath::fromString($argv[2]),
                    new \Qualimetrix\Infrastructure\Serializer\PhpSerializer(),
                );
                $cache->set('aa-first', 'first');
                $first = $GLOBALS['ownerCalls'];
                $cache->set('aa-second', 'second');
                $cache->set('aa-first', 'replacement');
                echo json_encode([$first, $GLOBALS['ownerCalls'], $cache->get('aa-first'), $cache->get('aa-second')], JSON_THROW_ON_ERROR);
            }
            PHP;
        $result = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $this->cacheDir]);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        [$first, $last, $replacement, $second] = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, $first);
        self::assertSame($first, $last);
        self::assertSame('replacement', $replacement);
        self::assertSame('second', $second);
    }

    #[Test]
    public function itEnumeratesThePrivateParentGroupOnceAcrossCacheEntries(): void
    {
        if (!\function_exists('posix_geteuid') || !\function_exists('posix_getegid')) {
            self::markTestSkipped('POSIX group facts are unavailable');
        }
        mkdir($this->cacheDir, 0775);
        chmod($this->cacheDir, 0775);
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
            require $argv[1];
            $uid = posix_geteuid();
            $gid = posix_getegid();
            $queries = [];
            $records = [
                'files:passwd' => "owner:x:$uid:$gid::/:/bin/sh\n",
                'systemd:passwd' => '',
                'files:group' => "owner:x:$gid:\n",
                'systemd:group' => '',
            ];
            $membership = \Qualimetrix\Core\FileTarget\NativePrivateGroupMembership::forProcess();
            foreach ([
                'readConfiguration' => static fn(): string => "passwd: files systemd\ngroup: files systemd\n",
                'enumerate' => static function (string $source, string $database) use (&$queries, $records): array {
                    $queries[] = "$source:$database";
                    return ['exitCode' => 0, 'output' => $records["$source:$database"]];
                },
                'userByUid' => static fn(int $id): array => ['name' => 'owner', 'uid' => $id, 'gid' => $gid],
                'groupByGid' => static fn(int $id): array => ['name' => 'owner', 'gid' => $id, 'members' => []],
            ] as $property => $value) {
                (new \ReflectionProperty($membership, $property))->setValue($membership, $value);
            }
            $cache = new \Qualimetrix\Infrastructure\Cache\FileCache(
                \Qualimetrix\Core\Path\AbsolutePath::fromString($argv[2]),
                new \Qualimetrix\Infrastructure\Serializer\PhpSerializer(),
            );
            $cache->set('aa-first', 'first');
            $cache->set('aa-second', 'second');
            $cache->set('bb-third', 'third');
            echo json_encode([$queries, $cache->get('aa-first'), $cache->get('aa-second'), $cache->get('bb-third')], JSON_THROW_ON_ERROR);
            PHP;
        $result = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $this->cacheDir]);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        [$queries, $first, $second, $third] = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['files:passwd', 'systemd:passwd', 'files:group', 'systemd:group'], $queries);
        self::assertSame(['first', 'second', 'third'], [$first, $second, $third]);
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

        $outcome = $this->cache->clear();

        self::assertTrue($outcome->complete);
        self::assertSame(0, $outcome->remaining);
        self::assertFalse($this->cache->has('key1'));
        self::assertFalse($this->cache->has('key2'));
        self::assertFalse($this->cache->has('key3'));
    }

    #[Test]
    public function itReportsAnOwnedEntryLeftBehindByAClearFailure(): void
    {
        $result = $this->runClearFault('entry');

        self::assertGreaterThan(0, $result['hookCalls']);
        self::assertTrue($result['entryExists']);
        self::assertFalse($result['outcome']['complete'] ?? null);
        self::assertSame(1, $result['outcome']['remaining'] ?? null);
        self::assertSame($this->cacheDir, $result['outcome']['directory'] ?? null);
        self::assertNotEmpty($result['outcome']['reason'] ?? null);
    }

    #[Test]
    public function itDoesNotCountForeignResidueAsAnOwnedEntry(): void
    {
        $result = $this->runClearFault('foreign');

        self::assertGreaterThan(0, $result['hookCalls']);
        self::assertFalse($result['entryExists']);
        self::assertTrue($result['foreignExists']);
        self::assertTrue($result['outcome']['complete'] ?? false);
        self::assertSame(0, $result['outcome']['remaining'] ?? null);
    }

    #[Test]
    public function itDoesNotClaimCompletionWhenALinkedShardCanHideCacheEntries(): void
    {
        $outside = $this->cacheDir . '-outside';
        mkdir($this->cacheDir);
        mkdir($outside);
        file_put_contents($outside . '/ab-key.cache', 'old bytes');
        symlink($outside, $this->cacheDir . '/ab');

        try {
            $outcome = $this->cache->clear();

            self::assertFalse($outcome->complete);
            self::assertNotEmpty($outcome->reason);
            self::assertSame('old bytes', file_get_contents($outside . '/ab-key.cache'));
        } finally {
            unlink($this->cacheDir . '/ab');
            unlink($outside . '/ab-key.cache');
            rmdir($outside);
        }
    }

    #[Test]
    public function itPreservesAnEntryAndRemovesTheTemporaryFileAfterAShortWrite(): void
    {
        $this->assertFailedReplacementLeavesOriginal('short');
    }

    #[Test]
    public function itPreservesAnEntryAndRemovesTheTemporaryFileAfterAWriteReturnsFalse(): void
    {
        $this->assertFailedReplacementLeavesOriginal('false');
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

        $outcome = $cache->clear();

        self::assertTrue($outcome->complete);
        self::assertSame(0, $outcome->remaining);
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

    /** @return array<string, mixed> */
    private function runClearFault(string $blocked): array
    {
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
namespace Qualimetrix\Infrastructure\Cache {
    function unlink(string $path): bool {
        if (($GLOBALS['qmx_blocked'] ?? null) === 'entry' && str_ends_with($path, '.cache')) {
            ++$GLOBALS['qmx_hook_calls'];
            return false;
        }
        if (($GLOBALS['qmx_blocked'] ?? null) === 'foreign' && str_ends_with($path, '/foreign.txt')) {
            ++$GLOBALS['qmx_hook_calls'];
            return false;
        }
        return \unlink($path);
    }
}
namespace {
    require $argv[1];
    $directory = $argv[2];
    $cache = new \Qualimetrix\Infrastructure\Cache\FileCache(
        \Qualimetrix\Core\Path\AbsolutePath::fromString($directory),
    );
    $cache->set('ab-key', 'value');
    \file_put_contents($directory . '/foreign.txt', 'foreign');
    $GLOBALS['qmx_hook_calls'] = 0;
    $GLOBALS['qmx_blocked'] = $argv[3];
    $outcome = $cache->clear();
    echo \json_encode([
        'hookCalls' => $GLOBALS['qmx_hook_calls'],
        'entryExists' => \file_exists($directory . '/ab/ab-key.cache'),
        'foreignExists' => \file_exists($directory . '/foreign.txt'),
        'outcome' => \is_object($outcome) ? [
            'complete' => $outcome->complete,
            'remaining' => $outcome->remaining,
            'directory' => $outcome->directory,
            'reason' => $outcome->reason,
        ] : null,
    ], \JSON_THROW_ON_ERROR);
}
PHP;

        $run = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $this->cacheDir, $blocked]);
        self::assertSame(0, $run['exitCode'], $run['stderr']);

        return json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);
    }

    private function assertFailedReplacementLeavesOriginal(string $fault): void
    {
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
namespace Qualimetrix\Infrastructure\Cache {
    function file_put_contents(string $path, string $data): int|false {
        if (($GLOBALS['qmx_fault'] ?? null) !== null && str_contains($path, '.cache.tmp.')) {
            ++$GLOBALS['qmx_old_hits'];
            \file_put_contents($path, substr($data, 0, 2));
            return $GLOBALS['qmx_fault'] === 'short' ? 2 : false;
        }
        return \file_put_contents($path, $data);
    }
}
namespace Qualimetrix\Core\FileTarget {
    function fwrite($handle, string $data): int|false {
        $uri = \stream_get_meta_data($handle)['uri'] ?? '';
        if (($GLOBALS['qmx_fault'] ?? null) !== null && str_starts_with($uri, $GLOBALS['qmx_shard_real'] . '/.qmx-')) {
            ++$GLOBALS['qmx_new_hits'];
            if ($GLOBALS['qmx_new_hits'] === 1) {
                return \fwrite($handle, substr($data, 0, 2));
            }
            return $GLOBALS['qmx_fault'] === 'short' ? 0 : false;
        }
        return \fwrite($handle, $data);
    }
}
namespace {
    require $argv[1];
    $directory = $argv[2];
    $cache = new \Qualimetrix\Infrastructure\Cache\FileCache(
        \Qualimetrix\Core\Path\AbsolutePath::fromString($directory),
    );
    $cache->set('ab-key', 'before');
    $GLOBALS['qmx_shard'] = $directory . '/ab';
    $GLOBALS['qmx_shard_real'] = \realpath($GLOBALS['qmx_shard']);
    $entry = $GLOBALS['qmx_shard'] . '/ab-key.cache';
    $original = \file_get_contents($entry);
    $GLOBALS['qmx_old_hits'] = 0;
    $GLOBALS['qmx_new_hits'] = 0;
    $GLOBALS['qmx_fault'] = $argv[3];
    $caught = false;
    try {
        $cache->set('ab-key', 'after');
    } catch (\Qualimetrix\Infrastructure\Cache\CacheWriteException) {
        $caught = true;
    }
    $names = \scandir($GLOBALS['qmx_shard']);
    if ($names === false) throw new \RuntimeException('Cannot inspect cache shard');
    echo \json_encode([
        'oldHits' => $GLOBALS['qmx_old_hits'],
        'newHits' => $GLOBALS['qmx_new_hits'],
        'caught' => $caught,
        'original' => \base64_encode($original),
        'after' => \base64_encode(\file_get_contents($entry)),
        'residue' => \array_values(\array_filter($names, static fn(string $name): bool =>
            str_contains($name, '.tmp.') || str_starts_with($name, '.qmx-'))),
    ], \JSON_THROW_ON_ERROR);
}
PHP;

        $run = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $this->cacheDir, $fault]);
        self::assertSame(0, $run['exitCode'], $run['stderr']);
        $result = json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);

        self::assertGreaterThan(0, $result['oldHits'] + $result['newHits'], 'Neither native write path was exercised.');
        self::assertTrue($result['caught']);
        self::assertSame($result['original'], $result['after']);
        self::assertSame([], $result['residue']);
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
