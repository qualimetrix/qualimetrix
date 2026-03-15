<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting;

/**
 * Single source of truth for metric display metadata.
 *
 * Provides human-readable labels, explanations, and health score
 * decomposition data for use in reports and formatters.
 */
final class MetricHintProvider
{
    /**
     * @var array<string, array{label: string, direction: string, goodValue: string, badExplanation: string, goodExplanation: string}>
     */
    private const array METRICS = [
        'ccn' => [
            'label' => 'Cyclomatic',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 4',
            'badExplanation' => 'too many code paths',
            'goodExplanation' => 'manageable branching',
        ],
        'ccn.avg' => [
            'label' => 'Cyclomatic (avg)',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 4',
            'badExplanation' => 'too many code paths per method',
            'goodExplanation' => 'manageable branching',
        ],
        'cognitive' => [
            'label' => 'Cognitive',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 5',
            'badExplanation' => 'deeply nested, hard to follow',
            'goodExplanation' => 'straightforward control flow',
        ],
        'cognitive.avg' => [
            'label' => 'Cognitive (avg)',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 5',
            'badExplanation' => 'deeply nested, hard to follow',
            'goodExplanation' => 'straightforward control flow',
        ],
        'npath' => [
            'label' => 'NPath',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 200',
            'badExplanation' => 'explosive number of execution paths',
            'goodExplanation' => 'few execution paths',
        ],
        'tcc' => [
            'label' => 'TCC',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 0.5',
            'badExplanation' => 'methods share few common fields',
            'goodExplanation' => 'methods share common fields',
        ],
        'lcc' => [
            'label' => 'LCC',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 0.5',
            'badExplanation' => 'methods are loosely connected',
            'goodExplanation' => 'methods are well connected',
        ],
        'lcom' => [
            'label' => 'LCOM4',
            'direction' => 'lower_is_better',
            'goodValue' => '1 or less',
            'badExplanation' => 'class has {value} unrelated method groups',
            'goodExplanation' => 'class is cohesive',
        ],
        'wmc' => [
            'label' => 'WMC',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 20',
            'badExplanation' => 'total method complexity is high',
            'goodExplanation' => 'total complexity is manageable',
        ],
        'cbo' => [
            'label' => 'CBO',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 7',
            'badExplanation' => 'depends on too many classes',
            'goodExplanation' => 'well-isolated',
        ],
        'cbo.avg' => [
            'label' => 'CBO (avg)',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 7',
            'badExplanation' => 'classes depend on too many others',
            'goodExplanation' => 'reasonable coupling',
        ],
        'instability' => [
            'label' => 'Instability',
            'direction' => 'range',
            'goodValue' => '0.3 – 0.7',
            'badExplanation' => 'package is highly unstable',
            'goodExplanation' => 'balanced stability',
        ],
        'abstractness' => [
            'label' => 'Abstractness',
            'direction' => 'range',
            'goodValue' => '0.3 – 0.7',
            'badExplanation' => 'package is too abstract/concrete',
            'goodExplanation' => 'balanced abstraction',
        ],
        'distance' => [
            'label' => 'Distance',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 0.3',
            'badExplanation' => 'poor balance of abstraction and stability',
            'goodExplanation' => 'well-balanced design',
        ],
        'classRank' => [
            'label' => 'ClassRank',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 0.02',
            'badExplanation' => 'coupling hotspot, many depend on this',
            'goodExplanation' => 'peripheral, low risk',
        ],
        'dit' => [
            'label' => 'DIT',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 3',
            'badExplanation' => 'deep inheritance, fragile hierarchy',
            'goodExplanation' => 'normal inheritance',
        ],
        'noc' => [
            'label' => 'NOC',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 5',
            'badExplanation' => 'too many direct subclasses',
            'goodExplanation' => 'normal subclass count',
        ],
        'rfc' => [
            'label' => 'RFC',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 50',
            'badExplanation' => 'too many callable methods',
            'goodExplanation' => 'reasonable method reach',
        ],
        'methodCount' => [
            'label' => 'Methods',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 20',
            'badExplanation' => 'too many methods',
            'goodExplanation' => 'focused class',
        ],
        'propertyCount' => [
            'label' => 'Properties',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 10',
            'badExplanation' => 'too many properties',
            'goodExplanation' => 'reasonable state',
        ],
        'classCount.sum' => [
            'label' => 'Classes',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 10',
            'badExplanation' => 'too many classes in namespace',
            'goodExplanation' => 'focused namespace',
        ],
        'mi' => [
            'label' => 'MI',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 65',
            'badExplanation' => 'code is hard to change safely',
            'goodExplanation' => 'code is maintainable',
        ],
        'mi.avg' => [
            'label' => 'MI (avg)',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 65',
            'badExplanation' => 'code is hard to change safely',
            'goodExplanation' => 'code is maintainable',
        ],
        'typeCoverage.pct' => [
            'label' => 'Type coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing type declarations',
            'goodExplanation' => 'well-typed code',
        ],
        'loc' => [
            'label' => 'LOC',
            'direction' => 'neutral',
            'goodValue' => '',
            'badExplanation' => '',
            'goodExplanation' => '',
        ],
        'lloc' => [
            'label' => 'LLOC',
            'direction' => 'neutral',
            'goodValue' => '',
            'badExplanation' => '',
            'goodExplanation' => '',
        ],
        'cloc' => [
            'label' => 'CLOC',
            'direction' => 'neutral',
            'goodValue' => '',
            'badExplanation' => '',
            'goodExplanation' => '',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array HEALTH_DECOMPOSITION = [
        'health.complexity' => ['ccn.avg', 'cognitive.avg'],
        'health.cohesion' => ['tcc.avg', 'lcom.avg'],
        'health.coupling' => ['cbo.avg'],
        'health.typing' => [],
        'health.maintainability' => ['mi.avg'],
        'health.overall' => [],
    ];

    /**
     * @var array<string, array{bad: string, good: string}>
     */
    private const array HEALTH_DIMENSION_LABELS = [
        'complexity' => ['bad' => 'high complexity', 'good' => 'low complexity'],
        'cohesion' => ['bad' => 'low cohesion', 'good' => 'good cohesion'],
        'coupling' => ['bad' => 'high coupling', 'good' => 'low coupling'],
        'typing' => ['bad' => 'low type safety', 'good' => 'good type safety'],
        'maintainability' => ['bad' => 'hard to maintain', 'good' => 'maintainable'],
    ];

    /** @var list<string> */
    private const array AGGREGATION_SUFFIXES = ['.avg', '.max', '.min', '.sum'];

    public function getLabel(string $metricKey): ?string
    {
        $entry = $this->resolveMetric($metricKey);

        return $entry !== null ? $entry['label'] : null;
    }

    public function getExplanation(string $metricKey, float $value): string
    {
        $entry = $this->resolveMetric($metricKey);

        if ($entry === null) {
            return '';
        }

        $direction = $entry['direction'];

        if ($direction === 'neutral' || ($entry['badExplanation'] === '' && $entry['goodExplanation'] === '')) {
            return '';
        }

        $isGood = $this->isGoodValue($value, $entry);

        $explanation = $isGood ? $entry['goodExplanation'] : $entry['badExplanation'];

        return str_replace('{value}', (string) (int) $value, $explanation);
    }

    public function getGoodValue(string $metricKey): ?string
    {
        $entry = $this->resolveMetric($metricKey);

        if ($entry === null || $entry['goodValue'] === '') {
            return null;
        }

        return $entry['goodValue'];
    }

    public function getDirection(string $metricKey): ?string
    {
        $entry = $this->resolveMetric($metricKey);

        return $entry !== null ? $entry['direction'] : null;
    }

    /**
     * Returns list of metric keys that compose a health dimension.
     *
     * @return list<string>
     */
    public function getDecomposition(string $healthDimension): array
    {
        return self::HEALTH_DECOMPOSITION[$healthDimension] ?? [];
    }

    public function getScoreLabel(float $score, float $warnThreshold, float $errThreshold): string
    {
        if ($score > $warnThreshold + 20) {
            return 'Excellent';
        }

        if ($score > $warnThreshold) {
            return 'Good';
        }

        if ($score > $errThreshold) {
            return 'Needs attention';
        }

        return 'Poor';
    }

    public function getHealthDimensionLabel(string $dimension, bool $bad): string
    {
        $labels = self::HEALTH_DIMENSION_LABELS[$dimension] ?? null;

        if ($labels === null) {
            return $dimension;
        }

        return $bad ? $labels['bad'] : $labels['good'];
    }

    /**
     * @return array{label: string, direction: string, goodValue: string, badExplanation: string, goodExplanation: string}|null
     */
    private function resolveMetric(string $metricKey): ?array
    {
        if (isset(self::METRICS[$metricKey])) {
            return self::METRICS[$metricKey];
        }

        foreach (self::AGGREGATION_SUFFIXES as $suffix) {
            if (str_ends_with($metricKey, $suffix)) {
                $baseKey = substr($metricKey, 0, -\strlen($suffix));

                if (isset(self::METRICS[$baseKey])) {
                    return self::METRICS[$baseKey];
                }
            }
        }

        return null;
    }

    /**
     * @param array{label: string, direction: string, goodValue: string, badExplanation: string, goodExplanation: string} $entry
     */
    private function isGoodValue(float $value, array $entry): bool
    {
        $direction = $entry['direction'];
        $goodValue = $entry['goodValue'];

        if ($direction === 'lower_is_better') {
            $threshold = $this->parseThreshold($goodValue, 'below');

            return $threshold !== null && $value <= $threshold;
        }

        if ($direction === 'higher_is_better') {
            $threshold = $this->parseThreshold($goodValue, 'above');

            return $threshold !== null && $value >= $threshold;
        }

        if ($direction === 'range') {
            return $this->isInRange($value, $goodValue);
        }

        return true;
    }

    private function parseThreshold(string $goodValue, string $prefix): ?float
    {
        $goodValue = str_replace('%', '', $goodValue);

        if (str_starts_with($goodValue, $prefix . ' ')) {
            $numericPart = trim(substr($goodValue, \strlen($prefix) + 1));

            return is_numeric($numericPart) ? (float) $numericPart : null;
        }

        // Handle "1 or less" style
        if (str_contains($goodValue, ' or less')) {
            $numericPart = trim(explode(' or less', $goodValue)[0]);

            return is_numeric($numericPart) ? (float) $numericPart : null;
        }

        return null;
    }

    private function isInRange(float $value, string $goodValue): bool
    {
        $parts = explode('–', $goodValue);

        if (\count($parts) !== 2) {
            return true;
        }

        $min = (float) trim($parts[0]);
        $max = (float) trim($parts[1]);

        return $value >= $min && $value <= $max;
    }
}
