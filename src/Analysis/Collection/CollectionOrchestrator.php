<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use LogicException;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Collection\Metric\DerivedMetricExtractor;
use Qualimetrix\Analysis\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Core\Metric\ClassWithMetrics;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Progress\ProgressReporter;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;
use Throwable;

/**
 * Orchestrates the collection phase.
 *
 * Coordinates processing of multiple files using the execution strategy,
 * registers collected metrics in the repository, and handles derived metrics.
 */
final class CollectionOrchestrator implements CollectionOrchestratorInterface
{
    public function __construct(
        private readonly FileProcessorInterface $fileProcessor,
        private readonly StrategySelectorInterface $strategySelector,
        private readonly DerivedMetricExtractor $derivedMetricExtractor,
        private readonly ProgressReporter $progress,
        private readonly LoggerInterface $logger,
    ) {}

    public function collect(
        array $files,
        MetricRepositoryInterface $repository,
        AbsolutePath $projectRoot,
    ): CollectionPhaseOutput {
        if ($files === []) {
            return new CollectionPhaseOutput(new CollectionResult([], []), []);
        }

        // Lifts projectRoot into the sequential FileProcessor instance. The
        // parallel strategy ships its own FileProcessor through WorkerBootstrap,
        // which calls the same setter on the worker side.
        $this->fileProcessor->setProjectRoot($projectRoot);

        $profiler = ProfilerHolder::get();

        // Single-phase collection: metrics + dependencies in one AST traversal
        $this->progress->start(\count($files));

        $this->logger->debug('Collection: metrics + dependencies (single traversal)', [
            'files' => \count($files),
        ]);

        $profiler->start('collection.execute_strategy', 'collection');
        $results = $this->strategySelector->select()->execute(
            $files,
            fn(SplFileInfo $file): FileProcessingResult => $this->processSafely($file, $projectRoot),
            true, // Allow parallelization
        );
        $profiler->stop('collection.execute_strategy');

        $output = $this->foldResults($results, $repository);

        $this->progress->finish();

        return $output;
    }

    /**
     * @param iterable<FileProcessingResult> $results
     */
    private function foldResults(
        iterable $results,
        MetricRepositoryInterface $repository,
    ): CollectionPhaseOutput {
        $profiler = ProfilerHolder::get();
        $profiler->start('collection.register_results', 'collection');
        /** @var list<RelativePath> $analyzedFiles */
        $analyzedFiles = [];
        /** @var list<FileProcessingResult> $failures */
        $failures = [];
        /** @var list<Dependency> $allDependencies */
        $allDependencies = [];
        /** @var array<string, list<Suppression>> $allSuppressions */
        $allSuppressions = [];
        /** @var array<string, list<ThresholdOverride>> $allThresholdOverrides */
        $allThresholdOverrides = [];
        /** @var array<string, list<ThresholdDiagnostic>> $allThresholdDiagnostics */
        $allThresholdDiagnostics = [];

        foreach ($results as $result) {
            $filePathKey = $result->filePath->value();
            $this->progress->setMessage('Registering ' . basename($filePathKey));

            if ($result->isSuccessful()) {
                $this->registerResult($result, $repository);
                $analyzedFiles[] = $result->filePath;
                $data = $result->collectedData();

                array_push($allDependencies, ...$data->dependencies);
                if ($data->suppressions !== []) {
                    $allSuppressions[$filePathKey] = $data->suppressions;
                }
                if ($data->thresholdOverrides !== []) {
                    $allThresholdOverrides[$filePathKey] = $data->thresholdOverrides;
                }
                if ($data->thresholdDiagnostics !== []) {
                    $allThresholdDiagnostics[$filePathKey] = $data->thresholdDiagnostics;
                }
            } else {
                $this->logger->warning('Failed to process file', [
                    'file' => $filePathKey,
                    'error' => $result->processingFailure()->message,
                ]);
                $failures[] = $result;
            }

            $this->progress->advance();
        }
        $profiler->stop('collection.register_results');

        return new CollectionPhaseOutput(
            new CollectionResult(
                $analyzedFiles,
                $failures,
                $allSuppressions,
                $allThresholdOverrides,
                $allThresholdDiagnostics,
            ),
            $allDependencies,
        );
    }

    private function processSafely(SplFileInfo $file, AbsolutePath $projectRoot): FileProcessingResult
    {
        try {
            return $this->fileProcessor->process($file);
        } catch (Throwable $exception) {
            return FileProcessingResult::failure(
                PathFactory::bestEffortRelative($file->getPathname(), $projectRoot),
                $exception->getMessage(),
                FileProcessingFailureKind::Processing,
            );
        }
    }

    /**
     * Registers file processing result in repository.
     */
    private function registerResult(
        FileProcessingResult $result,
        MetricRepositoryInterface $repository,
    ): void {
        $data = $result->collectedData();

        // Store file-level metrics
        $filePath = $result->filePath;
        $fileSymbol = SymbolPath::forFile($filePath);
        $repository->add($fileSymbol, $data->fileBag, $filePath, 1);

        // Register exact callable declarations before any logical aggregation.
        foreach ($data->callableMetrics as $callable) {
            $repository->addCallable($callable);
        }

        // Register class-level metrics
        foreach ($data->classMetrics as $classData) {
            $repository->addSubject(
                $classData['subject'],
                $classData['metrics'],
                $filePath,
                $classData['line'],
            );
        }

        // Register source-owned namespace contributions before aggregation.
        foreach ($data->namespaceMetrics as $namespaceData) {
            $contribution = new \Qualimetrix\Core\Metric\MetricBag();
            $existing = $repository->get($namespaceData['symbolPath']);

            foreach ($namespaceData['metrics']->all() as $name => $value) {
                $contribution = $contribution
                    ->with($name, ($existing->get($name) ?? 0) + $value)
                    ->with($name . '.count', ($existing->get($name . '.count') ?? 0) + 1);
            }

            $repository->add(
                $namespaceData['symbolPath'],
                $contribution,
                $filePath,
                $namespaceData['line'],
            );
        }

        // Derived metrics keep their exact declaration subject, never an FQN key.
        $classes = array_map(
            static function (array $classData): ClassWithMetrics {
                $declarationPath = $classData['subject']->declarationPath();
                if ($declarationPath === null) {
                    throw new LogicException('Class metrics must use an exact declaration subject');
                }

                return new ClassWithMetrics($declarationPath, $classData['line'], $classData['metrics']);
            },
            array_values($data->classMetrics),
        );
        $this->derivedMetricExtractor->extract($repository, $data->fileBag, $data->callableMetrics, $filePath, $classes);
    }
}
