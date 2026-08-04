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
        // stability contract (§6.2) forbids the numeric type from depending on
        // argument order. `max()`/`min()` alone break the tie by argument
        // position, so a test built only from same-type pairs cannot catch
        // this — it passes whether or not the tie is normalized.
        yield 'higher: tie between int and float promotes to float' => [WorseDirection::Higher, 10, 10.0, 10.0];
        yield 'higher: tie between float and int promotes to float' => [WorseDirection::Higher, 10.0, 10, 10.0];
        yield 'lower: tie between int and float promotes to float' => [WorseDirection::Lower, 65, 65.0, 65.0];
        yield 'lower: tie between float and int promotes to float' => [WorseDirection::Lower, 65.0, 65, 65.0];
    }

    #[Test]
    #[DataProvider('provideMorePermissiveCases')]
    public function itPicksTheMorePermissiveBoundary(
        WorseDirection $direction,
        int|float $a,
        int|float $b,
        int|float $expected,
    ): void {
        self::assertSame($expected, $direction->morePermissive($a, $b));
        self::assertSame($expected, $direction->morePermissive($b, $a), 'the operator is commutative');
    }

    #[Test]
    public function itTreatsHigherValuesAsWorseWhenHigherIsWorse(): void
    {
        $direction = WorseDirection::Higher;

        self::assertTrue($direction->isWorse(26, 25));
        self::assertFalse($direction->isWorse(25, 25), 'equal is not worse');
        self::assertFalse($direction->isWorse(24, 25));
    }

    #[Test]
    public function itTreatsLowerValuesAsWorseWhenLowerIsWorse(): void
    {
        $direction = WorseDirection::Lower;

        self::assertTrue($direction->isWorse(19.0, 20.0));
        self::assertFalse($direction->isWorse(20.0, 20.0), 'equal is not worse');
        self::assertFalse($direction->isWorse(21.0, 20.0));
    }

    /**
     * Epsilon is a tolerance band around the allowance, not a shift of it:
     * a value inside the band is neither worse nor better.
     */
    #[Test]
    public function itAbsorbsMovementInsideTheEpsilonBand(): void
    {
        $direction = WorseDirection::Higher;

        self::assertFalse($direction->isWorse(25.4, 25.0, 0.5));
        self::assertFalse($direction->isBetter(24.6, 25.0, 0.5));
        self::assertTrue($direction->isWorse(25.6, 25.0, 0.5), 'beyond the band is worse again');
        self::assertTrue($direction->isBetter(24.4, 25.0, 0.5), 'beyond the band is better again');
    }

    #[Test]
    public function itAbsorbsMovementInsideTheEpsilonBandWhenLowerIsWorse(): void
    {
        $direction = WorseDirection::Lower;

        self::assertFalse($direction->isWorse(19.6, 20.0, 0.5));
        self::assertFalse($direction->isBetter(20.4, 20.0, 0.5));
        self::assertTrue($direction->isWorse(19.4, 20.0, 0.5));
        self::assertTrue($direction->isBetter(20.6, 20.0, 0.5));
    }

    #[Test]
    public function itNeverCallsAValueBothWorseAndBetter(): void
    {
        foreach (WorseDirection::cases() as $direction) {
            foreach ([-3, 0, 3, 10, 25] as $current) {
                self::assertFalse(
                    $direction->isWorse($current, 10, 0.5) && $direction->isBetter($current, 10, 0.5),
                    'worse and better must stay mutually exclusive',
                );
            }
        }
    }
}
