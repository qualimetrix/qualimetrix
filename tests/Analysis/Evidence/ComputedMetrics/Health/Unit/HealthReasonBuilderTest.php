<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDimensionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\HealthReasonBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\OffenderThresholds;

#[CoversClass(HealthReasonBuilder::class)]
final class HealthReasonBuilderTest extends TestCase
{
    private HealthReasonBuilder $builder;
    private OffenderThresholds $thresholds;

    protected function setUp(): void
    {
        $catalog = self::createStub(\Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn(array_values(\Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults::getDefaults()));
        $this->thresholds = OffenderThresholds::fromCatalog($catalog);
        $this->builder = new HealthReasonBuilder(new HealthDimensionCatalog());
    }

    #[Test]
    public function itReturnsEmptyStringForEmptyInput(): void
    {
        self::assertSame('', $this->builder->buildReason([], $this->thresholds));
    }

    #[Test]
    public function itReturnsEmptyStringWhenAllAboveWarningThreshold(): void
    {
        // Default warning threshold for health dimensions is 50.0
        $scores = [
            'complexity' => 80.0,
            'cohesion' => 90.0,
            'coupling' => 75.0,
        ];

        self::assertSame('', $this->builder->buildReason($scores, $this->thresholds));
    }

    #[Test]
    public function itReturnsSingleReasonForOneBadDimension(): void
    {
        $scores = [
            'complexity' => 20.0, // Below 50 -> bad
            'cohesion' => 80.0,   // Above 50 -> good
        ];

        $reason = $this->builder->buildReason($scores, $this->thresholds);

        // Should contain the bad label for complexity
        self::assertSame('high complexity', $reason);
    }

    #[Test]
    public function itReturnsUpToTwoWorstReasons(): void
    {
        $scores = [
            'complexity' => 10.0,     // Very bad
            'cohesion' => 20.0,       // Bad
            'coupling' => 30.0,       // Bad
            'maintainability' => 80.0, // Good
        ];

        $reason = $this->builder->buildReason($scores, $this->thresholds);

        // Should contain at most 2 worst dimensions
        $parts = explode(', ', $reason);
        self::assertLessThanOrEqual(2, \count($parts));
        // Worst first: complexity (delta -40), then cohesion (delta -30)
        self::assertStringContainsString('high complexity', $reason);
    }

    #[Test]
    public function itDimensionExactlyAtThresholdIsBad(): void
    {
        // Delta = score - warnThreshold = 50.0 - 50.0 = 0 -> bad (not strictly above)
        $scores = [
            'complexity' => 50.0,
        ];

        $reason = $this->builder->buildReason($scores, $this->thresholds);

        self::assertSame('high complexity', $reason);
    }

    #[Test]
    public function itDimensionJustAboveThresholdIsGood(): void
    {
        $scores = [
            'complexity' => 50.1,
        ];

        $reason = $this->builder->buildReason($scores, $this->thresholds);

        self::assertSame('', $reason);
    }

    #[Test]
    public function itExcludesUnknownAndOverallDimensions(): void
    {
        $scores = [
            'unknown_dim' => 10.0,
            'overall' => 0.0,
        ];

        $reason = $this->builder->buildReason($scores, $this->thresholds);

        self::assertSame('', $reason);
    }
}
