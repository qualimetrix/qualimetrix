<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Observation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Observation\AxisObservation;
use Qualimetrix\Core\Observation\WorseDirection;

#[CoversClass(AxisObservation::class)]
final class AxisObservationTest extends TestCase
{
    #[Test]
    public function itKeepsTheRawValueUnrounded(): void
    {
        $axis = new AxisObservation('mi', 61.63157, 65, WorseDirection::Lower);

        self::assertSame(61.63157, $axis->rawValue);
        self::assertTrue($axis->hasValue());
        self::assertTrue($axis->hasOnsetBoundary());
    }

    #[Test]
    public function itRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name must not be empty');

        new AxisObservation('', 1);
    }

    /**
     * A numeric-string name is legal PHP but a trap: `DebtObservation::$axes`
     * is a `string => AxisObservation` map keyed by `$axis->name`, and PHP
     * silently coerces a key like `"10"` to the integer `10`. `axisNames()`
     * would then return an `int` against its declared `list<string>`, and a
     * strict comparison against manifest axis names read back from JSON would
     * fail for a reason invisible in any printed output — both sides print
     * identically. Closed here, before the name is ever used as an array key.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNumericStringNames(): iterable
    {
        yield 'plain digits' => ['10'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
    }

    #[Test]
    #[DataProvider('provideNumericStringNames')]
    public function itRejectsANameThatWouldCoerceToAnIntegerArrayKey(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('would be silently coerced to an integer array key');

        new AxisObservation($name, 1);
    }

    /**
     * The counter-cases: these look numeric but PHP does *not* coerce them as
     * array keys, so they must stay legal — over-rejecting would be its own
     * bug.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNonCoercingNumericLookingNames(): iterable
    {
        yield 'leading zero' => ['01'];
        yield 'decimal point' => ['1.0'];
        yield 'plus sign' => ['+1'];
    }

    #[Test]
    #[DataProvider('provideNonCoercingNumericLookingNames')]
    public function itAcceptsANumericLookingNameThatDoesNotCoerce(string $name): void
    {
        self::assertSame($name, (new AxisObservation($name, 1))->name);
    }

    /**
     * A null value is a first-class state, not a shape change: a class with
     * fewer than two public methods has no TCC at all, and the rule that reads
     * it must be able to say so without inventing a number.
     */
    #[Test]
    public function itTreatsANullValueAsALegalState(): void
    {
        $axis = new AxisObservation('tcc', null, 0.33, WorseDirection::Lower);

        self::assertNull($axis->rawValue);
        self::assertFalse($axis->hasValue());
        self::assertTrue($axis->hasOnsetBoundary(), 'a missing measurement does not remove the boundary');
    }

    #[Test]
    public function itTreatsAMissingOnsetBoundaryAsALegalState(): void
    {
        $axis = new AxisObservation('present', 1);

        self::assertNull($axis->onsetBoundary);
        self::assertFalse($axis->hasOnsetBoundary());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideNonFiniteValues(): iterable
    {
        yield 'NaN' => [\NAN];
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
    }

    #[Test]
    #[DataProvider('provideNonFiniteValues')]
    public function itRejectsANonFiniteRawValue(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rawValue must be finite');

        new AxisObservation('ccn', $value);
    }

    #[Test]
    #[DataProvider('provideNonFiniteValues')]
    public function itRejectsANonFiniteOnsetBoundary(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('onsetBoundary must be finite');

        new AxisObservation('ccn', 10, $value);
    }

    #[Test]
    #[DataProvider('provideNonFiniteValues')]
    public function itRejectsANonFiniteEpsilon(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('epsilon must be finite');

        new AxisObservation('ccn', 10, 5, WorseDirection::Higher, $value);
    }

    #[Test]
    public function itRejectsANegativeEpsilon(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('epsilon must not be negative');

        new AxisObservation('ccn', 10, 5, WorseDirection::Higher, -0.01);
    }

    #[Test]
    public function itDefaultsEpsilonToZero(): void
    {
        self::assertSame(0.0, (new AxisObservation('ccn', 10))->epsilon);
    }

    #[Test]
    public function itKeepsAPositiveEpsilon(): void
    {
        self::assertSame(0.5, (new AxisObservation('mi', 61.0, 65, WorseDirection::Lower, 0.5))->epsilon);
    }

    /**
     * Negative zero serializes as `-0` and compares equal to `0`, so a file
     * whose bytes must be stable cannot carry both forms.
     */
    #[Test]
    public function itNormalizesNegativeZero(): void
    {
        $axis = new AxisObservation('delta', -0.0, -0.0, WorseDirection::Higher, -0.0);

        self::assertSame('0', (string) $axis->rawValue);
        self::assertSame('0', (string) $axis->onsetBoundary);
        self::assertSame('0', (string) $axis->epsilon);
    }

    #[Test]
    public function itKeepsIntegerValuesAsIntegers(): void
    {
        $axis = new AxisObservation('ccn', 25, 10);

        self::assertSame(25, $axis->rawValue);
        self::assertSame(10, $axis->onsetBoundary);
    }
}
