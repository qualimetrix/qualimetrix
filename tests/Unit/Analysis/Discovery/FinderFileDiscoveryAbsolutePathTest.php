<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Discovery;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Discovery\FinderFileDiscovery;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the AbsolutePath-typed contract of {@see FinderFileDiscovery::discover()}
 * introduced in ADR 0015 Phase 2: the iterator yields AbsolutePath as the key
 * for every SplFileInfo value, leading `.`/`..` segments are normalized at the
 * boundary, and missing paths are silently skipped (input-list mode).
 */
#[CoversClass(FinderFileDiscovery::class)]
final class FinderFileDiscoveryAbsolutePathTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir() . '/qmx-disco-vo-' . uniqid();
        mkdir($this->fixturesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturesDir);
    }

    #[Test]
    public function itYieldsAbsolutePathAsKey(): void
    {
        $this->createFile('A.php', '<?php class A {}');
        $this->createFile('B.php', '<?php class B {}');

        $discovery = new FinderFileDiscovery();
        $generator = $discovery->discover(AbsolutePath::fromString($this->fixturesDir));

        $count = 0;
        foreach ($generator as $pathKey => $splFileInfo) {
            ++$count;
            self::assertInstanceOf(AbsolutePath::class, $pathKey);
            self::assertSame($splFileInfo->getPathname(), $pathKey->value());
        }

        self::assertSame(2, $count);
    }

    #[Test]
    public function itYieldsAbsolutePathAsKeyForSingleFile(): void
    {
        $file = $this->createFile('Solo.php', '<?php class Solo {}');

        $discovery = new FinderFileDiscovery();
        $generator = $discovery->discover(AbsolutePath::fromString($file));

        $count = 0;
        foreach ($generator as $pathKey => $splFileInfo) {
            ++$count;
            self::assertInstanceOf(AbsolutePath::class, $pathKey);
            self::assertSame($file, $pathKey->value());
            self::assertSame('Solo.php', $splFileInfo->getFilename());
        }

        self::assertSame(1, $count);
    }

    #[Test]
    public function itNormalizesDotSegmentsInInput(): void
    {
        $this->createFile('Norm.php', '<?php class Norm {}');

        // AbsolutePath::fromString already normalizes `/.` segments, so input like
        // "/tmp/qmx-…/./Norm.php" reaches discover() as the canonical path.
        $input = AbsolutePath::fromString($this->fixturesDir . '/./Norm.php');
        self::assertSame($this->fixturesDir . '/Norm.php', $input->value());

        $discovery = new FinderFileDiscovery();
        $observedKeys = [];
        $observedNames = [];
        foreach ($discovery->discover($input) as $pathKey => $splFileInfo) {
            $observedKeys[] = $pathKey->value();
            $observedNames[] = $splFileInfo->getFilename();
        }

        self::assertSame([$this->fixturesDir . '/Norm.php'], $observedKeys);
        self::assertSame(['Norm.php'], $observedNames);
    }

    #[Test]
    public function itHandlesSymlinkedFile(): void
    {
        $real = $this->createFile('Target.php', '<?php class Target {}');
        $link = $this->fixturesDir . '/Link.php';
        symlink($real, $link);

        $discovery = new FinderFileDiscovery();
        $observedKeys = [];
        $observedNames = [];
        foreach ($discovery->discover(AbsolutePath::fromString($link)) as $pathKey => $splFileInfo) {
            $observedKeys[] = $pathKey->value();
            $observedNames[] = $splFileInfo->getFilename();
        }

        // FinderFileDiscovery passes the symlink path through unchanged (no
        // canonicalize()), so the key value mirrors the input.
        self::assertSame([$link], $observedKeys);
        self::assertSame(['Link.php'], $observedNames);
    }

    #[Test]
    public function itSkipsNonExistentAbsolutePath(): void
    {
        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array(
            $discovery->discover(AbsolutePath::fromString('/non/existent/qmx-vo-path')),
            false,
        );

        self::assertSame([], $files);
    }

    #[Test]
    public function itDeduplicatesOverlappingDirectoryInputs(): void
    {
        // `src/ src/sub/` (nested) used to be deduped implicitly by
        // iterator_to_array(..., true) collapsing duplicate string keys;
        // with AbsolutePath as the iterator key, dedup is now explicit at
        // the source. Pre-fix this test emitted `sub/Inner.php` twice.
        mkdir($this->fixturesDir . '/sub', 0755, true);
        $this->createFile('Outer.php', '<?php class Outer {}');
        $this->createFileInDir('sub', 'Inner.php', '<?php class Inner {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array(
            $discovery->discover([
                AbsolutePath::fromString($this->fixturesDir),
                AbsolutePath::fromString($this->fixturesDir . '/sub'),
            ]),
            false,
        );

        $pathnames = array_map(
            static fn(SplFileInfo $f): string => $f->getPathname(),
            $files,
        );
        sort($pathnames);

        self::assertSame(
            [
                $this->fixturesDir . '/Outer.php',
                $this->fixturesDir . '/sub/Inner.php',
            ],
            $pathnames,
        );
    }

    #[Test]
    public function itDeduplicatesSingleFileOverlappingWithDirectory(): void
    {
        // `qmx check src/Foo.php src/` — the file arg also matches the dir scan;
        // dedup must collapse them.
        $file = $this->createFile('Shared.php', '<?php class Shared {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array(
            $discovery->discover([
                AbsolutePath::fromString($file),
                AbsolutePath::fromString($this->fixturesDir),
            ]),
            false,
        );

        self::assertCount(1, $files);
        self::assertSame('Shared.php', $files[0]->getFilename());
    }

    #[Test]
    public function itSkipsNonExistentEntryInList(): void
    {
        $existing = $this->createFile('Real.php', '<?php class Real {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array(
            $discovery->discover([
                AbsolutePath::fromString('/non/existent/qmx-vo-list'),
                AbsolutePath::fromString($existing),
            ]),
            false,
        );

        self::assertCount(1, $files);
        self::assertSame('Real.php', $files[0]->getFilename());
    }

    private function createFile(string $name, string $content): string
    {
        $path = $this->fixturesDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function createFileInDir(string $dir, string $name, string $content): string
    {
        $path = $this->fixturesDir . '/' . $dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
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
            if ($item->isLink() || !$item->isDir()) {
                @unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
