<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;

/**
 * What to say about an `exclude_namespace_channels` key that can never exclude
 * anything.
 *
 * Separate from {@see ChannelExclusionKeyValidator} for the reason
 * `Analysis\Policy\Inline\Directive\DirectiveNameHints` is separate from the
 * rule that reports a bad directive: that one decides *whether* a key is wrong,
 * a judgement about this run's universe, while this decides what to *say* about
 * it, which is a search over names and nothing else.
 */
final class ChannelExclusionKeyHints
{
    private const string OPTION = 'exclude_namespace_channels';

    /** The key is not in the grammar at all — nothing has been looked up yet. */
    public static function notASelector(string $ruleName, string $key): string
    {
        return self::prefix($ruleName, $key) . ' is not a channel selector.'
            . (FindingChannel::isRetiredPairSpelling($key)
                ? ' ' . FindingChannel::retiredPairAdvice($key)
                : ' Write an exact channel name, or "X.*" for the channels below it.');
    }

    /**
     * @param list<FindingChannel> $addressed what the key covers in the whole universe
     * @param list<FindingChannel> $produced what the owning rule emits
     */
    public static function refusal(
        string $ruleName,
        NameSelector $parsed,
        array $addressed,
        array $produced,
    ): string {
        return self::prefix($ruleName, (string) $parsed)
            . self::diagnosis($ruleName, $parsed, $addressed)
            . \sprintf(' The channels of "%s" are: %s.', $ruleName, self::spell($produced));
    }

    /**
     * Whether the key names a real channel this rule does not produce, or names
     * nothing at all. There is no third case any more: a channel is one name,
     * so "the rule half is wrong" is not a mistake that can be made.
     *
     * @param list<FindingChannel> $addressed
     */
    private static function diagnosis(string $ruleName, NameSelector $parsed, array $addressed): string
    {
        if ($addressed !== []) {
            return \sprintf(
                ' addresses %s — none of them produced by "%s", so the exclusion could never apply.',
                self::spell($addressed),
                $ruleName,
            );
        }

        return \sprintf(' addresses no channel: no channel is named "%s".', (string) $parsed);
    }

    private static function prefix(string $ruleName, string $key): string
    {
        return \sprintf('Option "%s" for rule "%s" is keyed by "%s", which', self::OPTION, $ruleName, $key);
    }

    /** @param list<FindingChannel> $channels */
    private static function spell(array $channels): string
    {
        return $channels === [] ? 'none' : implode(', ', array_map(
            static fn(FindingChannel $channel): string => $channel->code,
            $channels,
        ));
    }
}
