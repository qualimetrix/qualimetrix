<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Metric;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Symbol\SymbolPath;

/**
 * Extracts derived metrics from file-level MetricBag
 * and registers them as symbols in the repository.
 *
 * Derived collectors store metrics with FQN suffix:
 * - Method-level: "mi:Namespace\Class::method"
 * - Class-level: "typeCoverage.pct:Namespace\Class"
 *
 * This class extracts those metrics and adds them to the corresponding symbols.
 */
final readonly class DerivedMetricExtractor
{
    public function __construct(
        private CompositeCollector $compositeCollector,
    ) {}

    /**
     * Extracts derived metrics from file-level MetricBag
     * and registers them as symbols in the repository.
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

        // Group derived metrics by FQN (method or class)
        $symbolMetrics = [];

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

            // Validate FQN format (method or class)
            if (!$this->isValidMethodFqn($fqn) && !$this->isValidClassFqn($fqn)) {
                continue;
            }

            if (!isset($symbolMetrics[$fqn])) {
                $symbolMetrics[$fqn] = new MetricBag();
            }

            $symbolMetrics[$fqn] = $symbolMetrics[$fqn]->with($metricName, $value);
        }

        // Add derived metrics to existing symbols
        foreach ($symbolMetrics as $fqn => $derivedBag) {
            $symbolPath = $this->resolveSymbolPath($fqn);

            // Only add if symbol exists (don't create new symbols)
            if ($repository->has($symbolPath)) {
                $repository->add($symbolPath, $derivedBag, $filePath, 0);
            }
        }
    }

    /**
     * Resolves a FQN string to a SymbolPath.
     *
     * Supports:
     * - Method FQN: "Namespace\Class::method" → SymbolPath::forMethod()
     * - Class FQN: "Namespace\Class" → SymbolPath::forClass()
     */
    private function resolveSymbolPath(string $fqn): SymbolPath
    {
        $doubleColonPos = strrpos($fqn, '::');

        if ($doubleColonPos !== false) {
            // Method FQN: Namespace\Class::method
            $classPath = substr($fqn, 0, $doubleColonPos);
            $methodName = substr($fqn, $doubleColonPos + 2);
            [$namespace, $className] = $this->splitClassPath($classPath);

            return SymbolPath::forMethod($namespace, $className, $methodName);
        }

        // Class FQN: Namespace\Class
        [$namespace, $className] = $this->splitClassPath($fqn);

        return SymbolPath::forClass($namespace, $className);
    }

    /**
     * Splits a fully-qualified class path into namespace and class name.
     *
     * @return array{0: string, 1: string} [namespace, className]
     */
    private function splitClassPath(string $classPath): array
    {
        $lastBackslashPos = strrpos($classPath, '\\');

        if ($lastBackslashPos === false) {
            return ['', $classPath];
        }

        return [
            substr($classPath, 0, $lastBackslashPos),
            substr($classPath, $lastBackslashPos + 1),
        ];
    }

    /**
     * Validates method FQN format: Namespace\Class::method
     */
    private function isValidMethodFqn(string $fqn): bool
    {
        return (bool) preg_match(
            '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*::[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/',
            $fqn,
        );
    }

    /**
     * Validates class FQN format: Namespace\Class or Class
     */
    private function isValidClassFqn(string $fqn): bool
    {
        if (str_contains($fqn, '::')) {
            return false;
        }

        return (bool) preg_match(
            '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*$/',
            $fqn,
        );
    }
}
