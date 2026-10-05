# Computed Metrics

## Subject and boundary

`Analysis\Evidence\ComputedMetrics` owns formula-defined metrics and their
run-scoped definition lifecycle. It resolves the ordered configuration
document, validates and orders definitions, evaluates formulas after
Measurement aggregation, and emits threshold findings through `ComputedMetricRule`.
There is no process-global definition holder.

One rule class serves seven producers, not one. The six built-in health
dimensions each publish under their own name (`health.complexity`,
`health.cohesion`, `health.coupling`, `health.typing`, `health.maintainability`,
`health.overall`) because that set is closed at container-build time; every
user-defined metric publishes under the shared `computed`, because its name
comes out of someone else's `qmx.yaml` and cannot be validated by the stage
that validates that configuration. A producer here is therefore not in
bijection with a rule class — see
`Contract/Finding/ComputedMetricChannelFamily` for the declared names and the
`producerFor()` arbiter, and `ComputedMetricProducerOptions` for why the rule
asks per-definition options rather than one shared `enabled` flag.

`Health` is a distinct child owner. It owns the six built-in health dimensions,
score/decomposition semantics, contributor ranking, namespace drill-down, and
human explanations. Reporting owns only report assembly and output projection;
it consumes immutable Health contracts and never imports Health internals.

`Metadata` answers two questions that share no data, and holds a class for
each: `HealthDecompositionCatalog` says which metrics a score is made of and
under which keys they are published, `HealthDimensionCatalog` says how a
dimension is worded. They were one class while the decomposition was a single
flat list; once it had to answer per level, the product's own god-class rule
said the class held two subjects.

A decomposition answers per symbol level, because the formulas do: the project
coupling score is computed from CBO aggregates where the namespace one is
computed from Ce aggregates, and a single list described the wrong score at two
levels out of three. `HealthDimensionCatalog::inputsFor()` resolves the project
level from the namespace one exactly where `ComputedMetricDefinition`
inherits the namespace formula, and the metadata projection ships every level
resolved so the HTML report picks by the node's own level. Which class dragged a
parent score down is a separate question with a separate list — the contributor
keys — and the two must not be conflated.

Every input line also declares what it covers: the `.count` its aggregate
already publishes and the population that count is a share of. A score reports
the *narrowest* of its inputs' ratios, because a score is only as much a
statement about the project as its least-covered term — a cohesion score whose
TCC term saw a third of the classes is a statement about that third, however
completely LCOM saw the rest. Populations are symbol counts
(`size.symbol-class-count`, `size.symbol-method-count`,
`size.symbol-declaring-namespace-count`), never the `.count` of a
neighbouring metric and never the walk an aggregate makes: a denominator that
is itself a measurement shrinks when that measurement fails and hides the very
gap being reported. The namespace population used to be the tree's leaves — the
exact set `NamespaceToProjectAggregator` visits — so a namespace the walk
dropped left both sides of the ratio and the line read 100%. Where no input is
an aggregate over symbols — `health.overall`, `health.typing`, a class score, a
namespace-filtered subtree — the coverage is an explicit "not applicable" with
a reason, never a zero. Scores are never damped by coverage (ADR 0062).

An input line declares only the population; the `.count` is derived from the
line's own key, because it is the sample size of the very aggregate the line
displays. Declared beside the key it was a second spelling of one fact, and a
retargeted key with a stale count published "0 of 168 (0%)" in the tone of a
measurement. An absent `.count` at runtime is a genuine measured zero — the
aggregator publishes no key when nothing contributed — so it cannot be refused
there; an absent population is refused, since every run writes all three from
its own symbol list.

A ratio below 100% is not automatically a defect. `namespaces declaring a type`
carries a permanent gap: a namespace of bare enums declares a type but has no
abstractness of its own, so it is in the denominator and in no numerator. Two
such namespaces make this repository read 99%. Narrowing the denominator to the
namespaces the aggregate reached would print 100% and would be the same defect
the leaf-only population was.

Coverage is published on every surface that publishes a score: `json` and the
HTML payload carry the full record, `--format=health` a `Coverage` column on
every row including `overall` and at every terminal width, and the default
`summary` one dimmed line per dimension. A surface that shows the score and not
the share is a score over an unstated part of the subject.

`--exclude-health` renormalizes the remaining weights and hands the rounding
remainder to the widest share, so the printed weights sum to exactly one.
Rounding each on its own left the scale at 0.9999 and a subject scoring 100
everywhere published 99.99.

Namespace drill-down receives a bound `NamespacePattern`. `exact` aggregates
only the named namespace; `subtree` and `regex` aggregate every matched
namespace with the same class-count weighting used for report health and worst
classes. Renderers retain the authored selector spelling for diagnostics.

