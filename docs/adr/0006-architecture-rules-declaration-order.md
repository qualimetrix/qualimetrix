# 0006. Architecture Rules: Declaration-Order Matching

**Date:** 2026-05-13
**Status:** Accepted
**Supersedes:** [0005 — Architecture Layer Rules](0005-architecture-rules.md) on the matching-algorithm decision (Decision 3 of 0005)

## Context

[ADR 0005](0005-architecture-rules.md) shipped the MVP of `architecture.layer-violation` with **specificity-based** layer resolution: when two patterns match a class, the one with the longest literal prefix wins; equal-specificity ties were rejected at config-load time.

Post-implementation analysis (2026-05-12) surfaced that the algorithm required **three compensation layers** to handle its own corner cases:

1. A pre-validation `CollisionHeuristic` in `ArchitectureConfigurationFactory` that tried to detect ambiguous patterns before they reached runtime
2. A runtime `architecture.layer-collision` diagnostic that fired when specificity didn't disambiguate (symmetric globs)
3. An ADR-documented limitation listing scenarios the algorithm couldn't handle cleanly

When an algorithm needs three escape hatches, the algorithm is wrong, not the edges. Round-2 triple review (Claude + Gemini + Codex) on the followup plan converged on a pivot: replace specificity with **declaration-order matching**, the same mechanism used by deptrac, ArchUnit, `.gitignore`, Apache config, and most RBAC engines.

Backward compatibility was explicitly waived because the feature shipped in v0.17.0 but has not been released yet (post-merge, pre-tag), and the only active user is the project itself (dogfooding).

## Decision

### 1. Declaration-order matching, first match wins

When a class FQN matches the patterns of multiple layers, the layer **declared earlier** in the `architecture.layers` list wins. This is mechanical, predictable, and matches user expectations from every tool in the same neighbourhood. Order is the user's tool to express intent; the engine doesn't second-guess it.

Concretely: `LayerRegistry::resolveLayer(SymbolPath)` iterates `definitions()` in declared order and returns the first match (or null). No specificity computation; no collision detection.

### 2. YAML schema becomes an ordered list, long form only

The `architecture.layers` key was a map of `name → pattern(s)` (where iteration order in PHP arrays is insertion order, but semantically the key set was unordered). Under ordered matching, **order must be explicit and load-order-preserving**, so the shape becomes a list of objects:

```yaml
architecture:
  layers:
    - name: controller
      patterns: ['App\Controller\**']
    - name: repository
      patterns: ['App\Repository\**']
```

We rejected a single-key-map shorthand (`- controller: 'App\Controller\**'`). That form is a known YAML antipattern: it confuses parsers and IDE schema validators because the entry's "key" is data, not structure. One canonical form minimises cognitive load and validator surface.

### 3. Configuration merge semantics: replace-whole-list

`ConfigurationMerger` previously deep-merged the layers map. Under ordered-list semantics, deep merge would silently destroy ordering when a later config source defines more entries. Decision: when any configuration source (preset or project) defines `architecture.layers`, it **replaces the entire list**. Presets compose via the same rule; the last source to define `layers` wins outright. Documented in user-facing docs and ConfigurationMerger code.

### 4. Two replacement safety nets

The removed `architecture.layer-collision` diagnostic was load-bearing — it caught misordered or overlapping patterns. Under ordered semantics, the equivalent footguns split into two distinct failure modes, each handled by its own diagnostic.

**`architecture.unreachable-layer`** (loud case). A layer whose patterns never matched a class during the run emits one info diagnostic. Causes: (a) shadowed by a broader layer earlier in the order, or (b) the pattern matches no class in the codebase. Hit counting is done over `metrics->all(SymbolType::Class_)` so DTO-only layers without outgoing dependencies are NOT falsely flagged. The hit counter is a local variable inside `LayerViolationRule::analyze()`, never a class field — CLAUDE.md mandates stateless rules.

