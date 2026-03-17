<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Aggregator;

use AiMessDetector\Analysis\Aggregator\AggregationHelper;
use AiMessDetector\Core\Metric\AggregationStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
