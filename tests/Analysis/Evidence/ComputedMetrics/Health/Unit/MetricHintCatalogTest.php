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
        // ccn.min is not explicitly defined, but ccn is — should resolve via suffix stripping
        self::assertSame('Cyclomatic', $this->provider->getLabel('complexity.ccn.min'));
        self::assertSame('CBO', $this->provider->getLabel('coupling.cbo.sum'));
    }

    #[Test]
    public function itGetLabelPreferExactMatch(): void
    {
        // ccn.avg is explicitly defined — should return its own label, not ccn's
        self::assertSame('Cyclomatic (avg)', $this->provider->getLabel('complexity.ccn.avg'));
    }

    /**
     * `coupling.cbo-own` is a base key, not a suffix of `coupling.cbo`, so it
     * resolves to nothing unless it has an entry of its own — and a report
     * then prints the raw key with an empty target where a label belongs.
     */
    #[Test]
    public function itDescribesTheOwnScopeCboLikeItsSiblings(): void
    {
        self::assertSame('CBO (own)', $this->provider->getLabel('coupling.cbo-own'));
        self::assertSame('lower_is_better', $this->provider->getDirection('coupling.cbo-own'));
        self::assertSame($this->provider->getGoodValue('coupling.cbo'), $this->provider->getGoodValue('coupling.cbo-own'));
        self::assertNotSame('', $this->provider->getExplanation('coupling.cbo-own', 30.0));
        self::assertArrayHasKey('coupling.cbo-own', $this->provider->metricHints());
    }

    #[Test]
    public function itDescribesTheInclusiveClassRankHotspotHintBoundaryTruthfully(): void
    {
        $ranges = $this->provider->metricHints()['coupling.class-rank-share']['ranges'];
        self::assertSame(['max' => 5, 'text' => 'At most hotspot share'], $ranges[1]);
    }

    #[Test]
    public function itDescribesRfcAsAResponseSetRatherThanAnInterface(): void
    {
        $hint = $this->provider->metricHints()['coupling.rfc'];
        self::assertSame('Response for a Class', $hint['label']);
        self::assertSame([
            ['max' => 20, 'text' => 'Small response set'],
            ['max' => 50, 'text' => 'Moderate response set'],
            ['max' => 100, 'text' => 'Large response set'],
            ['above' => true, 'text' => 'Very large response set'],
        ], $hint['ranges']);
        self::assertSame('large response set', $this->provider->getExplanation('coupling.rfc', 51));
        self::assertSame('limited response set', $this->provider->getExplanation('coupling.rfc', 10));
    }

    #[Test]
    public function itDescribesAggregateCeAsOneJointBreadthTerm(): void
    {
        $target = 'joint breadth at most 4 (3 * Ce pkg avg + 0.5 * sqrt(Ce avg))';
        foreach (['coupling.ce.avg', 'coupling.ce-packages.avg'] as $key) {
            self::assertSame($target, $this->provider->getGoodValue($key));
            self::assertSame(
                $this->provider->getExplanation($key, 0.0),
                $this->provider->getExplanation($key, 100.0),
                'one component cannot determine whether the joint penalty applies',
            );
            self::assertSame([
                ['above' => true, 'text' => 'Joint breadth target: 3 * Ce pkg avg + 0.5 * sqrt(Ce avg) at most 4; neither component has an independent threshold'],
            ], $this->provider->metricHints()[$key]['ranges']);
        }
    }

    #[Test]
    public function itKeepsTheMaximumCeCeilingSeparateFromAverageBreadth(): void
    {
        self::assertSame('below 30', $this->provider->getGoodValue('coupling.ce.max'));
        self::assertSame('below 3', $this->provider->getGoodValue('coupling.ce'));
        self::assertSame('no class exceeds the outgoing dependency ceiling', $this->provider->getExplanation('coupling.ce.max', 30.0));
        self::assertSame('one class has many outgoing dependencies', $this->provider->getExplanation('coupling.ce.max', 30.1));
        self::assertSame(
            ['max' => 30, 'text' => 'No class exceeds the outgoing dependency ceiling'],
            $this->provider->metricHints()['coupling.ce.max']['ranges'][0] ?? null,
        );
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
        // ccn.min resolves to ccn entry
        self::assertSame('too many code paths', $this->provider->getExplanation('complexity.ccn.min', 10.0));
    }

    #[Test]
    public function itGetExplanationTypeCoveragePercentage(): void
    {
        // typeCoverage.pct good = "above 80%", value 90 is good
        self::assertSame('well-typed code', $this->provider->getExplanation('design.type-coverage.all', 90.0));
        self::assertSame('missing type declarations', $this->provider->getExplanation('design.type-coverage.all', 50.0));
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
}
