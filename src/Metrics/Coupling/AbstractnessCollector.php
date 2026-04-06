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
 * Computes abstractness metric for namespaces.
 *
 * Abstractness = (abstract classes + interfaces) / (classes + enums + traits + interfaces)
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
        return [
            MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum),
            MetricName::agg(MetricName::SIZE_ENUM_COUNT, AggregationStrategy::Sum),
            MetricName::agg(MetricName::SIZE_TRAIT_COUNT, AggregationStrategy::Sum),
            MetricName::agg(MetricName::SIZE_ABSTRACT_CLASS_COUNT, AggregationStrategy::Sum),
            MetricName::agg(MetricName::SIZE_INTERFACE_COUNT, AggregationStrategy::Sum),
        ];
    }

    public function provides(): array
    {
        return [MetricName::COUPLING_ABSTRACTNESS];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::COUPLING_ABSTRACTNESS,
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

            $classCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum));
            $enumCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_ENUM_COUNT, AggregationStrategy::Sum));
            $traitCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_TRAIT_COUNT, AggregationStrategy::Sum));
            $abstractCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_ABSTRACT_CLASS_COUNT, AggregationStrategy::Sum));
            $interfaceCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_INTERFACE_COUNT, AggregationStrategy::Sum));

            $totalTypes = (int) $classCount + (int) $enumCount + (int) $traitCount + (int) $interfaceCount;
            $totalAbstractions = (int) $abstractCount + (int) $interfaceCount;

            $abstractness = $this->computeAbstractness($totalTypes, $totalAbstractions);

            $repository->addScalar($nsPath, MetricName::COUPLING_ABSTRACTNESS, $abstractness);
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
