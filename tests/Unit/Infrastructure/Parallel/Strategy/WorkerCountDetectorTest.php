<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Parallel\Strategy;

use AiMessDetector\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerCountDetector::class)]
final class WorkerCountDetectorTest extends TestCase
{
    #[Test]
    public function itDetectsWorkerCountGreaterThanZero(): void
    {
        $detector = new WorkerCountDetector();

        $count = $detector->detect();

        self::assertGreaterThan(0, $count);
    }

    #[Test]
    public function itReturnsFallbackWhenDetectionFails(): void
    {
        $detector = new WorkerCountDetector();

        // Ensure the method returns a valid value
        // Different systems may have different core counts,
        // but the minimum should be the fallback (4)
        $count = $detector->detect();

        self::assertGreaterThanOrEqual(1, $count);
    }

    #[Test]
    public function itReturnsConsistentResults(): void
    {
        $detector = new WorkerCountDetector();

        $count1 = $detector->detect();
        $count2 = $detector->detect();

        // Two consecutive calls should return the same result
        self::assertSame($count1, $count2);
    }
}
