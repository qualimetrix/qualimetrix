# Infrastructure Rule

This subject adapts rule metadata and registries for infrastructure composition.
Executable rule behaviour remains owned by Analysis Finding and evidence
capabilities.

## The channel universe

`ChannelUniverse` is the single instance behind every view of the channel name
space. The compiler pass hands it three static facts per rule — the channels it
declares, the producer of each, and its declared `supportsThresholdOverride`
answer — and it resolves the open-ended `computed.*` / `health.*` family from
the injected definition catalog on every lookup.

That one resolution rule replaced two: a declaration registry that read the live
catalog and a producer registry frozen over the definitions handed to it. The
live reading wins because it is the one with consumers that have no other
source — the baseline ceiling learns a computed channel's direction nowhere
else. A directive naming a computed metric therefore resolves against the
configuration that was actually committed for the run.

Consumers depend on a narrow view, never on the composite:

| View                                  | Consumers                            | Answers                                                       |
| ------------------------------------- | ------------------------------------ | ------------------------------------------------------------- |
| `ChannelDeclarationRegistryInterface` | baseline ceiling, finding projection | what a channel declares                                       |
| `RuleChannelRegistryInterface`        | rule selection                       | what a producer emits                                         |
| `ChannelIdentityInterface`            | directive validation, diagnostics    | which names exist, what they belong to, what `X.*` expands to |

`RuleChannelSnapshotFactoryInterface` builds a second universe of the same class
over an explicit immutable definition set. It exists for preflight: CLI selector
validation must know the universe of the configuration it is validating, and it
runs before any store has accepted a value. That is the same lifecycle applied
to a not-yet-committed catalog, not a second lifecycle.

## Computed-metric description override

`ComputedMetricChannelPresentation` decorates Finding's
`ChannelPresentationView`: for a configured `computed.*`/`health.*` channel it
prefers `ComputedMetricDefinition::$description` over the producer rule's
generic one. It lives here rather than in `Analysis\Finding` because
`ComputedMetricDefinition` is owned by `Analysis\Evidence\ComputedMetrics`,
which itself depends on Finding for `RuleInterface` — importing it from
Finding would close a dependency cycle. Infrastructure already depends on both
capabilities (see `ChannelUniverse` above), so the override is composed here
and the public `ChannelPresentationInterface` alias resolves to this decorator.

## Structure

```text
Rule/
├── ChannelUniverse.php                       # the one channel-identity instance
├── ComputedMetricChannelPresentation.php     # layers the computed-metric description override
├── Contract/
│   └── RuleChannelSnapshotFactoryInterface.php
├── KnownRuleNamesAdapter.php
├── RuleRegistry.php
└── RuleRegistryInterface.php
```
