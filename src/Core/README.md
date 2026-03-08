# Core — Contracts and Primitives

## Overview

Core contains base contracts, Value Objects and Enums used by all other domains. Core has no dependencies except PHP and php-parser (only for Node types).

## Structure

```
Core/
├── Metric/
│   ├── MetricBag.php
│   ├── MetricCollectorInterface.php
│   ├── MetricDefinition.php              # VO for aggregation descriptions
│   ├── MetricRepositoryInterface.php
│   ├── MethodMetricsProviderInterface.php
│   ├── MethodWithMetrics.php
│   ├── ClassMetricsProviderInterface.php  # Provider for class-level metrics
│   ├── ClassWithMetrics.php               # VO for class with metrics
│   ├── DerivedCollectorInterface.php      # Derived (composite) collectors
│   ├── GlobalContextCollectorInterface.php # Cross-file collectors
│   ├── AggregationStrategy.php            # Strategy enum
│   └── SymbolLevel.php                    # Hierarchy level enum
├── Rule/
│   ├── RuleInterface.php
│   ├── RuleCategory.php
│   ├── RuleOptionsInterface.php           # Base options interface
│   ├── AnalysisContext.php
│   ├── HierarchicalRuleInterface.php      # Multi-level rules
│   ├── HierarchicalRuleOptionsInterface.php
│   ├── LevelOptionsInterface.php          # Level-specific options
│   ├── RuleLevel.php                      # Rule level enum
│   └── RuleMatcher.php                    # Prefix matching utility
├── Symbol/
│   ├── SymbolType.php
│   ├── SymbolInfo.php
│   ├── MethodInfo.php
│   ├── ClassInfo.php
│   └── ClassType.php
├── Ast/
│   └── FileParserInterface.php
├── Namespace_/
│   ├── NamespaceDetectorInterface.php
│   └── ProjectNamespaceResolverInterface.php
├── Dependency/
│   ├── DependencyGraphInterface.php
│   ├── Dependency.php
│   ├── CycleInterface.php
│   ├── DependencyType.php                 # Dependency type enum
│   └── EmptyDependencyGraph.php           # No-op graph implementation
├── Violation/
│   ├── Violation.php
│   ├── Severity.php
│   ├── SymbolPath.php
│   ├── Location.php
│   └── Filter/
│       ├── ViolationFilterInterface.php
│       └── PathExclusionFilter.php        # Filters by file path patterns
├── Progress/
│   ├── ProgressReporter.php               # Progress reporting interface
│   └── NullProgressReporter.php           # No-op implementation
├── Profiler/
│   ├── ProfilerInterface.php              # Performance profiler interface
│   ├── ProfilerHolder.php                 # Static holder for profiler instance
│   ├── NullProfiler.php                   # No-op profiler
│   └── Span.php                           # Profiling span VO
├── Util/
│   ├── StringSet.php                      # Immutable set of unique strings
│   └── PathMatcher.php                    # Glob pattern matching for file paths
└── Exception/
    └── ParseException.php
```

---

## Metric Contracts

### MetricCollectorInterface

A metric collector gathers a specific group of metrics from AST.

**Methods:**
- `getName(): string` — unique collector name
- `provides(): array<string>` — list of collected metrics (for dependency resolution)
- `getMetricDefinitions(): array<MetricDefinition>` — metric descriptions and aggregation strategies
- `getVisitor(): NodeVisitorAbstract` — visitor for AST traversal
- `collect(SplFileInfo $file, array $ast): MetricBag` — metric collection after traversal
- `reset(): void` — reset visitor state between files

**DI Tags:** `aimd.collector`

### DerivedCollectorInterface

Collector that derives metrics from other collectors' results. Executed **after** all regular collectors complete, in a separate phase. Calculates composite metrics from base metrics (e.g., Maintainability Index from Halstead Volume, CCN, and LOC).

