<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

/**
 * The seam of ADR 0017: paths in, the set a baseline measures out, with no
 * `InputInterface` anywhere — which is what lets a command that does not
 * declare `check`'s options measure exactly what `check` measures.
 */
#[CoversClass(MeasuredFindingSet::class)]
final class MeasuredFindingSetTest extends TestCase
{
    #[Test]
    public function itLeavesOutWhatTheSourcesOwnIgnoreTagsRemoved(): void
    {
        $ignored = self::finding('src/Legacy/Service.php', 'App\\Legacy', 'Service');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $set = $this->createSet(
            [$ignored, $reported],
            new FindingProjectionOptions(),
            [
                'src/Legacy/Service.php' => [
                    new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File),
                ],
            ],
        );

        self::assertSame([$reported], $set->forRun($this->configuration()));
    }

    #[Test]
    public function itLeavesOutWhatTheConfiguredExclusionsRemoved(): void
    {
        $excludedByPath = self::finding('generated/Proxy.php', 'App\\Generated', 'Proxy');
        $excludedByNamespace = self::finding('src/Vendor/Thing.php', 'App\\Vendor', 'Thing');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $options = new FindingProjectionOptions(
            excludePaths: ['generated'],
            excludeNamespaces: ['App\\Vendor'],
        );
        $set = $this->createSet(
            [$excludedByPath, $excludedByNamespace, $reported],
            $options,
        );

        self::assertSame([$reported], $set->forRun(
            $this->configuration(),
            options: $options,
        ));
    }

    /**
     * The set is what configuration and the source say, and nothing else.
     * A narrowing that exists only as a `check` flag is not part of it —
     * otherwise every baseline command would have to replicate `check`'s
     * option surface to agree with it, and the four ADR 0017 commands accept none
     * of those flags.
     */
    #[Test]
    public function itDefinesTheSetFromConfigurationAloneAndNotFromCheckFlags(): void
    {
        $onlyExcludedByAFlag = self::finding('vendor/library/SomeClass.php', 'App\\Vendor', 'SomeClass');

        $set = $this->createSet([$onlyExcludedByAFlag], new FindingProjectionOptions());

        self::assertSame(
            [$onlyExcludedByAFlag],
            $set->forRun($this->configuration()),
        );

        // The same narrowing, supplied as a flag, does remove it from the
        // run — it just does not redefine what the baseline measures.
        self::assertSame([], $set->forRun(
            $this->configuration(),
            options: new FindingProjectionOptions(excludePaths: ['vendor']),
        ));
    }

    #[Test]
    public function itListsOnlyStagesThatDefineTheMeasuredSet(): void
    {
        $set = $this->createSet([], new FindingProjectionOptions(
            excludePaths: ['generated'],
            excludeNamespaces: ['App\\Vendor'],
        ));

        foreach ([FindingFilterStage::Suppression, FindingFilterStage::PathExclusion, FindingFilterStage::NamespaceExclusion] as $stage) {
            self::assertTrue($stage->definesMeasuredSet());
        }
    }

    /**
     * `run()` must return the same measured set `forRun()` does —
     * the two are not two definitions, one is the other with the run kept —
     * and must expose the {@see AnalysisResult} the run itself produced,
     * not a rebuilt or partial one.
     */
    #[Test]
    public function itReturnsTheRunAlongsideTheSameMeasuredSetForPathsReturns(): void
    {
        $ignored = self::finding('src/Legacy/Service.php', 'App\\Legacy', 'Service');
        $reported = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');

        $set = $this->createSet(
            [$ignored, $reported],
            new FindingProjectionOptions(),
            [
                'src/Legacy/Service.php' => [
                    new Suppression(rule: '*', reason: 'Reviewed', line: 1, type: SuppressionType::File),
                ],
            ],
        );

        $run = $set->run($this->configuration());

        self::assertSame([$reported], $run->findings);
        self::assertSame([$ignored, $reported], $run->result->findings);
    }

    #[Test]
    public function itBuildsDefaultDiscoveryFromTheRunPathExcludes(): void
    {
        $root = AbsolutePath::fromString(sys_get_temp_dir());
        $configuration = new RunConfiguration(
            [$root],
            ['vendor', 'node_modules', '.git', 'generated'],
            $root,
            GeneratedFilePolicy::Exclude,
        );
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $factory = self::createMock(FileDiscoveryFactoryInterface::class);
        $factory->expects(self::once())
            ->method('create')
            ->with($configuration->pathExcludes)
            ->willReturn($discovery);
        $analyzer = self::createMock(AnalysisPipelineInterface::class);
        $analyzer->expects(self::once())
            ->method('analyze')
            ->with($configuration, $discovery)
            ->willReturn(self::analysisResult([]));

        $this->createSet([], new FindingProjectionOptions(), analyzer: $analyzer, discoveryFactory: $factory)
            ->run($configuration);
    }

    #[Test]
    public function itUsesExplicitDiscoveryWithoutCallingTheFactory(): void
    {
        $configuration = $this->configuration();
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $factory = self::createMock(FileDiscoveryFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $analyzer = self::createMock(AnalysisPipelineInterface::class);
        $analyzer->expects(self::once())
            ->method('analyze')
            ->with($configuration, $discovery)
            ->willReturn(self::analysisResult([]));

        $this->createSet([], new FindingProjectionOptions(), analyzer: $analyzer, discoveryFactory: $factory)
            ->run($configuration, $discovery);
    }

    /**
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions
     */
    private function createSet(
        array $findings,
        FindingProjectionOptions $configuration,
        array $suppressions = [],
        ?AnalysisPipelineInterface $analyzer = null,
        ?FileDiscoveryFactoryInterface $discoveryFactory = null,
    ): MeasuredFindingSet {
        if ($analyzer === null) {
            $analyzer = self::createStub(AnalysisPipelineInterface::class);
            $analyzer->method('analyze')->willReturn(self::analysisResult($findings, $suppressions));
        }
        if ($discoveryFactory === null) {
            $discoveryFactory = self::createStub(FileDiscoveryFactoryInterface::class);
            $discoveryFactory->method('create')->willReturn(self::createStub(FileDiscoveryInterface::class));
        }

        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $projector = new FindingProjector(
            new SuppressionFilter(),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new class implements GitScopeQueryInterface {
                public function resolve(GitScopeRequest $request): GitScopeResult
                {
                    return new GitScopeResult([], []);
                }
            },
        );

        return new MeasuredFindingSet($analyzer, $projector, $discoveryFactory);
    }

    /**
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions
     */
    private static function analysisResult(array $findings, array $suppressions = []): AnalysisResult
    {
        return new AnalysisResult(
            findings: $findings,
            duration: 0.1,
            metrics: self::createStub(MetricRepositoryInterface::class),
            coverage: new AnalysisCoverage([RelativePath::fromString('Fixture.php')], [], []),
            suppressions: $suppressions,
        );
    }

    private function configuration(): RunConfiguration
    {
        $root = AbsolutePath::fromString(sys_get_temp_dir());

        return new RunConfiguration([$root], [], $root, GeneratedFilePolicy::Exclude);
    }

    private static function finding(string $file, string $namespace, string $class): Finding
    {
        $path = RelativePath::fromString($file);
        $symbol = SymbolPath::forClass($namespace, $class);

        return new Finding(
            location: new Location($path, 10),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $path, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'CCN too high',
            severity: Severity::Warning,
            metricValue: 25,
        );
    }
}
