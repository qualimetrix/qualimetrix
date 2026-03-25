<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Halstead;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Halstead\HalsteadMetrics;

#[CoversClass(HalsteadMetrics::class)]
final class HalsteadMetricsTest extends TestCase
{
    public function testVocabulary(): void
    {
        $metrics = new HalsteadMetrics(n1: 5, n2: 10, N1: 20, N2: 30);

        // η = n1 + n2 = 5 + 10 = 15
        self::assertSame(15, $metrics->vocabulary());
    }

    public function testLength(): void
    {
        $metrics = new HalsteadMetrics(n1: 5, n2: 10, N1: 20, N2: 30);

        // N = N1 + N2 = 20 + 30 = 50
        self::assertSame(50, $metrics->length());
    }

    public function testVolume(): void
    {
        $metrics = new HalsteadMetrics(n1: 5, n2: 10, N1: 20, N2: 30);

        // V = N × log₂(η) = 50 × log₂(15) ≈ 50 × 3.9069 ≈ 195.35
        self::assertEqualsWithDelta(195.35, $metrics->volume(), 0.1);
    }

    public function testVolumeWithZeroVocabulary(): void
    {
        $metrics = new HalsteadMetrics(n1: 0, n2: 0, N1: 0, N2: 0);

        self::assertSame(0.0, $metrics->volume());
    }

    public function testDifficulty(): void
    {
        $metrics = new HalsteadMetrics(n1: 5, n2: 10, N1: 20, N2: 30);

        // D = (n1/2) × (N2/n2) = (5/2) × (30/10) = 2.5 × 3 = 7.5
        self::assertSame(7.5, $metrics->difficulty());
    }

    public function testDifficultyWithZeroOperands(): void
    {
        $metrics = new HalsteadMetrics(n1: 5, n2: 0, N1: 20, N2: 0);

        self::assertSame(0.0, $metrics->difficulty());
    }

    public function testDifficultyWithZeroOperators(): void
    {
        $metrics = new HalsteadMetrics(n1: 0, n2: 10, N1: 0, N2: 30);

        self::assertSame(0.0, $metrics->difficulty());
    }

    public function testEffort(): void
    {
        $metrics = new HalsteadMetrics(n1: 5, n2: 10, N1: 20, N2: 30);

        // E = D × V = 7.5 × 195.35 ≈ 1465.11
        $expected = $metrics->difficulty() * $metrics->volume();
        self::assertEqualsWithDelta($expected, $metrics->effort(), 0.01);
    }

    public function testBugs(): void
    {
        $metrics = new HalsteadMetrics(n1: 5, n2: 10, N1: 20, N2: 30);

        // B = V / 3000 ≈ 195.35 / 3000 ≈ 0.0651
        $expected = $metrics->volume() / 3000;
        self::assertEqualsWithDelta($expected, $metrics->bugs(), 0.0001);
    }

    public function testTime(): void
    {
        $metrics = new HalsteadMetrics(n1: 5, n2: 10, N1: 20, N2: 30);

        // T = E / 18
        $expected = $metrics->effort() / 18;
        self::assertEqualsWithDelta($expected, $metrics->time(), 0.01);
    }

    public function testEmpty(): void
    {
        $metrics = HalsteadMetrics::empty();

        self::assertSame(0, $metrics->n1);
        self::assertSame(0, $metrics->n2);
        self::assertSame(0, $metrics->N1);
        self::assertSame(0, $metrics->N2);
        self::assertTrue($metrics->isEmpty());
    }

    public function testIsEmpty(): void
    {
        $empty = new HalsteadMetrics(n1: 0, n2: 0, N1: 0, N2: 0);
        $nonEmpty = new HalsteadMetrics(n1: 1, n2: 1, N1: 1, N2: 1);

        self::assertTrue($empty->isEmpty());
        self::assertFalse($nonEmpty->isEmpty());
    }

    public function testIsEmptyWithOnlyUniqueOperators(): void
    {
        // Has unique operators but no total usage
        $metrics = new HalsteadMetrics(n1: 5, n2: 3, N1: 0, N2: 0);

        self::assertTrue($metrics->isEmpty());
    }

    public function testRealWorldExample(): void
    {
        // Example: a simple method with 3 unique operators (+, =, return)
        // 5 unique operands ($a, $b, $result)
        // 4 total operators, 6 total operands
        $metrics = new HalsteadMetrics(n1: 3, n2: 5, N1: 4, N2: 6);

        // η = 3 + 5 = 8
        self::assertSame(8, $metrics->vocabulary());

        // N = 4 + 6 = 10
        self::assertSame(10, $metrics->length());

        // V = 10 × log₂(8) = 10 × 3 = 30
        self::assertEqualsWithDelta(30.0, $metrics->volume(), 0.01);

        // D = (3/2) × (6/5) = 1.5 × 1.2 = 1.8
        self::assertEqualsWithDelta(1.8, $metrics->difficulty(), 0.01);

        // E = 1.8 × 30 = 54
        self::assertEqualsWithDelta(54.0, $metrics->effort(), 0.01);

        // B = 30 / 3000 = 0.01
        self::assertEqualsWithDelta(0.01, $metrics->bugs(), 0.001);

        // T = 54 / 18 = 3 seconds
        self::assertEqualsWithDelta(3.0, $metrics->time(), 0.01);
    }
}
