<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata;

/**
 * How a health dimension is worded.
 *
 * The score bands a number is read through and the phrases a report uses for a
 * dimension in trouble. Which metrics a dimension is made of, and under which
 * keys they are published, is {@see HealthDecompositionCatalog}: one question is
 * about evidence and the other about wording, and they share no data.
 */
final class HealthDimensionCatalog
{
    /** @var array<string, array{bad: string, good: string}> */
    private const array LABELS = [
        'complexity' => ['bad' => 'high complexity', 'good' => 'low complexity'],
        'cohesion' => ['bad' => 'low cohesion', 'good' => 'good cohesion'],
        'coupling' => ['bad' => 'high coupling', 'good' => 'low coupling'],
        'typing' => ['bad' => 'low type safety', 'good' => 'good type safety'],
        'maintainability' => ['bad' => 'hard to maintain', 'good' => 'maintainable'],
    ];

    public function getScoreLabel(float $score, float $warning, float $error): string
    {
        $range = 100 - $warning;

        return match (true) {
            $score > $warning + $range * 0.6 => 'Excellent',
            $score > $warning + $range * 0.3 => 'Good',
            $score > $warning => 'Fair',
            $score > $error => 'Poor',
            default => 'Critical',
        };
    }

    public function getUnhealthyDimensionLabel(string $dimension): string
    {
        return $this->dimensionLabel($dimension, 'bad');
    }

    public function getHealthyDimensionLabel(string $dimension): string
    {
        return $this->dimensionLabel($dimension, 'good');
    }

    /** @param 'bad'|'good' $quality */
    private function dimensionLabel(string $dimension, string $quality): string
    {
        $labels = self::LABELS[$dimension] ?? null;

        return $labels === null ? $dimension : $labels[$quality];
    }

}
