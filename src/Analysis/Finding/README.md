# Finding

The Finding capability owns analysis-rule vocabulary, rule execution, rule configuration, emitted finding values, channel declarations, and pre-projection filtering primitives.

## Structure

```text
Finding/
├── Contract/             # Published metadata, configuration, finding, channel, and filter contracts
│   ├── Control/          # finding control scope vocabulary
│   ├── Filter/           # Ordered finding-filter stages and results
│   ├── Rule/             # Rule authoring contracts
│   └── Threshold/        # threshold override value
├── Exclusion/            # Private namespace and path exclusion stores
├── Rule/                 # Internal producer and channel implementations
├── RuleConfiguration/    # Option parsing, normalization, and per-run state
└── RuleExecution.php     # Selects producers, executes them, and publishes metadata/stats
```

`RuleExecutionInterface` exposes immutable `RuleMetadata`; concrete rule instances never cross the capability boundary. `RuleConfigurationInterface` is the only external mutation/query surface for per-run options, selection, and exclusions. Runtime reset clears CLI selection and exclusion state before every run.

`ControlScope` and `ThresholdOverride` are Finding-owned vocabulary. Inline
produces them from source annotations, Run transports them, and Finding applies
them while selecting effective rule thresholds.

Infrastructure composes these internals through `FindingConfigurator`. Rule discovery and container construction remain Infrastructure concerns.

Finding owns the channel name space as a contract and not as an implementation.
`ChannelUniverseInterface` composes three narrow views —
`ChannelDeclarationRegistryInterface` (what a channel declares),
`RuleChannelRegistryInterface` (what a producer emits) and
`ChannelIdentityInterface` (which names exist, what they belong to, what `X.*`
expands to) — and Infrastructure supplies the single instance behind them.
Matching stays string comparison in `NameSelector` and never consults the
universe; the universe validates and resolves. `ChannelDeclaration` carries
one further declared property besides shape and direction:
`ChannelAcceptability`. `ConfigurationError` marks a channel whose findings
report a mistake in the configuration rather than debt in the code — it is
refused by every baseline path and fails the run without consulting `fail_on`.
Today the four layer-policy diagnostics carry it. Finding neither resolves computed
definitions nor retains Infrastructure-owned definition state.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
