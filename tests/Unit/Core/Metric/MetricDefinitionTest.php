<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Metric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\SymbolLevel;

#[CoversClass(MetricDefinition::class)]
final class MetricDefinitionTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $aggregations = [
            SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
        ];

        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: $aggregations,
        );

        self::assertSame('ccn', $definition->name);
        self::assertSame(SymbolLevel::Method, $definition->collectedAt);
        self::assertSame($aggregations, $definition->aggregations);
    }

    public function testConstructorWithDefaultAggregations(): void
    {
        $definition = new MetricDefinition(
            name: 'loc',
            collectedAt: SymbolLevel::File,
        );

        self::assertSame('loc', $definition->name);
        self::assertSame(SymbolLevel::File, $definition->collectedAt);
        self::assertSame([], $definition->aggregations);
    }

    #[DataProvider('aggregatedNameProvider')]
    public function testAggregatedName(string $metricName, AggregationStrategy $strategy, string $expected): void
    {
        $definition = new MetricDefinition(
            name: $metricName,
            collectedAt: SymbolLevel::Method,
        );

        self::assertSame($expected, $definition->aggregatedName($strategy));
    }

    /**
     * @return iterable<string, array{string, AggregationStrategy, string}>
     */
    public static function aggregatedNameProvider(): iterable
    {
        yield 'ccn with sum' => ['ccn', AggregationStrategy::Sum, 'ccn.sum'];
        yield 'ccn with avg' => ['ccn', AggregationStrategy::Average, 'ccn.avg'];
        yield 'ccn with max' => ['ccn', AggregationStrategy::Max, 'ccn.max'];
        yield 'ccn with min' => ['ccn', AggregationStrategy::Min, 'ccn.min'];
        yield 'ccn with count' => ['ccn', AggregationStrategy::Count, 'ccn.count'];
        yield 'loc with sum' => ['loc', AggregationStrategy::Sum, 'loc.sum'];
        yield 'classCount with sum' => ['classCount', AggregationStrategy::Sum, 'classCount.sum'];
    }

    public function testGetStrategiesForLevelReturnsStrategies(): void
    {
        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Class_->value => [
                    AggregationStrategy::Sum,
                    AggregationStrategy::Average,
                    AggregationStrategy::Max,
                ],
                SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
            ],
        );

        self::assertSame(
            [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
            $definition->getStrategiesForLevel(SymbolLevel::Class_),
        );
        self::assertSame(
            [AggregationStrategy::Sum],
            $definition->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
    }

    public function testGetStrategiesForLevelReturnsEmptyArrayForUndefinedLevel(): void
    {
        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum],
            ],
        );

        self::assertSame([], $definition->getStrategiesForLevel(SymbolLevel::Project));
        self::assertSame([], $definition->getStrategiesForLevel(SymbolLevel::Namespace_));
    }

    public function testHasAggregationsForLevelReturnsTrueWhenDefined(): void
    {
        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            ],
        );

        self::assertTrue($definition->hasAggregationsForLevel(SymbolLevel::Class_));
    }

    public function testHasAggregationsForLevelReturnsFalseWhenNotDefined(): void
    {
        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum],
            ],
        );

        self::assertFalse($definition->hasAggregationsForLevel(SymbolLevel::Namespace_));
        self::assertFalse($definition->hasAggregationsForLevel(SymbolLevel::Project));
    }

    public function testHasAggregationsForLevelReturnsFalseForEmptyArray(): void
    {
        $definition = new MetricDefinition(
            name: 'ccn',
            collectedAt: SymbolLevel::Method,
            aggregations: [
                SymbolLevel::Class_->value => [],
            ],
        );

        self::assertFalse($definition->hasAggregationsForLevel(SymbolLevel::Class_));
    }
}
