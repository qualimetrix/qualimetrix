<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The grammar that writes a level down beside a channel name.
 *
 * What it must not do is the thing the level suffix used to do: be part of the
 * name. `coupling.cbo.*` swept `coupling.cbo.class` up as a descendant, so a
 * group selector silently meant "every level"; `coupling.cbo:class` cannot be
 * confused with a name because `:` is out of the name grammar entirely.
 */
#[CoversClass(ChannelLevelSelector::class)]
final class ChannelLevelSelectorTest extends TestCase
{
    #[Test]
    public function aSelectorWithoutALevelAddressesEveryLevelOfItsChannels(): void
    {
        $selector = ChannelLevelSelector::tryParse('coupling.cbo');

        self::assertNotNull($selector);
        self::assertNull($selector->level());
        self::assertTrue($selector->matches('coupling.cbo', SymbolLevel::Class_));
        self::assertTrue($selector->matches('coupling.cbo', SymbolLevel::Namespace_));
        self::assertFalse($selector->matches('coupling.instability', SymbolLevel::Class_));
    }

    #[Test]
    public function aSelectorWithALevelAddressesThatLevelAlone(): void
    {
        $selector = ChannelLevelSelector::tryParse('coupling.cbo:namespace');

        self::assertNotNull($selector);
        self::assertSame(SymbolLevel::Namespace_, $selector->level());
        self::assertTrue($selector->matches('coupling.cbo', SymbolLevel::Namespace_));
        self::assertFalse($selector->matches('coupling.cbo', SymbolLevel::Class_));
        self::assertSame('coupling.cbo:namespace', (string) $selector);
    }

    /** The group form and the level compose: `X.*:level` is both narrowings at once. */
    #[Test]
    public function aGroupSelectorTakesALevelToo(): void
    {
        $selector = ChannelLevelSelector::tryParse('coupling.*:namespace');

        self::assertNotNull($selector);
        self::assertTrue($selector->matches('coupling.cbo', SymbolLevel::Namespace_));
        self::assertFalse($selector->matches('coupling.cbo', SymbolLevel::Class_));
        self::assertFalse($selector->matches('coupling', SymbolLevel::Namespace_));
        self::assertSame('coupling.*:namespace', (string) $selector);
    }

    /**
     * The whole level vocabulary and nothing else, read from the enum so a
     * sixth level is covered the day it is added.
     */
    #[Test]
    public function everyLevelOfTheVocabularyParses(): void
    {
        foreach (SymbolLevel::cases() as $level) {
            $selector = ChannelLevelSelector::tryParse('demo.rule:' . $level->value);

            self::assertNotNull($selector, $level->value);
            self::assertSame($level, $selector->level());
        }
    }

    /**
     * @param string $raw text that is not a selector
     */
    #[Test]
    #[DataProvider('provideNonSelectors')]
    public function itRefusesTextThatIsNotOne(string $raw): void
    {
        self::assertNull(ChannelLevelSelector::tryParse($raw));
    }

    /** @return iterable<string, array{string}> */
    public static function provideNonSelectors(): iterable
    {
        yield 'a level outside the vocabulary' => ['coupling.cbo:klass'];
        yield 'an empty level half' => ['coupling.cbo:'];
        yield 'an empty channel half' => [':class'];
        yield 'a lone wildcard' => ['*'];
        yield 'the retired channel pair' => ['coupling.cbo#coupling.cbo.class'];
        yield 'two levels' => ['coupling.cbo:class:namespace'];
    }

    /**
     * The halves are readable separately so a refusal can say *which* one was
     * wrong instead of calling the whole string unparseable.
     */
    #[Test]
    public function itReadsEitherHalfOfTextThatDoesNotParseAsAWhole(): void
    {
        self::assertTrue(ChannelLevelSelector::carriesLevelSeparator('coupling.cbo:klass'));
        self::assertFalse(ChannelLevelSelector::carriesLevelSeparator('coupling.cbo'));
        self::assertSame('coupling.cbo', ChannelLevelSelector::channelHalf('coupling.cbo:klass'));
        self::assertNull(ChannelLevelSelector::levelHalf('coupling.cbo:klass'));
        self::assertSame(SymbolLevel::Class_, ChannelLevelSelector::levelHalf('coupling.cbo:class'));
    }

    /**
     * The quotable half and the level come from the same split, so a refusal
     * cannot quote two halves that do not add up to the authored text.
     */
    #[Test]
    public function itReadsTheTextAfterTheSeparatorWhetherOrNotItIsALevel(): void
    {
        self::assertSame('klass', ChannelLevelSelector::levelHalfText('coupling.cbo:klass'));
        self::assertSame('b', ChannelLevelSelector::levelHalfText('coupling.cbo:a:b'));
        self::assertSame('coupling.cbo:a', ChannelLevelSelector::channelHalf('coupling.cbo:a:b'));
        self::assertSame('', ChannelLevelSelector::levelHalfText('coupling.cbo:'));
        self::assertNull(ChannelLevelSelector::levelHalfText('coupling.cbo'));
    }
}
