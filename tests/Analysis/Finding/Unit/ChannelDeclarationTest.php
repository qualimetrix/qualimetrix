<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolLevel;
use ReflectionClass;
use ReflectionParameter;

#[CoversClass(ChannelDeclaration::class)]
final class ChannelDeclarationTest extends TestCase
{
    /**
     * ADR 0031 moved {@see \Qualimetrix\Analysis\Finding\Contract\ChannelShape} off this class onto the producer, so
     * a magnitude-without-direction / occurrence-with-a-direction pairing is
     * no longer a runtime refusal here at all — there is no `$shape` field
     * left to disagree with `$direction`. What remains representable-or-not
     * is `$direction` itself, and the constructor being private plus neither
     * factory taking the other shape's parameters is the whole reason it
     * cannot be built any way but the two below.
     */
    #[Test]
    public function itKeepsItsConstructorPrivateSoOnlyTheFactoriesCanShapeADeclaration(): void
    {
        $constructor = (new ReflectionClass(ChannelDeclaration::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    /**
     * The one refusal left inside the constructor — an empty level list — is
     * unreachable through any factory, because all three take a mandatory
     * first level. Widening any signature to a variadic-only list puts it back
     * in reach, and this test is what says so out loud instead of leaving a
     * branch that looks live and is not.
     */
    #[Test]
    public function itLeavesTheEmptyLevelListRefusalUnstatableThroughTheFactorySignatures(): void
    {
        $reflection = new ReflectionClass(ChannelDeclaration::class);

        foreach (['magnitude', 'occurrence', 'judging'] as $name) {
            $factory = $reflection->getMethod($name);
            $levels = array_values(array_filter(
                $factory->getParameters(),
                static fn(ReflectionParameter $parameter): bool => $parameter->getName() === 'level',
            ));

            self::assertCount(1, $levels, $factory->getName() . '() must take a mandatory first level.');
            self::assertFalse($levels[0]->isVariadic());
            self::assertFalse($levels[0]->isOptional());
        }
    }

    /**
     * Neither factory can express the other shape's direction state:
     * `magnitude()`'s direction parameter is non-nullable, and `occurrence()`
     * has no direction parameter at all. Nothing in {@see ChannelDeclaration}
     * checks this at run time any more — {@see \Qualimetrix\Analysis\Finding\Contract\ChannelShape} is a producer
     * property now, and registry assembly is what checks a producer's
     * declared shape against this nullability (see
     * {@see \Qualimetrix\Tests\Infrastructure\Unit\ChannelDeclarationCompilerPassTest}).
     * This pins that the two signatures still make the mismatch
     * unrepresentable in the first place.
     */
    #[Test]
    public function itLeavesTheDirectionPairingUnrepresentableThroughTheFactorySignatures(): void
    {
        $magnitude = (new ReflectionClass(ChannelDeclaration::class))->getMethod('magnitude');
        $occurrence = (new ReflectionClass(ChannelDeclaration::class))->getMethod('occurrence');

        $direction = $magnitude->getParameters()[0];
        self::assertSame('direction', $direction->getName());
        self::assertFalse($direction->allowsNull(), 'A nullable direction would make a shapeless magnitude representable.');

        self::assertSame(
            ['level', 'moreLevels'],
            array_map(
                static fn(ReflectionParameter $parameter): string => $parameter->getName(),
                $occurrence->getParameters(),
            ),
            'An occurrence factory that accepted a direction would make a directed occurrence representable.',
        );
    }

    #[Test]
    public function itBuildsAMagnitudeDeclarationViaTheFactory(): void
    {
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_);

        self::assertSame(WorseDirection::Lower, $declaration->direction);
    }

    #[Test]
    public function itBuildsAnOccurrenceDeclarationViaTheFactory(): void
    {
        $declaration = ChannelDeclaration::occurrence(SymbolLevel::Class_);

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

    /**
     * `judging()` is `magnitude()` plus the metrics: same shape, same
     * direction requirement, no third {@see \Qualimetrix\Analysis\Finding\Contract\ChannelShape}
     * case (ADR 0046). A caller that could reach the metrics through
     * `magnitude()` or drop them here would make the two factories differ in
     * something other than that one declared fact.
     */
    #[Test]
    public function itBuildsAJudgingDeclarationThatIsAMagnitudeCarryingItsMetrics(): void
    {
        $declaration = ChannelDeclaration::judging(
            WorseDirection::Higher,
            JudgedMetrics::of(MetricName::COMPLEXITY_CCN),
            SymbolLevel::Callable,
        );

        self::assertSame(WorseDirection::Higher, $declaration->direction);
        self::assertNotNull($declaration->judges);
        self::assertSame([MetricName::COMPLEXITY_CCN], $declaration->judges->keys);
        self::assertFalse($declaration->isConfigurationError());
    }

    /**
     * Naming no judged metric is the default, not an omission to be filled in
     * later: `architecture.circular-dependency` and three others publish a
     * magnitude of their own making, and `null` is how they say so.
     */
    #[Test]
    public function itLeavesTheJudgedMetricsUnsetForTheTwoOlderFactories(): void
    {
        self::assertNull(ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Project)->judges);
        self::assertNull(ChannelDeclaration::occurrence(SymbolLevel::Project)->judges);
    }

    #[Test]
    public function itCarriesTheJudgedMetricsThroughTheConfigurationErrorWither(): void
    {
        $declaration = ChannelDeclaration::judging(
            WorseDirection::Lower,
            JudgedMetrics::of(MetricName::MAINTAINABILITY_MI),
            SymbolLevel::Callable,
        )->asConfigurationError();

        self::assertNotNull($declaration->judges);
        self::assertSame([MetricName::MAINTAINABILITY_MI], $declaration->judges->keys);
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
