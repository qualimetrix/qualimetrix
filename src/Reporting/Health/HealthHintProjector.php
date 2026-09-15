<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata\HealthMetricMetadataCollection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata\HealthMetricMetadataProviderInterface;

final readonly class HealthHintProjector
{
    public function __construct(
        private HealthMetricMetadataProviderInterface $metadataProvider,
    ) {}

    /**
     * @return array{metricHints: array<string, array{label: string, ranges: list<array{max?: float, above?: true, text: string}>, formatTemplate: string|null}>, healthDecomposition: array<string, array{levels: array<string, list<array{key: string, label: string, direction: string}>>}>}
     */
    public function project(): array
    {
        return $this->projectMetadata($this->metadataProvider->metadata());
    }

    /**
     * @return array{metricHints: array<string, array{label: string, ranges: list<array{max?: float, above?: true, text: string}>, formatTemplate: string|null}>, healthDecomposition: array<string, array{levels: array<string, list<array{key: string, label: string, direction: string}>>}>}
     */
    private function projectMetadata(HealthMetricMetadataCollection $metadata): array
    {
        return [
            'metricHints' => $metadata->metricHints,
            'healthDecomposition' => $metadata->healthDecomposition,
        ];
    }
}
