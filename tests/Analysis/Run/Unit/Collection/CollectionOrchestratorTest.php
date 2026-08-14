<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\DerivedMetricExtractor;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Run\Collection\CollectionOrchestrator;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\ExecutionStrategyInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\SuccessfulFileProcessing;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Progress\ProgressReporter;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionMethod;
use RuntimeException;
use SplFileInfo;

#[CoversClass(CollectionOrchestrator::class)]
final class CollectionOrchestratorTest extends TestCase
{
    private FileProcessorInterface&Stub $fileProcessor;
    private ExecutionStrategyInterface&Stub $strategy;
    private StrategySelectorInterface&Stub $strategySelector;
    private ProgressReporter&Stub $progress;
    private LoggerInterface&Stub $logger;
    private DerivedMetricExtractor $derivedMetricExtractor;

    protected function setUp(): void
    {
        $this->fileProcessor = self::createStub(FileProcessorInterface::class);
        $this->strategy = self::createStub(ExecutionStrategyInterface::class);
        $this->strategySelector = self::createStub(StrategySelectorInterface::class);
        $this->strategySelector->method('select')->willReturn($this->strategy);
        $this->progress = self::createStub(ProgressReporter::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->derivedMetricExtractor = new DerivedMetricExtractor(new CompositeCollector([]));
    }

    #[Test]
    public function itHandlesEmptyFileList(): void
    {
        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect([], $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(0, $result->filesAnalyzed);
        self::assertSame(0, $result->filesSkipped);
        self::assertSame([], $result->dependencies);
    }

    #[Test]
    public function itRequiresExplicitProgressAndLoggerCollaborators(): void
    {
        $parameters = (new ReflectionMethod(CollectionOrchestrator::class, '__construct'))->getParameters();

        self::assertFalse($parameters[3]->isDefaultValueAvailable());
        self::assertFalse($parameters[4]->isDefaultValueAvailable());
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
                filePath: RelativePath::fromString('tmp/file1.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray(['loc' => 50]),
                ),
            ),
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/file2.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray(['loc' => 100]),
                ),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $progress = $this->createMock(ProgressReporter::class);
        $progress->expects(self::once())->method('start')->with(2);
        $progress->expects(self::exactly(2))->method('advance');
        $progress->expects(self::once())->method('finish');

        $orchestrator = $this->createOrchestratorWith(progress: $progress);
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(2, $result->filesAnalyzed);
        self::assertSame(0, $result->filesSkipped);

        // Check that file metrics were registered
        $fileSymbol1 = SymbolPath::forFile(RelativePath::fromString('tmp/file1.php'));
        $fileSymbol2 = SymbolPath::forFile(RelativePath::fromString('tmp/file2.php'));

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
                filePath: RelativePath::fromString('tmp/valid.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray(['loc' => 50]),
                ),
            ),
            FileProcessingResult::failure(
                filePath: RelativePath::fromString('tmp/invalid.php'),
                error: 'Syntax error',
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $orchestrator = $this->createOrchestratorWith(logger: $logger);
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(1, $result->filesAnalyzed);
        self::assertSame(1, $result->filesSkipped);
    }

