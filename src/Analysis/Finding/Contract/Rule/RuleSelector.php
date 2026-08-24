<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/**
 * Applies rule filters to producer rules and full finding channels.
 *
 * A one-part selector is a {@see NameSelector}: an exact name, or `X.*` for
 * its strict descendants. It may address a producer rule, a channel's rule
 * name, or a channel's finding code — selection is the one surface that
 * deliberately reads both halves, because `--disable-rule` has always been
 * asked to mean both "stop running this rule" and "stop reporting this
 * channel".
 *
 * A selector in `ruleName#violationCode` form addresses both halves
 * explicitly, and both halves are exact. That form exists precisely to say
 * which half is meant, so a wildcard inside it would multiply the surface
 * without adding anything a one-part group selector cannot say.
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
        array $onlySelectors,
        array $disabledSelectors,
    ): bool {
        if ($this->anyMatchesProducerOrChannel($disabledSelectors, $producerRuleName, $channel)) {
            return false;
        }

        if ($onlySelectors === []) {
            return true;
        }

        return $this->anyMatchesProducerOrChannel($onlySelectors, $producerRuleName, $channel);
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
            if (!ChannelSelector::looksLikePair($selector) && self::matchesName($selector, $producerRuleName)) {
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
        if (!ChannelSelector::looksLikePair($selector) && self::matchesName($selector, $producerRuleName)) {
            return true;
        }

        foreach ($channels->channelsProducedBy($producerRuleName) as $channel) {
            if ($this->matchesChannel($selector, $channel)) {
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
    ): bool {
        foreach ($selectors as $selector) {
            if (!ChannelSelector::looksLikePair($selector) && self::matchesName($selector, $producerRuleName)) {
                return true;
            }

            if ($this->matchesChannel($selector, $channel)) {
                return true;
            }
        }

        return false;
    }

    private function matchesChannel(string $selector, FindingChannel $channel): bool
    {
        if (!ChannelSelector::looksLikePair($selector)) {
            return self::matchesName($selector, $channel->ruleName)
                || self::matchesName($selector, $channel->code);
        }

        return ChannelSelector::tryParse($selector)?->exactChannel()?->equals($channel) === true;
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
