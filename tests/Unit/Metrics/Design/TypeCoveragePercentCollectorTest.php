<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Design;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Metrics\Design\TypeCoveragePercentCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        $fqn = 'App\\Service\\UserService';
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':' . $fqn, 3)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED . ':' . $fqn, 3)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL . ':' . $fqn, 2)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED . ':' . $fqn, 2)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL . ':' . $fqn, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED . ':' . $fqn, 1);

        $result = $this->collector->calculate($bag);

        self::assertSame(100.0, $result->get(MetricName::TYPE_COVERAGE_PCT . ':' . $fqn));
    }

    public function testPartiallyTypedClassReturnsCorrectPercentage(): void
    {
        $fqn = 'App\\Service\\OrderService';
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':' . $fqn, 2)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED . ':' . $fqn, 1)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL . ':' . $fqn, 2)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED . ':' . $fqn, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL . ':' . $fqn, 2)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED . ':' . $fqn, 1);

        $result = $this->collector->calculate($bag);

        self::assertSame(50.0, $result->get(MetricName::TYPE_COVERAGE_PCT . ':' . $fqn));
    }

    public function testUntypedClassReturns0Percent(): void
    {
        $fqn = 'App\\Legacy\\OldClass';
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':' . $fqn, 4)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED . ':' . $fqn, 0)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL . ':' . $fqn, 2)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED . ':' . $fqn, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL . ':' . $fqn, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED . ':' . $fqn, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(0.0, $result->get(MetricName::TYPE_COVERAGE_PCT . ':' . $fqn));
    }

    public function testClassWithZeroTotalsReturns100Percent(): void
    {
        $fqn = 'App\\Marker\\EmptyInterface';
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':' . $fqn, 0)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED . ':' . $fqn, 0)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL . ':' . $fqn, 0)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED . ':' . $fqn, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL . ':' . $fqn, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED . ':' . $fqn, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(100.0, $result->get(MetricName::TYPE_COVERAGE_PCT . ':' . $fqn));
    }

    public function testMultipleClassesInOneBag(): void
    {
        $fqnA = 'App\\A';
        $fqnB = 'App\\B';

        $bag = (new MetricBag())
            // Class A: fully typed (3/3)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':' . $fqnA, 1)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED . ':' . $fqnA, 1)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL . ':' . $fqnA, 1)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED . ':' . $fqnA, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL . ':' . $fqnA, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED . ':' . $fqnA, 1)
            // Class B: partially typed (1/3)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':' . $fqnB, 1)
            ->with(MetricName::TYPE_COVERAGE_PARAM_TYPED . ':' . $fqnB, 1)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL . ':' . $fqnB, 1)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TYPED . ':' . $fqnB, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL . ':' . $fqnB, 1)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TYPED . ':' . $fqnB, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(100.0, $result->get(MetricName::TYPE_COVERAGE_PCT . ':' . $fqnA));
        self::assertSame(33.33, $result->get(MetricName::TYPE_COVERAGE_PCT . ':' . $fqnB));
    }

    public function testEmptyBagReturnsEmptyResult(): void
    {
        $result = $this->collector->calculate(new MetricBag());

        self::assertSame([], $result->all());
    }

    public function testMissingTypedCountsDefaultToZero(): void
    {
        $fqn = 'App\\Sparse';
        // Only paramTotal is set, typed counts are missing
        $bag = (new MetricBag())
            ->with(MetricName::TYPE_COVERAGE_PARAM_TOTAL . ':' . $fqn, 3)
            ->with(MetricName::TYPE_COVERAGE_RETURN_TOTAL . ':' . $fqn, 0)
            ->with(MetricName::TYPE_COVERAGE_PROPERTY_TOTAL . ':' . $fqn, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(0.0, $result->get(MetricName::TYPE_COVERAGE_PCT . ':' . $fqn));
    }
}
