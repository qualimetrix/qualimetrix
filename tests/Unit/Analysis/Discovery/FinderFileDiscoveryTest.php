<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Discovery;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Discovery\FinderFileDiscovery;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(FinderFileDiscovery::class)]
final class FinderFileDiscoveryTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir() . '/qmx-test-' . uniqid();
        mkdir($this->fixturesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturesDir);
    }

    #[Test]
    public function itDiscoversSingleFile(): void
    {
        $file = $this->createFile('Test.php', '<?php class Test {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover($file), false);

        self::assertCount(1, $files);
        self::assertInstanceOf(SplFileInfo::class, $files[0]);
        self::assertSame('Test.php', $files[0]->getFilename());
    }

    #[Test]
    public function itDiscoversFilesInDirectory(): void
    {
        $this->createFile('A.php', '<?php class A {}');
        $this->createFile('B.php', '<?php class B {}');
        $this->createFile('readme.txt', 'not php');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover($this->fixturesDir), false);

        self::assertCount(2, $files);

        $filenames = array_map(
            static fn(SplFileInfo $f): string => $f->getFilename(),
            $files,
        );
        sort($filenames);

        self::assertSame(['A.php', 'B.php'], $filenames);
    }

    #[Test]
    public function itExcludesVendorDirectory(): void
    {
        $this->createFile('App.php', '<?php class App {}');
        mkdir($this->fixturesDir . '/vendor', 0755, true);
        $this->createFileInDir('vendor', 'VendorClass.php', '<?php class VendorClass {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover($this->fixturesDir), false);

        self::assertCount(1, $files);
        self::assertSame('App.php', $files[0]->getFilename());
    }

    #[Test]
    public function itAcceptsMultiplePaths(): void
    {
        mkdir($this->fixturesDir . '/src', 0755, true);
        mkdir($this->fixturesDir . '/lib', 0755, true);

        $this->createFileInDir('src', 'Src.php', '<?php class Src {}');
        $this->createFileInDir('lib', 'Lib.php', '<?php class Lib {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover([
            $this->fixturesDir . '/src',
            $this->fixturesDir . '/lib',
        ]), false);

        self::assertCount(2, $files);
    }

    #[Test]
    public function itReturnsEmptyForEmptyPaths(): void
    {
        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover([]), false);

        self::assertSame([], $files);
    }

    #[Test]
    public function itSkipsNonExistentPaths(): void
    {
        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover('/non/existent/path'), false);

        self::assertSame([], $files);
    }

    #[Test]
    public function itSortsFilesByName(): void
    {
        $this->createFile('Z.php', '<?php class Z {}');
        $this->createFile('A.php', '<?php class A {}');
        $this->createFile('M.php', '<?php class M {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover($this->fixturesDir), false);

        $filenames = array_map(
            static fn(SplFileInfo $f): string => $f->getFilename(),
            $files,
        );

        self::assertSame(['A.php', 'M.php', 'Z.php'], $filenames);
    }

    #[Test]
    public function itDiscoversFilesInSubdirectories(): void
    {
        mkdir($this->fixturesDir . '/sub', 0755, true);
        $this->createFile('Root.php', '<?php class Root {}');
        $this->createFileInDir('sub', 'Sub.php', '<?php class Sub {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover($this->fixturesDir), false);

        self::assertCount(2, $files);
    }

    #[Test]
    public function itAcceptsMixedFilesAndDirectories(): void
    {
        mkdir($this->fixturesDir . '/src', 0755, true);
        $singleFile = $this->createFile('Single.php', '<?php class Single {}');
        $this->createFileInDir('src', 'InDir.php', '<?php class InDir {}');

        $discovery = new FinderFileDiscovery();
        $files = iterator_to_array($discovery->discover([
            $singleFile,
            $this->fixturesDir . '/src',
        ]), false);

        self::assertCount(2, $files);
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
