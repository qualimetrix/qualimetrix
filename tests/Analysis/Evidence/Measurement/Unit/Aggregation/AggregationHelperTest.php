<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Aggregation;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceMetricProviderInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(AggregationHelper::class)]
final class AggregationHelperTest extends TestCase
{
    #[Test]
    public function itMarksOnlyFileDefinitionsOfANamespaceProviderAndRetainsTheirExtraLevels(): void
    {
        $provider = self::createStubForIntersectionOfInterfaces([
            MetricCollectorInterface::class,
            NamespaceMetricProviderInterface::class,
        ]);
        $provider->method('getMetricDefinitions')->willReturn([
            new MetricDefinition(
                'size.loc',
                SymbolLevel::File,
                [SymbolLevel::Namespace_->value => [AggregationStrategy::Sum]],
                directPublicationLevels: [SymbolLevel::Project],
            ),
            new MetricDefinition(
                'size.class-count',
                SymbolLevel::File,
                directPublicationLevels: [SymbolLevel::Namespace_],
            ),
            new MetricDefinition(
                'size.class-loc',
                SymbolLevel::Class_,
                classKeyScope: ClassKeyScope::Declaration,
                directPublicationLevels: [SymbolLevel::Project],
            ),
        ]);

        $definitions = AggregationHelper::collectDefinitions([$provider]);

        self::assertCount(3, $definitions);
        self::assertTrue($definitions[0]->namespaceFileContribution);
        self::assertSame([SymbolLevel::File, SymbolLevel::Project, SymbolLevel::Namespace_], $definitions[0]->publicationLevels());
        self::assertSame([SymbolLevel::File, SymbolLevel::Namespace_], $definitions[1]->publicationLevels());
        self::assertFalse($definitions[2]->namespaceFileContribution);
        self::assertSame([SymbolLevel::Class_, SymbolLevel::Project], $definitions[2]->publicationLevels());
        self::assertSame(ClassKeyScope::Declaration, $definitions[2]->classKeyScope);
    }

    #[Test]
    public function itRefusesAProviderDefinitionWithUnsupportedNamespaceFileAggregation(): void
    {
        $provider = self::createStubForIntersectionOfInterfaces([
            MetricCollectorInterface::class,
            NamespaceMetricProviderInterface::class,
        ]);
        $provider->method('getMetricDefinitions')->willReturn([
            new MetricDefinition('size.loc', SymbolLevel::File, [SymbolLevel::Namespace_->value => [AggregationStrategy::Max]]),
        ]);

        $this->expectException(LogicException::class);
        AggregationHelper::collectDefinitions([$provider]);
    }

    /**
     * @return iterable<string, array{list<int|float>, float}>
     */
    public static function percentile95Provider(): iterable
    {
        yield 'single value' => [[10], 10.0];
        yield 'two values' => [[10, 20], 19.5];
        yield 'all same values' => [[5, 5, 5, 5, 5], 5.0];
        yield '100 values 1..100' => [range(1, 100), 95.05];
    }

    /**
     * @param list<int|float> $values
     */
    #[Test]
    #[DataProvider('percentile95Provider')]
    public function itComputesThe95thPercentileOfAValueList(array $values, float $expected): void
    {
        $result = AggregationHelper::applyStrategy(AggregationStrategy::Percentile95, $values);

        self::assertEqualsWithDelta($expected, $result, 0.0001);
    }

    #[Test]
    public function itDeclaresPercentile95AsAStrategyValuedP95(): void
    {
        self::assertSame('p95', AggregationStrategy::Percentile95->value);
    }

    /**
     * @return iterable<string, array{list<int|float>, float}>
     */
    public static function percentile5Provider(): iterable
    {
        yield 'single value' => [[10], 10.0];
        yield 'two values' => [[10, 20], 10.5];
        yield 'all same values' => [[5, 5, 5, 5, 5], 5.0];
        yield '100 values 1..100' => [range(1, 100), 5.95];
        yield 'MI-like distribution' => [[45, 60, 65, 70, 72, 75, 78, 80, 82, 85, 88, 90, 92, 95, 98, 100, 100, 100, 100, 100], 59.25];
    }

    /**
     * @param list<int|float> $values
     */
    #[Test]
    #[DataProvider('percentile5Provider')]
    public function itComputesThe5thPercentileOfAValueList(array $values, float $expected): void
    {
        $result = AggregationHelper::applyStrategy(AggregationStrategy::Percentile5, $values);

        self::assertEqualsWithDelta($expected, $result, 0.01);
    }

    #[Test]
    public function itDeclaresPercentile5AsAStrategyValuedP5(): void
    {
        self::assertSame('p5', AggregationStrategy::Percentile5->value);
    }
}
