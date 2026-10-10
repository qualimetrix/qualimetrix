<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use InvalidArgumentException;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;

/**
 * The one instance behind every view of {@see ChannelUniverseInterface}.
 *
 * It never scans rule classes itself. The static half — declarations, the
 * producer of each declared channel, and each rule's declared threshold-override
 * support — is handed in by
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass},
 * mirroring {@see RuleRegistry}, which likewise receives its rule class list
 * rather than discovering it. The capability namespaces may not be depended on
 * from `Core`, and rule metadata must be read by reflection at container build
 * time, so compile-time assembly is the only place such a map can be built.
 *
 * The computed-metric half is resolved from the injected definition catalog on
 * **every** lookup. Its vocabulary is open-ended — users define computed
 * metrics in `qmx.yaml`, at arbitrary depth, under two prefixes — so no
 * compile-time map could enumerate it, and both facts a consumer needs (the
 * magnitude direction from the definition's `inverted` flag, and the mere
 * existence of the name) are known only once configuration has resolved.
 *
 * That single resolution rule is what makes this one object rather than two.
 * The declaration registry it replaces read a live catalog; the producer
 * registry it replaces read definitions frozen when a snapshot was taken. Both
 * now read the injected catalog the same way, and {@see snapshot()} produces
 * another instance of this same class over an immutable catalog for preflight
 * validation — which must not commit anything to a store before every owner
 * has accepted its value.
 */
