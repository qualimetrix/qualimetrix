<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/**
 * The one place an authored `channel:level` pair is refused.
 *
 * Ш5c widened the addressing vocabulary from three values to five, and a
 * level a channel does not report at is now a thing a user can write. There
 * was no seam that could refuse it: the configuration one
 * ({@see \Qualimetrix\Infrastructure\Console\ChannelExclusionKeyValidator})
 * throws, but only on the option whose key is a channel, and the inline one
 * ({@see \Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability})
 * reports, but only on a target that already parsed. Two seams deciding this
 * separately would give the two families of directive different answers to
 * the same mistake, so both ask here and neither decides.
 *
 * What each seam keeps is how it is *loud*: configuration and CLI fail the run
 * before analysis starts, an inline directive is reported on
 * `annotation.unresolved-directive`, which is a configuration error and ends
 * the run too. What neither may do is swallow the pair — the outcome
 * `baseline:explain` used to give, where a level nobody reports at looked like
 * a selector that simply matched nothing.
 *
 * Three refusals, and they are deliberately distinguishable because the
 * correction differs:
 *
 * - the half after the separator is not a level at all;
 * - the channel half addresses nothing, so no level of it can exist;
 * - the channel is real and does not report at that level.
 *
 * Text carrying no separator is not this type's business and is answered
 * `null`: whether such text addresses anything is the question each seam
 * already answered before this one existed.
 */
final readonly class ChannelLevelAddressing
{
    public function __construct(
        private ChannelIdentityInterface $identity,
    ) {}

    /**
     * What is wrong with an authored pair, or `null` when the text addresses
     * at least one channel that reports at the level it names.
     */
    public function problemWith(string $raw): ?string
    {
        if (!ChannelLevelSelector::carriesLevelSeparator($raw)) {
            return null;
        }

        $channelHalf = ChannelLevelSelector::channelHalf($raw);
        $level = ChannelLevelSelector::levelHalf($raw);

        if ($level === null) {
            return \sprintf(
                '"%s" names no level after "%s". A level is one of %s.',
                $raw,
                ChannelLevelSelector::LEVEL_SEPARATOR,
                self::vocabulary(),
            );
        }

        $channel = NameSelector::tryParse($channelHalf);

        if ($channel === null) {
            return \sprintf(
                '"%s" is written as a channel-and-level pair, but "%s" is not a channel selector.'
                . ' Write an exact channel name, or "X.*" for the channels below X, then "%s%s".',
                $raw,
                $channelHalf,
                ChannelLevelSelector::LEVEL_SEPARATOR,
                $level->value,
            );
        }

        $addressed = $this->identity->expand($channel);

        if ($addressed === []) {
            return \sprintf('"%s" addresses no channel, so it cannot address one at level "%s".', $raw, $level->value);
        }

        $reported = [];

        foreach ($addressed as $candidate) {
            $levels = $this->identity->levelsOf($candidate->code);

            if (\in_array($level, $levels, true)) {
                return null;
            }

            foreach ($levels as $declared) {
                $reported[$declared->value] = true;
            }
        }

        return \sprintf(
            'Channel selector "%s" addresses %s, and none of them reports at level "%s" — %s. The pair can never'
            . ' match anything.',
            $channelHalf,
            self::quotedList(array_map(static fn(FindingChannel $channel): string => $channel->code, $addressed)),
            $level->value,
            $reported === []
                ? 'none of them declares a level at all'
                : 'the levels available are ' . self::quotedList(array_map(strval(...), array_keys($reported))),
        );
    }

    /**
     * The whole level vocabulary, sorted — which is enum case order today only
     * because the cases happen to be alphabetical. The list is prose in a
     * refusal, so its order is the reader's convenience and not a statement
     * about the enum.
     */
    private static function vocabulary(): string
    {
        return self::quotedList(array_map(
            static fn(SymbolLevel $level): string => $level->value,
            SymbolLevel::cases(),
        ));
    }

    /** @param list<string> $values */
    private static function quotedList(array $values): string
    {
        sort($values);

        return implode(', ', array_map(static fn(string $value): string => '"' . $value . '"', $values));
    }
}
