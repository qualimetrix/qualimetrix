# Metrics — Metric Collectors

## Overview

Metrics are collectors that gather metrics from the AST. Each collector:
- Implements `MetricCollectorInterface`
- Has its own Visitor for AST traversal
- Returns a `MetricBag` with collected metrics

Collectors **do not interpret** metrics — they only collect them. Interpretation happens in Rules.

---

## Metrics Table

| Metric                                                               | Category        | Level            | Description                                                                                                                                                                       |
| -------------------------------------------------------------------- | --------------- | ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Complexity**                                                       |                 |                  |                                                                                                                                                                                   |
| `ccn`                                                                | Complexity      | Method           | Cyclomatic Complexity — number of execution paths                                                                                                                                 |
| `cognitive`                                                          | Complexity      | Method           | Cognitive Complexity — difficulty of understanding code                                                                                                                           |
| `npath`                                                              | Complexity      | Method           | NPath Complexity — number of acyclic paths (exponential growth)                                                                                                                   |
| **Maintainability**                                                  |                 |                  |                                                                                                                                                                                   |
| `halstead.*`                                                         | Maintainability | Method           | Halstead metrics (volume, difficulty, effort, bugs, time)                                                                                                                         |
| `mi`                                                                 | Maintainability | Method           | Maintainability Index (derived from Halstead + CCN)                                                                                                                               |
| **Size**                                                             |                 |                  |                                                                                                                                                                                   |
| `loc`, `lloc`, `cloc`                                                | Size            | File             | Lines of Code (total, logical, comments)                                                                                                                                          |
| `classCount`, `interfaceCount`, `traitCount`, `enumCount`            | Size            | File             | Number of classes/interfaces/traits/enums                                                                                                                                         |
| `abstractClassCount`                                                 | Size            | File             | Number of abstract classes                                                                                                                                                        |
| `functionCount`                                                      | Size            | File             | Number of standalone functions                                                                                                                                                    |
| `propertyCount`                                                      | Structure       | Class            | Number of class properties (+ by visibility)                                                                                                                                      |
| `methodCount`                                                        | Structure       | Class            | Number of class methods (+ getters/setters)                                                                                                                                       |
| **Structure**                                                        |                 |                  |                                                                                                                                                                                   |
| `lcom`                                                               | Structure       | Class            | LCOM4 — method cohesion (graph components; edges from shared properties and `$this->method()` calls; static methods excluded)                                                     |
| `tcc`, `lcc`                                                         | Structure       | Class            | TCC/LCC — Tight/Loose Class Cohesion (0-1)                                                                                                                                        |
| `rfc`                                                                | Structure       | Class            | Response for Class — testability complexity                                                                                                                                       |
| `wmc`                                                                | Structure       | Class            | Weighted Methods per Class — sum of method CCN                                                                                                                                    |
| `dit`                                                                | Structure       | Class            | Depth of Inheritance Tree — inheritance depth                                                                                                                                     |
| `noc`                                                                | Structure       | Class            | Number of Children — number of direct subclasses                                                                                                                                  |
| `unusedPrivateMethods`, `unusedPrivateProperties`                    | Structure       | Class            | Count of unused private methods and properties                                                                                                                                    |
| **Coupling**                                                         |                 |                  |                                                                                                                                                                                   |
| `ca`, `ce`, `instability`                                            | Coupling        | Class            | Afferent/Efferent Coupling, instability                                                                                                                                           |
| `abstractness`                                                       | Coupling        | Namespace        | Proportion of abstract classes/interfaces                                                                                                                                         |
| `distance`                                                           | Coupling        | Namespace        | Distance from Main Sequence (derived)                                                                                                                                             |
| `classRank`                                                          | Coupling        | Class            | ClassRank — PageRank on dependency graph (GlobalContextCollector)                                                                                                                 |
| **Code Smell**                                                       |                 |                  |                                                                                                                                                                                   |
| `parameterCount`                                                     | Code Smell      | Method           | Number of method parameters                                                                                                                                                       |
| `unreachableCode`, `unreachableCode.firstLine`                       | Code Smell      | Method           | Unreachable code count after terminal statements and first unreachable line                                                                                                       |
| `identicalSubExpression.{type}` (entries)                            | Code Smell      | File             | Identical sub-expression findings per type via DataBag entries (`identical_operands`, `duplicate_condition`, `identical_ternary`, `duplicate_match_arm`, `duplicate_switch_case`) |
| **Design**                                                           |                 |                  |                                                                                                                                                                                   |
| `typeCoverage.param`, `typeCoverage.return`, `typeCoverage.property` | Design          | Class            | Type declaration coverage percentages (0-100) for parameters, return types, properties                                                                                            |
| `typeCoverage.pct`                                                   | Design          | Class            | Overall type coverage percentage (derived from typed/total counts)                                                                                                                |
| `typeCoverage.paramTotal`, `typeCoverage.paramTyped`                 | Design          | Class            | Raw counts: total and typed parameters                                                                                                                                            |
| `typeCoverage.returnTotal`, `typeCoverage.returnTyped`               | Design          | Class            | Raw counts: total and typed return declarations                                                                                                                                   |
| `typeCoverage.propertyTotal`, `typeCoverage.propertyTyped`           | Design          | Class            | Raw counts: total and typed properties                                                                                                                                            |
| **Security**                                                         |                 |                  |                                                                                                                                                                                   |
| `hardcodedCredentials`                                               | Security        | File             | Number of hardcoded credentials detected (passwords, API keys, secrets, tokens)                                                                                                   |
| `security.{type}` (entries)                                          | Security        | File             | Security pattern findings via DataBag entries (sql_injection, xss, command_injection)                                                                                             |
| `security.sensitiveParameter` (entries)                              | Security        | File             | Sensitive parameters without `#[\SensitiveParameter]` attribute, via DataBag entries                                                                                              |
| **Computed**                                                         |                 |                  |                                                                                                                                                                                   |
| `health.*`                                                           | Computed        | Class/NS/Project | Health scores (0-100) computed from aggregated metrics via Expression Language formulas                                                                                           |