final readonly class ChannelUniverse implements ChannelUniverseInterface, RuleChannelSnapshotFactoryInterface
{
    /** @var array<string, string> finding code => producing rule name */
    private array $staticProducerByCode;

    /**
     * @param array<string, ChannelDeclaration> $staticDeclarations keyed by channel name
     * @param array<string, list<string>> $staticChannelKeysByProducer producing rule name => channel keys
     * @param array<string, bool> $thresholdOverrideSupportByRule every registered rule name => its
     *                                                            declared answer, so that the key set doubles
     *                                                            as the set of addressable rule names
     */
    public function __construct(
        private array $staticDeclarations,
        private array $staticChannelKeysByProducer,
        private array $thresholdOverrideSupportByRule,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
        private MetricReachCatalogInterface $metricReachCatalog,
        private ComputedMetricReachInterface $computedMetricReach,
    ) {
        $producerByCode = [];

        foreach ($staticChannelKeysByProducer as $producerRuleName => $channelKeys) {
            foreach ($channelKeys as $channelKey) {
                $producerByCode[$channelKey] = $producerRuleName;
            }
        }

        $this->staticProducerByCode = $producerByCode;
    }

    public function declarationFor(FindingChannel $channel): ?ChannelDeclaration
    {
        $static = $this->staticDeclarations[$channel->code] ?? null;

        if ($static !== null) {
            return $static;
        }

        $definition = $this->definitionCatalog->find($channel->code);

        if ($definition === null) {
            return null;
        }

        return ComputedMetricChannelFamily::declarationFor($definition->name, $definition->reportingLevels(), $definition->inverted);
    }

    public function reachAt(FindingChannel $channel, SymbolLevel $level): ValueReach
    {
        $declaration = $this->declarationFor($channel)
            ?? throw new LogicException(\sprintf('Unknown finding channel "%s".', $channel->code));
        if (!\in_array($level, $declaration->levels, true)) {
            throw new LogicException(\sprintf('Channel "%s" does not report at level "%s".', $channel->code, $level->value));
        }

        if ($declaration->readsRunEvidence) {
            return ValueReach::Run;
        }

        if ($declaration->judges !== null) {
            $reach = ValueReach::Members;
            foreach ($declaration->judges->keys as $key) {
                if ($this->metricReachCatalog->metricReach($key) === MetricReach::Run) {
                    $reach = ValueReach::Run;
                }
            }

            return $reach;
        }

        if (!isset($this->staticDeclarations[$channel->code])) {
            return $this->computedMetricReach->reachAt($channel->code, $level, $this->definitionCatalog) === MetricReach::Run
                ? ValueReach::Run
                : ValueReach::Members;
        }

        return ValueReach::Members;
    }

    public function staticDeclarations(): array
    {
        return $this->staticDeclarations;
    }

    public function channelsProducedBy(string $producerRuleName): array
    {
        $channels = array_map(
            static fn(string $code): FindingChannel => new FindingChannel($code),
            $this->staticChannelKeysByProducer[$producerRuleName] ?? [],
        );

        foreach ($this->definitionCatalog->all() as $definition) {
            if ($definition->producerRuleName() === $producerRuleName) {
                $channels[] = new FindingChannel($definition->name);
            }
        }

        return $channels;
    }

    public function ruleNames(): array
    {
        return array_keys($this->thresholdOverrideSupportByRule);
    }

    public function hasRule(string $ruleName): bool
    {
        return isset($this->thresholdOverrideSupportByRule[$ruleName]);
    }

    public function channels(): array
    {
        $channels = [];

        foreach ($this->staticChannelKeysByProducer as $channelKeys) {
            foreach ($channelKeys as $channelKey) {
                $channels[] = new FindingChannel($channelKey);
            }
        }

        foreach ($this->definitionCatalog->all() as $definition) {
            $channels[] = new FindingChannel($definition->name);
        }

        return $channels;
    }

    public function hasChannel(string $code): bool
    {
        return $this->producerOf($code) !== null;
    }

    /**
     * The registry edge from a channel to the rule that produces it — what used
     * to be the left half of the channel key.
     *
     * Fail-closed on a name both halves claim. The static half used to win in
     * silence, which made a collision look like a working configuration: the
     * channel resolved, the runtime definition it was written for did not, and
     * nothing said so. A run whose vocabulary is ambiguous stops instead.
     */
    public function producerOf(string $code): ?string
    {
        $static = $this->staticProducerByCode[$code] ?? null;
        $runtime = $this->definitionCatalog->find($code)?->producerRuleName();

        if ($static !== null && $runtime !== null) {
            throw new LogicException(\sprintf(
                'The name "%s" is claimed by a statically declared channel of rule "%s" and by a computed metric'
                . ' definition. A channel is identified by its name alone, so one name cannot address two'
                . ' channels — %s should have refused this configuration.',
                $code,
                $static,
                self::class . '::snapshot()',
            ));
        }

        return $static ?? $runtime;
    }

    public function levelsOf(string $code): array
    {
        $declaration = $this->declarationFor(new FindingChannel($code));

        return $declaration === null ? [] : $declaration->levels;
    }

    public function supportsThresholdOverride(string $ruleName): bool
    {
        return $this->thresholdOverrideSupportByRule[$ruleName] ?? false;
    }

    public function expand(NameSelector $selector): array
    {
        return array_values(array_filter(
            $this->channels(),
            static fn(FindingChannel $channel): bool => $selector->matches($channel->code),
        ));
    }

    /**
     * Widened to the catalog interface on purpose. The contract promises a
     * caller that it may hand over one immutable resolved value; this class
     * only ever reads a catalog, and saying so keeps the concrete
     * definition type — which belongs to another capability — out of the
     * adapter that has no use for it.
     */
    public function snapshot(ComputedMetricDefinitionCatalogInterface $definitions): ChannelUniverseInterface
    {
        $this->assertRuntimeNamesUnclaimed($definitions);

        return new self(
            $this->staticDeclarations,
            $this->staticChannelKeysByProducer,
            $this->thresholdOverrideSupportByRule,
            $definitions,
            $this->metricReachCatalog,
            $this->computedMetricReach,
        );
    }

    /**
     * Refuses a computed metric whose name the static half already owns.
     *
     * The two halves of the universe share **one** name space: a channel is
     * addressed by its own name, a producer by its own, and selection reads both
     * vocabularies with the same selector. So a computed metric named after a
     * declared channel, or after a registered rule, makes an authored name mean
     * two things — and the name of a computed metric comes from the user's
     * `qmx.yaml`, which is why this is a configuration refusal and not an
     * assertion about our own code.
     *
     * Reachable today, not hypothetically: a `computed_metrics:` key spelled
     * like a registered rule passes the resolver, which only checks the
     * `computed.` prefix.
     *
     * **The producer half is the definition's own answer, never a list of
     * exceptions.** Since the family split, the six built-in dimensions are
     * themselves keys of `$thresholdOverrideSupportByRule`, and the catalog
     * hands the same six names back on every run — a membership test alone
     * would refuse every `bin/qmx check` before it read a file. A definition
     * that names its own producer is the producer of its own channel, which is
     * the one case where two claims are one thing.
     */
    private function assertRuntimeNamesUnclaimed(ComputedMetricDefinitionCatalogInterface $definitions): void
    {
        foreach ($definitions->all() as $definition) {
            $name = $definition->name;
            $claim = match (true) {
                isset($this->staticProducerByCode[$name]) => \sprintf(
                    'a channel declared by rule "%s"',
                    $this->staticProducerByCode[$name],
                ),
                isset($this->thresholdOverrideSupportByRule[$name])
                    && $definition->producerRuleName() !== $name => 'a registered rule',
                default => null,
            };

            if ($claim === null) {
                continue;
            }

            throw new InvalidArgumentException(\sprintf(
                'Computed metric "%s" is named after %s. A channel is identified by its name alone, so the two'
                . ' would be one address for two different things: every selector, suppression and baseline entry'
                . ' naming it would be ambiguous. Rename the computed metric.',
                $name,
                $claim,
            ));
        }
    }
}
