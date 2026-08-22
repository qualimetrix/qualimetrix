# 0029. Channel Presentation as a Run-Time Join, Not a Fourth Universe View

**Date:** 2026-08-22
**Status:** Accepted

## Context

`SarifRuleCollector` used to describe SARIF rule descriptors from two private
tables: a `match` over violation codes duplicating `RuleInterface::getDescription()`,
and a prefix→docs-path map duplicating `RuleInterface::getCategory()`. Both had
already drifted from the product: 9 of 24 `match` arms named rule names, never
violation codes, and matched nothing; 42 of 57 real channels reached no arm and
fell back to a humanised placeholder; `duplication.*` was mapped to
`website/docs/rules/architecture.md`. The unit test that should have caught
this asserted the table against violation codes it invented itself
(`'complexity.cyclomatic'`), so it stayed green while the product emitted none
of them. See `docs/internal/plans/sarif-channel-descriptions.md` for the full
measurement.

The fix requires joining three facts for a violation code: which rule produces
it, that rule's description, and where it is documented. No single existing
object holds all three.

### Why the producing rule comes from `producerOf()`, not from the code's prefix

A violation code's leading segments usually match its producing rule's name
(`complexity.cyclomatic.callable` → `complexity.cyclomatic`), which is exactly
the assumption the old `match` table made and which fails for two families:
`architecture.*` diagnostics (`architecture.coverage`, `architecture.potential-shadow`,
etc.) are all produced by the rule named `architecture.layer-violation`, and
every `annotation.*` diagnostic is produced by `annotation.directive`. Neither
producer name is a prefix of the channel names it emits, so no string
manipulation on the code recovers it. `ChannelIdentityInterface::producerOf()`
is the one place this is already resolved correctly, because the universe
built it from the same rule-to-channel declarations at compile time (and, for
the `computed.*`/`health.*` family, from the live definition catalog — see the
class docblock on `ChannelUniverseInterface`).

### Why this is its own contract instead of a fourth view on `ChannelUniverse`

`ChannelUniverseInterface` already composes three views — declaration,
producer-channel, identity — each backed by facts that exist when the universe
is assembled: compile-time class-string reflection for static channels, and
the live definition catalog for computed metrics. A channel's *presentation*
needs a fourth kind of fact — `getDescription()` and `getCategory()`, which
are instance methods of `RuleInterface` — and rule instances do not exist at
that point. `RuleNameReader` (which the universe's compiler pass already uses
for the `NAME` constant) reads its constant by reflection without
instantiating, specifically because a rule's constructor may depend on
services beyond its `Options`, making off-container instantiation unsafe.
Rule instances exist in exactly one place: the container.

Widening the universe to reach into the container at lookup time would give it
a second lifecycle alongside its two existing ones, for a fact none of its
current consumers (baseline, rule selection, directive validation) need. The
composing service is a small derivation over `ChannelIdentityInterface` and
the container's rule instances — not a second source of truth, because it
reads the same producer identity the universe already resolved rather than
recomputing it.

## Decision

Add `Analysis\Finding\Contract\ChannelPresentationInterface`, with one method,
`presentationFor(string $violationCode): ?ChannelPresentation`, returning the
description and documentation page joined from the code's producing rule.
`Analysis\Finding` owns this contract because it already owns both halves of
the join for the static case: `ChannelIdentityInterface::producerOf()` and
`RuleMetadata`. The implementation is registered beside the other channel
views in `RuleConfigurator`.

Rejected alternative: inject `ChannelIdentityInterface` and
`RuleExecutionInterface` directly into `SarifRuleCollector` and perform the
join there. Both aliases are already public in `RuleConfigurator`, so this
would wire with no new machinery — but every consumer that needs a
description/URL pair would repeat the same two-step lookup, and rule
*execution* would appear on the reporting side of the boundary. The universe's
own docblock already names "a declared rule property" as a question it was
built to let live somewhere; this is that somewhere.

### The computed-metric half is a decorator in `Infrastructure\Rule`, not inside `Finding`

For the `computed.*`/`health.*` family, the preferred description is the
configured `ComputedMetricDefinition::$description`, not the producing rule's
generic one. An earlier draft of this decision put that preference inside
`Analysis\Finding`, reasoning "Finding owns both facts, so it owns the join."
That is wrong: it requires `Finding` to read `Analysis\Evidence\ComputedMetrics`'s
definition catalog, and the dependency edge runs the other way —
`ComputedMetrics` already imports `Finding` (11 edges recorded in
`docs/internal/generated/modular-architecture/production-cross-owner-imports.tsv`:
`AbstractRule`, `Location`, `AnalysisContext`, and others), and there is no
edge back. Building the preference inside `Finding` would either invert that
edge or duplicate the catalog.

The resolution already exists in this codebase for the identical constraint:
`ChannelUniverse` itself lives in `Infrastructure\Rule` because it composes
facts from capabilities that must not depend on each other. The
computed-metric preference is a decorator, `ComputedMetricChannelPresentation`,
also in `Infrastructure\Rule`, aliased to `ChannelPresentationInterface`. It
delegates every lookup to the `Finding`-owned service and overrides only the
description for a channel the definition catalog configures — composition,
not a second join.

Recorded as a method failure, not just a fact: the wrong claim was about the
*direction* of an existing dependency edge, and it went unnoticed through two
rounds of triple review because nobody ran the one grep against the generated
import inventory that would have answered it directly.

## Consequences

- `SarifRuleCollector`'s private description table, category-to-docs map, and
  the `@qmx-threshold` suppression that existed only to hold that table are
  gone; an unknown violation code still gets a humanised fallback and the
  repository URL rather than throwing.
- Every rule declares its documentation page as a reflection-readable class
  constant (`DOCS_PAGE`, read by `RuleDocsPageReader`), the same idiom as
  `NAME`. The two channels whose page is not `rules/{prefix}` —
  `cohesion.lcom` (renamed from `design.lcom`, see ADR 0028, documented at
  `rules/cohesion.md`) and `computed.health` (documented at
  `reference/health-scores.md`, outside `/rules/` entirely) — are why the
  constant holds a full relative path rather than being derived from
  `RuleCategory`, whose own docblock states its scope is display grouping and
  nothing else.
- A guard (`RuleDocsPageCoverageTest`, `ChannelPresentationCoverageTest`,
  `SarifRuleDescriptorCoverageTest`) asserts every channel enumerated by
  `ChannelUniverseInterface::channels()` resolves to a description and a
  documentation page carrying that producing rule's `Rule ID:` anchor — never
  merely "the page exists", which is exactly what the old
  `duplication → architecture/` mis-mapping would have satisfied.
- Any new capability adding a rule must declare `NAME`, `DOCS_PAGE`, and a
  description on the rule class itself; there is no table anywhere else left
  to update.
