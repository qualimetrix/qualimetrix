<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/**
 * Applies rule filters to producer rules and finding channels.
 *
 * A selector is a {@see ChannelLevelSelector}: an exact name, or `X.*` for its
 * strict descendants, either optionally narrowed to one level of the
 * aggregation tree with `:level`. It may address a producer rule or a channel
 * — selection is the one surface that deliberately reads both vocabularies,
 * because `--disable-rule` has always been asked to mean both "stop running
 * this rule" and "stop reporting this channel".
 *
 * A selector carrying a level addresses a **channel** and never a producer
 * name, so it never stops a rule from running: `--disable-rule
 * coupling.cbo:namespace` leaves the class findings of the same channel
 * reported, exactly as a channel-specific selector always has.
 *
 * There is no second, channel-specific grammar any more. A channel is named by
 * one name, so the `ruleName#violationCode` form has nothing left to
 * disambiguate; it is refused where a selector is validated
 * ({@see \Qualimetrix\Infrastructure\Console\RuleInputValidator}) rather than
 * quietly matching nothing here.
 *
 * Two behaviours are gone, and neither was ever written down as a decision:
 * a bare prefix silently standing for a group, and the reverse match by which
 * a *narrower* selector enabled a broader producer.
 */
final class RuleSelector
{
    private RuleChannelRegistryInterface $channels;

    public function __construct(
        private readonly RuleChannelRegistryInterface $defaultChannels,
    ) {
        $this->channels = $defaultChannels;
    }

    public function replaceChannels(RuleChannelRegistryInterface $channels): void
    {
        $this->channels = $channels;
    }

    public function resetChannels(): void
    {
        $this->channels = $this->defaultChannels;
    }

    /**
     * @param list<string> $onlySelectors
     * @param list<string> $disabledSelectors
     */
    public function isProducerEnabled(
        string $producerRuleName,
        array $onlySelectors,
        array $disabledSelectors,
    ): bool {
        if ($this->matchesProducerName($disabledSelectors, $producerRuleName)) {
            return false;
        }

        if ($onlySelectors === []) {
            return true;
        }

        return $this->matchesProducer($onlySelectors, $producerRuleName);
    }

    /**
     * @param list<string> $onlySelectors
     * @param list<string> $disabledSelectors
     */
    public function isChannelEnabled(
        string $producerRuleName,
        FindingChannel $channel,
        SymbolLevel $level,
        array $onlySelectors,
        array $disabledSelectors,
    ): bool {
        if ($this->anyMatchesProducerOrChannel($disabledSelectors, $producerRuleName, $channel, $level)) {
            return false;
        }

        if ($onlySelectors === []) {
            return true;
        }

        return $this->anyMatchesProducerOrChannel($onlySelectors, $producerRuleName, $channel, $level);
    }

    /**
     * Whether a selector addresses any registered producer or one of its
     * declared/runtime channels.
     *
     * @param list<string> $producerRuleNames
     */
    public function matchesKnown(string $selector, array $producerRuleNames): bool
    {
        return $this->matchesKnownIn($selector, $producerRuleNames, $this->channels);
    }

    /**
     * @param list<string> $producerRuleNames
     */
    public function matchesKnownIn(
        string $selector,
        array $producerRuleNames,
        RuleChannelRegistryInterface $channels,
    ): bool {
        foreach ($producerRuleNames as $producerRuleName) {
            if ($this->selectorMatchesProducer($selector, $producerRuleName, $channels)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a name addresses a registered producer **exactly**. This is for
     * rule-option ownership — `rules:` keys and `--rule-opt RULE:...` — whose
     * keys cannot address finding channels and cannot address a group
     * either: options are applied by exact key, so a group key configured
     * nothing while looking as if it did.
     *
     * @param list<string> $producerRuleNames
     */
    public function matchesKnownProducer(string $name, array $producerRuleNames): bool
    {
        return \in_array($name, $producerRuleNames, true);
    }

    /**
     * @param list<string> $selectors
     */
    private function matchesProducer(array $selectors, string $producerRuleName): bool
    {
        foreach ($selectors as $selector) {
            if ($this->selectorMatchesProducer($selector, $producerRuleName, $this->channels)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A channel-specific disable selector must not prevent its producer from
     * running, because the producer may emit other enabled channels.
     *
     * @param list<string> $selectors
     */
    private function matchesProducerName(array $selectors, string $producerRuleName): bool
    {
        foreach ($selectors as $selector) {
            if (self::matchesName($selector, $producerRuleName)) {
                return true;
            }
        }

        return false;
    }

    private function selectorMatchesProducer(
        string $selector,
        string $producerRuleName,
        RuleChannelRegistryInterface $channels,
    ): bool {
        if (self::matchesName($selector, $producerRuleName)) {
            return true;
        }

        foreach ($channels->channelsProducedBy($producerRuleName) as $channel) {
            if (self::matchesChannel($selector, $channel, null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $selectors
     */
    private function anyMatchesProducerOrChannel(
        array $selectors,
        string $producerRuleName,
        FindingChannel $channel,
        ?SymbolLevel $level,
    ): bool {
        foreach ($selectors as $selector) {
            if (self::matchesName($selector, $producerRuleName)) {
                return true;
            }

            if (self::matchesChannel($selector, $channel, $level)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A selector without a level addresses every level of the channels it
     * names; one with a level addresses that level alone. The `null` level is
     * the reach question — "could this selector ever address this channel" —
     * asked where no finding exists yet, and there a level-bearing selector
     * still counts as reaching the channel.
     */
    private static function matchesChannel(string $selector, FindingChannel $channel, ?SymbolLevel $level): bool
    {
        $parsed = ChannelLevelSelector::tryParse($selector);

        if ($parsed === null) {
            return false;
        }

        return $level === null
            ? $parsed->channel()->matches($channel->code)
            : $parsed->matches($channel->code, $level);
    }

    /**
     * One-part selector semantics, in one place: equality, or `X.*` for strict
     * descendants. Text that is neither selects nothing.
     */
    private static function matchesName(string $selector, string $subject): bool
    {
        return NameSelector::tryParse($selector)?->matches($subject) === true;
    }
}