The threshold a decomposition line advertises is the knee its formula term
applies, and nothing else. Both had drifted silently while the catalog
transcribed the constants by hand, so
`HealthDecompositionAgreesWithFormulasTest` now reads the formulas and fails on
disagreement: every shown input must be one its level's formula reads, and every
advertised target must be that formula's own knee. A term whose knee sits on a
blend of several signals advertises nothing per key, and a term with no knee is
declared knee-less and rechecked as such.

## Structure

```text
ComputedMetrics/
├── Contract/                         # exact promises consumed outside the root owner
│   ├── Configuration/                # runtime configuration and Health exclusion promises
│   ├── Definition/                   # definitions, dimensions, and immutable resolved snapshot
│   ├── Evaluation/                   # concrete evaluation service consumed by Run
│   │   ├── ComputedMetricReads.php       # which absent keys a formula reads where it cannot use null
│   │   └── ComputedMetricBranchTrace.php # the conditional operands one evaluation entered
│   └── Finding/                      # computed finding channel family
├── Configuration/
│   ├── ComputedMetricsSection.php            # the `computed_metrics:` section declared to the document, and where an entry sits in it
│   ├── ExcludeHealthSection.php              # the `exclude_health:` section declared to the document
│   ├── ComputedMetricEntryKeys.php           # one entry's schema, reporting levels, six health names
│   ├── ComputedMetricValueForm.php          # shared context-free formula and level validation in each writing layer
│   ├── ComputedMetricAuthorship.php          # which layers wrote each metric, for a refusal to name
│   └── ComputedMetricRefusalWording.php      # the words of every refusal about what a value means
├── Finding/
│   └── ComputedMetricFindingBuilder.php
├── ComputedMetricAnalysis.php        # instance-owned catalog and configuration facade
├── ComputedMetricsConfigResolver.php # validated definition resolution
├── ComputedMetricFormulaValidator.php
├── ComputedMetricDependencyGraphCalculator.php
├── ComputedMetricDefaults.php
├── ComputedMetricProducerOptions.php # per-definition `enabled` routed through the producer arbiter
├── ComputedMetricRule.php
├── ComputedMetricRuleOptions.php
└── Health/
    ├── Contract/                     # exact Reporting-facing surface
    │   ├── DrillDown/                # score and worst-class queries
    │   ├── Metadata/                 # immutable metadata projection
    │   ├── Offender/                 # offender value and the levels ranked for it
    │   ├── Score/                    # score, decomposition and covered-share values
    │   └── Summary/                  # summary value and concrete builder
    ├── Configuration/                # formula exclusion
    ├── Metadata/                     # metric hints, decomposition, dimension wording, facade
    ├── Offender/                     # evidence, reasons, projection builder
    └── Score/                        # contributor ranking, covered-share reading
```

## Lifecycle and phase

`AnalysisRuntimeConfigurator` resolves
`ResolvedComputedMetricDefinitions` from the `computed_metrics` and
`exclude_health` sections of the resolved configuration document before
mutating any owner state. It passes that
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

Which absent keys a formula would read as `null` is one query,
`ComputedMetricExpression::missingKeysOf()`, asked against three presence
sets. `ComputedMetricFormulaValidator` asks it with every referenced computed
metric present only at its own `levels:`, so a bare cross-level read is a
configuration refusal before the run. The evaluator asks it with the union of
keys the level carries (a miss is the same refusal, after measurement) and then
per symbol through `evaluateOn()` (a miss publishes no value, counted in one
warning per metric and level). The right side of `??` counts only where its
left side is absent.

A ternary branch and the right side of `and`/`or` run only on a value the
symbol carries, so before a run only a key every path reads counts; the
condition always runs and counts in full. Per symbol, `ComputedMetricBranchTrace`
runs the formula as a copy whose conditional operands report their entry, and
the operands the evaluation entered decide. Entering one that would read an
absent key where no enclosing `??` catches it stops the run before the `null`
reaches the arithmetic or a PHP function. Which branch runs is taken from the
evaluation itself and never computed beside it; the trace relies only on
Expression Language evaluating one branch of `?:` and short-circuiting
`and`/`or`, not on how it marks the left side of `??`.

## Public contracts and named consumers

- `ComputedMetricConfiguratorInterface` —
  `Infrastructure\Console\AnalysisRuntimeConfigurator`.
- `ComputedMetricEvaluator` — `Analysis\Run\Pipeline\AnalysisPipeline`.
- `ResolvedComputedMetricDefinitions` — immutable definitions resolved for one
  run. Infrastructure Rule receives it as the input to its exact
  `RuleChannelSnapshotFactoryInterface`, which builds a preflight channel
  universe over it.
