<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Guards the fast path in `AbsolutePath::normalize()` / `RelativePath::normalize()`:
 * an already-canonical input must skip splitting the value into segments and
 * rebuilding it, which is what makes ADR 0015 §R3's per-construction budget
 * reachable at all.
 *
 * Both sides of the measurement are the *same* `fromString()` call, differing
 * only in whether the input gives normalization work to do. That is deliberate,
 * and it is the third reference tried here:
 *
 * - wall-clock nanoseconds (`≤500ns/call`) measured the machine's spare
 *   capacity, so it reddened under concurrent load with nothing regressed;
 * - a ratio against the `str_starts_with`/`substr` ops ADR 0015 §R3 names
 *   cancelled machine load but not architecture — `preg_match` is priced
 *   differently against those builtins per ISA and PCRE build, so the healthy
 *   value moved from 2.8× on arm64 to 5.7× on an x86 CI runner, straight into
 *   the band that meant "regressed" locally;
 * - this one has no foreign operation in the denominator, and its failure
 *   value is fixed by construction rather than measured: if the fast path
 *   stops being taken, both sides run identical code and the share goes to
 *   ~1.0 on any machine.
 *
 * Calibration, 11 batches × 20 000 constructions, fastest batch per side:
 *
 * | Machine                          | AbsolutePath  | RelativePath  |
 * | -------------------------------- | ------------- | ------------- |
 * | arm64, PHP 8.5, idle             | 0.439 – 0.466 | 0.444 – 0.472 |
 * | arm64, PHP 8.5, 2× oversubscribed | within noise  | within noise  |
 * | x86 GitHub runner, PHP 8.4       | 0.275         | 0.378         |
 * | x86 GitHub runner, PHP 8.5       | 0.296         | 0.373         |
 * | any machine, fast path removed   | 0.947 – 0.997 | 0.947 – 0.997 |
 *
 * The spread across architectures (0.275 – 0.472) stays clear of
 * {@see self::MAX_FAST_PATH_SHARE}, and the regressed band is bounded above by
 * 1.0 as a matter of arithmetic rather than measurement — that is the property
 * the previous two references lacked.
 *
 * Blind spot, stated because it is real: a cost added to `fromString()` ahead
 * of the branch lands on both sides and largely cancels. Injecting `realpath()`
 * there nearly doubled construction (500ns → 932ns) yet moved the share only to
 * 0.597 — under this budget, undetected. Arithmetic says such an addition shows
 * up only once it exceeds about a quarter of the normalizing path's cost.
 *
 * The worst member of that class — reaching for the filesystem — is covered
 * instead by {@see PathLexicalConstructionTest}, which counts realpath cache
 * entries and needs no clock at all. What neither test catches is expensive
 * *computation* added before the branch. Catching that needs an absolute
 * budget, which is precisely what a unit test cannot assert stably; wall-time
 * belongs in `composer benchmark:check`.
 */
#[CoversClass(AbsolutePath::class)]
#[CoversClass(RelativePath::class)]
final class PathFastPathCostTest extends TestCase
{
    /**
     * Constructing an already-canonical path may cost at most this share of
     * constructing one that has to be normalized. 1.0 means the fast path is
     * gone; the healthy value is under half.
     */
    private const MAX_FAST_PATH_SHARE = 0.70;

    private const ITERATIONS = 20_000;
    private const BATCHES = 11;

    #[Test]
    public function itSkipsNormalizationWhenTheAbsolutePathIsAlreadyCanonical(): void
    {
        $canonical = '/very/long/some/path/foo.php';
        $needsNormalizing = '/very/long/some/./path/foo.php';

        self::assertSame(
            $canonical,
            AbsolutePath::fromString($needsNormalizing)->value(),
            'the two inputs must differ only in whether normalization has work to do',
        );

        $measurement = self::fastPathShare(
            static function (int $iterations) use ($canonical): void {
                for ($i = 0; $i < $iterations; $i++) {
                    AbsolutePath::fromString($canonical);
                }
            },
            static function (int $iterations) use ($needsNormalizing): void {
                for ($i = 0; $i < $iterations; $i++) {
                    AbsolutePath::fromString($needsNormalizing);
                }
            },
        );

        self::assertLessThanOrEqual(
            self::MAX_FAST_PATH_SHARE,
            $measurement['share'],
            self::describe('AbsolutePath', $measurement),
        );
    }

