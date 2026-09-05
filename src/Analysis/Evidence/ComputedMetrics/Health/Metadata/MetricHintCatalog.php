<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;

/**
 * Single source of truth for metric display metadata.
 *
 * Provides human-readable labels, explanations, and health score
 * decomposition data for use in reports and formatters.
 */
final class MetricHintCatalog
{
    /**
     * @var array<string, array{label: string, direction: string, goodValue: string, badExplanation: string, goodExplanation: string}>
     */
    private const array METRICS = [
        'complexity.ccn' => [
            'label' => 'Cyclomatic',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 4',
            'badExplanation' => 'too many code paths',
            'goodExplanation' => 'manageable branching',
        ],
        'complexity.ccn.avg' => [
            'label' => 'Cyclomatic (avg)',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 4',
            'badExplanation' => 'too many code paths per method',
            'goodExplanation' => 'manageable branching',
        ],
        MetricName::COMPLEXITY_COGNITIVE => [
            'label' => 'Cognitive',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 5',
            'badExplanation' => 'deeply nested, hard to follow',
            'goodExplanation' => 'straightforward control flow',
        ],
        'complexity.cognitive.avg' => [
            'label' => 'Cognitive (avg)',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 5',
            'badExplanation' => 'deeply nested, hard to follow',
            'goodExplanation' => 'straightforward control flow',
        ],
        MetricName::COMPLEXITY_NPATH => [
            'label' => 'NPath',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 200',
            'badExplanation' => 'explosive number of execution paths',
            'goodExplanation' => 'few execution paths',
        ],
        'cohesion.tcc' => [
            'label' => 'TCC',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 0.5',
            'badExplanation' => 'methods share few common fields',
            'goodExplanation' => 'methods share common fields',
        ],
        'cohesion.lcc' => [
            'label' => 'LCC',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 0.5',
            'badExplanation' => 'methods are loosely connected',
            'goodExplanation' => 'methods are well connected',
        ],
        MetricName::COHESION_LCOM => [
            'label' => 'LCOM4',
            'direction' => 'lower_is_better',
            'goodValue' => '1 or less',
            'badExplanation' => 'class has {value} unrelated method groups',
            'goodExplanation' => 'class is cohesive',
        ],
        MetricName::COMPLEXITY_WMC => [
            'label' => 'WMC',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 20',
            'badExplanation' => 'total method complexity is high',
            'goodExplanation' => 'total complexity is manageable',
        ],
        MetricName::COUPLING_CBO => [
            'label' => 'CBO',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 7',
            'badExplanation' => 'coupled to too many classes',
            'goodExplanation' => 'well-isolated',
        ],
        'coupling.cbo.avg' => [
            'label' => 'CBO (avg)',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 7',
            'badExplanation' => 'classes depend on too many others',
            'goodExplanation' => 'reasonable coupling',
        ],
        'coupling.ce' => [
            'label' => 'Ce',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 3',
            'badExplanation' => 'depends on too many external classes',
            'goodExplanation' => 'limited outgoing dependencies',
        ],
        'coupling.ce.avg' => [
            'label' => 'Ce (avg)',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 3',
            'badExplanation' => 'classes depend on too many others outgoing',
            'goodExplanation' => 'low outgoing coupling',
        ],
        'coupling.ce-packages' => [
            'label' => 'Ce packages',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 2',
            'badExplanation' => 'spread across too many external packages',
            'goodExplanation' => 'narrow package dependencies',
        ],
        'coupling.ce-packages.avg' => [
            'label' => 'Ce pkg (avg)',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 1',
            'badExplanation' => 'classes touch many external packages',
            'goodExplanation' => 'narrow package dependencies',
        ],
        MetricName::COUPLING_INSTABILITY => [
            'label' => 'Instability',
            'direction' => 'range',
            'goodValue' => '0.3 – 0.7',
            'badExplanation' => 'package is highly unstable',
            'goodExplanation' => 'balanced stability',
        ],
        'coupling.abstractness' => [
            'label' => 'Abstractness',
            'direction' => 'range',
            'goodValue' => '0.3 – 0.7',
            'badExplanation' => 'package is too abstract/concrete',
            'goodExplanation' => 'balanced abstraction',
        ],
        MetricName::COUPLING_DISTANCE => [
            'label' => 'Distance',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 0.3',
            'badExplanation' => 'poor balance of abstraction and stability',
            'goodExplanation' => 'well-balanced design',
        ],
        MetricName::COUPLING_CLASS_RANK => [
            'label' => 'ClassRank',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 0.02',
            'badExplanation' => 'coupling hotspot, many depend on this',
            'goodExplanation' => 'peripheral, low risk',
        ],
        'design.dit' => [
            'label' => 'DIT',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 3',
            'badExplanation' => 'deep inheritance, fragile hierarchy',
            'goodExplanation' => 'normal inheritance',
        ],
        MetricName::DESIGN_NOC => [
            'label' => 'NOC',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 5',
            'badExplanation' => 'too many direct subclasses',
            'goodExplanation' => 'normal subclass count',
        ],
        'coupling.rfc' => [
            'label' => 'RFC',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 50',
            'badExplanation' => 'too many callable methods',
            'goodExplanation' => 'reasonable method reach',
        ],
        MetricName::SIZE_METHOD_COUNT => [
            'label' => 'Methods',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 20',
            'badExplanation' => 'too many methods',
            'goodExplanation' => 'focused class',
        ],
        MetricName::SIZE_PROPERTY_COUNT => [
            'label' => 'Properties',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 10',
            'badExplanation' => 'too many properties',
            'goodExplanation' => 'reasonable state',
        ],
        'size.class-count.sum' => [
            'label' => 'Classes',
            'direction' => 'lower_is_better',
            'goodValue' => 'below 10',
            'badExplanation' => 'too many classes in namespace',
            'goodExplanation' => 'focused namespace',
        ],
        'maintainability.mi' => [
            'label' => 'MI',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 65',
            'badExplanation' => 'code is hard to change safely',
            'goodExplanation' => 'code is maintainable',
        ],
        'maintainability.mi.avg' => [
            'label' => 'MI (avg)',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 65',
            'badExplanation' => 'code is hard to change safely',
            'goodExplanation' => 'code is maintainable',
        ],
        'maintainability.mi.p5' => [
            'label' => 'MI (p5)',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 50',
            'badExplanation' => 'worst methods are hard to maintain',
            'goodExplanation' => 'even worst methods are maintainable',
        ],
        'design.type-coverage.pct' => [
            'label' => 'Type coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing type declarations',
            'goodExplanation' => 'well-typed code',
        ],
        MetricName::DESIGN_TYPE_COVERAGE_PARAM => [
            'label' => 'Parameter Type Coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing parameter types',
            'goodExplanation' => 'well-typed parameters',
        ],
        MetricName::DESIGN_TYPE_COVERAGE_RETURN => [
            'label' => 'Return Type Coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing return types',
            'goodExplanation' => 'well-typed returns',
        ],
        MetricName::DESIGN_TYPE_COVERAGE_PROPERTY => [
            'label' => 'Property Type Coverage',
            'direction' => 'higher_is_better',
            'goodValue' => 'above 80%',
            'badExplanation' => 'missing property types',
            'goodExplanation' => 'well-typed properties',
        ],
        'size.loc' => [
            'label' => 'LOC',
            'direction' => 'neutral',
            'goodValue' => '',
            'badExplanation' => '',
            'goodExplanation' => '',
        ],
        'size.lloc' => [
            'label' => 'LLOC',
            'direction' => 'neutral',
            'goodValue' => '',
            'badExplanation' => '',
            'goodExplanation' => '',
        ],
        'size.cloc' => [
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
        'complexity.ccn' => [
            ['max' => 4, 'text' => 'Simple, easy to test'],
            ['max' => 10, 'text' => 'Moderate complexity'],
            ['max' => 20, 'text' => 'Complex, consider refactoring'],
            ['max' => 50, 'text' => 'Very complex, hard to maintain'],
            ['above' => true, 'text' => 'Extremely complex'],
        ],
        MetricName::COMPLEXITY_COGNITIVE => [
            ['max' => 5, 'text' => 'Simple, easy to understand'],
            ['max' => 15, 'text' => 'Moderate complexity'],
            ['max' => 30, 'text' => 'Complex, hard to follow'],
            ['above' => true, 'text' => 'Very hard to follow'],
        ],
        MetricName::COMPLEXITY_NPATH => [
            ['max' => 20, 'text' => 'Simple, few execution paths'],
            ['max' => 200, 'text' => 'Moderate path count'],
            ['max' => 1000, 'text' => 'Many execution paths'],
            ['above' => true, 'text' => 'Explosive path count'],
        ],
        // Cohesion
        MetricName::COHESION_LCOM => [
            ['max' => 1, 'text' => 'Cohesive — single responsibility'],
            ['max' => 3, 'text' => 'Moderate cohesion'],
            ['max' => 5, 'text' => 'Low cohesion, consider splitting'],
            ['above' => true, 'text' => 'Very low cohesion'],
        ],
        'cohesion.tcc' => [
            ['max' => 0.29, 'text' => 'Low method interconnection'],
            ['max' => 0.49, 'text' => 'Moderate cohesion'],
            ['above' => true, 'text' => 'Good cohesion'],
        ],
        'cohesion.lcc' => [
            ['max' => 0.29, 'text' => 'Low cohesion (incl. transitive)'],
            ['max' => 0.49, 'text' => 'Moderate cohesion'],
            ['above' => true, 'text' => 'Good cohesion'],
        ],
        MetricName::COMPLEXITY_WMC => [
            ['max' => 20, 'text' => 'Manageable class'],
            ['max' => 50, 'text' => 'Large class'],
            ['max' => 80, 'text' => 'Very large class'],
            ['above' => true, 'text' => 'Excessive — consider splitting'],
        ],
        // Coupling
        MetricName::COUPLING_CBO => [
            ['max' => 7, 'text' => 'Normal coupling'],
            ['max' => 14, 'text' => 'Moderate coupling'],
            ['max' => 20, 'text' => 'High coupling'],
            ['above' => true, 'text' => 'Very high coupling'],
        ],
        MetricName::COUPLING_INSTABILITY => [
            ['max' => 0.09, 'text' => 'Maximally stable'],
            ['max' => 0.29, 'text' => 'Stable'],
            ['max' => 0.7, 'text' => 'Balanced'],
            ['max' => 0.9, 'text' => 'Unstable'],
            ['above' => true, 'text' => 'Maximally unstable'],
        ],
        'coupling.abstractness' => [
            ['max' => 0.09, 'text' => 'All concrete'],
            ['max' => 0.5, 'text' => 'Mostly concrete'],
            ['max' => 0.9, 'text' => 'Mostly abstract'],
            ['above' => true, 'text' => 'All abstract'],
        ],
        MetricName::COUPLING_DISTANCE => [
            ['max' => 0.1, 'text' => 'On main sequence'],
            ['max' => 0.3, 'text' => 'Acceptable balance'],
            ['above' => true, 'text' => 'Off balance'],
        ],
        MetricName::COUPLING_CLASS_RANK => [
            ['max' => 0.009, 'text' => 'Peripheral class'],
            ['max' => 0.02, 'text' => 'Moderate importance'],
            ['max' => 0.05, 'text' => 'Important hub'],
            ['above' => true, 'text' => 'Critical coupling point'],
        ],
        // Design
        'design.dit' => [
            ['max' => 0, 'text' => 'Root class'],
            ['max' => 3, 'text' => 'Normal depth'],
            ['max' => 6, 'text' => 'Deep hierarchy'],
            ['above' => true, 'text' => 'Fragile hierarchy'],
        ],
        MetricName::DESIGN_NOC => [
            ['max' => 0, 'text' => 'Leaf class'],
            ['max' => 5, 'text' => 'Normal inheritance'],
            ['max' => 10, 'text' => 'Many subclasses'],
            ['above' => true, 'text' => 'Heavy base class'],
        ],
        'coupling.rfc' => [
            ['max' => 20, 'text' => 'Simple interface'],
            ['max' => 50, 'text' => 'Moderate interface'],
            ['max' => 100, 'text' => 'Complex interface'],
            ['above' => true, 'text' => 'Very complex interface'],
        ],
        // Size
        MetricName::SIZE_METHOD_COUNT => [
            ['max' => 10, 'text' => 'Focused class'],
            ['max' => 20, 'text' => 'Large class'],
            ['max' => 30, 'text' => 'Very large class'],
            ['above' => true, 'text' => 'God Class territory'],
        ],
        MetricName::SIZE_PROPERTY_COUNT => [
            ['max' => 10, 'text' => 'Normal'],
            ['max' => 15, 'text' => 'Large'],
            ['max' => 20, 'text' => 'Heavy'],
            ['above' => true, 'text' => 'Excessive'],
        ],
        'size.class-count.sum' => [
            ['max' => 10, 'text' => 'Focused namespace'],
            ['max' => 15, 'text' => 'Moderate namespace'],
            ['max' => 25, 'text' => 'Large namespace'],
            ['above' => true, 'text' => 'Bloated namespace'],
        ],
        // Maintainability
        'maintainability.mi' => [
            ['max' => 19, 'text' => 'Critical — very hard to maintain'],
            ['max' => 39, 'text' => 'Poor — refactoring recommended'],
            ['max' => 64, 'text' => 'Moderate — could benefit from simplification'],
            ['max' => 84, 'text' => 'Good maintainability'],
            ['above' => true, 'text' => 'Excellent maintainability'],
        ],
        // Type Coverage
        'design.type-coverage.pct' => [
            ['max' => 49, 'text' => 'Low type coverage'],
            ['max' => 79, 'text' => 'Moderate type coverage'],
            ['above' => true, 'text' => 'Good type coverage'],
        ],
        MetricName::DESIGN_TYPE_COVERAGE_PARAM => [
            ['max' => 49, 'text' => 'Low coverage'],
            ['max' => 79, 'text' => 'Moderate coverage'],
            ['above' => true, 'text' => 'Good coverage'],
        ],
        MetricName::DESIGN_TYPE_COVERAGE_RETURN => [
            ['max' => 49, 'text' => 'Low coverage'],
            ['max' => 79, 'text' => 'Moderate coverage'],
            ['above' => true, 'text' => 'Good coverage'],
        ],
        MetricName::DESIGN_TYPE_COVERAGE_PROPERTY => [
            ['max' => 49, 'text' => 'Low coverage'],
            ['max' => 79, 'text' => 'Moderate coverage'],
            ['above' => true, 'text' => 'Good coverage'],
        ],
    ];

    /**
     * Format templates for special metric display in the HTML report.
     *
     * Placeholders: {value} = numeric value, {plural} = 's' when value != 1.
     *
     * @var array<string, string>
     */
    private const array FORMAT_TEMPLATES = [
        MetricName::COHESION_LCOM => '{value} disconnected group{plural}',
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
        'complexity.ccn' => 'Cyclomatic Complexity',
        MetricName::COMPLEXITY_COGNITIVE => 'Cognitive Complexity',
        MetricName::COMPLEXITY_NPATH => 'NPath Complexity',
        'cohesion.tcc' => 'Tight Class Cohesion',
        'cohesion.lcc' => 'Loose Class Cohesion',
        MetricName::COMPLEXITY_WMC => 'Weighted Methods per Class',
        MetricName::COUPLING_CBO => 'Coupling Between Objects',
        'coupling.ce' => 'Efferent Coupling',
        'coupling.ce-packages' => 'Efferent Packages',
        'design.dit' => 'Depth of Inheritance Tree',
        MetricName::DESIGN_NOC => 'Number of Children',
        'coupling.rfc' => 'Response for a Class',
        'maintainability.mi' => 'Maintainability Index',
        MetricName::SIZE_METHOD_COUNT => 'Method Count',
        MetricName::SIZE_PROPERTY_COUNT => 'Property Count',
        'size.class-count.sum' => 'Class Count',
    ];

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

        return $entry === null || $entry['goodValue'] === '' ? null : $entry['goodValue'];
    }

