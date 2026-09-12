<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;

#[CoversClass(RuleOptionKeySet::class)]
final class RuleOptionKeySetTest extends TestCase
{
    #[Test]
    public function itPlacesAnAcceptedKeyInTheAcceptedStateOnly(): void
    {
        $set = RuleOptionKeySet::of(['threshold' => RuleOptionShape::text()->orNull(), 'max-warning' => RuleOptionShape::text()->orNull()]);

        self::assertTrue($set->knows('threshold'));
        self::assertTrue($set->accepts('threshold'));
        self::assertSame(['max-warning', 'threshold'], $set->acceptedForDisplay());
    }

    #[Test]
    public function itKnowsAKeyTheClassAnswersAboutItselfWithoutAcceptingIt(): void
    {
        $set = RuleOptionKeySet::of(['mode' => RuleOptionShape::text()->orNull()])->alsoAnsweredByTheClass('enabled');

        self::assertTrue($set->knows('enabled'));
        self::assertFalse($set->accepts('enabled'));
        self::assertSame(['mode'], $set->acceptedForDisplay());
    }

    #[Test]
    public function itPlacesAnUndeclaredKeyInNeitherState(): void
    {
        $set = RuleOptionKeySet::of(['mode' => RuleOptionShape::text()->orNull()])->alsoAnsweredByTheClass('enabled');

        self::assertFalse($set->knows('warning'));
        self::assertFalse($set->accepts('warning'));
    }

    #[Test]
    public function itAnswersAboutEveryKeyOfBothHalvesAndNoOther(): void
    {
        $set = RuleOptionKeySet::of(['threshold' => RuleOptionShape::text()->orNull(), 'vo-threshold' => RuleOptionShape::text()->orNull()])->alsoAnsweredByTheClass('enabled');

        $known = array_filter(
            ['threshold', 'voThreshold', 'enabled', 'warning', 'error'],
            $set->knows(...),
        );

        self::assertSame(['threshold', 'voThreshold', 'enabled'], array_values($known));
    }

    #[Test]
    #[DataProvider('provideEquivalentSpellings')]
    public function itFoldsSnakeCamelAndKebabIntoOneKey(string $accepted, string $answered, string $unknown): void
    {
        $set = RuleOptionKeySet::of(['max-warning' => RuleOptionShape::text()->orNull()])->alsoAnsweredByTheClass('vo-threshold');

        self::assertTrue($set->accepts(ConfigKeySpelling::normalize($accepted)));
        self::assertTrue($set->knows(ConfigKeySpelling::normalize($answered)));
        self::assertFalse($set->accepts(ConfigKeySpelling::normalize($answered)));
        self::assertFalse($set->knows(ConfigKeySpelling::normalize($unknown)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideEquivalentSpellings(): iterable
    {
        yield 'kebab' => ['max-warning', 'vo-threshold', 'max-error'];
        yield 'snake' => ['max_warning', 'vo_threshold', 'max_error'];
        yield 'camel' => ['maxWarning', 'voThreshold', 'maxError'];
        yield 'padded' => ['  max-warning  ', ' vo_threshold ', ' maxError '];
    }

    #[Test]
    public function itDisplaysTheDeclaredKebabSpellingRatherThanTheFoldedOne(): void
    {
        self::assertSame(['vo-threshold'], RuleOptionKeySet::of(['vo-threshold' => RuleOptionShape::text()->orNull()])->acceptedForDisplay());
    }

    #[Test]
    public function itRefusesAKeyDeclaredInAnythingButKebab(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('canonical kebab spelling ("max-warning")');

        RuleOptionKeySet::of(['maxWarning' => RuleOptionShape::text()->orNull()]);
    }

    #[Test]
    public function itRefusesAKeyThatWouldSitInTwoStatesAtOnce(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('declared twice');

        RuleOptionKeySet::of(['enabled' => RuleOptionShape::text()->orNull()])->alsoAnsweredByTheClass('enabled');
    }

    #[Test]
    public function itRefusesTheSameKeyDeclaredTwiceInTheAnsweredHalf(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('declared twice');

        RuleOptionKeySet::of(['max-warning' => RuleOptionShape::integer()])
            ->alsoAnsweredByTheClass('vo-threshold')
            ->alsoAnsweredByTheClass('vo-threshold');
    }

    #[Test]
    public function itRefusesABlankKey(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('blank key');

        RuleOptionKeySet::of(['   ' => RuleOptionShape::text()->orNull()]);
    }

    #[Test]
    public function itLetsASecondAnsweredHalfJoinTheFirst(): void
    {
        $set = RuleOptionKeySet::of(['mode' => RuleOptionShape::text()->orNull()])
            ->alsoAnsweredByTheClass('enabled')
            ->alsoAnsweredByTheClass('warning');

        self::assertTrue($set->knows('enabled'));
        self::assertTrue($set->knows('warning'));
        self::assertFalse($set->accepts('warning'));
    }

    #[Test]
    public function itAcceptsAnEmptyDeclaration(): void
    {
        $set = RuleOptionKeySet::of([]);

        self::assertSame([], $set->acceptedForDisplay());
        self::assertFalse($set->knows('enabled'));
    }

    /**
     * The guard that keeps `levelOptionsClasses()` the single source of a
     * slot: writing the slot by hand as well is the duplication codex-05
     * found, and it now fails loudly instead of going quietly out of step.
     */
    #[Test]
    public function itRefusesALevelSlotThatWasAlreadyDeclaredByHand(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('is declared twice');

        RuleOptionKeySet::of(['callable' => RuleOptionShape::block()])
            ->withLevelSlots(['callable' => \Qualimetrix\Analysis\Evidence\Complexity\MethodComplexityOptions::class]);
    }

    #[Test]
    public function itGivesEveryLevelSlotTheSameFormTheWalkIntoItAccepts(): void
    {
        $set = RuleOptionKeySet::of(['enabled' => RuleOptionShape::boolean()])
            ->withLevelSlots([
                'callable' => \Qualimetrix\Analysis\Evidence\Complexity\MethodComplexityOptions::class,
                'class' => \Qualimetrix\Analysis\Evidence\Complexity\ClassComplexityOptions::class,
            ]);

        self::assertSame(['callable', 'class', 'enabled'], $set->acceptedForDisplay());
        self::assertSame('a block of options or null', $set->shapeOf('callable')?->describe());
        self::assertTrue($set->shapeOf('class')?->matches(null));
    }
}
