<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;

/**
 * Runtime producer-to-channel registry.
 *
 * Static channel keys are assembled by the channel compiler pass. Computed
 * metric channels are resolved from the configured definitions because their
 * vocabulary is open-ended and unavailable while the container is compiled.
 */
final readonly class RuleChannelRegistry implements RuleChannelRegistryInterface
{
    /**
     * @param array<string, list<string>> $staticChannelKeysByProducer
     */
    public function __construct(
        private array $staticChannelKeysByProducer,
        private string $computedMetricRuleName,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {}

    public function channelsProducedBy(string $producerRuleName): array
    {
        $channels = array_map(
            ViolationChannel::fromKey(...),
            $this->staticChannelKeysByProducer[$producerRuleName] ?? [],
        );

        if ($producerRuleName !== $this->computedMetricRuleName) {
            return $channels;
        }

        foreach ($this->definitionCatalog->all() as $definition) {
            $channels[] = new ViolationChannel($producerRuleName, $definition->name);
        }

        return $channels;
    }
}
