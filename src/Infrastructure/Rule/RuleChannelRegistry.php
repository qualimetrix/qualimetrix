<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Core\Violation\ViolationChannel;

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

        foreach (ComputedMetricDefinitionHolder::getDefinitions() as $definition) {
            $channels[] = new ViolationChannel($producerRuleName, $definition->name);
        }

        return $channels;
    }
}
