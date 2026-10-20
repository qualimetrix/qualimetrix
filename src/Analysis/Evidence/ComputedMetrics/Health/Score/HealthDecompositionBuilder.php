<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\CoverageUnit;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;

final readonly class HealthDecompositionBuilder
{
    public function __construct(
        private HealthMetricCatalog $hintProvider,
        private CoverageReader $coverage,
    ) {}

    /**
     * @param list<array{key: string, sources: list<string>, label: string, direction: string, coverage: array{count: string, unit: CoverageUnit}|null}> $inputs
     *
     * @return list<DecompositionItem>
     */
    public function build(string $dimension, MetricBag $metrics, array $inputs): array
    {
        return array_map(
            fn(array $input): DecompositionItem => $this->input($dimension, $input['key'], $metrics),
            $inputs,
        );
    }

    private function input(string $dimension, string $key, MetricBag $metrics): DecompositionItem
    {
        $value = $metrics->get($key);
        $number = $value === null ? null : (float) $value;

        return new DecompositionItem(
            metricKey: $key,
            humanName: $this->hintProvider->getLabel($key) ?? $key,
            value: $number,
            goodValue: $this->hintProvider->getGoodValue($key) ?? '',
            direction: $this->hintProvider->getDirection($key) ?? 'lower_is_better',
            explanation: $number === null ? '' : $this->hintProvider->getExplanation($key, $number),
            coverage: $this->coverage->forInput($dimension, $key, $metrics->get(...)),
        );
    }

    /**
     * @return list<DecompositionItem>
     */
    public function buildTyping(MetricBag $metrics): array
    {
        $components = [
            ['label' => 'Parameter types', 'typed' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, AggregationStrategy::Sum)],
            ['label' => 'Return types', 'typed' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, AggregationStrategy::Sum)],
            ['label' => 'Property types', 'typed' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TYPED, AggregationStrategy::Sum), 'total' => MetricName::agg(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, AggregationStrategy::Sum)],
        ];

        $items = [];

        foreach ($components as $component) {
            $typed = $metrics->get($component['typed']);
            $total = $metrics->get($component['total']);

            if ($total === null || (int) $total === 0) {
                continue;
            }

            $pct = round((float) $typed / (float) $total * 100, 1);

            $items[] = new DecompositionItem(
                metricKey: $component['typed'],
                humanName: $component['label'],
                value: $pct,
                goodValue: '100%',
                direction: 'higher_is_better',
                explanation: \sprintf('%d of %d typed (%.1f%%)', (int) $typed, (int) $total, $pct),
            );
        }

        return $items;
    }

}
