<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Collection;

use LogicException;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedMetricExtractorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Strategy\StrategySelectorInterface;
use Qualimetrix\Analysis\Run\Contract\Progress\ProgressReporterInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
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
        private readonly DerivedMetricExtractorInterface $derivedMetricExtractor,
        private readonly ProgressReporterInterface $progress,
        private readonly ProfilerInterface $profiler,
        private readonly LoggerInterface $logger,
    ) {}

    public function collect(
        array $files,
        MetricRepositoryInterface $repository,
        AbsolutePath $projectRoot,
    ): CollectionPhaseOutput {
        if ($files === []) {
            return new CollectionPhaseOutput([], []);
        }

        // Lifts projectRoot into the sequential FileProcessor instance. The
        // parallel strategy ships its own FileProcessor through WorkerBootstrap,
        // which calls the same setter on the worker side.
        $this->fileProcessor->setProjectRoot($projectRoot);

        $profiler = $this->profiler;

        // Single-phase collection: metrics + dependencies in one AST traversal
        $this->progress->start(\count($files));

        $this->logger->debug('Collection: metrics + dependencies (single traversal)', [
            'files' => \count($files),
        ]);

        $profiler->start('collection.execute_strategy', 'collection');
        $results = $this->strategySelector->select($projectRoot)->execute(
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
        $this->profiler->start('collection.register_results', 'collection');
        $fold = new CollectionPhaseFold();

        foreach ($results as $result) {
            $this->progress->setMessage('Registering ' . basename($result->filePath->value()));

            if ($result->isSuccessful()) {
                $this->registerResult($result, $repository);
            } else {
                $this->logger->warning('Failed to process file', [
                    'file' => $result->filePath->value(),
                    'error' => $result->error(),
                ]);
            }
            $fold->absorb($result);

            $this->progress->advance();
        }
        $this->profiler->stop('collection.register_results');

        return $fold->output();
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
        $filePath = $result->filePath;
        $repository->add(SymbolPath::forFile($filePath), $result->fileBag(), $filePath, 1);

        // Register exact callable declarations before any logical aggregation.
        foreach ($result->callableMetrics() as $callable) {
            $repository->addCallable($callable);
        }

        foreach ($result->classMetrics() as $classData) {
            $repository->addSubject($classData['subject'], $classData['metrics'], $filePath, $classData['line']);
        }

        self::registerNamespaceContributions($result, $repository);

        $this->derivedMetricExtractor->extract(
            $repository,
            $result->fileBag(),
            $result->callableMetrics(),
            $filePath,
            self::derivationClasses($result),
        );
    }

    /**
     * Source-owned namespace contributions are registered before aggregation
     * as running sums, each metric paired with a `.count` of contributing files.
     */
    private static function registerNamespaceContributions(
        FileProcessingResult $result,
        MetricRepositoryInterface $repository,
    ): void {
        foreach ($result->namespaceMetrics() as $namespaceData) {
            $existing = $repository->get($namespaceData['symbolPath']);
            $contribution = new MetricBag();

            foreach ($namespaceData['metrics']->all() as $name => $value) {
                $contribution = $contribution
                    ->with($name, ($existing->get($name) ?? 0) + $value)
                    ->with($name . '.count', ($existing->get($name . '.count') ?? 0) + 1);
            }

            $repository->add($namespaceData['symbolPath'], $contribution, $result->filePath, $namespaceData['line']);
        }
    }

    /**
     * Derived metrics keep their exact declaration subject, never an FQN key.
     *
     * @return list<ClassWithMetrics>
     */
    private static function derivationClasses(FileProcessingResult $result): array
    {
        $classes = [];
        foreach ($result->classMetrics() as $classData) {
            $declarationPath = $classData['subject']->declarationPath()
                ?? throw new LogicException('Class metrics must use an exact declaration subject');
            $classes[] = new ClassWithMetrics($declarationPath, $classData['start'], $classData['line'], $classData['metrics']);
        }

        return $classes;
    }
}
