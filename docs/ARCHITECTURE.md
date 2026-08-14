# Qualimetrix — Architecture

## Navigation

| Task                        | Document                                                                        |
| --------------------------- | ------------------------------------------------------------------------------- |
| **Getting started**         | [CLAUDE.md](../CLAUDE.md) — rules, structure, commands                          |
| **New collector**           | [src/Metrics/README.md](../src/Metrics/README.md)                               |
| **New rule**                | [src/Rules/README.md](../src/Rules/README.md)                                   |
| **Understanding contracts** | [src/Core/README.md](../src/Core/README.md)                                     |
| **Analysis pipeline**       | [src/Analysis/README.md](../src/Analysis/README.md)                             |
| **Formatters**              | [src/Reporting/README.md](../src/Reporting/README.md)                           |
| **Configuration**           | [src/Analysis/Configuration/README.md](../src/Analysis/Configuration/README.md) |
| **DI, cache, CLI**          | [src/Infrastructure/README.md](../src/Infrastructure/README.md)                 |

---

## Key Concepts

### 1. Capability Boundaries and Current Dependency Graph

The accepted target is a capability-oriented modular monolith
([ADR 0022](adr/0022-capability-oriented-modular-monolith.md)). Leaf capabilities
own behaviour, configuration, state, tests and documentation. They
expose `Contract` only to named external owner-consumers. P3 proved one
consumer-owned Run port,
`Analysis\Run\Contract\FileSetInspectionParticipantInterface`: it receives an
eligible file set and owns no capability result. P4 adds only the two
capability-specific preparation contracts for Architecture policy and circular
dependency evidence. Generic lifecycle, graph-preparation, and
metric-derivation ports remain unapproved.

`Analysis`, `Analysis\Evidence`, and `Analysis\Policy` are navigation
taxonomies, never modules or allow-list targets. `Core` is limited to neutral
primitives, `Infrastructure` to delivery/composition, and `Reporting` to output
projection. P1 has landed `Analysis\Evidence\Duplication` as the first migrated
leaf: it owns detection, its run-scoped result provider, entities, options,
rule, tests and documentation. P3 removed its temporary inspection contract;
the internal detector now implements Run's FileSet participant port. P2 also landed
`Analysis\Evidence\DependencyModel` and `Reporting\GraphProjection`: graph
consumers use the model's six contracts, while Console uses Reporting's two
public projection types and cannot import exporter internals. P3 moved Run,
Measurement, and Configuration to their current physical boundaries. P4
separated declared-layer policy into `Analysis\Policy\Architecture` and SCC
evidence into `Analysis\Evidence\CircularDependency`; each leaf owns its own
prepared state and capability-specific preparation contract. Run owns
discovery, collection and ordering; Measurement owns collection facts,
repository, namespace attribution and aggregation; Configuration owns document
resolution through a deliberately transitional runtime DTO. P5 landed
`Analysis\Evidence\ComputedMetrics` plus its Health subdomain: definitions are
instance-owned, Run invokes only the evaluation contract, and Reporting
consumes immutable Health contracts. P6 implementation has published
`Analysis\Finding`, peer Inline and Baseline policy capabilities,
`Analysis\Evidence\Prioritization`, and Reporting's `FindingProjection`;
Infrastructure keeps the Console, Git, DI, and worker adapters behind those
public contracts. P6 remains paused pending its post-review closure and is not
complete. P7-P8 retain only the thin-evidence classification and final
locality/grant closure.

P0 governance implements the current enforcement model. The versioned internal
manifest covers all 754 declarations in 752 files and names 37 semantic
owners. P6 leaves no singleton enforcement seam and 51 exact internal grants,
which collapse to 8 coarse owner pairs and produce 223 declared qmx allow
edges. Generated artifacts are deterministic projections rather than a second
source of truth.
`external` excludes `Qualimetrix\**`; `coverage: error` makes
an uncovered project class fail even when it has no dependency edges.

The manifest checker is the exact owner/visibility/import authority. It runs as
`composer architecture:check` before selfcheck and rejects unlisted imports even
when a coarse qmx owner edge would allow them. The generated inventories are
review projections, not the manifest or a runtime/DI registry. A direct
`bin/qmx check` executes product analysis and the coarse qmx rule only; use
`composer check` for complete repository governance. Exact declared allow
cycles fail configuration loading, while `architecture.circular-dependency`
checks cycles in actual class dependencies.

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

| Component | Condition                                | DI Tag                    |
| --------- | ---------------------------------------- | ------------------------- |
| Collector | implements `MetricCollectorInterface`    | `qmx.collector`           |
| Rule      | implements `RuleInterface`               | `qmx.rule`                |
| Formatter | implements `FormatterInterface`          | `qmx.formatter`           |
| Stage     | implements `ConfigurationStageInterface` | `qmx.configuration_stage` |

**No need** to modify `ContainerFactory` when adding new components.

### 6. Baseline Ceiling

The version 11 baseline retains the post-rule, reported-magnitude ceiling. It compares
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

1. Create a collector in `src/Metrics/{Category}/`
2. Implement `MetricCollectorInterface`
3. **Done** — automatic registration via DI

### Add a New Rule

1. Identify the owning subject; do not create a role bucket for an independent capability
2. Put a thin legacy-layout rule in `src/Rules/{Category}/`, or co-locate a capability-owned rule with its subject
3. Implement `RuleInterface` + create an Options class
4. Register a capability root through its Infrastructure configurator; layered rules remain automatic

Current capability examples are Architecture,
[`Analysis.Evidence.Duplication`](../src/Analysis/Evidence/Duplication/README.md),
[`Analysis.Evidence.DependencyModel`](../src/Analysis/Evidence/DependencyModel/README.md),
[`Analysis.Evidence.ComputedMetrics`](../src/Analysis/Evidence/ComputedMetrics/README.md),
and [`Reporting.GraphProjection`](../src/Reporting/GraphProjection/README.md).

### Add a New Output Format

1. Create a formatter in `src/Reporting/Formatter/`
2. Implement `FormatterInterface`
3. **Done** — automatic registration via DI

### Add a New Config Option

1. Add a constant to `src/Analysis/Configuration/ConfigSchema.php` (e.g., `public const MY_OPTION = 'my.option'`)
2. Add an entry to `ConfigSchema::ENTRIES` (if YAML-configurable)
3. Add handling in the appropriate consumer (`TransitionalRuntimeConfiguration`, pipeline stage, etc.)

**Details** — in the README.md of the corresponding directory.
