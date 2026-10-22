<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use FilesystemIterator;
use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\TemporarySibling;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\AstEntryCodec;
use Qualimetrix\Infrastructure\Cache\FileCache;
use Qualimetrix\Infrastructure\Cache\SweepPolicy;
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
            namespace Qualimetrix\Core\FileTarget\PrivateGroup {
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
                );
                $cache->set('aa11111111111111111111111111111111111111111111111111111111111111', [new \PhpParser\Node\Stmt\Nop(['value' => 'first'])]);
                $first = $GLOBALS['ownerCalls'];
                $cache->set('aa22222222222222222222222222222222222222222222222222222222222222', [new \PhpParser\Node\Stmt\Nop(['value' => 'second'])]);
                $cache->set('aa11111111111111111111111111111111111111111111111111111111111111', [new \PhpParser\Node\Stmt\Nop(['value' => 'replacement'])]);
                echo json_encode([$first, $GLOBALS['ownerCalls'], $cache->get('aa11111111111111111111111111111111111111111111111111111111111111'), $cache->get('aa22222222222222222222222222222222222222222222222222222222222222')], JSON_THROW_ON_ERROR);
            }
            PHP;
        $result = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $this->cacheDir]);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        [$first, $last, $replacement, $second] = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, $first);
        self::assertSame($first, $last);
        self::assertSame('replacement', $replacement[0]['attributes']['value']);
        self::assertSame('second', $second[0]['attributes']['value']);
    }

    #[Test]
    public function itEnumeratesThePrivateParentGroupOnceAcrossCacheEntries(): void
    {
        if (!\function_exists('posix_geteuid') || !\function_exists('posix_getegid')) {
            self::markTestSkipped('POSIX group facts are unavailable');
        }
        self::assertTrue(mkdir($this->cacheDir, 0775));
        self::assertTrue(chgrp($this->cacheDir, posix_getegid()));
        self::assertTrue(chmod($this->cacheDir, 0775));
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
            $membership = \Qualimetrix\Core\FileTarget\PrivateGroup\NativePrivateGroupMembership::forProcess();
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
            );
            $cache->set('aa11111111111111111111111111111111111111111111111111111111111111', [new \PhpParser\Node\Stmt\Nop(['value' => 'first'])]);
            $cache->set('aa22222222222222222222222222222222222222222222222222222222222222', [new \PhpParser\Node\Stmt\Nop(['value' => 'second'])]);
            $cache->set('bb22222222222222222222222222222222222222222222222222222222222222', [new \PhpParser\Node\Stmt\Nop(['value' => 'third'])]);
            echo json_encode([$queries, $cache->get('aa11111111111111111111111111111111111111111111111111111111111111'), $cache->get('aa22222222222222222222222222222222222222222222222222222222222222'), $cache->get('bb22222222222222222222222222222222222222222222222222222222222222')], JSON_THROW_ON_ERROR);
            PHP;
        $result = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $this->cacheDir]);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        [$queries, $first, $second, $third] = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['files:passwd', 'systemd:passwd', 'files:group', 'systemd:group'], $queries);
        self::assertSame(['first', 'second', 'third'], [$first[0]['attributes']['value'], $second[0]['attributes']['value'], $third[0]['attributes']['value']]);
    }

    #[Test]
    public function itWritesOnlyVersionedAstEntriesAndTouchesHits(): void
    {
        $key = str_repeat('a', 64);
        $nodes = [new Nop(['value' => 'round trip'])];
        self::assertNull($this->cache->get($key));
        $this->cache->set($key, $nodes);
        $path = $this->path($key);
        self::assertFileExists($path);
        self::assertFileExists(\dirname(\dirname($path)) . '/CACHEDIR.TAG');
        self::assertFileDoesNotExist($this->cacheDir . '/CACHEDIR.TAG');
        self::assertFileDoesNotExist($this->cacheDir . '/.serializer');
        self::assertSame(0700, fileperms(\dirname($path)) & 0777);
        touch($path, time() - 3000000);
        self::assertEquals($nodes, $this->cache->get($key));
        clearstatcache(true, $path);
        self::assertGreaterThan(time() - 10, filemtime($path));
        $this->cache->delete($key);
        self::assertFalse($this->cache->has($key));
    }

    #[Test]
    public function itDeletesPoisonAndCountsEachDiscard(): void
    {
        $key = str_repeat('b', 64);
        $this->cache->set($key, []);
        file_put_contents($this->path($key), serialize([new stdClass()]));
        self::assertNull($this->cache->get($key));
        self::assertFalse($this->cache->has($key));
        self::assertSame(1, $this->cache->discardedEntryCount());
        self::assertNull($this->cache->get($key));
        self::assertSame(1, $this->cache->discardedEntryCount());
    }

    #[Test]
    public function itSweepsAllVersionsByCursorAgeAndQuotaAndPreservesForeignFiles(): void
    {
        $now = time();
        foreach ([1, 99] as $version) {
            for ($shard = 0; $shard < 256; ++$shard) {
                $dir = $this->cacheDir . '/ast-v' . $version . '/' . \sprintf('%02x', $shard);
                mkdir($dir, 0700, true);
                foreach ([1, 2, 3] as $id) {
                    $key = \sprintf('%02x', $shard) . str_repeat((string) $id, 62);
                    file_put_contents($dir . '/' . $key, str_repeat('x', 20));
                    touch($dir . '/' . $key, $now - ($id === 1 ? 3000000 : 100 - $id));
                }
            }
        }
        file_put_contents($this->cacheDir . '/ast-v1/.sweep', '0');
        file_put_contents($this->cacheDir . '/ast-v1/00/notes.txt', 'foreign');
        $removed = 0;
        for ($step = 0; $step < 16; ++$step) {
            $outcome = $this->cache->sweepStep($now, new SweepPolicy(shardQuotaBytes: 10));
            self::assertSame((($step + 1) * 16) % 256, $outcome->nextShard);
            self::assertSame(32, $outcome->shards);
            self::assertNull($outcome->cursorFailure);
            $removed += $outcome->removed;
        }
        self::assertSame(1024, $removed);
        $entries = glob($this->cacheDir . '/ast-v*/??/' . str_repeat('?', 64));
        self::assertNotFalse($entries);
        self::assertCount(512, $entries);
        self::assertSame('foreign', file_get_contents($this->cacheDir . '/ast-v1/00/notes.txt'));
        self::assertSame('0', file_get_contents($this->cacheDir . '/ast-v1/.sweep'));
    }

    #[Test]
    public function itSweepsAndClearsTheTemporaryNamesCreatedByPublication(): void
    {
        $this->cache->set(str_repeat('a', 64), []);
        $orphan = $this->abandonedTemporarySibling($this->cacheDir . '/ast-v1/aa');
        $metadata = $this->abandonedTemporarySibling($this->cacheDir . '/ast-v1');
        touch($orphan, time() - 3_000_000);
        touch($metadata, time() - 3_000_000);
        file_put_contents($this->cacheDir . '/ast-v1/.sweep', '170');
        self::assertSame(2, $this->cache->sweepStep(time(), SweepPolicy::default())->removed);
        self::assertFileDoesNotExist($orphan);
        self::assertFileDoesNotExist($metadata);
        $this->abandonedTemporarySibling($this->cacheDir . '/ast-v1/aa');
        $this->abandonedTemporarySibling($this->cacheDir . '/ast-v1');
        self::assertTrue($this->cache->clear()->complete);
        self::assertSame([], glob($this->cacheDir . '/.clearing-*'));
    }

    private function abandonedTemporarySibling(string $directory): string
    {
        $temporary = TemporarySibling::create(AbsolutePath::fromString($directory));
        $path = $temporary->path()->value();
        fclose($temporary->takeHandle());

        return $path;
    }

    #[Test]
    public function itClearsVersionsAtomicallyWithoutRemovingForeignContents(): void
    {
        $key = str_repeat('c', 64);
        $this->cache->set($key, []);
        mkdir($this->cacheDir . '/ast-v99/aa', 0700, true);
        file_put_contents($this->cacheDir . '/ast-v99/aa/' . str_repeat('a', 64), serialize([]));
        file_put_contents($this->cacheDir . '/notes.txt', 'outside');
        file_put_contents($this->cacheDir . '/ast-v1/cc/foreign.txt', 'inside');
        $outcome = $this->cache->clear();
        self::assertFalse($outcome->complete);
        self::assertSame(0, $outcome->remaining);
        self::assertStringContainsString('Kept 1 detached directory that still holds files:', $outcome->reason ?? '');
        self::assertStringContainsString('.clearing-', $outcome->reason ?? '');
        self::assertFalse($this->cache->has($key));
        self::assertDirectoryDoesNotExist($this->cacheDir . '/ast-v99');
        self::assertSame('outside', file_get_contents($this->cacheDir . '/notes.txt'));
        $foreign = glob($this->cacheDir . '/.clearing-*/cc/foreign.txt');
        self::assertNotFalse($foreign);
        self::assertCount(1, $foreign);
        $repeated = $this->cache->clear();
        self::assertFalse($repeated->complete);
        self::assertStringContainsString('.clearing-', $repeated->reason ?? '');
        unlink($foreign[0]);
        self::assertTrue($this->cache->clear()->complete);
        self::assertSame([], glob($this->cacheDir . '/.clearing-*'));
    }

    #[Test]
    public function itCountsMultipleRetainedDetachedDirectories(): void
    {
        foreach ([1, 99] as $version) {
            mkdir($this->cacheDir . '/ast-v' . $version, 0700, true);
            file_put_contents($this->cacheDir . '/ast-v' . $version . '/notes.txt', 'foreign');
        }

        $outcome = $this->cache->clear();

        self::assertFalse($outcome->complete);
        self::assertSame(0, $outcome->remaining);
        self::assertStringContainsString('Kept 2 detached directories that still hold files:', $outcome->reason ?? '');
        $retained = glob($this->cacheDir . '/.clearing-*');
        self::assertNotFalse($retained);
        self::assertCount(2, $retained);
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

        self::assertSame(0, $result['hookCalls'], 'Foreign names must not be offered for deletion.');
        self::assertFalse($result['entryExists']);
        self::assertTrue($result['foreignExists']);
        self::assertFalse($result['outcome']['complete'] ?? true);
        self::assertSame(0, $result['outcome']['remaining'] ?? null);
    }

    #[Test]
    public function itDoesNotClaimCompletionWhenALinkedShardCanHideCacheEntries(): void
    {
        $outside = $this->cacheDir . '-outside';
        mkdir($this->cacheDir . '/ast-v1', 0700, true);
        mkdir($outside);
        file_put_contents($outside . '/' . str_repeat('a', 64), 'old bytes');
        symlink($outside, $this->cacheDir . '/ast-v1/ab');

        try {
            $outcome = $this->cache->clear();

            self::assertFalse($outcome->complete);
            self::assertNotEmpty($outcome->reason);
            self::assertSame('old bytes', file_get_contents($outside . '/' . str_repeat('a', 64)));
        } finally {
            $links = glob($this->cacheDir . '/.clearing-*/ab');
            foreach ($links === false ? [] : $links as $link) {
                unlink($link);
            }
            unlink($outside . '/' . str_repeat('a', 64));
            rmdir($outside);
        }
    }

    #[Test]
    public function itSurvivesAnUnreadableSubdirectoryWhenClearing(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not refuse root.');
        }
        $key = str_repeat('e', 64);
        $this->cache->set($key, []);
        chmod($this->cacheDir . '/ast-v1/ee', 0000);
        try {
            $outcome = $this->cache->clear();
            self::assertFalse($outcome->complete);
            self::assertStringContainsString('Cannot inspect cache shard', $outcome->reason ?? '');
            self::assertNull($this->cache->get($key));
        } finally {
            $shards = glob($this->cacheDir . '/.clearing-*/ee');
            foreach ($shards === false ? [] : $shards as $shard) {
                chmod($shard, 0700);
            }
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
    #[TestWith(['cache[1]'])]
    #[TestWith(['cache?'])]
    #[TestWith(['cache*'])]
    public function itRetriesOnlyDetachedDirectoriesUnderTheLiteralCacheRoot(string $name): void
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('Literal wildcard filenames require a POSIX host.');
        }
        $directory = $this->cacheDir . '/' . $name;
        $detached = $directory . '/.clearing-aaaaaaaaaaaaaaaa';
        $key = str_repeat('a', 64);
        mkdir($detached . '/aa', 0700, true);
        file_put_contents($detached . '/aa/' . $key, 'OWN ENTRY');
        file_put_contents($detached . '/foreign.txt', 'KEEP FOREIGN');
        $cache = new FileCache(AbsolutePath::fromString($directory));

        $first = $cache->clear();
        self::assertFalse($first->complete);
        self::assertFileDoesNotExist($detached . '/aa/' . $key);
        self::assertSame('KEEP FOREIGN', file_get_contents($detached . '/foreign.txt'));

        $sibling = $this->cacheDir . '/cache1/.clearing-bbbbbbbbbbbbbbbb/aa/' . $key;
        mkdir(\dirname($sibling), 0700, true);
        file_put_contents($sibling, 'KEEP SIBLING');
        $second = $cache->clear();
        self::assertFileExists($sibling);
        self::assertSame('KEEP SIBLING', file_get_contents($sibling));
        self::assertFalse($second->complete);
        self::assertSame('KEEP FOREIGN', file_get_contents($detached . '/foreign.txt'));

        unlink($detached . '/foreign.txt');
        self::assertTrue($cache->clear()->complete);
        self::assertDirectoryDoesNotExist($detached);
        self::assertSame('KEEP SIBLING', file_get_contents($sibling));
    }

    #[Test]
    public function itTreatsDirectoryMetacharactersAsLiteralNamesWhenSweepingAndClearing(): void
    {
        $directory = $this->cacheDir . '/cache[1]';
        $cache = new FileCache(AbsolutePath::fromString($directory));
        $key = str_repeat('d', 64);
        $cache->set($key, []);
        $version = $directory . '/ast-v1';
        file_put_contents($version . '/.sweep', '208');
        touch($version . '/dd/' . $key, time() - 3000000);
        self::assertSame(1, $cache->sweepStep(time(), SweepPolicy::default())->removed);
        self::assertFalse($cache->has($key));
        $cache->set($key, []);
        self::assertTrue($cache->clear()->complete);
        self::assertFalse($cache->has($key));
    }

    private function path(string $key): string
    {
        return $this->cacheDir . '/ast-v' . AstEntryCodec::FORMAT_VERSION . '/' . substr($key, 0, 2) . '/' . $key;
    }

    /** @return array{hookCalls: int, entryExists: bool, foreignExists: bool, outcome: array{complete: bool, remaining: int, directory: string, reason: ?string}} */
    private function runClearFault(string $blocked): array
    {
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
namespace Qualimetrix\Infrastructure\Cache {
    function unlink(string $path): bool {
        if (($GLOBALS['qmx_blocked'] ?? null) === 'entry' && str_ends_with($path, '/' . str_repeat('a', 64))) {
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
    $cache->set(str_repeat('a', 64), []);
    \file_put_contents($directory . '/ast-v1/aa/foreign.txt', 'foreign');
    $GLOBALS['qmx_hook_calls'] = 0;
    $GLOBALS['qmx_blocked'] = $argv[3];
    $outcome = $cache->clear();
    echo \json_encode([
        'hookCalls' => $GLOBALS['qmx_hook_calls'],
        'entryExists' => count(\glob($directory . '/.clearing-*/aa/' . str_repeat('a', 64)) ?: []) === 1,
        'foreignExists' => count(\glob($directory . '/.clearing-*/aa/foreign.txt') ?: []) === 1,
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
namespace Qualimetrix\Core\FileTarget\Publication {
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
    $cache->set(str_repeat('a', 64), [new \PhpParser\Node\Stmt\Nop(['value' => 'before'])]);
    $GLOBALS['qmx_shard'] = $directory . '/ast-v' . \Qualimetrix\Infrastructure\Cache\AstEntryCodec::FORMAT_VERSION . '/aa';
    $GLOBALS['qmx_shard_real'] = \realpath($GLOBALS['qmx_shard']);
    $entry = $GLOBALS['qmx_shard'] . '/' . str_repeat('a', 64);
    $original = \file_get_contents($entry);
    $GLOBALS['qmx_old_hits'] = 0;
    $GLOBALS['qmx_new_hits'] = 0;
    $GLOBALS['qmx_fault'] = $argv[3];
    $caught = false;
    try {
        $cache->set(str_repeat('a', 64), [new \PhpParser\Node\Stmt\Nop(['value' => 'after'])]);
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
