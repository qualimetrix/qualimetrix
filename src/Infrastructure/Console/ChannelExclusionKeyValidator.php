<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;

/**
 * Whether one `exclude_namespace_channels` key addresses a channel the rule it
 * is written under produces.
 *
 * The key reads the one selector grammar there is: an exact channel name, or
 * `X.*` for the channels below it. The retired `ruleName#violationCode`
 * spelling is refused by name rather than left to fall through as an unknown
 * name — this option is the one whose *key is a channel*, so it is where a
 * stale spelling is most likely to have been written down.
 *
 * Addressing a channel is necessary and not sufficient. The map is applied to
 * the findings of the rule it is configured under, so a key naming a channel
 * that rule never emits excludes nothing — the same "configured, does nothing"
 * outcome this validation exists to remove.
 *
 * **Production, not applicability.** `RuleExecution` offers this option only
 * findings whose subject is a namespace, so a key naming an occurrence or
 * class-only channel of the right rule passes here and still excludes nothing.
 * Refusing those would need a declared "can appear as a namespace aggregate"
 * property that `ChannelDeclaration` does not carry — see ADR 0025, which
 * records why a half-built version of that check was not worth having.
 */
final readonly class ChannelExclusionKeyValidator
{
    public function __construct(
        private ChannelUniverseInterface $channels,
    ) {}

    /** @throws InvalidArgumentException when the key can never exclude anything */
    public function assertAddressesAProducedChannel(string $ruleName, string $key): void
    {
        $parsed = NameSelector::tryParse($key)
            ?? throw new InvalidArgumentException(ChannelExclusionKeyHints::notASelector($ruleName, $key));

        $addressed = $this->channels->expand($parsed);
        $produced = $this->channels->channelsProducedBy($ruleName);

        foreach ($addressed as $channel) {
            foreach ($produced as $candidate) {
                if ($candidate->equals($channel)) {
                    return;
                }
            }
        }

        throw new InvalidArgumentException(ChannelExclusionKeyHints::refusal($ruleName, $parsed, $addressed, $produced));
    }
}
