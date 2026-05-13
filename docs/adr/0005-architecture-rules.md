# 0005. Architecture Layer Rules

**Date:** 2026-05-12
**Status:** Superseded by [ADR 0006](0006-architecture-rules-declaration-order.md) on Decision 3 (matching algorithm). Other decisions remain in force.

## Context

Qualimetrix already detected one kind of structural problem in the dependency graph — circular dependencies (`architecture.circular-dependency`). Users repeatedly asked whether the tool could also enforce a layered architecture (controllers don't talk to repositories, the domain depends on nothing, vendor namespaces are gated), which is the headline feature of [deptrac](https://github.com/qossmic/deptrac).

The dependency graph (`Qualimetrix\Analysis\Collection\Dependency\DependencyGraph`) is already built during the Collection phase and exposed through `AnalysisContext::$dependencyGraph`, so the raw data is available. Adding a deptrac-style layer rule directly on top of that graph completes the "five tools in one" narrative (complexity, coupling, code smell, security, architecture) without a second tool in the CI pipeline.

The design pass produced a long shortlist of mechanisms to lift from deptrac/ArchUnit (DSL for include/exclude, class-name suffix matching, attribute-based membership, per-pair severity overrides, dependency-type filtering, sub-module isolation, multiple layers per class, …). Triple review (Claude + Gemini + Codex) on the plan converged on a deliberately small MVP surface. This ADR captures the locked design decisions so a future maintainer does not re-litigate them.

The implementation plan lives in `docs/internal/plans/architecture-rules.md`.

## Decision

### 1. Single new rule, single new top-level YAML section

One rule is introduced: `architecture.layer-violation`. Its configuration data (layer definitions, allow-list, coverage mode) lives in a new top-level YAML key, `architecture:`. The rule's own options carry only `enabled` and a `severity` selector. Layer/policy data is shared via `AnalysisContext::$architecture` so that future architecture-aware metrics and reporters can read the same configuration without duplicating it.

### 2. Namespace-based layer membership only

Membership is decided **purely** by matching the class FQN against namespace patterns. We rejected:

- **Class-name suffix matching** (`*Controller`, `*Repository`) — drifts from project naming conventions; teams that don't use such suffixes can't benefit; ambiguous when a class fits multiple suffixes.
- **Marker interface membership** — niche; deptrac is the place for that level of rule expressiveness.
- **PHP attribute membership** — same niche objection, plus it requires adding metadata to user code.

Namespace matches existing project organisation; users already think about packages and namespaces when planning architecture. NamespaceMatcher (with prefix-and-glob auto-detection) is already in `Core/Util/`, so the mechanism is free.

### 3. Allow-list semantics, single layer per class

The policy is an explicit allow-list per source layer. Anything not in the list is a violation. We rejected an explicit deny-list because it makes the answer to "can layer A reach layer B?" require reading the entire policy.

Each class belongs to at most one layer. When two patterns match, the layer with the **longer literal prefix** wins (specificity = length before first wildcard). Equal-specificity ties are a configuration error and surface at config-load time, not analysis time. Single-layer-per-class makes violation messages unambiguous and keeps the algorithm cache-friendly.

### 4. Vendor namespaces are first-class layers

Declaring `doctrine: 'Doctrine\**'` is supported and the rule treats it identically to a project layer. This means policy like "only repositories may use Doctrine" is expressible without a separate "vendor" feature. Vendor namespaces with no `allow:` entry are effectively quarantined — exactly what we want for "leaf" frameworks nobody should bypass.

### 5. Per-use-site reporting

Each forbidden dependency edge in `DependencyGraph::getAllDependencies()` produces one violation. If a class violates the policy via constructor type hint plus three method calls, you get four violations, each anchored to a precise file/line. This matches user expectation from PHPStan/Psalm (one error per occurrence) and is well suited to CI annotation formats (SARIF, GitHub PR comments).

Baseline identity, however, collapses use-sites: the hash includes `(source class, target class, dependency type)` but **not** the file line. Re-formatting or moving a use-site within the same file does not invalidate the baseline. Multiple use-sites of the same forbidden edge resolve to a single baseline entry. This is enforced by extending `ViolationHasher` to optionally include `dependencyTarget` and `dependencyType` — when both are null (every non-dependency rule), the hash is bit-identical to the pre-change behaviour. A regression test pins this.

### 6. Out-of-layer ends silently ignored by default; opt-in `coverage` diagnostics

A pragmatic concession to incremental adoption: when a dependency edge has an end that doesn't fall into any declared layer, the edge is silently skipped at the violation level. This lets users declare two or three layers covering 60% of the codebase and start enforcing today, without noise from the uncovered 40%.

For users who want diagnostics anyway, `architecture.coverage` is a separate setting (`ignore` / `warn` / `error`) that surfaces under a dedicated rule name `architecture.coverage`. It produces a single summary violation per analysis run, listing example unclassified classes (top 10 alphabetical). Separate rule name → independently baseline-able, suppressable, and filterable.

We rejected a logger-based diagnostic channel because PSR-3 messages don't appear in JSON/SARIF/HTML reports, and we want users to see the gap in the same surface as everything else.

### 7. Primitives in Core, factory in Configuration

Layer primitives (`LayerDefinition`, `LayerRegistry`, `LayerPolicy`, exceptions) live in `src/Core/Architecture/Layer/` so that future Metrics and Reporting components can consume them without depending on the Configuration layer. The typed config holder `ArchitectureConfiguration` also lives in `Core/Architecture/` for the same reason: rules and metrics see it through `AnalysisContext::$architecture` without an upward dependency.

The YAML-to-typed-config conversion (`ArchitectureConfigurationFactory`) stays in `src/Configuration/Architecture/` because validation depends on `ConfigLoadException` (Configuration concern) and the long-form normalization (`{target, types}` for forward compatibility) is YAML-shape-specific. Keeping the factory in Configuration preserves the project's Core-has-no-dependencies invariant.

### 8. Default-enabled rule with empty-config short-circuit

`enabled: true` by default keeps the rule discoverable. The first instruction in `analyze()` is to short-circuit when `architecture.layers` is empty, so projects without architecture configuration pay zero cost. We rejected the alternative — default-disabled, opt-in — because it would have made the rule invisible to users who haven't read the docs.

## Phase 2 deferrals

Documented explicitly so users (and future maintainers) know what to expect:

- **`types:` filter per allow-rule** — the YAML structure already accepts `{target: 'foo', types: [extends, method_call, ...]}` for forward compatibility, but the filter is not enforced. Declaring `types:` emits a configuration warning. Wiring is straightforward (the `Dependency` VO already carries `DependencyType`) but is deferred until we see a concrete user request, to avoid over-engineering.
- **Sub-module isolation within a layer** (`allow_same_layer: false`) — rare in practice. Adding it later does not break the current YAML grammar.
- **Class-name-suffix or interface-based membership** — explicitly out of scope (see Decision 2). If we change our minds, we'd add it as a parallel mechanism, not a replacement.
- **Layer-aware metrics** (e.g., "% of cross-layer fan-out", "instability per layer") — separate roadmap item. The Core primitives are reusable, so adding such metrics later does not touch the rule.

## Consequences

- A single new rule, but a noticeable feature surface for users — declaring architecture in YAML is the kind of capability that fundamentally changes how teams use the tool.
- `Violation`'s public shape gains two nullable fields (`dependencyTarget`, `dependencyType`). All existing violations construct them as null, and `ViolationHasher`'s output for non-dependency violations is bit-identical (pinned by regression test). No baseline regeneration is required for existing projects.
- `AnalysisContext` gains a nullable `architecture` field. Existing rules ignore it.
- `ResolvedConfiguration` gains a non-nullable `architecture` field; absent YAML produces an empty configuration (`isEmpty()` true), which makes the rule a no-op.
- The plan deliberately avoids a YAML DSL. As soon as users ask for include/exclude lists inside a layer or `extends`/`implements`-only rules, we'll need to revisit — but starting small is the right move.
- Deptrac replacement story: a project moving from deptrac will lose include/exclude DSL and the ability to type-filter dependencies. They gain: one tool instead of two, layer rules integrated with metrics, the same baseline/suppression mechanism as the rest of Qualimetrix.

## References

- Plan: `docs/internal/plans/architecture-rules.md`
- Implementation:
    - `src/Core/Architecture/Layer/` — primitives (`LayerDefinition`, `LayerRegistry`, `LayerPolicy`)
    - `src/Core/Architecture/ArchitectureConfiguration.php`, `CoverageMode.php`
    - `src/Configuration/Architecture/ArchitectureConfigurationFactory.php`
    - `src/Rules/Architecture/LayerViolationRule.php`, `LayerViolationOptions.php`
    - `src/Baseline/ViolationHasher.php` (extended hash payload)
    - `src/Core/Violation/Violation.php` (added `dependencyTarget`, `dependencyType`)
- Website docs: `website/docs/rules/architecture.md` (the `## Layer Violations` section)
- Prior art:
    - [deptrac](https://github.com/qossmic/deptrac) — closest neighbour; YAML-declared layers + allow-list + DSL.
    - [ArchUnit](https://www.archunit.org/) — Java-world inspiration for "architecture as test".
