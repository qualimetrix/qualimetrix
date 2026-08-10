<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Pipeline;

use ArrayIterator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Aggregator\GlobalCollectorRunner;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Collection\CollectionResult;
use Qualimetrix\Analysis\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Duplication\DuplicationDetectorInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Pipeline\AnalysisPipeline;
use Qualimetrix\Analysis\Pipeline\MetricEnricher;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Metric\GlobalContextCollectorInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Profiler\ProfilerInterface;
use Qualimetrix\Core\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Core\Rule\RuleSelector;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Rules\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Tests\Support\Pipeline\TestPipelineBuilder;
use SplFileInfo;

#[CoversClass(AnalysisPipeline::class)]
final class AnalysisPipelineTest extends TestCase
{
    private FileDiscoveryInterface&Stub $defaultDiscovery;
    private CollectionOrchestratorInterface&Stub $collectionOrchestrator;
    private RuleExecutorInterface&Stub $ruleExecutor;
    private ConfigurationProviderInterface&Stub $configurationProvider;
    private GlobalCollectorRunner $globalCollectorRunner;
    private LoggerInterface&Stub $logger;
    private CompositeCollector $compositeCollector;

    protected function setUp(): void
    {
        $this->defaultDiscovery = self::createStub(FileDiscoveryInterface::class);
        $this->collectionOrchestrator = self::createStub(CollectionOrchestratorInterface::class);
        $this->ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $this->configurationProvider = self::createStub(ConfigurationProviderInterface::class);
        $this->globalCollectorRunner = new GlobalCollectorRunner([]);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->compositeCollector = new CompositeCollector([]);

        $this->configurationProvider->method('getConfiguration')->willReturn(new AnalysisConfiguration());
        $this->configurationProvider->method('getRuleOptions')->willReturn([]);
        $this->ruleExecutor->method('execute')->willReturn([]);
    }

