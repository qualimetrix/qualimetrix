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
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\EntryInspectorInterface;
use Qualimetrix\Analysis\Run\Discovery\EntryKind;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Analysis\Run\Discovery\WalkedProject;
use Qualimetrix\Analysis\Run\Discovery\WalkRequest;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ProjectWalk::class)]
final class DirectoryWalkTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-directory-walk-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/tree/blocked', 0755, true);
        mkdir($this->root . '/tree/open', 0755, true);
        file_put_contents($this->root . '/tree/blocked/Hidden.php', '<?php');
        file_put_contents($this->root . '/tree/open/Kept.php', '<?php');
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function itHandsBackTheBranchItCouldNotEnter(): void
    {
        $walked = $this->walk(true);

        self::assertCount(1, $walked->skipped);
        self::assertSame($this->root . '/tree/blocked', $walked->skipped[0]->path->value());
        self::assertSame(AnalysisFailureKind::UnreadableDirectory, $walked->skipped[0]->reason);
        self::assertSame('Entry cannot be inspected or listed', $walked->skipped[0]->detail);
    }

    #[Test]
    public function itKeepsWalkingTheSiblingsOfTheBranchItCouldNotEnter(): void
    {
        $walked = $this->walk(true);

        self::assertSame([$this->root . '/tree/open/Kept.php'], array_map(
            static fn(SplFileInfo $file): string => $file->getPathname(),
            $walked->candidates,
        ));
        self::assertSame(AnalysisFailureKind::UnreadableDirectory, $walked->skipped[0]->reason);
    }

    #[Test]
    public function itStaysSilentWhenEveryBranchOpens(): void
    {
        $walked = $this->walk(false);
        $paths = array_map(static fn(SplFileInfo $file): string => $file->getPathname(), $walked->candidates);
        sort($paths);

        self::assertSame([
            $this->root . '/tree/blocked/Hidden.php',
            $this->root . '/tree/open/Kept.php',
        ], $paths);
        self::assertSame([], $walked->skipped);
    }

    private function walk(bool $refuseBlocked): WalkedProject
    {
        $inspector = new class ($this->root . '/tree/blocked', $refuseBlocked) implements EntryInspectorInterface {
            private EntryInspector $delegate;

            public function __construct(private readonly string $blocked, private readonly bool $refuseBlocked)
            {
                $this->delegate = new EntryInspector();
            }

            public function inspect(string $path): EntryKind
            {
                return $this->delegate->inspect($path);
            }

            public function list(string $directory): ?array
            {
                return $this->refuseBlocked && $directory === $this->blocked ? null : $this->delegate->list($directory);
            }
        };
        $root = AbsolutePath::fromString($this->root);
        $path = AbsolutePath::fromString($this->root . '/tree');
        $universe = new ProjectScopeUniverse($root, true, [], [], [], true, []);
        $run = new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new ProjectScopeMeasurement($universe, [$path], ProjectScopeState::Covered, []),
            authoredPathExcludes: [],
            autoloadDevPolicy: AutoloadDevPolicy::Exclude,
        );

        return (new ProjectWalk($inspector))->walk(new WalkRequest($run));
    }
}
