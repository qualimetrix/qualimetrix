<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelSelector;

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
final readonly class ChannelExclusionKeyHints
{
    private const string OPTION = 'exclude_namespace_channels';

    public function __construct(
        private ChannelIdentityInterface $identity,
    ) {}

    /** The key is not in the grammar at all — nothing has been looked up yet. */
    public function notASelector(string $ruleName, string $key): string
    {
        return self::prefix($ruleName, $key) . ' is not a channel selector.'
            . (ChannelSelector::looksLikePair($key)
                ? ' The "ruleName#violationCode" form takes exactly two exact halves and no "*" in either.'
                : ' Write an exact channel name, "ruleName#violationCode", or "X.*" for the channels below it.');
    }

    /**
     * @param list<FindingChannel> $addressed what the key covers in the whole universe
     * @param list<FindingChannel> $produced what the owning rule emits
     */
    public function refusal(
        string $ruleName,
        ChannelSelector $parsed,
        array $addressed,
        array $produced,
    ): string {
        return self::prefix($ruleName, (string) $parsed)
            . $this->diagnosis($ruleName, $parsed, $addressed)
            . \sprintf(' The channels of "%s" are: %s.', $ruleName, self::spell($produced));
    }

    /**
     * Which half is wrong, in the order the answer is worth anything: a real
     * channel this rule does not produce, then a pair whose code exists under
     * another rule name — the common mistake, because reports print the code
     * and not the pair — then a key that names nothing at all.
     *
     * @param list<FindingChannel> $addressed
     */
    private function diagnosis(string $ruleName, ChannelSelector $parsed, array $addressed): string
    {
        if ($addressed !== []) {
            return \sprintf(
                ' addresses %s — none of them produced by "%s", so the exclusion could never apply.',
                self::spell($addressed),
                $ruleName,
            );
        }

        $pair = $parsed->exactChannel();
        if ($pair === null) {
            return ' addresses no channel.';
        }

        $sameCode = array_values(array_filter(
            $this->identity->channels(),
            static fn(FindingChannel $channel): bool => $channel->code === $pair->code,
        ));

        return $sameCode === []
            ? \sprintf(' addresses no channel: no channel carries the code "%s".', $pair->code)
            : \sprintf(
                ' addresses no channel: the rule half is wrong, "%s" is spelled %s.',
                $pair->code,
                self::spell($sameCode),
            );
    }

    private static function prefix(string $ruleName, string $key): string
    {
        return \sprintf('Option "%s" for rule "%s" is keyed by "%s", which', self::OPTION, $ruleName, $key);
    }

    /** @param list<FindingChannel> $channels */
    private static function spell(array $channels): string
    {
        return $channels === [] ? 'none' : implode(', ', array_map(
            static fn(FindingChannel $channel): string => $channel->toKey(),
            $channels,
        ));
    }
}
