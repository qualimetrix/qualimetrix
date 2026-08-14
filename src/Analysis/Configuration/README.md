# Configuration

## Subject and current boundary

`Analysis\\Configuration` owns loading, normalizing, merging, validating, and
resolving the configuration document used for one analysis invocation. Its
current runtime object is deliberately named
`TransitionalRuntimeConfiguration`: P3 has moved the physical configuration
boundary, but it has not claimed that the mixed runtime DTO is a neutral final
kernel.

The module currently carries transitional fields for shared aggregation and
namespace detection. Their owners are made explicit by later packages: P4
removed Architecture configuration, P5 removed computed/health configuration,
P6 moved rule-option state behind Finding-owned contracts and moved output
format plus finding projection controls to Reporting-owned values, and P7
removed Coupling's framework-namespace field. Do not add a feature field to
this DTO as a permanent integration pattern.

## Structure

```text
Configuration/
├── Contract/
│   ├── Discovery/      # Composer autoload-path reader
│   ├── Exception/      # configuration load error
│   ├── Pipeline/       # context and pipeline contracts
│   ├── OutputFormat.php
│   ├── ResolvedFindingExclusions.php
│   └── Transitional*   # current runtime/resolved configuration promises
├── Discovery/          # Composer metadata reader
├── Loader/             # YAML load and section normalization
├── Pipeline/Stage/     # defaults, preset, file, Composer, CLI stages
├── Preset/             # built-in and custom preset resolution
└── Runtime/            # instance-owned runtime configuration holder
```

## Resolution model

`ConfigurationPipelineInterface` runs ordered stages over a
`ConfigurationContext`, then produces `TransitionalResolvedConfiguration`.
`ConfigSchema` remains the single source of YAML key names and types. The
precedence order is defaults, presets, configuration files, Composer discovery,
and CLI options; later layers override earlier scalar values while the schema
defines merge semantics for collection values.

The immutable contract `ConfigurationDocument` preserves ordered source
contributions. Feature leaves consume their own contribution key: for example,
Architecture policy parses and merges only `architecture` after the Console
logger exists, then returns typed warnings through its own contract. The
central pipeline neither contains an Architecture object nor transports a
feature-specific deferred warning. ComputedMetrics folds `computed_metrics` and
`exclude_health` directly from the same ordered document and publishes an
instance-owned catalog only after full validation. Coupling likewise folds the
canonical `coupling.framework_namespaces` contribution into its own run-scoped
state. The document root remains normalized and schema-governed even though the
transitional DTO no longer copies that value.

## Public contracts and adapters

External consumers use only declared `Contract/` promises. Loader types,
including `Loader/ConfigLoaderInterface`, are internal and are composed behind
the Configuration boundary. Infrastructure composition registers the configuration pipeline and
its stages; Console resolves configuration and sets the
`TransitionalRuntimeConfigurationProviderInterface` for one run. No consumer
may construct a feature configuration factory through this mixed boundary.

The resolved boundary now publishes `RuleSelection`, `OutputFormat`, and
`FindingProjectionOptions`. Console configures Finding once, passes projection
options to Reporting, and passes the output-format value to the presenter. The
transitional runtime DTO no longer carries `disabledRules`, `onlyRules`,
`excludePaths`, `excludeNamespaces`, or `format`.

## CLI option aliases

This is the canonical internal reference for aliases discovered from current
rule classes. `DocumentationConsistencyTest` requires every alias to remain in
this moved README; the user-facing CLI reference remains under `website/docs/`.

