<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability;
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

        new ChannelDeclaration(ChannelShape::Magnitude, null, ChannelAcceptability::AcceptableAsDebt, [SymbolLevel::Class_]);
    }

    #[Test]
    public function itRejectsAnOccurrenceDeclarationCarryingADirection(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('must not declare a WorseDirection');

        new ChannelDeclaration(ChannelShape::Occurrence, WorseDirection::Higher, ChannelAcceptability::AcceptableAsDebt, [SymbolLevel::Class_]);
    }

    #[Test]
    public function itAcceptsAMagnitudeDeclarationWithADirection(): void
    {
        $declaration = new ChannelDeclaration(ChannelShape::Magnitude, WorseDirection::Higher, ChannelAcceptability::AcceptableAsDebt, [SymbolLevel::Class_]);

        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
        self::assertSame(WorseDirection::Higher, $declaration->direction);
    }

    #[Test]
    public function itAcceptsAnOccurrenceDeclarationWithNoDirection(): void
    {
        $declaration = new ChannelDeclaration(ChannelShape::Occurrence, null, ChannelAcceptability::AcceptableAsDebt, [SymbolLevel::Class_]);

        self::assertSame(ChannelShape::Occurrence, $declaration->shape);
        self::assertNull($declaration->direction);
    }

    #[Test]
    public function itBuildsAMagnitudeDeclarationViaTheFactory(): void
    {
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_);

        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
        self::assertSame(WorseDirection::Lower, $declaration->direction);
    }

    #[Test]
    public function itBuildsAnOccurrenceDeclarationViaTheFactory(): void
    {
        $declaration = ChannelDeclaration::occurrence(SymbolLevel::Class_);

        self::assertSame(ChannelShape::Occurrence, $declaration->shape);
        self::assertNull($declaration->direction);
    }

    #[Test]
    public function itReportsLowerWorseTrueForALowerDirection(): void
    {
        self::assertTrue(ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_)->isLowerWorse());
    }

    #[Test]
    public function itReportsLowerWorseFalseForAHigherDirection(): void
    {
        self::assertFalse(ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_)->isLowerWorse());
    }

    #[Test]
    public function itReportsLowerWorseNullForAnOccurrenceDeclaration(): void
    {
        self::assertNull(ChannelDeclaration::occurrence(SymbolLevel::Class_)->isLowerWorse());
    }

    #[Test]
    public function itRefusesADeclarationWithNoLevel(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('at least one level');

        new ChannelDeclaration(ChannelShape::Occurrence, null, ChannelAcceptability::AcceptableAsDebt, []);
    }

    #[Test]
    public function itRefusesARepeatedLevel(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('more than once');

        ChannelDeclaration::occurrence(SymbolLevel::Class_, SymbolLevel::Class_);
    }

    #[Test]
    public function itOrdersDeclaredLevelsCanonicallySoTwoSpellingsOfOneChannelCompareEqual(): void
    {
        self::assertEquals(
            ChannelDeclaration::occurrence(SymbolLevel::Class_, SymbolLevel::Project, SymbolLevel::Namespace_),
            ChannelDeclaration::occurrence(SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project),
        );
        self::assertSame(
            [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
            ChannelDeclaration::occurrence(SymbolLevel::Project, SymbolLevel::Class_, SymbolLevel::Namespace_)->levels,
        );
    }
}
