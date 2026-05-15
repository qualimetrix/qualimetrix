# 0007. Architecture Rules Phase 2: Flexibility & Expressiveness — Design Decisions

**Date:** 2026-05-13
**Status:** Accepted
**Builds on:** [0005 — Architecture Layer Rules](0005-architecture-rules.md), [0006 — Declaration-Order Matching](0006-architecture-rules-declaration-order.md)

## Context

Phase 1 (ADR 0005, ADR 0006) shipped `architecture.layer-violation` with namespace-pattern membership, declaration-order matching, and an explicit allow-list. The feature covers the textbook DDD/Clean Architecture case but breaks down for real codebases that deviate from it:

- Classes whose architectural role does not match their namespace (a `Repository` in `App\Service\`)
- DDD bounded contexts where N modules share the same internal layer structure and cross-module dependencies must be forbidden without listing N pairs by hand
- Sub-tree exclusion within a layer match
- Allow-list edges that need to distinguish between extension, implementation, instantiation, and method-call

Without these extensions, teams either contort their YAML or fall back to deptrac for the long-tail cases — undermining the "one tool replaces five" promise.

This ADR locks the design decisions for Phase 2's five flexibility directions. Three rounds of triple review (Claude + Gemini + Codex) on the Phase 2 design plan converged on the decisions below. The plan was deleted after the implementation landed (Steps A–H, 2026-05-15) because the ADR captures the locked design and the code + component READMEs hold the implementation detail.

## Decision

### D1. Declaration-order matching is the foundation

All Phase 2 directions assume the declaration-order pivot from ADR 0006. No direction re-introduces specificity computation. When a class matches multiple layer entries, the layer declared earlier wins.

### D2. `match: any | all` controls multi-criterion membership combination (non-template layers)

Layer entries can now declare up to five membership criteria: `patterns`, `suffix`, `attributes`, `implements`, `extends`. For non-template layers:

- `match: any` (default) — class is a member if ANY criterion has at least one matching entry across kinds. Within one kind, lists are always OR'd (`attributes: [A, B]` means "has A or B"). Missing criterion under `match: all` is trivially satisfied.
- `match: all` (strict) — class is a member only if ALL declared criteria have at least one matching entry within their kind.

`any` is the default because the dominant adoption scenario is migration of existing codebases with inconsistent conventions — `any` lets the rule catch repositories that match by suffix even when the namespace doesn't align. `all` is opt-in for strict-convention projects.

`implements` and `extends` are **separate keys**, not unified into a single supertype criterion. The taxonomy is cleaner and `match: all` becomes naturally expressive ("must extend AbstractBase AND implement Loggable").

### D3. Template layers expand by observed binding tuples, AFTER Collection

A layer can declare a capture-variable name template like `'domain-{module}'`. `LayerExpansionStage` runs **after the Collection phase and before RuleExecution** — Collection-derived AST metadata (attributes, parent-class chain, interfaces) must already exist for capture criteria to function.

Expansion produces one concrete `LayerDefinition` per **observed binding tuple** — the distinct value combinations actually appearing in classes that satisfy all of the template's criteria. Cartesian product (distinct values × distinct values × ...) was rejected because it creates dead instances for combinations that don't exist in the codebase. Expansion ordering is in-place per template (concrete instances appear at the template's position in the declared layer list) with lexicographic ordering of captured values for determinism.

A hard ceiling on expanded instance count guards against pathological broad templates: **default 500**, configurable via `architecture.max_expanded_layers`. Overflow rejects at expansion with a clear error pointing at the offending template.

### D4. Allow-list selectors: exact / glob / captured, with precise grammar

Allow-list keys and values are `LayerSelector`s, dispatched by string content at config-load time:

- Contains `{var}` placeholder → **captured glob** (e.g. `'domain-{m}'`)
- Contains glob metacharacters (`*`, `?`, `[`) without `{var}` → **glob** (e.g. `'domain-*'`)
- Otherwise → **exact** (e.g. `'shared-kernel'`)

Layer names cannot contain `*`, `?`, `[`, `{`, `}` — these are reserved for selector syntax (validated at template-name and static-name config load). Unbalanced braces in a selector string (`'domain-{module'`) are rejected at config load with a `ConfigLoadException` — silent fall-through to exact-match would surprise users.

The `LayerSelector` API is **split** into source-side and target-side operations:

- `matchSource(string $layerName): ?CaptureBinding` — for LHS; returns binding (possibly empty for glob/exact) on match, null otherwise
- `matchesTarget(string $layerName, CaptureBinding $sourceBinding): bool` — for RHS; captured selectors use the binding established by the source-side match

A single `matches()` method couldn't extract a binding for captured source selectors — the split makes the dataflow explicit.

### D5. Capture-variable binding in allow-list is MANDATORY in Phase 2 MVP

Capture binding (`'app-{m}': ['domain-{m}']` for same-instance-only constraints) ships with the base template feature, not as a deferred enhancement. Without it, `'app-*': ['domain-*']` permissively allows cross-module app→domain edges — defeating the bounded-context isolation that motivates the entire direction.

**Caveat.** D5 closes the main DDD leak (same-instance allows), but does NOT make every wildcard configuration safe. A user who writes `'domain-*': ['domain-*']` (wildcard on both sides) still permits all-to-all. Such configurations emit a `warning`-severity diagnostic at config load, silenceable per entry via an explicit `allow_cross_instance: true` flag that signals intent.

### D6. Backward compatibility for Phase 1 users is required

Phase 1 YAML configs (post-followup-pivot from ADR 0006) without templates, suffix, attributes, or capture binding continue to work unchanged. Phase 2 strictly extends the schema; it does not modify or rename existing keys. The followup-plan's specificity → ordered pivot is the only breaking change in this trajectory.

### D7. Template layer membership: capture-producing criteria mandatory; non-capturing always AND-filter

A criterion is **capture-producing** if it references one or more capture variables `{var}` declared in the layer's name template. For non-template layers, every criterion is non-capturing and D2 applies as described.

For template layers:

- The layer MUST declare at least one capture-producing criterion (config-load validation)
- **Capture-producing criteria** combine according to `match: any | all`. `any` — at least one matches and establishes bindings. `all` — every capture-producing criterion matches with consistent bindings
- **Non-capturing criteria** ALWAYS act as AND-filters on the captured candidates, regardless of `match` mode. They can narrow membership but never widen it
- If a class matches a capture-producing criterion but fails a non-capturing filter during expansion, the class is excluded from tuple observation — the instance is never created, rather than being created and then unreachable

This carve-out resolves the otherwise-undefined behaviour of `match: any` for a class that matches only by a non-capturing criterion (no binding could be inferred for `{var}`).

### Direction 4 implementation note

The dependency-type filter (`relations:` on long-form allow entries) uses `DependencyType` from `src/Core/Dependency/DependencyType.php` **directly**. No parallel enum: `AllowTarget` carries `?list<DependencyType>`. Aliases (`inheritance`, `static_access`, `type_reference`, `runtime_check`) expand to constituent `DependencyType` values at config load via a configuration-layer service `AllowAliasExpander`, which validates direct token values against `DependencyType::cases()` **reflectively**. Adding a new `DependencyType` value in `Core` automatically becomes accepted by `relations:` without a Phase 2 code change — the drift risk between user-facing surface and collector output is closed by reflection.

`attribute` stands alone, not grouped under any alias — it's a distinct metadata category.

Whitelist-only: `forbid_relations` is rejected as redundant with whitelist semantics (`relations: [extends]` already implicitly forbids everything else). Adding it later would not break whitelist users.

## Out of scope (deferred or explicitly rejected)

- **Per-edge severity** (`level: warning | error` per allow entry) — no precedent in deptrac / ArchUnit / NDepend. Emulate via two rules with different severity if the use case becomes loud.
- **Positional partitioning** (`partition_by: 1` referring to wildcard index) — rejected during design in favour of symbolic capture variables (D3). Positional addressing is fragile under YAML edits — adding a wildcard silently shifts indices.
- **`forbid_relations` for direction 4** — whitelist suffices; revisit on real demand.
- **Instance method-call relation kind** — requires extending the collector first; candidate for Phase 3.
- **Per-source default for `relations`** — defer until a real signal.
- **Catch-all layer as a separate feature** — subsumed by declaration-order matching (a final layer with `patterns: ['**']`). Remains a documentation recipe rather than syntax.
- **Discovery-aware caching of template expansion across runs** — expansion is cheap; caching adds invalidation complexity for marginal gain.

## Consequences

- **Schema is strictly extended, not changed.** Existing Phase 1 configs (post-ADR 0006 schema) keep working. The new keys (`suffix`, `attributes`, `implements`, `extends`, `match`, `exclude`, capture-variable syntax in names and selectors, `relations` on long-form allow targets, `allow_cross_instance`) are opt-in.
- **New pipeline stage.** `LayerExpansionStage` slots between Collection and RuleExecution. It is the only stage allowed to look at both raw configuration (template layers) and collected class metadata. Downstream rule code sees only concrete `LayerDefinition`s.
- **`AllowAliasExpander` is reflective.** Future `DependencyType` additions surface automatically; the alias map is the only Phase-2-controlled vocabulary.
- **Two new info-severity diagnostics specific to Phase 2.** `architecture.empty-template` (warning, not info — a typo silently disables policy and deserves attention) and the existing `unreachable-layer` extended to fire per concrete template instance.
- **2a + 2b ship together.** The implementation plan does not split capture-binding into a fast-follower step; without 2b, direction 2's DDD partitioning value is incomplete (D5).
- **Larger contract surface.** `LayerDefinition`, `MembershipSpec`, `TemplateLayerDefinition`, `LayerExpansionStage`, `LayerSelector`, `CaptureBinding`, `AllowTarget`, `AllowAliasExpander`, `ExcludeSpec` — most new under Phase 2. ADR 0007 locks the conceptual shape; the live signatures live in `src/Core/Architecture/`; the user-facing surface lives in `website/docs/rules/architecture.md`.

## References

- Builds on: [ADR 0005](0005-architecture-rules.md) (Phase 1 schema and contracts), [ADR 0006](0006-architecture-rules-declaration-order.md) (declaration-order foundation)
- Implementation entry points: `src/Core/Architecture/`, `src/Configuration/Architecture/`, `src/Analysis/Architecture/LayerExpansionStage.php`, `src/Rules/Architecture/LayerViolationRule.php`
- User-facing docs: `website/docs/rules/architecture.md` (and `.ru.md`)
- `DependencyType` enum: `src/Core/Dependency/DependencyType.php`
- Prior art:
  - [deptrac](https://github.com/qossmic/deptrac) — class-name suffix, multi-criteria membership (different syntax)
  - [ArchUnit](https://www.archunit.org/) — predicate composition (`and`/`or`), capture-style scoping in `slices()`
  - [NetArchTest](https://github.com/BenMorris/NetArchTest), [Dependency Cruiser](https://github.com/sverweij/dependency-cruiser) — analogous mechanisms in .NET and JS
