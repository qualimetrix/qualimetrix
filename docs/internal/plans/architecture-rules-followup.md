# Architecture Rules — Follow-up Cleanup

**Status:** Ready for execution (pending final ADR drafting)
**Author:** 2026-05-12 (post-implementation review of `docs/internal/plans/architecture-rules.md`)
**Last revised:** 2026-05-13 (round 2 triple-review: evidence-based `potential-shadow`, hit counter as local variable, `LayerRegistry::resolveAll()` API, YAML merge semantics for ordered list, fixed Step 1/3 contract contradiction)
**Target:** Eliminate technical debt left after the architecture.layer-violation feature shipped + pivot the matching
semantics to declaration-order
**Tier:** 1 (changes a locked design decision and the user-facing YAML schema)

---

## Context for executors

The architecture.layer-violation feature (see [architecture-rules.md](architecture-rules.md)) shipped after triple
review and post-review fixes. During a final critical pass the orchestrator catalogued items that were either
intentionally deferred or compromised under time pressure.

**Crucial pivot.** Post-implementation analysis showed that the specificity-based layer resolution algorithm carries
three compensation layers (pre-validation heuristic in factory, runtime `architecture.layer-collision` diagnostic,
ADR-documented limitation). That depth of mitigation is a smell — when an algorithm needs three escape hatches, the
algorithm is wrong, not the edges. The locked decision from the original plan ("specificity-based resolution, single
layer per class") is hereby revised to **declaration-order matching, first match wins** — the same semantics used by
deptrac, ArchUnit, `.gitignore`, Apache, and most RBAC engines.

Step 0 implements that pivot and **removes** large amounts of code added in the original feature. It introduces two
replacement safety nets:

- `architecture.unreachable-layer` — catches the loud case (a layer whose patterns matched zero classes during the run)
- `architecture.potential-shadow` — catches the quieter case (a class matches multiple layers' patterns; the earlier
  one silently "stole" the class). Detection is **evidence-based**: walks all classes and records every (assigned,
  shadowed) pair seen in practice. This is exact — catches every real shadow regardless of how the patterns are
  written (prefix-overlap, suffix-theft, arbitrary intersection)

Both replacements are info-severity (`fail_on: info` opts in) and do not re-introduce specificity machinery. Steps 1–6
are residual cleanup + a debug CLI command that gives users per-class introspection of layer assignment.

Backward compatibility is **explicitly waived** — only the project itself uses the feature today (dogfooding), so a YAML
schema change is acceptable.

**Read before starting any step:**

- [docs/internal/plans/architecture-rules.md](architecture-rules.md) — original Tier 1 plan, now superseded on the
  matching algorithm
- [docs/adr/0005-architecture-rules.md](../../adr/0005-architecture-rules.md) — to be superseded by **new ADR 0006**
  (do not revise 0005; preserve historical reasoning)
- [CLAUDE.md](../../../CLAUDE.md) — note the rule about stateless rules; the hit counter for `unreachable-layer`
  MUST live as a local variable inside `analyze()`, not a rule field
- Implementation entry points: `src/Core/Architecture/`, `src/Configuration/Architecture/`,
  `src/Rules/Architecture/LayerViolationRule.php`
- [website/docs/rules/architecture.md](../../../website/docs/rules/architecture.md)

---

## Pre-flight: triple-review the plan before code

Per CLAUDE.md and memory `[[plan_stage_triple_review]]`, Tier 1 changes that revise locked decisions require **Gemini +
Codex review of this plan** before any code is written. Two review rounds have been completed; findings are folded into
this revision. Address any remaining findings before starting Step 0.

---

## Step 0: Switch to declaration-order matching (FOUNDATION)

**Goal.** Replace specificity-based resolution with first-match-wins over the user's declared layer order. Remove every
concept that exists only to support specificity (collision detection, pattern pre-validation heuristic, registry
collision cache, ambiguity diagnostic). Introduce two replacement safety nets — `architecture.unreachable-layer` and
`architecture.potential-shadow` — to catch misordered declarations and silent shadowing.

**Why this is a single Step despite the size.** The change is atomic — the new YAML schema, the new resolution
algorithm, the removed exception, the two new safety diagnostics, and the docs/dogfooding update are mutually
entangled. Splitting risks an intermediate state where two semantics co-exist.

**YAML schema change.**

Old:

```yaml
architecture:
  layers:
    controller: 'App\Controller\**'
    repository: 'App\Repository\**'
```

New (ordered list of layer entries — **long form only**):

```yaml
architecture:
  layers:
    - name: controller
      patterns: [ 'App\Controller\**' ]
    - name: repository
      patterns: [ 'App\Repository\**' ]
```

Patterns are always a list-of-strings; the value of `name` per entry is the layer name. The list is **ordered** — the
first layer whose patterns match the class FQN wins.

**No sugar form.** An earlier draft considered a single-key-map shorthand
(`- controller: 'App\Controller\**'`). Rejected because (a) single-key map per list entry is a known YAML antipattern
that confuses parsers, IDE schemas, and diagnostics; (b) one canonical form minimises cognitive load and the surface
area of the validator. Users always write the long form.

**Configuration merge semantics (NEW — was implicit before).** `ConfigurationMerger` currently treats
`architecture.layers` as a map and merges entries by name. Under ordered-list semantics, order is meaningful and naive
deep-merge breaks it. Decision: **the `architecture.layers` list is REPLACED in its entirety when a later configuration
source defines it** — not appended, not merged. Preset configs that define `architecture.layers` overwrite the field
completely; project configs that define it overwrite presets completely. Document this in both ConfigurationMerger code
(comment) and user-facing docs. Add test cases for the new merge semantics.

**Files removed.**

- `src/Core/Architecture/Layer/LayerCollisionException.php`
- The `bestMatchingPattern()` helper and the collision-cache machinery in `LayerRegistry`
- The specificity computation in `LayerDefinition` (`match(): ?int`, `firstWildcardPosition()`)
- The `CollisionHeuristic` pre-validation logic in `ArchitectureConfigurationFactory`
- The `architecture.layer-collision` diagnostic in `LayerViolationRule` (including `COLLISION_DIAGNOSTIC_NAME` constant
  and `buildCollisionDiagnostics()`)
- The `LayerPolicy::knownLayers()` method (truly dead under ordered semantics — cross-validation now relies on
  `LayerRegistry::layerNames()` which preserves declaration order)
- All tests targeting the removed code

**Files modified.**

- `src/Core/Architecture/Layer/LayerDefinition.php` — `matches(string $fqn): bool`, no specificity, no `match(): ?int`
- `src/Core/Architecture/Layer/LayerRegistry.php` — receives an ordered list of `LayerDefinition`s; `resolveLayer()`
  iterates them in order, returns the name of the first match or null. **Stateless lookup service** — no hit
  counters, no precomputed shadow pairs. New companion API `resolveAll()` for evidence-based shadow detection (see
  contracts below).
- `src/Configuration/Architecture/ArchitectureConfigurationFactory.php` — accepts the new ordered shape, validates name
  uniqueness, pattern shape, and rejects duplicate patterns (same patterns under different names is always a
  configuration mistake under ordered semantics — the second is unreachable; reject at load with a clear error).
  Drops collision detection.
- `src/Rules/Architecture/LayerViolationRule.php` — drops the collision branch, simplifies `resolveEdge()`. Adds
  end-of-analysis emission of `architecture.unreachable-layer` and `architecture.potential-shadow` (see diagnostic
  sections below).
- `src/Configuration/ConfigurationMerger.php` — applies the "replace whole list" semantics for `architecture.layers`
  described above.
- `qmx.yaml` (dogfooding) — migrate to the new shape
- All affected tests — rewrite for ordered semantics

**New diagnostic: `architecture.unreachable-layer`.**

Removing `architecture.layer-collision` exposes the loud failure mode in declaration-order semantics: a too-broad
pattern declared early shadows every later layer entirely, and the user sees zero violations rather than an error:

```yaml
architecture:
  layers:
    - name: legacy
      patterns: ['**']                # silently captures everything
    - name: controller
      patterns: ['App\Controller\**'] # zero matches, layer is dead
```

`architecture.unreachable-layer` covers this:

- **Severity:** `Info` (does not fail the run by default; `fail_on: info` opts in to strict CI behaviour)
- **Emission:** once per declared layer that matched zero classes during the run
- **Message:** "Layer 'X' was never matched during analysis. Possible causes: (1) it is shadowed by a broader layer
  earlier in the declaration order, (2) the pattern matches no class in the analysed codebase. Run
  `qmx debug:layer-assignment <class>` to inspect specific classes."
- **Hit counting source:** the rule iterates `AnalysisContext::$metrics->all(SymbolType::Class_)` (NOT the dependency
  graph) and resolves each class to its layer. This way classes without outgoing dependencies (DTO-only layers,
  marker interfaces, entry points) still register as hits.
- **State management:** the hit counter is a **local variable** inside `LayerViolationRule::analyze()`, NEVER a class
  field. CLAUDE.md mandates stateless rules; the executor reuses rule instances across `analyze()` calls. Add a
  regression test: two sequential `analyze()` calls on the same rule instance with different contexts must not
  share hit counts.

**New diagnostic: `architecture.potential-shadow`.**

`unreachable-layer` catches the loud case (a layer that captured nothing). Declaration-order has a quieter failure
mode: when a class matches multiple layers' patterns, only the first wins — earlier layers can silently "steal"
classes that a user expected to belong to a later layer. Example:

```yaml
- name: any-foo
  patterns: ['App\**\Foo']          # A: matches any FQN ending in Foo under App
- name: service
  patterns: ['App\Service\**']      # B: matches App\Service\*
```

Class `App\Service\Foo` matches both → goes to A. Class `App\Service\Bar` matches only B → goes to B. B is reachable
(it has matches), so `unreachable-layer` does not fire. But `App\Service\Foo` silently leaked into A.

A separate footgun: **suffix-theft** — `'**\*Service'` declared first will steal every `App\Domain\OrderService`
away from a later `'App\Domain\**'` layer (`**\*Service` matches any class ending in `Service` regardless of
namespace). A static prefix-overlap heuristic couldn't catch this case because the earlier pattern has no literal
prefix at all — it relies on the trailing `*Service` portion.

`architecture.potential-shadow` detects these cases **by evidence during analysis**:

- For each class in `AnalysisContext::$metrics->all(SymbolType::Class_)`, the rule walks layer definitions in
  declaration order and records ALL layers whose patterns match the class FQN (not just the first one). This uses
  `LayerRegistry::resolveAll()` (see contracts).
- For classes that match more than one layer, group by the (assigned, shadowed) pair: `assigned` is the first match
  (the layer the class actually went to), each subsequent match is a `shadowed` layer that lost the class.
- After scanning all classes, emit one info diagnostic per distinct (assigned, shadowed) pair, with a sample of up
  to **5 example class FQNs** (then "...and N more" if the total exceeds the sample).
- **Deterministic output:** `metrics->all(SymbolType::Class_)` iteration order depends on collection (potentially
  parallel) order and is not stable between runs. To guarantee stable diagnostics:
  1. Within each (assigned, shadowed) pair, sort the collected class FQNs lexicographically before truncating to
     the 5-element sample
  2. After aggregation, sort the (assigned, shadowed) pair list lexicographically by (assigned name, shadowed name)
     before emission
- **Complexity:** `O(classes × layers × patterns-per-layer)`. Typical projects have <20 layers with 1–3 patterns
  each, so this stays bounded (a 10k-class project ≈ 200–600k pattern matches, tens of ms).

**Why evidence-based, not static.** The original revision proposed a static prefix-overlap heuristic. Round 2 review
found that the heuristic fails on its own canonical example (`App\**\Foo` vs `App\Service\**`) because `fnmatch`
doesn't match `App\**\Foo` against the literal prefix `App\`. Generalising to arbitrary glob-glob intersection is
out of scope due to the `fnmatch` dialect plus namespace-prefix mode (regular language intersection is decidable in
theory, but a faithful static check for this dialect would be expensive and error-prone). Evidence-based is exact,
simple, and bounded.

- **Severity:** `Info` (same severity policy as `unreachable-layer`)
- **Emission:** at end of rule analysis, one diagnostic per (assigned, shadowed) pair, grouped output
- **Message format:** `"Layer 'A' (pattern: 'P_A_that_matched') shadows layer 'B' (pattern: 'P_B_that_would_match')
  for N class(es) including App\Service\Foo, App\Service\Bar. Run 'qmx debug:layer-assignment <class>' to inspect
  specific cases."`
- **Output cardinality.** A broad first layer (e.g. accidental `'**'`) can produce one diagnostic per shadowed
  layer, regardless of class count — the summarisation keeps output proportional to the number of shadowed LAYERS,
  not classes.
- **Implementation:** `LayerViolationRule` collects evidence into a **local** `array<string, array<string, list<string>>>`
  variable during the class iteration (same iteration that feeds `unreachable-layer`'s hit counter). After the loop,
  aggregates and emits diagnostics. Same statelessness rule applies — local, not field.

**Contracts after Step 0.**

`LayerDefinition`:

- `__construct(string $name, list<string> $patterns)` — same validation as before for name and pattern shape; no
  specificity stored
- `matches(string $fqn): bool` — fast path: any pattern matches
- `firstMatchingPattern(string $fqn): ?string` — returns the specific pattern that matched (or null) — used by the
  debug command and by `resolveAll()` to populate `LayerMatch.matchingPattern`
- `name(): string`
- `patterns(): list<string>`

`LayerMatch` (new VO):

- `layerName: string`
- `matchingPattern: string`

`LayerRegistry`:

- `__construct(list<LayerDefinition> $orderedLayers)` — order is significant; constructor validates name uniqueness
  and pattern-set uniqueness across layers
- `resolveLayer(SymbolPath $class): ?string` — first match, short-circuits, returns layer name or null. Cached by
  canonical key. Used in the hot path (per-edge analysis).
- `resolveAll(SymbolPath $class): list<LayerMatch>` — ALL matches in declaration order. Cached by canonical key.
  Used by `LayerViolationRule` for evidence-based shadow detection AND by the debug command in Step 6.
- `layerNames(): list<string>` — preserves declaration order (NOT sorted)
- `isEmpty(): bool`
- `definitions(): list<LayerDefinition>` — preserves order

`LayerViolationRule` (state-handling additions):

- Inside `analyze()`, maintains TWO local variables: hit counter (per layer) and shadow-evidence map. NEVER fields.
- After class iteration, builds `architecture.unreachable-layer` and `architecture.potential-shadow` diagnostics
  from these locals and adds them to the violations list.

`LayerPolicy`: remaining contract unchanged. `knownLayers()` is removed because cross-validation lives in
`LayerRegistry::layerNames()` (insertion-ordered now, which is stronger semantics for the factory's cross-check).

`ArchitectureConfigurationFactory`:

- `fromArray(array $raw): ArchitectureFactoryResult` — returns a result object carrying `ArchitectureConfiguration $config`
  AND `list<DeferredWarning> $warnings`. Step 1 introduces the deferred-warning mechanism; until Step 1 lands,
  Step 0's implementation can return an empty warning list (or use a transient inner type renamed by Step 1).
- **Step 1/3 contract reconciliation.** Step 3 currently says "no public API change visible to callers of
  `fromArray`". This is now incorrect — the API IS changing in Step 1 (returns result object). Step 3's DoD updated
  to: "no public API change beyond what Step 1 already introduced". Updated in the Step 3 section below.

**Catch-all pattern as first-class.** With ordered matching, declaring a final layer with pattern `**` is the idiomatic
way to capture everything else (replacing `coverage:warn` as the canonical "show me unclassified classes" mechanism).
The `architecture.coverage` diagnostic and `CoverageMode` are preserved as-is, but the user-facing docs should explain
that with a catch-all layer they are usually unnecessary. The `architecture.unreachable-layer` diagnostic naturally
guards against an accidental catch-all in non-final position; `architecture.potential-shadow` exposes any silent
shadowing that actually occurs on real classes.

**Test cases (new):**

- Ordered list with `narrow` declared before `broad`: classes matching both fall into `narrow`
- Same list with order reversed: classes fall into `broad`, demonstrating order significance
- Two layers with identical patterns: validator rejects with a clear error
- A class matching no layer returns null (unchanged behavior)
- Catch-all `**` declared last captures the residual
- `architecture.unreachable-layer`: layer whose pattern matches no class fires one info diagnostic
- `architecture.unreachable-layer`: layer with only-DTO-classes (no outgoing dependencies) does NOT fire (because
  hit counting is over `metrics->all(Class_)`, not the dependency graph)
- `architecture.unreachable-layer`: layer that is fully shadowed by a broader layer above fires (the hit counter
  for it stays zero because every class that would have matched goes to the broader one)
- `architecture.potential-shadow`: `App\**\Foo` declared first, `App\Service\**` second, fixture contains
  `App\Service\Foo` and `App\Service\Bar` → emits one diagnostic for the pair (any-foo, service), sample includes
  `App\Service\Foo`
- `architecture.potential-shadow`: suffix-theft `'**\*Service'` first, `App\Domain\**` second, fixture contains
  `App\Domain\OrderService` and `App\Domain\OrderRepository` → emits one diagnostic for the pair, sample includes
  `App\Domain\OrderService`
- `architecture.potential-shadow`: empty class set (no classes analysed) emits zero diagnostics
- `architecture.potential-shadow`: deterministic output across runs — two runs over the same fixture must emit
  diagnostics in identical order (regression test against the sort steps)
- `architecture.potential-shadow`: layers with truly disjoint patterns emit nothing
- **Statelessness regression:** create one `LayerViolationRule` instance, call `analyze()` with two different
  contexts back-to-back; hit counters and shadow evidence MUST NOT leak between calls

**Test cases (removed):**

- All `LayerCollisionException` tests
- `bestMatchingPattern` tests
- Specificity computation tests
- `architecture.layer-collision` diagnostic tests in `LayerViolationRule`
- Factory pre-validation heuristic tests targeting collision detection

**Documentation.**

- Write **ADR 0006 superseding ADR 0005** on the matching-strategy decision. Do not revise 0005 — preserve the
  historical reasoning. Capture in 0006: the smell that triggered the pivot (3 compensation layers), the alternative
  considered (literal-count specificity — still didn't resolve symmetric globs), the chosen approach (declaration-order
  matching), the two replacement diagnostics (`unreachable-layer` + evidence-based `potential-shadow`) and why
  evidence-based was chosen over static intersection (`fnmatch` dialect plus namespace-prefix mode make faithful
  static intersection complex and error-prone — out of scope, not undecidable), industry precedents (deptrac,
  ArchUnit, `.gitignore`, Apache, RBAC). Add a `Status: Superseded by ADR 0006` marker at the top of 0005.
- Update `website/docs/rules/architecture.md` (EN + RU):
  - Replace specificity language with ordered-evaluation language
  - Add catch-all pattern recipe
  - Remove `architecture.layer-collision` references
  - Document `architecture.unreachable-layer` (when it fires, three reasons it might fire, how to read the message)
  - Document `architecture.potential-shadow` (evidence-based detection, what it catches — every real shadow including
    suffix-theft, output format, link to `qmx debug:layer-assignment`)
  - Add the YAML merge semantics note ("layers list is replaced wholesale by later config sources, not merged")

- Update `src/Core/Architecture/README.md`
- Update CHANGELOG with a `Breaking` entry (YAML schema change + merge semantics) and `Changed` entries for the two new
  diagnostics

**DoD:**

- [ ] `composer check` green
- [ ] `bin/qmx check src/` reports 0 architecture violations after qmx.yaml migration (info diagnostics from
      unreachable-layer / potential-shadow may exist; they should be addressed by reordering, not by ignoring)
- [ ] No code references `LayerCollisionException` (grep returns empty)
- [ ] No code references `LayerPolicy::knownLayers()` (grep returns empty)
- [ ] `architecture.unreachable-layer` fires on a test fixture demonstrating the shadowed-layer footgun
- [ ] `architecture.unreachable-layer` does NOT fire for a DTO-only layer (no outgoing dependencies)
- [ ] `architecture.potential-shadow` fires on `App\**\Foo` vs `App\Service\**` fixture with concrete sample classes
- [ ] `architecture.potential-shadow` fires on the suffix-theft fixture (`**\Entity` vs `App\Domain\**`)
- [ ] Statelessness regression test passes (two sequential `analyze()` on same rule instance)
- [ ] All EN/RU docs aligned with ordered semantics; both new diagnostics documented; YAML merge semantics documented
- [ ] ADR 0006 written; ADR 0005 marked Superseded by 0006

**Dependencies:** none. First step.

**Subagent isolation.** Step 0 is a single big atomic change; one agent owns it end-to-end. Splitting into
"primitives" / "factory" / "rule" parallel agents is unsafe because the YAML shape change forces all three to move in
lockstep. Use a worktree to avoid disrupting parallel work on other steps.

---

## Step 1: Wire `ConfigurationPipeline` logger so factory warnings reach the user

**Problem.** `ArchitectureConfigurationFactory` emits PSR-3 warnings (e.g., mutual-allow detection).
`ConfigurationPipeline` forwards a `DelegatingLogger($loggerHolder)`, but `resolve()` runs before
`RuntimeConfigurator::configureLogger()` swaps the holder's `NullLogger` for the real one. In production every warning
is dropped.

**Note.** After Step 0 the factory has fewer warning sources (collision heuristic is gone) but mutual-allow detection
remains, so this step is still required. Step 0 already changed `fromArray` to return a result object — Step 1 is
the one that actually wires the warnings list through the pipeline.

**Goal.** Warnings emitted during configuration resolution reach the user-configured logger.

**Files:**

- MODIFY `src/Configuration/Architecture/ArchitectureConfigurationFactory.php` — emit `DeferredWarning`s into the
  result object instead of (or alongside) a logger
- MODIFY `src/Configuration/Pipeline/ConfigurationPipeline.php`
- MODIFY `src/Configuration/Pipeline/ResolvedConfiguration.php`
- MODIFY `src/Infrastructure/Console/RuntimeConfigurator.php`
- NEW `src/Configuration/Pipeline/DeferredWarning.php`
- Tests in `tests/Unit/Configuration/Pipeline/`, `tests/Unit/Configuration/Architecture/`,
  `tests/Unit/Infrastructure/Console/RuntimeConfiguratorTest.php`

**Approach (deferred warning queue):**

- Factory's `ArchitectureFactoryResult` (already introduced in Step 0) carries `warnings: list<DeferredWarning>`
- `ResolvedConfiguration` gains `deferredWarnings: list<DeferredWarning>`
- `RuntimeConfigurator::configure()` drains the list to the configured logger AFTER `configureLogger()` runs

**Contract:**

- `DeferredWarning`: small VO with `level: LogLevel` and `message: string` (and optional `context: array`)
- `ArchitectureFactoryResult` introduced in Step 0; this step gives the carried warnings their downstream destination

**Test cases:**

- Mutual-allow `A↔B` produces one `DeferredWarning` in resolved config; an integration test using the full DI
  container + CheckCommand asserts the warning lands in a captured logger
- Configurations without warnings produce empty `deferredWarnings`

**DoD:**

- [ ] `composer check` green
- [ ] New integration test demonstrates production-path visibility of factory warnings
- [ ] Old logger-injection paths removed from factory

**Dependencies:** Step 0 (Step 0 introduces `ArchitectureFactoryResult`). Sequential after Step 0.

---

## Step 2: De-duplicate pattern matching between `NamespaceMatcher` and `LayerDefinition`

**Problem (residual after Step 0).** `LayerDefinition::matches()` still needs prefix-vs-glob detection. Today it
duplicates the logic from `NamespaceMatcher::isGlobPattern` + `matches`. Drift risk if either side evolves.

**Goal.** Single source of truth for per-pattern matching.

**Files:**

- MODIFY `src/Core/Util/NamespaceMatcher.php` — add public/internal static helpers
- MODIFY `src/Core/Architecture/Layer/LayerDefinition.php` — delegate
- Tests: `tests/Unit/Core/Util/NamespaceMatcherTest.php` for new public surface

**Contract:**

- `NamespaceMatcher::matchesSingle(string $pattern, string $namespace): bool` — static
- `NamespaceMatcher::isGlob(string $pattern): bool` — static
- Existing `NamespaceMatcher` instance API (`matches()`, `isEmpty()`) preserved
- `LayerDefinition::matches()` and `firstMatchingPattern()` delegate to `NamespaceMatcher::matchesSingle`

**DoD:**

- [ ] `composer check` green
- [ ] `LayerDefinition` no longer contains pattern-matching primitives
- [ ] No behavioural change in `LayerDefinitionTest`

**Dependencies:** Step 0. Sequential after Step 0; parallelisable with Step 1.

---

## Step 3: Refactor `ArchitectureConfigurationFactory` (smaller scope after Step 0)

**Problem.** Even after Step 0 strips out collision-related code, the factory still bundles independent validation
concerns: top-level keys, layer entries, allow entries, coverage value, mutual-allow detection. WMC will remain above
the project default (50) without decomposition.

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

- Each validator exposes a single `validate(...)` method returning the parsed sub-result or throwing
  `ConfigLoadException`
- Factory composes validators in deterministic order
- The deferred-warning collector from Step 1 is injected into `MutualAllowDetector`

**DoD:**

- [ ] `composer check` green
- [ ] Factory WMC below project default (50)
- [ ] `@qmx-threshold` annotation removed
- [ ] **No public API change beyond what Step 1 already introduced** (i.e., `fromArray` continues to return
      `ArchitectureFactoryResult` — Step 3 does not break that contract again)

**Dependencies:** Step 1 (uses the deferred-warning collector). Sequential after Step 1.

---

## Step 4: Document `Severity::Info` for end users

**Problem.** `Severity::Info` was added during the post-review sweep. PHPDoc on the enum and formatter behavior is in
place, but reference documentation, configuration guide, and the rules catalog do not mention the new level.

**Files:**

- MODIFY `website/docs/reference/default-thresholds.md` (and `.ru.md`)
- MODIFY `website/docs/getting-started/configuration.md` (and `.ru.md`) — add `fail_on: info`
- MODIFY `website/docs/rules/architecture.md` (and `.ru.md`) — coverage mode `warn` section; sections for both new
  diagnostics (cross-link from Step 0 docs)
- VERIFY `CHANGELOG.md` mentions the new severity

**Content guidance:**

- Info never fails the run by default
- `fail_on: info` opts into stricter behavior
- Info-only run exits 0 by default

**DoD:**

- [ ] `cd website && .venv/bin/mkdocs build --strict` green
- [ ] EN and RU stay structurally aligned
- [ ] CHANGELOG entry under `[Unreleased]`

**Dependencies:** none structurally; partially overlaps Step 0 documentation. Coordinate to avoid merge conflicts.

---

## Step 5: Cosmetic cleanup

### 5.1 Golden JSON fixture for integration test

The original architecture-rules plan asked for a golden JSON file (`expected-violations.json`); the executor used
in-test assertions. After Step 0 the violation set is more stable (no collision diagnostics jittering between runs),
making a golden file easier to maintain.

**Files:**

- NEW `tests/Fixtures/ArchitectureSample/expected-violations.json` (+ fixture `qmx.yaml` if useful)
- MODIFY `tests/Integration/Architecture/LayerViolationIntegrationTest.php` — assert against golden file with a stable
  JSON formatter

Document the regeneration command in the test header.

### 5.2 Consolidate `RecordingLogger` / `TestLogger` test doubles

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

## Step 6: `qmx debug:layer-assignment` CLI command

**Problem.** `architecture.potential-shadow` provides aggregate insight (per layer pair, with a sample). Users
sometimes need per-class introspection: "did THIS specific class end up where I expected, and where else would it
have gone?".

**Goal.** Per-class introspection of layer assignment. Reuses `LayerRegistry::resolveAll()` from Step 0 — no new
matching primitives needed.

**Files:**

- NEW `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php`
- NEW `tests/Functional/Console/LayerAssignmentCommandTest.php`
- MODIFY `website/docs/rules/architecture.md` (EN + RU) — link to the command from the diagnostic descriptions

**Usage:**

```bash
qmx debug:layer-assignment 'App\Service\Foo' --config qmx.yaml
```

Output (assigned, no shadowing):

```
Class: App\Service\Foo

  Assigned to: service
    Matching pattern: App\Service\**

  Would also match (in declaration order):
    (none — the assignment is unique)
```

Output (shadowed):

```
Class: App\Service\Foo

  Assigned to: any-foo
    Matching pattern: App\**\Foo

  Would also match (in declaration order):
    - service       (pattern: 'App\Service\**')

  Diagnostic hint:
    Class is shadowed: would have matched 'service' if 'any-foo' was declared later. See
    architecture.potential-shadow diagnostic for the broader picture.
```

**Contract:**

- Command class extends Symfony console; reuses existing config loading + `LayerRegistry` building
- Calls `LayerRegistry::resolveAll(SymbolPath::forClass($fqn))` — first entry is the assignment, the rest are
  "would also match"
- Class supplied as FQN positional argument; optional `--config` to override config file path
- **Exit codes:** `0` for valid input (informational result, including "unclassified");
  `Command::INVALID` (2) for invalid input (empty FQN, whitespace, malformed); `Command::FAILURE` (1) for
  config-load failures

**Test cases:**

- Class matches one layer only → reports single assignment, "no other matches"
- Class matches multiple layers → reports assignment + ordered list of also-matched layers + shadowing hint
- Class matches no layer → reports "unclassified" + suggests adding a catch-all `**` layer; exit 0
- Invalid FQN (empty, whitespace, contains spaces) → exit `Command::INVALID` with clear message
- FQN with leading `\` (e.g. `\App\Service\Foo`) — normalised to no-leading-`\` form before lookup
- Config load failure → exit `Command::FAILURE`

**DoD:**

- [ ] `composer check` green
- [ ] Command discoverable via `bin/qmx list`
- [ ] EN help text present; documented in `website/docs/rules/architecture.md`

**Dependencies:** Step 0 (uses `LayerRegistry::resolveAll`). Independent of Steps 1–5; can run anytime after Step 0.

---

## Cross-cutting

### Validation strategy

After each step, run `composer check` AND `bin/qmx check src/ --memory-limit=512M` (self-analysis must remain clean of
new violations).

### Review trigger

Per CLAUDE.md, this is **Tier 1** (revises locked design decision, breaks user-facing schema).

- **Plan-stage triple review (Claude + Gemini + Codex) BEFORE any code is written** — two rounds completed; findings
  folded in.
- **Implementation triple review** after Step 0 lands (new domain seam = matching algorithm pivot + two new
  diagnostics).
- **Standard review** for Steps 1, 3, 6 individually.
- **No review** for Steps 2, 4, 5 (mechanical / docs).

### Sequencing

```
[Plan triple review — 2 rounds done]
        |
        v
   [ Step 0 ]
        |
        v
   [ Implementation triple review of Step 0 ]
        |
   +----+----+----+
   v         v    v
[Step 1]  [Step 2] [Step 6 — debug CLI]    [Step 4 docs — anytime]
   |
   v
[Step 3 — factory refactor]
   |
   v
[Step 5 — cosmetics]
```

### Backward compatibility

**Explicitly waived.** Only the project itself uses the architecture rules today (dogfooding). The YAML schema change in
Step 0 will be communicated through CHANGELOG `Breaking` and the ADR; external users (if any appear before the change
ships) will see a clear migration note covering both the layer list shape and the merge semantics.

### Definition of Done (whole plan)

- [ ] Plan triple review (2 rounds) addressed before Step 0
- [ ] All 6 steps complete
- [ ] `composer check` green
- [ ] `bin/qmx check src/` clean
- [ ] Website rebuilds with `mkdocs build --strict`
- [ ] CHANGELOG `Breaking` entry for the YAML pivot + `Changed` entries for `unreachable-layer`,
      `potential-shadow`, and the `debug:layer-assignment` command
- [ ] ADR 0006 supersedes ADR 0005, documenting the pivot and both replacement diagnostics
- [ ] Implementation triple review of Step 0 findings addressed
- [ ] Triple review of Step 3 (if any new abstractions surface)
- [ ] Standard review of Step 6
