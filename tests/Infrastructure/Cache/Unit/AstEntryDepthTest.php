<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\AstEntryCodec;
use Qualimetrix\Infrastructure\Cache\FileCache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(AstEntryCodec::class)]
#[CoversClass(FileCache::class)]
final class AstEntryDepthTest extends TestCase
{
    #[Test]
    public function itRoundTripsATreeBeyondTheOldDepthLimit(): void
    {
        $nodes = $this->tree(300);
        $codec = new AstEntryCodec();

        $bytes = $codec->encode($nodes);
        self::assertNotNull($bytes);
        $decoded = $codec->decode($bytes);
        self::assertNotNull($decoded);
        self::assertSame(serialize($nodes), serialize($decoded));
    }

    #[Test]
    public function itDecodesWithinTheCodecBoundRegardlessOfTheUnserializeIniDepth(): void
    {
        $original = \ini_get('unserialize_max_depth');
        self::assertNotFalse($original);
        $nodes = $this->tree(20);
        $codec = new AstEntryCodec();
        $bytes = $codec->encode($nodes);
        self::assertNotNull($bytes);

        ini_set('unserialize_max_depth', '5');
        try {
            $decoded = $codec->decode($bytes);
            self::assertNotNull($decoded);
            self::assertSame(serialize($nodes), serialize($decoded));
        } finally {
            ini_set('unserialize_max_depth', $original);
        }
    }

    #[Test]
    public function itDoesNotEncodeOrDecodeATreeBeyondTheSharedDepthLimit(): void
    {
        $nodes = $this->tree(2050);
        $codec = new AstEntryCodec();

        self::assertNull($codec->encode($nodes));
        self::assertNull($codec->decode(serialize($nodes)));
    }

    #[Test]
    public function itDoesNotWriteOrCountAnEntryBeyondTheDepthLimit(): void
    {
        $directory = realpath(sys_get_temp_dir()) . '/qmx-cache-depth-' . bin2hex(random_bytes(6));
        $cache = new FileCache(AbsolutePath::fromString($directory));
        $key = str_repeat('a', 64);
        $path = $directory . '/ast-v' . AstEntryCodec::FORMAT_VERSION . '/aa/' . $key;

        try {
            $cache->set($key, $this->tree(2050));
            self::assertFileDoesNotExist($path);
            self::assertNull($cache->get($key));
            self::assertSame(0, $cache->discardedEntryCount());
        } finally {
            if (is_dir($directory)) {
                $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($directory);
            }
        }
    }

    /** @return list<Node> */
    private function tree(int $depth): array
    {
        $node = new String_('start');
        for ($index = 0; $index < $depth; ++$index) {
            $node = new Concat($node, new String_('next'));
        }

        return [$node];
    }
}
