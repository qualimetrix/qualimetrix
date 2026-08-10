# Analysis — Analysis Orchestration

## Overview

Analysis is the orchestrator of static analysis. It implements a five-phase pipeline:

1. **Discovery** — finding PHP files for analysis
2. **Collection** — gathering metrics AND dependencies in a single AST traversal (parallelizable via amphp/parallel)
3. **Aggregation** — aggregation by namespace/module, building the dependency graph
4. **RuleExecution** — generating violations based on metrics and graph
5. **Reporting** — producing the report (performed in the Reporting module)

> **Note.** The former `Analysis/Architecture/` sub-tree (template-layer
> expansion stage) moved into the Architecture vertical slice
> ([ADR 0010](../../docs/adr/0010-architecture-vertical-slice.md)). It now
> lives at [`src/Architecture/Processing/`](../Architecture/README.md). The
> pipeline still drives template expansion at Phase 2.6 by holding the same
> stage reference — Phase 4 / [ADR 0008](../../docs/adr/0008-architecture-processor-service.md)
> will replace it with `ArchitectureProcessor`.

## Structure

```
Analysis/
├── Pipeline/                            # Orchestration of all phases
│   ├── AnalysisPipelineInterface.php    # Pipeline contract
│   ├── AnalysisPipeline.php             # Main orchestrator
│   ├── AnalysisResult.php               # Analysis result
│   ├── AnalysisCoverage.php             # Canonical discovered-file terminal states
│   ├── AnalysisFailure.php              # Typed path/kind/message failure
│   ├── AnalysisFailureKind.php           # Parse and processing failure categories
│   ├── AnalysisCoverage.php             # Discovered-file terminal states and completeness
│   ├── AnalysisFailure.php              # One failed discovered file
│   ├── AnalysisFailureKind.php          # Parse or processing failure category
│   ├── IncompleteAnalysisException.php  # Typed refusal for artifact-producing consumers
│   ├── DependencyGraphAnalyzerInterface.php # Complete discovery-to-graph contract
│   ├── DependencyGraphAnalyzer.php       # Graph-only analysis orchestration with terminal-state coverage
│   ├── DependencyGraphAnalysisResult.php # Graph plus canonical coverage result
│   ├── MetricEnricher.php               # Enrichment phases (aggregation, global collectors, computed metrics, cycles, duplication)
│   └── EnrichmentResult.php             # VO: cycles and duplicate blocks from the enrichment phase
│
├── Discovery/                           # File discovery
│   ├── FileDiscoveryInterface.php       # Discovery contract
│   ├── FinderFileDiscovery.php
│   └── GeneratedFileFilter.php          # Filters out generated files
│
├── Collection/                          # Data collection
│   ├── CollectionOrchestratorInterface.php # Orchestrator contract
│   ├── CollectionOrchestrator.php       # Collection coordination
│   ├── CollectionResult.php             # Collection phase result
│   ├── FileProcessorInterface.php       # File processor contract
│   ├── FileProcessor.php                # Single file processing
│   ├── FileProcessingResult.php         # Single file terminal state
│   ├── CollectedFileData.php             # Successful metrics/dependencies/annotations payload
│   ├── FileProcessingFailure.php         # Typed collection failure payload
│   ├── FileProcessingFailureKind.php     # Parse or processing failure category on the collection wire
│   │
│   ├── Declaration/
│   │   └── DeclarationBindings.php       # Immutable AST declaration-to-subject bindings
│   ├── SourceControl/
│   │   └── SourceControls.php            # Immutable source suppression/threshold extraction result
│   │
│   ├── Metric/
│   │   ├── CompositeCollector.php       # Combines visitors (unified AST traversal)
│   │   ├── CollectionOutput.php         # Output of composite collection
│   │   ├── DerivedCollectorRunner.php   # Stable topological derived-collector execution
│   │   └── DerivedMetricExtractor.php   # Extracts derived metrics from collected data
│   │
│   ├── Dependency/
│   │   ├── DependencyGraph.php          # Dependency graph
│   │   ├── DependencyGraphBuilder.php
│   │   ├── DependencyVisitor.php        # AST visitor (delegates to handlers)
│   │   ├── DependencyResolver.php       # Resolves class dependencies
│   │   ├── CircularDependencyDetector.php # Tarjan's algorithm
│   │   ├── Cycle.php
│   │   ├── CycleMemberLabels.php        # Short display labels for cycle members
│   │   ├── Handler/                     # Decomposed dependency handlers
│   │   │   ├── NodeDependencyHandlerInterface.php
│   │   │   ├── DependencyContext.php
│   │   │   ├── TypeDependencyHelper.php
│   │   │   ├── ClassLikeHandler.php
│   │   │   ├── TraitUseHandler.php
│   │   │   ├── InstantiationHandler.php
│   │   │   ├── StaticAccessHandler.php
│   │   │   ├── CatchInstanceofHandler.php
│   │   │   ├── PropertyHandler.php
│   │   │   └── FunctionLikeHandler.php
│   │   └── Export/                      # Graph export
│   │       ├── GraphExporterInterface.php
│   │       ├── DotExporter.php          # DOT format export
│   │       ├── DotExporterOptions.php
│   │       └── JsonGraphExporter.php    # JSON format export
│   │
│   └── Strategy/                        # Execution strategy contracts
│       ├── ExecutionStrategyInterface.php
│       ├── ParallelCapableInterface.php
│       └── StrategySelectorInterface.php
│
├── Aggregator/                          # Decomposed metric aggregation
│   ├── AggregationPhaseInterface.php    # Phase contract
│   ├── AggregationHelper.php            # Generic aggregation arithmetic
│   ├── NamespaceMetricContributions.php # Namespace ownership and file mapping
│   ├── CallableToClassAggregator.php      # Callable → Class phase
│   ├── ClassToNamespaceAggregator.php   # Class → Namespace phase
│   ├── NamespaceToProjectAggregator.php # Namespace → Project phase
│   ├── MetricAggregator.php             # Thin orchestrator
│   ├── GlobalCollectorRunner.php        # Runs global (cross-file) collectors
│   └── GlobalCollectorSorter.php        # Topological sort of global collectors
│
├── Duplication/                         # Rabin-Karp duplicate detection, split by phase (see DuplicationDetector docblock)
│   ├── NormalizedToken.php              # VO: normalized token for comparison (carries an isData flag)
│   ├── TokenNormalizer.php              # Normalizes PHP tokens for duplicate detection
│   ├── DataDeclarationTagger.php        # Flags tokens inside const/property-array data declarations
│   ├── ContentHintExtractor.php         # Extracts a short content preview for a duplicate block
│   ├── PackedPosition.php               # Bit-packing helper for (fileIdx, tokenOffset) positions
│   ├── SaturatingCandidateFilter.php    # Fixed-size two-bit candidate pre-filter for rolling hashes
│   ├── HashIndexBuilder.php             # Bounded pre-pass, then exact candidate-position index
│   ├── HashIndexBuildResult.php         # VO: exact candidate index + file paths
│   ├── RetokenizedFiles.php             # VO: pass 2 output (tokens/sources of files with hash matches)
│   ├── DuplicateSearchRequest.php       # VO: bundles pass-2 inputs for DuplicateBlockFinder::find()
│   ├── DuplicateBlockFinder.php         # Verifies matches, extends blocks, applies data/self-dup filters
│   ├── DuplicationDetectorInterface.php # Contract for duplicate block detection
│   └── DuplicationDetector.php          # Thin orchestrator composing the phases above (config via DI)
│
├── RuleExecution/
│   ├── RuleExecutorInterface.php        # Rule executor contract
│   ├── RuleExecutor.php
│   └── RuleExclusionStats.php           # VO: per-rule exclude_namespaces/exclude_paths suppression counts + optionally-captured violations (see RuleExclusionCaptureHolder)
│
├── Repository/
│   ├── InMemoryMetricRepository.php
│   ├── MetricSubjectIndex.php            # Exact subject lookup and logical callable bridge
│   ├── NamespaceMetricIndex.php          # Deduplicated namespace projection
│   └── RepositoryMerge.php               # Deterministic repository storage merge policy
│
├── Namespace_/                          # Namespace detection
│   ├── ChainNamespaceDetector.php
│   ├── Psr4NamespaceDetector.php
│   ├── TokenizerNamespaceDetector.php
│   └── ProjectNamespaceResolver.php     # Project-level namespace resolution
│
└── Exception/
    └── CyclicDependencyException.php
```

