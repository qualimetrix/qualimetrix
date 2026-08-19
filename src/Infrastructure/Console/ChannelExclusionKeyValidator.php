<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelSelector;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;

/**
 * Whether one `exclude_namespace_channels` key addresses a channel the rule it
 * is written under produces.
 *
 * The key reads the full selector grammar, the explicit
 * `ruleName#violationCode` pair included. This option is the one whose *key is
 * a channel*, so refusing it the full spelling of a channel would have made it
 * the odd surface out in a grammar whose point is being one grammar.
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
    private ChannelExclusionKeyHints $hints;

    public function __construct(
        private ChannelUniverseInterface $channels,
    ) {
        $this->hints = new ChannelExclusionKeyHints($channels);
    }

    /** @throws InvalidArgumentException when the key can never exclude anything */
    public function assertAddressesAProducedChannel(string $ruleName, string $key): void
    {
        $parsed = ChannelSelector::tryParse($key)
            ?? throw new InvalidArgumentException($this->hints->notASelector($ruleName, $key));

        $addressed = $this->addressedChannels($parsed);
        $produced = $this->channels->channelsProducedBy($ruleName);

        foreach ($addressed as $channel) {
            foreach ($produced as $candidate) {
                if ($candidate->equals($channel)) {
                    return;
                }
            }
        }

        throw new InvalidArgumentException($this->hints->refusal($ruleName, $parsed, $addressed, $produced));
    }

    /**
     * What the key addresses in the whole universe, before asking who produces
     * it — so the refusal can tell "no such channel" from "not this rule's".
     *
     * @return list<ViolationChannel>
     */
    private function addressedChannels(ChannelSelector $parsed): array
    {
        $target = $parsed->target();
        if ($target instanceof NameSelector) {
            return $this->channels->expand($target);
        }

        return array_values(array_filter(
            $this->channels->channels(),
            static fn(ViolationChannel $channel): bool => $channel->equals($target),
        ));
    }
}
