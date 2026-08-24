<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelSelector;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;

#[CoversClass(ChannelSelector::class)]
final class ChannelSelectorTest extends TestCase
{
    #[Test]
    public function itReadsAOnePartSelectorAgainstTheCodeAlone(): void
    {
        $selector = ChannelSelector::tryParse('health.cohesion');

        self::assertNotNull($selector);
        self::assertInstanceOf(NameSelector::class, $selector->target());
        self::assertNull($selector->exactChannel());
        self::assertTrue($selector->matches(new FindingChannel('computed.health', 'health.cohesion')));
        self::assertTrue($selector->matches(new FindingChannel('anything.else', 'health.cohesion')));
        self::assertFalse($selector->matches(new FindingChannel('computed.health', 'health.coupling')));
    }

    #[Test]
    public function itKeepsTheGroupFormOfTheOnePartSelector(): void
    {
        $selector = ChannelSelector::tryParse('health.*');

        self::assertNotNull($selector);
        self::assertTrue($selector->matches(new FindingChannel('computed.health', 'health.cohesion')));
        self::assertFalse($selector->matches(new FindingChannel('computed.health', 'health')));
    }

    #[Test]
    public function itReadsBothHalvesOfThePairForm(): void
    {
        $selector = ChannelSelector::tryParse('computed.health#health.cohesion');

        self::assertNotNull($selector);
        self::assertEquals(
            new FindingChannel('computed.health', 'health.cohesion'),
            $selector->exactChannel(),
        );
        self::assertTrue($selector->matches(new FindingChannel('computed.health', 'health.cohesion')));
    }

    /**
     * The rule half is what the pair form exists for: without it, `a#x` and
     * `b#x` would be the same selector.
     */
    #[Test]
    public function itRefusesAChannelWhoseRuleHalfDiffers(): void
    {
        $selector = ChannelSelector::tryParse('computed.health#health.cohesion');

        self::assertNotNull($selector);
        self::assertFalse($selector->matches(new FindingChannel('coupling.cbo', 'health.cohesion')));
    }

    #[Test]
    #[DataProvider('provideTextThatIsNotASelector')]
    public function itAnswersNullForTextThatIsNotASelector(string $raw): void
    {
        self::assertNull(ChannelSelector::tryParse($raw));
    }

    /** @return iterable<string, array{string}> */
    public static function provideTextThatIsNotASelector(): iterable
    {
        yield 'empty' => [''];
        yield 'bare separator' => ['#'];
        yield 'empty violation code half' => ['coupling.cbo#'];
        yield 'empty rule half' => ['#coupling.cbo.class'];
        yield 'three halves' => ['a#b#c'];
        yield 'group inside the rule half' => ['coupling.*#coupling.cbo.class'];
        yield 'group inside the violation code half' => ['coupling.cbo#coupling.cbo.*'];
        yield 'wildcard alone' => ['*'];
        yield 'empty dot segment' => ['coupling..cbo'];
    }

    #[Test]
    public function itReportsThePairSpellingBeforeParsingSoAFailureCanBeExplained(): void
    {
        self::assertTrue(ChannelSelector::looksLikePair('coupling.cbo#coupling.cbo.*'));
        self::assertFalse(ChannelSelector::looksLikePair('coupling.cbo'));
    }

    #[Test]
    public function itRoundTripsTheAuthoredText(): void
    {
        self::assertSame('computed.health#health.cohesion', (string) ChannelSelector::tryParse('computed.health#health.cohesion'));
        self::assertSame('health.*', (string) ChannelSelector::tryParse('health.*'));
    }
}
