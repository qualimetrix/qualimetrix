<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Health;

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
            'badExplanation' => 'coupled to too many classes',
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
        'mi.p5' => [
            'label' => 'MI (p5)',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 50',
            'badExplanation' => 'worst methods are hard to maintain',
            'goodExplanation' => 'even worst methods are maintainable',
        ],
        'typeCoverage.pct' => [
            'label' => 'Type coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing type declarations',
            'goodExplanation' => 'well-typed code',
        ],
        'typeCoverage.param' => [
            'label' => 'Parameter Type Coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing parameter types',
            'goodExplanation' => 'well-typed parameters',
        ],
        'typeCoverage.return' => [
            'label' => 'Return Type Coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing return types',
            'goodExplanation' => 'well-typed returns',
        ],
        'typeCoverage.property' => [
            'label' => 'Property Type Coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing property types',
            'goodExplanation' => 'well-typed properties',
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
     * Range-based interpretations for metrics, migrated from JS hints.
     *
     * Each entry has ordered ranges (lowest first), ending with an above:true sentinel.
     *
     * @var array<string, list<array{max?: float, above?: true, text: string}>>
     */
    private const array RANGES = [
        // Complexity
        'ccn' => [
            ['max' => 4, 'text' => 'Simple, easy to test'],
            ['max' => 10, 'text' => 'Moderate complexity'],
            ['max' => 20, 'text' => 'Complex, consider refactoring'],
            ['max' => 50, 'text' => 'Very complex, hard to maintain'],
            ['above' => true, 'text' => 'Extremely complex'],
        ],
        'cognitive' => [
            ['max' => 5, 'text' => 'Simple, easy to understand'],
            ['max' => 15, 'text' => 'Moderate complexity'],
            ['max' => 30, 'text' => 'Complex, hard to follow'],
            ['above' => true, 'text' => 'Very hard to follow'],
        ],
        'npath' => [
            ['max' => 20, 'text' => 'Simple, few execution paths'],
            ['max' => 200, 'text' => 'Moderate path count'],
            ['max' => 1000, 'text' => 'Many execution paths'],
            ['above' => true, 'text' => 'Explosive path count'],
        ],
        // Cohesion
        'lcom' => [
            ['max' => 1, 'text' => 'Cohesive — single responsibility'],
            ['max' => 3, 'text' => 'Moderate cohesion'],
            ['max' => 5, 'text' => 'Low cohesion, consider splitting'],
            ['above' => true, 'text' => 'Very low cohesion'],
        ],
        'tcc' => [
            ['max' => 0.29, 'text' => 'Low method interconnection'],
            ['max' => 0.49, 'text' => 'Moderate cohesion'],
            ['above' => true, 'text' => 'Good cohesion'],
        ],
        'lcc' => [
            ['max' => 0.29, 'text' => 'Low cohesion (incl. transitive)'],
            ['max' => 0.49, 'text' => 'Moderate cohesion'],
            ['above' => true, 'text' => 'Good cohesion'],
        ],
        'wmc' => [
            ['max' => 20, 'text' => 'Manageable class'],
            ['max' => 50, 'text' => 'Large class'],
            ['max' => 80, 'text' => 'Very large class'],
            ['above' => true, 'text' => 'Excessive — consider splitting'],
        ],
        // Coupling
        'cbo' => [
            ['max' => 7, 'text' => 'Normal coupling'],
            ['max' => 14, 'text' => 'Moderate coupling'],
            ['max' => 20, 'text' => 'High coupling'],
            ['above' => true, 'text' => 'Very high coupling'],
        ],
        'instability' => [
            ['max' => 0.09, 'text' => 'Maximally stable'],
            ['max' => 0.29, 'text' => 'Stable'],
            ['max' => 0.7, 'text' => 'Balanced'],
            ['max' => 0.9, 'text' => 'Unstable'],
            ['above' => true, 'text' => 'Maximally unstable'],
        ],
        'abstractness' => [
            ['max' => 0.09, 'text' => 'All concrete'],
            ['max' => 0.5, 'text' => 'Mostly concrete'],
            ['max' => 0.9, 'text' => 'Mostly abstract'],
            ['above' => true, 'text' => 'All abstract'],
        ],
        'distance' => [
            ['max' => 0.1, 'text' => 'On main sequence'],
            ['max' => 0.3, 'text' => 'Acceptable balance'],
            ['above' => true, 'text' => 'Off balance'],
        ],
        'classRank' => [
            ['max' => 0.009, 'text' => 'Peripheral class'],
            ['max' => 0.02, 'text' => 'Moderate importance'],
            ['max' => 0.05, 'text' => 'Important hub'],
            ['above' => true, 'text' => 'Critical coupling point'],
        ],
        // Design
        'dit' => [
            ['max' => 0, 'text' => 'Root class'],
            ['max' => 3, 'text' => 'Normal depth'],
            ['max' => 6, 'text' => 'Deep hierarchy'],
            ['above' => true, 'text' => 'Fragile hierarchy'],
        ],
        'noc' => [
            ['max' => 0, 'text' => 'Leaf class'],
            ['max' => 5, 'text' => 'Normal inheritance'],
            ['max' => 10, 'text' => 'Many subclasses'],
            ['above' => true, 'text' => 'Heavy base class'],
        ],
        'rfc' => [
            ['max' => 20, 'text' => 'Simple interface'],
            ['max' => 50, 'text' => 'Moderate interface'],
            ['max' => 100, 'text' => 'Complex interface'],
            ['above' => true, 'text' => 'Very complex interface'],
        ],
        // Size
        'methodCount' => [
            ['max' => 10, 'text' => 'Focused class'],
            ['max' => 20, 'text' => 'Large class'],
            ['max' => 30, 'text' => 'Very large class'],
            ['above' => true, 'text' => 'God Class territory'],
        ],
        'propertyCount' => [
            ['max' => 10, 'text' => 'Normal'],
            ['max' => 15, 'text' => 'Large'],
            ['max' => 20, 'text' => 'Heavy'],
            ['above' => true, 'text' => 'Excessive'],
        ],
        'classCount.sum' => [
            ['max' => 10, 'text' => 'Focused namespace'],
            ['max' => 15, 'text' => 'Moderate namespace'],
            ['max' => 25, 'text' => 'Large namespace'],
            ['above' => true, 'text' => 'Bloated namespace'],
        ],
        // Maintainability
        'mi' => [
            ['max' => 19, 'text' => 'Critical — very hard to maintain'],
            ['max' => 39, 'text' => 'Poor — refactoring recommended'],
            ['max' => 64, 'text' => 'Moderate — could benefit from simplification'],
            ['max' => 84, 'text' => 'Good maintainability'],
            ['above' => true, 'text' => 'Excellent maintainability'],
        ],
        // Type Coverage
        'typeCoverage.pct' => [
            ['max' => 49, 'text' => 'Low type coverage'],
            ['max' => 79, 'text' => 'Moderate type coverage'],
            ['above' => true, 'text' => 'Good type coverage'],
        ],
        'typeCoverage.param' => [
            ['max' => 49, 'text' => 'Low coverage'],
            ['max' => 79, 'text' => 'Moderate coverage'],
            ['above' => true, 'text' => 'Good coverage'],
        ],
        'typeCoverage.return' => [
            ['max' => 49, 'text' => 'Low coverage'],
            ['max' => 79, 'text' => 'Moderate coverage'],
            ['above' => true, 'text' => 'Good coverage'],
        ],
        'typeCoverage.property' => [
            ['max' => 49, 'text' => 'Low coverage'],
            ['max' => 79, 'text' => 'Moderate coverage'],
            ['above' => true, 'text' => 'Good coverage'],
        ],
    ];

    /**
     * Rich health score decomposition data, migrated from JS.
     *
     * Keys use the namespace-level (.avg) variant as primary for PHP compatibility.
     * The altKey provides the class-level variant for the HTML report.
     *
     * @var array<string, list<array{key: string, altKey: string|null, label: string, ideal: string, direction: string}>>
     */
    private const array HEALTH_INPUTS = [
        'health.complexity' => [
            ['key' => 'ccn.avg', 'altKey' => 'ccn', 'label' => 'CCN avg', 'ideal' => '1-3', 'direction' => 'lower'],
            ['key' => 'cognitive.avg', 'altKey' => 'cognitive', 'label' => 'Cognitive avg', 'ideal' => '0-4', 'direction' => 'lower'],
            ['key' => 'ccn.p95', 'altKey' => null, 'label' => 'CCN p95', 'ideal' => '≤25', 'direction' => 'lower'],
            ['key' => 'cognitive.p95', 'altKey' => null, 'label' => 'Cognitive p95', 'ideal' => '≤20', 'direction' => 'lower'],
        ],
        'health.cohesion' => [
            ['key' => 'tcc.avg', 'altKey' => 'tcc', 'label' => 'TCC', 'ideal' => '1.0', 'direction' => 'higher'],
            ['key' => 'lcom.avg', 'altKey' => 'lcom', 'label' => 'LCOM', 'ideal' => '1', 'direction' => 'lower'],
        ],
        'health.coupling' => [
            ['key' => 'cbo.avg', 'altKey' => 'cbo', 'label' => 'CBO', 'ideal' => '0-7', 'direction' => 'lower'],
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

    /**
     * Format templates for special metric display in the HTML report.
     *
     * Placeholders: {value} = numeric value, {plural} = 's' when value != 1.
     *
     * @var array<string, string>
     */
    private const array FORMAT_TEMPLATES = [
        'lcom' => '{value} disconnected group{plural}',
    ];

    /**
     * Long-form metric labels for the HTML report.
     *
     * METRICS uses short labels (e.g. 'Cyclomatic') for compact text output.
     * The HTML report needs descriptive labels (e.g. 'Cyclomatic Complexity').
     * Keys not listed here fall back to METRICS labels.
     *
     * @var array<string, string>
     */
    private const array HTML_LABELS = [
        'ccn' => 'Cyclomatic Complexity',
        'cognitive' => 'Cognitive Complexity',
        'npath' => 'NPath Complexity',
        'tcc' => 'Tight Class Cohesion',
        'lcc' => 'Loose Class Cohesion',
        'wmc' => 'Weighted Methods per Class',
        'cbo' => 'Coupling Between Objects',
        'dit' => 'Depth of Inheritance Tree',
        'noc' => 'Number of Children',
        'rfc' => 'Response for a Class',
        'mi' => 'Maintainability Index',
        'methodCount' => 'Method Count',
        'propertyCount' => 'Property Count',
        'classCount.sum' => 'Class Count',
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
    private const array AGGREGATION_SUFFIXES = ['.avg', '.max', '.min', '.sum', '.p95', '.p5'];

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
     * Derived from the rich HEALTH_INPUTS constant by extracting the key field.
     *
     * @return list<string>
     */
    public function getDecomposition(string $healthDimension): array
    {
        $inputs = self::HEALTH_INPUTS[$healthDimension] ?? [];

        return array_values(array_map(
            static fn(array $input): string => $input['key'],
            $inputs,
        ));
    }

    public function getScoreLabel(float $score, float $warnThreshold, float $errThreshold): string
    {
        $range = 100 - $warnThreshold;
        $strongThreshold = $warnThreshold + $range * 0.6;
        $goodThreshold = $warnThreshold + $range * 0.3;

        if ($score > $strongThreshold) {
            return 'Strong';
        }

        if ($score > $goodThreshold) {
            return 'Good';
        }

        if ($score > $warnThreshold) {
            return 'Acceptable';
        }

        if ($score > $errThreshold) {
            return 'Weak';
        }

        return 'Critical';
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
     * Exports all hint data for embedding in the HTML report.
     *
     * Returns a structure that JS can use to populate METRIC_HINTS and
     * HEALTH_DECOMPOSITION maps, making PHP the single source of truth.
     *
     * @return array{metricHints: array<string, array{label: string, ranges: list<array{max?: float, above?: true, text: string}>, formatTemplate: string|null}>, healthDecomposition: array<string, array{inputs: list<array{key: string, altKey: string|null, label: string, ideal: string, direction: string}>}>}
     */
    public function exportForHtml(): array
    {
        $metricHints = [];

        foreach (self::RANGES as $key => $ranges) {
            $label = self::HTML_LABELS[$key] ?? self::METRICS[$key]['label'];
            $metricHints[$key] = [
                'label' => $label,
                'ranges' => $ranges,
                'formatTemplate' => self::FORMAT_TEMPLATES[$key] ?? null,
            ];
        }

        $healthDecomposition = [];

        foreach (self::HEALTH_INPUTS as $dimension => $inputs) {
            $healthDecomposition[$dimension] = ['inputs' => $inputs];
        }

        return [
            'metricHints' => $metricHints,
            'healthDecomposition' => $healthDecomposition,
        ];
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
