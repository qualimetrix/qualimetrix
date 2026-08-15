<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata\HealthMetricMetadataCollection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata\HealthMetricMetadataProviderInterface;

final readonly class HealthMetricCatalog implements HealthMetricMetadataProviderInterface
{
    public function __construct(
        private MetricHintCatalog $metricHints = new MetricHintCatalog(),
        private HealthDimensionCatalog $dimensions = new HealthDimensionCatalog(),
    ) {}

    public function getLabel(string $key): ?string
    {
        return $this->metricHints->getLabel($key);
    }
    public function getExplanation(string $key, float $value): string
    {
        return $this->metricHints->getExplanation($key, $value);
    }
    public function getGoodValue(string $key): ?string
    {
        return $this->metricHints->getGoodValue($key);
    }
    public function getDirection(string $key): ?string
    {
        return $this->metricHints->getDirection($key);
    }
    /** @return list<string> */
    public function getDecomposition(string $dimension): array
    {
        return $this->dimensions->getDecomposition($dimension);
    }

    /** @return list<array{classKey: string, label: string, direction: string}> */
    public function getDecompositionForClasses(string $dimension): array
    {
        return $this->dimensions->getDecompositionForClasses($dimension);
    }
    public function getScoreLabel(float $score, float $warning, float $error): string
    {
        return $this->dimensions->getScoreLabel($score, $warning, $error);
    }
    public function getHealthDimensionLabel(string $dimension, bool $bad): string
    {
        return $bad
            ? $this->dimensions->getUnhealthyDimensionLabel($dimension)
            : $this->dimensions->getHealthyDimensionLabel($dimension);
    }

    public function metadata(): HealthMetricMetadataCollection
    {
        return new HealthMetricMetadataCollection($this->metricHints->metricHints(), $this->dimensions->healthDecomposition());
    }
}
