<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection;

use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Collection\Strategy\ExecutionStrategyInterface;
use AiMessDetector\Analysis\Collection\Strategy\StrategySelectorInterface;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Progress\NullProgressReporter;
use AiMessDetector\Core\Progress\ProgressReporter;
use AiMessDetector\Core\Violation\SymbolPath;
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
    private ?ExecutionStrategyInterface $resolvedStrategy = null;

    public function __construct(
        private readonly FileProcessorInterface $fileProcessor,
        private readonly StrategySelectorInterface $strategySelector,
        private readonly CompositeCollector $compositeCollector,
        private readonly ProgressReporter $progress = new NullProgressReporter(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Gets the execution strategy (lazy-resolved on first use).
     *
     * Strategy is selected lazily to ensure configuration is fully loaded.
     */
    private function getStrategy(): ExecutionStrategyInterface
    {
        return $this->resolvedStrategy ??= $this->strategySelector->select();
    }

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
        $results = $this->getStrategy()->execute(
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

        foreach ($results as $result) {
            $this->progress->setMessage('Registering ' . basename($result->filePath));

            if ($result->success) {
                $this->registerResult($result, $repository);
                $filesAnalyzed++;

                // Collect dependencies from result
                foreach ($result->dependencies as $dependency) {
                    $allDependencies[] = $dependency;
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

        return new CollectionResult($filesAnalyzed, $filesSkipped, $allDependencies);
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
        $this->registerDerivedMethodMetrics($repository, $result->fileBag, $result->filePath);
    }

    /**
     * Extracts derived metrics from file bag and adds them to method symbols.
     *
     * Derived collectors store metrics with FQN suffix (e.g., "mi:Namespace\Class::method").
     * This method extracts those metrics and adds them to the corresponding method symbols.
     */
    private function registerDerivedMethodMetrics(
        MetricRepositoryInterface $repository,
        MetricBag $fileBag,
        string $filePath,
    ): void {
        // Get metric names provided by derived collectors
        $derivedMetricNames = [];
        foreach ($this->compositeCollector->getDerivedCollectors() as $derivedCollector) {
            foreach ($derivedCollector->provides() as $metricName) {
                $derivedMetricNames[$metricName] = true;
            }
        }

        if ($derivedMetricNames === []) {
            return;
        }

        // Group derived metrics by method FQN
        $methodMetrics = [];

        foreach ($fileBag->all() as $key => $value) {
            // Parse key format: metricName:fqn
            $colonPos = strpos($key, ':');

            if ($colonPos === false) {
                continue;
            }

            $metricName = substr($key, 0, $colonPos);

            // Only process derived metrics
            if (!isset($derivedMetricNames[$metricName])) {
                continue;
            }

            $fqn = substr($key, $colonPos + 1);

            // Validate FQN format (must be a valid method FQN)
            if (!$this->isValidMethodFqn($fqn)) {
                continue;
            }

            if (!isset($methodMetrics[$fqn])) {
                $methodMetrics[$fqn] = new MetricBag();
            }

            $methodMetrics[$fqn] = $methodMetrics[$fqn]->with($metricName, $value);
        }

        // Add derived metrics to existing method symbols
        foreach ($methodMetrics as $fqn => $derivedBag) {
            // Parse FQN: Namespace\Class::method
            $doubleColonPos = strrpos($fqn, '::');

            if ($doubleColonPos === false) {
                continue;
            }

            $classPath = substr($fqn, 0, $doubleColonPos);
            $methodName = substr($fqn, $doubleColonPos + 2);

            // Extract namespace and class from class path
            $lastBackslashPos = strrpos($classPath, '\\');

            if ($lastBackslashPos === false) {
                // No namespace
                $namespace = '';
                $className = $classPath;
            } else {
                $namespace = substr($classPath, 0, $lastBackslashPos);
                $className = substr($classPath, $lastBackslashPos + 1);
            }

            $symbolPath = SymbolPath::forMethod($namespace, $className, $methodName);

            // Only add if method symbol exists (don't create new symbols)
            if ($repository->has($symbolPath)) {
                $repository->add($symbolPath, $derivedBag, $filePath, 0);
            }
        }
    }

    /**
     * Validates method FQN format.
     *
     * Valid formats:
     * - Namespace\Class::method
     * - Class::method
     */
    private function isValidMethodFqn(string $fqn): bool
    {
        // Must contain ::
        if (!str_contains($fqn, '::')) {
            return false;
        }

        // Validate format: identifiers with optional namespace backslashes, then ::, then identifier
        // PHP identifier: starts with letter or underscore, followed by letters/digits/underscores
        // Also supports Unicode (0x7f-0xff range)
        return (bool) preg_match(
            '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*::[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/',
            $fqn,
        );
    }
}
