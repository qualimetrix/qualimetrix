<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Collection\Metric\DerivedMetricExtractor;
use Qualimetrix\Analysis\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Progress\NullProgressReporter;
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
        private readonly ProgressReporter $progress = new NullProgressReporter(),
        private readonly LoggerInterface $logger = new NullLogger(),
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

        // Register results in repository and collect dependencies
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

                // Collect dependencies from result
                foreach ($data->dependencies as $dependency) {
                    $allDependencies[] = $dependency;
                }

                // Collect suppressions from result
                if ($data->suppressions !== []) {
                    $allSuppressions[$filePathKey] = $data->suppressions;
                }

                // Collect threshold overrides from result
                if ($data->thresholdOverrides !== []) {
                    $allThresholdOverrides[$filePathKey] = $data->thresholdOverrides;
                }

                // Collect threshold diagnostics from result
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

        $this->progress->finish();

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

        // Derived callable metrics keep their declaration subject, never an FQN key.
        $this->derivedMetricExtractor->extract($repository, $data->fileBag, $data->callableMetrics, $filePath);
    }
}
