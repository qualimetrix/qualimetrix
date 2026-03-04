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
 * Computes distance from the main sequence for namespaces.
 *
 * Distance = |A + I - 1|
 *
 * Where:
 * - A = Abstractness (from AbstractnessCollector)
 * - I = Instability (from CouplingCollector)
 *
 * Range: [0, 1]
 * - 0: on the main sequence (balanced)
 * - 1: far from the main sequence (problematic)
 *
 * Packages should ideally be close to the main sequence (A + I ≈ 1).
 */
final class DistanceCollector implements GlobalContextCollectorInterface
{
    public function getName(): string
    {
        return 'distance';
    }

    public function requires(): array
    {
        return ['instability', 'abstractness'];
    }

    public function provides(): array
    {
        return ['distance'];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: 'distance',
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [],
            ),
        ];
    }

    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        // Iterate over all namespaces and compute distance
        foreach ($repository->all(SymbolType::Namespace_) as $symbolInfo) {
            $nsPath = $symbolInfo->symbolPath;
            $metrics = $repository->get($nsPath);

            $instability = $metrics->get('instability') ?? 0.0;
            $abstractness = $metrics->get('abstractness') ?? 0.0;

            $distance = $this->computeDistance((float) $instability, (float) $abstractness);

            $newMetrics = (new MetricBag())->with('distance', $distance);

            $repository->add($nsPath, $newMetrics, '', null);
        }
    }

    /**
     * Computes distance from main sequence: D = |A + I - 1|.
     */
    private function computeDistance(float $instability, float $abstractness): float
    {
        return abs($abstractness + $instability - 1.0);
    }
}
