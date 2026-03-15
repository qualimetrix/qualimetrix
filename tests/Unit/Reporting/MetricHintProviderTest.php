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
        self::assertSame(['cbo.avg'], $this->provider->getDecomposition('health.coupling'));
        self::assertSame([], $this->provider->getDecomposition('health.typing'));
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
        yield 'Excellent: above warning + 20' => [95.0, 70.0, 40.0, 'Excellent'];
        yield 'Excellent: exactly warning + 20.01' => [90.01, 70.0, 40.0, 'Excellent'];
        yield 'Good: exactly warning + 20 is not excellent' => [90.0, 70.0, 40.0, 'Good'];
        yield 'Good: above warning' => [75.0, 70.0, 40.0, 'Good'];
        yield 'Good: exactly warning + 0.01' => [70.01, 70.0, 40.0, 'Good'];
        yield 'Needs attention: exactly warning is not good' => [70.0, 70.0, 40.0, 'Needs attention'];
        yield 'Needs attention: above error' => [50.0, 70.0, 40.0, 'Needs attention'];
        yield 'Needs attention: exactly error + 0.01' => [40.01, 70.0, 40.0, 'Needs attention'];
        yield 'Poor: exactly error' => [40.0, 70.0, 40.0, 'Poor'];
        yield 'Poor: below error' => [20.0, 70.0, 40.0, 'Poor'];
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
}
