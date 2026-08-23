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
├── RuleExecution.php     # Selects producers, executes them, and publishes metadata/stats
└── ChannelPresentationView.php # Joins a channel's producer to that rule's own description and docs page
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
Matching stays string comparison in `NameSelector` — and, for the surfaces that
address a whole channel rather than a bare code, in `ChannelSelector`, which adds
the exact `ruleName#violationCode` pair. Neither consults the universe; the
universe validates and resolves. `ChannelDeclaration` carries
two further declared properties besides shape and direction.

`isConfigurationError()`: whether the channel's findings report a mistake in the
configuration rather than debt in the code — such a finding is refused by every
baseline path and fails the run without consulting `fail_on`. **It is not
authored.** `ConfigurationValidatorInterface` is the second kind of finding
producer, and a channel is a configuration error exactly when a validator
declared it. `ChannelDeclaration`'s constructor is private, its two factories
both yield `false`, and `asConfigurationError()` is applied in one place — the
channel-registry assembly in `ChannelDeclarationCompilerPass`, where the
declaring type is still known. `ConfigurationErrorClassificationTopologyTest`
counts that place, and pins that no other production file even names the wither, so
an indirect call cannot hide from the count. Today the five layer-declaration verdicts and the three
inline-directive errors carry it.

A validator is not free-standing: `producerRuleName()` names the rule it belongs
to, and that name is what registers its channels, what `--disable-rule`,
`only_rules`, `exclude_paths` and `exclude_namespaces` address, what resolves its
description, documentation page and remediation estimate, and whose options —
`enabled` included — it answers to. `RuleExecution` runs it in that rule's slot,
so its findings keep their position in every report that does not sort, and
refuses a finding on a channel the validator does not declare.

`levels`: the `SymbolLevel`s the channel reports at, declared in full and never
empty. It governs what the registry accepts. Emission is a separate path — a
rule builds its finding's subject and the level follows from that — so the two
can disagree, and `ChannelLevelDeclarationDriftTest` runs the external corpus to
find out whether they have.

Three artefacts have to agree about a level, and each pair is compared by a
test rather than by convention: the channel **name** against the channel's
**declaration** (`ChannelLevelAssemblyTopologyTest` — a code carrying a level
segment must be the one `ViolationChannel::leveled()` produces for the level it
declares, and no level segment may be written as a literal anywhere in `src/`),
the declaration against what the product is **observed** emitting
(`ChannelLevelDeclarationDriftTest`), and the declaration against the tracked
fixture (`ChannelDeclarationFixtureDriftTest`). Finding neither resolves computed
definitions nor retains Infrastructure-owned definition state.

`ChannelPresentationInterface` (`presentationFor()` → `ChannelPresentation`)
joins `ChannelIdentityInterface::producerOf()` with that producer's own
`RuleMetadata` (description) and its declared documentation page —
`ChannelPresentationView` is the composing service, a small run-time join
rather than a fourth view on the universe (rule *instances* do not exist when
the universe is assembled). It cannot depend on `ComputedMetricDefinition` to
prefer a configured `computed.*`/`health.*` channel's own description without
closing a dependency cycle back onto this capability; that preference is
layered on by `Infrastructure\Rule\ComputedMetricChannelPresentation`, a
decorator registered in front of the public alias. See
`docs/internal/plans/sarif-channel-descriptions.md`, package P2.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
