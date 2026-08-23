<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevelProjection;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
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
    /** @var array<string, string> violation code => producing rule name */
    private array $staticProducerByViolationCode;

    /**
     * @param array<string, ChannelDeclaration> $staticDeclarations keyed by {@see ViolationChannel::toKey()}
     * @param array<string, list<string>> $staticChannelKeysByProducer producing rule name => channel keys
     * @param array<string, bool> $thresholdOverrideSupportByRule every registered rule name => its
     *                                                            declared answer, so that the key set doubles
     *                                                            as the set of addressable rule names
     * @param string $computedMetricRuleName the family discriminator for the run-time half — every
     *                                       `computed.*` / `health.*` channel is emitted under this one rule
     *                                       name. Injected rather than imported so this class needs no
     *                                       dependency edge onto a capability internal just to know one string
     */
    public function __construct(
        private array $staticDeclarations,
        private array $staticChannelKeysByProducer,
        private array $thresholdOverrideSupportByRule,
        private string $computedMetricRuleName,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {
        $producerByCode = [];

        foreach ($staticChannelKeysByProducer as $producerRuleName => $channelKeys) {
            foreach ($channelKeys as $channelKey) {
                $producerByCode[ViolationChannel::fromKey($channelKey)->violationCode] = $producerRuleName;
            }
        }

        $this->staticProducerByViolationCode = $producerByCode;
    }

    public function declarationFor(ViolationChannel $channel): ?ChannelDeclaration
    {
        if ($channel->ruleName === $this->computedMetricRuleName) {
            $definition = $this->definitionCatalog->find($channel->violationCode);

            if ($definition === null) {
                return null;
            }

            // A computed metric declares the declaration kinds it is computed
            // over; the levels it reports at are those kinds projected onto
            // the aggregation tree. Six health dimensions report at three
            // levels under one name, which is why no static map could hold
            // this half of the universe.
            $levels = array_map(SymbolLevelProjection::ofDeclaration(...), $definition->levels);

            if ($levels === []) {
                // `levels: []` is accepted by the resolver and makes the
                // metric emit nothing at all, so there is no channel to
                // declare — the same answer an unknown name gets.
                return null;
            }

            return ChannelDeclaration::magnitude(
                $definition->inverted ? WorseDirection::Lower : WorseDirection::Higher,
                ...$levels,
            );
        }

        return $this->staticDeclarations[$channel->toKey()] ?? null;
    }

    public function staticDeclarations(): array
    {
        return $this->staticDeclarations;
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

        foreach ($this->definitionCatalog->all() as $definition) {
            $channels[] = new ViolationChannel($producerRuleName, $definition->name);
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
                $channels[] = ViolationChannel::fromKey($channelKey);
            }
        }

        foreach ($this->definitionCatalog->all() as $definition) {
            $channels[] = new ViolationChannel($this->computedMetricRuleName, $definition->name);
        }

        return $channels;
    }

    public function hasChannel(string $violationCode): bool
    {
        return $this->producerOf($violationCode) !== null;
    }

    public function producerOf(string $violationCode): ?string
    {
        if (isset($this->staticProducerByViolationCode[$violationCode])) {
            return $this->staticProducerByViolationCode[$violationCode];
        }

        return $this->definitionCatalog->find($violationCode) === null
            ? null
            : $this->computedMetricRuleName;
    }

    public function supportsThresholdOverride(string $ruleName): bool
    {
        return $this->thresholdOverrideSupportByRule[$ruleName] ?? false;
    }

    public function expand(NameSelector $selector): array
    {
        return array_values(array_filter(
            $this->channels(),
            static fn(ViolationChannel $channel): bool => $selector->matches($channel->violationCode),
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
        return new self(
            $this->staticDeclarations,
            $this->staticChannelKeysByProducer,
            $this->thresholdOverrideSupportByRule,
            $this->computedMetricRuleName,
            $definitions,
        );
    }
}
