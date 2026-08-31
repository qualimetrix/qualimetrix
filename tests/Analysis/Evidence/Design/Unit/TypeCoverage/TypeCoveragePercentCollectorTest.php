<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\TypeCoverage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoveragePercentCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(TypeCoveragePercentCollector::class)]
final class TypeCoveragePercentCollectorTest extends TestCase
{
    private TypeCoveragePercentCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new TypeCoveragePercentCollector();
    }

    #[Test]
    public function itReturnsCollectorName(): void
    {
        self::assertSame('type-coverage-pct', $this->collector->getName());
    }

    #[Test]
    public function itRequiresTypeCoverageDependency(): void
    {
        self::assertSame(['type-coverage'], $this->collector->requires());
    }

    #[Test]
    public function itProvidesTypeCoveragePctMetric(): void
    {
        self::assertSame([MetricName::DESIGN_TYPE_COVERAGE_PCT], $this->collector->provides());
    }

    #[Test]
    public function itReturnsClassLevelMetricDefinitionWithNoAggregation(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);
        self::assertSame(MetricName::DESIGN_TYPE_COVERAGE_PCT, $definitions[0]->name);
        self::assertSame(SymbolLevel::Class_, $definitions[0]->collectedAt);
        self::assertSame([], $definitions[0]->aggregations);
    }

    #[Test]
    public function itReturns100PercentForFullyTypedClass(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, 3)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TYPED, 3)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, 2)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TYPED, 2)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, 1)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TYPED, 1);

        $result = $this->collector->calculate($bag);

        self::assertSame(100.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_PCT));
    }

    #[Test]
    public function itReturnsCorrectPercentageForPartiallyTypedClass(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, 2)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TYPED, 1)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, 2)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TYPED, 1)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, 2)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TYPED, 1);

        $result = $this->collector->calculate($bag);

        self::assertSame(50.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_PCT));
    }

    #[Test]
    public function itReturns0PercentForUntypedClass(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, 4)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TYPED, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, 2)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TYPED, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, 1)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TYPED, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(0.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_PCT));
    }

    #[Test]
    public function itReturns100PercentWhenAllTotalsAreZero(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TYPED, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TYPED, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TYPED, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(100.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_PCT));
    }

    #[Test]
    public function itReturns100PercentForEmptyBag(): void
    {
        $result = $this->collector->calculate(new MetricBag());

        self::assertSame(100.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_PCT));
    }

    #[Test]
    public function itDefaultsMissingTypedCountsToZero(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, 3)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(0.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_PCT));
    }
}
