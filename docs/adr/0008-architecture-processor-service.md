# 0008. `ArchitectureProcessor` Service

**Date:** 2026-05-15
**Status:** Accepted
**Builds on:** [0005 — Architecture Layer Rules](0005-architecture-rules.md), [0006 — Declaration-Order Matching](0006-architecture-rules-declaration-order.md), [0007 — Phase 2 Flexibility & Expressiveness](0007-architecture-rules-phase-2-design.md)

## Context

After Phase 1 + Phase 2 shipped, the rules-pipeline for `architecture.layer-violation` was distributed across five horizontal-layer touchpoints:

- `ArchitectureConfigurationFactory` — manually `new`-instantiated in `ConfigurationPipeline.php:86`, bypassing DI
- `LayerExpansionStage` — embedded in `AnalysisPipeline`, owning a private `ClassContextFactory` instance
- `LayerViolationRule` — read its prepared state through `ArchitectureConfigurationHolder` and `AnalysisContext::$architecture`
- `RuntimeConfigurator` — an undocumented holder consumer (constructor-injected; calls `->reset()` + `->set()` on each analysis run)
- `LayerAssignmentCommand` — only saw the static layer registry; template-expansion and graph-bound state were invisible to it

This produced four concrete defects:

1. Two `ClassContextFactory` instances co-exist with a subtle lifecycle dependency
2. `debug:layer-assignment` results diverge from `qmx check` for any config that uses template layers or graph-based criteria
3. The holder pattern forces a Core → Architecture type reference (via `AnalysisContext::$architecture`)
4. The factory's `new`-instantiation defeats DI hygiene (no autowiring, no decoration, no test substitution)

The 8-agent focused review filed these as M3, M4, M9, M10. They share one root cause: there is no single coordinator for the rules-pipeline lifecycle. The remediation introduces that coordinator.

## Decision

### 1. Single DI-managed service owning the rules-pipeline lifecycle

