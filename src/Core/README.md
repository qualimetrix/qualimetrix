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
│   ├── AggregationStrategy.php           # Strategy enum
│   └── SymbolLevel.php                   # Hierarchy level enum
├── Rule/
│   ├── RuleInterface.php
│   ├── RuleCategory.php
│   └── AnalysisContext.php
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
│   └── ProjectNamespaceResolver.php
├── Violation/
│   ├── Violation.php
│   ├── Severity.php
│   ├── SymbolPath.php
│   ├── Location.php
│   └── Filter/
│       └── ViolationFilterInterface.php
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

### MethodMetricsProviderInterface

Optional interface for collectors that provide method/function-level metrics.

Allows Analyzer to extract detailed metrics without knowledge of specific collector types.
This ensures proper layer separation: Analysis depends on Core abstractions, not on Metrics implementations.

**Methods:**
- `getMethodsWithMetrics(): list<MethodWithMetrics>` — returns method metrics after AST traversal

**Usage:** Implemented by collectors that gather method-level metrics (e.g., CyclomaticComplexityCollector).

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

### MetricBag

Value Object — metric container for a single entity (file/class/method).

**Methods:**
- `set(string $name, int|float $value): void`
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
enum SymbolType {
    case Method;    // all methods
    case Class_;    // all classes
    case File;      // all files
    case Namespace_; // all namespaces
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

| Value | Description |
|-------|-------------|
| `Sum` | Sum of values |
| `Average` | Arithmetic mean |
| `Max` | Maximum |
| `Min` | Minimum |
| `Count` | Number of elements |

### SymbolLevel (Enum)

Hierarchy level of a symbol in the aggregation tree.

| Value | Description |
|-------|-------------|
| `Method` | Method or function (leaf) |
| `Class_` | Class, interface, trait, enum |
| `File` | File |
| `Namespace_` | Namespace |
| `Project` | Project (root) |

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
- `getName(): string` — unique rule name (slug)
- `getDescription(): string` — human-readable description
- `getCategory(): RuleCategory` — category for grouping
- `requires(): array<string>` — required metrics (for auto-activation of collectors)
- `analyze(AnalysisContext $context): array<Violation>` — analyze metrics, generate violations

**Static:**
- `getOptionsClass(): class-string<RuleOptionsInterface>` — rule options class
- `getCliAliases(): array<string, string>` — CLI short aliases for options

**DI Tags:** `aimd.rule`

### RuleCategory (Enum)

| Value | Description |
|-------|-------------|
| `Complexity` | CCN, NPath, Cognitive, WMC |
| `Size` | MethodCount, ClassCount, PropertyCount |
| `Design` | LCOM, NOC, Inheritance |
| `Maintainability` | Maintainability Index |
| `Coupling` | Instability, CBO, Distance |
| `Architecture` | Circular Dependencies |
| `CodeSmell` | Boolean Arguments, Debug Code, etc. |

---

## Violation Value Objects

### Severity (Enum)

| Value | Exit Code | Description |
|-------|-----------|-------------|
| `Warning` | 1 | Requires attention |
| `Error` | 2 | Critical issue |

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
- `forNamespace(namespace): self`
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
- `metricValue: ?int` — metric value (for reports)

**Methods:**
- `getFingerprint(): string` — unique identifier for baseline (`ruleName:symbolPath`)

---

## Other Contracts

### FileParserInterface

**Methods:**
- `parse(SplFileInfo $file): array<Node>` — parse PHP file into AST
- Throws: `ParseException`

### NamespaceDetectorInterface

**Methods:**
- `detect(SplFileInfo $file): string` — detect file namespace (empty string for global)

### ProjectNamespaceResolver

Determines whether a namespace belongs to the project (not an external dependency).

**Purpose:**
- Reading `autoload.psr-4` and `autoload-dev.psr-4` from composer.json
- Extracting project namespace prefixes
- Checking namespace ownership (for filtering external dependencies)

**Constructor:**
- `__construct(?string $composerJsonPath = null, ?array $overridePrefixes = null)`
  - `composerJsonPath` — path to composer.json (null = auto-search in current directory and above)
  - `overridePrefixes` — prefix override (for testing or specific cases)

**Methods:**
- `isProjectNamespace(string $namespace): bool` — check if namespace belongs to the project
- `getProjectPrefixes(): list<string>` — list of detected prefixes (sorted by length descending)

**Usage examples:**
```php
// Auto-detection from composer.json
$resolver = new ProjectNamespaceResolver();
$resolver->isProjectNamespace('App\\Service'); // true (if App\\ is in autoload)
$resolver->isProjectNamespace('Vendor\\Package'); // false

// Explicit path to composer.json
$resolver = new ProjectNamespaceResolver('/path/to/composer.json');

// Prefix override (for testing)
$resolver = new ProjectNamespaceResolver(
    composerJsonPath: null,
    overridePrefixes: ['App\\', 'Domain\\'],
);
```

**Specifics:**
- Empty namespace (global scope) is considered project-owned
- Prefixes are normalized (trailing backslashes removed)
- Sorted by length (descending) for correct nested namespace matching
- Namespace boundary checking (App does not match Application)

### ViolationFilterInterface

Foundation for baseline and suppression.

**Methods:**
- `shouldInclude(Violation $violation): bool` — whether to include violation in the report

### ParseException

**Fields:**
- `file: string` — path to the file with error
- `message: string` — parse error description

---

## Info Classes for Iterators

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
