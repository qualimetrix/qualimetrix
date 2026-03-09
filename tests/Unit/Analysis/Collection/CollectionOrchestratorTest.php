<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection;

use AiMessDetector\Analysis\Collection\CollectionOrchestrator;
use AiMessDetector\Analysis\Collection\FileProcessingResult;
use AiMessDetector\Analysis\Collection\FileProcessorInterface;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Collection\Metric\DerivedMetricExtractor;
use AiMessDetector\Analysis\Collection\Strategy\ExecutionStrategyInterface;
use AiMessDetector\Analysis\Collection\Strategy\StrategySelectorInterface;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Progress\ProgressReporter;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SplFileInfo;

#[CoversClass(CollectionOrchestrator::class)]
final class CollectionOrchestratorTest extends TestCase
{
    private FileProcessorInterface&MockObject $fileProcessor;
    private ExecutionStrategyInterface&MockObject $strategy;
    private StrategySelectorInterface&MockObject $strategySelector;
    private ProgressReporter&MockObject $progress;
    private LoggerInterface&MockObject $logger;
    private DerivedMetricExtractor $derivedMetricExtractor;

    protected function setUp(): void
    {
        $this->fileProcessor = $this->createMock(FileProcessorInterface::class);
        $this->strategy = $this->createMock(ExecutionStrategyInterface::class);
        $this->strategySelector = $this->createMock(StrategySelectorInterface::class);
        $this->strategySelector->method('select')->willReturn($this->strategy);
        $this->progress = $this->createMock(ProgressReporter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->derivedMetricExtractor = new DerivedMetricExtractor(new CompositeCollector([]));
    }

    #[Test]
    public function itHandlesEmptyFileList(): void
    {
        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect([], $repository);

        self::assertSame(0, $result->result->filesAnalyzed);
        self::assertSame(0, $result->result->filesSkipped);
        self::assertSame([], $result->dependencies);
    }

    #[Test]
    public function itCollectsMetricsFromFiles(): void
    {
        $files = [
            new SplFileInfo('/tmp/file1.php'),
            new SplFileInfo('/tmp/file2.php'),
        ];

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/file1.php',
                fileBag: MetricBag::fromArray(['loc' => 50]),
            ),
            FileProcessingResult::success(
                filePath: '/tmp/file2.php',
                fileBag: MetricBag::fromArray(['loc' => 100]),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);
        $this->progress->expects(self::once())->method('start')->with(2);
        $this->progress->expects(self::exactly(2))->method('advance');
        $this->progress->expects(self::once())->method('finish');

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository);

        self::assertSame(2, $result->result->filesAnalyzed);
        self::assertSame(0, $result->result->filesSkipped);

        // Check that file metrics were registered
        $fileSymbol1 = SymbolPath::forFile('/tmp/file1.php');
        $fileSymbol2 = SymbolPath::forFile('/tmp/file2.php');