| Option                                                  | Rule                                 | Field                      |
| ------------------------------------------------------- | ------------------------------------ | -------------------------- |
| `--circular-deps`                                       | architecture.circular-dependency     | enabled                    |
| `--max-cycle-size=N`                                    | architecture.circular-dependency     | maxCycleSize               |
| `--layer-violation`                                     | architecture.layer-violation         | enabled                    |
| `--layer-violation-severity=SEVERITY`                   | architecture.layer-violation         | severity                   |
| `--layer-violation-unreachable-layer-severity=SEVERITY` | architecture.layer-violation         | unreachable_layer_severity |
| `--layer-violation-potential-shadow-severity=SEVERITY`  | architecture.layer-violation         | potential_shadow_severity  |
| `--layer-violation-empty-template-severity=SEVERITY`    | architecture.layer-violation         | empty_template_severity    |
| `--constructor-overinjection-warning=N`                 | code-smell.constructor-overinjection | warning                    |
| `--constructor-overinjection-error=N`                   | code-smell.constructor-overinjection | error                      |
| `--long-parameter-list-warning=N`                       | code-smell.long-parameter-list       | warning                    |
| `--long-parameter-list-error=N`                         | code-smell.long-parameter-list       | error                      |
| `--long-parameter-list-vo-warning=N`                    | code-smell.long-parameter-list       | vo-warning                 |
| `--long-parameter-list-vo-error=N`                      | code-smell.long-parameter-list       | vo-error                   |
| `--unreachable-code-warning=N`                          | code-smell.unreachable-code          | warning                    |
| `--unreachable-code-error=N`                            | code-smell.unreachable-code          | error                      |
| `--cognitive-warning=N`                                 | complexity.cognitive                 | method.warning             |
| `--cognitive-error=N`                                   | complexity.cognitive                 | method.error               |
| `--cognitive-class-warning=N`                           | complexity.cognitive                 | class.max_warning          |
| `--cognitive-class-error=N`                             | complexity.cognitive                 | class.max_error            |
| `--cyclomatic-warning=N`                                | complexity.cyclomatic                | method.warning             |
| `--cyclomatic-error=N`                                  | complexity.cyclomatic                | method.error               |
| `--cyclomatic-class-warning=N`                          | complexity.cyclomatic                | class.max_warning          |
| `--cyclomatic-class-error=N`                            | complexity.cyclomatic                | class.max_error            |
| `--npath-warning=N`                                     | complexity.npath                     | method.warning             |
| `--npath-error=N`                                       | complexity.npath                     | method.error               |
| `--npath-class-warning=N`                               | complexity.npath                     | class.max_warning          |
| `--npath-class-error=N`                                 | complexity.npath                     | class.max_error            |
| `--wmc-warning=N`                                       | complexity.wmc                       | warning                    |
| `--wmc-error=N`                                         | complexity.wmc                       | error                      |
| `--wmc-exclude-data-classes=N`                          | complexity.wmc                       | excludeDataClasses         |
| `--cbo-warning=N`                                       | coupling.cbo                         | class.warning              |
| `--cbo-error=N`                                         | coupling.cbo                         | class.error                |
| `--cbo-ns-warning=N`                                    | coupling.cbo                         | namespace.warning          |
| `--cbo-ns-error=N`                                      | coupling.cbo                         | namespace.error            |
| `--class-rank-warning=N`                                | coupling.class-rank                  | warning                    |
| `--class-rank-error=N`                                  | coupling.class-rank                  | error                      |
| `--distance-warning=N`                                  | coupling.distance                    | max_distance_warning       |
| `--distance-error=N`                                    | coupling.distance                    | max_distance_error         |
| `--instability-class-warning=N`                         | coupling.instability                 | class.max_warning          |
| `--instability-class-error=N`                           | coupling.instability                 | class.max_error            |
| `--instability-ns-warning=N`                            | coupling.instability                 | namespace.max_warning      |
| `--instability-ns-error=N`                              | coupling.instability                 | namespace.max_error        |
| `--data-class-woc-threshold=N`                          | design.data-class                    | wocThreshold               |
| `--data-class-wmc-threshold=N`                          | design.data-class                    | wmcThreshold               |
| `--data-class-min-methods=N`                            | design.data-class                    | minMethods                 |
| `--data-class-exclude-readonly=N`                       | design.data-class                    | excludeReadonly            |
| `--data-class-exclude-promoted-only=N`                  | design.data-class                    | excludePromotedOnly        |
| `--data-class-exclude-exceptions=N`                     | design.data-class                    | excludeExceptions          |
| `--god-class-wmc-threshold=N`                           | design.god-class                     | wmcThreshold               |
| `--god-class-lcom-threshold=N`                          | design.god-class                     | lcomThreshold              |
| `--god-class-tcc-threshold=N`                           | design.god-class                     | tccThreshold               |
| `--god-class-class-loc-threshold=N`                     | design.god-class                     | classLocThreshold          |
| `--god-class-min-criteria=N`                            | design.god-class                     | minCriteria                |
| `--god-class-min-methods=N`                             | design.god-class                     | minMethods                 |
| `--god-class-exclude-readonly=N`                        | design.god-class                     | excludeReadonly            |
| `--dit-warning=N`                                       | design.inheritance                   | warning                    |
| `--dit-error=N`                                         | design.inheritance                   | error                      |
| `--lcom-warning=N`                                      | design.lcom                          | warning                    |
| `--lcom-error=N`                                        | design.lcom                          | error                      |
| `--lcom-exclude-readonly=N`                             | design.lcom                          | excludeReadonly            |
| `--lcom-min-methods=N`                                  | design.lcom                          | minMethods                 |
| `--lcom-exclude-methods=V`                              | design.lcom                          | excludeMethods             |
| `--noc-warning=N`                                       | design.noc                           | warning                    |
| `--noc-error=N`                                         | design.noc                           | error                      |
| `--type-coverage-param-warning=N`                       | design.type-coverage                 | param_warning              |
| `--type-coverage-param-error=N`                         | design.type-coverage                 | param_error                |
| `--type-coverage-return-warning=N`                      | design.type-coverage                 | return_warning             |
| `--type-coverage-return-error=N`                        | design.type-coverage                 | return_error               |
| `--type-coverage-property-warning=N`                    | design.type-coverage                 | property_warning           |
| `--type-coverage-property-error=N`                      | design.type-coverage                 | property_error             |
| `--mi-warning=N`                                        | maintainability.index                | warning                    |
| `--mi-error=N`                                          | maintainability.index                | error                      |
| `--mi-exclude-tests=N`                                  | maintainability.index                | excludeTests               |
| `--mi-min-statements=N`                                 | maintainability.index                | minStatements              |
| `--class-count-warning=N`                               | size.class-count                     | warning                    |
| `--class-count-error=N`                                 | size.class-count                     | error                      |
| `--method-count-warning=N`                              | size.method-count                    | warning                    |
| `--method-count-error=N`                                | size.method-count                    | error                      |
| `--property-count-warning=N`                            | size.property-count                  | warning                    |
| `--property-count-error=N`                              | size.property-count                  | error                      |
| `--property-exclude-readonly=N`                         | size.property-count                  | excludeReadonly            |
| `--property-exclude-promoted-only=N`                    | size.property-count                  | excludePromotedOnly        |

Use `--rule-opt=RULE:OPTION=VALUE` for every option without a short alias.

## Definition of Done

- The same input layers resolve deterministically and invalid document data
  fails with `ConfigLoadException`.
- Two analysis invocations in one process do not leak runtime or rule-option
  state.
- Every new YAML key is added to `ConfigSchema` and consumed by its natural
  owner, rather than extending the transitional DTO by default.
