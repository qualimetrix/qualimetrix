<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Core\Observation\WorseDirection;

#[CoversClass(ChannelDeclaration::class)]
final class ChannelDeclarationTest extends TestCase
{
    #[Test]
    public function itRejectsAMagnitudeDeclarationWithoutADirection(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must declare a WorseDirection');

        new ChannelDeclaration(ChannelShape::Magnitude, null);
    }

    #[Test]
    public function itRejectsAnOccurrenceDeclarationCarryingADirection(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must not declare a WorseDirection');

        new ChannelDeclaration(ChannelShape::Occurrence, WorseDirection::Higher);
    }

    #[Test]
    public function itAcceptsAMagnitudeDeclarationWithADirection(): void
    {
        $declaration = new ChannelDeclaration(ChannelShape::Magnitude, WorseDirection::Higher);

        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
        self::assertSame(WorseDirection::Higher, $declaration->direction);
    }

    #[Test]
    public function itAcceptsAnOccurrenceDeclarationWithNoDirection(): void
    {
        $declaration = new ChannelDeclaration(ChannelShape::Occurrence);

        self::assertSame(ChannelShape::Occurrence, $declaration->shape);
        self::assertNull($declaration->direction);
    }

    #[Test]
    public function itBuildsAMagnitudeDeclarationViaTheFactory(): void
    {
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Lower);

        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
        self::assertSame(WorseDirection::Lower, $declaration->direction);
    }

    #[Test]
    public function itBuildsAnOccurrenceDeclarationViaTheFactory(): void
    {
        $declaration = ChannelDeclaration::occurrence();

        self::assertSame(ChannelShape::Occurrence, $declaration->shape);
        self::assertNull($declaration->direction);
    }

    #[Test]
    public function itReportsLowerWorseTrueForALowerDirection(): void
    {
        self::assertTrue(ChannelDeclaration::magnitude(WorseDirection::Lower)->isLowerWorse());
    }

    #[Test]
    public function itReportsLowerWorseFalseForAHigherDirection(): void
    {
        self::assertFalse(ChannelDeclaration::magnitude(WorseDirection::Higher)->isLowerWorse());
    }

    #[Test]
    public function itReportsLowerWorseNullForAnOccurrenceDeclaration(): void
    {
        self::assertNull(ChannelDeclaration::occurrence()->isLowerWorse());
    }
}
