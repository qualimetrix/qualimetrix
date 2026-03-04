<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Coupling;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Symbol\SymbolType;

/**
 * Computes abstractness metric for namespaces.
 *
 * Abstractness = (abstract classes + interfaces) / (classes + enums + traits)
 *
 * Range: [0, 1]
 * - 0: completely concrete (no abstractions)
 * - 1: completely abstract (all abstractions)
 *
 * Requires classCount, enumCount, traitCount, abstractClassCount, and interfaceCount aggregated at namespace level.
 */
final class AbstractnessCollector implements GlobalContextCollectorInterface
{
    public function getName(): string
    {
        return 'abstractness';
    }

    public function requires(): array
    {
        return ['classCount.sum', 'enumCount.sum', 'traitCount.sum', 'abstractClassCount.sum', 'interfaceCount.sum'];
    }

    public function provides(): array
    {
        return ['abstractness'];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: 'abstractness',
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [],
            ),
        ];
    }

    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        // Iterate over all namespaces and compute abstractness
        foreach ($repository->all(SymbolType::Namespace_) as $symbolInfo) {
            $nsPath = $symbolInfo->symbolPath;
            $metrics = $repository->get($nsPath);

            $classCount = $metrics->get('classCount.sum') ?? 0;
            $enumCount = $metrics->get('enumCount.sum') ?? 0;
            $traitCount = $metrics->get('traitCount.sum') ?? 0;
            $abstractCount = $metrics->get('abstractClassCount.sum') ?? 0;
            $interfaceCount = $metrics->get('interfaceCount.sum') ?? 0;

            $totalTypes = (int) $classCount + (int) $enumCount + (int) $traitCount;
            $totalAbstractions = (int) $abstractCount + (int) $interfaceCount;

            $abstractness = $this->computeAbstractness($totalTypes, $totalAbstractions);

            $newMetrics = (new MetricBag())->with('abstractness', $abstractness);

            $repository->add($nsPath, $newMetrics, '', null);
        }
    }

    /**
     * Computes abstractness: A = abstractions / total types.
     *
     * Returns 0.0 if total is 0 (empty namespace).
     * Ensures result is in [0, 1] range.
     */
    private function computeAbstractness(int $totalTypes, int $abstractions): float
    {
        if ($totalTypes === 0) {
            return 0.0;
        }

        $abstractness = $abstractions / $totalTypes;

        // Ensure A is in [0, 1] range
        return max(0.0, min(1.0, $abstractness));
    }
}
