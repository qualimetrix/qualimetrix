# 0031. Channel Shape Is a Producer Property

**Date:** 2026-08-24
**Status:** Accepted

## Context

`ChannelDeclaration` carried a `ChannelShape` (`magnitude` | `occurrence`)
alongside `direction`, `levels` and `configurationError`. The enumeration this
plan's Ш4c step drew from
(`docs/internal/plans/rule-vocabulary/enumeration-channel-shapes.tsv`) found
exactly one producer with a mixed shape across its channels —
`architecture.layer-violation` — and that mix was already resolved by ADR 0030:
the two judgements it named (`architecture.layer-violation`,
`architecture.unassigned-class`) now sit on separate rule classes, each with a
uniform shape.

An earlier draft of this ADR proposed moving `WorseDirection` to the producer
alongside shape, on the argument that no producer's channels disagreed on
direction either. That argument does not survive `computed.health`:
`ComputedMetricRule` is one class, one producer name, and its per-channel
direction is resolved at run time from each `ComputedMetricDefinition`'s own
`inverted` flag — the six built-in health dimensions declare `inverted: true`,
and a user-defined metric in the finding-gate corpus
(`finding-gate/cases/health/qmx.yaml`) declares `inverted: false`. A method on
the producer cannot answer for both. Shape, by contrast, is uniform for
`computed.health` — every dimension it emits is a real measured value — so only
shape moves.

## Decision

**`ChannelShape` is a property of the producer, not of the channel.**
`ChannelDeclaration` keeps `direction` (nullable), `configurationError` and
`levels`; it drops `shape` entirely. A magnitude channel is simply one built
through `ChannelDeclaration::magnitude()` (non-nullable direction, by the
factory's own signature); an occurrence channel is one built through
`ChannelDeclaration::occurrence()` (no direction parameter at all). Nothing
downstream needs a separate enum to tell the two apart — the field that used
to disagree with it is the field that is left.

A rule declares its shape through a new required interface method,
`RuleInterface::shape(): ChannelShape`, called by a plain static call at
container-build time — the same idiom `RuleDefinitionInterface::getOptionsClass()`
already establishes for a fact every producer must answer, as opposed to
`channelDeclarations()`'s optional-method idiom for a fact most rules do not
have. A validator answers the same question through
`ConfigurationValidatorInterface::shape()`, because a validator borrows its
producer rule's name (ADR 0030's `LayerDeclarationValidator` /
`InlineDirectiveValidator` pattern) and is, for every purpose that name serves,
one producer with the rule it belongs to.

**Two invariants move to registry assembly**, because neither is
representable as a type-level constraint on a single class in isolation:

1. A producer's declared shape must agree with whether its own channels carry
   a direction — `magnitude` channels all have one, `occurrence` channels
   none. `ChannelDeclarationCompilerPass` checks this per channel as it
   collects a producer's declarations.
2. A validator and the rule whose name it borrows must declare the same
   shape — two classes, one producer identity, one answer. The pass checks
   this once per validator, before inspecting either side's channels.

Both are `LogicException`s raised at container-build time: a mismatched
producer never reaches a running analysis.

## Consequences

**No observable surface changes.** Shape was never published — not in any
formatter, not in the baseline file format, not in `bin/qmx rules`. The
finding-gate proves this the same way it proved Ш2 and Ш3: GREEN against the
prior step's commit with every map empty and zero declared deltas.

**Cost, paid once per producer.** Every one of the 45 rules and both
configuration validators now declares `shape()` — most through a shared
default on an abstract base (`AbstractCodeSmellRule`, `AbstractSecurityPatternRule`,
`AbstractTypeCoverageRule`), the rest on their own class, matching whichever
channel-building factory their own `channelDeclarations()` calls. The tracked
fixture `tests/Analysis/Finding/Fixtures/Channels/declared.txt` changes on
every one of its 57 lines — the shape token that used to prefix each line
(`magnitude:higher`, `occurrence`) is now a bare direction (`higher`, `lower`,
`-`) — which is the fixture's own record of the property leaving the channel.
The set of 57 channel keys and the set of 52 producer names are unchanged; the
line format is what moved.

**A new guard against the two invariants regressing.** Two negative-control
tests in `ChannelDeclarationCompilerPassTest` build a fixture producer whose
declared shape disagrees with its own channel's direction, and a fixture rule
paired with a fixture validator that disagree with each other, and assert
container assembly refuses both. A topology test,
`ChannelShapeNotDeclaredByChannelTopologyTest`, asserts that no method calling
`ChannelDeclaration::magnitude()` / `::occurrence()` also references
`ChannelShape` in its own body — the same "compare outcomes, not literals"
lesson `ChannelLevelAssemblyTopologyTest` already applies to level suffixes,
carried over to shape.

**Two independent facts kept independent.** `AcceptedLevel::shape()` and
`BaselineEntry::shape()` are unaffected: both already derived a `ChannelShape`
from their own stored data (`$magnitudes === null`) rather than asking a
producer, which is exactly the asymmetry that lets a stored baseline entry's
shape be compared against a live channel's shape to detect drift
(`BaselineUpdateRefusalReason::ShapeMismatch`, `InertEntryReason::ShapeMismatch`).
Replacing that self-derivation with a registry lookup would make the detector
compare a producer's declaration against itself.
