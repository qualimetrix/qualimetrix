<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

/**
 * Answers "what is the declaration for this channel?" — the single lookup
 * the baseline ceiling needs to decide whether a channel is baselineable at
 * all and, if so, how its magnitude compares.
 *
 * Two sources feed one answer:
 *
 * - the **static** per-rule declarations, assembled at compile time by
 *   {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}
 *   from every tagged rule's optional `channelDeclarations()` method;
 * - the **run-time** `computed.*` / `health.*` family, resolved from the
 *   configured {@see \Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition}
 *   on every lookup, because that vocabulary is open-ended (user-defined
 *   computed metrics) and no static map can enumerate it.
 *
 * A channel absent from both is not an error: {@see declarationFor()}
 * returns `null`, and the caller treats the channel as not baselineable.
 *
 * The interface lives in `Core\Violation`, beside {@see ChannelDeclaration},
 * {@see ChannelShape} and {@see ViolationChannel} — the types it traffics in
 * — rather than beside its implementation in `Infrastructure\Rule`. Any
 * consumer that may not depend on `Infrastructure` (e.g. `Baseline`, which
 * `qmx.yaml` permits to depend only on `Core`) still needs this lookup;
 * mirrors {@see \Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface}
 * (contract) / {@see \Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter}
 * (adapter) — the same split for the same reason. The concrete
 * {@see \Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistry} stays in
 * `Infrastructure\Rule`, unchanged.
 */
interface ChannelDeclarationRegistryInterface
{
    /**
     * Returns the declaration for a channel, or `null` when the channel is
     * not baselineable (no rule declared it, and it is not a resolvable
     * `computed.*` / `health.*` definition).
     */
    public function declarationFor(ViolationChannel $channel): ?ChannelDeclaration;

    /**
     * The statically declared set only — excludes the run-time
     * `computed.*` / `health.*` family by construction, since that family
     * has no fixed set to enumerate.
     *
     * Exists for the drift guard: the tracked fixture under
     * `tests/Fixtures/Channels/` is compared against exactly this map, never
     * against {@see declarationFor()}'s run-time-widened answer.
     *
     * @return array<string, ChannelDeclaration> keyed by {@see ViolationChannel::toKey()}
     */
    public function staticDeclarations(): array;
}
