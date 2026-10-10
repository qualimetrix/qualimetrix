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
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Run\Discovery\ProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ProjectFiles::class)]
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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function itClassifiesANamedDirectoryBeforeCandidateSelection(): void
    {
        $result = $this->discover([$this->root . '/src/A.php', $this->root . '/src/subdir']);

        self::assertSame([$this->root . '/src/A.php'], array_map(
            static fn(SplFileInfo $file): string => $file->getPathname(),
            $result->eligibleFiles,
        ));
        self::assertSame(1, $result->discoveredCount);
        self::assertSame([], $result->skippedEntries);
    }

    #[Test]
    public function itCarriesTheWalksOwnSkipsForward(): void
    {
        symlink($this->root . '/src/subdir', $this->root . '/src/linked');
        $result = $this->discover([$this->root . '/src']);

        self::assertSame(['src/linked' => AnalysisFailureKind::DirectorySymlink], $this->reasons($result));
    }

    #[Test]
    public function itRecordsOneTerminalStatePerPathWhenOverlappingRootsSeeTheSameEntry(): void
    {
        symlink($this->root . '/src/subdir', $this->root . '/src/linked');
        $result = $this->discover([$this->root . '/src', $this->root . '/src/linked']);

        self::assertCount(1, $result->skippedEntries);
        self::assertSame(['src/linked' => AnalysisFailureKind::DirectorySymlink], $this->reasons($result));
    }

    /** @param list<string> $paths */
    private function discover(array $paths): DiscoveredProjectFiles
    {
        $root = AbsolutePath::fromString($this->root);
        $absolutePaths = array_map(AbsolutePath::fromString(...), $paths);
        $universe = new ProjectScopeUniverse($root, true, [], [], [], true, []);
        $run = new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new ProjectScopeMeasurement($universe, $absolutePaths, ProjectScopeState::Covered, []),
            authoredPathExcludes: [],
            autoloadDevPolicy: AutoloadDevPolicy::Exclude,
        );

        return (new ProjectFiles(new ProjectWalk(new EntryInspector()), new GeneratedFileFilter()))->discover($run);
    }

    /** @return array<string, AnalysisFailureKind> */
    private function reasons(DiscoveredProjectFiles $result): array
    {
        $reasons = [];
        foreach ($result->skippedEntries as $skip) {
            $reasons[str_replace($this->root . '/', '', $skip->path->value())] = $skip->reason;
        }
        ksort($reasons);

        return $reasons;
    }
}
