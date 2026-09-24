<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Analysis\Run\Discovery\FinderFileDiscovery;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * What the walk refuses, and that it says so.
 *
 * Every case here used to end the same way: the file set came out smaller than
 * the tree, the run reported success, and nothing named the difference. The
 * assertions are therefore always a pair — not yielded **and** recorded.
 */
#[CoversClass(FinderFileDiscovery::class)]
final class FinderFileDiscoverySkipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-skip-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/tree', 0755, true);
        file_put_contents($this->root . '/tree/Kept.php', '<?php class Kept {}');
    }

    protected function tearDown(): void
    {
        $this->unlock($this->root);
        $this->removeDirectory($this->root);
    }

    #[Test]
    public function itRecordsADirectorySymlinkInsteadOfCountingItAsAFile(): void
    {
        mkdir($this->root . '/outside', 0755, true);
        file_put_contents($this->root . '/outside/Hidden.php', '<?php class Hidden {}');
        symlink($this->root . '/outside', $this->root . '/tree/linked');

        [$files, $skips] = $this->walk();

        self::assertSame(['Kept.php'], $files);
        self::assertSame(
            [$this->root . '/tree/linked' => AnalysisFailureKind::DirectorySymlink],
            $this->reasonsByPath($skips),
        );
    }

    #[Test]
    public function itFollowsASymlinkToAFileAsAnOrdinaryUnitOfAnalysis(): void
    {
        mkdir($this->root . '/outside', 0755, true);
        file_put_contents($this->root . '/outside/Target.php', '<?php class Target {}');
        symlink($this->root . '/outside/Target.php', $this->root . '/tree/Linked.php');

        [$files, $skips] = $this->walk();

        self::assertSame(['Kept.php', 'Linked.php'], $files);
        self::assertSame([], $skips);
    }

    #[Test]
    public function itSaysNothingAboutASelfReferencingSymlinkThatIsNoCandidate(): void
    {
        symlink($this->root . '/tree/loop', $this->root . '/tree/loop');

        [$files, $skips] = $this->walk();

        self::assertSame(['Kept.php'], $files);
        self::assertSame([], $skips, 'A cycle that is not a `*.php` candidate is simply not a candidate');
    }

    #[Test]
    public function itRecordsASymlinkPointingAtItsOwnAncestor(): void
    {
        symlink($this->root . '/tree', $this->root . '/tree/self');

        [$files, $skips] = $this->walk();

        self::assertSame(['Kept.php'], $files);
        self::assertSame(
            [$this->root . '/tree/self' => AnalysisFailureKind::DirectorySymlink],
            $this->reasonsByPath($skips),
        );
    }

    /**
     * Overlapping roots walk the same entry more than once. Two records for one
     * path are two terminal states for one path, which the coverage invariant
     * refuses by throwing — turning the fix for a silent loss into a run that
     * produces nothing at all.
     */
    #[Test]
    public function itRecordsAnEntryOnceWhenOverlappingRootsBothReachIt(): void
    {
        mkdir($this->root . '/tree/sub', 0755, true);
        mkdir($this->root . '/outside', 0755, true);
        symlink($this->root . '/outside', $this->root . '/tree/sub/link');

        $discovery = $this->discovery();
        iterator_to_array($discovery->discover([
            AbsolutePath::fromString($this->root . '/tree'),
            AbsolutePath::fromString($this->root . '/tree/sub'),
        ]), false);

        self::assertSame(
            [$this->root . '/tree/sub/link' => AnalysisFailureKind::DirectorySymlink],
            $this->reasonsByPath($discovery->skippedEntries()),
        );
        self::assertCount(1, $discovery->skippedEntries());
    }

    /**
     * A regular file the process cannot read stays a unit of analysis: the
     * parser refuses it by name and the refusal reaches coverage as a parse
     * failure. Discovery inventing a second answer would give it two terminal
     * states.
     */
    #[Test]
    public function itYieldsAnUnreadableRegularFileRatherThanRecordingIt(): void
    {
        $this->requireUnprivilegedUser();
        file_put_contents($this->root . '/tree/Sealed.php', '<?php class Sealed {}');
        chmod($this->root . '/tree/Sealed.php', 0000);

        try {
            [$files, $skips] = $this->walk();
        } finally {
            chmod($this->root . '/tree/Sealed.php', 0644);
        }

        self::assertSame(['Kept.php', 'Sealed.php'], $files);
        self::assertSame([], $skips);
    }

    #[Test]
    public function itRecordsADanglingPhpSymlink(): void
    {
        symlink($this->root . '/gone/Missing.php', $this->root . '/tree/Dangling.php');

        [$files, $skips] = $this->walk();

        self::assertSame(['Kept.php'], $files);
        self::assertSame(
            [$this->root . '/tree/Dangling.php' => AnalysisFailureKind::NotRegularFile],
            $this->reasonsByPath($skips),
        );
    }

    #[Test]
    public function itRecordsANamedPipeNamedLikeSource(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('ext-posix is required to create a FIFO');
        }

        posix_mkfifo($this->root . '/tree/Pipe.php', 0644);

        [$files, $skips] = $this->walk();

        self::assertSame(['Kept.php'], $files);
        self::assertSame(
            [$this->root . '/tree/Pipe.php' => AnalysisFailureKind::NotRegularFile],
            $this->reasonsByPath($skips),
        );
    }

    #[Test]
    public function itRecordsAnUnreadableSubdirectoryAndStillReturnsItsSiblings(): void
    {
        $this->requireUnprivilegedUser();
        mkdir($this->root . '/tree/locked', 0755, true);
        file_put_contents($this->root . '/tree/locked/Locked.php', '<?php class Locked {}');
        chmod($this->root . '/tree/locked', 0000);

        [$files, $skips] = $this->walk();

        self::assertSame(['Kept.php'], $files);
        self::assertSame(
            [$this->root . '/tree/locked' => AnalysisFailureKind::UnreadableDirectory],
            $this->reasonsByPath($skips),
        );
    }

    #[Test]
    public function itRecordsAnUnreadableRootInsteadOfFailingTheRun(): void
    {
        $this->requireUnprivilegedUser();
        mkdir($this->root . '/sealed', 0755, true);
        file_put_contents($this->root . '/sealed/Sealed.php', '<?php class Sealed {}');
        chmod($this->root . '/sealed', 0000);

        $discovery = new FinderFileDiscovery(new DirectoryPruner(
            AbsolutePath::fromString($this->root),
            [],
        ));
        $files = iterator_to_array($discovery->discover([
            AbsolutePath::fromString($this->root . '/tree'),
            AbsolutePath::fromString($this->root . '/sealed'),
        ]), false);

        self::assertSame(
            ['Kept.php'],
            array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $files),
        );
        self::assertSame(
            [$this->root . '/sealed' => AnalysisFailureKind::UnreadableDirectory],
            $this->reasonsByPath($discovery->skippedEntries()),
        );
    }

    #[Test]
    public function itRecordsAnExplicitPathArgumentThatIsNotARegularFile(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('ext-posix is required to create a FIFO');
        }

        posix_mkfifo($this->root . '/Explicit.php', 0644);

        $discovery = $this->discovery();
        $files = iterator_to_array(
            $discovery->discover(AbsolutePath::fromString($this->root . '/Explicit.php')),
            false,
        );

        self::assertSame([], $files);
        self::assertSame(
            [$this->root . '/Explicit.php' => AnalysisFailureKind::NotRegularFile],
            $this->reasonsByPath($discovery->skippedEntries()),
        );
    }

    #[Test]
    public function itForgetsTheSkipsOfThePreviousDiscoverCall(): void
    {
        symlink($this->root . '/tree', $this->root . '/tree/self');

        $discovery = $this->discovery();
        iterator_to_array($discovery->discover(AbsolutePath::fromString($this->root . '/tree')), false);
        self::assertCount(1, $discovery->skippedEntries());

        unlink($this->root . '/tree/self');
        iterator_to_array($discovery->discover(AbsolutePath::fromString($this->root . '/tree')), false);

        self::assertSame([], $discovery->skippedEntries());
    }

    #[Test]
    public function itSaysNothingAboutAnExcludedDirectoryEvenWhenItIsALink(): void
    {
        mkdir($this->root . '/outside', 0755, true);
        symlink($this->root . '/outside', $this->root . '/tree/vendor');

        $discovery = new FinderFileDiscovery(new DirectoryPruner(
            AbsolutePath::fromString($this->root),
            DirectoryPruner::builtInPatterns(),
        ));
        $files = iterator_to_array(
            $discovery->discover(AbsolutePath::fromString($this->root . '/tree')),
            false,
        );

        self::assertSame(
            ['Kept.php'],
            array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $files),
        );
        self::assertSame([], $discovery->skippedEntries());
    }

    /** @return array{list<string>, list<SkippedEntry>} */
    private function walk(): array
    {
        $discovery = $this->discovery();
        $files = iterator_to_array(
            $discovery->discover(AbsolutePath::fromString($this->root . '/tree')),
            false,
        );

        $names = array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $files);
        sort($names);

        return [$names, $discovery->skippedEntries()];
    }

    private function discovery(): FinderFileDiscovery
    {
        return new FinderFileDiscovery(new DirectoryPruner(AbsolutePath::fromString($this->root), []));
    }

    /**
     * @param list<SkippedEntry> $skips
     *
     * @return array<string, AnalysisFailureKind>
     */
    private function reasonsByPath(array $skips): array
    {
        $reasons = [];
        foreach ($skips as $skip) {
            $reasons[$skip->path->value()] = $skip->reason;
            self::assertNotSame('', $skip->detail, 'A skip without a reason in words is half a record');
        }
        ksort($reasons);

        return $reasons;
    }

    private function requireUnprivilegedUser(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not refuse root');
        }
    }

    private function unlock(string $dir): void
    {
        foreach (['tree/locked', 'sealed'] as $relative) {
            if (is_dir($dir . '/' . $relative)) {
                chmod($dir . '/' . $relative, 0755);
            }
        }
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
