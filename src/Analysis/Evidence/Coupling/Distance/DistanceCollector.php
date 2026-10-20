<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Distance;

use Qualimetrix\Analysis\Evidence\Coupling\AbstractnessCollector;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingCollector;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
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
 * A namespace needs measured abstraction, instability, and at least one
 * incoming or outgoing graph edge in the same scope. Missing inputs and the
 * conventional I = 0 for Ca = Ce = 0 do not describe a distance. The project
 * value weights each measured own namespace by its eligible type count.
 *
 * @qmx-threshold health.cohesion 40.0 -- Independent immutable observations or stateless declaration operations intentionally have no shared instance-field graph; this finite class boundary keeps a further cohesion loss visible.
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
            MetricName::COUPLING_CA,
            MetricName::COUPLING_CE,
            MetricName::COUPLING_CA_OWN,
            MetricName::COUPLING_CE_OWN,
            MetricName::SIZE_CLASS_COUNT,
            MetricName::SIZE_TRAIT_COUNT,
            MetricName::SIZE_INTERFACE_COUNT,
            MetricName::SIZE_IMPLEMENTING_ENUM_COUNT,
        ];
    }

    public function getMetricReach(): MetricReach
    {
        return MetricReach::Run;
    }

    public function provides(): array
    {
        return [
            MetricName::COUPLING_DISTANCE,
            MetricName::COUPLING_DISTANCE_OWN,
            MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Average),
            MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Count),
        ];
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
                aggregations: [],
            ),
            new MetricDefinition(
                name: MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Average),
                collectedAt: SymbolLevel::Project,
            ),
            new MetricDefinition(
                name: MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Count),
                collectedAt: SymbolLevel::Project,
            ),
        ];
    }

    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        $weightedDistance = 0.0;
        $measuredTypes = 0;
        $measuredNamespaces = 0;

        foreach ($repository->all(SymbolLevel::Namespace_) as $symbolInfo) {
            $nsPath = $symbolInfo->symbolPath;
            $metrics = $repository->get($nsPath);

            $this->addSubtreeDistance($repository, $nsPath);

            $ownDistance = $this->addOwnDistance($repository, $nsPath);
            if ($ownDistance !== null) {
                $ownTypes = $this->ownTypeCount($metrics);
                if ($ownTypes !== null && $ownTypes > 0) {
                    $weightedDistance += $ownDistance * $ownTypes;
                    $measuredTypes += $ownTypes;
                    $measuredNamespaces++;
                }
            }
        }

        if ($measuredTypes > 0) {
            $project = SymbolPath::forProject();
            $repository->addScalar(
                $project,
                MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Average),
                $weightedDistance / $measuredTypes,
            );
            $repository->addScalar(
                $project,
                MetricName::agg(MetricName::COUPLING_DISTANCE_OWN, AggregationStrategy::Count),
                $measuredNamespaces,
            );
        }
    }

    private function addSubtreeDistance(MetricRepositoryInterface $repository, SymbolPath $nsPath): void
    {
        $metrics = $repository->get($nsPath);
        $instability = $metrics->get(MetricName::COUPLING_INSTABILITY);
        $abstractness = $metrics->get(MetricName::COUPLING_ABSTRACTNESS);
        $ca = $metrics->get(MetricName::COUPLING_CA);
        $ce = $metrics->get(MetricName::COUPLING_CE);

        if ($instability !== null && $abstractness !== null
            && $ca !== null && $ce !== null && $ca + $ce > 0) {
            $repository->addScalar(
                $nsPath,
                MetricName::COUPLING_DISTANCE,
                $this->computeDistance((float) $instability, (float) $abstractness),
            );
        }
    }

    /**
     * Publishes distance over the namespace's own scope, which is what the
     * project mean is taken over: a parent namespace and its children each
     * contribute their own declarations, so every eligible type is weighed once.
     *
     * A namespace that declares no type of its own has no own abstractness and
     * gets no key, which is what keeps such a container out of that average
     * instead of entering it at the worst possible D = 1.0.
     */
    private function addOwnDistance(MetricRepositoryInterface $repository, SymbolPath $nsPath): ?float
    {
        $metrics = $repository->get($nsPath);

        $ownAbstractness = $metrics->get(MetricName::COUPLING_ABSTRACTNESS_OWN);
        $ownInstability = $metrics->get(MetricName::COUPLING_INSTABILITY_OWN);

        $ownCa = $metrics->get(MetricName::COUPLING_CA_OWN);
        $ownCe = $metrics->get(MetricName::COUPLING_CE_OWN);
        if ($ownAbstractness === null || $ownInstability === null
            || $ownCa === null || $ownCe === null || $ownCa + $ownCe === 0) {
            return null;
        }

        $distance = $this->computeDistance((float) $ownInstability, (float) $ownAbstractness);
        $repository->addScalar($nsPath, MetricName::COUPLING_DISTANCE_OWN, $distance);

        return $distance;
    }

    private function ownTypeCount(MetricBag $metrics): ?int
    {
        $types = 0;
        foreach ([
            MetricName::SIZE_CLASS_COUNT,
            MetricName::SIZE_TRAIT_COUNT,
            MetricName::SIZE_INTERFACE_COUNT,
            MetricName::SIZE_IMPLEMENTING_ENUM_COUNT,
        ] as $key) {
            $count = $metrics->get($key);
            if ($count === null) {
                return null;
            }
            $types += (int) $count;
        }

        return $types;
    }

    /**
     * Computes distance from main sequence: D = |A + I - 1|.
     */
    private function computeDistance(float $instability, float $abstractness): float
    {
        return abs($abstractness + $instability - 1.0);
    }
}
