<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;

/**
 * The seven producers' options, addressed by the definition they would publish.
 *
 * One rule class serves seven producers, so a single `$options` object would
 * answer `enabled` for all of them at once: `rules: { health.cohesion: { enabled:
 * false } }` would switch off either everything or nothing. The rule therefore
 * asks this object per definition, and it routes through
 * {@see ComputedMetricChannelFamily::producerFor()} — the same arbiter that
 * decides which name the finding is published under, so the switch and the
 * published name can never disagree.
 *
 * Not a {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface}
 * on purpose: it is a collection of options, not options, and the rule's own
 * `$options` argument is bound by the options compiler pass from the one
 * producer that has a class.
 */
final readonly class ComputedMetricProducerOptions
{
    /**
     * @param array<string, ComputedMetricRuleOptions> $byProducer every name in
     *                                                             {@see ComputedMetricChannelFamily::PRODUCER_RULE_NAMES}, each built by
     *                                                             `RuleOptionsFactory::create()` so that its own `exclude_*` keys reach the
     *                                                             exclusion providers — the factory call is where that happens, and a
     *                                                             producer nobody builds options for excludes nothing while looking configured
     */
    public function __construct(
        private array $byProducer,
    ) {}

    public function isEnabledFor(string $definitionName): bool
    {
        $producer = ComputedMetricChannelFamily::producerFor($definitionName);

        return ($this->byProducer[$producer] ?? throw new LogicException(\sprintf(
            'No options were built for computed-metric producer "%s". Every name in'
            . ' ComputedMetricChannelFamily::PRODUCER_RULE_NAMES must be registered through'
            . ' RuleOptionsFactory::create(), or its configuration is read nowhere.',
            $producer,
        )))->isEnabled();
    }
}
