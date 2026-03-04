# Analysis — Analysis Orchestration

## Overview

Analysis is the orchestrator of static analysis. It implements a four-phase pipeline:

1. **Collection** — gathering metrics AND dependencies in a single AST traversal (parallelizable via amphp/parallel)
2. **Aggregation** — aggregation by namespace/module, building the dependency graph
3. **RuleExecution** — generating violations based on metrics and graph
4. **Reporting** — producing the report (performed in the Reporting module)

## Structure

```
Analysis/
├── Pipeline/                            # Orchestration of all phases
│   ├── AnalysisPipeline.php             # Main orchestrator
│   └── AnalysisResult.php               # Analysis result
│
├── Discovery/                           # File discovery
│   └── FinderFileDiscovery.php
│
├── Collection/                          # Data collection
│   ├── FileProcessor.php                # Single file processing
│   ├── CollectionOrchestrator.php       # Collection coordination
│   │
│   ├── Metric/
│   │   └── CompositeCollector.php       # Combines visitors (unified AST traversal)
│   │
│   ├── Dependency/
│   │   ├── DependencyGraph.php          # Dependency graph
│   │   ├── DependencyGraphBuilder.php
│   │   ├── DependencyVisitor.php
│   │   ├── CircularDependencyDetector.php # Tarjan's algorithm
│   │   └── Cycle.php
│   │
│   └── Strategy/                        # Execution strategies
│       ├── SequentialStrategy.php
│       ├── AmphpParallelStrategy.php
│       ├── StrategySelector.php
│       └── Serializer/                  # IgbinarySerializer, PhpSerializer
│
├── Aggregation/
│   ├── MetricAggregator.php             # Metric aggregation by levels
│   └── GlobalCollectorRunner.php
│
├── RuleExecution/
│   └── RuleExecutor.php
│
├── Repository/
│   └── InMemoryMetricRepository.php
│
└── Namespace_/                          # Namespace detection
    ├── ChainNamespaceDetector.php
    ├── Psr4NamespaceDetector.php
    └── TokenizerNamespaceDetector.php
```

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

**Phase 4: RuleExecution**
- Creating `AnalysisContext` with repository, dependency graph, and rule options
- Executing all rules via `RuleExecutor`
- Applying filters (Baseline, Suppression)

**Phase 5: Result**
Building and returning `AnalysisResult`.

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

### SequentialStrategy

Fallback for systems without pcntl — processes files sequentially.

### AmphpParallelStrategy

Implementation via `amphp/parallel`:
- Automatic transport selection (ext-parallel threads or pcntl fork)
- Optimized IPC
- igbinary/PHP serialize support
- Graceful fallback

### StrategySelector

Automatic strategy selection:
- amphp/parallel available AND (ext-pcntl OR ext-parallel) -> `AmphpParallelStrategy`
- Otherwise -> `SequentialStrategy`

### Serializer

**IgbinarySerializer** — uses `ext-igbinary` (2-5x faster, 50% smaller size).
**PhpSerializer** — built-in fallback.
**SerializerSelector** — automatic selection of the best serializer.

### Performance

**Expected speedup (1000 files):**

| Workers | Time | Speedup |
|---------|------|---------|
| 1 | 30s | 1x |
| 2 | 16s | 1.9x |
| 4 | 9s | 3.3x |
| 8 | 5s | 6x |

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

## MetricAggregator

Aggregates metrics by hierarchy levels based on `MetricDefinition` from collectors. Completely generic — no hardcoded metric names.

**Algorithm (3 phases):**

1. **Method -> Class**: applying strategies from `aggregations[Class_]` (result: `ccn.sum`, `ccn.avg`, `ccn.max`)
2. **File/Class -> Namespace**: applying strategies from `aggregations[Namespace_]`
3. **Namespace -> Project**: aggregating across all namespaces

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

### DependencyVisitor

Collects dependencies from AST. Integrated into `CompositeCollector` for unified AST traversal.

**What counts as a dependency:**
- `use` statements, `extends`, `implements`
- Parameter/return/property types
- `new ClassName()`, `ClassName::method()`

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

Stores metrics in memory. For large projects (>100K LOC) `SqliteStorage` is recommended.

**Key methods:**
- `add(SymbolPath, MetricBag, file, line)` — add with automatic merge
- `getNamespaces()` — list of namespaces
- `forNamespace(string)` — symbols in namespace

### SqliteStorage

Alternative implementation for large projects — stores metrics in SQLite:
- Minimal memory consumption
- Persistence between runs
- WAL mode, 64MB cache, memory-mapped I/O

**StorageFactory** automatically selects implementation: SQLite for projects > 1000 files, InMemory by default.