    public function getDirection(string $metricKey): ?string
    {
        return $this->resolveMetric($metricKey)['direction'] ?? null;
    }

    /** @return array<string, array{label: string, ranges: list<array{max?: float, above?: true, text: string}>, formatTemplate: string|null}> */
    public function metricHints(): array
    {
        /** @var array<string, array{label: string, ranges: list<array{max?: float, above?: true, text: string}>, formatTemplate: string|null}> $metricHints */
        $metricHints = [];

        foreach (self::RANGES as $key => $ranges) {
            $label = self::HTML_LABELS[$key] ?? self::METRICS[$key]['label'];
            $metricHints[$key] = [
                'label' => $label,
                'ranges' => $ranges,
                'formatTemplate' => self::FORMAT_TEMPLATES[$key] ?? null,
            ];
        }

        return $metricHints;
    }

    /**
     * @return array{label: string, direction: string, goodValue: string, badExplanation: string, goodExplanation: string}|null
     */
    private function resolveMetric(string $metricKey): ?array
    {
        if (isset(self::METRICS[$metricKey])) {
            return self::METRICS[$metricKey];
        }

        // The base key is read through the vocabulary rather than a local copy
        // of the suffix list. The copy that stood here was one short — it had no
        // `.count` — so a hint for a counted metric resolved to nothing and the
        // report simply showed none.
        $baseKey = MetricName::base($metricKey);

        if ($baseKey !== $metricKey && isset(self::METRICS[$baseKey])) {
            return self::METRICS[$baseKey];
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
