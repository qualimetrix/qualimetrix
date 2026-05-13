# Architecture Rules — Follow-up Cleanup

**Status:** Ready for execution
**Author:** 2026-05-12 (post-implementation review of `docs/internal/plans/architecture-rules.md`)
**Target:** Eliminate technical debt left after the architecture.layer-violation feature shipped + pivot the matching semantics to declaration-order
**Tier:** 1 (changes a locked design decision and the user-facing YAML schema)

---

## Context for executors

The architecture.layer-violation feature (see [architecture-rules.md](architecture-rules.md)) shipped after triple review and post-review fixes. During a final critical pass the orchestrator catalogued items that were either intentionally deferred or compromised under time pressure.

**Crucial pivot.** Post-implementation analysis showed that the specificity-based layer resolution algorithm carries three compensation layers (pre-validation heuristic in factory, runtime `architecture.layer-collision` diagnostic, ADR-documented limitation). That depth of mitigation is a smell — when an algorithm needs three escape hatches, the algorithm is wrong, not the edges. The locked decision from the original plan ("specificity-based resolution, single layer per class") is hereby revised to **declaration-order matching, first match wins** — the same semantics used by deptrac, ArchUnit, `.gitignore`, Apache, and most RBAC engines.

Step 0 implements that pivot and **removes** large amounts of code added in the original feature. Steps 1–6 are the residual cleanup, and several of them shrink or disappear because Step 0 eliminates their target code.

Backward compatibility is **explicitly waived** — only the project itself uses the feature today (dogfooding), so a YAML schema change is acceptable.

**Read before starting any step:**
- [docs/internal/plans/architecture-rules.md](architecture-rules.md) — original Tier 1 plan, now superseded on the matching algorithm
- [docs/adr/0005-architecture-rules.md](../../adr/0005-architecture-rules.md) — must be revised or superseded by a new ADR documenting the pivot
- [CLAUDE.md](../../../CLAUDE.md)
- Implementation entry points: `src/Core/Architecture/`, `src/Configuration/Architecture/`, `src/Rules/Architecture/LayerViolationRule.php`
- [website/docs/rules/architecture.md](../../../website/docs/rules/architecture.md)

---

## Pre-flight: triple-review the plan before code

Per CLAUDE.md and memory `[[plan_stage_triple_review]]`, Tier 1 changes that revise locked decisions require **Gemini + Codex review of this plan** before any code is written. Orchestrator dispatches the review concurrently with the executor reading the plan. Address findings before starting Step 0.

---

## Step 0: Switch to declaration-order matching (FOUNDATION)

**Goal.** Replace specificity-based resolution with first-match-wins over the user's declared layer order. Remove every concept that exists only to support specificity (collision detection, pattern pre-validation heuristic, registry collision cache, ambiguity diagnostic).

**Why this is a single Step despite the size.** The change is atomic — the new YAML schema, the new resolution algorithm, the removed exception, and the docs/dogfooding update are mutually entangled. Splitting risks an intermediate state where two semantics co-exist.

**YAML schema change.**

Old:
```yaml
architecture:
  layers:
    controller: 'App\Controller\**'
    repository: 'App\Repository\**'
```

New (ordered list of layer entries):
```yaml
architecture:
  layers:
    - name: controller
      patterns: ['App\Controller\**']
    - name: repository
      patterns: ['App\Repository\**']
```

Patterns can stay as a list-of-strings; the value of `name` per entry is the layer name. The list is **ordered** — the first layer whose patterns match the class FQN wins.

A short scalar form is acceptable as syntactic sugar:
```yaml
architecture:
  layers:
    - controller: 'App\Controller\**'
    - repository: ['App\Repository\**', 'App\Persistence\**']
```
Single-key map per entry, value is the pattern(s). Picking the canonical form is an executor decision — document it in the user-facing schema reference. (Recommendation: support both, normalize to the long form internally.)

**Files removed.**
- `src/Core/Architecture/Layer/LayerCollisionException.php`
- The `bestMatchingPattern()` helper and the collision-cache machinery in `LayerRegistry`
- The specificity computation in `LayerDefinition` (`match(): ?int`, `firstWildcardPosition()`)
- The `CollisionHeuristic` pre-validation logic in `ArchitectureConfigurationFactory`
- The `architecture.layer-collision` diagnostic in `LayerViolationRule` (including `COLLISION_DIAGNOSTIC_NAME` constant and `buildCollisionDiagnostics()`)
- The `LayerPolicy::knownLayers()` method (becomes truly dead — see Step 3 commentary)
- All tests targeting the removed code

