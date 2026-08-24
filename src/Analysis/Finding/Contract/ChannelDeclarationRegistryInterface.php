<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * Answers "what is the declaration for this channel?" — the single lookup the
 * baseline ceiling and the finding projection need to decide whether a channel
 * is baselineable at all and, if so, how its magnitude compares.
 *
 * This is one **view** onto {@see ChannelUniverseInterface}, not a registry of
 * its own: the same instance also answers what a producer emits and what a
 * name belongs to. The view exists so that a consumer of declarations does not
 * acquire the ability to ask about producers or expand selectors — narrow by
 * consumer, single by instance.
 *
 * Two contributors feed one answer:
 *
 * - the **static** per-rule declarations, assembled at container build time by
 *   {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}
 *   from every tagged rule's optional `channelDeclarations()` method;
 * - the **configured** `computed.*` / `health.*` family, resolved from the
 *   {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition}
 *   catalog on every lookup, because that vocabulary is open-ended
 *   (user-defined computed metrics) and no static map can enumerate it.
 *
 * They are two contributors to one name space, not two name spaces: nothing
 * records where a name came from, and nothing behaves differently because
 * of it.
 *
 * A channel absent from both is not an error here: {@see declarationFor()}
 * returns `null`, and the caller treats the channel as not baselineable. That
 * invariant is about a **stored artefact** — a baseline entry whose channel has
 * since disappeared must go inert rather than blow up an old baseline file. It
 * says nothing about an authored directive naming a channel that does not
 * exist, which is a different lifecycle with a different right answer.
 *
 * The interface lives beside {@see ChannelDeclaration}, {@see ChannelShape} and
 * {@see FindingChannel} — the types it traffics in — rather than beside its
 * implementation in `Infrastructure\Rule`. Any consumer that may not depend on
 * `Infrastructure` still needs this lookup; mirrors
 * {@see \Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface}
 * (contract) / {@see \Qualimetrix\Infrastructure\Rule\KnownRuleNamesAdapter}
 * (adapter) — the same split for the same reason.
 */
interface ChannelDeclarationRegistryInterface
{
    /**
     * Returns the declaration for a channel, or `null` when the channel is
     * not baselineable (no rule declared it, and it is not a resolvable
     * `computed.*` / `health.*` definition).
     */
    public function declarationFor(FindingChannel $channel): ?ChannelDeclaration;

    /**
     * The statically declared set only — excludes the run-time
     * `computed.*` / `health.*` family by construction, since that family
     * has no fixed set to enumerate.
     *
     * Exists for the drift guard: the tracked fixture under
     * `tests/Analysis/Finding/Fixtures/Channels/` is compared against exactly
     * this map, never against {@see declarationFor()}'s run-time-widened
     * answer.
     *
     * @return array<string, ChannelDeclaration> keyed by {@see FindingChannel::toKey()}
     */
    public function staticDeclarations(): array;
}
