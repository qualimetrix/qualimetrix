<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata\HealthMetricMetadataCollection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata\HealthMetricMetadataProviderInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class HealthMetricCatalog implements HealthMetricMetadataProviderInterface
{
    public function __construct(
        private MetricHintCatalog $metricHints = new MetricHintCatalog(),
        private HealthDimensionCatalog $dimensions = new HealthDimensionCatalog(),
        private HealthDecompositionCatalog $decomposition = new HealthDecompositionCatalog(),
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
    public function getDecomposition(string $dimension, SymbolLevel $level): array
    {
        return $this->decomposition->getDecomposition($dimension, $level);
    }

    /** @return list<array{classKey: string, label: string, direction: string}> */
    public function getDecompositionForClasses(string $dimension): array
    {
        return $this->decomposition->getDecompositionForClasses($dimension);
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
        return new HealthMetricMetadataCollection($this->metricHints->metricHints(), $this->decomposition->healthDecomposition());
    }
}
