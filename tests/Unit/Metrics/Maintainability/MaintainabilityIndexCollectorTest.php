<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Maintainability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCalculator;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;

#[CoversClass(MaintainabilityIndexCollector::class)]
#[CoversClass(MaintainabilityIndexCalculator::class)]
final class MaintainabilityIndexCollectorTest extends TestCase
{
    private MaintainabilityIndexCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new MaintainabilityIndexCollector();
    }

    public function testGetName(): void
    {
        self::assertSame('maintainability-index', $this->collector->getName());
    }

    public function testProvides(): void
    {
        self::assertSame(['mi'], $this->collector->provides());
    }

    public function testRequires(): void
    {
        $requires = $this->collector->requires();

        self::assertContains('halstead', $requires);
        self::assertContains('cyclomatic-complexity', $requires);
    }

    public function testCalculateWithValidMetrics(): void
    {
        $sourceBag = (new MetricBag())
            ->with('halstead.volume', 100.0)
            ->with('ccn', 5);

        $result = $this->collector->calculate($sourceBag);

        self::assertTrue($result->has('mi'));
        $mi = $result->get('mi');
        self::assertIsFloat($mi);
        self::assertGreaterThan(0, $mi);
        self::assertLessThanOrEqual(100, $mi);
    }

    public function testCalculateWithZeroVolume(): void
    {
        $sourceBag = (new MetricBag())
            ->with('halstead.volume', 0.0)
            ->with('ccn', 1);

        $result = $this->collector->calculate($sourceBag);

        // Empty method should have perfect MI
        self::assertSame(100.0, $result->get('mi'));
    }

    public function testCalculateWithMissingMetrics(): void
    {
        $sourceBag = new MetricBag();
        // Missing halstead.volume and ccn

        $result = $this->collector->calculate($sourceBag);

        // Without Halstead volume, MI cannot be calculated (e.g. class-level FQN)
        self::assertFalse($result->has('mi'));
    }

    public function testCalculateWithHighComplexity(): void
    {
        $sourceBag = (new MetricBag())
            ->with('halstead.volume', 500.0)
            ->with('ccn', 20);

        $result = $this->collector->calculate($sourceBag);

        // High complexity should result in lower MI
        $mi = $result->get('mi');
        self::assertLessThan(80, $mi);
    }

    public function testGetMetricDefinitions(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $miDef = $definitions[0];
        self::assertSame('mi', $miDef->name);
        self::assertSame(SymbolLevel::Method, $miDef->collectedAt);

        // Check aggregations
        $classStrategies = $miDef->getStrategiesForLevel(SymbolLevel::Class_);
        self::assertContains(AggregationStrategy::Average, $classStrategies);
        self::assertContains(AggregationStrategy::Min, $classStrategies);

        $namespaceStrategies = $miDef->getStrategiesForLevel(SymbolLevel::Namespace_);
        self::assertContains(AggregationStrategy::Average, $namespaceStrategies);
        self::assertContains(AggregationStrategy::Min, $namespaceStrategies);
    }
}
