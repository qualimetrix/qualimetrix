<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkipReportingDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Discovery\AnalysisFileDiscovery;
use Qualimetrix\Analysis\Run\Discovery\DiscoveredAnalysisFiles;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeBindingProbe;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The coordinator's own gate, which holds for discoveries that never walked a
 * filesystem: whatever a discovery hands over, a directory is not a unit of
 * analysis. The walk has the same gate, and the point of having both is that
 * the invariant does not depend on which discovery produced the list.
 */
#[CoversClass(AnalysisFileDiscovery::class)]
final class AnalysisFileDiscoverySkipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-coord-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/subdir', 0755, true);
        file_put_contents($this->root . '/src/A.php', '<?php class A {}');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    #[Test]
    public function itRefusesADirectoryHandedOverAsAFile(): void
    {
        $result = $this->discover([
            new SplFileInfo($this->root . '/src/A.php'),
            new SplFileInfo($this->root . '/src/subdir'),
        ]);

        self::assertSame(
            [$this->root . '/src/A.php'],
            array_map(static fn(SplFileInfo $f): string => $f->getPathname(), $result->eligibleFiles),
        );
        self::assertSame(1, $result->discoveredCount);
        self::assertSame(
            ['src/subdir' => AnalysisFailureKind::DirectorySymlink],
            $this->reasons($result->skippedEntries),
        );
    }

    #[Test]
    public function itPassesAnAbsentPathThroughForTheParserToRefuse(): void
    {
        // Not discovery's question: the parser answers it with a typed error
        // that reaches coverage on its own, and inventing a second answer here
        // would give one path two terminal states.
        $result = $this->discover([new SplFileInfo('/project/src/Nowhere.php')]);

        self::assertCount(1, $result->eligibleFiles);
        self::assertSame([], $result->skippedEntries);
    }

    #[Test]
    public function itCarriesTheWalksOwnSkipsForward(): void
    {
        $planted = new SkippedEntry(
            AbsolutePath::fromString($this->root . '/src/linked'),
            AnalysisFailureKind::DirectorySymlink,
            'Symbolic link to a directory is not traversed',
        );

        $result = $this->discover([new SplFileInfo($this->root . '/src/A.php')], [$planted]);

        self::assertSame(['src/linked' => AnalysisFailureKind::DirectorySymlink], $this->reasons($result->skippedEntries));
    }

    #[Test]
    public function itRecordsOneTerminalStatePerPathWhenBothGatesSeeTheSameEntry(): void
    {
        $planted = new SkippedEntry(
            AbsolutePath::fromString($this->root . '/src/subdir'),
            AnalysisFailureKind::UnreadableDirectory,
            'Directory cannot be listed',
        );

        $result = $this->discover(
            [new SplFileInfo($this->root . '/src/subdir')],
            [$planted],
        );

        self::assertCount(1, $result->skippedEntries);
    }

    /**
     * @param list<SplFileInfo> $files
     * @param list<SkippedEntry> $skips
     */
    private function discover(array $files, array $skips = []): DiscoveredAnalysisFiles
    {
        $discovery = new class ($files, $skips) implements FileDiscoveryInterface, SkipReportingDiscoveryInterface {
            /**
             * @param list<SplFileInfo> $files
             * @param list<SkippedEntry> $skips
             */
            public function __construct(
                private readonly array $files,
                private readonly array $skips,
            ) {}

            /** @return iterable<AbsolutePath, SplFileInfo> */
            public function discover(AbsolutePath|array $paths): iterable
            {
                foreach ($this->files as $file) {
                    yield AbsolutePath::fromString($file->getPathname()) => $file;
                }
            }

            public function skippedEntries(): array
            {
                return $this->skips;
            }
        };

        $filter = self::createStub(GeneratedFileFilterInterface::class);
        $filter->method('filter')->willReturnCallback(static fn(array $files): array => $files);

        return (new AnalysisFileDiscovery(
            $discovery,
            $filter,
            new UnmatchedExcludeAudit(new UnmatchedExcludeOptions(), new ExcludeBindingProbe()),
        ))->discover(new RunConfiguration(
            paths: [AbsolutePath::fromString($this->root . '/src')],
            pathExcludes: [],
            projectRoot: AbsolutePath::fromString($this->root),
            generatedFilePolicy: GeneratedFilePolicy::Include,
            coversProjectScope: true,
            authoredPathExcludes: [],
        ));
    }

    /**
     * @param list<SkippedEntry> $skips
     *
     * @return array<string, AnalysisFailureKind>
     */
    private function reasons(array $skips): array
    {
        $reasons = [];
        foreach ($skips as $skip) {
            $relative = str_replace($this->root . '/', '', $skip->path->value());
            $reasons[$relative] = $skip->reason;
        }
        ksort($reasons);

        return $reasons;
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
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
