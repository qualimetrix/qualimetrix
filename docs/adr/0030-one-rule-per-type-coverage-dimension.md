# 0030. One Rule per Judgement: Type-Coverage Dimensions and the Unassigned-Class Gate

**Date:** 2026-08-24
**Status:** Accepted

## Context

Two producers carried more than one judgement, and in both cases the extra
judgement was addressable in reports but not addressable in configuration.

`design.type-coverage` published three channels — `.param`, `.return`,
`.property` — from one rule with one options object. A report named the
dimension; every knob named the rule. Tuning the parameter threshold meant
writing `param_warning` on a rule whose name said nothing about parameters,
`@qmx-threshold design.type-coverage 90 70` retuned all three dimensions at
once whether or not that was meant, and a baseline entry for one dimension sat
under a key that spelled the aspect in the channel code only. The dimension was
an aspect inside a name, which is the shape ADR 0024 already rejected for
levels.

`architecture.unassigned-class` was a second channel of `LayerViolationRule`,
gated by that rule's `unassigned_class` option. It answers a question about the
run — how much of the analysed code no layer claims — while the rule around it
answers questions about individual dependency edges. Its gate was reachable
only through the other rule's name.

## Decision

**One rule per judgement.**

`design.type-coverage` becomes `design.param-type-coverage`,
`design.return-type-coverage` and `design.property-type-coverage`: three rules,
one channel each, each with its own `warning`/`error`/`threshold`, its own CLI
aliases, its own `@qmx-threshold` target and its own baseline entry. Names read
as noun phrases and none is a textual prefix of another, so a rename map cannot
match one where another was meant.

`architecture.unassigned-class` becomes `UnassignedClassRule` with
`UnassignedClassOptions`. Its single option is `mode` (`ignore|warn|error`);
`isEnabled()` is derived from it, because a `mode` and an `enabled` would be two
switches for one decision and the one that is off by default would silently win.

`InlineDirectiveRule` is renamed `UnusedDirectiveRule` — the class now says
which of the four `annotation.*` channels it owns. No published name changes.

### Why one shared Options class for three rules

`TypeCoverageOptions` is used by all three type-coverage rules. Nothing requires
a bijection between rule and options class: configuration is keyed by producer
rule name everywhere it is read —
`RuleOptionsCompilerPass::optionsServiceId($ruleName, $optionsClass)` (whose
docblock states the reuse explicitly), `RuleOptionsFactory::create()`,
`RuleThresholdKeyGroupRegistry::GROUPS`, `RuleValidatorMapFactory::build()`,
`BaselineConfiguredThresholds` and `CheckCommandDefinition::detectBooleanAliases`.
Three identical thin classes would have been three places for one shape to
drift.

### Why the walk is still shared, and what it may not decide

`LayerEvidenceCollector` remains one traversal per run, memoised weakly by the
run's `AnalysisContext`. It takes both gates — the layer rule's `enabled` and
the unassigned rule's `mode` — runs when **either** wants evidence, and
materialises the outside-every-layer set when either of them, or the
architecture section's `coverage`, has a use for it. Splitting the rule did not
split the traversal, which is the property that made the split affordable.

What the collector may *not* decide is whether a consumer reports. That
distinction is the one this ADR got wrong on its first attempt: the walk's entry
condition read the layer rule's `enabled` alone, so
`architecture.layer-violation: {enabled: false}` with
`architecture.unassigned-class: {mode: error}` produced no finding and no
diagnostic — a producer of its own, silenced through a sibling's options, which
is exactly the coupling the split exists to remove. Three reviewers found it
independently and it is the only finding all three shared. `collect()` now
answers "is there evidence"; the rule, the validator and the unassigned-class
rule each answer "may I report" from their own gate. The five declaration
verdicts answer to `architecture.layer-violation` because they are verdicts on
its declaration, which is why `LayerDeclarationValidator` took the producer's
options as a second constructor argument.