---

## Detailed Documentation by Category

- **[Complexity/](Complexity/README.md)** — Cyclomatic, Cognitive, NPath Complexity
- **[Maintainability/](Maintainability/README.md)** — Halstead, Maintainability Index
- **[Size/](Size/README.md)** — LOC, Class Count, Property Count, Method Count
- **[Structure/](Structure/README.md)** — TCC/LCC, LCOM, RFC, WMC, DIT, NOC, Unused Private
- **[Coupling/](Coupling/README.md)** — Ca/Ce/Instability, Abstractness, Distance, ClassRank
- **[CodeSmell/](CodeSmell/README.md)** — Code pattern detectors (goto, eval, debug code, parameter count, unreachable code, identical sub-expressions, etc.)
- **[Design/](Design/)** — Type coverage metrics, type coverage percentage (derived)
- **[ComputedMetric/](ComputedMetric/)** — Computed metric evaluator (health scores via Expression Language)
- **[Security/](Security/)** — Hardcoded credentials, security pattern detection (SQL injection, XSS, command injection), sensitive parameter detection

---

## Self-Aggregating Metrics

The system uses a **Self-Aggregating Metrics** pattern — each collector declares how its metrics should be aggregated. The aggregator becomes generic and contains no hardcoded metric names.

### AggregationStrategy (Enum)

| Value     | Description        | Example   |
| --------- | ------------------ | --------- |
| `Sum`     | Sum of values      | `ccn.sum` |
| `Average` | Arithmetic mean    | `ccn.avg` |
| `Max`     | Maximum            | `ccn.max` |
| `Min`     | Minimum            | `mi.min`  |
| `Count`   | Number of elements | —         |

### SymbolLevel (Enum)

Symbol hierarchy for aggregation:

```
Project
  └── Namespace
      └── Class/Interface/Trait/Enum
          └── Method/Function

(File — separate level for file-scoped metrics)
```

### Naming Convention

Aggregated metrics are named: `{metric}.{strategy}`

**Examples:**
- `ccn.sum`, `ccn.avg`, `ccn.max` (sum/average/maximum CCN in class/namespace)
- `loc.sum`, `loc.avg` (sum/average LOC)
- `mi.avg`, `mi.min` (average/minimum Maintainability Index)
- `lcom.avg`, `lcom.max` (average/maximum LCOM)

