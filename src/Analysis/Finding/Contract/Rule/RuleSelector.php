<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;

/**
 * Applies rule filters to producer rules and full violation channels.
 *
 * Bare selectors preserve the CLI's prefix shorthand and may address either
 * a producer rule, a channel rule name, or a violation code. A selector in
 * `ruleName#violationCode` form addresses both channel components explicitly.
 */
final readonly class RuleSelector
{
    public function __construct(
        private RuleChannelRegistryInterface $channels,
    ) {}

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
        ViolationChannel $channel,
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
        foreach ($producerRuleNames as $producerRuleName) {
            if ($this->selectorMatchesProducer($selector, $producerRuleName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a bare rule name addresses a registered producer. This is for
     * rule-option validation, whose keys cannot address violation channels.
     *
     * @param list<string> $producerRuleNames
     */
    public function matchesKnownProducer(string $name, array $producerRuleNames): bool
    {
        return RuleMatcher::anyMatches($producerRuleNames, $name)
            || RuleMatcher::anyReverseMatches($producerRuleNames, $name);
    }

    /**
     * @param list<string> $selectors
     */
    private function matchesProducer(array $selectors, string $producerRuleName): bool
    {
        foreach ($selectors as $selector) {
            if ($this->selectorMatchesProducer($selector, $producerRuleName)) {
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
            if (!str_contains($selector, '#') && RuleMatcher::matches($selector, $producerRuleName)) {
                return true;
            }
        }

        return false;
    }

    private function selectorMatchesProducer(string $selector, string $producerRuleName): bool
    {
        if (!str_contains($selector, '#')) {
            if (RuleMatcher::matches($selector, $producerRuleName)
                || RuleMatcher::matches($producerRuleName, $selector)
            ) {
                return true;
            }
        }

        foreach ($this->channels->channelsProducedBy($producerRuleName) as $channel) {
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
        ViolationChannel $channel,
    ): bool {
        foreach ($selectors as $selector) {
            if (!str_contains($selector, '#') && RuleMatcher::matches($selector, $producerRuleName)) {
                return true;
            }

            if ($this->matchesChannel($selector, $channel)) {
                return true;
            }
        }

        return false;
    }

    private function matchesChannel(string $selector, ViolationChannel $channel): bool
    {
        if (!str_contains($selector, '#')) {
            return RuleMatcher::matches($selector, $channel->ruleName)
                || RuleMatcher::matches($selector, $channel->violationCode);
        }

        $parts = explode('#', $selector);
        if (\count($parts) !== 2) {
            return false;
        }

        [$ruleName, $violationCode] = $parts;

        return $ruleName !== ''
            && $violationCode !== ''
            && RuleMatcher::matches($ruleName, $channel->ruleName)
            && RuleMatcher::matches($violationCode, $channel->violationCode);
    }
}
