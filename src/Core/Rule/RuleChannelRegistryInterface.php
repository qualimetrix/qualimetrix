<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use Qualimetrix\Core\Violation\ViolationChannel;

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
     * @return list<ViolationChannel>
     */
    public function channelsProducedBy(string $producerRuleName): array;
}
