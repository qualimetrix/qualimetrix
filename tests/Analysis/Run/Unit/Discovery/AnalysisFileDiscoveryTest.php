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
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Run\Discovery\ProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ProjectFiles::class)]
final class AnalysisFileDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function itUsesCapturedRunPathsForSelection(): void
    {
        file_put_contents($this->root . '/src/A.php', '<?php');
        file_put_contents($this->root . '/Other.php', '<?php');
        $result = $this->discover([$this->root . '/src'], GeneratedFilePolicy::Include);

        self::assertSame(['A.php'], $this->names($result));
        self::assertSame(1, $result->discoveredCount);
    }

    #[Test]
    public function itDeduplicatesOverlappingRootsByProjectRelativePath(): void
    {
        file_put_contents($this->root . '/src/A.php', '<?php');
        $result = $this->discover([$this->root, $this->root . '/src'], GeneratedFilePolicy::Include);

        self::assertSame(['A.php'], $this->names($result));
        self::assertSame(1, $result->discoveredCount);
    }

    #[Test]
    public function itPrefersTheSelectedRegularTargetToNamedLinksAndKeepsAHardlink(): void
    {
        $target = $this->root . '/src/Target.php';
        file_put_contents($target, '<?php');
        symlink($target, $this->root . '/src/First.php');
        symlink($target, $this->root . '/src/Second.php');
        link($target, $this->root . '/src/Hard.php');

        $result = $this->discover([$this->root . '/src'], GeneratedFilePolicy::Include);
        self::assertSame(['Hard.php', 'Target.php'], $this->names($result));
        self::assertSame(2, $result->discoveredCount);
    }

    #[Test]
    public function itKeepsGeneratedFilesAsExplicitExcludedTerminalStates(): void
    {
        file_put_contents($this->root . '/src/A.php', '<?php');
        file_put_contents($this->root . '/src/Generated.php', "<?php\n// @generated\n");
        $result = $this->discover([$this->root . '/src'], GeneratedFilePolicy::Exclude);

        self::assertSame(['A.php'], $this->names($result));
        self::assertSame(['src/Generated.php'], array_map(static fn($path): string => $path->value(), $result->generatedExcludedFiles));
        self::assertSame(2, $result->discoveredCount);
    }

    #[Test]
    public function itIncludesGeneratedFilesWithoutAllocatingExcludedStates(): void
    {
        file_put_contents($this->root . '/src/Generated.php', "<?php\n// @generated\n");
        $filter = $this->createMock(GeneratedFileFilterInterface::class);
        $filter->expects(self::never())->method('isGenerated');
        $result = $this->discover([$this->root . '/src'], GeneratedFilePolicy::Include, $filter);

        self::assertSame(['Generated.php'], $this->names($result));
        self::assertSame([], $result->generatedExcludedFiles);
    }

    #[Test]
    public function itSelectsEligibleFilesWithoutReadingFindingOptions(): void
    {
        file_put_contents($this->root . '/src/A.php', '<?php');
        $result = $this->discover([$this->root . '/src'], GeneratedFilePolicy::Exclude);

        self::assertSame(['A.php'], $this->names($result));
        self::assertSame(1, $result->discoveredCount);
        self::assertSame([], $result->selectorVerdicts);
    }

    /** @param list<string> $paths */
    private function discover(array $paths, GeneratedFilePolicy $policy, ?GeneratedFileFilterInterface $filter = null): DiscoveredProjectFiles
    {
        $root = AbsolutePath::fromString($this->root);
        $absolutePaths = array_map(AbsolutePath::fromString(...), $paths);
        $universe = new ProjectScopeUniverse($root, true, [], [], [], true, []);
        $run = new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: $policy,
            projectScope: new ProjectScopeMeasurement($universe, $absolutePaths, ProjectScopeState::Covered, []),
            authoredPathExcludes: [],
            autoloadDevPolicy: AutoloadDevPolicy::Exclude,
        );

        return (new ProjectFiles(new ProjectWalk(new EntryInspector()), $filter ?? new GeneratedFileFilter()))->discover($run);
    }

    /** @return list<string> */
    private function names(DiscoveredProjectFiles $result): array
    {
        return array_map(static fn(SplFileInfo $file): string => $file->getFilename(), $result->eligibleFiles);
    }
}
