<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection;

use AiMessDetector\Analysis\Collection\Metric\DerivedMetricExtractor;
use AiMessDetector\Analysis\Collection\Strategy\StrategySelectorInterface;
use AiMessDetector\Baseline\Suppression\Suppression;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Progress\NullProgressReporter;
use AiMessDetector\Core\Progress\ProgressReporter;
use AiMessDetector\Core\Symbol\SymbolPath;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplFileInfo;

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
    ): CollectionResult {
        if ($files === []) {
            return new CollectionResult(0, 0, []);
        }

        $profiler = ProfilerHolder::get();

        // Single-phase collection: metrics + dependencies in one AST traversal
        $this->progress->start(\count($files));

        $this->logger->debug('Collection: metrics + dependencies (single traversal)', [
            'files' => \count($files),
        ]);

        $profiler->start('collection.execute_strategy', 'collection');
        $results = $this->strategySelector->select()->execute(
            $files,
            fn(SplFileInfo $file): FileProcessingResult => $this->fileProcessor->process($file),
            true, // Allow parallelization
        );
        $profiler->stop('collection.execute_strategy');

        // Register results in repository and collect dependencies
        $profiler->start('collection.register_results', 'collection');
        $filesAnalyzed = 0;
        $filesSkipped = 0;
        /** @var list<Dependency> $allDependencies */
        $allDependencies = [];
        /** @var array<string, list<Suppression>> $allSuppressions */
        $allSuppressions = [];

        foreach ($results as $result) {
            $this->progress->setMessage('Registering ' . basename($result->filePath));

            if ($result->success) {
                $this->registerResult($result, $repository);
                $filesAnalyzed++;

                // Collect dependencies from result
                foreach ($result->dependencies as $dependency) {
                    $allDependencies[] = $dependency;
                }

                // Collect suppressions from result
                if ($result->suppressions !== []) {
                    $allSuppressions[$result->filePath] = $result->suppressions;
                }
            } else {
                $this->logger->warning('Failed to process file', [
                    'file' => $result->filePath,
                    'error' => $result->error,
                ]);
                $filesSkipped++;
            }

            $this->progress->advance();
        }
        $profiler->stop('collection.register_results');

        $this->progress->finish();

        return new CollectionResult($filesAnalyzed, $filesSkipped, $allDependencies, $allSuppressions);
    }

    /**
     * Registers file processing result in repository.
     */
    private function registerResult(
        FileProcessingResult $result,
        MetricRepositoryInterface $repository,
    ): void {
        // Guaranteed non-null for successful results
        \assert($result->fileBag !== null);

        // Store file-level metrics
        $fileSymbol = SymbolPath::forFile($result->filePath);
        $repository->add($fileSymbol, $result->fileBag, $result->filePath, 1);

        // Register method-level metrics
        foreach ($result->methodMetrics as $methodData) {
            $repository->add(
                $methodData['symbolPath'],
                $methodData['metrics'],
                $result->filePath,
                $methodData['line'],
            );
        }

        // Register class-level metrics
        foreach ($result->classMetrics as $classData) {
            $repository->add(
                $classData['symbolPath'],
                $classData['metrics'],
                $result->filePath,
                $classData['line'],
            );
        }

        // Extract derived metrics (like MI) from file bag and add to method symbols
        $this->derivedMetricExtractor->extract($repository, $result->fileBag, $result->filePath);
    }
}
