<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;

/**
 * Runtime producer-to-channel registry.
 *
 * Static channel keys are assembled by the channel compiler pass. Computed
 * metric channels are resolved from the configured definitions because their
 * vocabulary is open-ended and unavailable while the container is compiled.
 */
final readonly class RuleChannelRegistry implements RuleChannelRegistryInterface, RuleChannelSnapshotFactoryInterface
{
    /**
     * @param array<string, list<string>> $staticChannelKeysByProducer
     */
    public function __construct(
        private array $staticChannelKeysByProducer,
        private string $computedMetricRuleName,
        private ResolvedComputedMetricDefinitions $definitions,
    ) {}

    public function snapshot(ResolvedComputedMetricDefinitions $definitions): RuleChannelRegistryInterface
    {
        return new self($this->staticChannelKeysByProducer, $this->computedMetricRuleName, $definitions);
    }

    public function channelsProducedBy(string $producerRuleName): array
    {
        $channels = array_map(
            ViolationChannel::fromKey(...),
            $this->staticChannelKeysByProducer[$producerRuleName] ?? [],
        );

        if ($producerRuleName !== $this->computedMetricRuleName) {
            return $channels;
        }

        foreach ($this->definitions->all() as $definition) {
            $channels[] = new ViolationChannel($producerRuleName, $definition->name);
        }

        return $channels;
    }
}