**Methods:**
- `getName(): string` — unique collector name
- `requires(): array<string>` — names of required collectors
- `provides(): array<string>` — list of provided metric names
- `getMetricDefinitions(): array<MetricDefinition>` — metric definitions
- `calculate(MetricBag $sourceBag): MetricBag` — calculate derived metrics from source metrics

**DI Tags:** `aimd.derived_collector`

### GlobalContextCollectorInterface

Collector that computes metrics from global context (cross-file knowledge). Unlike `MetricCollectorInterface` which operates on individual files via AST, this operates on already-collected metrics and the dependency graph. Used for coupling, distance, and other cross-file metrics.

**Methods:**
- `getName(): string` — unique collector name
- `requires(): array<string>` — required metric names (for topological sorting)
- `provides(): array<string>` — list of provided metric names
- `getMetricDefinitions(): array<MetricDefinition>` — metric definitions
- `calculate(DependencyGraphInterface $graph, MetricRepositoryInterface $repository): void` — compute and store metrics

**DI Tags:** `aimd.global_collector`

### MethodMetricsProviderInterface

Optional interface for collectors that provide method/function-level metrics.

Allows Analyzer to extract detailed metrics without knowledge of specific collector types.
This ensures proper layer separation: Analysis depends on Core abstractions, not on Metrics implementations.

**Methods:**
- `getMethodsWithMetrics(): list<MethodWithMetrics>` — returns method metrics after AST traversal

**Usage:** Implemented by collectors that gather method-level metrics (e.g., CyclomaticComplexityCollector).

### ClassMetricsProviderInterface

Optional interface for collectors that provide class-level metrics.

Analogous to `MethodMetricsProviderInterface` but for class-level data. Allows extracting class metrics without knowing concrete collector types.

**Methods:**
- `getClassesWithMetrics(): list<ClassWithMetrics>` — returns class metrics after AST traversal

**Usage:** Implemented by collectors that gather class-level metrics (e.g., TccLccCollector, RfcCollector).

### MethodWithMetrics

Value Object — a method/function with collected metrics.

**Fields:**
- `namespace: ?string` — namespace (null for global functions)
- `class: ?string` — class name (null for functions)
- `method: string` — method/function name
- `line: int` — line number
- `metrics: MetricBag` — collected metrics

**Methods:**
- `getSymbolPath(): ?SymbolPath` — creates SymbolPath (null for closures)

### ClassWithMetrics

Value Object — a class with collected metrics.

**Fields:**
- `namespace: ?string` — namespace (null for global scope)
- `class: string` — class name
- `line: int` — line number
- `metrics: MetricBag` — collected metrics

**Methods:**
- `getSymbolPath(): SymbolPath` — creates SymbolPath for this class
- `toSymbolInfo(string $filePath): SymbolInfo` — creates SymbolInfo with file path

### MetricBag

Value Object — metric container for a single entity (file/class/method).

**Methods:**
- `with(string $name, int|float $value): self` — returns new MetricBag with the metric set (immutable)
- `fromArray(array $metrics): self` — static factory method
- `get(string $name): int|float|null`
- `has(string $name): bool`
- `all(): array<string, int|float>`
- `merge(self $other): self` — merge metrics (for parallelization)
- `withPrefix(string $prefix): self` — adds prefix to metric names

**Serializable:** Yes (for inter-process transfer)

### MetricRepositoryInterface

Access to collected metrics for rules. Uses `SymbolPath` for unified access.

**Methods:**
- `get(SymbolPath $symbol): MetricBag` — metrics for any symbol
- `all(SymbolType $type): iterable<SymbolInfo>` — iterator over symbols of a given type
- `has(SymbolPath $symbol): bool` — check if metrics exist

All symbol levels (Method, Class, File, Namespace, Project) return `MetricBag`.
Aggregated metrics use naming convention: `{metric}.{strategy}` (e.g., `ccn.sum`, `loc.avg`).

