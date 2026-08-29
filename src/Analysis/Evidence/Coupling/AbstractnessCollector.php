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

/**
 * Computes abstractness metric for namespaces.
 *
 * Abstractness = (abstract classes + interfaces)
 *              / (classes + traits + interfaces + enums implementing an interface)
 *
 * Range: [0, 1]
 * - 0: completely concrete (no abstractions)
 * - 1: completely abstract (all abstractions)
 *
 * Scope adaptation for PHP realities: Martin's 1994 model knows only abstract
 * classes and concrete classes, so every later PHP construct has to be mapped
 * onto it. A bare literal enumeration offers no substitution point at all --
 * it cannot be extended, subtyped or implemented -- so it is neutral and stays
 * out of the denominator entirely rather than counting as concrete. An
 * `enum X implements Y` is exactly such a substitution point, a concrete
 * implementation of a declared contract, and is counted as concrete. Without
 * that split a namespace holding one interface and N enums implementing it
 * would report A = 1.0. The shape of the formula (abstractions / total types)
 * is unchanged; only the classification of one construct is.
 *
 * Requires classCount, implementingEnumCount, traitCount, abstractClassCount and
 * interfaceCount aggregated at namespace level.
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
            MetricName::agg(MetricName::SIZE_IMPLEMENTING_ENUM_COUNT, AggregationStrategy::Sum),
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
        foreach ($repository->all(SymbolLevel::Namespace_) as $symbolInfo) {
            $nsPath = $symbolInfo->symbolPath;
            $metrics = $repository->get($nsPath);

            $classCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum));
            $implementingEnumCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_IMPLEMENTING_ENUM_COUNT, AggregationStrategy::Sum));
            $traitCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_TRAIT_COUNT, AggregationStrategy::Sum));
            $abstractCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_ABSTRACT_CLASS_COUNT, AggregationStrategy::Sum));
            $interfaceCount = (int) $metrics->require(MetricName::agg(MetricName::SIZE_INTERFACE_COUNT, AggregationStrategy::Sum));

            $totalTypes = $classCount + $traitCount + $interfaceCount + $implementingEnumCount;
            $totalAbstractions = $abstractCount + $interfaceCount;

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