**`architecture.potential-shadow`** (quiet case). When a class matches multiple layers, only the first wins — earlier layers can silently steal classes from later ones. Detection is **evidence-based**: walk every class, collect all matching layers via `LayerRegistry::resolveAll()`, group by (assigned, shadowed) pairs, emit one info diagnostic per pair with a sample of up to 5 example FQNs. This catches every real shadow regardless of pattern shape (prefix-overlap, suffix-theft, arbitrary intersection), without re-introducing specificity.

We considered a static prefix-overlap heuristic. Rejected because (a) it fails on its own canonical example (`App\**\Foo` shadowing `App\Service\**` — `fnmatch` doesn't match `App\**\Foo` against the literal prefix `App\`), and (b) generalising to arbitrary glob-glob intersection is out of scope due to the `fnmatch` dialect plus namespace-prefix mode. Evidence-based is `O(classes × layers × patterns-per-layer)` per analysis run — bounded and acceptable.

Both diagnostics are `Severity::Info` (don't fail the run by default; `fail_on: info` opts into strict CI). Both emissions are sorted lexicographically before output for deterministic diagnostics between runs (`metrics->all()` iteration order is not stable under parallel collection).

### 5. Per-class introspection via `qmx debug:layer-assignment`

A new CLI command takes a class FQN and prints: which layer it was assigned to, which other layers it would have matched (in declaration order), and a shadowing hint if applicable. Reuses `LayerRegistry::resolveAll()`. Exit codes: `0` for valid input (including "unclassified"), `Command::INVALID` for malformed FQN, `Command::FAILURE` for config errors.

This closes the residual gap where `potential-shadow`'s aggregate output (per pair, with sample) doesn't answer "did THIS specific class end up where I expected?".

### 6. `LayerRegistry::resolveAll()` as companion to `resolveLayer()`

Two methods, one cache, distinct hot/cold paths:

- `resolveLayer(SymbolPath): ?string` — first match, short-circuits, used per dependency edge in policy violations (hot path)
- `resolveAll(SymbolPath): list<LayerMatch>` — every match in declaration order, used by `potential-shadow` evidence collection and the debug command (per-class, cold path)

`LayerMatch` is a small VO carrying `layerName` and `matchingPattern`. Cache is shared by canonical key so a class queried by both methods doesn't re-walk the layer list.

## Consequences

- **Breaking YAML schema change.** The `architecture.layers` shape changes from map to ordered list. Communicated via CHANGELOG `Breaking` entry; only the project itself (dogfooding) is affected at the time of this ADR.
- **Code reduction.** `LayerCollisionException`, `bestMatchingPattern()`, specificity computation in `LayerDefinition`, `CollisionHeuristic`, `architecture.layer-collision` diagnostic, and `LayerPolicy::knownLayers()` are all deleted. The net delta after adding the two new diagnostics is still negative.
- **`LayerRegistry` stays stateless** as a lookup service. The hit counter for `unreachable-layer` lives in the rule (local var); shadow-evidence is collected in a local map. SRP preserved.
- **Catch-all pattern becomes idiomatic.** A final layer with `patterns: ['**']` captures every unclassified class and participates in policy normally — a cleaner alternative to `coverage: warn`. The `architecture.coverage` mechanism is preserved as-is for backward compatibility but documented as usually-unnecessary under ordered semantics.
- **Determinism guarantees in diagnostics.** Both new diagnostics sort their output before emission, so CI diffs are stable across runs.
- **User mental model is simpler.** "First match wins, top to bottom" is one sentence. Specificity required a paragraph plus a corner-case table. Declaration order also makes peer-review of `qmx.yaml` straightforward.

## References

- Implementation entry points (post-pivot): `src/Core/Architecture/Layer/`, `src/Configuration/Architecture/`, `src/Rules/Architecture/LayerViolationRule.php`, `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php`
- Prior art:
  - [deptrac](https://github.com/qossmic/deptrac) — declaration-order matching, the closest neighbour
  - [ArchUnit](https://www.archunit.org/) — ordered predicates in `should()` chains
  - `.gitignore`, Apache `<Location>` matching, RBAC engines — same first-match-wins convention
- Superseded decision: [0005 — Architecture Layer Rules](0005-architecture-rules.md), Decision 3