---

## Internal Dependency Layers

Enforced by the project's own `qmx.yaml` as `analysis-*` sub-layers
(see [ADR 0014](../../docs/adr/0014-deptrac-retirement.md)).

Analysis sub-packages follow layered dependency rules:

- **Leaf** (no Analysis siblings): Exception, Discovery, Namespace\_, Repository, Duplication
- **Mid**: Aggregator depends on Exception; RuleExecution is standalone; Collection depends on Exception
- **Orchestrator**: Pipeline depends on all sub-layers

---

## AnalysisPipeline — Main Orchestrator

Coordinator of all analysis phases.

**Public API:**
- `analyze(string|array $paths, ?FileDiscoveryInterface $discovery = null): AnalysisResult`

### Scanner orchestration boundaries

The scanner keeps each transformation inside the existing owner of its subject:

| Owner                          | Typed boundary and invariant                                                                                                                                                                                                                                                                                                                                                                                                                                      | Dependency treatment                                                                                                                                                                    | Focused regression                                                                                                                                                                 |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `NamespaceMetricContributions` | `collectValues(MetricRepositoryInterface, list<SymbolInfo>, list<SymbolInfo>, list<MetricDefinition>, SymbolLevel): array<string, list<int\|float>>`. `collectFromSymbols` handles callable, class, and global-function subjects only; physical-file and explicit-namespace contributions remain separate passes. Physical file ownership maps each file once per owned namespace even when several exact declarations share a logical name.                      | Reads repository subjects only; it neither creates graph edges nor changes repository ownership.                                                                                        | `NamespaceMetricContributionsTest::itCollectsEachTypedSymbolLevelAndExpandsNamespaceOwnedAverages` and `::itMapsOnePhysicalFileToEveryOwnedNamespace`.                             |
| `CollectionOrchestrator`       | `collect(list<SplFileInfo>, MetricRepositoryInterface, AbsolutePath): CollectionPhaseOutput`. It selects a strategy and folds the ordered `iterable<FileProcessingResult>` into `CollectionResult` plus the separately-lived `list<Dependency>`. Every terminal result advances progress once; only successful payloads register metrics, dependencies, suppressions, overrides, and diagnostics; every typed failure produces one warning and retains its order. | Retains successful dependencies in encounter order; failed results cannot contribute dependencies or controls.                                                                          | `CollectionOrchestratorTest::itFoldsSuccessfulPayloadsAndTypedFailuresWithoutLosingControls`.                                                                                      |
| `DependencyGraphBuilder`       | `build(array<Dependency>, iterable<LogicalClassPath>): DependencyGraph`. The stages are filtering, source/target/class/leaf-namespace indexing, parent-universe expansion, one class/namespace Ce/Ca pass, and parent roll-up. Degree-zero declarations and undeclared external targets remain vertices; sibling edges are internal to their common parent.                                                                                                       | Retains every non-built-in edge and built-in `Extends`; removes only non-inheritance edges to PHP built-ins. Endpoint coupling is deduplicated while the ordered edge list is retained. | `DependencyGraphBuilderTest`, including degree-zero, built-in filtering, duplicate endpoints, and parent Ce/Ca boundary cases.                                                     |
| `DependencyVisitor`            | `setFile(?RelativePath)` resets namespace, imports, class-like context, and collected dependencies; `enterNode(Node)` establishes named `ClassLike` sources, lets anonymous classes inherit the enclosing source, then dispatches other nodes through the existing handler table; `getDependencies(): array<Dependency>`.                                                                                                                                         | Handler-produced edge types and source/target identity are unchanged; state from a prior file cannot leak into the next traversal.                                                      | `DependencyVisitorTest::itPreservesExactDeclarationSourcesForEveryNamedClassLike` and `::itResetsDependenciesAndImportsWhenReusedForAnotherFile`.                                  |
| `AnalysisPipeline`             | `analyze(AbsolutePath\|list<AbsolutePath>, ?FileDiscoveryInterface): AnalysisResult`. The invariant order is Discovery -> Collection -> graph build -> Architecture preparation -> enrichment -> RuleExecution -> result projection. Private boundaries pass the existing `DependencyGraph`, `EnrichmentResult`, and `CollectionResult`; one `ProfilerInterface` is resolved at entry and used for every pipeline span, including Architecture preparation.       | Raw dependencies are consumed by graph construction and released; graph semantics and rule context remain unchanged.                                                                    | `AnalysisPipelineTest::itKeepsCollectionArchitectureEnrichmentAndRulesInExactOrderForDegreeZeroClasses` and `::itUsesOneResolvedProfilerEvenWhenDiscoveryReplacesTheGlobalHolder`. |
| `DependencyGraphAnalyzer`      | `analyze(list<AbsolutePath>, AbsolutePath): DependencyGraphAnalysisResult`. It discovers the named class/interface/trait/enum universe directly from parsed AST, excludes anonymous classes, builds the graph, and pairs it with canonical analyzed/parse-failure/processing-failure coverage.                                                                                                                                                                    | Visitor dependencies feed the same builder contract; declaration-only class-likes remain degree-zero vertices.                                                                          | `DependencyGraphAnalyzerTest::itBuildsTheCanonicalUniverseForEveryNamedClassLikeAndExternalTarget`.                                                                                |