- `ComputedMetricDefinitionCatalogInterface` — Health and Reporting's named
  projection consumers.
- `ComputedMetricChannelFamily` — declares the family's seven producer names
  and every class-keyed fact about them (shape, remediation minutes, docs
  page, threshold-override support) for the channel declaration
  compiler pass, and arbitrates which producer owns a given definition.
- `HealthFormulaExclusionInterface` and `ComputedMetricDefinition` — Health's
  exclusion implementation.
- `HealthDimension` — Reporting's HTML, JSON, and summary projections plus
  Health's exclusion, summary, and drill-down services.
- Health contracts — exact Reporting consumers for score/decomposition values,
  summary construction, metadata projection, drill-down, and offenders.

Every consumer is exact-source manifest authority. Root and Health internals
are not public, and neither taxonomy namespace is an allow target.

## Configuration semantics

The two sections are declared to the configuration document
(`ComputedMetricsSection`, `ExcludeHealthSection`); the document engine reads
every layer — defaults, presets, the configuration file, the command line —
recognises its keys, judges the form of its values and merges the layers.
`ComputedMetricValueForm` judges formula syntax, literal metric-index access
and the closed, non-duplicated level list in each layer that writes them. It
shares those algorithms with the resolved readers. Formula availability,
metric references, cycles and reference-level compatibility depend on the
merged definitions and are judged there.
The owner reads the merged result. The policy of every key is published in the
generated table of `website/docs/getting-started/configuration.md`; the
decision is [ADR 0086](../../../../docs/adr/0086-one-configuration-document-merged-by-declared-policy.md).

- `computed_metrics` merges by metric name, and each metric key by key: a
  layer changes only the keys it writes. `threshold` stands for `warning` plus
  `error` and is expanded in the layer that wrote it, so a file's `warning`
  over a preset's `threshold` keeps the preset's `error`. Writing
  `threshold` beside `warning` or `error` in one layer is refused.
- `formula` is the formula of every reporting level; `formulas.<level>`
  refines one level beside it, whichever layer wrote either.
  `ComputedMetricDefinition::formulaLevelFor()` selects the stored level
  for both evaluation and authorship. Project inherits namespace only when
  there is no stored project formula; an unchanged built-in project formula
  keeps its default authorship under an authored namespace refinement.
  A refusal about one formula names its exact writing leaf, even when another
  layer changed only the metric's description. Health exclusions use the same
  selection and order their formula and exclusion writers by precedence.
- `levels` is replaced whole by the last layer that writes it.
- `~` and `{}` write nothing under the name: a metric written either way
  leaves the metric below it — a built-in dimension or a preset's metric —
  unchanged, and a name nothing below defines is a user metric without a
  formula, refused with the layer that wrote the name. A lower layer's metric
  is removed only by `enabled: false`.
- `exclude_health` accumulates across layers and deduplicates; `[]` adds
  nothing. Each item is judged in the words of the layer that wrote it: a
  file's typo names the file and the item's path, an option's names the
  option.
- Disabled built-in dimensions are folded into exclusions before formula
  validation and `health.overall` weight normalization.
- Definitions may reference other computed metrics; cycles and unknown
  references fail configuration before publication.
- A metric name is judged by the document engine in the layer that wrote it,
  whatever is written under it (`ComputedMetricsSection`'s name predicate): a
  `health.*` name must be one of
  `ComputedMetricEntryKeys::acceptedHealthNames()`, any other must follow the
  name grammar and not end in a level word. Every refusal about a resolved
  definition names the layers that wrote it (`ComputedMetricAuthorship`); a
  built-in definition no layer touched is attributed to the defaults.

## Tests

Owned tests live under `tests/Analysis/Evidence/ComputedMetrics/`. The
materialized slice contains 29 PHPUnit classes, one support class, and no
fixtures when the three
retained Reporting assembly tests are included. Topology tests classify 42 raw relations exactly: 37 classified
relations (21 non-Health and 16 Health) plus five unchanged composed carriers.
The classified set includes `ResolvedComputedMetricDefinitions` relations to
`AnalysisRuntimeConfigurator`, `RuleInputValidator`, and
`RuleChannelSnapshotFactoryInterface`. `ChannelUniverse` itself reads only
`ComputedMetricDefinitionCatalogInterface`: the concrete resolved value reaches
it through that factory contract and never as an import of its own. Reverse, unknown-zone, cross-owner
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

## Formula reach for baseline comparison

Reach is determined at the selected level from all transitive formula references.
A Run-dependent input in any branch makes the formula Run-dependent, even when
that branch does not execute for current values. Project-level baseline subjects
always require the whole region. Baseline owns comparability; this capability
provides formula evidence rather than another run lifecycle port.
