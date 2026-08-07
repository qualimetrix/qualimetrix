<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Observation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Observation\WorseDirection;

#[CoversClass(WorseDirection::class)]
final class WorseDirectionTest extends TestCase
{
    /**
     * The allowance is the more permissive of the captured value and the
     * current onset, so it is never stricter than the onset.
     *
     * @return iterable<string, array{WorseDirection, int|float, int|float, int|float}>
     */
    public static function provideMorePermissiveCases(): iterable
    {
        yield 'higher: captured worse than onset' => [WorseDirection::Higher, 25, 10, 25];
        yield 'higher: onset above captured' => [WorseDirection::Higher, 10, 25, 25];
        yield 'higher: equal' => [WorseDirection::Higher, 10, 10, 10];
        yield 'lower: captured worse than onset' => [WorseDirection::Lower, 20.0, 65.0, 20.0];
        yield 'lower: onset below captured' => [WorseDirection::Lower, 65.0, 20.0, 20.0];
        yield 'lower: equal' => [WorseDirection::Lower, 65.0, 65.0, 65.0];

        // Mixed int/float ties: written to the baseline file, whose byte
        // stability contract in ADR 0017 forbids the numeric type from depending on
        // argument order. `max()`/`min()` alone break the tie by argument
        // position, so a test built only from same-type pairs cannot catch
        // this — it passes whether or not the tie is normalized.
        yield 'higher: tie between int and float promotes to float' => [WorseDirection::Higher, 10, 10.0, 10.0];
        yield 'higher: tie between float and int promotes to float' => [WorseDirection::Higher, 10.0, 10, 10.0];
        yield 'lower: tie between int and float promotes to float' => [WorseDirection::Lower, 65, 65.0, 65.0];
        yield 'lower: tie between float and int promotes to float' => [WorseDirection::Lower, 65.0, 65, 65.0];
    }

    /**
     * {@see WorseDirection::morePermissive()} is what makes `baseline:update`
     * (ADR 0017) direction-aware: it is the primitive
     * `update` folds the recorded boundary and the current one through so a
     * boundary may only move toward stricter, never toward more permissive.
     */
    #[Test]
    #[DataProvider('provideMorePermissiveCases')]
    public function itPicksTheBoundaryBaselineUpdateMayWidenTowards(
        WorseDirection $direction,
        int|float $a,
        int|float $b,
        int|float $expected,
    ): void {
        self::assertSame($expected, $direction->morePermissive($a, $b));
        self::assertSame($expected, $direction->morePermissive($b, $a), 'the operator is commutative');
    }

    /**
     * {@see WorseDirection::isWorse()} is the comparison behind the group-
     * acceptance rule in ADR 0017: acceptance counts, per level
     * of severity, how many members each side holds, and this predicate is
     * how "at least as bad as a level" is decided in the channel's declared
     * direction. Members are never paired by rank.
     */
    #[Test]
    public function itDecidesGroupAcceptanceWhenHigherIsWorse(): void
    {
        $direction = WorseDirection::Higher;

        self::assertTrue($direction->isWorse(26, 25));
        self::assertFalse($direction->isWorse(25, 25), 'equal is not worse');
        self::assertFalse($direction->isWorse(24, 25));
    }

    /**
     * The same counting predicate, mirrored for lower-is-worse channels
     * (Maintainability Index, cohesion, health scores): "at least as bad as"
     * reads `<=` there, which is why ADR 0017 is stated in terms of severity
     * levels rather than of larger and smaller numbers.
     */
    #[Test]
    public function itDecidesGroupAcceptanceWhenLowerIsWorse(): void
    {
        $direction = WorseDirection::Lower;

        self::assertTrue($direction->isWorse(19.0, 20.0));
        self::assertFalse($direction->isWorse(20.0, 20.0), 'equal is not worse');
        self::assertFalse($direction->isWorse(21.0, 20.0));
    }

    /**
     * Epsilon is a tolerance band around the allowance, not a shift of it:
     * a value inside the band is not worse. ADR 0017 passes an epsilon of `0.0`
     * (the tolerance is zero), but the parameter itself is exercised here.
     */
    #[Test]
    public function itAbsorbsMovementInsideTheEpsilonBand(): void
    {
        $direction = WorseDirection::Higher;

        self::assertFalse($direction->isWorse(25.4, 25.0, 0.5));
        self::assertTrue($direction->isWorse(25.6, 25.0, 0.5), 'beyond the band is worse again');
    }

    #[Test]
    public function itAbsorbsMovementInsideTheEpsilonBandWhenLowerIsWorse(): void
    {
        $direction = WorseDirection::Lower;

        self::assertFalse($direction->isWorse(19.6, 20.0, 0.5));
        self::assertTrue($direction->isWorse(19.4, 20.0, 0.5));
    }
}
