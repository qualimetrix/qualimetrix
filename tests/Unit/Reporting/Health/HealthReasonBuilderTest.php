<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Health\HealthReasonBuilder;
use Qualimetrix\Reporting\Health\MetricHintProvider;

#[CoversClass(HealthReasonBuilder::class)]
final class HealthReasonBuilderTest extends TestCase
{
    private HealthReasonBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HealthReasonBuilder(new MetricHintProvider());
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', $this->builder->buildReason([]));
    }

    public function testReturnsEmptyStringWhenAllAboveWarningThreshold(): void
    {
        // Default warning threshold for health dimensions is 50.0
        $scores = [
            'complexity' => 80.0,
            'cohesion' => 90.0,
            'coupling' => 75.0,
        ];

        self::assertSame('', $this->builder->buildReason($scores));
    }

    public function testReturnsSingleReasonForOneBadDimension(): void
    {
        $scores = [
            'complexity' => 20.0, // Below 50 -> bad
            'cohesion' => 80.0,   // Above 50 -> good
        ];

        $reason = $this->builder->buildReason($scores);

        // Should contain the bad label for complexity
        self::assertSame('high complexity', $reason);
    }

    public function testReturnsUpToTwoWorstReasons(): void
    {
        $scores = [
            'complexity' => 10.0,     // Very bad
            'cohesion' => 20.0,       // Bad
            'coupling' => 30.0,       // Bad
            'maintainability' => 80.0, // Good
        ];

        $reason = $this->builder->buildReason($scores);

        // Should contain at most 2 worst dimensions
        $parts = explode(', ', $reason);
        self::assertLessThanOrEqual(2, \count($parts));
        // Worst first: complexity (delta -40), then cohesion (delta -30)
        self::assertStringContainsString('high complexity', $reason);
    }

    public function testDimensionExactlyAtThresholdIsBad(): void
    {
        // Delta = score - warnThreshold = 50.0 - 50.0 = 0 -> bad (not strictly above)
        $scores = [
            'complexity' => 50.0,
        ];

        $reason = $this->builder->buildReason($scores);

        self::assertSame('high complexity', $reason);
    }

    public function testDimensionJustAboveThresholdIsGood(): void
    {
        $scores = [
            'complexity' => 50.1,
        ];

        $reason = $this->builder->buildReason($scores);

        self::assertSame('', $reason);
    }

    public function testUnknownDimensionNameUsedAsIs(): void
    {
        // Unknown dimensions that fall below threshold use the dimension name directly
        $scores = [
            'unknown_dim' => 10.0,
        ];

        $reason = $this->builder->buildReason($scores);

        // MetricHintProvider returns the raw dimension name for unknown dimensions
        self::assertSame('unknown_dim', $reason);
    }
}