**Files modified.**
- `src/Core/Architecture/Layer/LayerDefinition.php` — `matches(string $fqn): bool`, no specificity, no `match(): ?int`
- `src/Core/Architecture/Layer/LayerRegistry.php` — receives an ordered list of `LayerDefinition`s; `resolveLayer()` iterates them in order, returns the name of the first match or null
- `src/Configuration/Architecture/ArchitectureConfigurationFactory.php` — accepts the new ordered shape, validates name uniqueness and pattern shape, drops collision detection
- `src/Rules/Architecture/LayerViolationRule.php` — drops the collision branch, simplifies `resolveEdge()`
- `qmx.yaml` (dogfooding) — migrate to the new shape
- All affected tests — rewrite for ordered semantics

**Contracts after Step 0.**

`LayerDefinition`:
- `__construct(string $name, list<string> $patterns)` — same validation as before for name and pattern shape; no specificity stored
- `matches(string $fqn): bool`
- `name(): string`
- `patterns(): list<string>`

`LayerRegistry`:
- `__construct(list<LayerDefinition> $orderedLayers)` — order is significant; constructor validates name uniqueness
- `resolveLayer(SymbolPath $class): ?string` — iterate, first match wins, return name or null; results cached by canonical key
- `layerNames(): list<string>` — preserves declaration order (NOT sorted)
- `isEmpty(): bool`
- `definitions(): list<LayerDefinition>` — preserves order