    #[Test]
    public function itSkipsNormalizationWhenTheRelativePathIsAlreadyCanonical(): void
    {
        $canonical = 'src/Core/Path/AbsolutePath.php';
        $needsNormalizing = 'src/Core/./Path/AbsolutePath.php';

        self::assertSame(
            $canonical,
            RelativePath::fromString($needsNormalizing)->value(),
            'the two inputs must differ only in whether normalization has work to do',
        );

        $measurement = self::fastPathShare(
            static function (int $iterations) use ($canonical): void {
                for ($i = 0; $i < $iterations; $i++) {
                    RelativePath::fromString($canonical);
                }
            },
            static function (int $iterations) use ($needsNormalizing): void {
                for ($i = 0; $i < $iterations; $i++) {
                    RelativePath::fromString($needsNormalizing);
                }
            },
        );

        self::assertLessThanOrEqual(
            self::MAX_FAST_PATH_SHARE,
            $measurement['share'],
            self::describe('RelativePath', $measurement),
        );
    }

    /**
     * Runs both loops once per batch, adjacent in time, and divides the fastest
     * observed canonical batch by the fastest observed normalizing one.
     *
     * The fastest batch, not the median: contention only ever *adds* time, so
     * the quickest of {@see self::BATCHES} runs is the closest estimate of what
     * the code costs, and taking it per side cancels the load term instead of
     * tracking it. Measured over 16 runs at 2× CPU oversubscription, a
     * median-of-per-batch-ratios variant of this helper drifted from 2.03 to
     * 4.83 on a measurement whose idle value was 2.8, while fastest-over-
     * fastest stayed within 3% of its idle value.
     *
     * Each callable loops internally, so one closure call is paid per batch
     * rather than per construction; that overhead would land on both sides and
     * pull the share toward 1, blunting the regression signal.
     *
     * @param callable(int): void $canonical
     * @param callable(int): void $needsNormalizing
     *
     * @return array{share: float, fastNs: float, slowNs: float}
     */
    private static function fastPathShare(callable $canonical, callable $needsNormalizing): array
    {
        // Warm up so opcache/JIT and branch prediction settle before measuring.
        $canonical(2_000);
        $needsNormalizing(2_000);

        $fastestCanonical = \PHP_INT_MAX;
        $fastestNormalizing = \PHP_INT_MAX;

        for ($batch = 0; $batch < self::BATCHES; $batch++) {
            $start = hrtime(true);
            $needsNormalizing(self::ITERATIONS);
            $fastestNormalizing = min($fastestNormalizing, hrtime(true) - $start);

            $start = hrtime(true);
            $canonical(self::ITERATIONS);
            $fastestCanonical = min($fastestCanonical, hrtime(true) - $start);
        }

        self::assertGreaterThan(0, $fastestNormalizing, 'hrtime() resolution is too coarse to measure a batch');

        return [
            'share' => $fastestCanonical / $fastestNormalizing,
            'fastNs' => $fastestCanonical / self::ITERATIONS,
            'slowNs' => $fastestNormalizing / self::ITERATIONS,
        ];
    }

    /**
     * @param array{share: float, fastNs: float, slowNs: float} $measurement
     */
    private static function describe(string $subject, array $measurement): string
    {
        return \sprintf(
            '%s::fromString costs %.0fns on an already-canonical path against %.0fns when it normalizes — '
            . 'a share of %.3f, budget %.2f. A share near 1.0 means the fast path is no longer taken.',
            $subject,
            $measurement['fastNs'],
            $measurement['slowNs'],
            $measurement['share'],
            self::MAX_FAST_PATH_SHARE,
        );
    }
}
