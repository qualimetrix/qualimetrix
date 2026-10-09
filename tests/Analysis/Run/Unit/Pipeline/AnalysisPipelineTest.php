<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuild;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\UnmatchedTypeWarningInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Discovery\ScopeFacts;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\InlineDirectiveRun;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use Qualimetrix\Tests\TestSupport\Logging\Support\RecordingLogger;
use SplFileInfo;

#[CoversClass(AnalysisPipeline::class)]
#[CoversClass(InlineDirectiveRun::class)]
final class AnalysisPipelineTest extends TestCase
{
    #[Test]
    public function itTransportsNonfailureSummaryOnlyThroughNormalAnalysis(): void
    {
        $root = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $file = new SplFileInfo(__FILE__);
        $relative = PathFactory::published(AbsolutePath::fromString(__FILE__), $root);
        $discovery = self::createStub(ProjectFilesInterface::class);
        $discovery->method('discover')->willReturn(self::discovered([$file]));
        $collection = self::createStub(CollectionOrchestratorInterface::class);
        $collection->method('collect')->willReturn(new CollectionPhaseOutput([$relative], [], classLikeDeclarations: []));
        $definitions = new ResolvedComputedMetricDefinitions([
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition('computed.null', ['project' => 'null'], '', [\Qualimetrix\Core\Symbol\SymbolLevel::Project]),
        ]);
        $configuration = new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
        );
        $normal = $this->pipeline($discovery, $collection, computedDefinitions: $definitions)->analyze($configuration);
        self::assertSame(1, $normal->computedMetricEvaluation->absences[0]->noValueCount);
        self::assertSame('computed.null', $normal->computedMetricEvaluation->absences[0]->metricName);
        self::assertSame([], $normal->findings());
        $audit = $this->pipeline($discovery, $collection, computedDefinitions: $definitions)->auditDirectives($configuration);
        self::assertSame([], $audit->verdicts);
        self::assertSame(0, $audit->producedFindings);
        self::assertSame([$relative], $audit->coverage->analyzedFiles);
    }

    #[Test]
    public function itRefusesRuntimeFormulaFailureDuringSharedAuditPreparation(): void
    {
        $root = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $file = new SplFileInfo(__FILE__);
        $discovery = self::createStub(ProjectFilesInterface::class);
        $discovery->method('discover')->willReturn(self::discovered([$file]));
        $collection = self::createStub(CollectionOrchestratorInterface::class);
        $collection->method('collect')->willReturn(new CollectionPhaseOutput([PathFactory::published(AbsolutePath::fromString(__FILE__), $root)], [], classLikeDeclarations: []));
        $definitions = new ResolvedComputedMetricDefinitions([
            new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition('computed.bad', ['project' => '1 / 0'], '', [\Qualimetrix\Core\Symbol\SymbolLevel::Project]),
        ]);
        self::expectException(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal::class);
        self::expectExceptionMessage('Computed metric "computed.bad" failed at level "project"');
        $this->pipeline($discovery, $collection, computedDefinitions: $definitions)->auditDirectives(new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
        ));
    }

    #[Test]
    public function itRunsWithAnExplicitOwnerConfigurationAndReturnsMeasuredCoverage(): void
    {
        $root = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $file = new SplFileInfo(__FILE__);
        $relative = PathFactory::published(AbsolutePath::fromString(__FILE__), $root);

        $discovery = self::createStub(ProjectFilesInterface::class);
        $discovery->method('discover')->willReturn(self::discovered([$file]));

        $collection = $this->createMock(CollectionOrchestratorInterface::class);
        $collection->expects(self::once())->method('collect')
            ->with([$file], self::isInstanceOf(MetricRepositoryInterface::class), $root)
            ->willReturn(new CollectionPhaseOutput([$relative], [], classLikeDeclarations: []));

        $pipeline = $this->pipeline($discovery, $collection);
        $configuration = new RunConfiguration(
            pathExcludes: [new PathPattern(new SelectorDefinition(SelectorKind::Subtree, 'vendor'))],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );

        $result = $pipeline->analyze($configuration);

        self::assertSame([$relative], $result->measured->coverage->analyzedFiles);
        self::assertSame([], $result->findings());
    }

    #[Test]
    public function itPassesCapturedConfigurationToProjectFiles(): void
    {
        $root = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $configuration = new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );
        $projectFiles = $this->createMock(ProjectFilesInterface::class);
        $projectFiles->expects(self::once())->method('discover')->with(self::identicalTo($configuration))->willReturn(self::discovered([]));
        $collection = self::createStub(CollectionOrchestratorInterface::class);
        $collection->method('collect')->willReturn(new CollectionPhaseOutput([], [], classLikeDeclarations: []));

        $result = $this->pipeline($projectFiles, $collection)->analyze($configuration);

        self::assertSame([], $result->measured->coverage->analyzedFiles);
    }

    #[Test]
    public function itUsesTheProjectRootFromEachRunInTheSameProcess(): void
    {
        $firstRoot = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $secondRoot = AbsolutePath::fromString(sys_get_temp_dir());
        $discovery = self::createStub(ProjectFilesInterface::class);
        $discovery->method('discover')->willReturn(self::discovered([]));

        $seenRoots = [];
        $collection = self::createStub(CollectionOrchestratorInterface::class);
        $collection->method('collect')->willReturnCallback(
            static function (array $files, MetricRepositoryInterface $repository, AbsolutePath $root) use (&$seenRoots): CollectionPhaseOutput {
                $seenRoots[] = $root->value();

                return new CollectionPhaseOutput([], [], classLikeDeclarations: []);
            },
        );
        $pipeline = $this->pipeline($discovery, $collection);

        $pipeline->analyze(new RunConfiguration(
            pathExcludes: [],
            projectRoot: $firstRoot,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $firstRoot, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$firstRoot], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        ));
        $pipeline->analyze(new RunConfiguration(
            pathExcludes: [],
            projectRoot: $secondRoot,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $secondRoot, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$secondRoot], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        ));

        self::assertSame([$firstRoot->value(), $secondRoot->value()], $seenRoots);
    }

    #[Test]
    public function itReportsEachMixedSpellingGroupOnceAcrossRepositoryAndGraph(): void
    {
        $root = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $discovery = self::createStub(ProjectFilesInterface::class);
        $discovery->method('discover')->willReturn(self::discovered([]));
        $collection = self::createStub(CollectionOrchestratorInterface::class);
        $collection->method('collect')->willReturn(new CollectionPhaseOutput([], [], classLikeDeclarations: []));
        $repository = new InMemoryMetricRepository();
        $repository->add(SymbolPath::fromClassFqn('App\\Service'), new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag(), null, null);
        $repository->add(SymbolPath::fromClassFqn('app\\service'), new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag(), null, null);
        $mixed = new MixedSpelling('external', ['App\\Service', 'app\\service'], 'App\\Service');
        $logger = new RecordingLogger();

        $this->pipeline(
            $discovery,
            $collection,
            $repository,
            new DependencyGraphBuild(AdjacencyGraphBuilder::empty(), [$mixed]),
            $logger,
        )->analyze(new RunConfiguration(
            pathExcludes: [],
            projectRoot: $root,
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        ));

        $warnings = array_values(array_filter($logger->records, static fn(array $record): bool => $record['level'] === 'warning'));
        self::assertSame([
            'mixed spelling: App\\Service, app\\service → App\\Service',
            'mixed spelling: App, app → App',
        ], array_column($warnings, 'message'));
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function itMeasuresElapsedSecondsWhenTheWallClockMovesBackwards(): void
    {
        $prefix = tempnam(sys_get_temp_dir(), 'qmx-pipeline-clock-');
        self::assertNotFalse($prefix);

        try {
            file_put_contents($prefix, <<<'PHP'
                <?php
                namespace Qualimetrix\Analysis\Run\Pipeline;

                function microtime(bool $asFloat = false): float
                {
                    static $seconds = 100.0;

                    return --$seconds;
                }

                function hrtime(bool $asNumber = false): int
                {
                    static $nanoseconds = 0;
                    $nanoseconds += 1_000_000_000;

                    return $nanoseconds;
                }
                PHP);
            require $prefix;

            $root = AbsolutePath::fromString(\dirname(__DIR__, 5));
            $discovery = self::createStub(ProjectFilesInterface::class);
            $discovery->method('discover')->willReturn(self::discovered([]));
            $collection = self::createStub(CollectionOrchestratorInterface::class);
            $collection->method('collect')->willReturn(new CollectionPhaseOutput([], [], classLikeDeclarations: []));

            $result = $this->pipeline($discovery, $collection)->analyze(
                new RunConfiguration(
                    pathExcludes: [],
                    projectRoot: $root,
                    generatedFilePolicy: GeneratedFilePolicy::Include,
                    projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
                    authoredPathExcludes: [],
                    autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
                ),
            );

            self::assertSame(5.0, $result->measured->duration);
        } finally {
            unlink($prefix);
        }
    }

    /** @param list<SplFileInfo> $files */
    private static function discovered(array $files): DiscoveredProjectFiles
    {
        return new DiscoveredProjectFiles($files, [], [], [], [], new ScopeFacts([], [], [], false), \count($files));
    }

    private function pipeline(
        ProjectFilesInterface $discovery,
        CollectionOrchestratorInterface $collection,
        ?MetricRepositoryInterface $repository = null,
        ?DependencyGraphBuild $graphBuild = null,
        ?LoggerInterface $logger = null,
        ?ResolvedComputedMetricDefinitions $computedDefinitions = null,
    ): AnalysisPipeline {
        $profiler = self::createStub(ProfilerInterface::class);
        $ruleConfiguration = new RuleOptionsRegistry();
        $ruleConfiguration->replace(ResolvedOptionsFixture::ready(FindingConfiguration::none(), []));
        $producerGate = new RuleSelectorProducerGate($ruleConfiguration);
        $fileSetInspection = new FileSetInspectionComposite(
            [],
            $producerGate,
            $profiler,
        );
        $layerPolicy = self::architectureStub();
        $circular = self::createStub(CircularDependencyPreparationInterface::class);
        $preparation = new RuleProducerPreparation(
            $layerPolicy,
            $circular,
            $fileSetInspection,
            $producerGate,
        );
        $inlineDirectives = new InlineDirectiveRun(
            self::createStub(InlineDirectivePolicyInterface::class),
            self::createStub(ThresholdDirectiveAuditInterface::class),
        );

        $aggregation = self::createStub(MeasurementAggregationInterface::class);
        $aggregation->method('aggregate')->willReturn(new NamespaceTree([]));

        $analysis = new ComputedMetricAnalysis(new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()));
        $analysis->replace($computedDefinitions ?? new ResolvedComputedMetricDefinitions([]));
        $computed = new ComputedMetricEvaluator($analysis, $profiler);

        $graphBuilder = self::createStub(DependencyGraphBuilderInterface::class);
        $graphBuilder->method('build')->willReturn($graphBuild ?? new DependencyGraphBuild(AdjacencyGraphBuilder::empty(), []));

        $repository ??= new InMemoryMetricRepository();
        $repositoryFactory = self::createStub(MetricRepositoryFactoryInterface::class);
        $repositoryFactory->method('create')->willReturn($repository);

        $rules = self::createStub(RuleExecutionInterface::class);
        $rules->method('execute')->willReturn(new RuleExecutionResult([], [], new RuleExclusionStats(), LevelActivity::empty()));
        $rules->method('publication')->willReturn(new ChannelPublication(new RuleEnablement([], null)));
        $rules->method('allRules')->willReturn([]);

        return new AnalysisPipeline(
            $discovery,
            new UnmatchedExcludeAudit(new UnmatchedExcludeOptions()),
            $collection,
            $rules,
            $preparation,
            $inlineDirectives,
            $aggregation,
            $computed,
            $graphBuilder,
            $repositoryFactory,
            $profiler,
            $logger ?? new NullLogger(),
        );
    }

    private static function architectureStub(): LayerPolicyPreparationInterface&UnmatchedTypeWarningInterface
    {
        return new class implements LayerPolicyPreparationInterface, UnmatchedTypeWarningInterface {
            public function prepare(DependencyGraphInterface $graph, iterable $classUniverse): void {}

            public function reset(): void {}

            public function notJudgedWarning(\Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement $scope): ?string
            {
                return null;
            }
        };
    }
}
