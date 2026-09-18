<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;

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

    /**
     * The one branch a test can drive. The others read /proc, shell out, or
     * are the fallback that only a machine with none of them reaches, and the
     * detector takes no seam for any of them — so the fallback the old name
     * here promised was, and remains, unreachable from a test.
     */
    #[Test]
    public function itReadsTheProcessorCountFromTheWindowsEnvironmentVariable(): void
    {
        $before = getenv('NUMBER_OF_PROCESSORS');
        putenv('NUMBER_OF_PROCESSORS=7');

        try {
            self::assertSame(7, (new WorkerCountDetector())->detect());
        } finally {
            putenv($before === false ? 'NUMBER_OF_PROCESSORS' : 'NUMBER_OF_PROCESSORS=' . $before);
        }
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
