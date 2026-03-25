<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Design;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\Design\TypeCoveragePercentCollector;

#[CoversClass(TypeCoveragePercentCollector::class)]
final class TypeCoveragePercentCollectorTest extends TestCase
{
    private TypeCoveragePercentCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new TypeCoveragePercentCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('type-coverage-pct', $this->collector->getName());
    }

    public function testRequiresTypeCoverage(): void
    {
        self::assertSame(['type-coverage'], $this->collector->requires());
    }

    public function testProvidesTypeCoveragePct(): void
    {
        self::assertSame([MetricName::TYPE_COVERAGE_PCT], $this->collector->provides());
    }

    public function testMetricDefinitionsReturnClassLevelWithNoAggregation(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);
        self::assertSame(MetricName::TYPE_COVERAGE_PCT, $definitions[0]->name);
        self::assertSame(SymbolLevel::Class_, $definitions[0]->collectedAt);
        self::assertSame([], $definitions[0]->aggregations);
    }

    public function testFullyTypedClassReturns100Percent(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL, 3)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED, 3)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL, 2)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED, 2)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED, 1);

        $result = $this->collector->calculate($bag);

        self::assertSame(100.0, $result->get(MetricName::TYPE_COVERAGE_PCT));
    }

    public function testPartiallyTypedClassReturnsCorrectPercentage(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL, 2)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED, 1)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL, 2)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL, 2)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED, 1);

        $result = $this->collector->calculate($bag);

        self::assertSame(50.0, $result->get(MetricName::TYPE_COVERAGE_PCT));
    }

    public function testUntypedClassReturns0Percent(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL, 4)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED, 0)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL, 2)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(0.0, $result->get(MetricName::TYPE_COVERAGE_PCT));
    }

    public function testClassWithZeroTotalsReturns100Percent(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL, 0)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED, 0)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL, 0)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(100.0, $result->get(MetricName::TYPE_COVERAGE_PCT));
    }

    public function testEmptyBagReturns100Percent(): void
    {
        $result = $this->collector->calculate(new MetricBag());

        self::assertSame(100.0, $result->get(MetricName::TYPE_COVERAGE_PCT));
    }

    public function testMissingTypedCountsDefaultToZero(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL, 3)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(0.0, $result->get(MetricName::TYPE_COVERAGE_PCT));
    }
}
