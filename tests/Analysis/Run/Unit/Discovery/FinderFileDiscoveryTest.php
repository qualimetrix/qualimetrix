<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use FilesystemIterator;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Analysis\Run\Discovery\FinderFileDiscovery;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(FinderFileDiscovery::class)]
final class FinderFileDiscoveryTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir() . '/qmx-test-' . bin2hex(random_bytes(6));
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

        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover(AbsolutePath::fromString($file)), false);

        self::assertCount(1, $files);
        self::assertInstanceOf(SplFileInfo::class, $files[0]); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('Test.php', $files[0]->getFilename());
    }

    #[Test]
    public function itDiscoversFilesInDirectory(): void
    {
        $this->createFile('A.php', '<?php class A {}');
        $this->createFile('B.php', '<?php class B {}');
        $this->createFile('readme.txt', 'not php');

        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover(AbsolutePath::fromString($this->fixturesDir)), false);

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

        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover(AbsolutePath::fromString($this->fixturesDir)), false);

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

        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover([
            AbsolutePath::fromString($this->fixturesDir . '/src'),
            AbsolutePath::fromString($this->fixturesDir . '/lib'),
        ]), false);

        self::assertCount(2, $files);
    }

    #[Test]
    public function itReturnsEmptyForEmptyPaths(): void
    {
        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover([]), false);

        self::assertSame([], $files);
    }

    #[Test]
    public function itSkipsNonExistentPaths(): void
    {
        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover(AbsolutePath::fromString('/non/existent/path')), false);

        self::assertSame([], $files);
    }

    #[Test]
    public function itSortsFilesByName(): void
    {
        $this->createFile('Z.php', '<?php class Z {}');
        $this->createFile('A.php', '<?php class A {}');
        $this->createFile('M.php', '<?php class M {}');

        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover(AbsolutePath::fromString($this->fixturesDir)), false);

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

        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover(AbsolutePath::fromString($this->fixturesDir)), false);

        self::assertCount(2, $files);
    }

    #[Test]
    public function itAcceptsMixedFilesAndDirectories(): void
    {
        mkdir($this->fixturesDir . '/src', 0755, true);
        $singleFile = $this->createFile('Single.php', '<?php class Single {}');
        $this->createFileInDir('src', 'InDir.php', '<?php class InDir {}');

        $discovery = $this->discovery();
        $files = iterator_to_array($discovery->discover([
            AbsolutePath::fromString($singleFile),
            AbsolutePath::fromString($this->fixturesDir . '/src'),
        ]), false);

        self::assertCount(2, $files);
    }

    /** @return iterable<string, array{string, string}> */
    public static function providePrunedRoots(): iterable
    {
        yield 'vendor at the root' => ['vendor', 'vendor'];
        yield 'vendor below the root' => ['lib/vendor', 'lib/vendor'];
        yield 'written with a trailing slash' => ['lib/vendor/', 'lib/vendor'];
        yield 'node_modules' => ['node_modules', 'node_modules'];
        yield '.git' => ['.git', '.git'];
    }

    #[Test]
    #[DataProvider('providePrunedRoots')]
    public function itRefusesANamedRootThatIsADirectoryItNeverWalks(string $written, string $shown): void
    {
        mkdir($this->fixturesDir . '/' . $shown, 0755, true);
        $this->createFileInDir($shown, 'Hidden.php', '<?php class Hidden {}');

        try {
            iterator_to_array($this->discovery()->discover(AbsolutePath::fromString($this->fixturesDir . '/' . $written)), false);
            self::fail('A named root the walk never enters must be refused, not analysed as empty.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringStartsWith(\sprintf('"%s" is a vendor, node_modules or .git directory', $shown), $refusal->summary());
        }
    }

    #[Test]
    public function itRefusesBeforeYieldingAFileNamedBesideThePrunedRoot(): void
    {
        $file = $this->createFile('Named.php', '<?php class Named {}');
        mkdir($this->fixturesDir . '/lib/vendor', 0755, true);
        mkdir($this->fixturesDir . '/node_modules', 0755, true);

        $discovered = $this->discovery()->discover([
            AbsolutePath::fromString($file),
            AbsolutePath::fromString($this->fixturesDir . '/lib/vendor'),
            AbsolutePath::fromString($this->fixturesDir . '/node_modules'),
        ]);
        self::assertInstanceOf(Generator::class, $discovered);

        try {
            // Runs the walk up to its first yield: a refusal that comes after a
            // file was handed out would return here instead of throwing.
            $discovered->current();
            self::fail('Expected a refusal before the first file.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringStartsWith(
                '"lib/vendor", "node_modules" are vendor, node_modules or .git directories',
                $refusal->summary(),
            );
        }
    }

    #[Test]
    public function itWalksANamedRootThatLiesInsideAPrunedDirectory(): void
    {
        mkdir($this->fixturesDir . '/vendor/acme', 0755, true);
        $this->createFileInDir('vendor/acme', 'Vendored.php', '<?php class Vendored {}');

        $files = iterator_to_array($this->discovery()->discover(AbsolutePath::fromString($this->fixturesDir . '/vendor/acme')), false);

        self::assertCount(1, $files);
        self::assertSame('Vendored.php', $files[0]->getFilename());
    }

    #[Test]
    public function itAnalysesAFileNamedInsideAPrunedDirectory(): void
    {
        mkdir($this->fixturesDir . '/vendor/acme', 0755, true);
        $file = $this->createFileInDir('vendor/acme', 'helpers.php', '<?php function helper() {}');

        $files = iterator_to_array($this->discovery()->discover(AbsolutePath::fromString($file)), false);

        self::assertCount(1, $files);
    }

    #[Test]
    public function itStillPrunesAVendorDirectoryBelowANamedRoot(): void
    {
        mkdir($this->fixturesDir . '/src/vendor', 0755, true);
        $this->createFileInDir('src', 'App.php', '<?php class App {}');
        $this->createFileInDir('src/vendor', 'Hidden.php', '<?php class Hidden {}');

        $files = iterator_to_array($this->discovery()->discover(AbsolutePath::fromString($this->fixturesDir . '/src')), false);

        self::assertSame(['App.php'], array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $files));
    }

    /**
     * An authored exclude may remove a default root on purpose — a composer
     * root the author does not want analysed — and discovery cannot tell that
     * root from one written on the command line.
     */
    #[Test]
    public function itLeavesARootRemovedByAnAuthoredExcludeToThatExclude(): void
    {
        mkdir($this->fixturesDir . '/legacy', 0755, true);
        $this->createFileInDir('legacy', 'Old.php', '<?php class Old {}');
        $discovery = new FinderFileDiscovery(new DirectoryPruner(
            AbsolutePath::fromString($this->fixturesDir),
            [...DirectoryPruner::builtInPatterns(), new PathPattern(new SelectorDefinition(SelectorKind::Exact, 'legacy'))],
        ));

        self::assertSame([], iterator_to_array($discovery->discover(AbsolutePath::fromString($this->fixturesDir . '/legacy')), false));
    }

    private function createFile(string $name, string $content): string
    {
        $path = $this->fixturesDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function discovery(): FinderFileDiscovery
    {
        $root = AbsolutePath::fromString($this->fixturesDir);

        return new FinderFileDiscovery(new DirectoryPruner($root, DirectoryPruner::builtInPatterns()));
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
