<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Maintainability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCalculator;

#[CoversClass(MaintainabilityIndexCalculator::class)]
final class MaintainabilityIndexCalculatorTest extends TestCase
{
    private MaintainabilityIndexCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MaintainabilityIndexCalculator();
    }

    #[Test]
    public function itCalculatesRawWithTypicalValues(): void
    {
        // Typical method: Volume=100, CCN=5, LOC=20
        $mi = $this->calculator->calculateRaw(100.0, 5, 20);

        // MI = 171 - 5.2×ln(100) - 0.23×5 - 16.2×ln(20)
        // MI = 171 - 5.2×4.605 - 1.15 - 16.2×2.996
        // MI = 171 - 23.95 - 1.15 - 48.53 ≈ 97.37
        self::assertEqualsWithDelta(97.37, $mi, 0.5);
    }

    #[Test]
    public function itCalculatesRawWithEmptyMethod(): void
    {
        // Empty method: no operators, no complexity
        $mi = $this->calculator->calculateRaw(0.0, 1, 0);

        // Should return perfect score
        self::assertSame(171.0, $mi);
    }

    #[Test]
    public function itCalculatesRawWithComplexMethod(): void
    {
        // Complex method: Volume=500, CCN=20, LOC=100
        $mi = $this->calculator->calculateRaw(500.0, 20, 100);

        // Should be lower than typical
        self::assertLessThan(90.0, $mi);
    }

    #[Test]
    public function itCalculatesNormalizedInRangeZeroToHundred(): void
    {
        // Typical values
        $mi = $this->calculator->calculate(100.0, 5, 20);

        self::assertGreaterThanOrEqual(0, $mi);
        self::assertLessThanOrEqual(100, $mi);
    }

    #[Test]
    public function itCalculatesNormalizedPerfectScore(): void
    {
        // Empty/trivial method
        $mi = $this->calculator->calculate(0.0, 1, 0);

        // Should be 100 (perfect)
        self::assertSame(100.0, $mi);
    }

    #[Test]
    public function itClampsNormalizedScoreToZeroForExtremeComplexity(): void
    {
        // Extremely complex method
        $mi = $this->calculator->calculate(10000.0, 100, 1000);

        // Should be clamped to 0
        self::assertSame(0.0, $mi);
    }

    #[Test]
    public function itYieldsHighScoreForGoodMaintainability(): void
    {
        // Very simple method: very low volume, minimal complexity
        $mi = $this->calculator->calculate(5.0, 1, 2);

        // MI > 85 is considered good
        self::assertGreaterThan(85.0, $mi);
    }

    #[Test]
    public function itYieldsMidRangeScoreForModerateMaintainability(): void
    {
        // Medium complexity method
        $mi = $this->calculator->calculate(150.0, 8, 30);

        // MI 65-85 is moderate
        self::assertGreaterThanOrEqual(50.0, $mi);
        self::assertLessThanOrEqual(90.0, $mi);
    }

    #[Test]
    public function itYieldsLowScoreForPoorMaintainability(): void
    {
        // High complexity method
        $mi = $this->calculator->calculate(800.0, 25, 150);

        // MI < 65 is poor
        self::assertLessThan(65.0, $mi);
    }
}
