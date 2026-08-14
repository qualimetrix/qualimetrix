<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata;

/**
 * Immutable metric metadata used to build output-specific hint projections.
 */
final readonly class HealthMetricMetadataCollection
{
    /**
     * @param array<string, array{label: string, ranges: list<array{max?: float, above?: true, text: string}>, formatTemplate: string|null}> $metricHints
     * @param array<string, array{inputs: list<array{key: string, altKey: string|null, label: string, ideal: string, direction: string}>}> $healthDecomposition
     */
    public function __construct(
        public array $metricHints,
        public array $healthDecomposition,
    ) {}
}
