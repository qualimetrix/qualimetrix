<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting;

use AiMessDetector\Reporting\MetricHintProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricHintProvider::class)]
final class MetricHintProviderTest extends TestCase
{
    private MetricHintProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MetricHintProvider();
    }

    // --- getLabel ---

    public function testGetLabelForKnownKey(): void
    {
        self::assertSame('Cyclomatic', $this->provider->getLabel('ccn'));
        self::assertSame('Cyclomatic (avg)', $this->provider->getLabel('ccn.avg'));
        self::assertSame('LCOM4', $this->provider->getLabel('lcom'));
        self::assertSame('LOC', $this->provider->getLabel('loc'));
    }

    public function testGetLabelForUnknownKey(): void
    {
        self::assertNull($this->provider->getLabel('nonexistent'));
    }

    public function testGetLabelWithSuffixResolution(): void
    {
        // ccn.max is not explicitly defined, but ccn is — should resolve via suffix stripping
        self::assertSame('Cyclomatic', $this->provider->getLabel('ccn.max'));
        self::assertSame('Cyclomatic', $this->provider->getLabel('ccn.min'));
        self::assertSame('CBO', $this->provider->getLabel('cbo.sum'));
    }

    public function testGetLabelPreferExactMatch(): void
    {
        // ccn.avg is explicitly defined — should return its own label, not ccn's
        self::assertSame('Cyclomatic (avg)', $this->provider->getLabel('ccn.avg'));
    }

    // --- getExplanation ---

    public function testGetExplanationLowerIsBetterBadValue(): void
    {
        // ccn good = "below 4", value 10 is bad
        self::assertSame('too many code paths', $this->provider->getExplanation('ccn', 10.0));
    }

    public function testGetExplanationLowerIsBetterGoodValue(): void
    {
        // ccn good = "below 4", value 3 is good
        self::assertSame('manageable branching', $this->provider->getExplanation('ccn', 3.0));
    }

    public function testGetExplanationLowerIsBetterAtThreshold(): void
    {
        // ccn good = "below 4", value exactly 4 is still good (<=)
        self::assertSame('manageable branching', $this->provider->getExplanation('ccn', 4.0));
    }

    public function testGetExplanationHigherIsBetterBadValue(): void
    {
        // tcc good = "above 0.5", value 0.2 is bad
        self::assertSame('methods share few common fields', $this->provider->getExplanation('tcc', 0.2));
    }

    public function testGetExplanationHigherIsBetterGoodValue(): void
    {
        // tcc good = "above 0.5", value 0.8 is good
        self::assertSame('methods share common fields', $this->provider->getExplanation('tcc', 0.8));
    }

    public function testGetExplanationHigherIsBetterAtThreshold(): void
    {
        // tcc good = "above 0.5", value exactly 0.5 is good (>=)
        self::assertSame('methods share common fields', $this->provider->getExplanation('tcc', 0.5));
    }

    public function testGetExplanationRangeBadValue(): void
    {
        // instability good = "0.3 – 0.7", value 0.1 is outside range
        self::assertSame('package is highly unstable', $this->provider->getExplanation('instability', 0.1));
        self::assertSame('package is highly unstable', $this->provider->getExplanation('instability', 0.9));
    }

    public function testGetExplanationRangeGoodValue(): void
    {
        // instability good = "0.3 – 0.7", value 0.5 is in range
        self::assertSame('balanced stability', $this->provider->getExplanation('instability', 0.5));
    }

    public function testGetExplanationRangeAtBoundaries(): void
    {
        self::assertSame('balanced stability', $this->provider->getExplanation('instability', 0.3));
        self::assertSame('balanced stability', $this->provider->getExplanation('instability', 0.7));
    }

    public function testGetExplanationNeutral(): void
    {
        self::assertSame('', $this->provider->getExplanation('loc', 100.0));
        self::assertSame('', $this->provider->getExplanation('lloc', 50.0));
    }

    public function testGetExplanationUnknownKey(): void
    {
        self::assertSame('', $this->provider->getExplanation('nonexistent', 5.0));
    }

    public function testGetExplanationLcomPlaceholder(): void
    {
        // lcom bad = "class has {value} unrelated method groups"
        self::assertSame(
            'class has 5 unrelated method groups',
            $this->provider->getExplanation('lcom', 5.0),
        );
    }

    public function testGetExplanationLcomGoodValue(): void
    {
        // lcom good = "1 or less"
        self::assertSame('class is cohesive', $this->provider->getExplanation('lcom', 1.0));
    }

    public function testGetExplanationWithSuffixResolution(): void
    {
        // ccn.max resolves to ccn entry
        self::assertSame('too many code paths', $this->provider->getExplanation('ccn.max', 10.0));
    }

    public function testGetExplanationTypeCoveragePercentage(): void
    {
        // typeCoverage.pct good = "above 80%", value 90 is good
        self::assertSame('well-typed code', $this->provider->getExplanation('typeCoverage.pct', 90.0));
        self::assertSame('missing type declarations', $this->provider->getExplanation('typeCoverage.pct', 50.0));
    }

    // --- getGoodValue ---

    public function testGetGoodValueKnown(): void
    {
        self::assertSame('below 4', $this->provider->getGoodValue('ccn'));
        self::assertSame('above 0.5', $this->provider->getGoodValue('tcc'));
        self::assertSame('0.3 – 0.7', $this->provider->getGoodValue('instability'));
    }

    public function testGetGoodValueNeutral(): void
    {
        self::assertNull($this->provider->getGoodValue('loc'));
    }

    public function testGetGoodValueUnknown(): void
    {
        self::assertNull($this->provider->getGoodValue('nonexistent'));
    }

    // --- getDirection ---

    public function testGetDirectionKnown(): void
    {
        self::assertSame('lower_is_better', $this->provider->getDirection('ccn'));
        self::assertSame('higher_is_better', $this->provider->getDirection('tcc'));
        self::assertSame('range', $this->provider->getDirection('instability'));
        self::assertSame('neutral', $this->provider->getDirection('loc'));
    }

    public function testGetDirectionUnknown(): void
    {
        self::assertNull($this->provider->getDirection('nonexistent'));
    }

    // --- getDecomposition ---

    public function testGetDecompositionKnownDimension(): void
    {
        self::assertSame(['ccn.avg', 'cognitive.avg'], $this->provider->getDecomposition('health.complexity'));
        self::assertSame(['tcc.avg', 'lcom.avg'], $this->provider->getDecomposition('health.cohesion'));
        self::assertSame(['cbo.avg', 'distance.avg'], $this->provider->getDecomposition('health.coupling'));
        self::assertSame(['typeCoverage.pct'], $this->provider->getDecomposition('health.typing'));
        self::assertSame(['mi.avg'], $this->provider->getDecomposition('health.maintainability'));
        self::assertSame([], $this->provider->getDecomposition('health.overall'));
    }

    public function testGetDecompositionUnknownDimension(): void
    {
        self::assertSame([], $this->provider->getDecomposition('health.unknown'));
    }

    // --- getScoreLabel ---

    /**
     * @return iterable<string, array{float, float, float, string}>
     */
    public static function scoreLabelProvider(): iterable
    {
        // score, warnThreshold, errThreshold, expected
        // range = 100 - 70 = 30
        // strongThreshold = 70 + 30 * 0.6 = 88
        // goodThreshold = 70 + 30 * 0.3 = 79
        yield 'Strong: well above strong threshold' => [95.0, 70.0, 40.0, 'Strong'];
        yield 'Strong: above strong threshold' => [90.0, 70.0, 40.0, 'Strong'];
        yield 'Strong: just above strong threshold' => [88.01, 70.0, 40.0, 'Strong'];
        yield 'Good: exactly strong threshold is not strong' => [88.0, 70.0, 40.0, 'Good'];
        yield 'Good: well above good threshold' => [85.0, 70.0, 40.0, 'Good'];
        yield 'Good: just above good threshold' => [79.01, 70.0, 40.0, 'Good'];
        yield 'Acceptable: exactly good threshold is not good' => [79.0, 70.0, 40.0, 'Acceptable'];
        yield 'Acceptable: above warning' => [75.0, 70.0, 40.0, 'Acceptable'];
        yield 'Acceptable: just above warning' => [70.01, 70.0, 40.0, 'Acceptable'];
        yield 'Weak: exactly warning is not acceptable' => [70.0, 70.0, 40.0, 'Weak'];
        yield 'Weak: above error' => [50.0, 70.0, 40.0, 'Weak'];
        yield 'Weak: just above error' => [40.01, 70.0, 40.0, 'Weak'];
        yield 'Critical: exactly error' => [40.0, 70.0, 40.0, 'Critical'];
        yield 'Critical: below error' => [20.0, 70.0, 40.0, 'Critical'];

        // Test with health.overall defaults (warn=50, err=30):
        // range = 100 - 50 = 50
        // strongThreshold = 50 + 50 * 0.6 = 80
        // goodThreshold = 50 + 50 * 0.3 = 65
        yield 'Overall: Strong (88.5)' => [88.5, 50.0, 30.0, 'Strong'];
        yield 'Overall: Good (68.3)' => [68.3, 50.0, 30.0, 'Good'];
        yield 'Overall: Acceptable (64.6)' => [64.6, 50.0, 30.0, 'Acceptable'];
        yield 'Overall: Acceptable (52.5)' => [52.5, 50.0, 30.0, 'Acceptable'];
        yield 'Overall: Weak (45.4)' => [45.4, 50.0, 30.0, 'Weak'];
    }

    #[DataProvider('scoreLabelProvider')]
    public function testGetScoreLabel(float $score, float $warnThreshold, float $errThreshold, string $expected): void
    {
        self::assertSame($expected, $this->provider->getScoreLabel($score, $warnThreshold, $errThreshold));
    }

    // --- getHealthDimensionLabel ---

    public function testGetHealthDimensionLabelBad(): void
    {
        self::assertSame('high complexity', $this->provider->getHealthDimensionLabel('complexity', true));
        self::assertSame('low cohesion', $this->provider->getHealthDimensionLabel('cohesion', true));
        self::assertSame('high coupling', $this->provider->getHealthDimensionLabel('coupling', true));
        self::assertSame('low type safety', $this->provider->getHealthDimensionLabel('typing', true));
        self::assertSame('hard to maintain', $this->provider->getHealthDimensionLabel('maintainability', true));
    }

    public function testGetHealthDimensionLabelGood(): void
    {
        self::assertSame('low complexity', $this->provider->getHealthDimensionLabel('complexity', false));
        self::assertSame('good cohesion', $this->provider->getHealthDimensionLabel('cohesion', false));
        self::assertSame('low coupling', $this->provider->getHealthDimensionLabel('coupling', false));
        self::assertSame('good type safety', $this->provider->getHealthDimensionLabel('typing', false));
        self::assertSame('maintainable', $this->provider->getHealthDimensionLabel('maintainability', false));
    }

    public function testGetHealthDimensionLabelUnknown(): void
    {
        self::assertSame('unknown', $this->provider->getHealthDimensionLabel('unknown', true));
        self::assertSame('unknown', $this->provider->getHealthDimensionLabel('unknown', false));
    }

    // --- exportForHtml ---

    public function testExportForHtmlReturnsExpectedTopLevelKeys(): void
    {
        $result = $this->provider->exportForHtml();

        self::assertArrayHasKey('metricHints', $result);
        self::assertArrayHasKey('healthDecomposition', $result);
    }

    public function testExportForHtmlMetricHintsContainsAllRangedMetrics(): void
    {
        $result = $this->provider->exportForHtml();
        $hints = $result['metricHints'];

        // Verify expected metric categories are present
        $expectedKeys = [
            'ccn', 'cognitive', 'npath',
            'lcom', 'tcc', 'lcc', 'wmc',
            'cbo', 'instability', 'abstractness', 'distance', 'classRank',
            'dit', 'noc', 'rfc',
            'methodCount', 'propertyCount', 'classCount.sum',
            'mi',
            'typeCoverage.pct', 'typeCoverage.param', 'typeCoverage.return', 'typeCoverage.property',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $hints, "Missing metric hint for: {$key}");
            self::assertArrayHasKey('label', $hints[$key]);
            self::assertArrayHasKey('ranges', $hints[$key]);
            self::assertArrayHasKey('formatTemplate', $hints[$key]);
        }

        // Neutral metrics (loc, lloc, cloc) should NOT be in metricHints
        self::assertArrayNotHasKey('loc', $hints);
        self::assertArrayNotHasKey('lloc', $hints);
        self::assertArrayNotHasKey('cloc', $hints);
    }

    public function testExportForHtmlLabelsAreDescriptive(): void
    {
        $result = $this->provider->exportForHtml();

        // HTML labels should be descriptive (not cryptic abbreviations)
        self::assertSame('Cyclomatic Complexity', $result['metricHints']['ccn']['label']);
        self::assertSame('Cognitive Complexity', $result['metricHints']['cognitive']['label']);
        self::assertSame('Tight Class Cohesion', $result['metricHints']['tcc']['label']);
        self::assertSame('Maintainability Index', $result['metricHints']['mi']['label']);
    }

    public function testExportForHtmlEveryRangedMetricHasLabel(): void
    {
        $result = $this->provider->exportForHtml();

        foreach ($result['metricHints'] as $key => $hint) {
            self::assertNotEmpty($hint['label'], "Metric '{$key}' has empty label");
            // Labels should not be raw metric keys
            self::assertNotSame($key, $hint['label'], "Metric '{$key}' label should not be the raw key");
        }
    }

    public function testExportForHtmlRangesEndWithAbove(): void
    {
        $result = $this->provider->exportForHtml();

        foreach ($result['metricHints'] as $key => $hint) {
            $ranges = $hint['ranges'];
            $last = end($ranges);
            self::assertTrue($last['above'] ?? false, "{$key} should end with above:true");
        }
    }

    public function testExportForHtmlFormatTemplateOnlyOnLcom(): void
    {
        $result = $this->provider->exportForHtml();

        self::assertSame('{value} disconnected group{plural}', $result['metricHints']['lcom']['formatTemplate']);

        // All others should be null
        foreach ($result['metricHints'] as $key => $hint) {
            if ($key !== 'lcom') {
                self::assertNull($hint['formatTemplate'], "{$key} should have null formatTemplate");
            }
        }
    }

    public function testExportForHtmlHealthDecompositionHasAllDimensions(): void
    {
        $result = $this->provider->exportForHtml();
        $decomp = $result['healthDecomposition'];

        $expectedDimensions = [
            'health.complexity',
            'health.cohesion',
            'health.coupling',
            'health.typing',
            'health.maintainability',
            'health.overall',
        ];

        foreach ($expectedDimensions as $dimension) {
            self::assertArrayHasKey($dimension, $decomp, "Missing health dimension: {$dimension}");
            self::assertArrayHasKey('inputs', $decomp[$dimension]);
        }
    }

    public function testExportForHtmlHealthInputsHaveRequiredFields(): void
    {
        $result = $this->provider->exportForHtml();

        foreach ($result['healthDecomposition'] as $dimension => $data) {
            foreach ($data['inputs'] as $i => $input) {
                self::assertArrayHasKey('key', $input, "{$dimension}[{$i}] missing key");
                self::assertArrayHasKey('altKey', $input, "{$dimension}[{$i}] missing altKey");
                self::assertArrayHasKey('label', $input, "{$dimension}[{$i}] missing label");
                self::assertArrayHasKey('ideal', $input, "{$dimension}[{$i}] missing ideal");
                self::assertArrayHasKey('direction', $input, "{$dimension}[{$i}] missing direction");
            }
        }
    }
}