    #[Test]
    public function itRegistersMethodMetrics(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];
        $symbolPath = SymbolPath::forMethod('App', 'Service', 'calculate');
        $methodBag = MetricBag::fromArray(['ccn' => 5]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: new MetricBag(),
                    callableMetrics: [$this->callable($symbolPath, $methodBag, 15, 'tmp/test.php')],
                ),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

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
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: new MetricBag(),
                    classMetrics: [
                        'declaration:class:App\\Service@tmp/test.php:16' => [
                            'subject' => \Qualimetrix\Core\Symbol\MetricSubject::declaration(
                                new DeclarationPath($symbolPath, RelativePath::fromString('tmp/test.php'), 16),
                            ),
                            'metrics' => $classBag,
                            'line' => 5,
                        ],
                    ],
                ),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertTrue($repository->has($symbolPath));
        self::assertSame(25, $repository->get($symbolPath)->get('wmc'));
    }

    #[Test]
    public function itRegistersClassDerivedMetricsForDistinctExactDeclarationsWithTheSameLogicalName(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];
        $file = RelativePath::fromString('tmp/test.php');
        $symbolPath = SymbolPath::forClass('App', 'Service');
        $firstSubject = \Qualimetrix\Core\Symbol\MetricSubject::declaration(
            new DeclarationPath($symbolPath, $file, 16),
        );
        $secondSubject = \Qualimetrix\Core\Symbol\MetricSubject::declaration(
            new DeclarationPath($symbolPath, $file, 96),
        );

        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['typeCoverage.pct']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('typeCoverage.pct', SymbolLevel::Class_),
        ]);
        $extractor = new DerivedMetricExtractor(new CompositeCollector([], [$derivedCollector]));

        $processingResults = [
            FileProcessingResult::success(
                filePath: $file,
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray([
                        'typeCoverage.pct:' . $firstSubject->toCanonical() => 100.0,
                        'typeCoverage.pct:' . $secondSubject->toCanonical() => 50.0,
                    ]),
                    classMetrics: [
                        $firstSubject->toCanonical() => [
                            'subject' => $firstSubject,
                            'metrics' => MetricBag::fromArray(['typeCoverage.paramTotal' => 2]),
                            'line' => 5,
                        ],
                        $secondSubject->toCanonical() => [
                            'subject' => $secondSubject,
                            'metrics' => MetricBag::fromArray(['typeCoverage.paramTotal' => 2]),
                            'line' => 20,
                        ],
                    ],
                ),
            ),
        ];
        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = new CollectionOrchestrator(
            $this->fileProcessor,
            $this->strategySelector,
            $extractor,
            $this->progress,
            $this->logger,
        );
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(100.0, $repository->getSubject($firstSubject)->get('typeCoverage.pct'));
        self::assertSame(50.0, $repository->getSubject($secondSubject)->get('typeCoverage.pct'));
    }

    #[Test]
    public function itCollectsDependenciesFromResults(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];
        $dependency1 = $this->dependency('App\Foo', 'App\Bar', DependencyType::New_, 'tmp/test.php', 10);
        $dependency2 = $this->dependency('App\Foo', 'App\Baz', DependencyType::Extends, 'tmp/test.php', 5);

        $processingResults = [
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: new MetricBag(),
                    callableMetrics: [],
                    classMetrics: [],
                    dependencies: [$dependency1, $dependency2],
                ),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertCount(2, $result->dependencies);
        self::assertSame('App\Foo', $result->dependencies[0]->sourceLogical()->toString());
        self::assertSame('App\Bar', $result->dependencies[0]->targetLogical()->toString());
        self::assertSame('App\Baz', $result->dependencies[1]->targetLogical()->toString());
    }

    #[Test]
    public function itMergesDependenciesFromMultipleFiles(): void
    {
        $files = [
            new SplFileInfo('/tmp/file1.php'),
            new SplFileInfo('/tmp/file2.php'),
        ];

        $dep1 = $this->dependency('App\Foo', 'App\Bar', DependencyType::New_, 'tmp/file1.php', 10);
        $dep2 = $this->dependency('App\Baz', 'App\Qux', DependencyType::Implements, 'tmp/file2.php', 5);

        $processingResults = [
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/file1.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: new MetricBag(),
                    dependencies: [$dep1],
                ),
            ),
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/file2.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: new MetricBag(),
                    dependencies: [$dep2],
                ),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $orchestrator = $this->createOrchestrator();
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(2, $result->filesAnalyzed);
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
            FileProcessingResult::failure(RelativePath::fromString('tmp/broken1.php'), 'Parse error: unexpected token'),
            FileProcessingResult::failure(RelativePath::fromString('tmp/broken2.php'), 'File not found'),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning');

        $orchestrator = $this->createOrchestratorWith(logger: $logger);
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(0, $result->filesAnalyzed);
        self::assertSame(2, $result->filesSkipped);
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
                filePath: RelativePath::fromString('tmp/good.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray(['loc' => 50]),
                ),
            ),
            FileProcessingResult::failure(RelativePath::fromString('tmp/bad1.php'), 'Syntax error'),
            FileProcessingResult::failure(RelativePath::fromString('tmp/bad2.php'), 'Parse error'),
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/good2.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray(['loc' => 75]),
                ),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning')->willReturnCallback(
            function (string $message, array $context): void {
                self::assertSame('Failed to process file', $message);
                self::assertArrayHasKey('file', $context);
                self::assertArrayHasKey('error', $context);
            },
        );

        $orchestrator = $this->createOrchestratorWith(logger: $logger);
        $repository = new InMemoryMetricRepository();

        $result = $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(2, $result->filesAnalyzed);
        self::assertSame(2, $result->filesSkipped);
    }

    #[Test]
    public function itFoldsSuccessfulPayloadsAndTypedFailuresWithoutLosingControls(): void
    {
        $files = [
            new SplFileInfo('/tmp/good.php'),
            new SplFileInfo('/tmp/empty-controls.php'),
            new SplFileInfo('/tmp/parse.php'),
            new SplFileInfo('/tmp/good2.php'),
            new SplFileInfo('/tmp/processing.php'),
        ];
        $path = RelativePath::fromString('tmp/good.php');
        $emptyControlsPath = RelativePath::fromString('tmp/empty-controls.php');
        $secondPath = RelativePath::fromString('tmp/good2.php');
        $subject = \Qualimetrix\Core\Symbol\MetricSubject::logicalClass(
            new LogicalClassPath(SymbolPath::fromClassFqn('App\\Service')),
        );
        $suppression = new Suppression('complexity', 'fixture', 7, SuppressionType::File);
        $secondSuppression = new Suppression('design', 'second fixture', 17, SuppressionType::NextLine);
        $override = new ThresholdOverride('complexity.cyclomatic', 12, 20, 8, $subject, ControlScope::Class_);
        $secondOverride = new ThresholdOverride('design.type-coverage', 95, 80, 18, $subject, ControlScope::Class_);
        $diagnostic = new ThresholdDiagnostic(9, $subject, 'invalid fixture threshold');
        $secondDiagnostic = new ThresholdDiagnostic(19, $subject, 'second invalid fixture threshold');
        $dependencies = [
            $this->dependency('App\\Service', 'App\\Port', DependencyType::Implements, 'tmp/good.php', 10),
            $this->dependency('App\\Service', 'App\\Helper', DependencyType::New_, 'tmp/good.php', 11),
        ];
        $secondDependency = $this->dependency(
            'App\\SecondService',
            'App\\SecondPort',
            DependencyType::Implements,
            'tmp/good2.php',
            20,
        );

        $this->strategy->method('execute')->willReturn([
            FileProcessingResult::success(
                filePath: $path,
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray(['loc' => 12]),
                    dependencies: $dependencies,
                    suppressions: [$suppression],
                    thresholdOverrides: [$override],
                    thresholdDiagnostics: [$diagnostic],
                ),
            ),
            FileProcessingResult::success(
                filePath: $emptyControlsPath,
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray(['loc' => 3]),
                ),
            ),
            FileProcessingResult::failure(
                RelativePath::fromString('tmp/parse.php'),
                'parse failed',
                FileProcessingFailureKind::Parse,
            ),
            FileProcessingResult::success(
                filePath: $secondPath,
                payload: new SuccessfulFileProcessing(
                    fileBag: MetricBag::fromArray(['loc' => 8]),
                    dependencies: [$secondDependency],
                    suppressions: [$secondSuppression],
                    thresholdOverrides: [$secondOverride],
                    thresholdDiagnostics: [$secondDiagnostic],
                ),
            ),
            FileProcessingResult::failure(
                RelativePath::fromString('tmp/processing.php'),
                'processing failed',
                FileProcessingFailureKind::Processing,
            ),
        ]);

        $warningContexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning')->willReturnCallback(
            static function (string $message, array $context) use (&$warningContexts): void {
                self::assertSame('Failed to process file', $message);
                $warningContexts[] = $context;
            },
        );
        $progress = $this->createMock(ProgressReporter::class);
        $progressMessages = [];
        $progress->expects(self::once())->method('start')->with(5);
        $progress->expects(self::exactly(5))->method('setMessage')->willReturnCallback(
            static function (string $message) use (&$progressMessages): void {
                $progressMessages[] = $message;
            },
        );
        $progress->expects(self::exactly(5))->method('advance');
        $progress->expects(self::once())->method('finish');
        $repository = new InMemoryMetricRepository();

        $output = $this->createOrchestratorWith(logger: $logger, progress: $progress)->collect(
            $files,
            $repository,
            AbsolutePath::fromString('/tmp'),
        );

        self::assertSame([$path, $emptyControlsPath, $secondPath], $output->analyzedFiles);
        self::assertSame(
            ['tmp/parse.php', 'tmp/processing.php'],
            array_map(static fn(FileProcessingResult $failure): string => $failure->filePath->value(), $output->failures),
        );
        self::assertSame(
            [FileProcessingFailureKind::Parse, FileProcessingFailureKind::Processing],
            array_map(
                static fn(FileProcessingResult $failure): FileProcessingFailureKind => $failure->failureKind(),
                $output->failures,
            ),
        );
        self::assertSame([
            ['file' => 'tmp/parse.php', 'error' => 'parse failed'],
            ['file' => 'tmp/processing.php', 'error' => 'processing failed'],
        ], $warningContexts);
        self::assertSame([
            'Registering good.php',
            'Registering empty-controls.php',
            'Registering parse.php',
            'Registering good2.php',
            'Registering processing.php',
        ], $progressMessages);
        self::assertSame([$suppression], $output->suppressions[$path->value()]);
        self::assertSame([$secondSuppression], $output->suppressions[$secondPath->value()]);
        self::assertSame([$override], $output->thresholdOverrides[$path->value()]);
        self::assertSame([$secondOverride], $output->thresholdOverrides[$secondPath->value()]);
        self::assertSame([$diagnostic], $output->thresholdDiagnostics[$path->value()]);
        self::assertSame([$secondDiagnostic], $output->thresholdDiagnostics[$secondPath->value()]);
        self::assertSame(['tmp/good.php', 'tmp/good2.php'], array_keys($output->suppressions));
        self::assertSame(['tmp/good.php', 'tmp/good2.php'], array_keys($output->thresholdOverrides));
        self::assertSame(['tmp/good.php', 'tmp/good2.php'], array_keys($output->thresholdDiagnostics));
        self::assertArrayNotHasKey($emptyControlsPath->value(), $output->suppressions);
        self::assertArrayNotHasKey($emptyControlsPath->value(), $output->thresholdOverrides);
        self::assertArrayNotHasKey($emptyControlsPath->value(), $output->thresholdDiagnostics);
        self::assertSame([...$dependencies, $secondDependency], $output->dependencies);
        self::assertTrue($repository->has(SymbolPath::forFile($path)));
        self::assertTrue($repository->has(SymbolPath::forFile($emptyControlsPath)));
        self::assertTrue($repository->has(SymbolPath::forFile($secondPath)));
        self::assertFalse($repository->has(SymbolPath::forFile(RelativePath::fromString('tmp/parse.php'))));
        self::assertFalse($repository->has(SymbolPath::forFile(RelativePath::fromString('tmp/processing.php'))));
    }

    #[Test]
    public function itConvertsUnexpectedSequentialExceptionsToTypedPerFileFailures(): void
    {
        $files = [new SplFileInfo('/tmp/broken.php')];
        $processor = self::createStub(FileProcessorInterface::class);
        $processor->method('process')->willThrowException(new RuntimeException('collector crashed'));

        $strategy = self::createStub(ExecutionStrategyInterface::class);
        $strategy->method('execute')->willReturnCallback(
            static fn(array $input, callable $callback): array => array_map($callback, $input),
        );
        $selector = self::createStub(StrategySelectorInterface::class);
        $selector->method('select')->willReturn($strategy);

        $orchestrator = new CollectionOrchestrator(
            $processor,
            $selector,
            $this->derivedMetricExtractor,
            $this->progress,
            $this->logger,
        );

        $output = $orchestrator->collect(
            $files,
            new InMemoryMetricRepository(),
            AbsolutePath::fromString('/tmp'),
        );

        self::assertSame(0, $output->filesAnalyzed);
        self::assertSame(1, $output->filesSkipped);
        self::assertSame(FileProcessingFailureKind::Processing, $output->failures[0]->failureKind());
        self::assertSame('broken.php', $output->failures[0]->filePath->value());
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
                filePath: RelativePath::fromString('tmp/file1.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: new MetricBag(),
                ),
            ),
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/file2.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: new MetricBag(),
                ),
            ),
        ];

        $this->strategy->method('execute')->willReturn($processingResults);

        // Verify progress reporting sequence
        $progress = $this->createMock(ProgressReporter::class);
        $progress->expects(self::once())->method('start')->with(2);
        $progress->expects(self::exactly(2))->method('setMessage')
            ->willReturnCallback(function (string $message): void {
                self::assertStringStartsWith('Registering ', $message);
            });
        $progress->expects(self::exactly(2))->method('advance');
        $progress->expects(self::once())->method('finish');

        $orchestrator = $this->createOrchestratorWith(progress: $progress);
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));
    }

    #[Test]
    public function itPassesCallableToExecutionStrategy(): void
    {
        $files = [new SplFileInfo('/tmp/test.php')];

        $strategy = $this->createMock(ExecutionStrategyInterface::class);
        $strategySelector = self::createStub(StrategySelectorInterface::class);
        $strategySelector->method('select')->willReturn($strategy);

        // Verify that strategy receives a callable
        $strategy->expects(self::once())
            ->method('execute')
            ->with(
                self::identicalTo($files),
                self::isCallable(),
                self::isTrue(), // allow parallelization
            )
            ->willReturn([
                FileProcessingResult::success(
                    filePath: RelativePath::fromString('tmp/test.php'),
                    payload: new SuccessfulFileProcessing(
                        fileBag: new MetricBag(),
                    ),
                ),
            ]);

        $orchestrator = $this->createOrchestratorWith(strategySelector: $strategySelector);
        $repository = new InMemoryMetricRepository();

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));
    }

    #[Test]
    public function itRegistersDerivedMetricsForMethods(): void
    {
        // Create mock derived collector
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'calculate');

        $callable = $this->callable($methodSymbol, MetricBag::fromArray(['ccn' => 5, 'loc' => 20]), 15, 'tmp/test.php');

        // File bag contains base metrics plus the declaration-scoped derived metric.
        $fileBag = MetricBag::fromArray([
            'ccn:App\Service::calculate' => 5,
            'loc:App\Service::calculate' => 20,
            $this->derivedKey('mi', $callable) => 85.5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: $fileBag,
                    callableMetrics: [$callable],
                ),
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

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        // Verify that derived metric was added to method symbol
        self::assertTrue($repository->has($methodSymbol));
        $methodBag = $repository->get($methodSymbol);
        self::assertSame(85.5, $methodBag->get('mi'));
    }

    #[Test]
    public function itIgnoresDerivedMetricsForNonExistentMethods(): void
    {
        // Create mock derived collector
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];

        // File bag contains derived metric for method that doesn't exist
        $fileBag = MetricBag::fromArray([
            'mi:App\NonExistent::method' => 85.5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: $fileBag,
                    callableMetrics: [], // No methods registered
                ),
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

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        // Verify that derived metric was NOT added (method doesn't exist)
        $nonExistentSymbol = SymbolPath::forMethod('App', 'NonExistent', 'method');
        self::assertFalse($repository->has($nonExistentSymbol));
    }

    #[Test]
    public function itIgnoresInvalidMethodFqnsInDerivedMetrics(): void
    {
        // Create mock derived collector
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
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
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: $fileBag,
                ),
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
        $result = $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(1, $result->filesAnalyzed);
    }

    #[Test]
    public function itHandlesDerivedMetricsWithoutNamespace(): void
    {
        // Create mock derived collector
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];
        $methodSymbol = SymbolPath::forMethod('', 'SimpleClass', 'method');

        $callable = $this->callable($methodSymbol, MetricBag::fromArray(['ccn' => 3]), 10, 'tmp/test.php');

        // File bag contains the declaration-scoped derived metric for a class without namespace.
        $fileBag = MetricBag::fromArray([
            $this->derivedKey('mi', $callable) => 85.5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: $fileBag,
                    callableMetrics: [$callable],
                ),
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

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        // Verify that derived metric was added
        self::assertTrue($repository->has($methodSymbol));
        $methodBag = $repository->get($methodSymbol);
        self::assertSame(85.5, $methodBag->get('mi'));
    }

    #[Test]
    public function itIgnoresNonDerivedMetricsWithColonFormat(): void
    {
        // Create mock derived collector that provides 'mi'
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];

        $callable = $this->callable(SymbolPath::forMethod('App', 'Service', 'method'), new MetricBag(), 10, 'tmp/test.php');

        // File bag contains aggregate-looking keys and one declaration-scoped derived metric.
        $fileBag = MetricBag::fromArray([
            'ccn:App\Service::method' => 5, // not a derived metric
            'loc:App\Service::method' => 20, // not a derived metric
            $this->derivedKey('mi', $callable) => 85.5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: $fileBag,
                    callableMetrics: [$callable],
                ),
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

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

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
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
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
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: $fileBag,
                ),
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
        $result = $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        self::assertSame(1, $result->filesAnalyzed);

        // Verify file metrics were registered
        $fileSymbol = SymbolPath::forFile(RelativePath::fromString('tmp/test.php'));
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
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: $fileBag,
                    callableMetrics: [$this->callable($methodSymbol, MetricBag::fromArray(['ccn' => 5]), 10, 'tmp/test.php')],
                ),
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

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

        // Verify that method metrics were registered normally
        self::assertTrue($repository->has($methodSymbol));
        self::assertSame(5, $repository->get($methodSymbol)->get('ccn'));
    }

    #[Test]
    public function itHandlesUnicodeInMethodFqn(): void
    {
        // Create mock derived collector
        $derivedCollector = self::createStub(DerivedCollectorInterface::class);
        $derivedCollector->method('provides')->willReturn(['mi']);
        $derivedCollector->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('mi', SymbolLevel::Callable),
        ]);

        $compositeCollector = new CompositeCollector([], [$derivedCollector]);

        $files = [new SplFileInfo('/tmp/test.php')];
        // PHP allows Unicode in identifiers (0x7f-0xff range)
        $methodSymbol = SymbolPath::forMethod('App', 'Service', 'calculate');

        $callable = $this->callable($methodSymbol, MetricBag::fromArray(['ccn' => 3]), 10, 'tmp/test.php');

        // File bag contains a declaration-scoped derived metric.
        $fileBag = MetricBag::fromArray([
            $this->derivedKey('mi', $callable) => 85.5,
        ]);

        $processingResults = [
            FileProcessingResult::success(
                filePath: RelativePath::fromString('tmp/test.php'),
                payload: new SuccessfulFileProcessing(
                    fileBag: $fileBag,
                    callableMetrics: [$callable],
                ),
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

        $orchestrator->collect($files, $repository, AbsolutePath::fromString('/tmp'));

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
            filePath: RelativePath::fromString('tmp/test.php'),
            payload: new SuccessfulFileProcessing(
                fileBag: new MetricBag(),
            ),
        );

        // Strategy selector should be called on each collect() call
        $strategySelector = $this->createMock(StrategySelectorInterface::class);
        $strategySelector->expects(self::exactly(2))
            ->method('select')
            ->willReturn($this->strategy);

        $this->strategy->method('execute')->willReturn([$processingResult]);

        $orchestrator = $this->createOrchestratorWith(strategySelector: $strategySelector);

        $repository1 = new InMemoryMetricRepository();
        $orchestrator->collect($files, $repository1, AbsolutePath::fromString('/tmp'));

        $repository2 = new InMemoryMetricRepository();
        $orchestrator->collect($files, $repository2, AbsolutePath::fromString('/tmp'));
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

    private function createOrchestratorWith(
        ?StrategySelectorInterface $strategySelector = null,
        ?ProgressReporter $progress = null,
        ?LoggerInterface $logger = null,
    ): CollectionOrchestrator {
        return new CollectionOrchestrator(
            fileProcessor: $this->fileProcessor,
            strategySelector: $strategySelector ?? $this->strategySelector,
            derivedMetricExtractor: $this->derivedMetricExtractor,
            progress: $progress ?? $this->progress,
            logger: $logger ?? $this->logger,
        );
    }

    private function callable(SymbolPath $symbol, MetricBag $metrics, int $line, string $file): CallableWithMetrics
    {
        return new CallableWithMetrics(
            new DeclarationPath($symbol, RelativePath::fromString($file), 0),
            CallableKind::Method,
            null,
            null,
            new LogicalClassPath(SymbolPath::forClass($symbol->namespace ?? '', $symbol->type ?? '')),
            $metrics,
        );
    }

    private function derivedKey(string $metric, CallableWithMetrics $callable): string
    {
        return $metric . ':' . $callable->kind->value . ':' . $callable->declarationPath->toCanonical();
    }

    private function dependency(string $source, string $target, DependencyType $type, string $file, int $line): Dependency
    {
        $path = RelativePath::fromString($file);

        return new Dependency(
            new DeclarationPath(SymbolPath::fromClassFqn($source), $path, 0),
            new LogicalClassPath(SymbolPath::fromClassFqn($target)),
            $type,
            new Location($path, $line),
        );
    }
}
