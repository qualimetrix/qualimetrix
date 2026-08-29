<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Rule;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;

/**
 * Immutable channel registry for explicitly supplied producer declarations.
 *
 * Production uses the infrastructure registry, which also resolves dynamic
 * computed-metric channels. This implementation is useful for isolated
 * composition roots and tests where the complete declaration map is known.
 */
final readonly class InMemoryRuleChannelRegistry implements RuleChannelRegistryInterface
{
    /**
     * @param array<string, list<FindingChannel>> $channelsByProducer
     */
    public function __construct(
        private array $channelsByProducer = [],
    ) {}

    public function channelsProducedBy(string $producerRuleName): array
    {
        return $this->channelsByProducer[$producerRuleName] ?? [];
    }
}
