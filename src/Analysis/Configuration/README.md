# Configuration

## Subject and current boundary

`Analysis\\Configuration` owns loading, normalizing, merging, validating, and
resolving the ordered `ConfigurationDocument` used for one analysis invocation.
It owns source resolution and schema semantics, not a cross-owner runtime DTO.
Each feature resolves its own immutable projection from that concrete document;
owner-local runtime state exists only where a long-lived service needs it.

The document is a narrow public source seam: its contributions and working
directory are consumed by named owners, while Symfony input remains inside the
Console adapter. It does not expose a generic configuration interface, a
universal invocation context, or a carrier for feature fields.

## Structure

```text
Configuration/
├── Contract/
│   ├── ConfigurationDocument.php # immutable ordered source contributions
│   ├── Discovery/                # Composer autoload-path reader
│   ├── Exception/                # configuration load error
│   └── Pipeline/                 # resolution request and pipeline contracts
├── Discovery/          # Composer metadata reader
├── Loader/             # YAML load and section normalization
├── Pipeline/Stage/     # defaults, preset, file, Composer, CLI stages
├── Preset/             # built-in and custom preset resolution
└── ConfigurationMerger.php # document-layer merge mechanics
```

## Resolution model

`ConfigurationPipelineInterface` runs ordered stages over a
`ConfigurationResolutionRequest`, then produces `ConfigurationDocument`.
`ConfigSchema` remains the single source of YAML key names and types. The
precedence order is defaults, presets, configuration files, Composer discovery,
and CLI options; later layers override earlier scalar values while the schema
defines merge semantics for collection values.

`ConfigurationDocument` preserves ordered source contributions. Feature leaves
consume their own contribution key: for example,
Architecture policy parses and merges only `architecture` after the Console
logger exists, then returns typed warnings through its own contract. The
central pipeline neither contains an Architecture object nor transports a
feature-specific deferred warning. ComputedMetrics folds `computed_metrics` and
`exclude_health` directly from the same ordered document and publishes an
instance-owned catalog only after full validation. Coupling likewise folds the
canonical `coupling.framework_namespaces` contribution into its own run-scoped
state. The document root remains normalized and schema-governed even though the
mixed carrier copies that value.

## Public contracts and adapters

External consumers use only declared `Contract/` promises. Loader types,
including `Loader/ConfigLoaderInterface`, are internal and are composed behind
the Configuration boundary. Infrastructure composition registers the pipeline
and its stages; Console adapts Symfony input into the resolution request.
Consumers resolve only their named value: Run produces `RunConfiguration`,
Finding produces `FindingConfiguration`, Cache and Parallel produce their local
configurations, and Reporting resolves output and finding-projection values.
No consumer may construct a feature configuration factory through Configuration
or add a feature field to a shared carrier.

## CLI option aliases

This is the canonical internal reference for aliases discovered from current
rule classes. `DocumentationConsistencyTest` requires every alias to remain in
this moved README; the user-facing CLI reference remains under `website/docs/`.

