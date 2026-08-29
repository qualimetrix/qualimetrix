# 0032. Computed-Metric Producer Split: One Name per Closed Definition

**Date:** 2026-08-26
**Status:** Accepted

## Context

Every computed metric — the six built-in health dimensions and any
user-defined metric from `computed_metrics:` — published its findings under
one producer, `computed.health`, and that name was `ComputedMetricRule::NAME`.
The single name hid two halves with an important difference: the six health
dimensions are a fixed, closed set (`HealthDimension` enumerates them), while a
user-defined metric's name is whatever the consuming project's `qmx.yaml`
happens to declare.

Two mechanisms in the surrounding system make that difference load-bearing
rather than cosmetic:

1. **A circular validation order.** `KnownRuleNamesAdapter` builds the set of
   known rule names by reflecting over every registered rule class's `NAME`
   constant, at container-build time. `RuleNameValidator` — the configuration
   stage that checks `rules:` section keys, `--disable-rule`, `--only-rule`,
   and `only_rules`/`disabled_rules` — runs *inside* the same configuration
   pipeline that parses `computed_metrics:`, and before it. A user-defined
   metric's name is therefore not yet known when the validator that would have
   to recognize it runs; it cannot be given a producer of its own without
   asking the pipeline to validate against something it has not read yet.
   `ComputedMetricsConfigResolver::resolve()` draws the same line from the
   configuration side: it merges an override onto an existing `health.*` name
   and refuses a brand new one with that prefix.
2. **A name-uniqueness refusal that rules out six classes.**
   `RuleRegistryCompilerPass::validateNoDuplicateNames()` refuses two
   registered rules that declare the same `NAME`. Giving each health
   dimension its own producer therefore cannot mean six services of the
   existing `ComputedMetricRule` class — that is refused outright — and it
   should not mean six new classes either: they would be six copies of one
   rule body, differing only in which dimension they read, which is exactly
   the kind of duplication a rule class exists to avoid.

Both mechanisms point the same way: the closed half can be split by name
because the build knows every name in advance; the open half cannot be split
at all, because its names are a fact about someone else's configuration file.

## Decision

**A producer is split from its rule class where the set of names is closed at
build time, and shared where it is not.** The six built-in health dimensions
become producers of their own — `health.complexity`, `health.cohesion`,
`health.coupling`, `health.typing`, `health.maintainability`,
`health.overall` — each named exactly as its one channel is. Every
user-defined computed metric keeps one shared producer, `computed`, which is
also `ComputedMetricRule::NAME`.

**A producer stops being synonymous with a rule class.** `ComputedMetrics`
gains no new rule classes. Instead,
`ComputedMetricChannelFamily` (`src/Analysis/Evidence/ComputedMetrics/Contract/Finding/`)
declares the seven producer names and every fact the rest of the system used
to read off "the class named by this producer" — remediation minutes, the
documentation page, threshold-override support, channel declarations, CLI
aliases, category — plus `producerFor(string $definitionName): string`, the
one arbiter every consumer asks which producer owns a given definition
(finding emission, the `producerOf()`/`channelsProducedBy()` registry
lookups, and the run-time name-collision guard). `ChannelDeclarationCompilerPass`
reads this declaration for the six health names exactly where it already
reflects over rule classes for everyone else, so the container's view of "all
producers" is complete without a second, class-shaped path.

`ComputedMetricRule::analyze()` asks a new `ComputedMetricProducerOptions`
object — one options instance per producer, keyed by
`producerFor($definition->name)` — instead of one shared `isEnabled()` call
before its loop. A single shared flag would have made
`rules: { health.cohesion: { enabled: false } }` switch off either every
computed metric or none of them; asking per-definition is the only way seven
producers sharing one class can each answer their own `enabled`.

### Alternatives considered

- **Keep one producer, `computed.health`, for everything.** Rejected: this is
  the status quo the split replaces. It could not represent "disable
  `health.cohesion` without disabling every other computed metric" through
  `rules:`, `--disable-rule`, or `exclude_namespace_channels`, because all of
  those key on the producer name and there was only one.
- **Six new rule classes, one per health dimension.** Rejected by the
  name-uniqueness argument above being moot — six classes would not collide on
  `NAME` — but they would be six copies of one class body reading one field of
  `HealthDimension`, which is the duplication a shared class exists to avoid.
- **Give every user-defined metric its own producer too, inferred from its
  channel name.** Rejected for the circularity reason: the name is not known
  until after the validator that would have to accept it has already run.

## Consequences

- `RuleCategory` gains `Health` and `Computed` cases. Deriving a category from
  a name's first segment would otherwise put seven producers under whichever
  category `computed.health` used to borrow (`Maintainability`), which named
  none of them.
- `allRules()` becomes producer-oriented, and `totalRuleCount()` and
  `activeRules()` follow so the registry does not answer two different
  questions under one contract. `bin/qmx rules` lists 51 producers instead of
  45; this is independent of any `qmx.yaml` a project supplies, since the six
  health names and the one open name are all known at build time.
- Three facts on `ComputedMetricChannelFamily`
  (`SUPPORTS_THRESHOLD_OVERRIDE`, `CHANNEL_DECLARATIONS`, `CLI_ALIASES`) are
  declared explicitly rather than left absent, because their readers default
  silently rather than throwing when a producer has no class to reflect over.
  A silently-defaulted fact on a class-backed producer fails loudly elsewhere;
  the same omission on a producer with no class would instead quietly
  impoverish behavior (an accepted-but-inert `@qmx-threshold`, a missing
  channel, a missing CLI shortcut) with nothing to catch it.
- Three call sites that used to throw on an unrecognized rule name
  (`ChannelPresentationView::presentationFor()`, `RemediationTimeRegistry::getBaseMinutes()`,
  the run-time name-collision guard in `ChannelUniverse`) are made complete by
  construction against all seven names, rather than patched with a fallback —
  a `LogicException` on an undocumented producer is the behavior these guard,
  not a defect to soften.
- Exclusions and rule selection are now keyed by a finding's *producer*
  (`producerOf($finding->channel()->code)`) rather than by
  `$rule->getName()`. For every static rule and every configuration validator
  this is the same value it always was; the split only introduces daylight
  between the two on the computed-metric family.
- Baseline entries are unaffected: a baseline stores the channel's identity,
  not its producer, so no regeneration follows from this change by itself.
- `rules: { health.cohesion: { enabled: false } }` (stop publishing findings)
  and `computed_metrics: { health.cohesion: { enabled: false } }` (remove the
  dimension, renormalizing `health.overall`'s weights) now read almost
  identically while doing different things, and a dimension removed the
  second way leaves its producer with zero channels — an
  `exclude_namespace_channels` key that used to address it is then refused.
  Both are documented at `website/docs/reference/health-scores.md`
  ("Disabling a Dimension") rather than left to be discovered from the
  behavior alone.