    #[Test]
    public function itHandlesEmptyFileList(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult([], []), []));

        $pipeline = $this->createPipeline();

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame(0, $result->filesAnalyzed);
        self::assertSame(0, $result->filesSkipped);
        self::assertSame([], $result->violations);
    }

    #[Test]
    public function itCollectsMetricsFromDiscoveredFiles(): void
    {
        $files = [
            new SplFileInfo('/tmp/file1.php'),
            new SplFileInfo('/tmp/file2.php'),
        ];

        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator($files));

        $collectionOrchestrator = $this->createMock(CollectionOrchestratorInterface::class);
        $collectionOrchestrator->expects(self::once())
            ->method('collect')
            ->with(
                $files,
                self::isInstanceOf(MetricRepositoryInterface::class),
            )
            ->willReturn(new CollectionPhaseOutput(new CollectionResult(self::relativePaths($files), []), []));

        $pipeline = $this->createPipeline(collectionOrchestrator: $collectionOrchestrator);

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame(2, $result->filesAnalyzed);
        self::assertSame(0, $result->filesSkipped);
    }

    #[Test]
    public function itUsesCustomFileDiscovery(): void
    {
        $customDiscovery = self::createStub(FileDiscoveryInterface::class);
        $customDiscovery->method('discover')->willReturn(new ArrayIterator([]));

        $defaultDiscovery = $this->createMock(FileDiscoveryInterface::class);
        $defaultDiscovery->expects(self::never())->method('discover');
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult([], []), []));

        $pipeline = $this->createPipeline(defaultDiscovery: $defaultDiscovery);

        $pipeline->analyze(AbsolutePath::fromString('/path/to/src'), $customDiscovery);
    }

    #[Test]
    public function itCollectsDependenciesWithMetrics(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];
        $dependencies = [
            new Dependency(
                new DeclarationPath(SymbolPath::fromClassFqn('App\Foo'), RelativePath::fromString('tmp/test.php'), 0),
                new LogicalClassPath(SymbolPath::fromClassFqn('App\Bar')),
                DependencyType::New_,
                new Location(RelativePath::fromString('tmp/test.php'), 10),
            ),
        ];

        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator($files));

        $collectionOrchestrator = $this->createMock(CollectionOrchestratorInterface::class);
        $collectionOrchestrator->expects(self::once())
            ->method('collect')
            ->with(
                $files,
                self::isInstanceOf(MetricRepositoryInterface::class),
            )
            ->willReturn(new CollectionPhaseOutput(new CollectionResult(self::relativePaths($files), []), $dependencies));

        $pipeline = $this->createPipeline(collectionOrchestrator: $collectionOrchestrator);

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame(1, $result->filesAnalyzed);
    }

    #[Test]
    public function itReturnsResultWithCorrectMetadata(): void
    {
        $root = (new AnalysisConfiguration())->projectRoot;
        $files = array_map(
            static fn(int $index): SplFileInfo => new SplFileInfo($root->value() . '/File' . $index . '.php'),
            range(0, 6),
        );
        $terminalPaths = self::relativePaths($files);
        $failures = [
            FileProcessingResult::failure($terminalPaths[5], 'failure 5'),
            FileProcessingResult::failure($terminalPaths[6], 'failure 6'),
        ];

        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator($files));
        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(new CollectionResult(\array_slice($terminalPaths, 0, 5), $failures), []),
        );

        $pipeline = $this->createPipeline();

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame(5, $result->filesAnalyzed);
        self::assertSame(2, $result->filesSkipped);
        self::assertGreaterThan(0, $result->duration);
        self::assertInstanceOf(MetricRepositoryInterface::class, $result->metrics); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itKeepsGeneratedExclusionsAsCompleteCoverage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-generated-');
        self::assertNotFalse($path);
        file_put_contents($path, "<?php\n/** @generated */\nfinal class Generated {}\n");

        try {
            $this->defaultDiscovery->method('discover')->willReturn(
                new ArrayIterator([new SplFileInfo($path)]),
            );
            $this->collectionOrchestrator->method('collect')->willReturn(
                new CollectionPhaseOutput(new CollectionResult([], []), []),
            );

            $result = $this->createPipeline()->analyze(AbsolutePath::fromString(sys_get_temp_dir()));

            self::assertSame(1, $result->coverage->discoveredFiles());
            self::assertSame(1, $result->coverage->generatedExcludedFilesCount());
            self::assertSame(0, $result->coverage->failedFilesCount());
            self::assertTrue($result->coverage->isComplete());
            self::assertSame(1, $result->filesSkipped);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itCarriesTypedCollectionFailuresIntoCanonicalCoverage(): void
    {
        $root = (string) getcwd();
        $files = [new SplFileInfo($root . '/Good.php'), new SplFileInfo($root . '/Broken.php')];
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator($files));

        $goodPath = RelativePath::fromString('Good.php');
        $failure = FileProcessingResult::failure(
            RelativePath::fromString('Broken.php'),
            'worker crashed',
            FileProcessingFailureKind::Processing,
        );
        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult(
                    [$goodPath],
                    [$failure],
                ),
                [],
            ),
        );

        $result = $this->createPipeline()->analyze(AbsolutePath::fromString($root));

        self::assertFalse($result->coverage->isComplete());
        self::assertSame(2, $result->coverage->discoveredFiles());
        self::assertSame('Broken.php', $result->coverage->failures[0]->path->value());
        self::assertSame(AnalysisFailureKind::Processing, $result->coverage->failures[0]->kind);
        self::assertSame('worker crashed', $result->coverage->failures[0]->message);
    }

    #[Test]
    public function itRejectsTerminalPathsThatDoNotMatchDiscovery(): void
    {
        $root = (new AnalysisConfiguration())->projectRoot;
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([
            new SplFileInfo($root->value() . '/ActuallyDiscovered.php'),
        ]));
        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult([RelativePath::fromString('Synthetic.php')], []),
                [],
            ),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('terminal states do not match');

        $this->createPipeline()->analyze($root);
    }

    /**
     * A `@qmx-threshold` annotation is extracted during Collection and
     * carried into `AnalysisContext` for rule execution — but the
     * ADR 0017's `explain` command needs to read the same
     * overrides *after* the run, from `AnalysisResult` alone. This is the
     * run this package's DoD requires: a real `AnalysisPipeline::analyze()`
     * call over a file whose Collection phase reported a `@qmx-threshold`
     * override must leave that override readable on the result it returns.
     */
    #[Test]
    public function itCarriesThresholdOverridesFromCollectionOntoTheResult(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));

        $overrides = [
            'src/Foo.php' => [
                self::thresholdOverride('complexity.cyclomatic', 15.0, 25.0, 10),
            ],
        ];

        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult([], [], thresholdOverrides: $overrides),
                [],
            ),
        );

        $pipeline = $this->createPipeline();
        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame($overrides, $result->thresholdOverrides);
    }

    #[Test]
    public function itExecutesRulesAfterCollection(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult([], []), []));

        $ruleExecutor = $this->createMock(RuleExecutorInterface::class);
        $ruleExecutor->expects(self::once())->method('execute')->willReturn([]);

        $pipeline = $this->createPipeline(ruleExecutor: $ruleExecutor);

        $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));
    }

    #[Test]
    public function itLogsAnalysisPhases(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult([], []), []));

        $logger = $this->createMock(LoggerInterface::class);
        // Expect multiple log calls for different phases
        $logger->expects(self::atLeast(3))->method('info');
        $logger->expects(self::atLeast(2))->method('debug');

        $pipeline = $this->createPipeline(logger: $logger);

        $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));
    }

    #[Test]
    public function itHandlesArrayOfPaths(): void
    {
        $paths = [
            AbsolutePath::fromString('/path/to/src'),
            AbsolutePath::fromString('/path/to/lib'),
        ];

        $defaultDiscovery = $this->createMock(FileDiscoveryInterface::class);
        $defaultDiscovery->expects(self::once())
            ->method('discover')
            ->with($paths)
            ->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult([], []), []));

        $pipeline = $this->createPipeline(defaultDiscovery: $defaultDiscovery);

        $pipeline->analyze($paths);
    }

    #[Test]
    public function itDeduplicatesOverlappingFiles(): void
    {
        $file1 = new SplFileInfo('/tmp/file1.php');
        $file2 = new SplFileInfo('/tmp/file2.php');

        // Discovery yields the same file path as key twice (overlapping paths scenario)
        $discoveryResult = new ArrayIterator([
            '/tmp/file1.php' => $file1,
            '/tmp/file2.php' => $file2,
        ]);

        $this->defaultDiscovery->method('discover')->willReturn($discoveryResult);

        $collectionOrchestrator = $this->createMock(CollectionOrchestratorInterface::class);
        $collectionOrchestrator->expects(self::once())
            ->method('collect')
            ->with(
                self::callback(static function (array $files): bool {
                    // Should have exactly 2 unique files, not duplicates
                    return \count($files) === 2;
                }),
                self::isInstanceOf(MetricRepositoryInterface::class),
            )
            ->willReturn(new CollectionPhaseOutput(new CollectionResult(self::relativePaths([$file1, $file2]), []), []));

        $pipeline = $this->createPipeline(collectionOrchestrator: $collectionOrchestrator);
        $pipeline->analyze([
            AbsolutePath::fromString('/path/to/src'),
            AbsolutePath::fromString('/path/to/src/sub'),
        ]);
    }

    #[Test]
    public function itSkipsDuplicationDetectionWhenRuleDisabled(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult([], []), []));

        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn(
            new AnalysisConfiguration(disabledRules: ['duplication.code-duplication']),
        );
        $configProvider->method('getRuleOptions')->willReturn([]);

        $duplicationDetector = $this->createMock(DuplicationDetectorInterface::class);
        $duplicationDetector->expects(self::never())->method('detect');

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($this->defaultDiscovery)
            ->withCollectionOrchestrator($this->collectionOrchestrator)
            ->withRuleExecutor($this->ruleExecutor)
            ->withConfigurationProvider($configProvider)
            ->withMetricEnricher(new MetricEnricher(
                compositeCollector: $this->compositeCollector,
                globalCollectorRunner: $this->globalCollectorRunner,
                configurationProvider: $configProvider,
                logger: $this->logger,
                duplicationDetector: $duplicationDetector,
            ))
            ->withLogger($this->logger)
            ->build();

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame([], $result->violations);
    }

    #[Test]
    public function itSkipsCircularDependencyDetectionWhenRuleDisabled(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult([], []), []));

        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn(
            new AnalysisConfiguration(disabledRules: ['architecture.circular-dependency']),
        );
        $configProvider->method('getRuleOptions')->willReturn([]);

        $pipeline = $this->createPipeline(configurationProvider: $configProvider);

        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame([], $result->violations);
    }

    #[Test]
    public function itWarnsWhenThresholdAnnotationTargetsUnsupportedRule(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));

        $overrides = [
            'src/Foo.php' => [
                self::thresholdOverride('code-smell.boolean-argument', 50.0, 100.0, 10, 50),
            ],
        ];

        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult([], [], thresholdOverrides: $overrides),
                [],
            ),
        );

        // BooleanArgumentRule has a boolean-only Options class — no ThresholdAwareOptionsInterface
        $booleanArgRule = new BooleanArgumentRule(BooleanArgumentRule::getOptionsClass()::fromArray([]));
        // ComplexityRule supports it
        $complexityRule = new ComplexityRule(ComplexityRule::getOptionsClass()::fromArray([]));

        $ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $ruleExecutor->method('execute')->willReturn([]);
        $ruleExecutor->method('getAllRules')->willReturn([$booleanArgRule, $complexityRule]);

        $pipeline = $this->createPipeline(ruleExecutor: $ruleExecutor);
        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        // Should have a warning violation for the unsupported rule
        self::assertCount(1, $result->violations);
        self::assertSame('annotation.unsupported-threshold', $result->violations[0]->ruleName);
        self::assertSame(Severity::Warning, $result->violations[0]->severity);
        self::assertStringContainsString('code-smell.boolean-argument', $result->violations[0]->message);
        self::assertStringContainsString('does not support', $result->violations[0]->message);
    }

    #[Test]
    public function itDoesNotWarnForSupportedThresholdOverride(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));

        $overrides = [
            'src/Foo.php' => [
                self::thresholdOverride('complexity.cyclomatic', 15.0, 25.0, 10, 50),
            ],
        ];

        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult([], [], thresholdOverrides: $overrides),
                [],
            ),
        );

        $complexityRule = new ComplexityRule(ComplexityRule::getOptionsClass()::fromArray([]));

        $ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $ruleExecutor->method('execute')->willReturn([]);
        $ruleExecutor->method('getAllRules')->willReturn([$complexityRule]);

        $pipeline = $this->createPipeline(ruleExecutor: $ruleExecutor);
        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame([], $result->violations);
    }

    #[Test]
    public function itSkipsArchitecturePrepareWhenLayerViolationRuleDisabled(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(new CollectionResult([], []), []),
        );

        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn(
            new AnalysisConfiguration(disabledRules: [LayerViolationRule::NAME]),
        );
        $configProvider->method('getRuleOptions')->willReturn([]);

        $processor = $this->createMock(ArchitectureProcessorInterface::class);
        // bind() runs in the production wiring before the pipeline analyses —
        // TestPipelineBuilder mimics that, then the pipeline must not call
        // prepare() once it sees the rule is disabled (symmetric with the
        // duplication detector skip in MetricEnricher).
        $processor->expects(self::once())->method('bind');
        $processor->expects(self::never())->method('prepare');

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($this->defaultDiscovery)
            ->withCollectionOrchestrator($this->collectionOrchestrator)
            ->withRuleExecutor($this->ruleExecutor)
            ->withConfigurationProvider($configProvider)
            ->withMetricEnricher(new MetricEnricher(
                compositeCollector: $this->compositeCollector,
                globalCollectorRunner: $this->globalCollectorRunner,
                configurationProvider: $configProvider,
                logger: $this->logger,
            ))
            ->withArchitectureProcessor($processor)
            ->withLogger($this->logger)
            ->build();

        // bind() simulates the production RuntimeConfigurator handshake.
        $processor->bind(ArchitectureConfiguration::empty());

        $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));
    }

    #[Test]
    public function itPreparesArchitectureProcessorWhenLayerViolationRuleEnabled(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(new CollectionResult([], []), []),
        );

        // Default AnalysisConfiguration leaves disabledRules empty, so the
        // layer-violation rule is enabled by default.
        $processor = $this->createMock(ArchitectureProcessorInterface::class);
        $processor->expects(self::once())->method('bind');
        $processor->expects(self::once())->method('prepare');

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($this->defaultDiscovery)
            ->withCollectionOrchestrator($this->collectionOrchestrator)
            ->withRuleExecutor($this->ruleExecutor)
            ->withConfigurationProvider($this->configurationProvider)
            ->withMetricEnricher(new MetricEnricher(
                compositeCollector: $this->compositeCollector,
                globalCollectorRunner: $this->globalCollectorRunner,
                configurationProvider: $this->configurationProvider,
                logger: $this->logger,
            ))
            ->withArchitectureProcessor($processor)
            ->withLogger($this->logger)
            ->build();

        $processor->bind(ArchitectureConfiguration::empty());

        $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));
    }

    #[Test]
    public function itPreparesTheArchitectureProducerWhenOnlyADiagnosticChannelIsSelected(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(new CollectionResult([], []), []),
        );

        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn(
            new AnalysisConfiguration(onlyRules: ['architecture.coverage']),
        );
        $configProvider->method('getRuleOptions')->willReturn([]);

        $processor = $this->createMock(ArchitectureProcessorInterface::class);
        $processor->expects(self::once())->method('bind');
        $processor->expects(self::once())->method('prepare');

        $channelRegistry = new class implements RuleChannelRegistryInterface {
            public function channelsProducedBy(string $producerRuleName): array
            {
                return $producerRuleName === LayerViolationRule::NAME
                    ? [new ViolationChannel('architecture.coverage', 'architecture.coverage')]
                    : [];
            }
        };

        $pipeline = TestPipelineBuilder::create()
            ->withDefaultDiscovery($this->defaultDiscovery)
            ->withCollectionOrchestrator($this->collectionOrchestrator)
            ->withRuleExecutor($this->ruleExecutor)
            ->withConfigurationProvider($configProvider)
            ->withMetricEnricher(new MetricEnricher(
                compositeCollector: $this->compositeCollector,
                globalCollectorRunner: $this->globalCollectorRunner,
                configurationProvider: $configProvider,
                logger: $this->logger,
            ))
            ->withArchitectureProcessor($processor)
            ->withRuleSelector(new RuleSelector($channelRegistry))
            ->withLogger($this->logger)
            ->build();

        $processor->bind(ArchitectureConfiguration::empty());

        $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));
    }

    #[Test]
    public function itDoesNotWarnForWildcardThresholdOverride(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));

        $overrides = [
            'src/Foo.php' => [
                self::thresholdOverride('*', 15.0, 25.0, 10, 50),
            ],
        ];

        $this->collectionOrchestrator->method('collect')->willReturn(
            new CollectionPhaseOutput(
                new CollectionResult([], [], thresholdOverrides: $overrides),
                [],
            ),
        );

        $ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $ruleExecutor->method('execute')->willReturn([]);
        $ruleExecutor->method('getAllRules')->willReturn([]);

        $pipeline = $this->createPipeline(ruleExecutor: $ruleExecutor);
        $result = $pipeline->analyze(AbsolutePath::fromString('/path/to/src'));

        self::assertSame([], $result->violations);
    }

    #[Test]
    public function itKeepsCollectionArchitectureEnrichmentAndRulesInExactOrderForDegreeZeroClasses(): void
    {
        $events = [];
        $root = (new AnalysisConfiguration())->projectRoot;
        $file = RelativePath::fromString('src/DegreeZero.php');
        $class = SymbolPath::fromClassFqn('App\\DegreeZero');
        $discovered = new SplFileInfo($root->value() . '/' . $file->value());
        $capturedRepository = null;

        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn(new ArrayIterator([$discovered]));

        $collection = $this->createMock(CollectionOrchestratorInterface::class);
        $collection->expects(self::once())->method('collect')->willReturnCallback(
            static function (array $files, MetricRepositoryInterface $repository) use (
                &$events,
                &$capturedRepository,
                $class,
                $file,
                $discovered,
            ): CollectionPhaseOutput {
                self::assertSame([$discovered], $files);
                $events[] = 'Collection';
                $capturedRepository = $repository;
                $repository->add($class, MetricBag::fromArray(['base' => 1]), $file, 1);

                return new CollectionPhaseOutput(new CollectionResult([$file], []), []);
            },
        );

        $architecture = $this->createMock(ArchitectureProcessorInterface::class);
        $architecture->expects(self::once())->method('prepare')->willReturnCallback(
            static function (DependencyGraphInterface $graph, ClassSet $classSet) use (&$events, $class): void {
                $events[] = 'Architecture';
                self::assertSame([$class], $graph->getAllClasses());
                self::assertSame([$class], $classSet->classes());
            },
        );
        $architecture->bind(ArchitectureConfiguration::empty());

        $globalCollector = self::createStub(GlobalContextCollectorInterface::class);
        $globalCollector->method('getName')->willReturn('order-fixture');
        $globalCollector->method('provides')->willReturn(['enriched']);
        $globalCollector->method('requires')->willReturn([]);
        $globalCollector->method('getMetricDefinitions')->willReturn([]);
        $globalCollector->method('calculate')->willReturnCallback(
            static function (DependencyGraphInterface $graph, MetricRepositoryInterface $repository) use (&$events, $class): void {
                $events[] = 'Enrichment';
                self::assertSame([$class], $graph->getAllClasses());
                $repository->addScalar($class, 'enriched', 1);
            },
        );
        $enricher = new MetricEnricher(
            compositeCollector: $this->compositeCollector,
            globalCollectorRunner: new GlobalCollectorRunner([$globalCollector]),
            configurationProvider: $this->configurationProvider,
            logger: $this->logger,
        );

        $rules = $this->createMock(RuleExecutorInterface::class);
        $rules->expects(self::once())->method('execute')->willReturnCallback(
            static function (\Qualimetrix\Core\Rule\AnalysisContext $context) use (&$events, $class): array {
                $events[] = 'RuleExecution';
                self::assertSame(1, $context->metrics->get($class)->get('enriched'));
                self::assertNotNull($context->dependencyGraph);
                self::assertSame([$class], $context->dependencyGraph->getAllClasses());

                return [];
            },
        );

        $result = TestPipelineBuilder::create()
            ->withDefaultDiscovery($discovery)
            ->withCollectionOrchestrator($collection)
            ->withRuleExecutor($rules)
            ->withConfigurationProvider($this->configurationProvider)
            ->withMetricEnricher($enricher)
            ->withArchitectureProcessor($architecture)
            ->withLogger($this->logger)
            ->build()
            ->analyze($root);

        self::assertSame(['Collection', 'Architecture', 'Enrichment', 'RuleExecution'], $events);
        self::assertSame([], $result->violations);
        self::assertSame(1, $result->filesAnalyzed);
        self::assertSame(0, $result->filesSkipped);
        self::assertTrue($result->coverage->isComplete());
        self::assertSame(1, $result->coverage->discoveredFiles());
        self::assertSame($capturedRepository, $result->metrics);
        self::assertSame(1, $result->metrics->get($class)->get('enriched'));
        self::assertNotNull($result->namespaceTree);
        self::assertSame([], $result->suppressions);
        self::assertSame([], $result->thresholdOverrides);
    }

    #[Test]
    public function itUsesOneResolvedProfilerEvenWhenDiscoveryReplacesTheGlobalHolder(): void
    {
        $primaryEvents = [];
        $replacementEvents = [];
        $primary = self::createStub(ProfilerInterface::class);
        $primary->method('start')->willReturnCallback(
            static function (string $name) use (&$primaryEvents): void {
                $primaryEvents[] = 'start:' . $name;
            },
        );
        $primary->method('stop')->willReturnCallback(
            static function (string $name) use (&$primaryEvents): void {
                $primaryEvents[] = 'stop:' . $name;
            },
        );
        $replacement = self::createStub(ProfilerInterface::class);
        $replacement->method('start')->willReturnCallback(
            static function (string $name) use (&$replacementEvents): void {
                $replacementEvents[] = 'start:' . $name;
            },
        );
        $replacement->method('stop')->willReturnCallback(
            static function (string $name) use (&$replacementEvents): void {
                $replacementEvents[] = 'stop:' . $name;
            },
        );

        ProfilerHolder::set($primary);
        try {
            $discovery = self::createStub(FileDiscoveryInterface::class);
            $discovery->method('discover')->willReturnCallback(static function () use ($replacement): ArrayIterator {
                ProfilerHolder::reset();
                ProfilerHolder::set($replacement);

                return new ArrayIterator([]);
            });
            $this->collectionOrchestrator->method('collect')->willReturn(
                new CollectionPhaseOutput(new CollectionResult([], []), []),
            );
            $architecture = $this->createMock(ArchitectureProcessorInterface::class);
            $architecture->expects(self::once())->method('prepare');
            $architecture->bind(ArchitectureConfiguration::empty());

            TestPipelineBuilder::create()
                ->withDefaultDiscovery($discovery)
                ->withCollectionOrchestrator($this->collectionOrchestrator)
                ->withRuleExecutor($this->ruleExecutor)
                ->withConfigurationProvider($this->configurationProvider)
                ->withMetricEnricher(new MetricEnricher(
                    compositeCollector: $this->compositeCollector,
                    globalCollectorRunner: $this->globalCollectorRunner,
                    configurationProvider: $this->configurationProvider,
                    logger: $this->logger,
                ))
                ->withArchitectureProcessor($architecture)
                ->withLogger($this->logger)
                ->withProfilerHolder(new ProfilerHolder())
                ->build()
                ->analyze(AbsolutePath::fromString('/path/to/src'));
        } finally {
            ProfilerHolder::reset();
        }

        self::assertSame([
            'start:analysis',
            'start:discovery',
            'stop:discovery',
            'start:collection',
            'stop:collection',
            'start:dependency',
            'stop:dependency',
            'start:architecture-prepare',
            'stop:architecture-prepare',
            'start:rules',
            'stop:rules',
            'stop:analysis',
        ], $primaryEvents);
        self::assertSame([], $replacementEvents);
    }

    private function createPipeline(
        ?FileDiscoveryInterface $defaultDiscovery = null,
        ?CollectionOrchestratorInterface $collectionOrchestrator = null,
        ?RuleExecutorInterface $ruleExecutor = null,
        ?LoggerInterface $logger = null,
        ?ConfigurationProviderInterface $configurationProvider = null,
    ): AnalysisPipeline {
        $resolvedConfigProvider = $configurationProvider ?? $this->configurationProvider;
        $resolvedLogger = $logger ?? $this->logger;

        $metricEnricher = new MetricEnricher(
            compositeCollector: $this->compositeCollector,
            globalCollectorRunner: $this->globalCollectorRunner,
            configurationProvider: $resolvedConfigProvider,
            logger: $resolvedLogger,
        );

        return TestPipelineBuilder::create()
            ->withDefaultDiscovery($defaultDiscovery ?? $this->defaultDiscovery)
            ->withCollectionOrchestrator($collectionOrchestrator ?? $this->collectionOrchestrator)
            ->withRuleExecutor($ruleExecutor ?? $this->ruleExecutor)
            ->withConfigurationProvider($resolvedConfigProvider)
            ->withMetricEnricher($metricEnricher)
            ->withLogger($resolvedLogger)
            ->build();
    }

    /**
     * @param list<SplFileInfo> $files
     *
     * @return list<RelativePath>
     */
    private static function relativePaths(array $files): array
    {
        $projectRoot = (new AnalysisConfiguration())->projectRoot;

        return array_map(
            static fn(SplFileInfo $file): RelativePath => PathFactory::bestEffortRelative(
                $file->getPathname(),
                $projectRoot,
            ),
            $files,
        );
    }

    private static function thresholdOverride(
        string $rulePattern,
        int|float $warning,
        int|float $error,
        int $line,
        ?int $endLine = null,
    ): ThresholdOverride {
        $file = RelativePath::fromString('src/Foo.php');

        return new ThresholdOverride(
            $rulePattern,
            $warning,
            $error,
            $line,
            MetricSubject::aggregate(SymbolPath::forFile($file)),
            ControlScope::Class_,
            $endLine,
        );
    }
}
