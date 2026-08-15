# Infrastructure Rule

This subject adapts rule metadata and registries for infrastructure composition.
Executable rule behaviour remains owned by Analysis Finding and evidence
capabilities.

## Snapshot assembly

`RuleChannelRegistry` assembles static producer-to-channel keys from the
compiler pass and implements the sole public
`RuleChannelSnapshotFactoryInterface`. For each configuration run, its
`snapshot(ResolvedComputedMetricDefinitions $definitions)` method returns a new
immutable `RuleChannelRegistryInterface` view. Computed-metric channel names are
derived only from that supplied immutable definition value.

The factory accepts one resolved value and returns one run snapshot.
`RuleInputValidator` asks it for that snapshot while validating selectors;
Finding receives the resulting registry through its own contract. No previously
resolved definition set is retained or published for another run.

## Structure

```text
Rule/
├── ChannelDeclarationRegistry.php   # compiler-pass static channel assembly
├── Contract/
│   └── RuleChannelSnapshotFactoryInterface.php
├── KnownRuleNamesAdapter.php
├── RuleChannelRegistry.php          # immutable per-run channel view
├── RuleRegistry.php
└── RuleRegistryInterface.php
```