---

## Collector Contract

**Methods:**
- `getName(): string` — unique name
- `provides(): array<string>` — which metrics it collects
- `getMetricDefinitions(): array<MetricDefinition>` — metric descriptions and aggregation strategies
- `getVisitor(): NodeVisitorAbstract` — visitor for AST
- `collect(SplFileInfo $file, array $ast): MetricBag` — collection after traversal
- `reset(): void` — state reset between files

**Optional interfaces:**
- `MethodMetricsProviderInterface` — provides method-level metrics
- `ClassMetricsProviderInterface` — provides class-level metrics
- `DerivedCollectorInterface` — derived metrics (require other collectors)
- `GlobalContextCollectorInterface` — use global context (dependency graph)

**DI tags:** `aimd.collector`

---

## MetricDefinition (VO)

Describes a metric and its aggregation strategies.

**Fields:**
- `name: string` — base name (`ccn`, `loc`, `classCount`)
- `collectedAt: SymbolLevel` — collection level (Method, File, ...)
- `aggregations: array<SymbolLevel, list<AggregationStrategy>>` — strategies by level

**Methods:**
- `aggregatedName(AggregationStrategy $strategy): string` — returns `{name}.{strategy}`
- `getStrategiesForLevel(SymbolLevel $level): list<AggregationStrategy>`

**Example:**

```php
new MetricDefinition(
    name: 'ccn',
    collectedAt: SymbolLevel::Method,
    aggregations: [
        SymbolLevel::Class_->value => [Sum, Average, Max],
        SymbolLevel::Namespace_->value => [Sum, Average, Max],
        SymbolLevel::Project->value => [Sum, Average, Max],
    ],
)
```

---

## Creating a New Collector

### Checklist

1. [ ] Create a `{Name}Collector` class in the appropriate category
2. [ ] Create a `{Name}Visitor` for AST traversal (with ResettableVisitorInterface)
3. [ ] Implement `provides(): array` — list of metrics
4. [ ] Implement `getMetricDefinitions(): array` — aggregation descriptions
5. [ ] Implement `collect()` — metric collection from visitor
6. [ ] For class-level metrics: implement `ClassMetricsProviderInterface`
7. [ ] For method-level metrics: implement `MethodMetricsProviderInterface`
8. [ ] Add `aimd.collector` DI tag (automatically via autoconfiguration)
9. [ ] Write unit tests (including a test for getMetricDefinitions)

### Metric Naming Conventions

Metrics are stored in separate `MetricBag` instances for each symbol, so keys do not contain FQN.

| Level     | Example Keys                                              |
| --------- | --------------------------------------------------------- |
| Method    | `ccn`, `cognitive`, `mi`, `halstead.volume`               |
| Class     | `methodCount`, `lcom`, `tcc`, `rfc`, `ccn.sum`, `ccn.avg` |
| File      | `loc`, `lloc`, `cloc`, `classCount`                       |
| Namespace | `loc.sum`, `loc.avg`, `ccn.sum`, `lcom.max`               |
| Project   | same as Namespace                                         |

---

## Edge Cases

- Anonymous classes — do not count in classCount, LCOM, TCC/LCC, DIT, RFC, CCN, NPath, Halstead
- Nested functions — separate metrics
- Closure — treated as a separate method with a generated name
- File without classes — only file-level metrics
- Empty file — `loc = 0`, `classCount = 0`
- Class without methods — `lcom = 0`, `methodCount = 0`, `tcc = 1.0`, `lcc = 1.0`, `rfc = 0`
- Class with one method — `tcc = 1.0`, `lcc = 1.0` (perfect cohesion by definition)
- Class without properties — methods are isolated unless connected by `$this->method()` calls, `tcc = 0.0`, `lcc = 0.0`
- LCOM — static methods are excluded from the graph; `$this->method()` calls create edges; `self::`/`static::` calls do not
- TCC/LCC — consider only public methods
- RFC — abstract methods are counted, `self::`/`static::`/`parent::` are not counted
