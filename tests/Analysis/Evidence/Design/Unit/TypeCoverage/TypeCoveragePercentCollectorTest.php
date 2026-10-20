<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\TypeCoverage;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoverageCollector;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoveragePercentCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Symbol\SymbolLevel;
use SplFileInfo;

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
        self::assertSame([MetricName::DESIGN_TYPE_COVERAGE_ALL], $this->collector->provides());
    }

    #[Test]
    public function itReturnsClassLevelMetricDefinitionWithNoAggregation(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);
        self::assertSame(MetricName::DESIGN_TYPE_COVERAGE_ALL, $definitions[0]->name);
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

        self::assertSame(100.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_ALL));
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

        self::assertSame(50.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_ALL));
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

        self::assertSame(0.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_ALL));
    }

    #[Test]
    public function itOmitsPercentageWhenAllTotalsAreZero(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TYPED, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TYPED, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TYPED, 0);

        $result = $this->collector->calculate($bag);

        self::assertNull($result->get(MetricName::DESIGN_TYPE_COVERAGE_ALL));
    }

    #[Test]
    public function itOmitsPercentagesForIntegerAndFloatingPointZeroTotals(): void
    {
        foreach ([0, 0.0, -0.0] as $zero) {
            $bag = MetricBag::fromArray([
                MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL => $zero,
                MetricName::DESIGN_TYPE_COVERAGE_PARAM_TYPED => $zero,
                MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL => $zero,
                MetricName::DESIGN_TYPE_COVERAGE_RETURN_TYPED => $zero,
                MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL => $zero,
                MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TYPED => $zero,
            ]);
            self::assertSame([], $this->collector->calculate($bag)->all());
        }
    }

    #[Test]
    public function itOmitsPercentageForEmptyBag(): void
    {
        $result = $this->collector->calculate(new MetricBag());

        self::assertNull($result->get(MetricName::DESIGN_TYPE_COVERAGE_ALL));
    }

    #[Test]
    public function itDefaultsMissingTypedCountsToZero(): void
    {
        $bag = (new MetricBag())
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL, 3)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL, 0)
            ->with(MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL, 0);

        $result = $this->collector->calculate($bag);

        self::assertSame(0.0, $result->get(MetricName::DESIGN_TYPE_COVERAGE_ALL));
    }
    #[Test]
    public function itPreservesSixRawZerosAndTheirNonemptySumWithoutPercentages(): void
    {
        $collector = new TypeCoverageCollector();
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php class Marker {}');
        self::assertNotNull($ast);
        (new NodeTraverser($collector->getVisitor()))->traverse($ast);
        $source = $collector->collect(new SplFileInfo('marker.php'), $ast);
        $classBag = new MetricBag();
        $values = [];
        foreach (['param', 'return', 'property'] as $dimension) {
            foreach (['total', 'typed'] as $fact) {
                $key = 'design.type-coverage.' . $dimension . '.' . $fact;
                self::assertSame(0, $source->get($key . ':Marker'));
                $classBag = $classBag->with($key, 0);
                $values[$key] = [0];
            }
            self::assertNull($source->get('design.type-coverage.' . $dimension . ':Marker'));
        }
        self::assertSame([], $this->collector->calculate($classBag)->all());
        foreach ([SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
            $aggregate = AggregationHelper::applyAggregations($values, $collector->getMetricDefinitions(), $level);
            foreach (array_keys($values) as $key) {
                self::assertSame(0, $aggregate->get($key . '.sum'));
            }
            self::assertSame([], AggregationHelper::applyAggregations([], $collector->getMetricDefinitions(), $level)->all());
        }
    }

}
