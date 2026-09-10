<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Discovery;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    #[Test]
    public function itUsesTheDefaultDiscoveryWhenNoOverrideIsProvided(): void
    {
        $file = new SplFileInfo('/project/src/A.php');
        $default = $this->createMock(FileDiscoveryInterface::class);
        $default->expects(self::once())->method('discover')->willReturn(new ArrayIterator([$file]));

        $result = $this->discovery($default)->discover(
            self::configuration(['/project/src'], GeneratedFilePolicy::Include),
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
            self::configuration(['/project/src'], GeneratedFilePolicy::Include),
            $override,
        );
    }

    #[Test]
    public function itDeduplicatesOverlappingRootsByProjectRelativePath(): void
    {
        $first = new SplFileInfo('/project/src/A.php');
        $duplicate = new SplFileInfo('/project/src/../src/A.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$first, $duplicate]));

        $result = $this->discovery($default)->discover(
            self::configuration(['/project', '/project/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$first], $result->eligibleFiles);
        self::assertSame(1, $result->discoveredCount);
    }

    #[Test]
    public function itKeepsGeneratedFilesAsExplicitExcludedTerminalStates(): void
    {
        $eligible = new SplFileInfo('/project/src/A.php');
        $generated = new SplFileInfo('/project/src/Generated.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$eligible, $generated]));
        $filter = self::createStub(GeneratedFileFilterInterface::class);
        $filter->method('filter')->willReturn([$eligible]);

        $result = (new AnalysisFileDiscovery($default, $filter, self::audit()))->discover(
            self::configuration(['/project/src'], GeneratedFilePolicy::Exclude),
        );

        self::assertSame([$eligible], $result->eligibleFiles);
        self::assertSame(['src/Generated.php'], array_map(static fn($path): string => $path->value(), $result->generatedExcludedFiles));
        self::assertSame(2, $result->discoveredCount);
    }

    #[Test]
    public function itIncludesGeneratedFilesWithoutAllocatingExcludedStates(): void
    {
        $file = new SplFileInfo('/project/src/Generated.php');
        $default = self::createStub(FileDiscoveryInterface::class);
        $default->method('discover')->willReturn(new ArrayIterator([$file]));
        $filter = $this->createMock(GeneratedFileFilterInterface::class);
        $filter->expects(self::never())->method('filter');

        $result = (new AnalysisFileDiscovery($default, $filter, self::audit()))->discover(
            self::configuration(['/project/src'], GeneratedFilePolicy::Include),
        );

        self::assertSame([$file], $result->eligibleFiles);
        self::assertSame([], $result->generatedExcludedFiles);
    }

    private function discovery(FileDiscoveryInterface $default): AnalysisFileDiscovery
    {
        $filter = self::createStub(GeneratedFileFilterInterface::class);
        $filter->method('filter')->willReturnCallback(static fn(array $files): array => $files);

        return new AnalysisFileDiscovery($default, $filter, self::audit());
    }

    /** @param list<string> $paths */
    private static function configuration(array $paths, GeneratedFilePolicy $policy): RunConfiguration
    {
        return new RunConfiguration(
            paths: array_map(AbsolutePath::fromString(...), $paths),
            pathExcludes: [],
            projectRoot: AbsolutePath::fromString('/project'),
            generatedFilePolicy: $policy,
            coversProjectScope: true,
            authoredPathExcludes: [],
        );
    }

    private static function audit(): UnmatchedExcludeAudit
    {
        return new UnmatchedExcludeAudit(new UnmatchedExcludeOptions(), new ExcludeBindingProbe());
    }
}