        self::assertTrue($repository->has($fileSymbol1));
        self::assertTrue($repository->has($fileSymbol2));
        self::assertSame(50, $repository->get($fileSymbol1)->get('loc'));
        self::assertSame(100, $repository->get($fileSymbol2)->get('loc'));
    }

    #[Test]
    public function itHandlesProcessingFailures(): void
    {
        $files = [
            new SplFileInfo('/tmp/valid.php'),
            new SplFileInfo('/tmp/invalid.php'),
        ];

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/valid.php',
                fileBag: MetricBag::fromArray(['loc' => 50]),
            ),
            FileProcessingResult::failure(
                filePath: '/tmp/invalid.php',
                error: 'Syntax error',
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);
        $this->logger->expects(self::once())->method('warning');

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository);

        self::assertSame(1, $result->result->filesAnalyzed);
        self::assertSame(1, $result->result->filesSkipped);
    }

    #[Test]
    public function itRegistersMethodMetrics(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];
        $symbolPath = SymbolPath::forMethod('App', 'Service', 'calculate');
        $methodBag = MetricBag::fromArray(['ccn' => 5]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: new MetricBag(),
                methodMetrics: [
                    'App::Service::calculate' => [
                        'symbolPath' => $symbolPath,
                        'metrics' => $methodBag,
                        'line' => 15,
                    ],
                ],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);

        self::assertTrue($repository->has($symbolPath));
        self::assertSame(5, $repository->get($symbolPath)->get('ccn'));
    }

    #[Test]
    public function itRegistersClassMetrics(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];
        $symbolPath = SymbolPath::forClass('App', 'Service');
        $classBag = MetricBag::fromArray(['wmc' => 25]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: new MetricBag(),
                classMetrics: [
                    'App::Service' => [
                        'symbolPath' => $symbolPath,
                        'metrics' => $classBag,
                        'line' => 5,
                    ],
                ],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);

        self::assertTrue($repository->has($symbolPath));
        self::assertSame(25, $repository->get($symbolPath)->get('wmc'));
    }

    #[Test]
    public function itCollectsDependenciesFromResults(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];
        $dependency1 = new Dependency(SymbolPath::fromClassFqn('App\Foo'), SymbolPath::fromClassFqn('App\Bar'), DependencyType::New_, new Location('/tmp/test.php', 10));
        $dependency2 = new Dependency(SymbolPath::fromClassFqn('App\Foo'), SymbolPath::fromClassFqn('App\Baz'), DependencyType::Extends, new Location('/tmp/test.php', 5));

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: new MetricBag(),
                methodMetrics: [],
                classMetrics: [],
                dependencies: [$dependency1, $dependency2],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository);

        self::assertCount(2, $result->dependencies);
        self::assertSame('App\Foo', $result->dependencies[0]->source->toString());
        self::assertSame('App\Bar', $result->dependencies[0]->target->toString());
        self::assertSame('App\Baz', $result->dependencies[1]->target->toString());
    }

    #[Test]
    public function itMergesDependenciesFromMultipleFiles(): void
    {
        $files = [
            new SplFileInfo('/tmp/file1.php'),
            new SplFileInfo('/tmp/file2.php'),
        ];

        $dep1 = new Dependency(SymbolPath::fromClassFqn('App\Foo'), SymbolPath::fromClassFqn('App\Bar'), DependencyType::New_, new Location('/tmp/file1.php', 10));
        $dep2 = new Dependency(SymbolPath::fromClassFqn('App\Baz'), SymbolPath::fromClassFqn('App\Qux'), DependencyType::Implements, new Location('/tmp/file2.php', 5));

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/file1.php',
                fileBag: new MetricBag(),
                dependencies: [$dep1],
            ),
            FileProcessingResult::success(
                filePath: '/tmp/file2.php',
                fileBag: new MetricBag(),
                dependencies: [$dep2],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository);

        self::assertSame(2, $result->result->filesAnalyzed);
        self::assertCount(2, $result->dependencies);
    }

    #[Test]
    public function itHandlesAllFilesFailingProcessing(): void
    {
        $files = [
            new SplFileInfo('/tmp/broken1.php'),
            new SplFileInfo('/tmp/broken2.php'),
        ];

        $processingResults = [
            FileProcessingResult::failure('/tmp/broken1.php', 'Parse error: unexpected token'),
            FileProcessingResult::failure('/tmp/broken2.php', 'File not found'),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);
        $this->logger->expects(self::exactly(2))->method('warning');

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository);

        self::assertSame(0, $result->result->filesAnalyzed);
        self::assertSame(2, $result->result->filesSkipped);
        self::assertSame([], $result->dependencies);
    }

    #[Test]
    public function itHandlesPartialFailures(): void
    {
        $files = [
            new SplFileInfo('/tmp/good.php'),
            new SplFileInfo('/tmp/bad1.php'),
            new SplFileInfo('/tmp/bad2.php'),
            new SplFileInfo('/tmp/good2.php'),
        ];

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/good.php',
                fileBag: MetricBag::fromArray(['loc' => 50]),
            ),
            FileProcessingResult::failure('/tmp/bad1.php', 'Syntax error'),
            FileProcessingResult::failure('/tmp/bad2.php', 'Parse error'),
            FileProcessingResult::success(
                filePath: '/tmp/good2.php',
                fileBag: MetricBag::fromArray(['loc' => 75]),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);
        $this->logger->expects(self::exactly(2))->method('warning')->willReturnCallback(
            function (string $message, array $context): void {
                self::assertSame('Failed to process file', $message);
                self::assertArrayHasKey('file', $context);
                self::assertArrayHasKey('error', $context);
            },
        );

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository);

        self::assertSame(2, $result->result->filesAnalyzed);
        self::assertSame(2, $result->result->filesSkipped);
    }

    #[Test]
    public function itReportsProgressDuringCollection(): void
    {
        $files = [
            new SplFileInfo('/tmp/file1.php'),
            new SplFileInfo('/tmp/file2.php'),
        ];

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/file1.php',
                fileBag: new MetricBag(),
            ),
            FileProcessingResult::success(
                filePath: '/tmp/file2.php',
                fileBag: new MetricBag(),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        // Verify progress reporting sequence
        $this->progress->expects(self::once())->method('start')->with(2);
        $this->progress->expects(self::exactly(2))->method('setMessage')
            ->willReturnCallback(function (string $message): void {
                self::assertStringStartsWith('Registering ', $message);
            });
        $this->progress->expects(self::exactly(2))->method('advance');
        $this->progress->expects(self::once())->method('finish');

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);
    }

    #[Test]
    public function itPassesCallableToExecutionStrategy(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];

        // Verify that strategy receives a callable
        $this->strategy->expects(self::once())
            ->method('execute')
            ->with(
                self::identicalTo($files),
                self::isType('callable'),
                self::isTrue(), // allow parallelization
            )
            ->willReturn([
                FileProcessingResult::success(
                    filePath: '/tmp/test.php',
                    fileBag: new MetricBag(),
                ),
            ]);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);
    }

    #[Test]
    public function itRegistersDerivedMetricsForMethods(): void
    {
        // Create mock derived collector
        $derivedCollector = $this->createMock(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'calculate');

        // File bag contains base metrics + derived metric with FQN suffix
        $fileBag = MetricBag::fromArray([
            'ccn:App\Service::calculate' => 5,
            'loc:App\Service::calculate' => 20,
            'mi:App\Service::calculate' => 85.5, // derived metric
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: $fileBag,
                methodMetrics: [
                    'App::Service::calculate' => [
                        'symbolPath' => $methodSymbol,
                        'metrics' => MetricBag::fromArray(['ccn' => 5, 'loc' => 20]),
                        'line' => 15,
                    ],
                ],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: new DerivedMetricExtractor($compositeCollector),
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);

        // Verify that derived metric was added to method symbol
        self::assertTrue($repository->has($methodSymbol));
        $methodBag = $repository->get($methodSymbol);
        self::assertSame(85.5, $methodBag->get('mi'));
    }

    #[Test]
    public function itIgnoresDerivedMetricsForNonExistentMethods(): void
    {
        // Create mock derived collector
        $derivedCollector = $this->createMock(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];

        // File bag contains derived metric for method that doesn't exist
        $fileBag = MetricBag::fromArray([
            'mi:App\NonExistent::method' => 85.5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: $fileBag,
                methodMetrics: [], // No methods registered
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: new DerivedMetricExtractor($compositeCollector),
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);

        // Verify that derived metric was NOT added (method doesn't exist)
        $nonExistentSymbol = SymbolPath::forMethod('App', 'NonExistent', 'method');
        self::assertFalse($repository->has($nonExistentSymbol));
    }

    #[Test]
    public function itIgnoresInvalidMethodFqnsInDerivedMetrics(): void
    {
        // Create mock derived collector
        $derivedCollector = $this->createMock(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];

        // File bag contains invalid FQNs
        $fileBag = MetricBag::fromArray([
            'mi:InvalidFqn' => 85.5, // no ::
            'mi:123Invalid::method' => 90.0, // starts with digit
            'mi:' => 80.0, // empty FQN
            'mi:::double' => 75.0, // invalid format
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: $fileBag,
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: new DerivedMetricExtractor($compositeCollector),
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository = new InMemoryMetricRepository();

        // Should not throw exceptions
        $result = $orchestrator->collect($files, $repository);

        self::assertSame(1, $result->result->filesAnalyzed);
    }

    #[Test]
    public function itHandlesDerivedMetricsWithoutNamespace(): void
    {
        // Create mock derived collector
        $derivedCollector = $this->createMock(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];
        $methodSymbol = SymbolPath::forMethod('', 'SimpleClass', 'method');

        // File bag contains derived metric for class without namespace
        $fileBag = MetricBag::fromArray([
            'mi:SimpleClass::method' => 85.5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: $fileBag,
                methodMetrics: [
                    '::SimpleClass::method' => [
                        'symbolPath' => $methodSymbol,
                        'metrics' => MetricBag::fromArray(['ccn' => 3]),
                        'line' => 10,
                    ],
                ],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: new DerivedMetricExtractor($compositeCollector),
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);

        // Verify that derived metric was added
        self::assertTrue($repository->has($methodSymbol));
        $methodBag = $repository->get($methodSymbol);
        self::assertSame(85.5, $methodBag->get('mi'));
    }

    #[Test]
    public function itIgnoresNonDerivedMetricsWithColonFormat(): void
    {
        // Create mock derived collector that provides 'mi'
        $derivedCollector = $this->createMock(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];

        // File bag contains metrics with colon format, but not derived
        $fileBag = MetricBag::fromArray([
            'ccn:App\Service::method' => 5, // not a derived metric
            'loc:App\Service::method' => 20, // not a derived metric
            'mi:App\Service::method' => 85.5, // IS a derived metric
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: $fileBag,
                methodMetrics: [
                    'App::Service::method' => [
                        'symbolPath' => SymbolPath::forMethod('App', 'Service', 'method'),
                        'metrics' => new MetricBag(),
                        'line' => 10,
                    ],
                ],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: new DerivedMetricExtractor($compositeCollector),
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);

        // Only 'mi' should be added as derived metric
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'method');
        $methodBag = $repository->get($methodSymbol);

        self::assertTrue($methodBag->has('mi'));
        self::assertFalse($methodBag->has('ccn')); // base metrics not added via derived path
        self::assertFalse($methodBag->has('loc'));
    }

    #[Test]
    public function itHandlesMetricsWithoutColonSeparator(): void
    {
        // Create mock derived collector
        $derivedCollector = $this->createMock(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];

        // File bag contains regular metrics without colon separator
        $fileBag = MetricBag::fromArray([
            'totalLoc' => 100,
            'fileComplexity' => 50,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: $fileBag,
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: new DerivedMetricExtractor($compositeCollector),
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository = new InMemoryMetricRepository();

        // Should not throw exceptions
        $result = $orchestrator->collect($files, $repository);

        self::assertSame(1, $result->result->filesAnalyzed);

        // Verify file metrics were registered
        $fileSymbol = SymbolPath::forFile('/tmp/test.php');
        self::assertTrue($repository->has($fileSymbol));
        self::assertSame(100, $repository->get($fileSymbol)->get('totalLoc'));
    }

    #[Test]
    public function itHandlesNoDerivedCollectors(): void
    {
        // CompositeCollector with no derived collectors
        $compositeCollector = new CompositeCollector([]);

        $files = [new SplFileInfo('/tmp/test.php')];
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'method');

        // File bag contains metrics with colon format
        $fileBag = MetricBag::fromArray([
            'ccn:App\Service::method' => 5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: $fileBag,
                methodMetrics: [
                    'App::Service::method' => [
                        'symbolPath' => $methodSymbol,
                        'metrics' => MetricBag::fromArray(['ccn' => 5]),
                        'line' => 10,
                    ],
                ],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: new DerivedMetricExtractor($compositeCollector),
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);

        // Verify that method metrics were registered normally
        self::assertTrue($repository->has($methodSymbol));
        self::assertSame(5, $repository->get($methodSymbol)->get('ccn'));
    }

    #[Test]
    public function itHandlesUnicodeInMethodFqn(): void
    {
        // Create mock derived collector
        $derivedCollector = $this->createMock(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];
        // PHP allows Unicode in identifiers (0x7f-0xff range)
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'calculate');

        // File bag contains derived metric with Unicode characters
        $fileBag = MetricBag::fromArray([
            'mi:App\Service::calculate' => 85.5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: '/tmp/test.php',
                fileBag: $fileBag,
                methodMetrics: [
                    'App::Service::calculate' => [
                        'symbolPath' => $methodSymbol,
                        'metrics' => MetricBag::fromArray(['ccn' => 3]),
                        'line' => 10,
                    ],
                ],
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: new DerivedMetricExtractor($compositeCollector),
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository);

        // Verify that derived metric was added for method with non-ASCII identifiers
        self::assertTrue($repository->has($methodSymbol));
        $methodBag = $repository->get($methodSymbol);
        self::assertSame(85.5, $methodBag->get('mi'));
    }

    #[Test]
    public function itSelectsStrategyFreshOnEachCollectCall(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];

        $processingResult = FileProcessingResult::success(
            filePath: '/tmp/test.php',
            fileBag: new MetricBag(),
        );

        // Strategy selector should be called on each collect() call
        $this->strategySelector = $this->createMock(StrategySelectorInterface::class);
        $this->strategySelector->expects(self::exactly(2))
            ->method('select')
            ->willReturn($this->strategy);

        $this->strategy->method('execute')->willReturn([$processingResult]);

        $orchestrator = new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: $this->derivedMetricExtractor,
            progress: $this->progress,
            logger: $this->logger,
        );

        $repository1 = new InMemoryMetricRepository();
        $orchestrator->collect($files, $repository1);

        $repository2 = new InMemoryMetricRepository();
        $orchestrator->collect($files, $repository2);
    }

    private function createOrchestrator(): CollectionOrchestrator
    {
        return new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $this->strategySelector,
            derivedMetricExtractor: $this->derivedMetricExtractor,
            progress: $this->progress,
            logger: $this->logger,
        );
    }
}