`CollectionOrchestrator` has a deliberately breaking internal constructor:
`ProgressReporter` and `LoggerInterface` are mandatory and there is no null-object
compatibility shim. Callers that previously omitted them must inject explicit
implementations. The shipped DI configuration already supplies the delegating
progress reporter (`DelegatingProgressReporter`) and logger
(`DelegatingLogger`). Every direct `new CollectionOrchestrator(...)` consumer
must pass its chosen explicit implementations; there is no default, nullable
fallback, overload, or compatibility shim.

### Algorithm

**Phase 1: Discovery**
Finding PHP files via `FileDiscoveryInterface`.

**Phase 2: Collection** (parallelizable)
- Selecting execution strategy (sequential/parallel)
- Processing files via `FileProcessor`
- Collecting metrics AND dependencies in a single AST traversal via `CompositeCollector`
- Building the dependency graph

**Phase 2.6: Architecture template expansion** (only when configuration carries `TemplateLayerDefinition`s)
- Owned by `Qualimetrix\Architecture\Processing\ArchitectureProcessor`
  ([ADR 0008](../../docs/adr/0008-architecture-processor-service.md)); the
  pipeline calls `prepare($graph, $classSet)` to bind the per-run graph and
  expand templates in one step. See [`src/Architecture/README.md`](../Architecture/README.md)
  for the full description.
