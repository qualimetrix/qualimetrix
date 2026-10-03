<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\EntryInspectorInterface;
use Qualimetrix\Analysis\Run\Discovery\EntryKind;
use Qualimetrix\Analysis\Run\Discovery\ProjectTree;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(ProjectTree::class)]
final class ProjectTreeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-project-tree-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Excluded', 0777, true);
        file_put_contents($this->root . '/src/A.php', '<?php');
        file_put_contents($this->root . '/src/Excluded/B.php', '<?php');
    }

    protected function tearDown(): void
    {
        if (is_link($this->root . '/src/Alias')) {
            unlink($this->root . '/src/Alias');
        }
        unlink($this->root . '/src/Excluded/B.php');
        unlink($this->root . '/src/A.php');
        rmdir($this->root . '/src/Excluded');
        rmdir($this->root . '/src');
        rmdir($this->root);
    }

    #[Test]
    public function itListsPhpBelowAuthoredExcludedDirectoriesOnDemand(): void
    {
        $snapshot = (new ProjectTree(new EntryInspector()))->snapshot($this->universe());

        self::assertSame(['src/A.php', 'src/Excluded/B.php'], array_map(
            static fn(RelativePath $path): string => $path->value(),
            $snapshot->phpFiles,
        ));
        self::assertTrue($snapshot->complete());
    }

    #[Test]
    public function itReportsInaccessibleMetadataAsIncompleteAndUnknown(): void
    {
        $inspector = new class implements EntryInspectorInterface {
            private EntryInspector $delegate;

            public function __construct()
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return str_ends_with($path, '/Excluded/B.php') ? EntryKind::StatFailed : $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                return str_ends_with($directory, '/Excluded') ? null : $this->delegate->list($directory);
            }
        };
        $tree = new ProjectTree($inspector);
        $snapshot = $tree->snapshot($this->universe());

        self::assertFalse($snapshot->complete());
        self::assertSame(['src/Excluded'], array_map(
            static fn(RelativePath $path): string => $path->value(),
            $snapshot->inaccessibleEntries,
        ));
        self::assertSame(ProjectEntryPresence::Unknown, $tree->hasFile(
            AbsolutePath::fromString($this->root),
            RelativePath::fromString('src/Excluded/B.php'),
        ));
    }

    #[Test]
    public function itDoesNotCallAnUnknownEntryAbsent(): void
    {
        $tree = new ProjectTree(new EntryInspector());
        $root = AbsolutePath::fromString($this->root);

        self::assertSame(ProjectEntryPresence::Present, $tree->hasFile($root, RelativePath::fromString('src/A.php')));
        self::assertSame(ProjectEntryPresence::Absent, $tree->hasFile($root, RelativePath::fromString('src/Missing.php')));
        self::assertFalse((new ProjectTree(new EntryInspector()))->snapshot(new ProjectScopeUniverse(
            $root,
            true,
            [],
            [],
            [],
            true,
            [],
        ))->complete());
    }

    #[Test]
    public function itDoesNotFollowAWalkedDirectoryLinkDuringSnapshot(): void
    {
        symlink($this->root . '/src/Excluded', $this->root . '/src/Alias');

        $inspector = new class implements EntryInspectorInterface {
            /** @var list<string> */
            public array $listed = [];
            private EntryInspector $delegate;

            public function __construct()
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                $this->listed[] = $directory;

                return $this->delegate->list($directory);
            }
        };

        $snapshot = (new ProjectTree($inspector))->snapshot($this->universe());

        self::assertSame(['src/A.php', 'src/Excluded/B.php'], array_map(
            static fn(RelativePath $path): string => $path->value(),
            $snapshot->phpFiles,
        ));
        self::assertTrue($snapshot->complete());
        self::assertNotContains($this->root . '/src/Alias', $inspector->listed);
    }

    private function universe(): ProjectScopeUniverse
    {
        $root = AbsolutePath::fromString($this->root);

        return new ProjectScopeUniverse($root, true, [
            ['target' => 'src', 'path' => AbsolutePath::fromString($this->root . '/src')],
        ], [], [], true, []);
    }
}
