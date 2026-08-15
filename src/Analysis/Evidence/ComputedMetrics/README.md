# Computed Metrics

## Subject and boundary

`Analysis\Evidence\ComputedMetrics` owns formula-defined metrics and their
run-scoped definition lifecycle. It resolves the ordered configuration
document, validates and orders definitions, evaluates formulas after
Measurement aggregation, and emits threshold findings through its own rule.
There is no process-global definition holder.

`Health` is a distinct child owner. It owns the six built-in health dimensions,
score/decomposition semantics, contributor ranking, namespace drill-down, and
human explanations. Reporting owns only report assembly and output projection;
it consumes immutable Health contracts and never imports Health internals.

## Structure

```text
ComputedMetrics/
├── Contract/                         # exact promises consumed outside the root owner
│   ├── Configuration/                # runtime configuration and Health exclusion promises
│   ├── Definition/                   # definitions, dimensions, and immutable resolved snapshot
│   ├── Evaluation/                   # concrete evaluation service consumed by Run
│   └── Finding/                      # computed finding channel family
├── Configuration/
│   └── ComputedMetricContributionReader.php
├── Finding/
│   └── ComputedMetricFindingBuilder.php
├── ComputedMetricAnalysis.php        # instance-owned catalog and configuration facade
├── ComputedMetricsConfigResolver.php # validated definition resolution
├── ComputedMetricFormulaValidator.php
├── ComputedMetricDependencyGraphCalculator.php
├── ComputedMetricDefaults.php
├── ComputedMetricRule.php
├── ComputedMetricRuleOptions.php
└── Health/
    ├── Contract/                     # exact Reporting-facing surface
    │   ├── DrillDown/                # score and worst-class queries
    │   ├── Metadata/                 # immutable metadata projection
    │   ├── Offender/                 # offender value
    │   ├── Score/                    # score and decomposition values
    │   └── Summary/                  # summary value and concrete builder
    ├── Configuration/                # formula exclusion
    ├── Metadata/                     # metric hints, health dimensions, facade
    ├── Offender/                     # evidence, reasons, projection builder
    └── Score/                        # contributor ranking
```

## Lifecycle and phase

`AnalysisRuntimeConfigurator` resolves
`ResolvedComputedMetricDefinitions` from the ordered `computed_metrics` and
`exclude_health` contributions before mutating any owner state. It passes that
immutable value to selector validation, which obtains the exact rule-channel
snapshot. Only after all resolution and validation succeeds does the runtime
replace the ComputedMetrics token and commit the selector snapshot. Run reset
discards the selector snapshot; a failed resolution or validation leaves the
previous stores untouched and the selector static-only.

`AnalysisPipeline` calls `ComputedMetricEvaluator::evaluate()` after
Measurement aggregation and before CircularDependency preparation. Evaluation
reads the replaced immutable definition token, mutates only
`MetricRepositoryInterface`, owns the `computed` profiler span, and is a no-op
when no files or definitions exist.

## Public contracts and named consumers

- `ComputedMetricConfiguratorInterface` —
  `Infrastructure\Console\AnalysisRuntimeConfigurator`.
- `ComputedMetricEvaluator` — `Analysis\Run\Pipeline\AnalysisPipeline`.
- `ResolvedComputedMetricDefinitions` — immutable definitions resolved for one
  run. Infrastructure Rule receives it only as the input to its exact
  `RuleChannelSnapshotFactoryInterface`; it consumes no retained definition
  state.
- `ComputedMetricDefinitionCatalogInterface` — Health and Reporting's named
  projection consumers.
- `ComputedMetricChannelFamily` — the channel declaration compiler pass.
- `HealthFormulaExclusionInterface` and `ComputedMetricDefinition` — Health's
  exclusion implementation.
- `HealthDimension` — Reporting's HTML, JSON, and summary projections plus
  Health's exclusion, summary, and drill-down services.
- Health contracts — exact Reporting consumers for score/decomposition values,
  summary construction, metadata projection, drill-down, and offenders.

Every consumer is exact-source manifest authority. Root and Health internals
are not public, and neither taxonomy namespace is an allow target.

## Configuration semantics

- A later `computed_metrics` contribution replaces the complete previous map;
  an explicit `{}` is a replacement, while omission retains the prior map.
- `exclude_health` contributions append in source order and stable-deduplicate;
  omission or `[]` adds nothing.
- Disabled built-in dimensions are folded into exclusions before formula
  validation and `health.overall` weight normalization.
- Definitions may reference other computed metrics; cycles and unknown
  references fail configuration before publication.

The public YAML keys, formulas, thresholds, metric names, channel names, CLI
behavior, and report schemas are unchanged by the ownership migration.

## Tests

Owned tests live under `tests/Analysis/Evidence/ComputedMetrics/`. The
materialized P5-F2 slice contains 28 PHPUnit classes, 281 discovered IDs, one
support class, and no fixtures when the three retained Reporting assembly tests
are included. Topology tests classify 43 raw relations exactly: 38 classified
relations (22 non-Health and 16 Health) plus five unchanged composed carriers.
The classified set includes `ResolvedComputedMetricDefinitions` relations to
`AnalysisRuntimeConfigurator`, `RuleInputValidator`, `RuleChannelRegistry`, and
`RuleChannelSnapshotFactoryInterface`. Reverse, unknown-zone, cross-owner
internal, and unclassified Contract imports fail closed.

## Definition of Done

- Definitions are resolved as `ResolvedComputedMetricDefinitions` and replaced
  atomically between runs; Infrastructure Rule receives only the run value.
- Run depends only on the evaluation contract and stores no capability state.
- Health imports no Reporting type; Reporting imports only root/Health
  contracts for computed-metric semantics.
- Formula evaluation, finding channels, health values, and output schemas
  preserve their existing behavior.
- Manifest, generated ownership evidence, PHPUnit discovery, and dogfooding are
  fresh and green.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