`LayerPolicy`: unchanged contract, but `knownLayers()` is removed because cross-validation lives in `LayerRegistry::layerNames()` (insertion-ordered now, which is stronger semantics for the factory's cross-check).

**Catch-all pattern as first-class.** With ordered matching, declaring a final layer with pattern `**` is the idiomatic way to capture everything else (replacing `coverage:warn` as the canonical "show me unclassified classes" mechanism). The `architecture.coverage` diagnostic and `CoverageMode` are preserved as-is, but the user-facing docs should explain that with a catch-all layer they are usually unnecessary.

**Test cases (new):**
- Ordered list with `narrow` declared before `broad`: classes matching both fall into `narrow`
- Same list with order reversed: classes fall into `broad`, demonstrating order significance
- Two layers with identical patterns: validator rejects duplicate-name OR documents that earlier wins (recommended: reject duplicate patterns at config-load time with a clear error explaining ordered semantics)
- A class matching no layer returns null (unchanged behavior)
- Catch-all `**` declared last captures the residual

**Test cases (removed):**
- All `LayerCollisionException` tests
- `bestMatchingPattern` tests
- Specificity computation tests
- `architecture.layer-collision` diagnostic tests in `LayerViolationRule`
- Factory pre-validation heuristic tests targeting collision detection

**Documentation.**
- Revise ADR 0005 OR write ADR 0006 superseding the matching-strategy decision in 0005. Capture: the smell that triggered the revision (3 compensation layers), the alternative considered (literal-count specificity — still didn't resolve symmetric globs), the chosen approach (declaration-order), and the industry precedents.
- Update `website/docs/rules/architecture.md` (EN + RU) — replace specificity language with ordered-evaluation language; add catch-all pattern recipe; remove `architecture.layer-collision` references
- Update `src/Core/Architecture/README.md`
- Update CHANGELOG with a `Breaking` entry (YAML schema change)

**DoD:**
- [ ] `composer check` green
- [ ] `bin/qmx check src/` reports 0 architecture violations after qmx.yaml migration
- [ ] No code references `LayerCollisionException` (grep returns empty)
- [ ] All EN/RU docs aligned with ordered semantics
- [ ] ADR captures the pivot

**Dependencies:** none. First step.

**Subagent isolation.** Step 0 is a single big atomic change; one agent owns it end-to-end. Splitting into "primitives" / "factory" / "rule" parallel agents is unsafe because the YAML shape change forces all three to move in lockstep. Use a worktree to avoid disrupting parallel work on other steps.

---

## Step 1: Wire `ConfigurationPipeline` logger so factory warnings reach the user

**Problem.** `ArchitectureConfigurationFactory` emits PSR-3 warnings (e.g., mutual-allow detection). `ConfigurationPipeline` forwards a `DelegatingLogger($loggerHolder)`, but `resolve()` runs before `RuntimeConfigurator::configureLogger()` swaps the holder's `NullLogger` for the real one. In production every warning is dropped.

**Note.** After Step 0 the factory has fewer warning sources (collision heuristic is gone) but mutual-allow detection remains, so this step is still required.

**Goal.** Warnings emitted during configuration resolution reach the user-configured logger.

**Files:**
- MODIFY `src/Configuration/Architecture/ArchitectureConfigurationFactory.php`
- MODIFY `src/Configuration/Pipeline/ConfigurationPipeline.php`
- MODIFY `src/Configuration/Pipeline/ResolvedConfiguration.php`
- MODIFY `src/Infrastructure/Console/RuntimeConfigurator.php`
- NEW `src/Configuration/Pipeline/DeferredWarning.php`
- Tests in `tests/Unit/Configuration/Pipeline/`, `tests/Unit/Configuration/Architecture/`, `tests/Unit/Infrastructure/Console/RuntimeConfiguratorTest.php`

**Approach (deferred warning queue):**
- Factory writes warnings into a returned collection instead of (or alongside) a logger.
- `ResolvedConfiguration` gains `deferredWarnings: list<DeferredWarning>`.
- `RuntimeConfigurator::configure()` drains the list to the configured logger AFTER `configureLogger()` runs.

**Alternative considered (rejected):** reorder logger configuration. Rejected because logger configuration depends on CLI input which is already coupled to resolved configuration in non-trivial ways; touching that ordering risks broader regressions.

**Contract:**
- `DeferredWarning` is a small VO with `level: LogLevel` and `message: string` (and optional `context: array`).
- Factory contract: `fromArray(array $raw): ArchitectureFactoryResult` where `ArchitectureFactoryResult` carries `config` and `warnings`. Existing logger argument is removed.

**Test cases:**
- Mutual-allow `A↔B` produces one `DeferredWarning` in resolved config; an integration test using the full DI container + CheckCommand asserts the warning lands in a captured logger
- Configurations without warnings produce empty `deferredWarnings`

**DoD:**
- [ ] `composer check` green
- [ ] New integration test demonstrates production-path visibility of factory warnings
- [ ] Old logger-injection paths removed from factory

**Dependencies:** Step 0 (factory shape may simplify enough to make this cleaner). Sequential after Step 0.

---

## Step 2: De-duplicate pattern matching between `NamespaceMatcher` and `LayerDefinition`

**Problem (residual after Step 0).** `LayerDefinition::matches()` still needs prefix-vs-glob detection. Today it duplicates the logic from `NamespaceMatcher::isGlobPattern` + `matches`. Drift risk if either side evolves.

**Goal.** Single source of truth for per-pattern matching.

**Files:**
- MODIFY `src/Core/Util/NamespaceMatcher.php` — add public/internal static helpers
- MODIFY `src/Core/Architecture/Layer/LayerDefinition.php` — delegate
- Tests: `tests/Unit/Core/Util/NamespaceMatcherTest.php` for new public surface

**Contract:**
- `NamespaceMatcher::matchesSingle(string $pattern, string $namespace): bool` — static, mirrors current per-pattern semantics
- `NamespaceMatcher::isGlob(string $pattern): bool` — static
- Existing `NamespaceMatcher` instance API (`matches()`, `isEmpty()`) preserved
- `LayerDefinition::matches()` delegates to `NamespaceMatcher::matchesSingle` for each pattern

**DoD:**
- [ ] `composer check` green
- [ ] `LayerDefinition` no longer contains pattern-matching primitives
- [ ] No behavioural change in `LayerDefinitionTest`

**Dependencies:** Step 0 (because Step 0 removes the specificity-related methods, narrowing what this step touches). Sequential after Step 0; parallelisable with Step 1.

---

## Step 3: Confirm `LayerPolicy::knownLayers()` removed

**Status:** Step 0 already removes this method. This step exists only to verify the removal landed correctly and the README is in sync.

**Files:**
- VERIFY `src/Core/Architecture/Layer/LayerPolicy.php` — method absent
- VERIFY `src/Core/Architecture/README.md` — no stale references
- VERIFY tests — no references to `knownLayers()`

**DoD:**
- [ ] `grep -rn "knownLayers" src/ tests/` returns no hits

**Dependencies:** Step 0. Often resolved as part of Step 0; kept as a separate verification gate.

---

## Step 4: Refactor `ArchitectureConfigurationFactory` (smaller scope after Step 0)

**Problem.** Even after Step 0 strips out collision-related code, the factory still bundles independent validation concerns: top-level keys, layer entries, allow entries, coverage value, mutual-allow detection. WMC will remain above the project default (50) without decomposition.

**Goal.** Decompose into focused collaborators. Drop the `@qmx-threshold complexity.wmc error=100` annotation.

**Files:**
- MODIFY `src/Configuration/Architecture/ArchitectureConfigurationFactory.php` — becomes orchestrator
- NEW `src/Configuration/Architecture/Validation/LayersValidator.php`
- NEW `src/Configuration/Architecture/Validation/AllowValidator.php`
- NEW `src/Configuration/Architecture/Validation/CoverageValidator.php`
- NEW `src/Configuration/Architecture/Validation/MutualAllowDetector.php`
- Tests per validator

**Removed from scope (vs. previous version of this plan):** `CollisionHeuristic` — gone with Step 0.

**Contract:**
- Each validator exposes a single `validate(...)` method returning the parsed sub-result or throwing `ConfigLoadException`
- Factory composes validators in deterministic order
- The deferred-warning collector from Step 1 is injected into `MutualAllowDetector`

**DoD:**
- [ ] `composer check` green
- [ ] Factory WMC below project default (50)
- [ ] `@qmx-threshold` annotation removed
- [ ] No public API change visible to callers of `fromArray`

**Dependencies:** Step 1 (uses the deferred-warning collector). Sequential after Step 1.

---

## Step 5: Document `Severity::Info` for end users

**Problem.** `Severity::Info` was added during the post-review sweep. PHPDoc on the enum and formatter behavior is in place, but reference documentation, configuration guide, and the rules catalog do not mention the new level.

**Files:**
- MODIFY `website/docs/reference/default-thresholds.md` (and `.ru.md`)
- MODIFY `website/docs/getting-started/configuration.md` (and `.ru.md`) — add `fail_on: info`
- MODIFY `website/docs/rules/architecture.md` (and `.ru.md`) — coverage mode `warn` section
- VERIFY `CHANGELOG.md` mentions the new severity

**Content guidance:**
- Info never fails the run by default
- `fail_on: info` opts into stricter behavior
- Info-only run exits 0 by default

**DoD:**
- [ ] `cd website && .venv/bin/mkdocs build --strict` green
- [ ] EN and RU stay structurally aligned
- [ ] CHANGELOG entry under `[Unreleased]`

**Dependencies:** none. Anytime.

---

## Step 6: Cosmetic cleanup

**Sub-items (reduced from previous plan — 6.1 removed because `LayerCollisionException` is gone after Step 0):**

### 6.1 Golden JSON fixture for integration test

The original architecture-rules plan asked for a golden JSON file (`expected-violations.json`); the executor used in-test assertions. After Step 0 the violation set is more stable (no collision diagnostics jittering between runs), making a golden file easier to maintain.

**Files:**
- NEW `tests/Fixtures/ArchitectureSample/expected-violations.json` (+ fixture `qmx.yaml` if useful)
- MODIFY `tests/Integration/Architecture/LayerViolationIntegrationTest.php` — assert against golden file with a stable JSON formatter

Document the regeneration command in the test header.

### 6.2 Consolidate `RecordingLogger` / `TestLogger` test doubles

**Files:**
- NEW `tests/Support/Logger/RecordingLogger.php`
- MODIFY callers in `tests/Unit/Configuration/Architecture/`, `tests/Unit/Configuration/Pipeline/`
- MODIFY composer.json `autoload-dev` if a new namespace is needed

**DoD (whole step):**
- [ ] `composer check` green
- [ ] Golden file regeneration documented
- [ ] No duplicate logger doubles in test tree

**Dependencies:** Step 1 (test double consolidation overlaps with logger work). Sequential after Step 1.

---

## Cross-cutting

### Validation strategy

After each step, run `composer check` AND `bin/qmx check src/ --memory-limit=512M` (self-analysis must remain clean of new violations).

### Review trigger

Per CLAUDE.md, this is **Tier 1** (revises locked design decision, breaks user-facing schema).

- **Plan-stage triple review (Claude + Gemini + Codex) BEFORE any code is written.** Memory `[[plan_stage_triple_review]]` records that this catches contract mismatches early.
- **Implementation triple review** after Step 0 lands (new domain seam = matching algorithm pivot).
- **Standard review** for Steps 1, 4 individually.
- **No review** for Steps 2, 3, 5, 6 (mechanical / docs).

### Sequencing

```
[Plan triple review]
        |
        v
   [ Step 0 ]
        |
        v
   [ Implementation triple review of Step 0 ]
        |
   +----+----+
   v         v
[Step 1]  [Step 2]   [Step 3 verify]   [Step 5 docs — anytime]
   |
   v
[Step 4 — factory refactor]
   |
   v
[Step 6 — cosmetics]
```

### Backward compatibility

**Explicitly waived.** Only the project itself uses the architecture rules today (dogfooding). The YAML schema change in Step 0 will be communicated through CHANGELOG `Breaking` and the ADR; external users (if any appear before the change ships) will see a clear migration note.

### Definition of Done (whole plan)

- [ ] Plan triple review addressed before Step 0
- [ ] All 6 steps complete
- [ ] `composer check` green
- [ ] `bin/qmx check src/` clean
- [ ] Website rebuilds with `mkdocs build --strict`
- [ ] CHANGELOG `Breaking` entry for the YAML pivot
- [ ] ADR 0005 revised or ADR 0006 added documenting the pivot
- [ ] Implementation triple review of Step 0 findings addressed
- [ ] Triple review of Step 4 (if any new abstractions surface)
