<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Design;

use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Metric\ParallelSafeCollectorInterface;
use AiMessDetector\Core\Metric\SymbolLevel;

/**
 * Derived collector that computes type coverage percentage at class level.
 *
 * Aggregates per-class typed/total counts from the TypeCoverageCollector
 * into a single percentage metric: (typed / total) * 100.
 */
final class TypeCoveragePercentCollector implements DerivedCollectorInterface, ParallelSafeCollectorInterface
{
    private const NAME = 'type-coverage-pct';

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['type-coverage'];
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [MetricName::TYPE_COVERAGE_PCT];
    }

    public function calculate(MetricBag $sourceBag): MetricBag
    {
        $result = new MetricBag();
        $prefix = MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':';

        foreach ($sourceBag->all() as $key => $value) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $fqn = substr($key, \strlen($prefix));

            $paramTyped = $sourceBag->get(MetricName::TYPE_COVERAGE_PARAM_TYPED . ':' . $fqn) ?? 0;
            $returnTyped = $sourceBag->get(MetricName::TYPE_COVERAGE_RETURN_TYPED . ':' . $fqn) ?? 0;
            $propertyTyped = $sourceBag->get(MetricName::TYPE_COVERAGE_PROPERTY_TYPED . ':' . $fqn) ?? 0;

            $paramTotal = $sourceBag->get(MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':' . $fqn) ?? 0;
            $returnTotal = $sourceBag->get(MetricName::TYPE_COVERAGE_RETURN_TOTAL . ':' . $fqn) ?? 0;
            $propertyTotal = $sourceBag->get(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL . ':' . $fqn) ?? 0;

            $totalTyped = $paramTyped + $returnTyped + $propertyTyped;
            $totalAll = $paramTotal + $returnTotal + $propertyTotal;

            $pct = $totalAll > 0 ? round($totalTyped / $totalAll * 100, 2) : 100.0;
            $result = $result->with(MetricName::TYPE_COVERAGE_PCT . ':' . $fqn, $pct);
        }

        return $result;
    }

    /**
     * @return list<MetricDefinition>
     */
    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::TYPE_COVERAGE_PCT,
                collectedAt: SymbolLevel::Class_,
                aggregations: [],
            ),
        ];
    }
}
