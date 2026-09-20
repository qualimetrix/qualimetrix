# Qualimetrix — Architecture

## Navigation

| Task                        | Document                                                                        |
| --------------------------- | ------------------------------------------------------------------------------- |
| **Getting started**         | [CLAUDE.md](../CLAUDE.md) — rules, structure, commands                          |
| **New collector**           | [Analysis capability index](../src/Analysis/README.md#current-leaves)           |
| **New rule**                | [Analysis capability index](../src/Analysis/README.md#current-leaves)           |
| **Understanding contracts** | [src/Core/README.md](../src/Core/README.md)                                     |
| **Analysis pipeline**       | [src/Analysis/README.md](../src/Analysis/README.md)                             |
| **Formatters**              | [src/Reporting/README.md](../src/Reporting/README.md)                           |
| **Configuration**           | [src/Analysis/Configuration/README.md](../src/Analysis/Configuration/README.md) |
| **DI, cache, CLI**          | [src/Infrastructure/README.md](../src/Infrastructure/README.md)                 |

---

## Key Concepts

### 1. Capability Boundaries and Current Dependency Graph

The current architecture is a capability-oriented modular monolith
([ADR 0022](adr/0022-capability-oriented-modular-monolith.md)). Leaf capabilities
own behaviour, configuration, state, tests and documentation. They expose
`Contract` only to named external owner-consumers. Ports are subject-specific:
Run owns FileSet inspection, DependencyModel owns traversal, and Architecture
and CircularDependency own their preparation contracts. Generic lifecycle,
graph-preparation, and metric-derivation ports remain unapproved.

`Analysis`, `Analysis\Evidence`, and `Analysis\Policy` are navigation
taxonomies, never modules or allow-list targets. `Core` is limited to neutral
primitives, `Infrastructure` to delivery/composition, and `Reporting` to output
projection. Evidence is split among DependencyModel, Duplication,
CircularDependency, ComputedMetrics, CodeSmell, Cohesion, Complexity, Coupling,
Design, Maintainability, Measurement, Prioritization, Security, and Size.
Configuration, Finding, Run, Architecture, Baseline, and Inline each retain
their named policy or orchestration subject. Reporting owns GraphProjection and
FindingProjection; Infrastructure owns Console, Git, DI, workers, and other
delivery/composition adapters. Context and runtime state stay with their named
owners as specified by
[ADR 0023](adr/0023-p8-context-locality-and-composition-bindings.md). The current
layout has no universal invocation context, generic runtime store, or generic
collector configuration carrier.

The versioned internal manifest implements the current enforcement model. It
covers every production declaration, names its semantic owner, and has no
singleton enforcement seam. Exact composition bindings retain the coarse owner
pairs the qmx projection would otherwise lose; such a binding is one observed DI
source-to-private target reference, neither a public contract nor an owner-wide
permission. Generated artifacts are deterministic projections
rather than a second source of truth, and they — not this document — carry the
counts: see `docs/internal/generated/modular-architecture/`, whose
`manifest-enforcement-summary.tsv` reports the current declaration, owner,
binding, and allow-edge totals.
`external` excludes `Qualimetrix\**`; `coverage-gap: error` makes
an uncovered project class fail even when it has no dependency edges.

Every test, support file, and fixture directory is governed by the same
manifest; `test-topology.tsv` in the generated directory reports how many of
each. Self-analysis runs against the versioned v13 root baseline, whose
213 groups across 163 subjects are checked against the file itself by
`DocumentationConsistencyTest`, and the current dogfood result is zero findings.

The manifest checker is the exact owner/visibility/import authority. It runs as
`composer architecture:check` before selfcheck and rejects unlisted imports even
when a coarse qmx owner edge would allow them. The generated inventories are
review projections, not the manifest or a runtime/DI registry. A direct
`bin/qmx check` executes product analysis and the coarse qmx rule only; use
`composer check` for complete repository governance. Exact declared allow
cycles fail configuration loading, while `architecture.circular-dependency`
checks cycles in actual class dependencies.

`ConfigurationDocument` is the concrete public source seam. It preserves the
ordered contributions and invocation working directory only; it is not a
generic configuration interface or invocation context. Run, Finding, Cache,
Parallel, Reporting, and Console resolve their own values from it, retaining
mutable state only inside the owner that needs a per-container store.

### 2. Five-Phase Pipeline

```
Discovery -> Collection (parallel) -> Aggregation -> RuleExecution -> Reporting
                |                        |              |               |
             MetricBag[]          AggregatedMetrics  Finding[]        Output
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

| Component     | State             | Task                          |
| ------------- | ----------------- | ----------------------------- |
| **Collector** | Stateful per-file | AST traversal -> MetricBag    |
| **Rule**      | Stateless         | MetricRepository -> Finding[] |

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
- Identifying findings
- Baseline (ignoring known issues)
- Accessing metrics via MetricRepository
- Dependency graph (class and namespace coupling)

### 5. Automatic Service Registration

Symfony DI with autoconfiguration — components under an existing capability's
exact registration roots are registered automatically:

| Component | Condition                                | DI Tag                    |
| --------- | ---------------------------------------- | ------------------------- |
| Collector | implements `MetricCollectorInterface`    | `qmx.collector`           |
| Rule      | implements `RuleInterface`               | `qmx.rule`                |
| Formatter | implements `FormatterInterface`          | `qmx.formatter`           |
| Stage     | implements `ConfigurationStageInterface` | `qmx.configuration_stage` |

Each capability configurator owns finite collector and rule roots. A new
capability requires explicit composition; it must not be enrolled by a wildcard
over `Analysis/Evidence/*`.

### 6. Baseline Ceiling

The version 13 baseline retains the post-rule, reported-magnitude ceiling. It compares
only groups of findings that currently fire, after source/configuration
suppression and exclusions but before git report scoping. A measured breach is
promoted to Error; a malformed, stale, or otherwise inapplicable entry is
fail-safe and suppresses nothing. See [ADR 0017](adr/0017-baseline-ceiling.md)
and [Baseline](../src/Analysis/Policy/Baseline/README.md) for the lifecycle and file contract.

For full details (CompilerPasses, exclude patterns, autowiring constraints for rules), see [CLAUDE.md § Symfony DI](../CLAUDE.md#7-symfony-di-automatic-service-registration).

### 7. Analysis Coverage and Verdict

Every discovered PHP file ends in exactly one state: analyzed, intentionally
excluded as generated, or failed during parsing/processing. Generated exclusions
keep a run complete; failures make it incomplete. `check` still renders the
selected report for diagnosis but exits 4 and marks policy results as
non-authoritative. Artifact-producing consumers such as baseline lifecycle
commands and `graph:export` refuse incomplete input. See
[ADR 0018](adr/0018-analysis-coverage-verdict-and-output-projection.md).

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
composer architecture:check # exact manifest policy + generated freshness
composer check     # full validation, including manifest check and qmx selfcheck
composer phpstan   # type safety, level 8
composer test      # unit/integration tests
```

---

## Extending the System

### Add a New Metric

1. Identify the owning evidence capability and create the collector below its exact root
2. Implement `MetricCollectorInterface`
3. Extend only that capability configurator's exact collector registration

### Add a New Rule

1. Identify the owning subject; do not create a role bucket for an independent capability
2. Co-locate the rule and its Options class with the owning capability
3. Implement `RuleInterface` + create an Options class
4. Extend only that capability's exact lazy, non-autowired rule registration

Current capability examples include Architecture,
[`Analysis.Evidence.Duplication`](../src/Analysis/Evidence/Duplication/README.md),
[`Analysis.Evidence.DependencyModel`](../src/Analysis/Evidence/DependencyModel/README.md),
[`Analysis.Evidence.ComputedMetrics`](../src/Analysis/Evidence/ComputedMetrics/README.md),
[`Analysis.Evidence.CodeSmell`](../src/Analysis/Evidence/CodeSmell/README.md),
[`Analysis.Evidence.Cohesion`](../src/Analysis/Evidence/Cohesion/README.md),
[`Analysis.Evidence.Complexity`](../src/Analysis/Evidence/Complexity/README.md),
[`Analysis.Evidence.Coupling`](../src/Analysis/Evidence/Coupling/README.md),
[`Analysis.Evidence.Design`](../src/Analysis/Evidence/Design/README.md),
[`Analysis.Evidence.Maintainability`](../src/Analysis/Evidence/Maintainability/README.md),
[`Analysis.Evidence.Security`](../src/Analysis/Evidence/Security/README.md),
[`Analysis.Evidence.Size`](../src/Analysis/Evidence/Size/README.md), and
[`Reporting.GraphProjection`](../src/Reporting/GraphProjection/README.md).

### Add a New Output Format

1. Create a formatter in `src/Reporting/Formatter/`
2. Implement `FormatterInterface`
3. **Done** — automatic registration via DI

### Add a New Config Option

1. Add a constant to `src/Analysis/Configuration/ConfigSchema.php` (e.g., `public const MY_OPTION = 'my.option'`)
2. Add an entry to `ConfigSchema::ENTRIES` (if YAML-configurable)
3. Add handling in the owning resolver or adapter; do not add a mixed runtime
   carrier to Configuration.

**Details** — in the README.md of the corresponding directory.
