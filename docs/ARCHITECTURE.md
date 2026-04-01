# Qualimetrix — Architecture

## Navigation

| Task                        | Document                                                        |
| --------------------------- | --------------------------------------------------------------- |
| **Getting started**         | [CLAUDE.md](../CLAUDE.md) — rules, structure, commands          |
| **New collector**           | [src/Metrics/README.md](../src/Metrics/README.md)               |
| **New rule**                | [src/Rules/README.md](../src/Rules/README.md)                   |
| **Understanding contracts** | [src/Core/README.md](../src/Core/README.md)                     |
| **Analysis pipeline**       | [src/Analysis/README.md](../src/Analysis/README.md)             |
| **Formatters**              | [src/Reporting/README.md](../src/Reporting/README.md)           |
| **Configuration**           | [src/Configuration/README.md](../src/Configuration/README.md)   |
| **DI, cache, CLI**          | [src/Infrastructure/README.md](../src/Infrastructure/README.md) |

---

## Key Concepts

### 1. Layer Dependency Graph

```
Infrastructure -> Analysis -> Metrics/Rules/Reporting/Configuration -> Core
```

- **Core** — contracts and primitives (0 dependencies except PHP + php-parser types)
- **Metrics/Rules/Reporting/Configuration** — domain implementations (depend only on Core)
- **Analysis** — orchestration (depends on domains)
- **Infrastructure** — entry point (depends on everything)

**Rule:** dependencies flow DOWNWARD only. Violations are checked via `deptrac`.

### 2. Five-Phase Pipeline

```
Discovery -> Collection (parallel) -> Aggregation -> RuleExecution -> Reporting
                |                        |              |               |
             MetricBag[]          AggregatedMetrics  Violation[]      Output
```

| Phase         | % of time | Parallel             |
| ------------- | --------- | -------------------- |
| Discovery     | <1%       | No                   |
| Collection    | 85-95%    | Yes (amphp/parallel) |
| Aggregation   | 2-5%      | No                   |
| RuleExecution | 1-3%      | No                   |
| Reporting     | <1%       | No                   |

**Collection** — the only parallelizable phase (AST parsing is the bottleneck).

### 3. Collector/Rule Separation

| Component     | State             | Task                            |
| ------------- | ----------------- | ------------------------------- |
| **Collector** | Stateful per-file | AST traversal -> MetricBag      |
| **Rule**      | Stateless         | MetricRepository -> Violation[] |

**Collectors** gather metrics (one metric = one AST pass).
**Rules** analyze pre-computed metrics (do NOT perform AST traversal).

### 4. SymbolPath — Stable Identifier

Located in `Core\Symbol` namespace. Used across the entire system for stable symbol identification.

```php
SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
SymbolPath::forClass('App\Service', 'UserService');
SymbolPath::forNamespace('App\Service');
SymbolPath::forFile('src/Service/UserService.php');
```

Used for:
- Identifying violations
- Baseline (ignoring known issues)
- Accessing metrics via MetricRepository
- Dependency graph (class and namespace coupling)

### 5. Automatic Service Registration

Symfony DI with autoconfiguration — new components are registered automatically:

| Component | Condition                                | DI Tag             |
| --------- | ---------------------------------------- | ------------------ |
| Collector | implements `MetricCollectorInterface`    | `qmx.collector`    |
| Rule      | implements `RuleInterface`               | `qmx.rule`         |
| Formatter | implements `FormatterInterface`          | `qmx.formatter`    |
| Stage     | implements `ConfigurationStageInterface` | `qmx.config_stage` |

**No need** to modify `ContainerFactory` when adding new components.

For full details (CompilerPasses, exclude patterns, autowiring constraints for rules), see [CLAUDE.md § Symfony DI](../CLAUDE.md#7-symfony-di-automatic-service-registration).

---

## Architectural Invariants

### DO NOT Violate

1. **Core has no dependencies** — only PHP + php-parser types
2. **Rules are stateless** — they do not perform AST traversal, only read metrics
3. **Collectors are stateful per-file** — they reset between files via `reset()`
4. **Atomic cache writes** — via tmp + rename (race condition protection)
5. **Anonymous classes are ignored** — only named classes are counted

### Verification

```bash
composer deptrac   # architecture layers
composer phpstan   # type safety, level 8
composer test      # unit/integration tests
```

---

## Extending the System

### Add a New Metric

1. Create a collector in `src/Metrics/{Category}/`
2. Implement `MetricCollectorInterface`
3. **Done** — automatic registration via DI

### Add a New Rule

1. Create a rule in `src/Rules/{Category}/`
2. Implement `RuleInterface` + create an Options class
3. **Done** — automatic registration via DI

### Add a New Output Format

1. Create a formatter in `src/Reporting/Formatter/`
2. Implement `FormatterInterface`
3. **Done** — automatic registration via DI

**Details** — in the README.md of the corresponding directory.
