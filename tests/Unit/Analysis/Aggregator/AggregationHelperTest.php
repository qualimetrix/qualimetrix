<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\AggregationHelper;
use Qualimetrix\Core\Metric\AggregationStrategy;

#[CoversClass(AggregationHelper::class)]
final class AggregationHelperTest extends TestCase
{
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
    public function percentile95ReturnsExpectedValue(array $values, float $expected): void
    {
        $result = AggregationHelper::applyStrategy(AggregationStrategy::Percentile95, $values);

        self::assertEqualsWithDelta($expected, $result, 0.0001);
    }

    #[Test]
    public function percentile95IsIncludedInAggregationStrategyEnum(): void
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
    public function percentile5ReturnsExpectedValue(array $values, float $expected): void
    {
        $result = AggregationHelper::applyStrategy(AggregationStrategy::Percentile5, $values);

        self::assertEqualsWithDelta($expected, $result, 0.01);
    }

    #[Test]
    public function percentile5IsIncludedInAggregationStrategyEnum(): void
    {
        self::assertSame('p5', AggregationStrategy::Percentile5->value);
    }
}
