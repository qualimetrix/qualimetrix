<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;

final class HealthDimensionCatalog
{
    /** @var array<string, list<array{key: string, altKey: string|null, label: string, ideal: string, direction: string}>> */
    private const array INPUTS = [
        'health.complexity' => [
            ['key' => 'complexity.ccn.avg', 'altKey' => 'complexity.ccn.sum', 'label' => 'CCN avg', 'ideal' => '1-3', 'direction' => 'lower'],
            ['key' => 'complexity.cognitive.avg', 'altKey' => 'complexity.cognitive.sum', 'label' => 'Cognitive avg', 'ideal' => '0-4', 'direction' => 'lower'],
            ['key' => 'complexity.ccn.p95', 'altKey' => null, 'label' => 'CCN p95', 'ideal' => '≤5', 'direction' => 'lower'],
            ['key' => 'complexity.cognitive.p95', 'altKey' => null, 'label' => 'Cognitive p95', 'ideal' => '≤6', 'direction' => 'lower'],
        ],
        'health.cohesion' => [
            ['key' => 'cohesion.tcc.avg', 'altKey' => 'cohesion.tcc', 'label' => 'TCC', 'ideal' => '1.0', 'direction' => 'higher'],
            ['key' => 'cohesion.lcom.avg', 'altKey' => MetricName::COHESION_LCOM, 'label' => 'LCOM', 'ideal' => '1', 'direction' => 'lower'],
        ],
        'health.coupling' => [
            ['key' => 'coupling.ce.avg', 'altKey' => 'coupling.ce', 'label' => 'Ce (avg)', 'ideal' => '0-3', 'direction' => 'lower'],
            ['key' => 'coupling.ce-packages.avg', 'altKey' => 'coupling.ce-packages', 'label' => 'Ce packages', 'ideal' => '0-1', 'direction' => 'lower'],
            ['key' => 'coupling.distance.avg', 'altKey' => MetricName::COUPLING_DISTANCE, 'label' => 'Distance', 'ideal' => '0.0', 'direction' => 'lower'],
        ],
        'health.typing' => [
            ['key' => 'design.type-coverage.pct', 'altKey' => null, 'label' => 'Coverage', 'ideal' => '100%', 'direction' => 'higher'],
        ],
        'health.maintainability' => [
            ['key' => 'maintainability.mi.avg', 'altKey' => 'maintainability.mi', 'label' => 'MI avg', 'ideal' => '82+', 'direction' => 'higher'],
            ['key' => 'maintainability.mi.p5', 'altKey' => null, 'label' => 'MI p5', 'ideal' => '≥65', 'direction' => 'higher'],
            ['key' => 'maintainability.mi.min', 'altKey' => null, 'label' => 'MI min', 'ideal' => '≥40', 'direction' => 'higher'],
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

    /**
     * The class-level metrics a report calls out by name.
     *
     * A constant beside the other two catalogs rather than a literal in the
     * accessor: this is settled data about the product, and the accessor that
     * hands it out has no decision left to make.
     *
     * @var list<string>
     */
    private const array NOTABLE_CLASS_METRICS = [
        MetricName::SIZE_METHOD_COUNT,
        MetricName::SIZE_PROPERTY_COUNT,
        MetricName::COUPLING_CBO,
        'complexity.ccn.avg',
        'cohesion.tcc',
        MetricName::COMPLEXITY_WMC,
        'maintainability.mi.avg',
        'size.loc',
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
        return self::NOTABLE_CLASS_METRICS;
    }

    public function classLocMetric(): string
    {
        return 'size.class-loc';
    }

    public function classCountMetric(): string
    {
        return 'size.class-count.sum';
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
