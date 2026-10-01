<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

/**
 * The compatibility oracle for selector semantics — and it is **synthetic on
 * purpose**.
 *
 * Among the project's own declared channels there is not one pair where one
 * channel's code is a dotted descendant of another channel's code. A test
 * written against real names (`architecture.coverage-gap` and friends) would
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
#[CoversClass(RuleEnablementResolver::class)]
final class SelectorCompatibilityOracleTest extends TestCase
{
    private const string PRODUCER = 'demo.rule';

    private const string SIBLING_PRODUCER = 'demo.other';

    /**
     * @return iterable<string, array{string, list<string>, ?string}>
     */
    public static function provideSelectorCases(): iterable
    {
        // The producer is named `demo.rule` and so is one of its channels. A selector equal to
        // the producer name addresses the *rule*, so it takes every channel
        // the rule emits; that is selection's documented "rule and/or channel"
        // reading, not a prefix match, and `demo.rule.*` below shows the
        // difference.
        yield 'exact producer name selects every channel of that producer' => [
            'demo.rule',
            ['demo.rule', 'demo.rule.leaf', 'demo.rule.leaf.deep'],
            null,
        ];

        yield 'group selector selects strict descendants and not the parent' => [
            'demo.rule.*',
            ['demo.rule.leaf', 'demo.rule.leaf.deep'],
            null,
        ];

        yield 'exact descendant does not swallow its own descendant' => [
            'demo.rule.leaf',
            ['demo.rule.leaf'],
            null,
        ];

        yield 'bare prefix without a star is refused' => [
            'demo',
            [],
            "Rule selector \"demo\" does not match any registered producer or channel. Write \"demo.*\" to select its descendants.",
        ];

        yield 'lone wildcard is refused' => [
            '*',
            [],
            "Rule selector \"*\" does not match any registered producer or channel.",
        ];

        yield 'selector deeper than any channel is refused' => [
            'demo.rule.leaf.deeper',
            [],
            "Rule selector \"demo.rule.leaf.deeper\" does not match any registered producer or channel.",
        ];

        yield 'group selector on the sibling does not reach this producer' => [
            'demo.other.*',
            [],
            null,
        ];

        yield 'explicit two-part form addresses both halves exactly' => [
            'demo.rule.leaf',
            ['demo.rule.leaf'],
            null,
        ];

        yield 'retired two-part form is refused' => [
            'demo.rule#demo.rule.*',
            [],
            "Rule selector \"demo.rule#demo.rule.*\" is written in the retired channel-pair form. The \"ruleName#code\" spelling of a channel is gone: a channel is named by its code alone. Write \"demo.rule.*\".",
        ];
    }

    /**
     * @param list<string> $expectedChannelKeys
     */
    #[Test]
    #[DataProvider('provideSelectorCases')]
    public function itSelectsExactlyTheseChannels(string $selector, array $expectedChannelKeys, ?string $refusal): void
    {
        if ($refusal !== null) {
            try {
                self::enablement([$selector]);
                self::fail('An unknown or retired selector must be refused.');
            } catch (ConfigurationRefusal $exception) {
                self::assertSame($refusal, $exception->getMessage());
            }

            return;
        }

        $rules = self::enablement([$selector]);

        $selected = [];
        foreach (self::channels() as $channel) {
            if ($rules->publishes($channel, SymbolLevel::Class_)) {
                $selected[] = $channel->code;
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
    public function itDisablesExactlyTheseChannels(string $selector, array $expectedChannelKeys, ?string $refusal): void
    {
        if ($refusal !== null) {
            try {
                self::enablement(disabled: [$selector]);
                self::fail('An unknown or retired selector must be refused.');
            } catch (ConfigurationRefusal $exception) {
                self::assertSame($refusal, $exception->getMessage());
            }

            return;
        }

        $rules = self::enablement(disabled: [$selector]);

        $removed = [];
        foreach (self::channels() as $channel) {
            if (!$rules->publishes($channel, SymbolLevel::Class_)) {
                $removed[] = $channel->code;
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
        self::assertTrue(self::enablement(['demo.rule.leaf'])->runs(self::PRODUCER));
        self::assertTrue(self::enablement(['demo.rule.*'])->runs(self::PRODUCER));
        self::assertFalse(self::enablement(['demo.rule.*'])->runs(self::SIBLING_PRODUCER));
        foreach ([
            'demo' => 'Rule selector "demo" does not match any registered producer or channel. Write "demo.*" to select its descendants.',
            '*' => 'Rule selector "*" does not match any registered producer or channel.',
        ] as $selector => $refusal) {
            try {
                self::enablement([$selector]);
                self::fail('A reverse prefix or lone wildcard must not become a selector.');
            } catch (ConfigurationRefusal $exception) {
                self::assertSame($refusal, $exception->getMessage());
            }
        }
    }

    /**
     * Rule-option ownership is the one surface with no group form at all: a
     * `rules:` key resolves to exactly one options object.
     */
    #[Test]
    public function itAcceptsOnlyExactProducerNamesAsOptionOwners(): void
    {
        $rules = self::registry();

        self::assertTrue($rules->hasRule('demo.rule'));
        self::assertFalse($rules->hasRule('demo'));
        self::assertFalse($rules->hasRule('demo.*'));
        self::assertFalse($rules->hasRule('demo.rule.leaf'));
        self::assertFalse($rules->hasRule('*'));
    }

    /** @return list<FindingChannel> */
    private static function channels(): array
    {
        return [
            new FindingChannel('demo.rule'),
            new FindingChannel('demo.rule.leaf'),
            new FindingChannel('demo.rule.leaf.deep'),
        ];
    }

    private static function registry(): ChannelUniverse
    {
        return new ChannelUniverse(
            [
                'demo.rule' => ChannelDeclaration::occurrence(SymbolLevel::Class_),
                'demo.rule.leaf' => ChannelDeclaration::occurrence(SymbolLevel::Class_),
                'demo.rule.leaf.deep' => ChannelDeclaration::occurrence(SymbolLevel::Class_),
                self::SIBLING_PRODUCER => ChannelDeclaration::occurrence(SymbolLevel::Class_),
                'demo.other.leaf' => ChannelDeclaration::occurrence(SymbolLevel::Class_),
            ],
            [
                self::PRODUCER => array_map(static fn(FindingChannel $channel): string => $channel->code, self::channels()),
                self::SIBLING_PRODUCER => [self::SIBLING_PRODUCER, 'demo.other.leaf'],
            ],
            [self::PRODUCER => false, self::SIBLING_PRODUCER => false],
            new ResolvedComputedMetricDefinitions([]),
        );
    }

    /** @param list<string> $only
     * @param list<string> $disabled
     */
    private static function enablement(array $only = [], array $disabled = []): RuleEnablement
    {
        $metadata = [
            new RuleMetadata(self::PRODUCER, CodeDuplicationOptions::class, '', [], false),
            new RuleMetadata(self::SIBLING_PRODUCER, CodeDuplicationOptions::class, '', [], false),
        ];
        return ResolvedOptionsFixture::ready(FindingConfiguration::none(), $metadata, channels: self::registry(), only: $only, disabled: $disabled)->enablement
            ?? throw new LogicException('The fixture must carry final enablement.');
    }
}