`Qualimetrix\Architecture\Processing\ArchitectureProcessor` (namespace per [ADR 0010](0010-architecture-vertical-slice.md)) is a DI-managed shared service with an explicit per-run lifecycle (`reset()` + `bind()` at the start of each analysis run; `prepare()` after Collection; `classify()` / `getPreparedConfiguration()` during RuleExecution). It owns the complete rules-pipeline lifecycle: holding the prepared configuration, performing template expansion, binding the dependency graph to the registry, and answering per-class membership queries. All consumers route through it. Symfony DI scopes are not used — the service is shared (singleton-like, per the project's standard service configuration) and per-run isolation is enforced by `reset()` rather than by container scope.

The factory (`ArchitectureConfigurationFactory`) is **not** moved — its invocation stays in `ConfigurationPipeline` where it already lives. The processor accepts an already-built `ArchitectureConfiguration` via `bind()`. This was a course-correction during round-3 review: an earlier draft proposed `load(array $raw)`, but `ConfigurationProviderInterface` does not expose the raw subtree to either `AnalysisPipeline` or `RuntimeConfigurator`, and adding that flow would cross-cut another layer.

### 2. Interface contract

```php
interface ArchitectureProcessorInterface
{
    // Stage A: bind a fully-built ArchitectureConfiguration to the processor.
    // Called by RuntimeConfigurator after ConfigurationPipeline has produced
    // the configuration. Idempotent: subsequent calls replace the binding.
    public function bind(ArchitectureConfiguration $config): void;

    // Stage B: graph + full class set available → expand templates, bind
    // graph to registry, store prepared state. Called by AnalysisPipeline
    // after the Collection phase has built the dependency graph.
    // MUST internally call $config->registry()->bindGraph($graph).
    public function prepare(DependencyGraphInterface $graph, ClassSet $classes): void;

    // Stage C: prepared configuration + class set → per-class membership.
    // Returns iterable<LayerMatch> — LayerMatch already carries the layer
    // name and matched criteria, sufficient for byte-for-byte debug parity
    // with qmx check.
    public function classify(iterable $classPaths): iterable;

    // Read-back of the prepared configuration for rule consumption.
    // Returns the prepared configuration only between prepare() and the next
    // reset() or bind(). Returns null in every other state (pre-bind,
    // post-bind pre-prepare, post-reset). Rule consumers treat null as no-op.
    public function getPreparedConfiguration(): ?ArchitectureConfiguration;

    // Reset internal state for the next analysis run. Idempotent.
    public function reset(): void;
}
```

Three contract details are load-bearing:

- **`prepare()` MUST internally call `$config->registry()->bindGraph($graph)`.** Round-3 review caught that leaving this unassigned would let an implementer silently no-op every graph-criterion. It is part of the interface contract, not an implementer-discretion detail.
- **The two-parameter form `prepare($graph, $classes)` is load-bearing:** template expansion and unreachable-layer diagnostics both need every class in the codebase, including classes that have no dependency edges and therefore do not appear in `$graph` nodes. `LayerExpansionStage::expand(array $entries, ClassSet $classes, int $maxExpansion)` already takes the class set as a separate argument, and `LayerViolationRule` reads classes from `$context->metrics->all(SymbolType::Class_)`. Passing `ClassSet` alongside the graph mirrors that existing reality.
- **`classify()` returns `iterable<LayerMatch>`, NOT `iterable<MembershipResult>`.** An earlier draft conflated the two. `MembershipResult` is per-definition (does this class match this layer); `LayerMatch` is per-class (which layer was this class assigned to, with which criteria). The debug command needs the latter for parity with `qmx check`. No extension or subtype relationship is introduced.

### 3. State machine

Legal sequence (one analysis run): `reset → bind → prepare → classify? → getPreparedConfiguration?`, repeatable. `classify` and `getPreparedConfiguration` are optional per run; the others are mandatory in that order.

Illegal sequences throw `LogicException` with a message identifying the missing precondition:

- `prepare()` before `bind()` → "ArchitectureProcessor::prepare() requires bind() to have been called"
- `classify()` before `prepare()` → "ArchitectureProcessor::classify() requires prepare() to have been called"

Two transition rules are load-bearing:

- **`bind()` is invalidating** — calling it after a previous `prepare()` discards the prepared state. Subsequent `classify()` requires a fresh `prepare()` against the new binding.
- **`reset()` restores the initial state** — after `reset()`, subsequent `prepare()` and `classify()` throw `LogicException` until a fresh `bind()` is performed. `reset()` is idempotent (callable twice in a row is a no-op).

Fail-fast is preferred over silent no-op because misordered lifecycle indicates a wiring bug at the DI level, not a runtime input problem.

### 4. Consumers and integration points

| Consumer                                          | Calls on processor                                          | Trigger                                                                                                                                                                                            |
| ------------------------------------------------- | ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `RuntimeConfigurator` (Infrastructure)            | `reset()` + `bind($resolved->architecture)`                 | Start of each analysis run, replacing today's `architectureHolder->reset()` + `architectureHolder->set()`                                                                                          |
| `AnalysisPipeline` (Analysis)                     | `prepare($graph, $classes)`                                 | After the Collection phase has produced the dependency graph; `$classes` is built from `metrics->all(SymbolType::Class_)`                                                                          |
| `LayerViolationRule` (Architecture/Rules)         | `getPreparedConfiguration()` in `analyze()`                 | Read prepared state; null → no-op                                                                                                                                                                  |
| `LayerAssignmentCommand` (Infrastructure/Console) | Full chain: `reset` → `bind` → `prepare` → `classify`       | Self-contained — the debug command runs Discovery + Collection internally, builds its own `ClassSet`, and passes it to `prepare()` so its output reflects template expansion and graph-bound state |
| Future tooling (metrics, reporters)               | `getPreparedConfiguration()` or `classify()` as appropriate | —                                                                                                                                                                                                  |

Rules cannot use plain constructor autowiring (CLAUDE.md Critical Rule 7) because `RuleOptionsInterface` is injected by `RuleOptionsCompilerPass`, not by the container — that compiler pass is also where the processor interface is resolved as an extra dependency.

The alias is required for rule injection: `LayerViolationRule` declares the interface in its constructor, and `RuleOptionsCompilerPass::resolveExtraDependencies()` resolves it through `container->has(ArchitectureProcessorInterface::class)`. Without the alias, rule-side injection is silently skipped and the rule sees only its base options. For `AnalysisPipeline` injection the alias is incidental — a direct `new Reference(ArchitectureProcessor::class)` argument would also work. The configurator registers the alias once and both consumption sites benefit.

### 5. Alternatives considered

- **Status quo with holder + `AnalysisContext::$architecture` field.** Rejected — keeps the scattered lifecycle, the Core → Architecture type edge, and the `debug:layer-assignment` divergence.
- **`load(array $raw)` on the interface.** Rejected after round-3 review — `ConfigurationProviderInterface` does not expose raw subtrees, and adding that flow contradicts the decision to keep the factory in `ConfigurationPipeline`. Adopting `bind(ArchitectureConfiguration)` resolves the contradiction cleanly.
- **Single-parameter `prepare($graph)`.** Rejected — `DependencyGraphInterface` carries only classes with at least one dependency edge; template expansion and unreachable-layer diagnostics need every class. Same shape of blocker as the earlier `load(array)` proposal: the data needed at the call site is wider than what a single interface argument can deliver.
- **Marker interface in Core for `ArchitectureConfiguration`.** Rejected — interface methods would leak Architecture types upward into Core, reintroducing the boundary violation the holder was already creating.
- **Generic `AnalysisContext` extras map.** Deferred — recorded as a future option if the dedicated-service approach develops unexpected costs.
- **Multiple narrow services (Loader / Expander / Classifier).** Rejected as the public surface — re-exposes the coordination problem. Accepted internally — the processor delegates to private collaborators (`TupleExtractor`, `LayerInstantiator`, etc.).

## Consequences

- M3, M4, M9, M10 from the 8-agent review are closed by construction
- `AnalysisContext::$architecture` is removed; the Core → Architecture type edge disappears
- `ArchitectureConfigurationHolder` becomes redundant and is deleted; its two consumers (rule, `RuntimeConfigurator`) migrate to the processor
- `debug:layer-assignment` now produces byte-for-byte parity with `qmx check` for template and graph-criteria configs, because both routes share the same processor instance
- The processor is hot-path neutral — it caches prepared state and does not re-walk the registry per classify call
- Future architecture-aware features (metrics, reporters, additional debug commands) get a documented service to inject, not an `AnalysisContext` field to read

## References

- Interface and implementation: `src/Architecture/Processing/ArchitectureProcessor.php`, `ArchitectureProcessorInterface.php`
- DI configurator (alias registration): `src/Infrastructure/DependencyInjection/Configurator/ArchitectureConfigurator.php`
- Rule integration: `src/Architecture/Rules/LayerViolationRule.php`
- Debug command: `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php`
- Builds on: [ADR 0005](0005-architecture-rules.md), [ADR 0006](0006-architecture-rules-declaration-order.md), [ADR 0007](0007-architecture-rules-phase-2-design.md)
- Related: [ADR 0010](0010-architecture-vertical-slice.md) (namespace placement under `src/Architecture/Processing/`)
