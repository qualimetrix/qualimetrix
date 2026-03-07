<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Metric;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Violation\SymbolPath;

/**
 * Extracts derived method-level metrics from file-level MetricBag
 * and registers them as method symbols in the repository.
 *
 * Derived collectors store metrics with FQN suffix (e.g., "mi:Namespace\Class::method").
 * This class extracts those metrics and adds them to the corresponding method symbols.
 */
final readonly class DerivedMetricExtractor
{
    public function __construct(
        private CompositeCollector $compositeCollector,
    ) {}

    /**
     * Extracts derived method-level metrics from file-level MetricBag
     * and registers them as method symbols in the repository.
     */
    public function extract(
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
