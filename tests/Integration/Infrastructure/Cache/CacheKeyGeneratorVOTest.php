<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Infrastructure\Cache;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the symlink-aware cache key stability contract (ADR 0015 Phase 5):
 * a file accessed through a symlink and through its canonical real path
 * must produce the same cache key, so AST cache hits survive checkout/build
 * directories that route through `dist/` or `link-to-src/` symlinks.
 */
#[CoversClass(CacheKeyGenerator::class)]
final class CacheKeyGeneratorVOTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-cachekeygen-vo-' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->tempDir);
    }

    #[Test]
    public function itProducesStableCacheKeyForSymlinkedFiles(): void
    {
        $realFile = $this->tempDir . '/real.php';
        $symlinkFile = $this->tempDir . '/link.php';
        file_put_contents($realFile, '<?php class Real {}');
        symlink($realFile, $symlinkFile);

        $generator = new CacheKeyGenerator();

        $keyForReal = $generator->generate(new SplFileInfo($realFile));
        $keyForSymlink = $generator->generate(new SplFileInfo($symlinkFile));

        // Both paths resolve to the same realpath, so cache keys must match —
        // this is the property that lets AST caches survive symlinked build dirs.
        self::assertSame($keyForReal, $keyForSymlink);
    }

    #[Test]
    public function itProducesDifferentKeysForDifferentRealFiles(): void
    {
        $fileA = $this->tempDir . '/a.php';
        $fileB = $this->tempDir . '/b.php';
        file_put_contents($fileA, '<?php class A {}');
        file_put_contents($fileB, '<?php class B {}');

        $generator = new CacheKeyGenerator();

        self::assertNotSame(
            $generator->generate(new SplFileInfo($fileA)),
            $generator->generate(new SplFileInfo($fileB)),
        );
    }

    #[Test]
    public function itHandlesUnresolvableFileGracefully(): void
    {
        $generator = new CacheKeyGenerator();
        $missing = new SplFileInfo($this->tempDir . '/missing.php');

        // Should not throw — cache key generator is tolerant of broken paths
        $key = $generator->generate($missing);

        self::assertNotEmpty($key);
    }
}