- No-op for configurations without templates — pipeline runs unchanged.

**Phase 3: Aggregation**
- Aggregating metrics by levels (method -> class -> namespace -> project)
- Running global collectors
- Re-aggregating metrics after global collectors (so global metrics like CBO, Instability, NOC, Distance are properly aggregated to namespace and project levels)
- Running circular dependency detection (skipped when `architecture.circular-dependency` rule is disabled)
- Running duplication detection — token-based duplicate code block detection across analyzed files (skipped when `duplication.code-duplication` rule is disabled; this phase is memory-intensive on large codebases)

**Phase 4: RuleExecution**
- Creating `AnalysisContext` with repository, dependency graph, circular dependency results, duplicate blocks, and rule options
- Executing all rules via `RuleExecutor`
- Applying filters (Baseline, Suppression)

`RuleExecutor` also applies the **per-rule** `exclude_namespaces`,
`exclude_namespace_channels`, and `exclude_paths` options — extracted for any rule name by
`RuleOptionsFactory` (`Qualimetrix\Configuration`), regardless of whether that rule's Options
class declares such a field. `exclude_namespaces` remains producer-wide. The channel-scoped
form first matches `Violation::violationCode` by exact or dot-boundary prefix and then filters
only namespace-aggregate `SymbolPath`s matching its namespace prefix/glob list; class findings
and sibling channels remain. This is distinct from the global
`exclude_namespaces` / `exclude_paths` filters applied later in
`ViolationFilterPipeline` (`Qualimetrix\Infrastructure\Console`): it runs immediately after each
rule's `analyze()` call and is *not* exempted for `architecture.*` rules the way the global
filter is. Suppressed counts and the suppressed violations themselves are exposed via
`RuleExecutor::getRuleExclusionStats(): RuleExclusionStats`, consumed by
`ViolationFilterOrchestrator` for `-v` / `--show-suppressed` output. Channel-scoped removals are
included in the namespace-exclusion count. The per-rule counts are always collected, but the
individual `Violation` objects are retained only when
`Core\Violation\RuleExclusionCaptureHolder` is enabled (set from `--show-suppressed` by
`RuntimeConfigurator`) — holding onto every suppressed violation regardless of whether
`--show-suppressed` was passed would waste memory on codebases with wide per-rule exclusions.

