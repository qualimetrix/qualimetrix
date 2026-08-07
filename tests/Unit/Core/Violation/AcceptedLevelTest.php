<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Violation\AcceptedLevel;
use Qualimetrix\Core\Violation\ChannelShape;

#[CoversClass(AcceptedLevel::class)]
final class AcceptedLevelTest extends TestCase
{
    #[Test]
    public function itCarriesTheAcceptedMagnitudesOfAMagnitudeChannel(): void
    {
        $level = new AcceptedLevel([40, 100], 2);

        self::assertSame(ChannelShape::Magnitude, $level->shape());
        self::assertSame([40.0, 100.0], $level->magnitudes);
        self::assertSame(2, $level->count);
    }

    #[Test]
    public function itCarriesOnlyACountOnAnOccurrenceChannel(): void
    {
        $level = new AcceptedLevel(null, 3);

        self::assertSame(ChannelShape::Occurrence, $level->shape());
        self::assertNull($level->magnitudes);
    }

    #[Test]
    public function itNamesTheMagnitudesWithoutTrailingZeros(): void
    {
        self::assertSame('25', (new AcceptedLevel([25.0], 1))->describe());
        self::assertSame('40, 100', (new AcceptedLevel([40.0, 100.0], 2))->describe());
        self::assertSame('0.421', (new AcceptedLevel([0.421], 1))->describe());
    }

    /**
     * A `-0.0` and a `0.0` are numerically equal and spell differently; the
     * report must not depend on which one a formula produced.
     */
    #[Test]
    public function itNamesNegativeZeroAsZero(): void
    {
        self::assertSame('0', (new AcceptedLevel([-0.0], 1))->describe());
    }

    #[Test]
    public function itNamesAnOccurrenceCountInWords(): void
    {
        self::assertSame('1 occurrence', (new AcceptedLevel(null, 1))->describe());
        self::assertSame('3 occurrences', (new AcceptedLevel(null, 3))->describe());
    }

    #[Test]
    public function itRefusesALevelThatAcceptsNoFinding(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AcceptedLevel(null, 0);
    }

    #[Test]
    public function itRefusesAMagnitudeListThatDisagreesWithItsCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AcceptedLevel([40.0], 2);
    }
}