### Why the run asks about a list of producers

`RuleProducerPreparation::prepareArchitecture()` used to prepare the layer
policy when the producer `architecture.layer-violation` was selected. While the
unassigned-class channel belonged to that rule, a selector naming the channel
matched its producer and the question was complete. As a producer of its own it
is not matched, and `--only-rule=architecture.unassigned-class` reached
`LayerEvidenceCollector` with an unprepared policy — a `LogicException`, not a
silent miss. The capability now publishes
`LayerPolicyPreparationInterface::PRODUCER_RULE_NAMES`, and the run prepares
when any of them is selected. A third rule reading the prepared policy is added
to that list and the run needs no change.

The cost of that shape is named rather than hidden: with the layer rule disabled
and the unassigned gate left at its default `ignore`, the policy is still
prepared and the preparation is wasted. Preparation is selection-driven and
selection cannot see a capability's own option value; buying the missing skip
would mean teaching the run to read `mode`, which is a worse trade than one
`ClassSet` build.

## Consequences

**Breaking, and mechanically migratable:**

- `rules: design.type-coverage: { param_warning, param_error, param_threshold,
  return_*, property_* }` becomes three sections keyed
  `design.param-type-coverage`, `design.return-type-coverage`,
  `design.property-type-coverage`, each with bare `warning`, `error`,
  `threshold`. The camelCase spellings (`paramWarning`, …) are gone with them.
- The six flags `--type-coverage-{param,return,property}-{warning,error}` become
  `--{param,return,property}-type-coverage-{warning,error}`, per the
  `{rule-short-name}-{option}` convention in `docs/internal/CLI_CONVENTIONS.md`.
- `--layer-violation-unassigned-class=MODE` becomes
  `--unassigned-class-mode=MODE`; the config key
  `architecture.layer-violation: { unassigned_class: … }` becomes
  `architecture.unassigned-class: { mode: … }`.
- `@qmx-threshold design.type-coverage W E` no longer retunes three dimensions
  with one directive: it names a rule that no longer exists, and each dimension
  is retuned on its own.
- `--disable-rule=design.type-coverage` matches nothing. `--disable-rule` and
  `only_rules` now address the three names; `design.*` still spans them.
- Baseline entries carrying `design.type-coverage#design.type-coverage.param`
  and its siblings are rewritten to `design.param-type-coverage#design.param-type-coverage`
  and siblings.
- `bin/qmx rules` reports 45 rules rather than 42. The channel count is
  unchanged at 57: the split moved a channel's owner, not its existence.

**Observable and deliberate:**

- `architecture.unassigned-class` moves from position 45 to position 50 of the
  published channel order. `ChannelUniverse::channels()` yields channels grouped
  by producer; the five declaration verdicts stay in the layer-violation
  producer's group and the new rule forms its own after it. That order is
  published — a "did you mean" answer breaks ties between equidistant names by
  it — so the move is recorded in
  `tests/Analysis/Finding/Fixtures/Channels/order.txt` rather than engineered
  away by shuffling groups, which would have made registration order
  unobservable in a different way. Whether any input can reach a tie involving
  this channel is measured, not assumed: see
  `ChannelSuggestionTieTest`.
- The published order of the three type-coverage channels is a decision, not a
  consequence of the file system: `DesignConfigurator` names every Design rule
  explicitly, in the order `param, return, property`. The `*Collector.php` glob
  stays, because collectors declare no channels.
- One rule description becomes three, and the SARIF descriptor of each new rule
  carries its own text. `architecture.unassigned-class` gets a descriptor
  description of its own for the first time.

The equivalence of everything else is proved by measurement rather than
asserted: `composer gate -- --reference=<the commit before the split>` compares
findings, eleven formats, exit codes, `qmx rules`, `baseline:explain`, the
generated baseline and the suppressed report, under three declared channel-map
rows, fourteen declared input-map rows and seven declared deltas.