**SymbolType (Enum):**
```php
enum SymbolType: string {
    case Method;     // all methods
    case Function_;  // all functions
    case Class_;     // all classes
    case File;       // all files
    case Namespace_; // all namespaces
    case Project;    // project-level (aggregated from all namespaces)
}
```

**Usage examples:**
```php
// Method metrics (raw)
$metrics = $repository->get(SymbolPath::forMethod('App\Service', 'UserService', 'calculate'));
$ccn = $metrics->get('ccn'); // int

// Namespace metrics (aggregated)
$nsMetrics = $repository->get(SymbolPath::forNamespace('App\Service'));
$avgCcn = $nsMetrics->get('ccn.avg'); // float
$totalLoc = $nsMetrics->get('loc.sum'); // int
$classCount = $nsMetrics->get('classCount.sum'); // int

// Iterate over all methods
foreach ($repository->all(SymbolType::Method) as $methodInfo) {
    $metrics = $repository->get($methodInfo->symbolPath);
}
```

**Advantages of a unified API:**
- Single `MetricBag` type for all levels — simpler to work with
- Naming convention `{metric}.{strategy}` — clear which aggregation was applied
- SymbolPath is already used for violations — reuse

### AggregationStrategy (Enum)

Defines how metrics are aggregated when transitioning to a higher level.

| Value     | Description        |
| --------- | ------------------ |
| `Sum`     | Sum of values      |
| `Average` | Arithmetic mean    |
| `Max`     | Maximum            |
| `Min`     | Minimum            |
| `Count`   | Number of elements |

### SymbolLevel (Enum)

Hierarchy level of a symbol in the aggregation tree.

| Value        | Description                   |
| ------------ | ----------------------------- |
| `Method`     | Method or function (leaf)     |
| `Class_`     | Class, interface, trait, enum |
| `File`       | File                          |
| `Namespace_` | Namespace                     |
| `Project`    | Project (root)                |

### MetricDefinition

Value Object — describes a metric and its aggregation strategies.

**Fields:**
- `name: string` — base name (`ccn`, `loc`, `classCount`)
- `collectedAt: SymbolLevel` — collection level
- `aggregations: array<string, list<AggregationStrategy>>` — strategies by level

**Methods:**
- `aggregatedName(AggregationStrategy $strategy): string` — `{name}.{strategy}`
- `getStrategiesForLevel(SymbolLevel $level): list<AggregationStrategy>`
- `hasAggregationsForLevel(SymbolLevel $level): bool`

**Example:**
```php
new MetricDefinition(
    name: 'ccn',
    collectedAt: SymbolLevel::Method,
    aggregations: [
        'class' => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
        'namespace' => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
        'project' => [AggregationStrategy::Sum, AggregationStrategy::Average, AggregationStrategy::Max],
    ],
);
```

---

## Rule Contracts

### RuleInterface

A rule analyzes metrics and generates violations. **Completely stateless.**

**Methods:**
- `getName(): string` — unique rule name (slug in `group.rule-name` format)
- `getDescription(): string` — human-readable description
- `getCategory(): RuleCategory` — category for grouping
- `requires(): array<string>` — required metrics (for auto-activation of collectors)
- `analyze(AnalysisContext $context): array<Violation>` — analyze metrics, generate violations

**Static:**
- `getOptionsClass(): class-string<RuleOptionsInterface>` — rule options class
- `getCliAliases(): array<string, string>` — CLI short aliases for options

**DI Tags:** `aimd.rule`

### HierarchicalRuleInterface

Extends `RuleInterface` for rules that operate on multiple levels of code hierarchy (method, class, namespace), with different thresholds and logic for each level.

**Methods:**
- `getSupportedLevels(): list<RuleLevel>` — levels at which this rule operates
- `analyzeLevel(RuleLevel $level, AnalysisContext $context): list<Violation>` — analyze at a specific level

### RuleOptionsInterface

Base options interface for all rules.

**Methods:**
- `fromArray(array $config): self` — create options from configuration array (static)
- `isEnabled(): bool` — whether the rule is enabled
- `getSeverity(int|float $value): ?Severity` — severity for a metric value (null if acceptable)

