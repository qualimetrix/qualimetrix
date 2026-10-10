<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\RfcCollector;
use Qualimetrix\Analysis\Evidence\Coupling\RfcGlobalCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Tests\Analysis\Evidence\Coupling\Support\RfcMeasurement;

#[CoversClass(RfcCollector::class)]
#[CoversClass(RfcGlobalCollector::class)]
final class RfcCollectorTest extends TestCase
{
    #[Test]
    public function itProvidesOnlyRawRowsBeforeTheSoleGlobalPublisher(): void
    {
        $local = new RfcCollector();
        $global = new RfcGlobalCollector();
        self::assertSame('rfc', $local->getName());
        self::assertSame([RfcCollector::FACTS], $local->provides());
        self::assertSame([], $local->getMetricDefinitions());
        self::assertSame($local->provides(), $global->requires());
        self::assertSame(['coupling.rfc', 'coupling.rfc-own', 'coupling.rfc-external'], $global->provides());
        self::assertNotContains(\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface::class, class_implements($local));
        $definitions = $global->getMetricDefinitions();
        self::assertSame($global->provides(), array_map(static fn($definition): string => $definition->name, $definitions));
        foreach ($definitions as $definition) {
            self::assertSame(SymbolLevel::Class_, $definition->collectedAt);
            foreach ([SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
                self::assertSame([AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max, AggregationStrategy::Percentile95], $definition->getStrategiesForLevel($level));
            }
        }
    }

    #[Test]
    public function itKeepsExactSameNameBodiesAndRowsThroughSerialization(): void
    {
        $repository = RfcMeasurement::measure(['Twin.php' => '<?php namespace App; class Twin { function one() {} } if (false) { class Twin { function one() { Logger::log(); } function two() {} } }']);
        $classes = iterator_to_array($repository->allClassDeclarations());
        self::assertCount(2, $classes);
        $values = [];
        foreach ($classes as $class) {
            self::assertNotNull($class->subject);
            $bag = $repository->getSubject($class->subject);
            self::assertNotEmpty($bag->entries(RfcCollector::FACTS));
            $values[] = $bag->get('coupling.rfc');
        }
        self::assertSame([1, 3], $values);
    }

    #[Test]
    public function itResetsItsRawDeclarationStateBetweenFiles(): void
    {
        $repository = RfcMeasurement::measure(['First.php' => '<?php class First { function run() { Logger::log(); } }', 'Second.php' => '<?php class Second { function run() {} }']);
        $bags = RfcMeasurement::byName($repository);
        self::assertSame(2, $bags['First']->get('coupling.rfc'));
        self::assertSame(1, $bags['Second']->get('coupling.rfc'));
        self::assertCount(2, $bags);
    }
}
