# Analysis — Analysis Orchestration

## Overview

Analysis is the orchestrator of static analysis. It implements a five-phase pipeline:

1. **Discovery** — finding PHP files for analysis
2. **Collection** — gathering metrics AND dependencies in a single AST traversal (parallelizable via amphp/parallel)
3. **Aggregation** — aggregation by namespace/module, building the dependency graph
4. **RuleExecution** — generating violations based on metrics and graph
5. **Reporting** — producing the report (performed in the Reporting module)

## Structure

```
Analysis/
├── Pipeline/                            # Orchestration of all phases
│   ├── AnalysisPipelineInterface.php    # Pipeline contract
│   ├── AnalysisPipeline.php             # Main orchestrator
│   ├── AnalysisResult.php               # Analysis result
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
│   ├── FileProcessingResult.php         # Single file result
│   │
│   ├── Metric/
│   │   ├── CompositeCollector.php       # Combines visitors (unified AST traversal)
│   │   ├── CollectionOutput.php         # Output of composite collection
│   │   └── DerivedMetricExtractor.php   # Extracts derived metrics from collected data
│   │
│   ├── Dependency/
│   │   ├── DependencyGraph.php          # Dependency graph
│   │   ├── DependencyGraphBuilder.php
│   │   ├── DependencyVisitor.php        # AST visitor (delegates to handlers)
│   │   ├── DependencyResolver.php       # Resolves class dependencies
│   │   ├── CircularDependencyDetector.php # Tarjan's algorithm
│   │   ├── Cycle.php
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
│   │   │   └── MethodHandler.php
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
│   ├── AggregationHelper.php            # Static helper methods
│   ├── MethodToClassAggregator.php      # Method → Class phase
│   ├── ClassToNamespaceAggregator.php   # Class → Namespace phase
│   ├── NamespaceToProjectAggregator.php # Namespace → Project phase
│   ├── MetricAggregator.php             # Thin orchestrator
│   ├── GlobalCollectorRunner.php        # Runs global (cross-file) collectors
│   └── GlobalCollectorSorter.php        # Topological sort of global collectors
│
├── Duplication/
│   ├── NormalizedToken.php              # VO: normalized token for comparison
│   ├── TokenNormalizer.php              # Normalizes PHP tokens for duplicate detection
│   └── DuplicationDetector.php          # Detects duplicate code blocks (config via DI)
│
├── RuleExecution/
│   ├── RuleExecutorInterface.php        # Rule executor contract
│   └── RuleExecutor.php
│
├── Repository/
│   └── InMemoryMetricRepository.php
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

## Internal Dependency Layers (deptrac)

Analysis sub-packages follow layered dependency rules:

- **Leaf** (no Analysis siblings): Exception, Discovery, Namespace\_, Repository, Duplication
- **Mid**: Aggregator depends on Exception; RuleExecution is standalone; Collection depends on Exception
- **Orchestrator**: Pipeline depends on all sub-layers

---

## AnalysisPipeline — Main Orchestrator

Coordinator of all analysis phases.

**Public API:**
- `analyze(string|array $paths, ?FileDiscoveryInterface $discovery = null): AnalysisResult`

### Algorithm

**Phase 1: Discovery**
Finding PHP files via `FileDiscoveryInterface`.

**Phase 2: Collection** (parallelizable)
- Selecting execution strategy (sequential/parallel)
- Processing files via `FileProcessor`
- Collecting metrics AND dependencies in a single AST traversal via `CompositeCollector`
- Building the dependency graph

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
- `filesAnalyzed: int`
- `filesSkipped: int`
- `duration: float`
- `metrics: MetricRepositoryInterface`

**Methods:**
- `hasErrors(): bool`, `hasWarnings(): bool`
- `getExitCode(): int` — 0/1/2

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
3. Memory cleanup: `unset($ast)` + `gc_collect_cycles()`
4. Returning `FileProcessingResult`

Parse exceptions are caught and returned as `FileProcessingResult::failure()`.

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

---

## MetricAggregator (Decomposed)

Aggregates metrics by hierarchy levels based on `MetricDefinition` from collectors. Completely generic — no hardcoded metric names.

The aggregator has been decomposed into individual phases, each implementing `AggregationPhaseInterface`:

- **MethodToClassAggregator** — applies strategies from `aggregations[Class_]` (result: `ccn.sum`, `ccn.avg`, `ccn.max`)
- **ClassToNamespaceAggregator** — applies strategies from `aggregations[Namespace_]`. For method-collected metrics (CCN, Cognitive, NPath, MI), namespace-level aggregation reads raw method-level values directly (not class-level sums), so `.max`/`.avg`/`.p95` reflect per-method statistics
- **NamespaceToProjectAggregator** — aggregates across all namespaces; handles both class-collected metrics (promoted from namespace via `aggregations[Project_]`) and namespace-collected metrics (e.g., `distance`, `abstractness`, `ce.p95`) that already exist at namespace level and are aggregated directly to project level

`MetricAggregator` is now a thin orchestrator that runs these phases in order. `AggregationHelper` provides shared static helper methods (extracted and refactored for reuse across phases) used by the aggregation phases.

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

Builds the graph from collected dependencies: grouping by classes -> building the graph.

### DependencyVisitor (Decomposed)

Collects dependencies from AST. Integrated into `CompositeCollector` for unified AST traversal. Delegates to specialized handlers via `NodeDependencyHandlerInterface`.

**Handlers** (in `Handler/` directory):
- `ClassLikeHandler` — `use` statements, `extends`, `implements`
- `TraitUseHandler` — trait usage
- `PropertyHandler` — property type dependencies
- `MethodHandler` — parameter/return types
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

### Cycle

Dependency cycle value object.

**Methods:**
- `getSize(): int`
- `toString(): string` — "App\A -> App\B -> App\A"
- `toShortString(): string` — "A -> B -> A"

---

## Repository

### InMemoryMetricRepository

Stores metrics in memory.

**Key methods:**
- `add(SymbolPath, MetricBag, file, line)` — add with automatic merge
- `getNamespaces()` — list of namespaces
- `forNamespace(string)` — symbols in namespace