### HierarchicalRuleOptionsInterface

Extends `RuleOptionsInterface` with level-specific capabilities.

**Methods:**
- `forLevel(RuleLevel $level): LevelOptionsInterface` — options for a specific level
- `isLevelEnabled(RuleLevel $level): bool` — whether a specific level is enabled
- `getSupportedLevels(): list<RuleLevel>` — all supported levels

### LevelOptionsInterface

Options for a specific level of a hierarchical rule.

**Methods:**
- `fromArray(array $config): self` — create from configuration array (static)
- `isEnabled(): bool` — whether this level is enabled
- `getSeverity(int|float $value): ?Severity` — severity for the given metric value

### RuleLevel (Enum)

Levels of code hierarchy at which rules can operate.

| Value        | Description |
| ------------ | ----------- |
| `Method`     | Method      |
| `Class_`     | Class       |
| `Namespace_` | Namespace   |

**Methods:**
- `displayName(): string` — human-readable display name

### RuleMatcher

Utility for prefix matching of rule names and violation codes.

**Pattern matching rules:**
- Exact match: `'complexity.cyclomatic'` matches `'complexity.cyclomatic'`
- Prefix match: `'complexity'` matches `'complexity.cyclomatic'` (pattern + `.` is prefix of subject)
- No reverse: `'complexity.cyclomatic'` does NOT match `'complexity'`

**Methods:**
- `matches(string $pattern, string $subject): bool` — exact or prefix match
- `anyMatches(array $patterns, string $subject): bool` — any pattern matches subject
- `anyReverseMatches(array $patterns, string $subject): bool` — subject is prefix of any pattern

### RuleCategory (Enum)

| Value             | Description                            |
| ----------------- | -------------------------------------- |
| `Complexity`      | CCN, NPath, Cognitive, WMC             |
| `Size`            | MethodCount, ClassCount, PropertyCount |
| `Design`          | LCOM, NOC, Inheritance                 |
| `Maintainability` | Maintainability Index                  |
| `Coupling`        | Instability, CBO, Distance             |
| `Architecture`    | Circular Dependencies                  |
| `CodeSmell`       | Boolean Arguments, Debug Code, etc.    |

---

## Violation Value Objects

### Severity (Enum)

| Value     | Exit Code | Description        |
| --------- | --------- | ------------------ |
| `Warning` | 1         | Requires attention |
| `Error`   | 2         | Critical issue     |

### Location

Physical location of a violation in the file system.

**Fields:**
- `file: string` — file path
- `line: ?int` — line number (null for namespace-level)

**Methods:**
- `toString(): string` — `"file.php:42"` or `"file.php"`

### SymbolPath

Stable symbol identifier for baseline. Does not depend on line number.

**Fields:**
- `namespace: ?string` — `App\Service`
- `type: ?string` — `UserService` (class/interface/trait/enum)
- `member: ?string` — `calculateTotal` (method/function)

**Methods:**
- `toCanonical(): string` — canonical format for baseline

**Factory methods:**
- `forMethod(namespace, class, method): self`
- `forClass(namespace, class): self`
- `forNamespace(namespace): self` — use empty string for global PHP namespace
- `forProject(): self` — project-level (aggregated from all namespaces)
- `forFile(path): self`
- `forGlobalFunction(namespace, function): self`

**Canonical examples:**
- `App\Service\UserService::calculateTotal` — method
- `App\Service\UserService` — class
- `file:src/Service/UserService.php` — file
- `App\Service` — namespace
- `::globalFunction` — global function

### Violation

A rule violation.

**Fields:**
- `location: Location`
- `symbolPath: SymbolPath`
- `ruleName: string`
- `violationCode: string` — stable machine identifier for baseline hashing
- `message: string`
- `severity: Severity`
- `metricValue: int|float|null` — metric value (for reports)
- `level: ?RuleLevel` — rule level that produced this violation (null for non-hierarchical rules)

