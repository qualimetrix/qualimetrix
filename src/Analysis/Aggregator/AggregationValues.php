<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

/**
 * Return type for collectNamespaceMetricValues() — carries both values and their weights.
 */
final readonly class AggregationValues
{
    /**
     * @param array<string, list<float>> $values metric name => list of values
     * @param array<string, list<float>> $weights metric name => list of weights (parallel to values)
     */
    public function __construct(
        public array $values,
        public array $weights,
    ) {}
}
