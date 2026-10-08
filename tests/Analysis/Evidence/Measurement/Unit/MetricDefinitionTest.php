<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(MetricDefinition::class)]
final class MetricDefinitionTest extends TestCase
{
    #[Test]
    public function itConstructorSetsProperties(): void
    {
        $aggregations = [
            SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            SymbolLevel::Namespace_->value => [AggregationStrategy::Sum],
        ];

        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: $aggregations,
        );

        self::assertSame('complexity.ccn', $definition->name);
        self::assertSame(SymbolLevel::Callable, $definition->collectedAt);
        self::assertSame($aggregations, $definition->aggregations);
    }

    #[Test]
    public function itConstructorWithDefaultAggregations(): void
    {
        $definition = new MetricDefinition(
            name: 'size.loc',
            collectedAt: SymbolLevel::File,
        );

        self::assertSame('size.loc', $definition->name);
        self::assertSame(SymbolLevel::File, $definition->collectedAt);
        self::assertSame([], $definition->aggregations);
        self::assertNull($definition->classKeyScope);
    }

    #[Test]
    public function itDescribesThePublicationAreaForAClassMetric(): void
    {
        $definition = new MetricDefinition(
            name: 'size.class-loc',
            collectedAt: SymbolLevel::Class_,
            classKeyScope: ClassKeyScope::Declaration,
        );

        self::assertSame(ClassKeyScope::Declaration, $definition->classKeyScope);
    }

    #[Test]
    public function itKeepsDirectBasePublicationSeparateFromAggregatedSuffixes(): void
    {
        $definition = new MetricDefinition(
            name: 'coupling.ce',
            collectedAt: SymbolLevel::Class_,
            aggregations: [SymbolLevel::Namespace_->value => [AggregationStrategy::Average]],
            directPublicationLevels: [SymbolLevel::Namespace_],
        );

        self::assertSame([SymbolLevel::Class_, SymbolLevel::Namespace_], $definition->publicationLevels());
        self::assertSame(['avg', 'count'], $definition->publishedSuffixes(SymbolLevel::Namespace_));
        self::assertSame([], $definition->publishedSuffixes(SymbolLevel::Project));
    }

    #[Test]
    public function itRefusesRepeatingTheCollectedLevelAsAnExtraPublicationLevel(): void
    {
        $this->expectException(LogicException::class);
        new MetricDefinition('size.loc', SymbolLevel::File, directPublicationLevels: [SymbolLevel::File]);
    }

    #[Test]
    public function itRefusesDuplicateExtraPublicationLevels(): void
    {
        $this->expectException(LogicException::class);
        new MetricDefinition('size.loc', SymbolLevel::File, directPublicationLevels: [SymbolLevel::Namespace_, SymbolLevel::Namespace_]);
    }

    #[Test]
    public function itPublishesTheCountSuffixRequiredByAnAverage(): void
    {
        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Average],
            ],
        );

        self::assertSame(['avg', 'count'], $definition->publishedSuffixes(SymbolLevel::Class_));
    }

    #[Test]
    public function itRefusesAnUnsupportedNamespaceFileContributionStrategy(): void
    {
        $this->expectException(LogicException::class);

        new MetricDefinition(
            name: 'size.loc',
            collectedAt: SymbolLevel::File,
            aggregations: [SymbolLevel::Namespace_->value => [AggregationStrategy::Max]],
            namespaceFileContribution: true,
        );
    }

    #[DataProvider('aggregatedNameProvider')]
    #[Test]
    public function itAggregatedName(string $metricName, AggregationStrategy $strategy, string $expected): void
    {
        $definition = new MetricDefinition(
            name: $metricName,
            collectedAt: SymbolLevel::Callable,
        );

        self::assertSame($expected, $definition->aggregatedName($strategy));
    }

    /**
     * @return iterable<string, array{string, AggregationStrategy, string}>
     */
    public static function aggregatedNameProvider(): iterable
    {
        yield 'ccn with sum' => ['complexity.ccn', AggregationStrategy::Sum, 'complexity.ccn.sum'];
        yield 'ccn with avg' => ['complexity.ccn', AggregationStrategy::Average, 'complexity.ccn.avg'];
        yield 'ccn with max' => ['complexity.ccn', AggregationStrategy::Max, 'complexity.ccn.max'];
        yield 'ccn with min' => ['complexity.ccn', AggregationStrategy::Min, 'complexity.ccn.min'];
        yield 'ccn with count' => ['complexity.ccn', AggregationStrategy::Count, 'complexity.ccn.count'];
        yield 'loc with sum' => ['size.loc', AggregationStrategy::Sum, 'size.loc.sum'];
        yield 'classCount with sum' => ['size.class-count', AggregationStrategy::Sum, 'size.class-count.sum'];
    }

    #[Test]
    public function itGetStrategiesForLevelReturnsStrategies(): void
    {
        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
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

    #[Test]
    public function itGetStrategiesForLevelReturnsEmptyArrayForUndefinedLevel(): void
    {
        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum],
            ],
        );

        self::assertSame([], $definition->getStrategiesForLevel(SymbolLevel::Project));
        self::assertSame([], $definition->getStrategiesForLevel(SymbolLevel::Namespace_));
    }

    #[Test]
    public function itHasAggregationsForLevelReturnsTrueWhenDefined(): void
    {
        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
            ],
        );

        self::assertTrue($definition->hasAggregationsForLevel(SymbolLevel::Class_));
    }

    #[Test]
    public function itHasAggregationsForLevelReturnsFalseWhenNotDefined(): void
    {
        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Class_->value => [AggregationStrategy::Sum],
            ],
        );

        self::assertFalse($definition->hasAggregationsForLevel(SymbolLevel::Namespace_));
        self::assertFalse($definition->hasAggregationsForLevel(SymbolLevel::Project));
    }

    #[Test]
    public function itHasAggregationsForLevelReturnsFalseForEmptyArray(): void
    {
        $definition = new MetricDefinition(
            name: 'complexity.ccn',
            collectedAt: SymbolLevel::Callable,
            aggregations: [
                SymbolLevel::Class_->value => [],
            ],
        );

        self::assertFalse($definition->hasAggregationsForLevel(SymbolLevel::Class_));
    }
}
