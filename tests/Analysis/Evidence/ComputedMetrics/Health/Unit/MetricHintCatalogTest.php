<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\MetricHintCatalog;

#[CoversClass(MetricHintCatalog::class)]
final class MetricHintCatalogTest extends TestCase
{
    private MetricHintCatalog $provider;

    protected function setUp(): void
    {
        $this->provider = new MetricHintCatalog();
    }

    // --- getLabel ---

    #[Test]
    public function itGetLabelForKnownKey(): void
    {
        self::assertSame('Cyclomatic', $this->provider->getLabel('complexity.ccn'));
        self::assertSame('Cyclomatic (avg)', $this->provider->getLabel('complexity.ccn.avg'));
        self::assertSame('LCOM4', $this->provider->getLabel('cohesion.lcom'));
        self::assertSame('LOC', $this->provider->getLabel('size.loc'));
    }

    #[Test]
    public function itGetLabelForUnknownKey(): void
    {
        self::assertNull($this->provider->getLabel('nonexistent'));
    }

    #[Test]
    public function itGetLabelWithSuffixResolution(): void
    {
        // ccn.max is not explicitly defined, but ccn is — should resolve via suffix stripping
        self::assertSame('Cyclomatic', $this->provider->getLabel('complexity.ccn.max'));
        self::assertSame('Cyclomatic', $this->provider->getLabel('complexity.ccn.min'));
        self::assertSame('CBO', $this->provider->getLabel('coupling.cbo.sum'));
    }

    #[Test]
    public function itGetLabelPreferExactMatch(): void
    {
        // ccn.avg is explicitly defined — should return its own label, not ccn's
        self::assertSame('Cyclomatic (avg)', $this->provider->getLabel('complexity.ccn.avg'));
    }

    // --- getExplanation ---

    #[Test]
    public function itGetExplanationLowerIsBetterBadValue(): void
    {
        // ccn good = "below 4", value 10 is bad
        self::assertSame('too many code paths', $this->provider->getExplanation('complexity.ccn', 10.0));
    }

    #[Test]
    public function itGetExplanationLowerIsBetterGoodValue(): void
    {
        // ccn good = "below 4", value 3 is good
        self::assertSame('manageable branching', $this->provider->getExplanation('complexity.ccn', 3.0));
    }

    #[Test]
    public function itGetExplanationLowerIsBetterAtThreshold(): void
    {
        // ccn good = "below 4", value exactly 4 is still good (<=)
        self::assertSame('manageable branching', $this->provider->getExplanation('complexity.ccn', 4.0));
    }

    #[Test]
    public function itGetExplanationHigherIsBetterBadValue(): void
    {
        // tcc good = "above 0.5", value 0.2 is bad
        self::assertSame('methods share few common fields', $this->provider->getExplanation('cohesion.tcc', 0.2));
    }

    #[Test]
    public function itGetExplanationHigherIsBetterGoodValue(): void
    {
        // tcc good = "above 0.5", value 0.8 is good
        self::assertSame('methods share common fields', $this->provider->getExplanation('cohesion.tcc', 0.8));
    }

    #[Test]
    public function itGetExplanationHigherIsBetterAtThreshold(): void
    {
        // tcc good = "above 0.5", value exactly 0.5 is good (>=)
        self::assertSame('methods share common fields', $this->provider->getExplanation('cohesion.tcc', 0.5));
    }

    #[Test]
    public function itGetExplanationRangeBadValue(): void
    {
        // instability good = "0.3 – 0.7", value 0.1 is outside range
        self::assertSame('package is highly unstable', $this->provider->getExplanation('coupling.instability', 0.1));
        self::assertSame('package is highly unstable', $this->provider->getExplanation('coupling.instability', 0.9));
    }

    #[Test]
    public function itGetExplanationRangeGoodValue(): void
    {
        // instability good = "0.3 – 0.7", value 0.5 is in range
        self::assertSame('balanced stability', $this->provider->getExplanation('coupling.instability', 0.5));
    }

    #[Test]
    public function itGetExplanationRangeAtBoundaries(): void
    {
        self::assertSame('balanced stability', $this->provider->getExplanation('coupling.instability', 0.3));
        self::assertSame('balanced stability', $this->provider->getExplanation('coupling.instability', 0.7));
    }

    #[Test]
    public function itGetExplanationNeutral(): void
    {
        self::assertSame('', $this->provider->getExplanation('size.loc', 100.0));
        self::assertSame('', $this->provider->getExplanation('size.lloc', 50.0));
    }

    #[Test]
    public function itGetExplanationUnknownKey(): void
    {
        self::assertSame('', $this->provider->getExplanation('nonexistent', 5.0));
    }

    #[Test]
    public function itGetExplanationLcomPlaceholder(): void
    {
        // lcom bad = "class has {value} unrelated method groups"
        self::assertSame(
            'class has 5 unrelated method groups',
            $this->provider->getExplanation('cohesion.lcom', 5.0),
        );
    }

    #[Test]
    public function itGetExplanationLcomGoodValue(): void
    {
        // lcom good = "1 or less"
        self::assertSame('class is cohesive', $this->provider->getExplanation('cohesion.lcom', 1.0));
    }

    #[Test]
    public function itGetExplanationWithSuffixResolution(): void
    {
        // ccn.max resolves to ccn entry
        self::assertSame('too many code paths', $this->provider->getExplanation('complexity.ccn.max', 10.0));
    }

    #[Test]
    public function itGetExplanationTypeCoveragePercentage(): void
    {
        // typeCoverage.pct good = "above 80%", value 90 is good
        self::assertSame('well-typed code', $this->provider->getExplanation('design.type-coverage.pct', 90.0));
        self::assertSame('missing type declarations', $this->provider->getExplanation('design.type-coverage.pct', 50.0));
    }

    // --- getGoodValue ---

    #[Test]
    public function itGetGoodValueKnown(): void
    {
        self::assertSame('below 4', $this->provider->getGoodValue('complexity.ccn'));
        self::assertSame('above 0.5', $this->provider->getGoodValue('cohesion.tcc'));
        self::assertSame('0.3 – 0.7', $this->provider->getGoodValue('coupling.instability'));
    }

    #[Test]
    public function itGetGoodValueNeutral(): void
    {
        self::assertNull($this->provider->getGoodValue('size.loc'));
    }

    #[Test]
    public function itGetGoodValueUnknown(): void
    {
        self::assertNull($this->provider->getGoodValue('nonexistent'));
    }

    // --- getDirection ---

    #[Test]
    public function itGetDirectionKnown(): void
    {
        self::assertSame('lower_is_better', $this->provider->getDirection('complexity.ccn'));
        self::assertSame('higher_is_better', $this->provider->getDirection('cohesion.tcc'));
        self::assertSame('range', $this->provider->getDirection('coupling.instability'));
        self::assertSame('neutral', $this->provider->getDirection('size.loc'));
    }

    #[Test]
    public function itGetDirectionUnknown(): void
    {
        self::assertNull($this->provider->getDirection('nonexistent'));
    }

    // --- getDecomposition ---

    // --- getScoreLabel ---

    // --- getHealthDimensionLabel ---

}
