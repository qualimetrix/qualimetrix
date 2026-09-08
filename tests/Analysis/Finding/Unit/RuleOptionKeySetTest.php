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

#[CoversClass(RuleOptionKeySet::class)]
final class RuleOptionKeySetTest extends TestCase
{
    #[Test]
    public function itPlacesAnAcceptedKeyInTheAcceptedStateOnly(): void
    {
        $set = RuleOptionKeySet::of('threshold', 'max-warning');

        self::assertTrue($set->knows('threshold'));
        self::assertTrue($set->accepts('threshold'));
        self::assertSame(['max-warning', 'threshold'], $set->acceptedForDisplay());
    }

    #[Test]
    public function itKnowsAKeyTheClassAnswersAboutItselfWithoutAcceptingIt(): void
    {
        $set = RuleOptionKeySet::of('mode')->alsoAnsweredByTheClass('enabled');

        self::assertTrue($set->knows('enabled'));
        self::assertFalse($set->accepts('enabled'));
        self::assertSame(['mode'], $set->acceptedForDisplay());
    }

    #[Test]
    public function itPlacesAnUndeclaredKeyInNeitherState(): void
    {
        $set = RuleOptionKeySet::of('mode')->alsoAnsweredByTheClass('enabled');

        self::assertFalse($set->knows('warning'));
        self::assertFalse($set->accepts('warning'));
    }

    #[Test]
    public function itAnswersAboutEveryKeyOfBothHalvesAndNoOther(): void
    {
        $set = RuleOptionKeySet::of('threshold', 'vo-threshold')->alsoAnsweredByTheClass('enabled');

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
        $set = RuleOptionKeySet::of('max-warning')->alsoAnsweredByTheClass('vo-threshold');

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
        self::assertSame(['vo-threshold'], RuleOptionKeySet::of('vo-threshold')->acceptedForDisplay());
    }

    #[Test]
    public function itRefusesAKeyDeclaredInAnythingButKebab(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('canonical kebab spelling ("max-warning")');

        RuleOptionKeySet::of('maxWarning');
    }

    #[Test]
    public function itRefusesAKeyThatWouldSitInTwoStatesAtOnce(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('declared twice');

        RuleOptionKeySet::of('enabled')->alsoAnsweredByTheClass('enabled');
    }

    #[Test]
    public function itRefusesTheSameKeySpelledTwoWaysInOneHalf(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('declared twice');

        RuleOptionKeySet::of('max-warning', 'max-warning');
    }

    #[Test]
    public function itRefusesABlankKey(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('blank key');

        RuleOptionKeySet::of('   ');
    }

    #[Test]
    public function itLetsASecondAnsweredHalfJoinTheFirst(): void
    {
        $set = RuleOptionKeySet::of('mode')
            ->alsoAnsweredByTheClass('enabled')
            ->alsoAnsweredByTheClass('warning');

        self::assertTrue($set->knows('enabled'));
        self::assertTrue($set->knows('warning'));
        self::assertFalse($set->accepts('warning'));
    }

    #[Test]
    public function itAcceptsAnEmptyDeclaration(): void
    {
        $set = RuleOptionKeySet::of();

        self::assertSame([], $set->acceptedForDisplay());
        self::assertFalse($set->knows('enabled'));
    }
}
