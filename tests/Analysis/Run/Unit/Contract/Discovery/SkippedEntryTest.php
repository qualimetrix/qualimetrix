<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Contract\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * The name a skipped entry publishes is the key baselines and suppressions are
 * indexed by, so it has to be the name the reader was pointed at. A symbolic
 * link resolved on the way out names its target instead — a different object,
 * which the same run may well have analysed under its own name.
 */
#[CoversClass(SkippedEntry::class)]
final class SkippedEntryTest extends TestCase
{
    private string $root;

    private string $projectRoot;

    protected function setUp(): void
    {
        $created = realpath(sys_get_temp_dir()) . '/qmx-skipped-' . bin2hex(random_bytes(6));
        mkdir($created . '/tree/real', 0o755, true);
        mkdir($created . '/project', 0o755, true);
        symlink($created . '/tree/real', $created . '/tree/linked');

        $this->root = $created;
        $this->projectRoot = $created . '/project';
    }

    protected function tearDown(): void
    {
        if (is_link($this->root . '/tree/linked')) {
            unlink($this->root . '/tree/linked');
        }
        @rmdir($this->root . '/tree/real');
        @rmdir($this->root . '/tree');
        @rmdir($this->root . '/project');
        @rmdir($this->root);
    }

    /**
     * The tree sits outside the project root, so the conversion falls back —
     * and the fallback canonicalizes whatever path it is handed.
     */
    #[Test]
    public function itKeepsTheLinksOwnNameWhenTheTreeIsOutsideTheProjectRoot(): void
    {
        self::assertSame(
            $this->outOfRoot('linked'),
            $this->relative($this->root . '/tree/linked', $this->projectRoot),
        );
    }

    /** The branch that already held, kept honest against the same fixture. */
    #[Test]
    public function itKeepsTheLinksOwnNameWhenTheTreeIsInsideTheProjectRoot(): void
    {
        self::assertSame(
            'tree/linked',
            $this->relative($this->root . '/tree/linked', $this->root),
        );
    }

    /**
     * The structure-preserving fallback is what keeps two out-of-root files of
     * the same basename apart, and protecting the last segment must not cost
     * that.
     */
    #[Test]
    public function itStillPreservesTheDirectoryStructureOfAnOutOfRootEntry(): void
    {
        self::assertSame(
            $this->outOfRoot('real'),
            $this->relative($this->root . '/tree/real', $this->projectRoot),
        );
    }

    /** A path whose parent is the filesystem root has no structure to keep. */
    #[Test]
    public function itNamesAnEntryDirectlyUnderTheFilesystemRootByItsOwnName(): void
    {
        self::assertSame('nonexistent-qmx-entry', $this->relative('/nonexistent-qmx-entry', $this->projectRoot));
    }

    /**
     * What the structure-preserving fallback spells for an entry of the tree:
     * the whole canonical path without its leading slash.
     */
    private function outOfRoot(string $name): string
    {
        $tree = realpath($this->root . '/tree');
        self::assertIsString($tree);

        return ltrim($tree, '/') . '/' . $name;
    }

    private function relative(string $entry, string $projectRoot): string
    {
        return (new SkippedEntry(
            AbsolutePath::fromString($entry),
            AnalysisFailureKind::DirectorySymlink,
            'Symbolic link to a directory is not traversed',
        ))->relativeTo(AbsolutePath::fromString($projectRoot))->value();
    }
}
