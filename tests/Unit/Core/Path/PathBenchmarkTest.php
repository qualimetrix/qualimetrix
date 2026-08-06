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
 * Guards the ADR 0015 §R3 construction-cost budget for the fast path
 * (already-normalized input).
 *
 * The ADR phrases that budget as "`fromString()` ≤500ns/call, measured against
 * `str_starts_with`/`substr` ops". Asserting the nanoseconds directly encodes
 * the machine's spare capacity rather than the code's behaviour: it reddens on
 * a loaded machine with nothing regressed, and it silently loosens on faster
 * hardware. This restates the same budget as a *ratio* against those string
 * ops, measured in the same process from the fastest batch of each — see
 * {@see self::costRatio()} for why the fastest and not the median.
 *
 * Calibration on this repository (14-core Apple M-series, PHP 8.5 CLI), 11
 * batches × 20 000 constructions, idle and under 2× CPU oversubscription:
 *
 * | Variant                                        | Idle        | Loaded      |
 * | ---------------------------------------------- | ----------- | ----------- |
 * | fast path (as shipped)                         | 2.78 – 3.01 | 2.72 – 2.96 |
 * | fast path removed, every call normalizes       | 5.12 – 5.61 | 5.03 – 5.75 |
 * | one `realpath()` added, its cache already warm | 7.3         | —           |
 *
 * {@see self::MAX_COST_RATIO} sits in the gap *on that machine only*. The
 * baseline turned out not to travel: on a GitHub-hosted x86 runner (Ubuntu,
 * PHP 8.4) the unmodified fast path measures 4.93× and 5.73× — the band this
 * limit calls a regression on arm64. That is a property of the reference, not
 * of the code under test: numerator and denominator are *different*
 * operations, and `preg_match` is priced differently against
 * `str_starts_with`/`substr` on a different ISA and PCRE build. Restating the
 * ADR's wording literally inherited that flaw.
 *
 * So the test stays in the `benchmark` group, which `composer test` excludes —
 * meaning it does not run, and the budget it describes is not currently
 * enforced anywhere. The fix is a reference that cannot drift between
 * architectures: the same `fromString()` measured against its own slow path,
 * where both sides share a call shape and only the branch under test differs
 * (~0.5 healthy, → 1.0 once the fast path stops being taken). Calibrate that
 * on the runner, not here, before returning the test to the default suite.
 */
#[Group('benchmark')]
#[CoversClass(AbsolutePath::class)]
#[CoversClass(RelativePath::class)]
final class PathBenchmarkTest extends TestCase
{
    /**
     * Construction may cost at most this multiple of the string ops it replaces.
     */
    private const MAX_COST_RATIO = 4.0;

    private const ITERATIONS = 20_000;
    private const BATCHES = 11;

    #[Test]
    public function itConstructsAbsolutePathWithinAConstantFactorOfTheStringOpsItReplaces(): void
    {
        $path = '/very/long/some/path/foo.php';

        $ratio = self::costRatio(
            static function (int $iterations) use ($path): void {
                for ($i = 0; $i < $iterations; $i++) {
                    AbsolutePath::fromString($path);
                }
            },
            self::stringOpsBaseline($path),
        );

        self::assertLessThanOrEqual(
            self::MAX_COST_RATIO,
            $ratio,
            \sprintf(
                'AbsolutePath::fromString costs %.2f× the string ops it replaces, budget is %.2f×',
                $ratio,
                self::MAX_COST_RATIO,
            ),
        );
    }

    #[Test]
    public function itConstructsRelativePathWithinAConstantFactorOfTheStringOpsItReplaces(): void
    {
        $path = 'src/Core/Path/AbsolutePath.php';

        $ratio = self::costRatio(
            static function (int $iterations) use ($path): void {
                for ($i = 0; $i < $iterations; $i++) {
                    RelativePath::fromString($path);
                }
            },
            self::stringOpsBaseline($path),
        );

        self::assertLessThanOrEqual(
            self::MAX_COST_RATIO,
            $ratio,
            \sprintf(
                'RelativePath::fromString costs %.2f× the string ops it replaces, budget is %.2f×',
                $ratio,
                self::MAX_COST_RATIO,
            ),
        );
    }

    /**
     * The reference ADR 0015 §R3 names: a prefix check plus one substring
     * extraction — what a call site paid for a raw `string` path before the VOs.
     *
     * Both operations run unconditionally. A ternary would short-circuit on a
     * relative input, making the two tests' baselines cost different things and
     * their ratios incomparable.
     *
     * @return callable(int): void
     */
    private static function stringOpsBaseline(string $path): callable
    {
        return static function (int $iterations) use ($path): void {
            $sink = null;

            for ($i = 0; $i < $iterations; $i++) {
                // Results are assigned so neither call can be dropped as dead code.
                $sink = str_starts_with($path, '/');
                $sink = substr($path, 1);
            }
        };
    }

    /**
     * Runs both loops once per batch, adjacent in time, and divides the fastest
     * observed subject batch by the fastest observed baseline batch.
     *
     * The fastest batch, not the median: contention only ever *adds* time, so
     * the quickest of {@see self::BATCHES} runs is the closest estimate of what the
     * code costs, and taking it on each side independently cancels the load
     * term instead of tracking it. Measured over 16 runs at 2× CPU
     * oversubscription, the median-of-per-batch-ratios variant of this helper
     * drifted from 2.03 to 4.83 — i.e. it both false-passed and false-failed —
     * while fastest-over-fastest held within 2.72–2.96 of its idle value.
     *
     * Each callable loops internally, so exactly one closure call is paid per
     * batch rather than one per measured operation; that overhead would land on
     * both sides and pull the ratio toward 1, blunting the regression signal.
     *
     * @param callable(int): void $subject
     * @param callable(int): void $baseline
     */
    private static function costRatio(callable $subject, callable $baseline): float
    {
        // Warm up so opcache/JIT and branch prediction settle before measuring.
        $subject(2_000);
        $baseline(2_000);

        $fastestSubject = \PHP_INT_MAX;
        $fastestBaseline = \PHP_INT_MAX;

        for ($batch = 0; $batch < self::BATCHES; $batch++) {
            $start = hrtime(true);
            $baseline(self::ITERATIONS);
            $fastestBaseline = min($fastestBaseline, hrtime(true) - $start);

            $start = hrtime(true);
            $subject(self::ITERATIONS);
            $fastestSubject = min($fastestSubject, hrtime(true) - $start);
        }

        self::assertGreaterThan(0, $fastestBaseline, 'hrtime() resolution is too coarse to measure the baseline');

        return $fastestSubject / $fastestBaseline;
    }
}