| Option                                  | Rule                                 | Field                 |
| --------------------------------------- | ------------------------------------ | --------------------- |
| `--circular-deps`                       | architecture.circular-dependency     | enabled               |
| `--max-cycle-size=N`                    | architecture.circular-dependency     | maxCycleSize          |
| `--layer-violation`                     | architecture.layer-violation         | enabled               |
| `--layer-violation-severity=SEVERITY`   | architecture.layer-violation         | severity              |
| `--unassigned-class-mode=MODE`          | architecture.unassigned-class        | mode                  |
| `--constructor-overinjection-warning=N` | code-smell.constructor-overinjection | warning               |
| `--constructor-overinjection-error=N`   | code-smell.constructor-overinjection | error                 |
| `--long-parameter-list-warning=N`       | code-smell.long-parameter-list       | warning               |
| `--long-parameter-list-error=N`         | code-smell.long-parameter-list       | error                 |
| `--long-parameter-list-vo-warning=N`    | code-smell.long-parameter-list       | vo-warning            |
| `--long-parameter-list-vo-error=N`      | code-smell.long-parameter-list       | vo-error              |
| `--unreachable-code-warning=N`          | code-smell.unreachable-code          | warning               |
| `--unreachable-code-error=N`            | code-smell.unreachable-code          | error                 |
| `--cognitive-warning=N`                 | complexity.cognitive                 | callable.warning      |
| `--cognitive-error=N`                   | complexity.cognitive                 | callable.error        |
| `--cognitive-class-warning=N`           | complexity.cognitive                 | class.max_warning     |
| `--cognitive-class-error=N`             | complexity.cognitive                 | class.max_error       |
| `--cyclomatic-warning=N`                | complexity.cyclomatic                | callable.warning      |
| `--cyclomatic-error=N`                  | complexity.cyclomatic                | callable.error        |
| `--cyclomatic-class-warning=N`          | complexity.cyclomatic                | class.max_warning     |
| `--cyclomatic-class-error=N`            | complexity.cyclomatic                | class.max_error       |
| `--npath-warning=N`                     | complexity.npath                     | callable.warning      |
| `--npath-error=N`                       | complexity.npath                     | callable.error        |
| `--npath-class-warning=N`               | complexity.npath                     | class.max_warning     |
| `--npath-class-error=N`                 | complexity.npath                     | class.max_error       |
| `--wmc-warning=N`                       | complexity.wmc                       | warning               |
| `--wmc-error=N`                         | complexity.wmc                       | error                 |
| `--wmc-exclude-data-classes=N`          | complexity.wmc                       | excludeDataClasses    |
| `--cbo-warning=N`                       | coupling.cbo                         | class.warning         |
| `--cbo-error=N`                         | coupling.cbo                         | class.error           |
| `--cbo-ns-warning=N`                    | coupling.cbo                         | namespace.warning     |
| `--cbo-ns-error=N`                      | coupling.cbo                         | namespace.error       |
| `--class-rank-warning=N`                | coupling.class-rank                  | warning               |
| `--class-rank-error=N`                  | coupling.class-rank                  | error                 |
| `--distance-warning=N`                  | coupling.distance                    | max_distance_warning  |
| `--distance-error=N`                    | coupling.distance                    | max_distance_error    |
| `--instability-class-warning=N`         | coupling.instability                 | class.max_warning     |
| `--instability-class-error=N`           | coupling.instability                 | class.max_error       |
| `--instability-ns-warning=N`            | coupling.instability                 | namespace.max_warning |
| `--instability-ns-error=N`              | coupling.instability                 | namespace.max_error   |
| `--data-class-woc-threshold=N`          | design.data-class                    | wocThreshold          |
| `--data-class-wmc-threshold=N`          | design.data-class                    | wmcThreshold          |
| `--data-class-min-members=N`            | design.data-class                    | minMembers            |
| `--data-class-exclude-readonly=N`       | design.data-class                    | excludeReadonly       |
| `--data-class-exclude-promoted-only=N`  | design.data-class                    | excludePromotedOnly   |
| `--data-class-exclude-exceptions=N`     | design.data-class                    | excludeExceptions     |
| `--god-class-wmc-threshold=N`           | design.god-class                     | wmcThreshold          |
| `--god-class-lcom-threshold=N`          | design.god-class                     | lcomThreshold         |
| `--god-class-tcc-threshold=N`           | design.god-class                     | tccThreshold          |
| `--god-class-class-loc-threshold=N`     | design.god-class                     | classLocThreshold     |
| `--god-class-min-criteria=N`            | design.god-class                     | minCriteria           |
| `--god-class-min-methods=N`             | design.god-class                     | minMethods            |
| `--god-class-exclude-readonly=N`        | design.god-class                     | excludeReadonly       |
| `--dit-warning=N`                       | design.inheritance                   | warning               |
| `--dit-error=N`                         | design.inheritance                   | error                 |
| `--lcom-warning=N`                      | cohesion.lcom                        | warning               |
| `--lcom-error=N`                        | cohesion.lcom                        | error                 |
| `--lcom-exclude-readonly=N`             | cohesion.lcom                        | excludeReadonly       |
| `--lcom-min-methods=N`                  | cohesion.lcom                        | minMethods            |
| `--lcom-exclude-methods=V`              | cohesion.lcom                        | excludeMethods        |
| `--noc-warning=N`                       | design.noc                           | warning               |
| `--noc-error=N`                         | design.noc                           | error                 |
| `--param-type-coverage-warning=N`       | design.type-coverage.param           | warning               |
| `--param-type-coverage-error=N`         | design.type-coverage.param           | error                 |
| `--return-type-coverage-warning=N`      | design.type-coverage.return          | warning               |
| `--return-type-coverage-error=N`        | design.type-coverage.return          | error                 |
| `--property-type-coverage-warning=N`    | design.type-coverage.property        | warning               |
| `--property-type-coverage-error=N`      | design.type-coverage.property        | error                 |
| `--mi-warning=N`                        | maintainability.index                | warning               |
| `--mi-error=N`                          | maintainability.index                | error                 |
| `--mi-exclude-tests=N`                  | maintainability.index                | excludeTests          |
| `--mi-min-statements=N`                 | maintainability.index                | minStatements         |
| `--class-count-warning=N`               | size.class-count                     | warning               |
| `--class-count-error=N`                 | size.class-count                     | error                 |
| `--method-count-warning=N`              | size.method-count                    | warning               |
| `--method-count-error=N`                | size.method-count                    | error                 |
| `--property-count-warning=N`            | size.property-count                  | warning               |
| `--property-count-error=N`              | size.property-count                  | error                 |
| `--property-exclude-readonly=N`         | size.property-count                  | excludeReadonly       |
| `--property-exclude-promoted-only=N`    | size.property-count                  | excludePromotedOnly   |

Use `--rule-opt=RULE:OPTION=VALUE` for every option without a short alias.

## Definition of Done

- The same input layers resolve deterministically and invalid document data
  fails with `ConfigLoadException`.
- Two analysis invocations in one process do not leak owner-local runtime or
  rule-option state.
- Every new YAML key is added to `ConfigSchema` and consumed by its natural
  owner, rather than extending a generic configuration carrier.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
