<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Microbench guarding the ADR 0015 §R3 budget: ≤500 ns/construction for the
 * fast-path (already-normalized) input. Skipped by default; run explicitly with
 * `composer test -- --group=benchmark`.
 */
#[Group('benchmark')]
#[CoversClass(AbsolutePath::class)]
#[CoversClass(RelativePath::class)]
final class PathBenchmarkTest extends TestCase
{
    private const BUDGET_NS = 500.0;
    private const ITERATIONS = 100_000;
    private const SAMPLES = 5;

    #[Test]
    public function itConstructsAbsolutePathUnderBudget(): void
    {
        // Warmup so JIT/opcache stabilizes before measurement.
        for ($i = 0; $i < 1000; $i++) {
            AbsolutePath::fromString('/very/long/some/path/foo.php');
        }

        $samples = [];
        for ($batch = 0; $batch < self::SAMPLES; $batch++) {
            $start = hrtime(true);
            for ($i = 0; $i < self::ITERATIONS; $i++) {
                AbsolutePath::fromString('/very/long/some/path/foo.php');
            }
            $samples[] = (hrtime(true) - $start) / self::ITERATIONS;
        }
        sort($samples);
        $median = $samples[(int) (self::SAMPLES / 2)];

        self::assertLessThanOrEqual(
            self::BUDGET_NS,
            $median,
            \sprintf('AbsolutePath::fromString median %.0fns exceeds %.0fns budget', $median, self::BUDGET_NS),
        );
    }

    #[Test]
    public function itConstructsRelativePathUnderBudget(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            RelativePath::fromString('src/Core/Path/AbsolutePath.php');
        }

        $samples = [];
        for ($batch = 0; $batch < self::SAMPLES; $batch++) {
            $start = hrtime(true);
            for ($i = 0; $i < self::ITERATIONS; $i++) {
                RelativePath::fromString('src/Core/Path/AbsolutePath.php');
            }
            $samples[] = (hrtime(true) - $start) / self::ITERATIONS;
        }
        sort($samples);
        $median = $samples[(int) (self::SAMPLES / 2)];

        self::assertLessThanOrEqual(
            self::BUDGET_NS,
            $median,
            \sprintf('RelativePath::fromString median %.0fns exceeds %.0fns budget', $median, self::BUDGET_NS),
        );
    }
}