Rule selection is channel-aware. `Core\Rule\RuleSelector` first decides whether a
producer must run from the producer-to-channel registry, then filters each finding while
the producer identity is still available. This is required for computed metrics
(`computed.health#health.*`) and Architecture diagnostics such as
`architecture.layer-violation` producing `architecture.coverage#architecture.coverage`.
The same selector guards Architecture preparation, circular-dependency detection, and
duplication detection, so prerequisite work and rule execution cannot disagree.

**Phase 5: Result**
Building and returning `AnalysisResult`.

### Full Dependency Graph Principle

The dependency graph is always built from ALL project files, ensuring:

- Afferent couplings (Ca) are always visible (Instability = Ce / (Ca + Ce) is correct)
- ClassRank (PageRank) reflects the complete project graph
- Distance from Main Sequence is accurate
- Health scores are computed from the full graph

When `--report=git:staged` is used:
1. `AnalysisPipeline` collects metrics from all files (cache amortizes the cost)
2. `ViolationFilterPipeline` filters violations to changed files
3. Formatters show full health scores; worst offenders are from the complete graph

---

## AnalysisResult

Analysis result.

**Fields:**
- `violations: array<Violation>`
- `coverage: AnalysisCoverage` — canonical per-path terminal states and completeness verdict
- `filesAnalyzed: int` — derived from canonical coverage
- `filesSkipped: int` — derived from canonical coverage (generated exclusions + failures)
- `duration: float`
- `metrics: MetricRepositoryInterface`

**Methods:**
- `hasErrors(): bool`, `hasWarnings(): bool`
- `getExitCode(): int` — 0/1/2

`AnalysisResult` requires an `AnalysisCoverage`; aggregate counters cannot be
supplied independently. Likewise, `CollectionResult` requires the exact
successful paths and typed failed results and derives its counters from those
terminal states. The pipeline compares their union with the discovered eligible
paths and fails fast when the sets differ.

---

## Discovery

Finding PHP files for analysis.

**FinderFileDiscovery** — implementation via Symfony Finder:
- Searches for `*.php` in specified paths
- Sorts by name
- Returns a Generator for memory efficiency

**GeneratedFileFilter** — filters out generated files (e.g., auto-generated proxies, compiled templates) from analysis.

---

## Namespace Detection

### Psr4NamespaceDetector

Primary strategy — based on directory structure from `composer.json`.

**Algorithm:**
1. Loading mapping from `autoload` + `autoload-dev`
2. Finding matching prefix by file realpath
3. Computing namespace from relative path

### TokenizerNamespaceDetector

Fallback — parsing file tokens (reads first 4KB, looks for `T_NAMESPACE`).

### ChainNamespaceDetector

Chain of Responsibility — tries detectors in order, returns the first non-empty result.

### ProjectNamespaceResolver

Project-level namespace resolution — determines the root namespaces for the analyzed project.

---

## FileProcessor

Processing a single PHP file: parsing, collecting metrics AND dependencies, memory cleanup.