**Methods:**
- `getFingerprint(): string` — unique identifier for baseline (`ruleName:symbolPath`)

### ViolationFilterInterface

Foundation for baseline and suppression.

**Methods:**
- `shouldInclude(Violation $violation): bool` — whether to include violation in the report

### PathExclusionFilter

Suppresses violations whose file path matches configured exclusion patterns. Violations without a file (e.g., namespace-level or architectural) are never filtered.

**Constructor:** `__construct(PathMatcher $pathMatcher)`

---

## Dependency Contracts

### DependencyGraphInterface

Interface for querying the dependency graph. Provides coupling metrics (Ce/Ca) at class and namespace level.

### Dependency

Value Object representing a dependency between two classes.

### CycleInterface

Interface for circular dependency detection results.

### DependencyType (Enum)

Classifies all possible types of dependencies between classes.

| Value                 | Description              | Strong coupling |
| --------------------- | ------------------------ | --------------- |
| `Extends`             | Class inheritance        | Yes             |
| `Implements`          | Interface implementation | Yes             |
| `TraitUse`            | Trait usage              | Yes             |
| `New_`                | Object instantiation     | No              |
| `StaticCall`          | Static method call       | No              |
| `StaticPropertyFetch` | Static property access   | No              |
| `ClassConstFetch`     | Class constant access    | No              |
| `TypeHint`            | Type hint usage          | No              |
| `Catch_`              | Exception catching       | No              |
| `Instanceof_`         | Instanceof check         | No              |
| `Attribute`           | PHP 8 attribute          | No              |
| `PropertyType`        | Typed property           | No              |
| `IntersectionType`    | Intersection type        | No              |
| `UnionType`           | Union type               | No              |

**Methods:**
- `description(): string` — human-readable description
- `isStrongCoupling(): bool` — whether this type creates strong coupling

### EmptyDependencyGraph

No-op implementation of `DependencyGraphInterface`. Used when dependency collection is disabled. All queries return empty results / zero values.

---

## Progress Reporting

### ProgressReporter

Interface for tracking analysis progress.

**Methods:**
- `start(int $total): void` — start tracking with total item count
- `advance(int $step = 1): void` — advance by specified steps
- `setMessage(string $message): void` — set current operation message
- `finish(): void` — finish tracking and clean up

### NullProgressReporter

No-op implementation. Used in quiet mode, non-TTY output (CI, pipes), or with `--no-progress`.

---

## Profiler Contracts

### ProfilerInterface

Interface for profiling performance metrics. Tracks execution time and memory usage using a tree of spans.

**Methods:**
- `start(string $name, ?string $category = null): void` — start a new span
- `stop(string $name): void` — stop the most recent span with the given name
- `isEnabled(): bool` — whether profiling is active
- `getRootSpan(): ?Span` — root span of the profiling tree
- `getSummary(): array` — aggregated statistics grouped by span name
- `export(string $format): string` — export data (`'json'` or `'chrome-tracing'`)
- `clear(): void` — reset all profiling data

### ProfilerHolder

Static holder for global profiler access. Returns `NullProfiler` if no profiler has been set.

**Methods:**
- `set(ProfilerInterface $profiler): void` — set the profiler instance (during container init)
- `get(): ProfilerInterface` — get current profiler (or NullProfiler)
- `reset(): void` — reset instance (for testing)

### NullProfiler

No-op profiler for production use. Provides minimal overhead when profiling is disabled.

### Span

Value Object representing a profiling span (time interval). Spans can be nested to create a tree structure.

**Fields:**
- `name: string` — span name (e.g., `"FileProcessor::process"`)
- `category: ?string` — optional category (e.g., `"collection"`, `"analysis"`)
- `startTime: float` — start timestamp in nanoseconds
- `startMemory: int` — memory usage at start in bytes
- `endTime: ?float` — end timestamp (null if running)
- `endMemory: ?int` — memory at end (null if running)
- `parent: ?Span` — parent span (null for root)
- `children: list<Span>` — child spans

