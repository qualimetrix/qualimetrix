<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

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
 *
 * A namespace missing either input gets no distance, in either scope: the
 * inputs are declared required, and a missing one read as 0 made a namespace
 * that declares only functions -- present in the repository, absent from the
 * class graph, so never given an instability -- publish |0 + 0 - 1| = 1.0,
 * the worst value there is, for a namespace the metric does not describe.
 */
final class DistanceCollector implements GlobalContextCollectorInterface
{
    public function getName(): string
    {
        return 'distance';
    }

    public function requires(): array
    {
        return [
            MetricName::COUPLING_INSTABILITY,
            MetricName::COUPLING_ABSTRACTNESS,
            MetricName::COUPLING_INSTABILITY_OWN,
            MetricName::COUPLING_ABSTRACTNESS_OWN,
        ];
    }

    public function provides(): array
    {
        return [MetricName::COUPLING_DISTANCE, MetricName::COUPLING_DISTANCE_OWN];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COUPLING_DISTANCE,
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [],
            ),
            new MetricDefinition(
                name: MetricName::COUPLING_DISTANCE_OWN,
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
        foreach ($repository->all(SymbolLevel::Namespace_) as $symbolInfo) {
            $nsPath = $symbolInfo->symbolPath;
            $metrics = $repository->get($nsPath);

            $instability = $metrics->get(MetricName::COUPLING_INSTABILITY);
            $abstractness = $metrics->get(MetricName::COUPLING_ABSTRACTNESS);

            if ($instability !== null && $abstractness !== null) {
                $repository->addScalar(
                    $nsPath,
                    MetricName::COUPLING_DISTANCE,
                    $this->computeDistance((float) $instability, (float) $abstractness),
                );
            }

            $this->addOwnDistance($repository, $nsPath);
        }
    }

    /**
     * Publishes distance over the namespace's own scope, which is what the
     * project average is taken over: a parent namespace and its children each
     * contribute their own declarations, so every declaration is weighed once.
     *
     * A namespace that declares no type of its own has no own abstractness and
     * gets no key, which is what keeps such a container out of that average
     * instead of entering it at the worst possible D = 1.0.
     */
    private function addOwnDistance(MetricRepositoryInterface $repository, SymbolPath $nsPath): void
    {
        $metrics = $repository->get($nsPath);

        $ownAbstractness = $metrics->get(MetricName::COUPLING_ABSTRACTNESS_OWN);
        $ownInstability = $metrics->get(MetricName::COUPLING_INSTABILITY_OWN);

        if ($ownAbstractness === null || $ownInstability === null) {
            return;
        }

        $repository->addScalar(
            $nsPath,
            MetricName::COUPLING_DISTANCE_OWN,
            $this->computeDistance((float) $ownInstability, (float) $ownAbstractness),
        );
    }

    /**
     * Computes distance from main sequence: D = |A + I - 1|.
     */
    private function computeDistance(float $instability, float $abstractness): float
    {
        return abs($abstractness + $instability - 1.0);
    }
}
