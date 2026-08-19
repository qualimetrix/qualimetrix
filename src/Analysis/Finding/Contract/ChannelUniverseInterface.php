<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;

/**
 * The whole channel universe of one resolved configuration, in one instance.
 *
 * There used to be two registries — one answering "what does this channel
 * declare for baseline purposes", the other "what channels does this producer
 * emit" — assembled by the same compiler pass from the same rule classes and
 * disagreeing only in how each reached the run-time computed-metric family.
 * Two objects meant two answers were possible to the same question, and no
 * third question (a reverse lookup, a declared rule property, the expansion of
 * a group selector) had anywhere to live at all.
 *
 * There is now one instance and three narrow views onto it, each named after
 * what its consumers need:
 *
 * - {@see ChannelDeclarationRegistryInterface} — the baseline ceiling and the
 *   finding projection: what a channel declares;
 * - {@see RuleChannelRegistryInterface} — rule selection: what a producer
 *   emits;
 * - {@see ChannelIdentityInterface} — directive validation and diagnostics:
 *   which names exist, what they belong to, what they expand to.
 *
 * Consumers depend on a view, never on this composite: the composite exists so
 * that one object can answer everything, not so that everyone can ask
 * everything.
 *
 * **Lifecycle.** The universe resolves its computed-metric half from the
 * injected definition catalog on every lookup — one rule for both halves,
 * where the declaration half used to read a live catalog and the producer half
 * a value frozen at snapshot time. Preflight validation, which must not mutate
 * any store before every owner has accepted its value, builds a second
 * instance of the same class over the immutable definitions it is validating;
 * that is the same lifecycle applied to a not-yet-committed catalog, not a
 * second lifecycle.
 *
 * The universe is assembled **after configuration is resolved**, because the
 * computed-metric names come from configuration. Statically declared channels
 * and configured computed metrics are two contributors to one name space, not
 * two name spaces: nothing records where a name came from, and nothing may
 * behave differently because of it.
 */
interface ChannelUniverseInterface extends
    ChannelDeclarationRegistryInterface,
    RuleChannelRegistryInterface,
    ChannelIdentityInterface {}