**Methods:**
- `getDuration(): ?float` — duration in milliseconds
- `getMemoryDelta(): ?int` — memory delta in bytes
- `isRunning(): bool` — whether span is still active

---

## Utility Classes

### StringSet

An immutable set of unique strings with O(1) lookups. Implements `Countable` and `IteratorAggregate`.

**Methods:**
- `add(string $value): self` — new set with the value added
- `addAll(iterable $values): self` — new set with multiple values added
- `contains(string $value): bool` — check membership
- `count(): int` — number of unique strings
- `isEmpty(): bool` — whether set is empty
- `toArray(): array<int, string>` — all strings as indexed array
- `filter(callable $predicate): self` — filter by predicate
- `union(self $other): self` — set union
- `intersect(self $other): self` — set intersection
- `diff(self $other): self` — set difference
- `fromArray(array $values): self` — create from array (static)

### PathMatcher

Matches file paths against glob patterns using `fnmatch()`. Used for `exclude_paths` configuration.

**Constructor:** `__construct(list<string> $patterns)`

**Methods:**
- `matches(string $filePath): bool` — whether path matches any pattern
- `isEmpty(): bool` — whether no patterns are configured

---

## Other Contracts

### FileParserInterface

**Methods:**
- `parse(SplFileInfo $file): array<Node>` — parse PHP file into AST
- Throws: `ParseException`

### NamespaceDetectorInterface

**Methods:**
- `detect(SplFileInfo $file): string` — detect file namespace (empty string for global)

### ProjectNamespaceResolverInterface

Determines whether a namespace belongs to the project (not an external dependency).

**Methods:**
- `isProjectNamespace(string $namespace): bool` — check if namespace belongs to the project
- `getProjectPrefixes(): list<string>` — list of detected prefixes (without trailing backslash)

### ParseException

**Fields:**
- `file: string` — path to the file with error
- `message: string` — parse error description

---

## Info Classes for Iterators

### SymbolInfo

**Fields:**
- `symbolPath: SymbolPath`
- `file: string`
- `line: ?int`

### MethodInfo

**Fields:**
- `fqn: string` — `App\Service\User::calculate`
- `namespace: string`
- `class: string`
- `name: string`
- `file: string`
- `line: int`

**Methods:**
- `getSymbolPath(): SymbolPath` — creates SymbolPath for the method

### ClassInfo

**Fields:**
- `fqn: string` — `App\Service\User`
- `namespace: string`
- `name: string`
- `file: string`
- `line: int`
- `type: ClassType` — class/interface/trait/enum

**Methods:**
- `getSymbolPath(): SymbolPath` — creates SymbolPath for the class

---

## Implementation Stages

### Steps

1. [x] Severity enum
2. [x] RuleCategory enum
3. [x] Location VO
4. [x] SymbolPath VO
5. [x] Violation VO
6. [x] MetricBag VO
7. [x] AggregationStrategy enum
8. [x] SymbolLevel enum
9. [x] MetricDefinition VO
10. [x] MethodInfo, ClassInfo
11. [x] MetricCollectorInterface (with getMetricDefinitions)
12. [x] MetricRepositoryInterface (unified MetricBag)
13. [x] RuleInterface
14. [x] FileParserInterface
15. [x] NamespaceDetectorInterface
16. [x] ViolationFilterInterface
17. [x] ParseException
18. [x] Unit tests

### Definition of Done

- All contracts and VOs are created
- Unit tests for SymbolPath::toCanonical()
- Unit tests for MetricBag::merge()
- Unit tests for Violation::getFingerprint()
- Unit tests for MetricDefinition::aggregatedName()
- PHPStan level 8 with no errors

---

## Edge Cases

- Location with null line — display only file
- Global namespace — empty string
- SymbolPath with null namespace — starts with `::` for global functions
- MetricBag::get() for non-existent metric — null
- MetricBag::merge() with key conflict — value from `$other`
