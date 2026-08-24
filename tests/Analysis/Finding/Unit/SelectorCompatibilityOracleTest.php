<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;

/**
 * The compatibility oracle for selector semantics — and it is **synthetic on
 * purpose**.
 *
 * Among the project's own declared channels there is not one pair where one
 * channel's code is a dotted descendant of another channel's code. A test
 * written against real names (`architecture.coverage` and friends) would
 * therefore stay green with the group semantics completely broken: there is
 * nothing for a parent selector to wrongly swallow. The fixture below supplies
 * exactly that missing pair, so every case here can actually fail.
 *
 * `demo.rule` is a producer with three channels: `demo.rule`, its dotted
 * descendant `demo.rule.leaf`, and that one's own descendant
 * `demo.rule.leaf.deep`. Two levels of descent are what makes the *exact*
 * case discriminating: under the old prefix semantics `demo.rule.leaf` also
 * swallowed `demo.rule.leaf.deep`. `demo.other` is an unrelated sibling
 * producer that must never be caught by a `demo`-shaped selector.
 */
#[CoversClass(RuleSelector::class)]
final class SelectorCompatibilityOracleTest extends TestCase
{
    private const string PRODUCER = 'demo.rule';

    private const string SIBLING_PRODUCER = 'demo.other';

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideSelectorCases(): iterable
    {
        // The producer is named `demo.rule` and so is one of its channels —
        // the shape 38 of the project's 51 channels have. A selector equal to
        // the producer name addresses the *rule*, so it takes every channel
        // the rule emits; that is selection's documented "rule and/or channel"
        // reading, not a prefix match, and `demo.rule.*` below shows the
        // difference.
        yield 'exact producer name selects every channel of that producer' => [
            'demo.rule',
            ['demo.rule#demo.rule', 'demo.rule#demo.rule.leaf', 'demo.rule#demo.rule.leaf.deep'],
        ];

        yield 'group selector selects strict descendants and not the parent' => [
            'demo.rule.*',
            ['demo.rule#demo.rule.leaf', 'demo.rule#demo.rule.leaf.deep'],
        ];

        yield 'exact descendant does not swallow its own descendant' => [
            'demo.rule.leaf',
            ['demo.rule#demo.rule.leaf'],
        ];

        yield 'bare prefix without a star selects nothing' => [
            'demo',
            [],
        ];

        yield 'lone wildcard is not a selector' => [
            '*',
            [],
        ];

        yield 'selector deeper than any channel selects nothing' => [
            'demo.rule.leaf.deeper',
            [],
        ];

        yield 'group selector on the sibling does not reach this producer' => [
            'demo.other.*',
            [],
        ];

        yield 'explicit two-part form addresses both halves exactly' => [
            'demo.rule#demo.rule.leaf',
            ['demo.rule#demo.rule.leaf'],
        ];

        yield 'explicit two-part form takes no wildcard' => [
            'demo.rule#demo.rule.*',
            [],
        ];
    }

    /**
     * @param list<string> $expectedChannelKeys
     */
    #[Test]
    #[DataProvider('provideSelectorCases')]
    public function itSelectsExactlyTheseChannels(string $selector, array $expectedChannelKeys): void
    {
        $rules = new RuleSelector(self::registry());

        $selected = [];
        foreach (self::channels() as $channel) {
            if ($rules->isChannelEnabled(self::PRODUCER, $channel, [$selector], [])) {
                $selected[] = $channel->toKey();
            }
        }

        self::assertSame($expectedChannelKeys, $selected);
    }

    /**
     * The same table read the other way round: what an `only` selector keeps,
     * a `disabled` selector removes.
     *
     * @param list<string> $expectedChannelKeys
     */
    #[Test]
    #[DataProvider('provideSelectorCases')]
    public function itDisablesExactlyTheseChannels(string $selector, array $expectedChannelKeys): void
    {
        $rules = new RuleSelector(self::registry());

        $removed = [];
        foreach (self::channels() as $channel) {
            if (!$rules->isChannelEnabled(self::PRODUCER, $channel, [], [$selector])) {
                $removed[] = $channel->toKey();
            }
        }

        self::assertSame($expectedChannelKeys, $removed);
    }

    /**
     * A producer keeps running while any of its channels is still selected —
     * and a selector naming only the descendant channel must still reach it,
     * through the registry rather than through the producer name happening to
     * be a prefix of the selector.
     */
    #[Test]
    public function itEnablesTheProducerThroughItsChannelsAndNotByReversePrefix(): void
    {
        $rules = new RuleSelector(self::registry());

        self::assertTrue($rules->isProducerEnabled(self::PRODUCER, ['demo.rule.leaf'], []));
        self::assertTrue($rules->isProducerEnabled(self::PRODUCER, ['demo.rule.*'], []));
        self::assertFalse($rules->isProducerEnabled(self::PRODUCER, ['demo'], []));
        self::assertFalse($rules->isProducerEnabled(self::PRODUCER, ['*'], []));
        self::assertFalse($rules->isProducerEnabled(self::SIBLING_PRODUCER, ['demo.rule.*'], []));
    }

    /**
     * Rule-option ownership is the one surface with no group form at all: a
     * `rules:` key resolves to exactly one options object.
     */
    #[Test]
    public function itAcceptsOnlyExactProducerNamesAsOptionOwners(): void
    {
        $rules = new RuleSelector(self::registry());
        $producers = [self::PRODUCER, self::SIBLING_PRODUCER];

        self::assertTrue($rules->matchesKnownProducer('demo.rule', $producers));
        self::assertFalse($rules->matchesKnownProducer('demo', $producers));
        self::assertFalse($rules->matchesKnownProducer('demo.*', $producers));
        self::assertFalse($rules->matchesKnownProducer('demo.rule.leaf', $producers));
        self::assertFalse($rules->matchesKnownProducer('*', $producers));
    }

    /** @return list<FindingChannel> */
    private static function channels(): array
    {
        return [
            new FindingChannel(self::PRODUCER, 'demo.rule'),
            new FindingChannel(self::PRODUCER, 'demo.rule.leaf'),
            new FindingChannel(self::PRODUCER, 'demo.rule.leaf.deep'),
        ];
    }

    private static function registry(): InMemoryRuleChannelRegistry
    {
        return new InMemoryRuleChannelRegistry([
            self::PRODUCER => self::channels(),
            self::SIBLING_PRODUCER => [new FindingChannel(self::SIBLING_PRODUCER, self::SIBLING_PRODUCER)],
        ]);
    }
}
