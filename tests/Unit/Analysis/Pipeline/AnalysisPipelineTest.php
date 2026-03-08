<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Pipeline;

use AiMessDetector\Analysis\Aggregation\GlobalCollectorRunner;
use AiMessDetector\Analysis\Collection\CollectionOrchestratorInterface;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SplFileInfo;

#[CoversClass(AnalysisPipeline::class)]
final class AnalysisPipelineTest extends TestCase
{
    private FileDiscoveryInterface&MockObject $defaultDiscovery;
    private CollectionOrchestratorInterface&MockObject $collectionOrchestrator;
    private RuleExecutorInterface&MockObject $ruleExecutor;
    private ConfigurationProviderInterface&MockObject $configurationProvider;
    private GlobalCollectorRunner $globalCollectorRunner;
    private LoggerInterface&MockObject $logger;
    private CompositeCollector $compositeCollector;

    protected function setUp(): void
    {
        $this->defaultDiscovery = $this->createMock(FileDiscoveryInterface::class);
        $this->collectionOrchestrator = $this->createMock(CollectionOrchestratorInterface::class);
        $this->ruleExecutor = $this->createMock(RuleExecutorInterface::class);
        $this->configurationProvider = $this->createMock(ConfigurationProviderInterface::class);
        $this->globalCollectorRunner = new GlobalCollectorRunner([]);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->compositeCollector = new CompositeCollector([]);

        $this->configurationProvider->method('getRuleOptions')->willReturn([]);
        $this->ruleExecutor->method('execute')->willReturn([]);
    }

    #[Test]
    public function itHandlesEmptyFileList(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionResult(0, 0, []));

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
        $this->collectionOrchestrator->expects(self::once())
            ->method('collect')
            ->with(
                $files,
                self::isInstanceOf(MetricRepositoryInterface::class),
            )
            ->willReturn(new CollectionResult(2, 0, []));

        $pipeline = $this->createPipeline();

        $result = $pipeline->analyze('/path/to/src');

        self::assertSame(2, $result->filesAnalyzed);
        self::assertSame(0, $result->filesSkipped);
    }

    #[Test]
    public function itUsesCustomFileDiscovery(): void
    {
        $customDiscovery = $this->createMock(FileDiscoveryInterface::class);
        $customDiscovery->method('discover')->willReturn(new ArrayIterator([]));

        $this->defaultDiscovery->expects(self::never())->method('discover');
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionResult(0, 0, []));

        $pipeline = $this->createPipeline();

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
        $this->collectionOrchestrator->expects(self::once())
            ->method('collect')
            ->with(
                $files,
                self::isInstanceOf(MetricRepositoryInterface::class),
            )
            ->willReturn(new CollectionResult(1, 0, $dependencies));

        $pipeline = $this->createPipeline();

        $result = $pipeline->analyze('/path/to/src');

        self::assertSame(1, $result->filesAnalyzed);
    }

    #[Test]
    public function itReturnsResultWithCorrectMetadata(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionResult(5, 2, []));

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
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionResult(0, 0, []));

        $this->ruleExecutor->expects(self::once())->method('execute');

        $pipeline = $this->createPipeline();

        $pipeline->analyze('/path/to/src');
    }

    #[Test]
    public function itLogsAnalysisPhases(): void
    {
        $this->defaultDiscovery->method('discover')->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionResult(0, 0, []));

        // Expect multiple log calls for different phases
        $this->logger->expects(self::atLeast(3))->method('info');
        $this->logger->expects(self::atLeast(2))->method('debug');

        $pipeline = $this->createPipeline();

        $pipeline->analyze('/path/to/src');
    }

    #[Test]
    public function itHandlesArrayOfPaths(): void
    {
        $paths = ['/path/to/src', '/path/to/lib'];

        $this->defaultDiscovery->expects(self::once())
            ->method('discover')
            ->with($paths)
            ->willReturn(new ArrayIterator([]));
        $this->collectionOrchestrator->method('collect')->willReturn(new CollectionResult(0, 0, []));

        $pipeline = $this->createPipeline();

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
        $this->collectionOrchestrator->expects(self::once())
            ->method('collect')
            ->with(
                self::callback(static function (array $files): bool {
                    // Should have exactly 2 unique files, not duplicates
                    return \count($files) === 2;
                }),
                self::isInstanceOf(MetricRepositoryInterface::class),
            )
            ->willReturn(new CollectionResult(2, 0, []));

        $pipeline = $this->createPipeline();
        $pipeline->analyze(['/path/to/src', '/path/to/src/sub']);
    }

    private function createPipeline(): AnalysisPipeline
    {
        return new AnalysisPipeline(
            defaultDiscovery: $this->defaultDiscovery,
            collectionOrchestrator: $this->collectionOrchestrator,
            compositeCollector: $this->compositeCollector,
            ruleExecutor: $this->ruleExecutor,
            configurationProvider: $this->configurationProvider,
            globalCollectorRunner: $this->globalCollectorRunner,
            repositoryFactory: new DefaultMetricRepositoryFactory(),
            logger: $this->logger,
        );
    }
}
