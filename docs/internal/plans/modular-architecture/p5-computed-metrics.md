# P5 — ComputedMetrics and Health

> **Status:** Completed. P5-A through P5-G and the corrective P5-F packages land at 739 declarations / 737 files, 37 owners, 34 manifest-authorized P5 relations plus five classified carrier/composition imports, and 509 PHPUnit classes / 7,245 IDs. The reviewed immutable ledger has 23 historical identities: 19 active semantic rekeys and four resolved rows. Publication contains nine reviewed exact namespace/channel exclusions, one exceptional reviewed Analysis Configuration distance recalibration, 281 accepted current baseline rows, and no other semantic debt. Two consecutive generations are byte-identical and workers=0 dogfood reports no violations. After all three independent-review findings were fixed, the reviewer returned GO and the final outside-sandbox `composer check` passed: PHPUnit 7,245 tests / 23,187 assertions / 1 skipped, Python 17 tests, PHPStan 1,267 files with no errors, and architecture 739 declarations / 737 files / 37 owners / 6 seams / 62 exact grants -> 10 coarse edges. P6 is the current design gate.
> **Prerequisites:** [overview](../modular-architecture.md), [decisions](decisions-and-target.md), [completed P4](p4-architecture-policy.md), and [roadmap](roadmap-p5-p8.md).

## Outcome

Create the subject-owned `Analysis\Evidence\ComputedMetrics` capability, keep
Health as its named subdomain, remove the process-global definition holder, and
dissolve P3's transitional enrichment shell. Reporting keeps formatting and
report assembly; ComputedMetrics/Health owns formulas, scores, decomposition,
ranking, drill-down and explanatory semantics. P5 preserves formulas,
thresholds, metric names, CLI keys, output schemas, finding channels and rule
severity.

## Finite current inventory

The manifest marks exactly 18 declarations for P5:

| Current root                             | Exact declarations                                                                                                  | Target owner             |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------ |
| `Configuration`                          | `ComputedMetricFormulaValidator`, `ComputedMetricsConfigResolver`                                                   | ComputedMetrics          |
| `Configuration/ComputedMetrics/Contract` | `HealthFormulaExclusionInterface`                                                                                   | ComputedMetrics contract |
| `Configuration`                          | `HealthFormulaExcluder`                                                                                             | ComputedMetrics.Health   |
| `Core/ComputedMetric`                    | `ComputedMetricDefaults`, `ComputedMetricDefinition`, `ComputedMetricDefinitionHolder`, `HealthDimension`           | ComputedMetrics          |
| `Metrics/ComputedMetric`                 | `ComputedMetricDependencyGraphCalculator`, `ComputedMetricEvaluator`                                                | ComputedMetrics          |
| `Reporting/Health`                       | `ContributorRanker`, `DecompositionItem`, `HealthContributor`, `HealthScore`, `NamespaceDrillDown`, `WorstOffender` | ComputedMetrics.Health   |
| `Rules/ComputedMetric`                   | `ComputedMetricRule`, `ComputedMetricRuleOptions`                                                                   | ComputedMetrics          |

Four additional Reporting declarations have this finite disposition:

| Current declaration/method set                                                                                                                                                               | Exact target                                                                                 | Owner                                          |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| `HealthReasonBuilder` (all methods)                                                                                                                                                          | `Analysis\Evidence\ComputedMetrics\Health\HealthReasonBuilder`                               | ComputedMetrics.Health internal                |
| `MetricHintProvider::{getLabel,getExplanation,getGoodValue,getDirection,getDecomposition,getDecompositionForClasses,getScoreLabel,getHealthDimensionLabel}`                                  | `Analysis\Evidence\ComputedMetrics\Health\HealthMetricCatalog`                               | ComputedMetrics.Health internal                |
| `MetricHintProvider::exportForHtml`                                                                                                                                                          | `Reporting\Health\HealthHintProjector::project` over `HealthMetricMetadataProviderInterface` | Reporting internal over Health contract        |
| `SummaryEnricher::{buildHealthScores,buildDecomposition,buildTypingDecomposition,buildWorstOffenders,getPerDimensionScores,countViolationsPerSymbol,getNotableMetrics,isDefinitionExcluded}` | `Analysis\Evidence\ComputedMetrics\Health\HealthSummaryBuilder`                              | ComputedMetrics.Health contract implementation |
| `SummaryEnricher::{enrich}` plus Debt/Impact calculation and `Report` construction                                                                                                           | retained `Reporting\Health\SummaryEnricher`                                                  | Reporting contract                             |
| `HealthScoreResolver` (all methods)                                                                                                                                                          | retained unchanged at `Reporting\Health\HealthScoreResolver`                                 | Reporting internal                             |

`HealthSummaryBuilderInterface`, `HealthMetricMetadataProviderInterface` and the
existing Health value/read declarations form the exact Reporting-facing
surface. The two old singleton seams disappear. Health imports no `Report`,
`FormatterContext`, formatter or HTML payload type.

`Analysis\Run\Enrichment\TransitionalMetricEnricher` and
`TransitionalEnrichmentResult` are deleted. Measurement retains aggregation,
CircularDependency retains cycle preparation, Run retains FileSet inspection,
and ComputedMetrics owns evaluation.

The holder has exactly seven production reader/writer sites to eliminate:
`TransitionalMetricEnricher`, `AnalysisRuntimeConfigurator`,
`ChannelDeclarationRegistry`, `RuleChannelRegistry`, `HtmlTreeBuilder`,
`SummaryEnricher`, and `ComputedMetricRuleOptions`. No static compatibility
facade remains.

The finite pre-P5 moved test set is **20 PHPUnit classes / 269 discovered IDs /
one support artifact / zero fixtures**:

- root: `BenchmarkConsumersCoverageTest`, `ComputedMetricsConfigResolverTest`,
  `HealthFormulaExcluderTest`, `ComputedMetricDefaultsTest`,
  `ComputedMetricDefinitionHolderTest`, `ComputedMetricDefinitionTest`,
  `ComputedMetricEvaluatorTest`, `ComputedMetricRuleOptionsTest`, and
  `ComputedMetricRuleTest`;
- Health: `ContributorRankerTest`, `DecompositionItemTest`,
  `HealthContributorTest`, `HealthReasonBuilderTest`, `HealthScoreResolverTest`,
  `HealthScoreTest`, `MetricHintProviderTest`, `NamespaceDrillDownTest`,
  `SummaryEnricherTest`, `ViolationDensityTest`, `WorstOffenderTest`, and
  `MetricRepositoryTestHelper`.

P5 closes exactly two temporary grants:
`ChannelDeclarationCompilerPass -> ComputedMetricRule` and
`OutputConfigurator -> ComputedMetricFormulaValidator`.

The 22 current declarations in scope (18 manifest P5 rows plus the four
Reporting dispositions) become exactly **32 target declarations**. Holder ->
`ComputedMetricAnalysis` is a one-for-one replacement; `MetricHintProvider` ->
`HealthMetricCatalog` is a rename. The ten net-new declarations are:

- root Contract: `ComputedMetricConfiguratorInterface`,
  `ComputedMetricEvaluationInterface`,
  `ComputedMetricDefinitionCatalogInterface`, `ComputedMetricChannelFamily`;
- Health Contract: `HealthSummaryBuilderInterface`, `HealthSummary`,
  `HealthMetricMetadataProviderInterface`, `HealthMetricMetadataCollection`;
- implementations/adapters: `HealthSummaryBuilder` and Reporting-owned
  `HealthHintProjector`.

The final manifest has exactly **34 P5-owned cross-owner contract consumer
relations**: 18 targeting root ComputedMetrics contracts and the 16
Reporting -> Health relations enumerated below. During P5-A/P5-B the total is
kept finite as the reviewed targets materialize; the evaluation relation is temporarily from
`TransitionalMetricEnricher`; P5-C replaces that exact source with the permanent
`AnalysisPipeline` source. Publication asserts both the declaration arithmetic
and final relation set.

## P5-0 — Completed design and topology gate

P5-0 selected a direct capability-owned contract consumed by Run. This follows
the cross-capability sequencing rule: Run knows that computed evaluation is an
explicit phase, but owns no formula configuration or computed state. A generic
metric-derivation participant/composite, service locator, raw-array callback
and static holder are rejected.

The immutable `ComputedMetricDefinition` moves into
`Analysis\Evidence\ComputedMetrics\Contract`; it is the catalog's public read
DTO, not an internal entity. `ComputedMetricDefaults` and the mutable catalog
implementation remain root internals. The one internal `ComputedMetricAnalysis`
instance implements three segregated contracts under that Contract namespace:

| Contract                                   | Exact permanent source consumers                                                                                                        | Operation                                                                                                                         |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `ComputedMetricConfiguratorInterface`      | `Infrastructure\Console\RuntimeConfigurator`                                                                                            | `configure(ConfigurationDocumentInterface): void`; clear first, fold/validate, publish only success                               |
| `ComputedMetricEvaluationInterface`        | `Analysis\Run\Pipeline\AnalysisPipeline`                                                                                                | `evaluate(MetricRepositoryInterface, int $filesAnalyzed): void`; mutate only the repository; zero files or definitions is a no-op |
| `ComputedMetricDefinitionCatalogInterface` | `Infrastructure\Rule\ChannelDeclarationRegistry`, `Infrastructure\Rule\RuleChannelRegistry`, `Reporting\Formatter\Html\HtmlTreeBuilder` | immutable current `ComputedMetricDefinition` list and exact-name lookup only                                                      |

`ComputedMetricChannelFamily` is a fourth immutable root contract containing
the single `computed.health` producer name. Exact consumer
`Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass`
uses it at compile time; the internal rule uses the same source of truth. The
compiler pass never imports the internal rule and no duplicate literal is
introduced.

The final 18 exact-source relations targeting root ComputedMetrics contracts
are:

