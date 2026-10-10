<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Run\Discovery\ProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ProjectFiles::class)]
final class FinderFileDiscoveryAbsolutePathTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir() . '/qmx-disco-vo-' . bin2hex(random_bytes(6));
        mkdir($this->fixturesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturesDir);
    }

    #[Test]
    public function itPublishesProjectRelativeNamesWithTheirSelectedFiles(): void
    {
        $this->createFile('A.php', '<?php class A {}');
        $this->createFile('B.php', '<?php class B {}');
        $files = $this->files([AbsolutePath::fromString($this->fixturesDir)])->eligibleFiles;
        $published = [];
        foreach ($files as $file) {
            $published[] = PathFactory::published(AbsolutePath::fromString($file->getPathname()), AbsolutePath::fromString($this->fixturesDir))->value();
        }

        self::assertSame(['A.php', 'B.php'], $published);
        self::assertCount(2, $files);
    }

    #[Test]
    public function itPublishesANamedSingleFile(): void
    {
        $file = $this->createFile('Solo.php', '<?php class Solo {}');
        $files = $this->files([AbsolutePath::fromString($file)])->eligibleFiles;

        self::assertCount(1, $files);
        self::assertSame($file, $files[0]->getPathname());
        self::assertSame('Solo.php', PathFactory::published(AbsolutePath::fromString($files[0]->getPathname()), AbsolutePath::fromString($this->fixturesDir))->value());
    }

    #[Test]
    public function itNormalizesDotSegmentsInPublishedInput(): void
    {
        $this->createFile('Norm.php', '<?php class Norm {}');
        $input = AbsolutePath::fromString($this->fixturesDir . '/./Norm.php');
        self::assertSame($this->fixturesDir . '/Norm.php', $input->value());

        $files = $this->files([$input])->eligibleFiles;
        self::assertSame([$this->fixturesDir . '/Norm.php'], array_map(static fn(SplFileInfo $file): string => $file->getPathname(), $files));
        self::assertSame(['Norm.php'], array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $files));
    }

    #[Test]
    public function itKeepsTheWrittenNamedFileLink(): void
    {
        $real = $this->createFile('Target.php', '<?php class Target {}');
        $link = $this->fixturesDir . '/Link.php';
        symlink($real, $link);

        $files = $this->files([AbsolutePath::fromString($link)])->eligibleFiles;
        self::assertSame([$link], array_map(static fn(SplFileInfo $file): string => $file->getPathname(), $files));
        self::assertSame(['Link.php'], array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $files));
        self::assertSame('Link.php', PathFactory::published(AbsolutePath::fromString($files[0]->getPathname()), AbsolutePath::fromString($this->fixturesDir))->value());
    }

    #[Test]
    public function itDeduplicatesOverlappingDirectoryInputs(): void
    {
        mkdir($this->fixturesDir . '/sub', 0755, true);
        $this->createFile('Outer.php', '<?php class Outer {}');
        $this->createFileInDir('sub', 'Inner.php', '<?php class Inner {}');
        $files = $this->files([
            AbsolutePath::fromString($this->fixturesDir),
            AbsolutePath::fromString($this->fixturesDir . '/sub'),
        ])->eligibleFiles;
        $pathnames = array_map(static fn(SplFileInfo $file): string => $file->getPathname(), $files);
        sort($pathnames);

        self::assertSame([$this->fixturesDir . '/Outer.php', $this->fixturesDir . '/sub/Inner.php'], $pathnames);
    }

    #[Test]
    public function itDeduplicatesSingleFileOverlappingWithDirectory(): void
    {
        $file = $this->createFile('Shared.php', '<?php class Shared {}');
        $files = $this->files([
            AbsolutePath::fromString($file),
            AbsolutePath::fromString($this->fixturesDir),
        ])->eligibleFiles;

        self::assertCount(1, $files);
        self::assertSame('Shared.php', $files[0]->getFilename());
    }

    /** @param list<AbsolutePath> $paths */
    private function files(array $paths): DiscoveredProjectFiles
    {
        $root = AbsolutePath::fromString($this->fixturesDir);
        $universe = new ProjectScopeUniverse($root, true, [], [], [], true, []);
        $run = new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new ProjectScopeMeasurement($universe, $paths, ProjectScopeState::Covered, []),
            authoredPathExcludes: [],
            autoloadDevPolicy: AutoloadDevPolicy::Exclude,
        );

        return (new ProjectFiles(new ProjectWalk(new EntryInspector()), new GeneratedFileFilter()))->discover($run);
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
            $item->isLink() || !$item->isDir() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($dir);
    }
}