**Algorithm:**
1. Parsing AST (with caching)
2. Collecting metrics AND dependencies via `CompositeCollector` (unified AST traversal)
3. Extracting method, class, and namespace-owned metric contributions
4. Building immutable `DeclarationBindings` from the AST and collected declaration metrics
5. Extracting immutable `SourceControls` (suppressions, threshold overrides, diagnostics)
6. Memory cleanup: `unset($ast)` + `gc_collect_cycles()`
7. Returning `FileProcessingResult`

`DeclarationBindings` owns the declaration-binding subject: it maps named class
and callable declarations (including closures, arrows, property hooks, and
parameters) to exact `MetricSubject`s, preserving lexical ownership and the
file fallback. `SourceControls` consumes only the AST, those bindings, and the
Baseline extractors to produce immutable control lists. Both depend downward on
Core/Baseline contracts; parsing, collector invocation, and collection-wire
assembly remain owned by `FileProcessor`. Focused unit tests cover declaration
binding and source-control extraction, while `FileProcessorTest` pins their
transport through `FileProcessingResult`.

Parse exceptions are caught and returned as `FileProcessingResult::failure()`.
Successful results carry a cohesive `CollectedFileData` payload. Unexpected
sequential and parallel worker exceptions both become typed `processing`
failures, while parser exceptions are typed `parse` failures.

`AnalysisCoverage` is the canonical run verdict. It assigns every discovered
path exactly one terminal state: analyzed, intentionally generated-excluded, or
failed (`parse` / `processing`). Intentional exclusions preserve completeness;
failures do not. Reporting projects this contract into `ReportCoverage`, while
artifact writers reject incomplete runs with `IncompleteAnalysisException`
([ADR 0018](../../docs/adr/0018-analysis-coverage-verdict-and-output-projection.md)).

---

## CollectionOrchestrator

Coordinates the Collection phase: execution strategy selection, file processing, metric registration.

**Algorithm:**
1. Executing strategy (sequential or parallel)
2. For each file: registering metrics and dependencies in repository
3. Progress tracking
4. Returning `CollectionResult`

---

## ExecutionStrategy

Abstraction for choosing between sequential and parallel execution.

The `Collection/Strategy/` directory contains only contracts (`ExecutionStrategyInterface`, `ParallelCapableInterface`, `StrategySelectorInterface`). Concrete implementations live in `Infrastructure/Parallel/`:

- **SequentialStrategy** — fallback for systems without pcntl
- **AmphpParallelStrategy** — parallel execution via `amphp/parallel`
- **StrategySelector** — automatic strategy selection based on available extensions
- **Serializer/** — IgbinarySerializer, PhpSerializer, SerializerSelector

### Performance

**Expected speedup (1000 files):**

| Workers | Time | Speedup |
| ------- | ---- | ------- |
| 1       | 30s  | 1x      |
| 2       | 16s  | 1.9x    |
| 4       | 9s   | 3.3x    |
| 8       | 5s   | 6x      |

Speedup is not linear due to fork overhead, IPC serialization, disk I/O contention.

---

## CompositeCollector

Combines visitors of all collectors and DependencyVisitor for a single AST pass (unified AST traversal).

**Algorithm:**
1. Creating NodeTraverser
2. Adding visitors of all collectors + DependencyVisitor
3. **One** AST traversal
4. Collecting and merging all MetricBags
5. Collecting dependencies from DependencyVisitor
6. Returning `CollectionOutput(metrics, dependencies)`

`CompositeCollector` owns only base collector traversal and collection output.
`DerivedCollectorRunner` receives the completed base bag, base collector
subjects, and the relative file path. It validates collector-name dependencies,
orders derived collectors topologically with deterministic name ties, and
accumulates each derived result into the next collector's input. It applies
only a `MetricDefinition`'s declared `collectedAt` level to its exact callable
declaration or class declaration key; it does not resolve by FQN, line, or a
fallback `provides()` name. `DerivedMetricExtractor` then transfers those
already-keyed results into existing repository subjects. The runner depends
downward on Core metric contracts; it does not orchestrate the pipeline or the
repository.

Focused tests are `CompositeCollectorTest`, `DerivedCollectorRunnerTest`,
`DerivedCollectorSortTest`, and `DerivedMetricExtractorTest`. They cover base
traversal separation, missing and cyclic collector dependencies, deterministic
ties, multi-level derived values, empty subject collections, exact duplicate
callable declarations, and repository result extraction.

---

## MetricAggregator (Decomposed)

Aggregates metrics by hierarchy levels based on `MetricDefinition` from collectors. Completely generic — no hardcoded metric names.

The aggregator has been decomposed into individual phases, each implementing `AggregationPhaseInterface`:

- **CallableToClassAggregator** — applies strategies from `aggregations[Class_]` (result: `ccn.sum`, `ccn.avg`, `ccn.max`)
- **ClassToNamespaceAggregator** — applies strategies from `aggregations[Namespace_]`. For method-collected metrics (CCN, Cognitive, NPath, MI), namespace-level aggregation reads raw callable-level values directly (not class-level sums), so `.max`/`.avg`/`.p95` reflect per-method statistics
- **NamespaceToProjectAggregator** — aggregates across all namespaces; handles both class-collected metrics (promoted from namespace via `aggregations[Project_]`) and namespace-collected metrics (e.g., `distance`, `abstractness`, `ce.p95`) that already exist at namespace level and are aggregated directly to project level

`MetricAggregator` is now a thin orchestrator that runs these phases in order. `AggregationHelper` provides generic aggregation arithmetic, while `NamespaceMetricContributions` resolves namespace-owned values and their physical-file mapping.

File-collected metrics may expose explicit namespace-owned contributions through
`NamespaceMetricProviderInterface`. Namespace aggregation prefers those contributions;
project aggregation deliberately reads physical file bags, so a multi-namespace file
is counted once at project level.
For a metric whose namespace strategy is exactly `Sum`, one explicit namespace total is
contributed once rather than split across source contributors. This preserves discrete
values such as class counts for downstream abstractness calculations and count gates.
This ownership split is specified by
[ADR 0019](../../docs/adr/0019-namespace-metric-ownership-and-attribution.md).

### Duplication candidate discovery

Duplication detection makes a bounded-memory pre-pass over rolling token hashes. Its
fixed-size saturating filter retains no positions: hash collisions can only add candidates.
A second full pass rebuilds every position for those candidates, then token comparison in
`DuplicateBlockFinder` verifies equality. Consequently a true repeated hash is never lost,
while first-pass memory is independent of the number of token windows.

**Naming convention:** `{metric}.{strategy}` (e.g.: `ccn.sum`, `ccn.avg`, `loc.sum`)

---

## Dependency Graph

The dependency graph is built during the Collection phase and used for architecture rules.

### DependencyGraph

Value object representing the dependency graph between classes.

**Methods:**
- `getNodes(): array<string>` — list of classes
- `getDependencies(string $class): array<string>` — class dependencies
- `getDependents(string $class): array<string>` — who depends on this class

**Representation:** `A -> B` means "A depends on B" (A uses B).

### DependencyGraphBuilder

Builds the graph from exact declaration-source dependencies and the explicit
logical-class universe. `build(array $dependencies, iterable $logicalClassUniverse)`
always receives that universe, so classes with no dependencies remain graph vertices.

### DependencyGraphAnalyzer

`Pipeline/DependencyGraphAnalyzer` is the graph-only orchestration path used by
graph export. It owns discovery, parsing, traversal, graph construction, and the
canonical terminal-state coverage result. Graph primitives, visitors, and the
builder remain under `Collection/Dependency/` because they are collection-time
dependency mechanics rather than orchestration.

### DependencyVisitor (Decomposed)

Collects dependencies from AST. Integrated into `CompositeCollector` for unified AST traversal. Delegates to specialized handlers via `NodeDependencyHandlerInterface`.

**Handlers** (in `Handler/` directory):
- `ClassLikeHandler` — `use` statements, `extends`, `implements`
- `TraitUseHandler` — trait usage
- `PropertyHandler` — property type dependencies
- `FunctionLikeHandler` — parameter/return types and parameter attributes for any function-like signature (class methods, closures, arrow functions)
- `InstantiationHandler` — `new ClassName()`
- `StaticAccessHandler` — `ClassName::method()`
- `CatchInstanceofHandler` — catch blocks, instanceof checks

**Shared infrastructure:**
- `DependencyContext` — context passed to handlers during traversal
- `TypeDependencyHelper` — extracts class names from type nodes

### DependencyResolver

Resolves class dependencies from collected data.

### Graph Export

**GraphExporterInterface** — contract for graph exporters.
**DotExporter** — exports dependency graph in DOT format for visualization with Graphviz.
**JsonGraphExporter** — exports dependency graph in JSON format for programmatic consumption.

### CircularDependencyDetector

Detects circular dependencies using **Tarjan's SCC algorithm**.

**Complexity:** O(V + E) — for a project with 1000 classes and 5000 dependencies this is ~10ms.

**Canonical ordering.** The SCC partition is unique, but member order inside an SCC
falls out of the traversal order, which follows file discovery order. Since the first
member becomes the violation's symbol path (and thus its baseline hash), the detector
normalises it: members are sorted by canonical symbol key, `findPath()` starts from
that first member and expands neighbours in canonical order, and the returned cycles
are sorted by representative. The output is a function of the graph structure alone —
an unrelated file cannot change the identity of an existing cycle.

### Cycle

Dependency cycle value object. `getClasses()` is sorted by canonical key; its first
entry is the cycle representative, and `getPath()` starts and ends there.

**Methods:**
- `getSize(): int`
- `toString(): string` — "App\A -> App\B -> App\A"
- `toShortString(): string` — "A -> B -> A", each member disambiguated within the cycle (see below)
- `toTruncatedShortString(int $maxEntries = 5): string` — first `$maxEntries` members plus "... (N more)" for large cycles
- `toStructuredData(): array{cycle: list<string>, length: int, category: string}` — fully qualified members, for machine consumers (e.g. the `Cycle data:` JSON trailer in `CircularDependencyRule`)

**Label rendering:** `CycleMemberLabels` — builds the short display label for each member of a cycle. A member keeps its bare class name when no other member ends with it; otherwise it grows by whole namespace segments until it does tell them apart, and is anchored at the root (`\Writer`) when even its full name is a suffix of another member's. Built once per `Cycle` over its whole membership — `getClasses()`, not just the displayed path — so the two renderings agree and a namesake outside the displayed loop still counts.

---

## Repository

### InMemoryMetricRepository

Stores metrics in memory.

**Key methods:**
- `add(SymbolPath, MetricBag, file, line)` — add with automatic merge
- `addCallable(CallableWithMetrics)` — retain exact declaration identity and metadata
- `allDeclarations()`, `allCallables()`, `allLogicalClasses()` — typed declaration/class views
- `getNamespaces()` — list of namespaces
- `forNamespace(string)` — symbols in namespace

`MetricSubjectIndex` owns typed `MetricSubject` metrics, exact `SymbolInfo`,
and the unique logical-callable-to-exact-declaration bridge. Its inputs are
typed subject writes and `CallableWithMetrics`; its outputs are exact lookup and
declaration/callable/logical-class iteration. Aggregate subjects keep their
typed metadata there, while their metrics use the canonical plain aggregate
`SymbolPath` storage and are synchronized from it. `NamespaceMetricIndex` owns the
deduplicated namespace projection of aggregate and typed infos, excluding exact
class declarations during rebuild so their logical-class projection is counted
once. `RepositoryMerge` owns deterministic combination of plain and typed
storage: right-hand scalar metrics override, structured entries append, typed
callable metadata promotes plain metadata in either order, and conflicting
typed metadata fails fast. These helpers depend only on Core contracts and
each other inside the Repository leaf; the public repository API remains in
`InMemoryMetricRepository`.

Focused repository tests pin duplicate logical declarations, empty indexes,
namespace projection rebuild/deduplication, typed metadata conflicts, and
scalar/structured merge collisions. `InMemoryMetricRepositoryTest` covers the
public API and both merge orders; `GoldenFileAggregationTest` confirms that
aggregation values remain unchanged.
