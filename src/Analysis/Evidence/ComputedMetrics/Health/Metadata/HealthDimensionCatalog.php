<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata;

final class HealthDimensionCatalog
{
    /** @var array<string, list<array{key: string, altKey: string|null, label: string, ideal: string, direction: string}>> */
    private const array INPUTS = [
        'health.complexity' => [
            ['key' => 'ccn.avg', 'altKey' => 'ccn.sum', 'label' => 'CCN avg', 'ideal' => '1-3', 'direction' => 'lower'],
            ['key' => 'cognitive.avg', 'altKey' => 'cognitive.sum', 'label' => 'Cognitive avg', 'ideal' => '0-4', 'direction' => 'lower'],
            ['key' => 'ccn.p95', 'altKey' => null, 'label' => 'CCN p95', 'ideal' => '≤5', 'direction' => 'lower'],
            ['key' => 'cognitive.p95', 'altKey' => null, 'label' => 'Cognitive p95', 'ideal' => '≤6', 'direction' => 'lower'],
        ],
        'health.cohesion' => [
            ['key' => 'tcc.avg', 'altKey' => 'tcc', 'label' => 'TCC', 'ideal' => '1.0', 'direction' => 'higher'],
            ['key' => 'lcom.avg', 'altKey' => 'lcom', 'label' => 'LCOM', 'ideal' => '1', 'direction' => 'lower'],
        ],
        'health.coupling' => [
            ['key' => 'ce.avg', 'altKey' => 'ce', 'label' => 'Ce (avg)', 'ideal' => '0-3', 'direction' => 'lower'],
            ['key' => 'ce_packages.avg', 'altKey' => 'ce_packages', 'label' => 'Ce packages', 'ideal' => '0-1', 'direction' => 'lower'],
            ['key' => 'distance.avg', 'altKey' => 'distance', 'label' => 'Distance', 'ideal' => '0.0', 'direction' => 'lower'],
        ],
        'health.typing' => [
            ['key' => 'typeCoverage.pct', 'altKey' => null, 'label' => 'Coverage', 'ideal' => '100%', 'direction' => 'higher'],
        ],
        'health.maintainability' => [
            ['key' => 'mi.avg', 'altKey' => 'mi', 'label' => 'MI avg', 'ideal' => '82+', 'direction' => 'higher'],
            ['key' => 'mi.p5', 'altKey' => null, 'label' => 'MI p5', 'ideal' => '≥65', 'direction' => 'higher'],
            ['key' => 'mi.min', 'altKey' => null, 'label' => 'MI min', 'ideal' => '≥40', 'direction' => 'higher'],
        ],
        'health.overall' => [],
    ];

    /** @var array<string, array{bad: string, good: string}> */
    private const array LABELS = [
        'complexity' => ['bad' => 'high complexity', 'good' => 'low complexity'],
        'cohesion' => ['bad' => 'low cohesion', 'good' => 'good cohesion'],
        'coupling' => ['bad' => 'high coupling', 'good' => 'low coupling'],
        'typing' => ['bad' => 'low type safety', 'good' => 'good type safety'],
        'maintainability' => ['bad' => 'hard to maintain', 'good' => 'maintainable'],
    ];

    /** @var array{inputs: array<string, list<array{key: string, altKey: string|null, label: string, ideal: string, direction: string}>>, labels: array<string, array{bad: string, good: string}>, scores: array<string, string>} */
    private array $dimensions;

    public function __construct()
    {
        $this->dimensions = [
            'inputs' => self::INPUTS,
            'labels' => self::LABELS,
            'scores' => [
                'complexity' => 'health.complexity',
                'cohesion' => 'health.cohesion',
                'coupling' => 'health.coupling',
                'typing' => 'health.typing',
                'maintainability' => 'health.maintainability',
            ],
        ];
    }

    /** @return list<string> */
    public function getDecomposition(string $dimension): array
    {
        return array_values(array_map(static fn(array $input): string => $input['key'], $this->dimensions['inputs'][$dimension] ?? []));
    }

    /** @return list<array{classKey: string, label: string, direction: string}> */
    public function getDecompositionForClasses(string $dimension): array
    {
        return array_values(array_map(static fn(array $input): array => [
            'classKey' => $input['altKey'] ?? $input['key'],
            'label' => $input['label'],
            'direction' => $input['direction'],
        ], $this->dimensions['inputs'][$dimension] ?? []));
    }

    /**
     * @param list<array{classKey: string, direction: string}> $inputs
     * @param callable(string): (int|float|null) $readMetric
     *
     * @return array{primaryValue: float|null, contributorMetrics: array<string, int|float>}
     */
    public function selectContributorMetrics(array $inputs, callable $readMetric): array
    {
        $primaryValue = isset($inputs[0]) ? $readMetric($inputs[0]['classKey']) : null;
        $contributorMetrics = [];
        foreach ($inputs as $input) {
            $value = $readMetric($input['classKey']);
            if ($value !== null) {
                $contributorMetrics[$input['classKey']] = $value;
            }
        }

        return [
            'primaryValue' => $primaryValue === null ? null : (float) $primaryValue,
            'contributorMetrics' => $contributorMetrics,
        ];
    }

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

    public function overallMetric(): string
    {
        return 'health.overall';
    }

    /** @return array<string, string> short name => metric name */
    public function scoreDimensions(): array
    {
        return $this->dimensions['scores'];
    }

    /** @return list<string> */
    public function notableClassMetrics(): array
    {
        return ['methodCount', 'propertyCount', 'cbo', 'ccn.avg', 'tcc', 'wmc', 'mi.avg', 'loc'];
    }

    public function classLocMetric(): string
    {
        return 'classLoc';
    }

    public function classCountMetric(): string
    {
        return 'classCount.sum';
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
        $labels = $this->dimensions['labels'][$dimension] ?? null;

        return $labels === null ? $dimension : $labels[$quality];
    }

    /** @return array<string, array{inputs: list<array{key: string, altKey: string|null, label: string, ideal: string, direction: string}>}> */
    public function healthDecomposition(): array
    {
        $result = [];
        foreach ($this->dimensions['inputs'] as $dimension => $inputs) {
            $result[$dimension] = ['inputs' => $inputs];
        }

        return $result;
    }
}
