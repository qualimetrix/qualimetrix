<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Discovery\AnalysisFileDiscovery;
use Qualimetrix\Analysis\Run\Discovery\DiscoveredAnalysisFiles;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeBindingProbe;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use Qualimetrix\Core\Path\AbsolutePath;
use SplFileInfo;

#[CoversClass(AnalysisFileDiscovery::class)]
#[CoversClass(DiscoveredAnalysisFiles::class)]
#[CoversClass(GeneratedFilePolicy::class)]
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
        $files = glob($this->root . '/src/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->root . '/src');
        rmdir($this->root);
    }

    #[Test]
    public function itUsesTheDefaultDiscoveryWhenNoOverrideIsProvided(): void
    {
        $file = new SplFileInfo($this->root . '/src/A.php');
        $default = $this->createMock(FileDiscoveryInterface::class);
        $default->expects(self::once())->method('discover')->willReturn(new ArrayIterator([$file]));

        $result = $this->discovery($default)->discover(
            $this->configuration([$this->root . '/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$file], $result->eligibleFiles);
        self::assertSame(1, $result->discoveredCount);
    }

    #[Test]
    public function itUsesTheExplicitOverrideWithoutCallingTheDefaultDiscovery(): void
    {
        $default = $this->createMock(FileDiscoveryInterface::class);
        $default->expects(self::never())->method('discover');
        $override = $this->createMock(FileDiscoveryInterface::class);
        $override->expects(self::once())->method('discover')->willReturn(new ArrayIterator([]));

        $this->discovery($default)->discover(
            $this->configuration([$this->root . '/src'], GeneratedFilePolicy::Include),
            $override,
        );
    }

    #[Test]
    public function itDeduplicatesOverlappingRootsByProjectRelativePath(): void
    {
        $first = new SplFileInfo($this->root . '/src/A.php');
        $duplicate = new SplFileInfo($this->root . '/src/../src/A.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$first, $duplicate]));

        $result = $this->discovery($default)->discover(
            $this->configuration([$this->root, $this->root . '/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$first], $result->eligibleFiles);
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

        $files = array_map(static fn(string $name): SplFileInfo => new SplFileInfo($name), [
            $target,
            $this->root . '/src/First.php',
            $this->root . '/src/Second.php',
            $this->root . '/src/Hard.php',
        ]);
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator($files));

        $result = $this->discovery($default)->discover(
            $this->configuration([$this->root . '/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$files[0], $files[3]], $result->eligibleFiles);
        self::assertSame(2, $result->discoveredCount);
    }

    #[Test]
    public function itPrefersTheRegularTargetEvenWhenANameLinkIsDiscoveredFirst(): void
    {
        $target = $this->root . '/src/Target.php';
        file_put_contents($target, '<?php');
        symlink($target, $this->root . '/src/First.php');
        symlink($target, $this->root . '/src/Second.php');
        link($target, $this->root . '/src/Hard.php');

        $first = new SplFileInfo($this->root . '/src/First.php');
        $hard = new SplFileInfo($this->root . '/src/Hard.php');
        $regular = new SplFileInfo($target);
        $second = new SplFileInfo($this->root . '/src/Second.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$first, $hard, $regular, $second]));

        $result = $this->discovery($default)->discover(
            $this->configuration([$this->root . '/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$hard, $regular], $result->eligibleFiles);
        self::assertSame(2, $result->discoveredCount);
    }

    #[Test]
    public function itKeepsTheFirstNamedLinkWhenTheRegularTargetIsNotSelected(): void
    {
        $target = $this->root . '/src/Target.php';
        file_put_contents($target, '<?php');
        symlink($target, $this->root . '/src/First.php');
        symlink($target, $this->root . '/src/Second.php');
        link($target, $this->root . '/src/Hard.php');

        $first = new SplFileInfo($this->root . '/src/First.php');
        $second = new SplFileInfo($this->root . '/src/Second.php');
        $hard = new SplFileInfo($this->root . '/src/Hard.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$second, $first, $hard]));

        $result = $this->discovery($default)->discover(
            $this->configuration([$this->root . '/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$second, $hard], $result->eligibleFiles);
        self::assertSame(2, $result->discoveredCount);
    }

    #[Test]
    public function itDoesNotHideUnresolvedNamedLinks(): void
    {
        symlink($this->root . '/src/Missing.php', $this->root . '/src/First.php');
        symlink($this->root . '/src/Missing.php', $this->root . '/src/Second.php');
        $first = new SplFileInfo($this->root . '/src/First.php');
        $second = new SplFileInfo($this->root . '/src/Second.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$first, $second]));

        $result = $this->discovery($default)->discover(
            $this->configuration([$this->root . '/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$first, $second], $result->eligibleFiles);
        self::assertSame(2, $result->discoveredCount);
    }

    #[Test]
    public function itKeepsGeneratedFilesAsExplicitExcludedTerminalStates(): void
    {
        $eligible = new SplFileInfo($this->root . '/src/A.php');
        $generated = new SplFileInfo($this->root . '/src/Generated.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$eligible, $generated]));
        $filter = self::createStub(GeneratedFileFilterInterface::class);
        $filter->method('filter')->willReturn([$eligible]);

        $result = (new AnalysisFileDiscovery($default, $filter, self::audit()))->discover(
            $this->configuration([$this->root . '/src'], GeneratedFilePolicy::Exclude),
        );

        self::assertSame([$eligible], $result->eligibleFiles);
        self::assertSame(['src/Generated.php'], array_map(static fn($path): string => $path->value(), $result->generatedExcludedFiles));
        self::assertSame(2, $result->discoveredCount);
    }

    #[Test]
    public function itIncludesGeneratedFilesWithoutAllocatingExcludedStates(): void
    {
        $file = new SplFileInfo($this->root . '/src/Generated.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$file]));
        $filter = $this->createMock(GeneratedFileFilterInterface::class);
        $filter->expects(self::never())->method('filter');

        $result = (new AnalysisFileDiscovery($default, $filter, self::audit()))->discover(
            $this->configuration([$this->root . '/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$file], $result->eligibleFiles);
        self::assertSame([], $result->generatedExcludedFiles);
    }

    #[Test]
    public function itSelectsEligibleFilesWithoutReadingFindingOptions(): void
    {
        $file = new SplFileInfo($this->root . '/src/A.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$file]));
        $filter = self::createStub(GeneratedFileFilterInterface::class);
        $filter->method('filter')->willReturn([$file]);
        $options = $this->createMock(RuleOptionsInterface::class);
        $options->expects(self::never())->method('isEnabled');

        $result = (new AnalysisFileDiscovery(
            $default,
            $filter,
            new UnmatchedExcludeAudit($options, new ExcludeBindingProbe()),
        ))->discoverEligible($this->configuration([$this->root . '/src'], GeneratedFilePolicy::Exclude));

        self::assertSame([$file], $result->eligibleFiles);
        self::assertSame(1, $result->discoveredCount);
        self::assertSame([], $result->unmatchedExcludeFindings);
    }

    private function discovery(FileDiscoveryInterface $default): AnalysisFileDiscovery
    {
        $filter = self::createStub(GeneratedFileFilterInterface::class);
        $filter->method('filter')->willReturnCallback(static fn(array $files): array => $files);

        return new AnalysisFileDiscovery($default, $filter, self::audit());
    }

    /** @param list<string> $paths */
    private function configuration(array $paths, GeneratedFilePolicy $policy): RunConfiguration
    {
        return new RunConfiguration(
            pathExcludes: [],
            projectRoot: AbsolutePath::fromString($this->root),
            generatedFilePolicy: $policy,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: AbsolutePath::fromString($this->root), pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: array_map(AbsolutePath::fromString(...), $paths), scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );
    }

    private static function audit(): UnmatchedExcludeAudit
    {
        return new UnmatchedExcludeAudit(new UnmatchedExcludeOptions(), new ExcludeBindingProbe());
    }
}
