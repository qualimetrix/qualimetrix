<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Metric;

use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;

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
     *
     * @param list<CallableWithMetrics> $callables
     */
    public function extract(
        MetricRepositoryInterface $repository,
        MetricBag $fileBag,
        array $callables,
        RelativePath $filePath,
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

        // Group derived metrics by exact declaration and callable kind.
        $symbolMetrics = [];

        foreach ($fileBag->all() as $key => $value) {
            // Parse key format: metricName:callable-kind:declaration-canonical.
            $colonPos = strpos($key, ':');

            if ($colonPos === false) {
                continue;
            }

            $metricName = substr($key, 0, $colonPos);

            // Only process derived metrics
            if (!isset($derivedMetricNames[$metricName])) {
                continue;
            }

            $declarationKey = substr($key, $colonPos + 1);
            if (!str_contains($declarationKey, ':declaration:')) {
                continue;
            }

            $symbolMetrics[$declarationKey] = ($symbolMetrics[$declarationKey] ?? new MetricBag())
                ->with($metricName, $value);
        }

        // Add derived metrics only to their exact callable declaration.
        foreach ($callables as $callable) {
            $key = $callable->kind->value . ':' . $callable->declarationPath->toCanonical();
            $derivedBag = $symbolMetrics[$key] ?? null;

            if ($derivedBag !== null && $repository->hasSubject(MetricSubject::declaration($callable->declarationPath))) {
                $repository->addSubject(
                    MetricSubject::declaration($callable->declarationPath),
                    $derivedBag,
                    $filePath,
                    $callable->declarationPath->startFilePos,
                );
            }
        }
    }

}
