<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Coupling;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\GlobalContextCollectorInterface;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

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
        return [MetricName::COUPLING_INSTABILITY, MetricName::COUPLING_ABSTRACTNESS];
    }

    public function provides(): array
    {
        return [MetricName::COUPLING_DISTANCE];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COUPLING_DISTANCE,
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [
                    SymbolLevel::Project->value => [AggregationStrategy::Average],
                ],
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

            $instability = $metrics->get(MetricName::COUPLING_INSTABILITY) ?? 0.0;
            $abstractness = $metrics->get(MetricName::COUPLING_ABSTRACTNESS) ?? 0.0;

            $distance = $this->computeDistance((float) $instability, (float) $abstractness);

            $repository->addScalar($nsPath, MetricName::COUPLING_DISTANCE, $distance);
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
