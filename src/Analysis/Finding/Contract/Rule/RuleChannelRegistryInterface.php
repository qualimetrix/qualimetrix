<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/**
 * Maps a registered producer rule to the channels it can emit.
 *
 * A producer's {@see RuleInterface::getName()} is not necessarily either
 * component of every emitted channel. Selection therefore cannot infer this
 * relation from string prefixes; it must ask the registry explicitly.
 */
interface RuleChannelRegistryInterface
{
    /**
     * @return list<FindingChannel>
     */
    public function channelsProducedBy(string $producerRuleName): array;
}
