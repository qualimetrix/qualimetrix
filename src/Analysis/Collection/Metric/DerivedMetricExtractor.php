<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Metric;

use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

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
            foreach ($this->resolveCandidatePaths($fqn) as $symbolPath) {
                // Only add if symbol exists (don't create new symbols)
                if ($repository->has($symbolPath)) {
                    $repository->add($symbolPath, $derivedBag, $filePath, 0);

                    break;
                }
            }
        }
    }

    /**
     * Resolves a FQN string to candidate SymbolPaths.
     *
     * For method FQNs (contains "::"), returns a single method path.
     * For bare FQNs (no "::"), returns both class and function candidates,
     * since "App\Utils\helper" could be either a class or a standalone function.
     *
     * @return list<SymbolPath>
     */
    private function resolveCandidatePaths(string $fqn): array
    {
        $doubleColonPos = strrpos($fqn, '::');

        if ($doubleColonPos !== false) {
            // Method FQN: Namespace\Class::method — unambiguous
            $classPath = substr($fqn, 0, $doubleColonPos);
            $methodName = substr($fqn, $doubleColonPos + 2);
            [$namespace, $className] = $this->splitClassPath($classPath);

            return [SymbolPath::forMethod($namespace, $className, $methodName)];
        }

        // Bare FQN: could be class or standalone function
        [$namespace, $name] = $this->splitClassPath($fqn);

        return [
            SymbolPath::forClass($namespace, $name),
            SymbolPath::forGlobalFunction($namespace, $name),
        ];
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