| Target                                     | Exact source consumers                                                                                                                                           |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ComputedMetricConfiguratorInterface`      | `RuntimeConfigurator`                                                                                                                                            |
| `ComputedMetricEvaluationInterface`        | `AnalysisPipeline`                                                                                                                                               |
| `ComputedMetricDefinitionCatalogInterface` | `ChannelDeclarationRegistry`, `RuleChannelRegistry`, `HtmlTreeBuilder`, `HealthSummaryBuilder`, `HealthScoreDrillDown`, `WorstClassDrillDown`                    |
| `ComputedMetricChannelFamily`              | `ChannelDeclarationCompilerPass`                                                                                                                                 |
| `HealthFormulaExclusionInterface`          | `HealthFormulaExcluder`                                                                                                                                          |
| `ComputedMetricDefinition`                 | `HealthFormulaExcluder`                                                                                                                                          |
| `HealthDimension`                          | `HtmlMetricAggregator`, `JsonHealthSection`, `HealthBarRenderer`, `HealthFormulaExcluder`, `HealthSummaryBuilder`, `HealthScoreDrillDown`, `WorstClassDrillDown` |

Health publishes `Health\Contract\HealthSummaryBuilderInterface` (consumed
only by `Reporting\Health\SummaryEnricher`) and
`Health\Contract\HealthMetricMetadataProviderInterface` (consumed only by
`Reporting\Health\HealthHintProjector`). The former accepts the existing
Measurement repository/tree plus violation facts and returns immutable
`HealthSummary` values; the latter returns one immutable
`HealthMetricMetadataCollection`. These are exact-source permanent relations,
not owner-wide grants.

Health remains the distinct semantic owner
`Analysis.Evidence.ComputedMetrics.Health`. It may import only root Contract
declarations: `ComputedMetricDefinitionCatalogInterface`,
`ComputedMetricDefinition`, `HealthDimension`, and
`HealthFormulaExclusionInterface`; it never imports root internals.
`HealthDimension` moves to the root `Contract` because root defaults and
configuration use it and Health consumes it, preserving the one-way
Health -> root owner edge.

The final Reporting -> Health authority is exactly 16 exact-source relations:

| Target contract declaration             | Exact Reporting source consumers                                     |
| --------------------------------------- | -------------------------------------------------------------------- |
| `DecompositionItem`                     | `HealthTextFormatter`, `JsonHealthSection`, `HealthBarRenderer`      |
| `HealthContributor`                     | `JsonHealthSection`                                                  |
| `HealthScore`                           | `HealthTextFormatter`, `HealthBarRenderer`                           |
| `NamespaceDrillDown`                    | `HealthScoreResolver`, `JsonOffenderSection`, `OffenderListRenderer` |
| `WorstOffender`                         | `ViolationFilter`, `JsonOffenderSection`, `OffenderListRenderer`     |
| `HealthSummaryBuilderInterface`         | `SummaryEnricher`                                                    |
| `HealthSummary`                         | `SummaryEnricher`                                                    |
| `HealthMetricMetadataProviderInterface` | `HealthHintProjector`                                                |
| `HealthMetricMetadataCollection`        | `HealthHintProjector`                                                |

`ContributorRanker`, `HealthReasonBuilder`, `HealthMetricCatalog`, and all
other Health implementation declarations are internal. The manifest encodes
these 16 relations individually and adds no owner-wide Health consumer.

The moved internal `ComputedMetricRule` receives the same catalog instance from
DI; `ComputedMetricRuleOptions` retains only ordinary rule options and never
performs global lookup. No cross-owner consumer imports the concrete analysis.
Every new manifest import consumer is permanent exact-source with
`closes_in:null`; P5 adds zero owner-wide consumers.

P5-A temporarily authorizes exact consumer
`Analysis\Run\Enrichment\TransitionalMetricEnricher ->
ComputedMetricEvaluationInterface` with `closes_in:P5-C`. P5-C atomically
deletes that relation and source class and publishes the final permanent exact
`Analysis\Run\Pipeline\AnalysisPipeline` consumer. The intermediate manifest
has one temporary evaluation relation; the final manifest has one permanent
evaluation relation and zero P5 temporary consumers.

Phase order is frozen as discovery -> collection -> DependencyModel graph ->
Architecture preparation -> Measurement aggregation/global reaggregation ->
ComputedMetrics evaluation -> CircularDependency preparation -> FileSet
inspection -> rule execution. Configuration occurs before the run through
`RuntimeConfigurator`, after reset/logger setup and before pipeline invocation.
Calling `configure()` always clears prior definitions before parsing; a failed
configuration leaves the catalog empty. A successful second configuration
replaces the first catalog atomically.

Configuration folding preserves current layered behaviour exactly:

| Document case                                         | Result                                                              |
| ----------------------------------------------------- | ------------------------------------------------------------------- |
| source omits `computed_metrics`                       | keep the last contributed map                                       |
| source supplies `computed_metrics`, including `{}`    | replace the whole prior map; never deep merge                       |
| source omits `exclude_health`                         | retain accumulated exclusions                                       |
| source supplies `exclude_health`, including `[]`      | append in source order and stable-deduplicate; empty does not clear |
| `health.*.enabled: false` in the winning computed map | combine with accumulated exclusions before validation               |
| invalid later contribution                            | throw and publish no definitions from this or a previous run        |

Preset contributions participate in their original preset order, followed by
later stages. Focused multi-stage tests freeze replacement, union, ordering,
deduplication, empty and rollback behaviour.

`ComputedMetricsDocumentContributionIntegrationTest` adds exactly six IDs:
`itReplacesTheWholeComputedMapAtTheLatestContributingStage`,
`itKeepsThePreviousComputedMapWhenALaterStageOmitsTheKey`,
`itTreatsAnExplicitEmptyComputedMapAsReplacement`,
`itUnionsHealthExclusionsInStableSourceOrder`,
`itTreatsAnEmptyHealthExclusionListAsNoAdditionalEntries`, and
`itClearsPriorRunDefinitionsWhenALaterContributionIsInvalid`. Existing
`ConfigurationMergerTest::mergeableListKeysAreUnionedAndDeduplicated` loses
only its feature-specific `exclude_health` assertions (same test ID), because
that fold moves to ComputedMetrics.

The exact internal DAG zones are: `Contract` contains definition/read DTOs and
depends only on Configuration and Measurement contracts plus neutral
Symbol/Finding types; root
formula/evaluation depends on Contract, Measurement and neutral types; `Health`
depends on root Contract, Measurement and neutral types; the rule
implementation depends on root Contract plus Finding and neutral types.
For computed/health imports, Reporting depends only on root/Health contracts.
Reverse, unknown-zone,
taxonomy-parent and cross-owner-internal imports are negative probes.

**DoD:** the exact contracts, consumers, phase/fold tables, declaration
dispositions, DAG and negative probes above are encoded in manifest/governance
tests before moves; no new owner-wide relation exists; final plan re-review
returns GO.

## P5-A — Instance-owned definitions and configuration

Move definitions, defaults, validation, dependency ordering, evaluation and
configuration resolution to `src/Analysis/Evidence/ComputedMetrics`. Replace
`ComputedMetricDefinitionHolder` atomically: introduce the catalog, migrate all
seven production sites, their DI and tests, then delete the holder in this same
package. `TransitionalMetricEnricher` temporarily consumes the evaluation
contract until P5-C deletes the shell; no facade remains between
packages.
Configuration is atomic: clear previous run state, parse ordered
`computed_metrics` contributions from `ConfigurationDocumentInterface`, apply
defaults/overrides, validate the whole graph, and publish only success. Failure
on run B must not leave run A definitions active.

The root-owned analysis folds ordered `exclude_health` string values and passes
the resulting list to the distinct root-owned
`HealthFormulaExclusionInterface`, whose implementation remains Health-owned.
No Health-owned configuration DTO crosses back into the root, so the owner DAG
stays acyclic. ComputedMetrics parses both document nodes; remove
`TransitionalResolvedConfiguration::$computedMetrics`,
`TransitionalRuntimeConfiguration::$excludeHealth` and their result-field
mappings while retaining the public YAML/CLI keys as document contributions.

Move the nine root tests and replace the holder test with catalog lifecycle
tests. This package also updates holder consumers/tests formerly listed in
P5-D. Cover A -> B sequential configuration, failure after A, absence,
`enabled:false`, explicit/deduplicated exclusions, syntax errors, unknown
references and cycles.

**DoD:** no mutable static computed state or computed fields in transitional
Configuration DTOs; the capability parses its own document nodes; focused
resolver/catalog/evaluator tests and PHPStan pass.

## P5-B — Health ownership

Move the six manifest Health declarations plus `HealthReasonBuilder`; replace
`MetricHintProvider` with the method-level split in P5-0. Extract the named
methods from `SummaryEnricher` into `HealthSummaryBuilder`; retain
`HealthScoreResolver` wholly in Reporting. Preserve the narrow Health values
read by Reporting.

Reporting retains formatters, formatter-context routing, `Report` assembly and
Debt/Impact orchestration. Move/rename the eight wholly Health-owned test
classes (`ContributorRanker`, `DecompositionItem`, `HealthContributor`,
`HealthReasonBuilder`, `HealthScore`, `NamespaceDrillDown`,
`ViolationDensity`, `WorstOffender`) plus their support. Split
`MetricHintProviderTest` into `HealthMetricCatalogTest` and
`HealthHintProjectorTest`; split `SummaryEnricherTest` into
`HealthSummaryBuilderTest` and the retained Reporting assembly test; retain
`HealthScoreResolverTest` in Reporting. Existing test methods retain their IDs
under the responsible class. `ComputedMetricDefinitionHolderTest` becomes
`ComputedMetricAnalysisTest` with the same four IDs. New IDs are the three
methods in `ComputedMetricsInternalTopologyTest` (materialized DAG, reverse-edge
probe, unknown/internal-edge probe) and the six document-contribution IDs named
in P5-A. The finite affected target is therefore **24 PHPUnit classes / 278 IDs
/ one support / zero fixtures**: the retained 20 classes and 269 IDs, plus two
classes created by the two test splits without ID growth, one document class
with six IDs, and one topology class with three IDs. Governance freezes this
arithmetic before source publication.

P5-B materializes the two method-level test splits without changing their
retained IDs: its exact input slice is **11 classes / 115 IDs** and its current
output is **13 classes / 115 IDs**.

P5-C removes the six mixed `MetricEnricherTest` IDs and adds two exact Run
pipeline IDs. The complete P5-owned slice therefore moves from **23 classes /
279 IDs** to **23 classes / 275 IDs**. P5-D adds the
`ComputedMetricsInternalTopologyTest`, contributing one class and three IDs,
so materialized authority and the reviewed target are both exactly **24 classes /
278 IDs**.

**DoD:** both seams are gone; Health imports no Reporting type; Reporting
imports no ComputedMetrics internal; project/namespace/class outputs retain
their regressions.

## P5-C — Run integration and shell deletion

Apply the P5-0 phase contract in `AnalysisPipeline` and
`AnalysisConfigurator`. Preserve the complete frozen order: DependencyModel
graph, Architecture preparation, Measurement aggregation/global
reaggregation, ComputedMetrics evaluation, CircularDependency preparation,
FileSet inspection, then rule execution. Delete both
transitional enrichment classes and their DI constants/registration. Pass
`NamespaceTree` directly from Measurement to `AnalysisContext` and result.

Replace mixed `MetricEnricherTest` coverage with owner-specific phase tests;
update `AnalysisPipelineTest`, `AnalysisPipelineIntegrationTest` and
`TestPipelineBuilder` for order, zero files, disabled producers and reset.

**DoD:** Run has no computed configuration/state and neither transitional
class; the selected P5-0 contract is the only new edge; pipeline tests pass.

## P5-D — Remaining composition and retained regressions

Complete `AnalysisConfigurator`, `OutputConfigurator`, and compiler-pass
composition after the holder consumers were migrated atomically in P5-A. The
compiler pass imports `ComputedMetrics\Contract\ComputedMetricChannelFamily`
as the sole compile-time source of the producer name and never imports the
internal rule class.

Retained integration/output regressions are exactly:
`RuntimeConfiguratorTest`, `ChannelDeclarationRegistryTest`,
`RuleChannelRegistryTest`, `HtmlTreeBuilderTest`, `GoldenFileAggregationTest`,
`BaselineMeasuredSetSeamTest`, `BaselineRunBeforeLoadTest`,
`ContainerFactoryTest`, `ChannelCoverageTest`,
`ChannelEmissionStaticGuardTest`, `ResultPresenterTest`,
`JsonShapePreservationTest`, `HealthTextFormatterTest`, `HtmlFormatterTest`,
`JsonHealthSectionTest`, `JsonOffenderSectionDensityTest`, `JsonFormatterTest`,
`HealthBarRendererTest`, `HintRendererTest`,
`OffenderListRendererDensityTest`, and `SummaryFormatterTest`. Cross-owner
tests obtain public contracts, never internal implementations.

The finite retained Configuration transport set is
`YamlNormalizationCharacterizationTest`, `YamlKeyReachabilityTest`,
`AnalysisConfigurationTest`, `ConfigSchemaTest`, `YamlConfigLoaderTest`,
`ConfigDataNormalizerTest`, `ConfigurationMergerTest`, and
`ConfigurationPipelineTest`; these prove that the public keys remain reachable
while Configuration no longer materializes computed feature fields.

**DoD:** `rg ComputedMetricDefinitionHolder src tests` is empty; both grants
are removable; DI exposes only approved contracts; all listed regressions pass.

## P5-E — Final governance reconciliation, docs and serial publication

P5-0 owns the explicitly non-current `p5_target` projection, its schema,
generator validation and governance-source assertions. Production packages
P5-A through P5-D are then serial current-authority manifest writers: each
package re-reads the manifest left by its predecessor and atomically changes
only the exact declaration rows, contract relations, temporary consumers,
grants and seams materialized by that package's source changes. They never
write generated inventories, `qmx.yaml`, the baseline or PHPUnit discovery.

- P5-A materializes the root contracts/implementation, holder replacement,
  configuration/exclusion rows and the temporary exact
  `TransitionalMetricEnricher` evaluation consumer.
- P5-B materializes the Health/Reporting split, its exact 16 Reporting-to-Health imports
  and both seam closures.
- P5-C replaces the temporary evaluation consumer with the permanent exact
  `AnalysisPipeline` relation while deleting the transitional shell.
- P5-D closes the two named internal grants and reconciles the remaining exact
  composition-root relations.

These packages are never parallel manifest writers. P5-E is the sole generated
artifact/final-publication writer: it reconciles the fully materialized current
manifest against `p5_target`, removes the now-obsolete non-current projection
and its transitional schema/generator validation, then publishes inventory
generators, governance tests, `qmx.yaml`, `qmx-baseline.json`,
`phpunit.xml.dist`, and all generated modular-architecture artifacts. It updates
the overview, this plan, ADR 0022 current state, `docs/ARCHITECTURE.md`, the new
module README, and affected Analysis/Configuration/Run/Core/Rules/Reporting
READMEs. Update `CHANGELOG.md` only for an intentional user-facing contract
change.

The authoritative P5 documentation rows are explicitly updated in this package:
`docs/adr/0001-computed-metrics.md`,
`website/docs/reference/health-scores.md`, and
`website/docs/reference/health-scores.ru.md`. They remain at their paths and
document the landed owner/lifecycle without changing user-facing semantics.
The documentation ownership regression asserts all three rows plus this plan.

Generate twice after all writers are idle and require byte identity. Re-key a
baseline row only for the same moved semantic finding; never add new debt or
bulk-regenerate.

**DoD:** architecture and freshness checks pass; P5 rows are materialized;
seams/grants are absent; live/generated PHPUnit discovery matches; docs links,
leak check and zero-warning dogfood pass.

The first P5-E publication did not meet this DoD. Zero stale baseline rows was
mistaken for a clean run after redirected stdout hid the process status. The
authoritative command exited 2 with 26 active findings. The completion and
zero-warning claims are retracted.

## P5-F — Subject-cohesion remediation after failed aggregate

P5-F changes no formula, YAML key, report field, threshold or finding channel.
It adds no qmx exclusion and no baseline row. The frozen 32-declaration target
is revised because retaining it would preserve the monoliths found by aggregate.

The first corrective-plan review is resolved as follows: (1) all external
surfaces remain under subject-nested Contract trees; (2) one finite
`ComputedMetricsConfigurator` owns composition; (3) every serial source package
has an immediate manifest checkpoint; (4) the concrete evaluator contract is
fully specified without an undeclared engine; (5) logger extraction adds one
honest test ID while the end-to-end warning test stays put; and (6) baseline
authority is the immutable `57fa22fa` ledger. The second-round composition
finding is also addressed by the four-alias table below. F1/F2 measurement
later proved that the ledger's former six-row limit was incomplete; the finite
reconciliation below replaces that limit and awaits review before execution
continues.

### Finite production delta and contract ownership

Current authority is 732 declarations in 730 files. Eleven additions and three
deletions tentatively yield **740 declarations in 738 files**; owners remain
37. Generation is authoritative and execution stops if `+11 - 3 = +8` is not
proved for both declarations and files.

| Change | Declaration                                                                   | Owner                              | Visibility / exact external consumers                                    | Subject                                    |
| ------ | ----------------------------------------------------------------------------- | ---------------------------------- | ------------------------------------------------------------------------ | ------------------------------------------ |
| add    | `ComputedMetrics\Configuration\ComputedMetricContributionReader`              | ComputedMetrics                    | internal                                                                 | ordered feature contributions              |
| add    | `ComputedMetrics\Finding\ComputedMetricFindingBuilder`                        | ComputedMetrics                    | internal                                                                 | severity, message and finding construction |
| add    | `Health\Metadata\MetricHintCatalog`                                           | ComputedMetrics.Health             | internal                                                                 | metric hints, ranges and explanations      |
| add    | `Health\Metadata\HealthDimensionCatalog`                                      | ComputedMetrics.Health             | internal                                                                 | decomposition and health labels            |
| add    | `Health\Contract\DrillDown\HealthScoreDrillDown`                              | ComputedMetrics.Health             | contract-visible concrete; `HealthScoreResolver`                         | namespace/class score queries              |
| add    | `Health\Contract\DrillDown\WorstClassDrillDown`                               | ComputedMetrics.Health             | contract-visible concrete; `JsonOffenderSection`, `OffenderListRenderer` | namespace worst-class query                |
| add    | `Health\Offender\WorstOffenderEvidence`                                       | ComputedMetrics.Health             | internal                                                                 | counts, metrics, scores and density        |
| add    | `Health\Offender\WorstOffenderBuilder`                                        | ComputedMetrics.Health             | internal                                                                 | measured-symbol offender projection        |
| add    | `Analysis\Run\RuleProducerPreparation`                                        | Analysis.Run                       | internal                                                                 | rule-producer gating/reset                 |
| add    | `Infrastructure\Console\RuntimeLoggerConfigurator`                            | Infrastructure.Console             | internal                                                                 | logger creation/publication                |
| add    | `Infrastructure\DependencyInjection\Configurator\ComputedMetricsConfigurator` | Infrastructure.DependencyInjection | internal                                                                 | exact capability registration and aliases  |
| delete | `Contract\ComputedMetricEvaluationInterface`                                  | ComputedMetrics                    | former contract                                                          | redundant one-implementation surface       |
| delete | `Health\Contract\HealthSummaryBuilderInterface`                               | ComputedMetrics.Health             | former contract                                                          | redundant one-implementation surface       |
| delete | `Health\Contract\NamespaceDrillDown`                                          | ComputedMetrics.Health             | former contract                                                          | behavior incorrectly placed in Contract    |

The existing `ComputedMetricEvaluator` moves to
`Contract\Evaluation\ComputedMetricEvaluator` and becomes the contract-visible
concrete final service consumed exactly by `AnalysisPipeline`. It exposes
`evaluate(MetricRepositoryInterface, int $filesAnalyzed): void`, injects the
definition catalog, reads `all()` exactly once into a local immutable snapshot,
and is a no-op for zero files or an empty snapshot. It owns the existing
`computed` profiler span around formula evaluation. Its current compute engine
is retained inside this same declaration; no second evaluator/engine class is
introduced. `ComputedMetricAnalysis` retains atomic catalog publication and no
longer imports the repository or evaluator. The existing zero-files pipeline
test continues to assert the concrete service call and a focused evaluator test
freezes both no-op cases, snapshot use and profiler ownership.

The existing `HealthSummaryBuilder` moves to
`Health\Contract\Summary\HealthSummaryBuilder` and becomes contract-visible,
consumed exactly by Reporting's `SummaryEnricher`. Concrete services are
cleaner in both cases because each promises one final stateless operation and
has one implementation; retaining interfaces would add Measurement consumers
without isolating a replaceable strategy.
`ComputedMetricDefinitionCatalogInterface` remains because four consumers need
a narrow immutable read view over mutable state.
`HealthMetricMetadataProviderInterface` remains because Reporting consumes only
the immutable projection, not the broader Health lookup API. Before deletion,
execution re-enumerates implementations and consumers; discovery of a second
real implementation or consumer-owned port requires revising this table first.

`WorstOffenderEvidence` stays internal. `WorstOffender` encapsulates it and
keeps Reporting-facing reads, so Reporting continues to import only
`WorstOffender`. `WorstOffenderBuilder` is the named Health projection subject,
not a generic helper.

### Every namespace move and preserved external surface

All contract-visible declarations remain below a `Contract/**` subtree, as
required by ADR 0022. Internal collaborators use subject folders outside
Contract. External modules import only the exact Contract declarations below.
These are moves, not additions, unless listed in the delta table:

| Current                                                 | Final subject namespace                                          |
| ------------------------------------------------------- | ---------------------------------------------------------------- |
| `Contract\ComputedMetricChannelFamily`                  | `Contract\Finding\ComputedMetricChannelFamily`                   |
| `Contract\ComputedMetricConfiguratorInterface`          | `Contract\Configuration\ComputedMetricConfiguratorInterface`     |
| `Contract\ComputedMetricDefinition`                     | `Contract\Definition\ComputedMetricDefinition`                   |
| `Contract\ComputedMetricDefinitionCatalogInterface`     | `Contract\Definition\ComputedMetricDefinitionCatalogInterface`   |
| `Contract\HealthDimension`                              | `Contract\Definition\HealthDimension`                            |
| `Contract\HealthFormulaExclusionInterface`              | `Contract\Configuration\HealthFormulaExclusionInterface`         |
| root `ComputedMetricEvaluator`                          | `Contract\Evaluation\ComputedMetricEvaluator`                    |
| Health `HealthFormulaExcluder`                          | `Health\Configuration\HealthFormulaExcluder`                     |
| `Health\Contract\DecompositionItem`                     | `Health\Contract\Score\DecompositionItem`                        |
| `Health\Contract\HealthContributor`                     | `Health\Contract\Score\HealthContributor`                        |
| `Health\Contract\HealthMetricMetadataCollection`        | `Health\Contract\Metadata\HealthMetricMetadataCollection`        |
| `Health\Contract\HealthMetricMetadataProviderInterface` | `Health\Contract\Metadata\HealthMetricMetadataProviderInterface` |
| `Health\Contract\HealthScore`                           | `Health\Contract\Score\HealthScore`                              |
| `Health\Contract\HealthSummary`                         | `Health\Contract\Summary\HealthSummary`                          |
| `Health\Contract\WorstOffender`                         | `Health\Contract\Offender\WorstOffender`                         |
| Health `HealthMetricCatalog`                            | `Health\Metadata\HealthMetricCatalog` facade                     |
| Health `ContributorRanker`                              | `Health\Score\ContributorRanker`                                 |
| Health `HealthReasonBuilder`                            | `Health\Offender\HealthReasonBuilder`                            |
| Health `HealthSummaryBuilder`                           | `Health\Contract\Summary\HealthSummaryBuilder`                   |

The three deleted declarations have no move. Other ComputedMetrics declarations
stay at their current subject paths.

The final manifest-authorized surface retains exactly 34 cross-owner Contract
consumer relations:

| Final Contract target                                               | Exact external source consumers                                                                                                                                                       |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| root `Contract\Configuration\ComputedMetricConfiguratorInterface`   | `RuntimeConfigurator`                                                                                                                                                                 |
| root `Contract\Definition\ComputedMetricDefinitionCatalogInterface` | `ChannelDeclarationRegistry`, `RuleChannelRegistry`, `HtmlTreeBuilder`, Health `Contract\Summary\HealthSummaryBuilder`, `HealthScoreDrillDown`, `WorstClassDrillDown`                 |
| root `Contract\Definition\ComputedMetricDefinition`                 | Health `Configuration\HealthFormulaExcluder`                                                                                                                                          |
| root `Contract\Definition\HealthDimension`                          | `HtmlMetricAggregator`, `JsonHealthSection`, `HealthBarRenderer`, Health `Configuration\HealthFormulaExcluder`, `HealthSummaryBuilder`, `HealthScoreDrillDown`, `WorstClassDrillDown` |
| root `Contract\Configuration\HealthFormulaExclusionInterface`       | Health `Configuration\HealthFormulaExcluder`                                                                                                                                          |
| root `Contract\Finding\ComputedMetricChannelFamily`                 | `ChannelDeclarationCompilerPass`                                                                                                                                                      |
| root `Contract\Evaluation\ComputedMetricEvaluator`                  | `AnalysisPipeline`                                                                                                                                                                    |
| Health `Contract\Score\DecompositionItem`                           | `HealthTextFormatter`, `JsonHealthSection`, `HealthBarRenderer`                                                                                                                       |
| Health `Contract\Score\HealthContributor`                           | `JsonHealthSection`                                                                                                                                                                   |
| Health `Contract\Score\HealthScore`                                 | `HealthTextFormatter`, `HealthBarRenderer`                                                                                                                                            |
| Health `Contract\Summary\HealthSummaryBuilder`                      | Reporting `SummaryEnricher`                                                                                                                                                           |
| Health `Contract\Summary\HealthSummary`                             | Reporting `SummaryEnricher`                                                                                                                                                           |
| Health `Contract\Metadata\HealthMetricMetadataProviderInterface`    | `HealthHintProjector`                                                                                                                                                                 |
| Health `Contract\Metadata\HealthMetricMetadataCollection`           | `HealthHintProjector`                                                                                                                                                                 |
| Health `Contract\Offender\WorstOffender`                            | `ViolationFilter`, `JsonOffenderSection`, `OffenderListRenderer`                                                                                                                      |
| Health `Contract\DrillDown\HealthScoreDrillDown`                    | `HealthScoreResolver`                                                                                                                                                                 |
| Health `Contract\DrillDown\WorstClassDrillDown`                     | `JsonOffenderSection`, `OffenderListRenderer`                                                                                                                                         |

The root-target rows contain 18 exact relations and the Health-target rows 16.
The former 28-row review table undercounted four already-live Health-to-root
imports (`HealthFormulaExcluder` and `HealthSummaryBuilder` each importing
`HealthDimension`) and two relations introduced when the former drill-down was
split (`HealthScoreDrillDown` and `WorstClassDrillDown` each importing the
definition catalog and `HealthDimension`, replacing one consumer with two).
No facade is introduced to preserve the obsolete count. The complete raw AST
surface is 39 imports: these 34 manifest consumer relations plus exactly five
carrier/composition imports frozen by topology. No external source imports an
internal subject folder, and no owner-wide consumer is added.

### DAG, Run and Console mechanisms

- Root internal Configuration -> Analysis Configuration + Contract/Definition;
  Contract/Evaluation -> Contract/Definition + Measurement; internal Finding
  -> Contract/Definition + Analysis Finding/Core. Contract/Finding contains
  only the externally read channel family.
- Health internal Metadata -> root Contract/Definition; internal Score ->
  Contract/Metadata + root Contract/Definition; internal Offender ->
  Contract/Score + Contract/Metadata + Measurement/Core. Contract/DrillDown ->
  Contract/Offender + Contract/Score + Contract/Metadata + root
  Contract/Definition + Measurement; Contract/Summary -> Contract/Score +
  Contract/Offender + Contract/Metadata + root Contract/Definition +
  Measurement. Reporting imports only these exact Contract declarations.
  Health -> root remains one-way.
- `RuleProducerPreparation` receives `LayerPolicyPreparationInterface`,
  `CircularDependencyPreparationInterface`, `FileSetInspectionComposite` and
  `RuleSelector`, with exact `prepareArchitecture`,
  `prepareCircularDependencies` and `inspectFiles` operations. Exact consumers
  of the first two contracts move from `AnalysisPipeline` to this internal Run
  service. Aggregation/evaluation remain direct pipeline phases, preserving
  graph -> architecture -> aggregation -> computed -> circular -> inspection
  -> rules. Four dependencies become one; pipeline constructor 14 -> 11.
- `RuntimeLoggerConfigurator` receives `LoggerFactory` and `LoggerHolder`,
  publishes and returns the logger. RuntimeConfigurator replaces those two
  dependencies with one, 8 -> 7, preserving reset -> logger -> Architecture ->
  ComputedMetrics. It is an adapter, not a locator.

`ComputedMetricsConfigurator` becomes the single composition owner for this
capability. Registration responsibilities move as a finite set:

| From                                 | Responsibility moved to `ComputedMetricsConfigurator`                                                                                                                                                                                                                                                                                                                                                                 | What remains                                                                                          |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `AnalysisConfigurator`               | registration of the root ComputedMetrics implementation tree and the former evaluation alias                                                                                                                                                                                                                                                                                                                          | only the named `AnalysisPipeline` consumer reference to `Contract\Evaluation\ComputedMetricEvaluator` |
| `OutputConfigurator`                 | registration of the Health implementation/contract trees and metadata/summary/drill-down aliases                                                                                                                                                                                                                                                                                                                      | only named Reporting consumer references to Health `Contract/**` declarations                         |
| `RuleConfigurator`                   | exact registration of capability-owned `ComputedMetricRule` and its options                                                                                                                                                                                                                                                                                                                                           | generic rule autoconfiguration/tag/compiler-pass machinery; no ComputedMetrics class reference        |
| existing ComputedMetrics composition | four exact aliases: `Contract\Configuration\ComputedMetricConfiguratorInterface -> ComputedMetricAnalysis`; `Contract\Definition\ComputedMetricDefinitionCatalogInterface -> ComputedMetricAnalysis`; `Health\Contract\Metadata\HealthMetricMetadataProviderInterface -> Health\Metadata\HealthMetricCatalog`; `Contract\Configuration\HealthFormulaExclusionInterface -> Health\Configuration\HealthFormulaExcluder` | no duplicate aliases elsewhere; `ComputedMetricsConfigResolver` receives the fourth alias             |

The new configurator registers exact root and Health subject roots, the
capability-owned rule, and exactly the four named aliases above. It does not
publish internal implementations beyond those aliases. Existing
`ContainerFactoryTest` service, alias and single-instance assertions are
extended to cover it. The test must resolve
`Contract\Configuration\HealthFormulaExclusionInterface`, prove it is the same
`Health\Configuration\HealthFormulaExcluder` instance wired into
`ComputedMetricsConfigResolver`, and thereby reject a duplicate excluder or an
accidental fallback implementation. This extends an existing test ID; no
dedicated configurator test class or test ID is added.

### Measurement acceptance rule

P5-F rejects Measurement threshold/headroom changes. Deleting evaluation and
summary interfaces removes two `MetricRepositoryInterface` consumers; removing
repository use from `ComputedMetricAnalysis` removes a third; splitting one
drill-down consumer into two adds one: expected net **-2**. The offender builder
accepts `MetricBag`/symbol evidence, never the repository. MetricName and
AggregationStrategy remain honest summary/drill-down dependencies.

Remeasure namespace CBO, class CBO and ClassRank after source changes. The
accepted namespace ceiling stays 48. If consumer reduction does not clear the
Measurement findings, stop with the measured graph: do not raise threshold or
baseline and do not add a facade/context/query wrapper to hide the dependency.

### Exact test migration and arithmetic

Existing behavioral cases are moved, not replaced with fake tests:

- Rename `ComputedMetricsDocumentContributionIntegrationTest` to
  `ComputedMetricContributionReaderTest`, retaining its six `itReplaces...`,
  `itKeeps...`, `itTreats...`, `itUnions...`, `itTreats...`, and `itClears...`
  document methods exactly.
- Split `HealthMetricCatalogTest`: `MetricHintCatalogTest` receives every
  `itGetLabel*`, `itGetExplanation*`, `itGetGoodValue*`, `itGetDirection*`;
  `HealthDimensionCatalogTest` receives both `itGetDecomposition*`, expanded
  `itGetScoreLabel`, and all three `itGetHealthDimensionLabel*` methods.
- Split `NamespaceDrillDownTest`: `HealthScoreDrillDownTest` receives all six
  `itSubtreeHealthScores*` and four `itBuildClassHealthScores*`; the seven
  `itBuildWorstClasses*` methods move to `WorstClassDrillDownTest`.
- Split `ComputedMetricRuleTest`: `ComputedMetricFindingBuilderTest` receives
  the five normal/inverted threshold outcome methods plus
  `itFormatsViolationMessageCorrectly`, `itSetsViolationCodeToDefinitionName`,
  `itRoundsMetricValueToOneDecimal`, both `itUsesAbove/Below*`,
  `itIncludesDimensionScoreAndThresholdInRecommendation`,
  `itExtractsDimensionLabelInRecommendation`,
  `itCarriesThresholdFieldInViolation`, and expanded
  `itHasDimensionSpecificRecommendation`. Traversal, levels, locations,
  duplicates, disabled and no-threshold cases remain in the rule test.
- `RuleProducerPreparationTest` receives existing pipeline methods
  `itSkipsCircularDependencyDetectionWhenRuleDisabled`,
  `itResetsArchitecturePreparationWithoutDoingWorkWhenLayerViolationRuleIsDisabled`,
  `itPreparesArchitecturePolicyWhenLayerViolationRuleIsEnabled`,
  `itResetsCircularDependencyPreparationWithoutDoingWorkWhenRuleIsDisabled`,
  and `itPreparesTheArchitectureProducerWhenOnlyADiagnosticChannelIsSelected`.
  Exact pipeline-order coverage remains in `AnalysisPipelineTest`; the eight
  `FileSetInspectionCompositeTest` reset/gating/order/failure cases remain.
- `architectureWarningsAreLoggedThroughConfiguredLogger` remains end-to-end in
  `RuntimeConfiguratorTest`, together with
  `emptyArchitectureDocumentProducesNoLogOutput` and
  `architectureWarningsUseWarningLevel`. Fresh inventory proves there is no
  existing logger-only test method to move honestly. New
  `RuntimeLoggerConfiguratorTest::itCreatesPublishesAndReturnsTheSameLogger`
  is therefore one real new behavioral ID covering the extracted adapter.

The Health/root splits add three classes; Run and Infrastructure add one each.
Tentative global discovery is **508 classes / 7,242 expanded IDs**
(`503 + 3 + 1 + 1` classes and one honest logger ID). The ComputedMetrics/Health
test slice becomes 27 classes / 278 IDs / one support / zero fixtures. The
current 29 ComputedMetrics/Health production declarations become 34
(`29 - 3 + 8`); including the three retained Reporting declarations, the
revised ComputedMetrics/Health scope is 37. Adding the new Run, RuntimeLogger
and DI configurator declarations makes the complete corrective P5-F review
scope 40 declarations. Live semantic discovery must prove all arithmetic
before publication. Extend the existing three
ComputedMetrics topology IDs to
enumerate all final declarations/visibility/consumers, all DAG directions, and
reverse/unknown/cross-owner rejection; add no topology-only test IDs.

P5-F is serial and has one designated governance writer. After **each** source
subpackage, that writer reconciles the current manifest immediately, runs
source/manifest isolation checks, and leaves both green before the next source
subpackage starts:

1. P5-F1 root ComputedMetrics source/tests -> root declaration, visibility,
   consumer and DAG rows -> isolated root tests plus manifest check.
2. P5-F2 Health/Reporting source/tests -> Health moves, exact Reporting
   consumers and DAG rows -> Health/output regressions plus manifest check.
3. P5-F2.1 ComputedMetrics/Health metric closure -> class/method findings,
   immutable-ledger comparison and full-source measurement -> zero new or
   regressed package findings before Run work starts.
4. P5-F3 Run source/tests -> RuleProducerPreparation and changed exact
   preparation/evaluation consumers -> Run tests plus manifest check.
5. P5-F4 Console/DI source/tests -> RuntimeLoggerConfigurator,
   ComputedMetricsConfigurator, registrations and aliases -> Console/container
   tests plus manifest check.
6. Only after F1-F4 are green and idle, the same governance writer updates
   materialized generator assertions, governance tests and module README.

**P5-F1 current-authority checkpoint (2026-08-13):** the root source package is
materialized at 733 production declarations in 731 files: ComputedMetrics owns
16 root declarations and Health retains its 14 declarations. The concrete
`Contract\Evaluation\ComputedMetricEvaluator` replaces the deleted evaluation
interface; `Configuration\ComputedMetricContributionReader` and
`Finding\ComputedMetricFindingBuilder` are the two additions. Live PHPUnit
discovery is 504 classes / 7,244 expanded IDs (`503 + 1`, `7,241 + 3`): the
contribution class is a rename, the finding split adds one class without moving
or duplicating its existing IDs, and evaluator lifecycle coverage adds exactly
three IDs. The current manifest and isolated production checker agree; P5-G
publication artifacts and generated PHPUnit discovery intentionally remain a
later serial responsibility.

**P5-F2 current-authority checkpoint (2026-08-13):** Health/Reporting is
materialized at 737 production declarations in 735 files: ComputedMetrics owns
16 root declarations and Health owns 18. F2 adds six declarations, deletes two,
and moves the remaining Health surface, for a net `733 + 6 - 2 = 737`; the
whole-plan final remains 740/738 after F3 +1 and F4 +2. The P5 boundary has 34
manifest-authorized cross-owner Contract consumer relations (18 targeting root,
16 targeting Health) and five separately classified carrier/composition
imports. Live discovery is 507 PHPUnit classes / 7,244 expanded IDs: the
catalog, drill-down, and offender-evidence splits add three classes while
preserving every existing semantic ID. The narrow 34-relation invariant
correction is implemented. This structural checkpoint was held open for
P5-F2.1; the closure record below supersedes that temporary state.

`ContainerFactoryTest` is touched only in F4, avoiding overlapping Run/Console
writes. No P5-F subpackage writes generated artifacts, qmx, baseline or PHPUnit
projection. Each runs focused tests, lint, scoped PHPStan and scoped CS; no
source package may hand an inconsistent manifest to its successor.

### P5-F2.1 — Metric-ledger closure before Run work

The authoritative full-source `--workers=0` measurement after the structural
F2 checkpoint contained exactly 37 root ComputedMetrics/Health rows. Its
historical accounting was `20 proven pre-P5 identities + 1 unverified
namespace candidate + 16 new/regressed rows = 37`. Review rejected the
namespace candidate; source closure resolved three of the 20 immutable rows
and all 16 new/regressed rows. P5-F2.1 changes no baseline, qmx exclusion or
threshold and preserves the 737/735 production checkpoint, 507/7,244 test
checkpoint, 34 manifest relations and five carrier/composition imports unless
a reviewed subject change proves different arithmetic.

The class/method closure set is finite:

| Current subject                                                   | Channels / current values                                                                   | Required action                                                                                                                                                         |
| ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Configuration\ComputedMetricContributionReader::read`            | cognitive 17; cyclomatic 11                                                                 | split contribution parsing into subject-named operations; do not move the same branching into another declaration                                                       |
| `Health\Score\ContributorRanker::rank`                            | cognitive 15; cyclomatic 10                                                                 | separate candidate measurement from deterministic ranking while preserving ordering and missing-metric behavior                                                         |
| `ComputedMetricAnalysis`                                          | data-class 100                                                                              | restore cohesive catalog-publication behavior rather than adding cosmetic accessors or a suppression                                                                    |
| `Health\Metadata\HealthDimensionCatalog::getHealthDimensionLabel` | boolean argument 1                                                                          | remove the duplicate boolean surface introduced by the facade split; expose subject-named label operations                                                              |
| `Health\Contract\DrillDown\WorstClassDrillDown`                   | cohesion 40; instability 0.8666666667, regressed from the immutable predecessor ceiling 0.8 | reduce outgoing responsibilities through the existing Health offender/metadata subjects and make the drill-down operation cohesive; do not add a facade to hide imports |
| `Health\Metadata\HealthDimensionCatalog`                          | cohesion 45.4                                                                               | keep only dimension decomposition/label metadata that co-changes; move no behavior merely to chase a number                                                             |

Every category-B method in the immutable table below is also remeasured. If an
extraction changes its semantics or raises its magnitude above the immutable
ceiling, it is removed from the rekey set and fixed as a regression.

Namespace signals are not automatically structural exceptions. The completed
narrow design reviews authorize P5-G to add exactly nine channel-scoped
namespace exclusions and no capability-wide/category-wide exclusion:

| Exact full namespace                                                      | Exact excluded channel                | Reviewed reason                                                                                                                                                                        |
| ------------------------------------------------------------------------- | ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract`                  | `computed.health#health.cohesion`     | navigation-only union of independently owned subject contracts                                                                                                                         |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition`       | `computed.health#health.cohesion`     | immutable definition values plus their narrow catalog read contract have structural value-object/interface cohesion                                                                    |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract`           | `computed.health#health.cohesion`     | navigation-only union of Health contract subjects                                                                                                                                      |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown` | `computed.health#health.cohesion`     | two independently consumed concrete query contracts share no mutable state                                                                                                             |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender`           | `computed.health#health.cohesion`     | evidence value, reason projection and offender construction are one subject with intentionally disjoint public surfaces                                                                |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score`     | `coupling.distance#coupling.distance` | three immutable output values are stable contract leaves by construction                                                                                                               |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata`           | `coupling.distance#coupling.distance` | internal catalogs are concrete stable metadata providers, not an abstract extension point                                                                                              |
| `Qualimetrix\Analysis\Configuration\Contract`                             | `coupling.cbo#coupling.cbo.namespace` | namespace CBO union grew 23 -> 24 solely because four broad historical consumer namespaces became five mandatory ADR 0022 named capability consumers; exact class CBO remains enforced |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender`           | `coupling.distance#coupling.distance` | internal concrete evidence/reason/building projection subject has A=0 by design; moving its DTO to public contract would widen visibility without an external production consumer      |

The former tentative mapping from immutable `ns:Qualimetrix\Core\ComputedMetric`
cohesion 46.7 to current `Contract\Definition` cohesion 30 is rejected: exact
member-set equivalence is not proved. The old row is resolved; the new signal
is the exact structural exclusion above, never a baseline rekey. Root
`Configuration` maintainability already cleared. Health `Configuration`
maintainability 49.6 is a real issue: decompose `HealthFormulaExcluder`'s
normalization/validation, filtering/overall lookup and overall reconstruction,
then require that signal to disappear. This review is final for the exact
nine-row set; a changed namespace/channel set requires another narrow review.

**DoD:** the six class/method closure subjects have no new/regressed channels;
no immutable-ledger magnitude is exceeded; namespace measurement is green or
has completed the required narrow design review and an amended finite plan;
focused behavior, static checks, current manifest and inventory remain green;
F2 then closes before F3 begins.

**P5-F2.1 closure checkpoint (2026-08-13):** authoritative full-source
`--workers=0` measurement exited 2 with 298 repository-wide findings, as
expected before P5-G baseline/exclusion publication. The F1/F2 code-finding set
contains zero new or regressed class/method rows and Health `Configuration`
maintainability is absent. The measurement retains 17 root/Health immutable
rows plus Reporting's active Summary instability row; three immutable
HealthFormulaExcluder rows are resolved. Exactly seven reviewed structural
namespace signals await P5-G. Focused execution is 348 tests / 1,319 assertions; live inventory remains
737/735 and 507 classes / 7,244 IDs with the 34 + five relation classification.
PHPStan, CS, lint, topology, inventories and diff checks are green. F2/F2.1 are
source-complete; F3 is next.

**P5-F3 current-authority checkpoint (2026-08-13):** Run is materialized at
738 production declarations in 736 files. The new internal
`Analysis\\Run\\RuleProducerPreparation` receives the two capability-specific
preparation contracts, `FileSetInspectionComposite`, and `RuleSelector`; the
two exact Run preparation consumers move from `AnalysisPipeline` to this
coordinator. Five existing producer tests move without changing semantic IDs,
live discovery becomes 508 classes / 7,244 IDs, and the pipeline constructor is
11 while its explicit phase order is unchanged. Raw F3 acceptance is
`11 <= 11`, the immutable `57fa22fa` ceiling; the current offset-only finding
is an authorized semantic rekey, not new debt. P5-G must publish that rekey so
the final active finding count is zero. The root-owned Amp-dependent pipeline
probe, run outside the sandbox, is green at 6 tests / 51 assertions. F4 is next.

**P5-F4 current-authority checkpoint (2026-08-13):** Console and DI are
materialized at 740 production declarations in 738 files. The new internal
`RuntimeLoggerConfigurator` creates, publishes, and returns one logger;
`RuntimeConfigurator` now has seven constructor dependencies while preserving
reset -> logger -> Architecture -> ComputedMetrics order. The new internal
`ComputedMetricsConfigurator` is the sole owner of the exact root/Health
implementation registrations, the capability-owned rule, and the four named
contract aliases; the former Analysis/Output/Rule registrations are absent.
All implementations remain private behind contracts, with string-only
composition for internal declarations. Live discovery is 509 classes / 7,245
IDs. P5-F1 through P5-F4 are source-complete; P5-F4.1 is next and P5-G is
paused.

## P5-F4.1 — Coupling closure before publication

P5-F4.1 is one serial cross-owner source package. Configuration, ComputedMetrics
root/Health and their Run/Policy consumers overlap at the configuration document
contract, while both Health query owners feed offender assembly. Splitting those
edits between writers would create an intermediate interface/concrete mismatch
or a half-migrated builder input. One designated writer therefore performs the
source and test migration, immediately reconciles the current manifest, and
runs the focused/static/inventory and full-source metric gates. Generated
publication artifacts, `qmx.yaml`, `qmx-baseline.json` and `phpunit.xml.dist`
remain untouched until P5-G resumes.

The finite production changes are:

1. Remove the unused, documentation-only `MetricBag` import from
   `Contract\Definition\HealthDimension`; the enum has no Measurement behavior.
2. Make offender assembly consume Health-owned snapshots rather than a
   Measurement repository:
   - `WorstOffender::computeViolationDensity(int, int|float|null): ?float`
     receives the already selected LOC scalar and retains the exact zero
     violations / missing-or-non-positive LOC behavior;
   - `WorstOffenderBuilder::build(...)` receives that LOC scalar instead of a
     `MetricBag` plus key;
   - the builder's closed batch input is exactly
     `iterable<array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>}>`.
     `HealthSummaryBuilder` and `WorstClassDrillDown`, the existing query owners,
     obtain each symbol's bag once and extract those five named fields before
     invoking the builder. `notableMetrics` contains only the finite selected
     keys returned by `HealthDimensionCatalog::notableClassMetrics()`; neither
     owner copies `MetricBag::all()` wholesale. The builder filters the supplied
     symbols by namespace, skips a snapshot whose `overall` is null, counts the
     supplied violations, derives the score label from `overall`, derives the
     reason from `dimensionScores`, computes density from the already selected
     `loc`, attaches the supplied `notableMetrics`, sorts the offenders, and
     constructs the result. It neither imports `MetricBag` or
     `MetricRepositoryInterface` nor interprets Measurement keys implicitly.
     `ContributorRanker` and
     `ComputedMetricEvaluator` retain their honest `MetricRepositoryInterface`
     and `MetricBag` dependencies.
3. Add `HealthDimensionCatalog::classCountMetric(): string`, returning the one
   stable `classCount.sum` key owned by that metadata subject.
   `HealthScoreDrillDown` uses it for subtree weighting and drops its direct
   `AggregationStrategy` and `MetricName` imports. Existing exact-, child-,
   prefix-, weighted-average- and minimum-one subtree tests preserve behavior.
4. Replace the public `ConfigurationDocumentInterface` plus its sole root
   `Analysis\Configuration\ConfigurationDocument` implementation with one
   final readonly contract-public
   `Analysis\Configuration\Contract\ConfigurationDocument`. It keeps the
   current ordered contribution constructor and iteration behavior; there is no
   second implementation or test double. The seven exact production consumers
   split into two same-owner consumers—`TransitionalResolvedConfiguration` and
   `ConfigurationPipeline`—and five externally authorized consumers:
   `ComputedMetricAnalysis`, `ComputedMetricContributionReader`,
   `ComputedMetricConfiguratorInterface`, `ArchitecturePolicy`, and
   `ArchitecturePolicyConfiguratorInterface`. The concrete publishes exactly
   those five external consumers, preserving the existing owner relations and
   adding no composition or owner edge. Direct constructor use in the
   existing ComputedMetrics, Measurement, Run pipeline, Console runtime/scope,
   Git scope and Architecture policy tests changes namespace only. Removing the
   interface and root implementation and adding the concrete contract is a
   deliberate pre-release public-contract replacement under the repository BC
   policy; no compatibility alias or forwarding class remains.

No declaration is added for the snapshot shape: it is the exact array shape
above at the Health-owned method boundary, not a generic transport helper. The
configuration replacement is two deletions plus one addition, so the checkpoint
changes from **740 declarations / 738 files** to **739 / 737**; owners remain
37. No test class or semantic case is created or removed, so discovery remains
**509 classes / 7,245 IDs**. The manifest must be re-derived from the live AST:
the obsolete interface and root concrete disappear, the new contract concrete
has the two same-owner and five external consumers above, and governance
expected keys migrate from the interface/root implementation identities to the
one contract concrete. The Measurement imports removed by steps 1–3 disappear.
The exact P5 relation and raw carrier sets are then recounted rather than
copied; this package adds no owner edge, internal grant or enforcement seam.

The existing behavioral IDs exercised by the migration are finite:
`WorstOffenderTest::itConstructsWithDefaults` plus the seven
`WorstClassDrillDownTest::itBuildWorstClasses*` cases prove assembly, violation
counting, missing scores and optional notable metrics;
`itBuildWorstClassesCountsViolationsPerClass` is augmented, without a new ID,
to assert class LOC and density through the closed snapshot path. The seven
`ViolationDensityTest` cases remain exact:
`itClassDensityComputedCorrectly`, `itNamespaceDensityUsesLocSum`,
`itDensityZeroWhenNoViolations`, `itDensityNullWhenLocZero`,
`itDensityNullWhenLocMissing`, `itDensityRoundedToOneDecimal`, and
`itWorstOffenderDefaultDensityIsNull`. The offender-producing summary coverage
is `SummaryEnricherTest::itWorstNamespaces`, `itWorstClasses`, and
`itSkipsSymbolsAboveWarningThreshold`; the project-only
`HealthSummaryBuilderTest::itEnrichesWithHealthScores` is not claimed as
offender coverage. All ten `HealthScoreDrillDownTest` cases prove subtree and
class behavior, including weighted and minimum-one class counts; the six
`ComputedMetricContributionReaderTest` cases and four
`ComputedMetricAnalysisTest` cases preserve ordered document contributions and
atomic publication. Existing Configuration pipeline, Architecture policy, Run,
Console and Git tests keep their IDs while importing the final concrete
contract. If the implementation reveals behavior not covered by this set, it
adds an honest regression ID and revises the 7,245 arithmetic before closure.

The projected raw metrics are hypotheses to verify, not acceptance by
arithmetic:

| Signal                                                 | Current | Projected after exact consumer removal |
| ------------------------------------------------------ | ------: | -------------------------------------: |
| `AggregationStrategy` class CBO                        | 38      | 37                                     |
| `MetricBag` class CBO                                  | 69      | 66                                     |
| `MetricName` class CBO                                 | 63      | 62                                     |
| `MetricRepositoryInterface` class CBO                  | 47      | 46                                     |
| `Analysis\Configuration\Contract` namespace CBO        | 24      | 23                                     |
| `Analysis\Evidence\Measurement\Contract` namespace CBO | 51      | 48                                     |

`MetricRepositoryInterface` ClassRank must be measured and absent; no projected
number is treated as proof. P5-F4.1 closes only when focused tests, lint, scoped
PHPStan/CS, topology, isolated production/test inventories and manifest policy
are green, and an authoritative full-source `--workers=0` JSON scan shows all
six CBO rows and the ClassRank row absent. There is no baseline rekey, threshold
change, or qmx exclusion for these seven findings. P5-G resumes only after this
checkpoint is reviewed and all source/manifest writers are idle.

The three first-round F4.1 review findings above are addressed; address check
is pending.

**P5-F4.1 implemented checkpoint (2026-08-13):** live source and isolated
inventories agree at 739 declarations / 737 files, 37 owners and 509 PHPUnit
classes / 7,245 IDs. The authoritative full-source scan clears
`AggregationStrategy`, `MetricName` and `MetricRepositoryInterface` ClassRank,
but remains red for `MetricBag` class CBO 67, `MetricRepositoryInterface` class
CBO 46, Configuration Contract namespace CBO 24, and Measurement Contract
namespace CBO 49. F4.1 is therefore only a partial source closure; P5-G remains
paused.

## P5-F4.2 — Remaining coupling closure

P5-F4.2 is one serial source plus immediate manifest package; it changes no
declaration, file, owner, test class or test-ID count, while explicitly replacing
one semantic test identity. The current and target
inventory therefore remains **739 declarations / 737 files**, 37 owners, and
**509 classes / 7,245 IDs**.

1. `Health\Score\ContributorRanker` becomes a pure deterministic ranker with
   the exact input
   `iterable<array{symbol: SymbolInfo, primaryValue: float|null, contributorMetrics: array<string, int|float>}>`,
   plus direction and limit. The existing query owners
   `HealthSummaryBuilder` and `HealthScoreDrillDown` retain their honest
   repository/`MetricBag` dependencies. For the selected Health dimension they
   derive the finite metadata input list (`altKey ?? key` for each catalog
   decomposition item), read each bag once, set `primaryValue` explicitly, and
   copy only non-null values for those listed scalar keys into
   `contributorMetrics`; they never use `MetricBag::all()`. The variable map is
   closed by that catalog-derived finite key set and matches the
   `HealthContributor` contract—it is not an open Measurement bag. Direction
   is the exact literal union `'higher'|'lower'`; any other value fails closed
   with `InvalidArgumentException` rather than silently choosing an order. The
   ranker imports no Measurement type, performs no repository lookup and
   interprets no metric key. It filters null primary values, sorts by the
   supplied direction with canonical symbol as the stable tie-breaker, slices
   to the non-negative limit, and projects `HealthContributor` with the already
   selected `contributorMetrics`. `ContributorRankerTest::itReturnsEmptyForUnknownDimension`
   becomes obsolete because known query owners now select dimensions; that ID
   is deliberately replaced by the real ranker contract
   `itRejectsUnsupportedDirection`, which supplies an invalid literal and
   asserts `InvalidArgumentException`. The class still has the same number of
   test methods, but this is an explicit semantic identity replacement, not a
   preserved case. The existing null-primary case, both valid directions,
   limits and projections migrate to closed rows. The existing tie-break test
   is augmented to use identical short class names in two different namespaces
   and assert their canonical-symbol ordering. The existing complete-
   decomposition test is augmented to assert both `ccn.sum` and
   `cognitive.sum` in `contributorMetrics`. Global discovery therefore remains
   7,245 IDs.
2. Remove the documentation-only `use ConfigFileStage` import from
   `Analysis\Configuration\Contract\KnownRuleNamesProviderInterface` and replace
   its docblock with neutral wording: the contract provides known rule names to
   configuration validation. There is no runtime reference, behavior change,
   relation replacement or new consumer.

The manifest and raw import inventory are reconciled immediately after these
two edits. No owner edge, exact internal grant, seam, qmx rule, threshold or
baseline row changes. From the F4.1 scan, the exact projected raw metric deltas
are `MetricBag` CBO **67 -> 66**, `MetricRepositoryInterface` CBO **46 -> 45**,
Measurement Contract namespace CBO **49 -> 48**, and Configuration Contract
namespace CBO **24 -> 23**. `AggregationStrategy`, `MetricName` and ClassRank
are already absent and must remain absent. These numbers are hypotheses:
focused Health/Configuration tests, lint, scoped PHPStan/CS, topology, isolated
production/test inventories and manifest policy run first; then an authoritative
full-source `--workers=0` JSON scan confirms `MetricBag` CBO 66,
`MetricRepositoryInterface` CBO 45 and Measurement Contract namespace CBO 48,
with ClassRank absent. Configuration Contract namespace CBO remains 24 rather
than the projected 23 because its exact CBO union replaces four historical
consumer namespaces with five mandatory named capability consumers. Narrow
review rejects a baseline ceiling increase and further source distortion; it
authorizes only `coupling.cbo.exclude_namespace_channels` selector
`coupling.cbo.namespace` for exact namespace
`Qualimetrix\Analysis\Configuration\Contract`. Direct class CBO remains
enforced. F4.2 is complete and P5-G resumes.

## P5-F4.3 — Publication-probe closure

P5-F4.3 is one finite serial source/manifest package. It changes no declaration,
file, owner, test-class or test-ID count: the checkpoint remains **739
declarations / 737 files**, 37 owners and **509 classes / 7,245 IDs**. P5-G is
paused until this package and the pending baseline decision below pass review.

1. `WorstClassDrillDown::buildWorstClasses` resolves the finite
   `notableMetricNames` list once from its accepted public boolean policy. The
   public boolean remains because callers intentionally select the compact or
   notable-metric projection. Delete the byte-equivalent, now-unused
   `WorstOffenderBuilder::buildWorstClassesWithNotableMetrics` wrapper.
   `snapshots(MetricRepositoryInterface, list<string>)` receives no boolean and
   delegates to two subject-named private operations. `snapshots` remains the
   query owner that obtains each `MetricBag`; it passes only that local bag's
   typed scalar reader to `dimensionScores` and `selectedMetrics`, which read
   the finite dimension and supplied catalog keys respectively. Keeping the
   `MetricBag` type on both extracted helpers added a new adjacent class
   instability signal without changing their scalar-only responsibility, so
   the narrower callable boundary is intentional rather than a hidden bag or
   generic lifecycle abstraction. Snapshot construction then only assembles
   symbol, overall, dimension scores, LOC and selected notable metrics. This
   removes the new private boolean-argument and cognitive-16 signals without
   changing output or adding a generic helper.
   Existing `WorstClassDrillDownTest` IDs preserve compact/notable selection,
   sorting, density, violation counting and missing-overall behavior.
2. `WorstOffenderEvidence` remains in internal `Health\Offender`; its path,
   namespace, visibility and manifest row do not change. Its exact three
   same-owner production consumers are
   `Health\Contract\Offender\WorstOffender`,
   `Health\Contract\Summary\HealthSummaryBuilder`, and
   `Health\Offender\WorstOffenderBuilder`; there is no external-owner
   production consumer that warrants a contract-visible DTO. Exact test
   consumers are
   `ViolationDensityTest`, `WorstOffenderEvidenceTest`, `WorstOffenderTest`,
   `ViolationFilterTest`, `JsonOffenderSectionDensityTest`, `JsonFormatterTest`,
   `HintRendererTest`, `OffenderListRendererDensityTest`, and
   `SummaryFormatterTest`. Those tests do not establish an external production
   contract. The reviewed disposition is the exact structural
   `coupling.distance.exclude_namespaces` entry
   `[Q]ualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender`: this
   internal concrete projection/building subject has A=0 by design. The exact
   glob avoids descendants; no visibility change, declaration move, baseline
   row or manifest mutation is allowed.
3. The Analysis Configuration namespace distance is a separate baseline-review
   decision, not a source target. Immutable P3 evidence and
   `57fa22fa:qmx-baseline.json` record the same namespace identity at
   `A=2/9`, `Ca=42`, `Ce=12`, `I=12/(42+12)=2/9`,
   `D=|2/9+2/9-1|=0.5555555556` (stored ceiling `0.555556`). Current source has
   `A=2/9`, `Ca=34`, `Ce=9`, `I=9/(34+9)=9/43`, and
   `D=|2/9+9/43-1|=220/387=0.5684754522`. The identity is unchanged, but the
   magnitude is higher because reviewed capability migration removes 13 old
   Architecture/Run afferent namespaces while adding five mandatory
   ConfigurationDocument consumers (`Ca: 42 -> 34`), and removes three old
   Configuration-to-Architecture/PSR efferent namespaces (`Ce: 12 -> 9`). The
   proposed action is an **exceptional reviewed baseline recalibration**
   from `0.555556` to `0.5684754522`, with no source gaming and no qmx
   exclusion. The metric remains applicable and the future ratchet remains
   active at the recalibrated magnitude. This is not a semantic rekey: the
   subject graph changed after intentional dependency removal. Because the
   recalibration raises a ceiling, P5-G may mutate this baseline row only after
   independent review explicitly accepts the exact graph arithmetic and
   exception.

The focused F4.3 gate is fixed at these ten classes / 139 expanded IDs:
`WorstClassDrillDownTest`, `ViolationDensityTest`,
`WorstOffenderEvidenceTest`, `WorstOffenderTest`, `ViolationFilterTest`,
`JsonOffenderSectionDensityTest`, `JsonFormatterTest`, `HintRendererTest`,
`OffenderListRendererDensityTest`, and `SummaryFormatterTest`. It is followed
by ComputedMetrics topology, modular governance, isolated production/test inventories, lint,
scoped PHPStan/CS and an authoritative full-source workers=0 JSON scan with
explicit exit. Acceptance requires both `snapshots` signals absent, Health
Offender distance present only before P5-G applies its reviewed exact exclusion,
no adjacent finding, counts unchanged, and the Configuration
distance row unchanged at exactly `0.5684754522` pending its independent
baseline decision. No qmx, baseline or generated publication edit belongs to
F4.3. The seven existing drill-down IDs, scoped static gates, isolated
inventories and authoritative address check are green: both private
`snapshots` findings are absent and no adjacent finding remains. P5-G is next
once all source writers are idle.

## P5-F4.4 — AnalysisConfigurator maintainability closure

P5-F4.4 changes only existing `AnalysisConfigurator`. After the file-set
inspection composite, `configure()` calls exactly
`registerRuleProducerPreparation(ContainerBuilder)` and then
`registerAnalysisPipeline(ContainerBuilder)`. The first contains only the
current producer-preparation ID/class/four ordered references; the second only
the pipeline ID/class/eleven ordered references, public flag and public alias.
The evaluator string local moves into the latter immediately before use. All
IDs, aliases, arguments, visibility, ordering and compiler timing stay exact.

Pre-F4.4 average MI/health were **47.8227/39.807**, below immutable raw
**48.2562/40.9226** (stored baseline health ceiling **40.9**). A temporary
candidate and the materialized source measure **69.2896/74.991** with no
new finding. Exact focused tests are `ContainerFactoryTest`,
`AnalysisPipelineTest`, `AnalysisPipelineIntegrationTest`,
`ChannelCoverageTest`, and `FileSetInspectionParticipantCompilerPassTest`, then
lint, scoped PHPStan/CS and full-source dogfood. Counts remain **739/737**, 37
owners, **509/7,245**. The immutable AnalysisConfigurator health row is resolved
and P5-G must remove it so final dogfood has `stale=0`. Review findings are
addressed; the address check is green and P5-G is next.

## P5-G — Corrective serial republish

P5-G starts only after all P5-F writers are idle. It alone publishes generated
inventories, `qmx.yaml`, `qmx-baseline.json`, `phpunit.xml.dist` and final docs.
The immutable semantic ledger base is **`57fa22fa:qmx-baseline.json`**, not the
currently rewritten working file. A rekey is authorized only when that file
contains the exact old row and source history proves that the same declaration
or member moved without a semantic change. The finite reviewed candidates are:

In the table, a callable or class spelling is exact shorthand for the baseline
identity `declaration:<kind>:Qualimetrix\<spelling>`; `ns:` rows are already
complete namespace identities. “same old callable/class” repeats the complete
identity immediately above, not an owner-wide permission. Channel shorthands
expand exactly as follows: cognitive =
`complexity.cognitive#complexity.cognitive.callable`; cyclomatic =
`complexity.cyclomatic#complexity.cyclomatic.callable`; NPath =
`complexity.npath#complexity.npath.callable`; WMC =
`complexity.wmc#complexity.wmc`; instability =
`coupling.instability#coupling.instability.class`; health.cohesion =
`computed.health#health.cohesion`; and boolean argument =
`code-smell.boolean-argument#code-smell.boolean-argument`; and constructor
overinjection =
`code-smell.constructor-overinjection#code-smell.constructor-overinjection`.
The immutable v11
boolean rows carry one occurrence with no magnitude array; current value `1`
below is that same single occurrence, not a raised ceiling.

| State           | Immutable old subject                                                                                                    | Channel / ceiling              | Current/final semantic subject / measured value                                                                                                                            |
| --------------- | ------------------------------------------------------------------------------------------------------------------------ | ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| proven active   | `Analysis\Run\Pipeline\AnalysisPipeline::__construct@src/Analysis/Run/Pipeline/AnalysisPipeline.php:3489`                | constructor overinjection / 11 | `Analysis\Run\Pipeline\AnalysisPipeline::__construct@src/Analysis/Run/Pipeline/AnalysisPipeline.php:3136` / 11                                                             |
| proven active   | `Reporting\Health\SummaryEnricher::buildWorstOffenders@src/Reporting/Health/SummaryEnricher.php:8928`                    | cognitive / 18                 | `Health\Contract\Summary\HealthSummaryBuilder::buildWorstOffenders@src/Analysis/Evidence/ComputedMetrics/Health/Contract/Summary/HealthSummaryBuilder.php:8302` / 15       |
| proven active   | same old callable                                                                                                        | cyclomatic / 14                | same final callable / 11                                                                                                                                                   |
| proven active   | `Reporting\Health\SummaryEnricher::countViolationsPerSymbol@src/Reporting/Health/SummaryEnricher.php:13178`              | cognitive / 20                 | `Health\Contract\Summary\HealthSummaryBuilder::countViolationsPerSymbol@src/Analysis/Evidence/ComputedMetrics/Health/Contract/Summary/HealthSummaryBuilder.php:12113` / 20 |
| proven active   | same old callable                                                                                                        | cyclomatic / 13                | same final callable / 13                                                                                                                                                   |
| proven active   | `Reporting\Health\SummaryEnricher@src/Reporting/Health/SummaryEnricher.php:804`                                          | WMC / 65                       | `Health\Contract\Summary\HealthSummaryBuilder@src/Analysis/Evidence/ComputedMetrics/Health/Contract/Summary/HealthSummaryBuilder.php:1578` / 60                            |
| proven active   | same old class                                                                                                           | instability / 0.9              | retained `Reporting\Health\SummaryEnricher@<live declaration offset>` / 0.8181818182                                                                                       |
| proven active   | `Configuration\ComputedMetricsConfigResolver::resolve@src/Configuration/ComputedMetricsConfigResolver.php:1685`          | cognitive / 16                 | `Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver::resolve@src/Analysis/Evidence/ComputedMetrics/ComputedMetricsConfigResolver.php:1731` / 16               |
| proven active   | same old callable                                                                                                        | cyclomatic / 10                | same final callable / 10                                                                                                                                                   |
| proven active   | `Configuration\ComputedMetricsConfigResolver::mergeDefinition@src/Configuration/ComputedMetricsConfigResolver.php:6651`  | cognitive / 17                 | `Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver::mergeDefinition@src/Analysis/Evidence/ComputedMetrics/ComputedMetricsConfigResolver.php:6697` / 17       |
| proven active   | same old callable                                                                                                        | cyclomatic / 14                | same final callable / 14                                                                                                                                                   |
| proven active   | same old callable                                                                                                        | NPath / 360                    | same final callable / 360                                                                                                                                                  |
| proven active   | `Configuration\ComputedMetricsConfigResolver::createDefinition@src/Configuration/ComputedMetricsConfigResolver.php:8843` | cyclomatic / 12                | `Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver::createDefinition@src/Analysis/Evidence/ComputedMetrics/ComputedMetricsConfigResolver.php:8889` / 12      |
| proven active   | `Configuration\ComputedMetricsConfigResolver@src/Configuration/ComputedMetricsConfigResolver.php:627`                    | WMC / 60                       | `Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver@src/Analysis/Evidence/ComputedMetrics/ComputedMetricsConfigResolver.php:673` / 60                         |
| proven active   | same old class                                                                                                           | health.cohesion / 40           | same final class / 40                                                                                                                                                      |
| proven resolved | `Configuration\HealthFormulaExcluder::applyExcludeHealth@src/Configuration/HealthFormulaExcluder.php:868`                | cognitive / 20                 | no current row after P5-F2.1 decomposition                                                                                                                                 |
| proven resolved | same old callable                                                                                                        | cyclomatic / 14                | no current row after P5-F2.1 decomposition                                                                                                                                 |
| proven resolved | same old callable                                                                                                        | NPath / 960                    | no current row after P5-F2.1 decomposition                                                                                                                                 |
| proven active   | `Core\ComputedMetric\ComputedMetricDefinition@src/Core/ComputedMetric/ComputedMetricDefinition.php:148`                  | health.cohesion / 30           | `Contract\Definition\ComputedMetricDefinition@src/Analysis/Evidence/ComputedMetrics/Contract/Definition/ComputedMetricDefinition.php:182` / 30                             |
| proven active   | `Rules\ComputedMetric\ComputedMetricRule::determineSeverity@src/Rules/ComputedMetric/ComputedMetricRule.php:6574`        | cyclomatic / 10                | `Finding\ComputedMetricFindingBuilder::severity@src/Analysis/Evidence/ComputedMetrics/Finding/ComputedMetricFindingBuilder.php:1621` / 10                                  |
| proven active   | `Reporting\Health\NamespaceDrillDown::buildWorstClasses@src/Reporting/Health/NamespaceDrillDown.php:4401`                | boolean argument / 1           | `Health\Contract\DrillDown\WorstClassDrillDown::buildWorstClasses@src/Analysis/Evidence/ComputedMetrics/Health/Contract/DrillDown/WorstClassDrillDown.php:2020` / 1        |
| proven active   | `Reporting\Health\MetricHintProvider::getHealthDimensionLabel@src/Reporting/Health/MetricHintProvider.php:24167`         | boolean argument / 1           | `Health\Metadata\HealthMetricCatalog::getHealthDimensionLabel@src/Analysis/Evidence/ComputedMetrics/Health/Metadata/HealthMetricCatalog.php:1717` / 1                      |
The immutable ledger therefore contains exactly 23 proven historical rows:
19 active rekeys and four resolved rows: the three HealthFormulaExcluder rows
above plus `Infrastructure\DependencyInjection\Configurator\AnalysisConfigurator`
`computed.health#health.maintainability` at immutable offset 2002 / raw value
40.9226 and stored ceiling 40.9 (working pre-F4.4 offset 2055 / 39.807). The F4.4 candidate measures
74.991, so that row is resolved and P5-G removes it rather than rekeying it.
There are zero pending rows. The root/Health slice contains 17 active rows; Reporting's active Summary
instability row is the eighteenth and remains at
0.8181818182, below its immutable 0.9 ceiling. Run's active
`AnalysisPipeline::__construct` row is the nineteenth and remains at 11,
equal to its immutable `57fa22fa` ceiling. The earlier root/Health 37-row
slice was `20 immutable + 1 now-rejected namespace candidate + 16 new/regressed`.
After F2.1, the 16 code rows are fixed, three immutable rows are resolved, the
rejected old namespace row is resolved, and the current Definition namespace
signal belongs only to the reviewed nine-row structural exclusion set. P5-G
may rekey exactly 19 active rows, remove resolved old rows, and add only those
nine exact exclusions.

Live declaration offsets are re-probed after all P5-F writers are idle; changed
offsets do not grant a new semantic identity. This ledger is independent of the
current-file diff and of accumulated P4 semantic rekeys, which remain intact
and are audited against their own immutable history. Rekeys are not additions.
Disappeared old rows are removed as resolved. P5-G adds zero semantic debt,
raises zero unreviewed magnitude and adds zero unreviewed qmx exclusion; only
the nine reviewed exact namespace/channel exclusions are published.

Reconcile counts from live generation, generate twice after writers idle and
require byte identity. Run exact dogfood `bin/qmx check src/
--baseline=qmx-baseline.json --fail-on=warning --workers=0`; record its process
exit code explicitly. Acceptance is exit 0, zero active findings and zero stale
rows. Zero stale alone is not success.

Root then runs full `composer check`, live/generated semantic ID equality,
architecture freshness, docs consistency/local links, private leaks and
`git diff --check`. Independent review receives the full P5-F/G diff, revised
contract ownership, Measurement before/after graph, baseline ledger and logs.
All confirmed findings including LOW are fixed and the whole gate reruns.

**DoD:** every F3 source metric is at or below its immutable ceiling, including
`AnalysisPipeline::__construct` at 11/11. Publication is byte-stable; P5
baseline changes contain only the 19
active immutable-ledger rekeys and resolved removals, qmx changes add
only the nine reviewed exact namespace/channel exclusions, and P4 accumulated rekeys remain unchanged;
explicit workers=0 dogfood exits 0 with active=0 and stale=0; root aggregate
and independent review are green.

## Execution and aggregate gate

```text
P5-0 -> P5-A -> P5-B -> P5-C -> P5-D -> P5-E attempted publication
     -> failed aggregate -> P5-F remediation -> P5-G republish
     -> root aggregate validation -> independent review GO -> [complete]
```

Packages share contracts, DI and the authoritative manifest and land
sequentially in the main worktree. They may be delegated, but never run as
parallel writers. A package must leave its source and exact current manifest
rows consistent before the next package starts. The first P5-E publication is
historical and failed aggregate. P5-F owns corrective source/test/current-
manifest work; P5-G begins only after all P5-F writers are idle and is the sole
corrective publication writer. Every package reports exact files, focused
tests, PHPStan/CS and deviations; no package performs git operations.

Aggregate validation runs all moved tests; exact Run/Console/DI/channel/
baseline/formatter regressions; `composer architecture:check`; semantic
discovery comparison; full `composer check`; dogfood with the versioned
baseline, `--fail-on=warning` and `--workers=0`; documentation consistency,
local links, leak check and `git diff --check`. The independent reviewer gets
the whole P5 diff, P5-0 decision, shared seams and aggregate evidence. All
confirmed findings including LOW are fixed and revalidated before P5 is marked
Completed.
