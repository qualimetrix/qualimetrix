<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Core\Observation\WorseDirection;
use ReflectionClass;
use ReflectionParameter;

#[CoversClass(ChannelDeclaration::class)]
final class ChannelDeclarationTest extends TestCase
{
    /**
     * The pairing "magnitude without a direction" and "occurrence with one"
     * are not tested by constructing them, because they cannot be
     * constructed: the constructor is private and neither factory can express
     * either. This is half of what makes the two missing tests unnecessary
     * rather than forgotten; the other half is the signature test below.
     */
    #[Test]
    public function itKeepsItsConstructorPrivateSoOnlyTheFactoriesCanShapeADeclaration(): void
    {
        $constructor = (new ReflectionClass(ChannelDeclaration::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    /**
     * The private constructor alone does not make the three refusals inside it
     * unreachable — the factory signatures do, and they are what this pins.
     *
     * `magnitude()` takes a non-nullable direction, so "a magnitude channel
     * must declare a direction" cannot be stated. `occurrence()` has no
     * direction parameter at all, so "an occurrence channel must not declare
     * one" cannot be stated either. Both take a mandatory first level, so the
     * empty list cannot be stated. Widening any of these — a nullable
     * direction, a direction parameter on `occurrence()`, a variadic-only
     * level list — puts the corresponding refusal back in reach, and this test
     * is what says so out loud instead of leaving three branches that look
     * live and are not.
     */
    #[Test]
    public function itLeavesTheThreeRefusalsUnstatableThroughTheFactorySignatures(): void
    {
        $magnitude = (new ReflectionClass(ChannelDeclaration::class))->getMethod('magnitude');
        $occurrence = (new ReflectionClass(ChannelDeclaration::class))->getMethod('occurrence');

        $direction = $magnitude->getParameters()[0];
        self::assertSame('direction', $direction->getName());
        self::assertFalse($direction->allowsNull(), 'A nullable direction would make the magnitude refusal reachable.');

        self::assertSame(
            ['level', 'moreLevels'],
            array_map(
                static fn(ReflectionParameter $parameter): string => $parameter->getName(),
                $occurrence->getParameters(),
            ),
            'An occurrence factory that accepted a direction would make the occurrence refusal reachable.',
        );

        foreach ([$magnitude, $occurrence] as $factory) {
            $levels = array_values(array_filter(
                $factory->getParameters(),
                static fn(ReflectionParameter $parameter): bool => $parameter->getName() === 'level',
            ));

            self::assertCount(1, $levels, $factory->getName() . '() must take a mandatory first level.');
            self::assertFalse($levels[0]->isVariadic());
            self::assertFalse($levels[0]->isOptional());
        }
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
    public function itRefusesARepeatedLevel(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('more than once');

        ChannelDeclaration::occurrence(SymbolLevel::Class_, SymbolLevel::Class_);
    }

    #[Test]
    public function itYieldsAConfigurationErrorOnlyThroughTheWither(): void
    {
        $plain = ChannelDeclaration::occurrence(SymbolLevel::Project);

        self::assertFalse($plain->isConfigurationError());
        self::assertFalse(ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Project)->isConfigurationError());
        self::assertTrue($plain->asConfigurationError()->isConfigurationError());
        self::assertFalse($plain->isConfigurationError(), 'The wither must not mutate the declaration it copies.');
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
