<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Pipeline;

use AiMessDetector\Analysis\Aggregator\GlobalCollectorRunner;
use AiMessDetector\Analysis\Collection\CollectionOrchestratorInterface;
use AiMessDetector\Analysis\Collection\CollectionPhaseOutput;
use AiMessDetector\Analysis\Collection\CollectionResult;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Discovery\FileDiscoveryInterface;
use AiMessDetector\Analysis\Pipeline\AnalysisPipeline;
use AiMessDetector\Analysis\Repository\DefaultMetricRepositoryFactory;
use AiMessDetector\Analysis\RuleExecution\RuleExecutorInterface;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
        $this->defaultDiscovery = $this->createStub(FileDiscoveryInterface::class);
        $this->collectionOrchestrator = $this->createStub(CollectionOrchestratorInterface::class);
        $this->ruleExecutor = $this->createStub(RuleExecutorInterface::class);
        $this->configurationProvider = $this->createStub(ConfigurationProviderInterface::class);
        $this->globalCollectorRunner = new GlobalCollectorRunner([]);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->compositeCollector = new CompositeCollector([]);

        $this->configurationProvider->method('getRuleOptions')->willReturn([]);
        $this->ruleExecutor->method('execute')->willReturn([]);
    }

    #[Test]
    public function itHandlesEmptyFileList(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult(0, 0), []));

        $pipeline = $this->createPipeline();

        $result = $pipeline->analyze('/path/to/src');

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
            ->willReturn(new CollectionPhaseOutput(new CollectionResult(2, 0), []));

        $pipeline = $this->createPipeline(collectionOrchestrator: $collectionOrchestrator);

        $result = $pipeline->analyze('/path/to/src');

        self::assertSame(2, $result->filesAnalyzed);
        self::assertSame(0, $result->filesSkipped);
    }

    #[Test]
    public function itUsesCustomFileDiscovery(): void
    {
        $customDiscovery = $this->createStub(FileDiscoveryInterface::class);
        $customDiscovery->method('discover')->willReturn(new ArrayIterator([]));

        $defaultDiscovery = $this->createMock(FileDiscoveryInterface::class);
        $defaultDiscovery->expects(self::never())->method('discover');
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult(0, 0), []));

        $pipeline = $this->createPipeline(defaultDiscovery: $defaultDiscovery);

        $pipeline->analyze('/path/to/src', $customDiscovery);
    }

    #[Test]
    public function itCollectsDependenciesWithMetrics(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];
        $dependencies = [
            new Dependency(SymbolPath::fromClassFqn('App\Foo'), SymbolPath::fromClassFqn('App\Bar'), DependencyType::New_, new Location('/tmp/test.php', 10)),
        ];

        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator($files));

        $collectionOrchestrator = $this->createMock(CollectionOrchestratorInterface::class);
        $collectionOrchestrator->expects(self::once())
            ->method('collect')
            ->with(
                $files,
                self::isInstanceOf(MetricRepositoryInterface::class),
            )
            ->willReturn(new CollectionPhaseOutput(new CollectionResult(1, 0), $dependencies));

        $pipeline = $this->createPipeline(collectionOrchestrator: $collectionOrchestrator);

        $result = $pipeline->analyze('/path/to/src');

        self::assertSame(1, $result->filesAnalyzed);
    }

    #[Test]
    public function itReturnsResultWithCorrectMetadata(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult(5, 2), []));

        $pipeline = $this->createPipeline();

        $result = $pipeline->analyze('/path/to/src');

        self::assertSame(5, $result->filesAnalyzed);
        self::assertSame(2, $result->filesSkipped);
        self::assertGreaterThan(0, $result->duration);
        self::assertInstanceOf(MetricRepositoryInterface::class, $result->metrics);
    }

    #[Test]
    public function itExecutesRulesAfterCollection(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult(0, 0), []));

        $ruleExecutor = $this->createMock(RuleExecutorInterface::class);
        $ruleExecutor->expects(self::once())->method('execute')->willReturn([]);

        $pipeline = $this->createPipeline(ruleExecutor: $ruleExecutor);

        $pipeline->analyze('/path/to/src');
    }

    #[Test]
    public function itLogsAnalysisPhases(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult(0, 0), []));

        $logger = $this->createMock(LoggerInterface::class);
        // Expect multiple log calls for different phases
        $logger->expects(self::atLeast(3))->method('info');
        $logger->expects(self::atLeast(2))->method('debug');

        $pipeline = $this->createPipeline(logger: $logger);

        $pipeline->analyze('/path/to/src');
    }

    #[Test]
    public function itHandlesArrayOfPaths(): void
    {
        $paths = ['/path/to/src', '/path/to/lib'];

        $defaultDiscovery = $this->createMock(FileDiscoveryInterface::class);
        $defaultDiscovery->expects(self::once())
            ->method('discover')
            ->with($paths)
            ->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionPhaseOutput(new CollectionResult(0, 0), []));

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
            ->willReturn(new CollectionPhaseOutput(new CollectionResult(2, 0), []));

        $pipeline = $this->createPipeline(collectionOrchestrator: $collectionOrchestrator);
        $pipeline->analyze(['/path/to/src', '/path/to/src/sub']);
    }

    private function createPipeline(
        ?FileDiscoveryInterface $defaultDiscovery = null,
        ?CollectionOrchestratorInterface $collectionOrchestrator = null,
        ?RuleExecutorInterface $ruleExecutor = null,
        ?LoggerInterface $logger = null,
    ): AnalysisPipeline {
        return new AnalysisPipeline(
            defaultDiscovery: $defaultDiscovery ?? $this->defaultDiscovery,
            collectionOrchestrator: $collectionOrchestrator ?? $this->collectionOrchestrator,
            compositeCollector: $this->compositeCollector,
            ruleExecutor: $ruleExecutor ?? $this->ruleExecutor,
            configurationProvider: $this->configurationProvider,
            globalCollectorRunner: $this->globalCollectorRunner,
            repositoryFactory: new DefaultMetricRepositoryFactory(),
            logger: $logger ?? $this->logger,
        );
    }
}
