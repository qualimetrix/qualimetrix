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
│   ├── Definition/                   # definitions, dimensions, and catalog promise
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

`RuntimeConfigurator` calls `ComputedMetricConfiguratorInterface::configure()`
before a run. Configuration always clears the prior catalog first, folds the
ordered `computed_metrics` and `exclude_health` contributions through
`ComputedMetricContributionReader`, validates the
whole dependency graph, and publishes only a successful definition set. A
failed second configuration cannot leak definitions from the first run.

`AnalysisPipeline` calls `ComputedMetricEvaluator::evaluate()` after
Measurement aggregation and before CircularDependency preparation. Evaluation
reads one immutable catalog snapshot, mutates only `MetricRepositoryInterface`,
owns the `computed` profiler span, and is a no-op when no files or definitions
exist.

## Public contracts and named consumers

- `ComputedMetricConfiguratorInterface` — `Infrastructure\Console\RuntimeConfigurator`.
- `ComputedMetricEvaluator` — `Analysis\Run\Pipeline\AnalysisPipeline`.
- `ComputedMetricDefinitionCatalogInterface` — the two Infrastructure rule
  registries, Reporting's `HtmlTreeBuilder`, Health's summary builder, and both
  Health drill-down services.
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
are included. Topology tests classify all 39 raw cross-owner Contract imports
exactly: 34 manifest-authorized consumer relations and five immutable
carrier/composition imports. Reverse, unknown-zone, cross-owner internal, and
unclassified Contract imports fail closed.

## Definition of Done

- Definitions are instance-owned and replaced atomically between runs.
- Run depends only on the evaluation contract and stores no capability state.
- Health imports no Reporting type; Reporting imports only root/Health
  contracts for computed-metric semantics.
- Formula evaluation, finding channels, health values, and output schemas
  preserve their existing behavior.
- Manifest, generated ownership evidence, PHPUnit discovery, and dogfooding are
  fresh and green.
