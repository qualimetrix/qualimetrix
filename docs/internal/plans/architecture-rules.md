# Architecture Rules — Implementation Plan

**Status:** Ready for execution
**Author:** Design session 2026-05-12 (triple-reviewed by Claude + Gemini + Codex)
**Target:** Replace deptrac for Qualimetrix users; complete the "five tools in one" narrative
**Tier:** 1 (Strategic, high value)

---

## Context for executors

Qualimetrix is a CLI static analyzer for PHP. The dependency graph (`src/Analysis/Collection/Dependency/DependencyGraph.php`) is already built during the Collection phase and exposed through `AnalysisContext::$dependencyGraph`. Each `Dependency` value object holds `source`, `target` (both `SymbolPath`), `type` (`DependencyType`), and `location` (`Location` with file+line). The matching utility `NamespaceMatcher` (`src/Core/Util/NamespaceMatcher.php`) already supports both prefix-mode and glob-mode (auto-detected).

This feature adds a single rule `architecture.layer-violation` that enforces a user-declared layered dependency policy.

**Read before starting any step:**
- `CLAUDE.md` (root) — project conventions, Symfony DI auto-registration, dependency direction
- `src/Core/Dependency/Dependency.php` — edge VO
- `src/Core/Symbol/SymbolPath.php` — symbol identification
- `src/Core/Util/NamespaceMatcher.php` — pattern matching
- `src/Configuration/ConfigSchema.php` — config key registration
- `src/Configuration/Pipeline/ResolvedConfiguration.php` — final config shape
- `src/Configuration/Pipeline/ConfigurationPipeline.php` — `buildResolved()` is the post-merge hook
- `src/Rules/AbstractRule.php` and `src/Rules/Architecture/CircularDependencyRule.php` — rule pattern
- `src/Baseline/ViolationHasher.php` — violation identity for baseline

---

## Locked design decisions (do not re-litigate)

| Decision                  | Choice                                                                                  |
| ------------------------- | --------------------------------------------------------------------------------------- |
| Layer membership          | Namespace-based only (no class-name suffix, no interface, no attribute)                 |
| Default policy semantics  | Allow-list (everything not in allow → violation)                                        |
| Layer abstraction         | Named layers + allow lists                                                              |
| Vendor namespaces         | First-class layers (`doctrine: 'Doctrine\**'`)                                          |
| Reporting granularity     | Per use-site (one violation per offending dependency edge)                              |
| Multi-layer membership    | Single layer per class; longest-specificity tie-break; equal specificity → config error |
| Same-layer dependencies   | Always allowed (MVP). Sub-module isolation deferred to Phase 2                          |
| Out-of-layer ends         | Edge silently ignored (incremental adoption). Opt-in `coverage` diagnostics             |
| Default-enable model      | Rule `enabled=true` by default; `analyze()` short-circuits on empty `layers`            |
| Baseline                  | Extend `ViolationHasher` to include target+depType for dependency-based rules           |
| Dependency type filtering | Out of scope for MVP; YAML structure must allow non-breaking addition                   |
| Layer primitives location | `src/Core/Architecture/Layer/` (reusable by Metrics and Reporting later)                |

---

## Final file layout

```
src/Core/Architecture/                         # NEW DOMAIN
  Layer/
    LayerDefinition.php                        # VO: name + patterns + specificity
    LayerRegistry.php                          # All layers + class → layer resolution
    LayerPolicy.php                            # Allow-list lookup
    LayerCollisionException.php                # Thrown by registry on ambiguous match
    InvalidLayerDefinitionException.php        # Thrown on construction-time validation

src/Configuration/Architecture/                # NEW
  ArchitectureConfiguration.php                # Typed config holder (registry + policy + coverage)
  CoverageMode.php                             # Enum: Ignore | Warn | Error
  ArchitectureConfigurationFactory.php         # fromArray() — validation + construction

src/Configuration/ConfigSchema.php             # MODIFY: add ARCHITECTURE constant + ENTRIES row
src/Configuration/Pipeline/ResolvedConfiguration.php  # MODIFY: add architecture field
src/Configuration/Pipeline/ConfigurationPipeline.php  # MODIFY: buildResolved() constructs ArchitectureConfiguration

src/Rules/Architecture/                        # EXISTING (extend)
  LayerViolationRule.php                       # NEW
  LayerViolationOptions.php                    # NEW

src/Baseline/ViolationHasher.php               # MODIFY: optional dep-payload in hash
src/Core/Violation/Violation.php               # MODIFY: add nullable dependencyTarget + dependencyType fields

src/Core/Rule/AnalysisContext.php              # MODIFY: optional architecture field OR pass via ruleOptions
src/Infrastructure/Console/Command/CheckCommand.php  # MODIFY: pass architecture config through

tests/Unit/Core/Architecture/Layer/*           # NEW
tests/Unit/Configuration/Architecture/*        # NEW
tests/Unit/Rules/Architecture/LayerViolationRuleTest.php  # NEW
tests/Integration/Architecture/*               # NEW

website/docs/rules/architecture/layer-violation.md       # NEW (EN)
website/docs/rules/architecture/layer-violation.ru.md    # NEW (RU)
website/docs/reference/default-thresholds.md             # MODIFY
docs/adr/NNN-architecture-rules.md                       # NEW
CHANGELOG.md                                             # MODIFY
qmx.yaml                                                 # MODIFY (dogfooding)
```

---

## Step 1: Core layer primitives

**Goal:** Provide reusable, framework-agnostic primitives for layer membership and policy evaluation in `src/Core/Architecture/`.

**Files:**
- NEW `src/Core/Architecture/Layer/LayerDefinition.php`
- NEW `src/Core/Architecture/Layer/LayerRegistry.php`
- NEW `src/Core/Architecture/Layer/LayerPolicy.php`
- NEW `src/Core/Architecture/Layer/LayerCollisionException.php`
- NEW `src/Core/Architecture/Layer/InvalidLayerDefinitionException.php`
- NEW `tests/Unit/Core/Architecture/Layer/LayerDefinitionTest.php`
- NEW `tests/Unit/Core/Architecture/Layer/LayerRegistryTest.php`
- NEW `tests/Unit/Core/Architecture/Layer/LayerPolicyTest.php`

**Contracts:**

`LayerDefinition` (`final readonly`):
- `__construct(string $name, list<string> $patterns)` — validates non-empty name (regex `[a-z][a-z0-9_-]*`), non-empty `$patterns`, all patterns non-empty strings. Throws `InvalidLayerDefinitionException`.
- `match(string $fqn): ?int` — returns specificity (positive int) if at least one pattern matches the FQN; null otherwise. Specificity = length in chars of the literal prefix before the first wildcard char (`*`, `?`, `[`) in the matched pattern. For pure-prefix patterns (no wildcards): full pattern length. If multiple patterns of the same layer match, return the maximum specificity among them.
- `name(): string`
- `patterns(): list<string>` — original patterns, for diagnostics

Implementation note for executor: re-use `NamespaceMatcher` for the boolean match check, but compute specificity separately. Cache compiled state in the constructor.

`LayerRegistry` (`final`, but with mutable resolution cache):
- `__construct(list<LayerDefinition> $layers)` — validates unique names. Throws `InvalidArgumentException` on duplicate names.
- `resolveLayer(SymbolPath $class): ?string` — returns the layer name owning this class, or null if no layer matches. On ambiguous match (two layers tie on specificity), throws `LayerCollisionException` carrying both layer names and patterns. Result is cached per `SymbolPath::toCanonical()`.
- `layerNames(): list<string>` — sorted, for validation by `LayerPolicy` and config validator
- `isEmpty(): bool`
- `definitions(): list<LayerDefinition>` — for diagnostics

FQN construction: combine `SymbolPath::$namespace` and `SymbolPath::$type` with backslash separator. If both empty (file-level symbol), no layer.

`LayerPolicy` (`final readonly`):
- `__construct(array<string, list<string>> $allowedTargets)` — map `sourceLayer → list of allowed target layers`. No validation here (validation lives in factory).
- `isAllowed(string $from, string $to): bool` — true if `$from === $to` OR `$to` is in `$allowedTargets[$from]`. If `$from` is unknown to the policy (no entry), returns false (strict).
- `allowedTargets(string $from): list<string>` — for recommendations. Empty list if `$from` unknown.
- `knownLayers(): list<string>` — union of all keys and values, sorted; used for cross-validation against `LayerRegistry::layerNames()`.

`LayerCollisionException extends RuntimeException`:
- `__construct(string $fqn, list<array{string, string}> $matches)` — `$matches` is list of `[layerName, pattern]`. Message must mention all candidates.
- `getFqn(): string`
- `getMatches(): list<array{string, string}>`

`InvalidLayerDefinitionException extends InvalidArgumentException`:
- Standard exception, used by `LayerDefinition::__construct`.

**Test cases:**

LayerDefinition:
- `App\Service` matches `App\Service`, `App\Service\Foo`, `App\Service\Deep\Sub` — specificity 11
- `App\Service` does NOT match `App\ServiceManager\Foo` — boundary
- `App\**\Repository` matches `App\X\Repository`, specificity 4 (`App\`)
- `App\Service\**` matches `App\Service\Foo`, specificity 12 (`App\Service\`)
- Pure literal `App\Foo` matches `App\Foo` and `App\Foo\Bar`, specificity 7
- Multi-pattern layer: maximum specificity returned
- Empty name → `InvalidLayerDefinitionException`
- Invalid name (uppercase, special chars) → exception
- Empty pattern list → exception
- Empty string pattern → exception

LayerRegistry:
- Single layer, single match → returns name
- Single layer, no match → returns null
- Two layers, longest specificity wins
- Two layers, equal specificity, both match → `LayerCollisionException` with both names+patterns
- Duplicate layer names in constructor → `InvalidArgumentException`
- Cache: `resolveLayer` called twice with same `SymbolPath` returns same result; underlying `LayerDefinition::match` invoked only once (verify with mock or call counter)
- `isEmpty()` returns true for empty list, false otherwise
- `layerNames()` returns sorted list of unique names

LayerPolicy:
- Same layer always allowed (even if not in map)
- Target in allow list → true
- Target not in allow list → false
- Unknown source layer → false
- `allowedTargets('unknown')` → empty list
- `knownLayers()` returns sorted union of keys and target values

**DoD:**
- [ ] `composer phpstan` passes (level 8)
- [ ] `composer test` passes
- [ ] `composer deptrac` passes (Core has no upward dependencies)
- [ ] PHP-CS-Fixer clean
- [ ] 100% line coverage on the 3 primitive classes (test coverage report via `composer test:coverage` if configured)
- [ ] No imports from `Rules`, `Metrics`, `Configuration`, `Analysis`, `Infrastructure`

**Dependencies:** none. Can start first.

**Subagent isolation:** Single agent. All files in new directory `src/Core/Architecture/Layer/` plus their tests. No overlap with other steps.

---

## Step 2: Configuration plumbing

**Goal:** Wire the `architecture:` YAML section through the configuration pipeline into a typed `ArchitectureConfiguration` on `ResolvedConfiguration`.

**Files:**
- NEW `src/Configuration/Architecture/ArchitectureConfiguration.php`
- NEW `src/Configuration/Architecture/CoverageMode.php`
- NEW `src/Configuration/Architecture/ArchitectureConfigurationFactory.php`
- MODIFY `src/Configuration/ConfigSchema.php` — add `ARCHITECTURE` constant + ENTRIES entry
- MODIFY `src/Configuration/Pipeline/ResolvedConfiguration.php` — add `architecture: ArchitectureConfiguration` field
- MODIFY `src/Configuration/Pipeline/ConfigurationPipeline.php` — `buildResolved()` calls factory with `$merged['architecture'] ?? []`
- NEW `tests/Unit/Configuration/Architecture/ArchitectureConfigurationFactoryTest.php`
- NEW `tests/Unit/Configuration/Pipeline/ResolvedConfigurationTest.php` (extend if exists)
- MODIFY existing pipeline tests if assertion of full `ResolvedConfiguration` is performed anywhere

**Contracts:**

`ArchitectureConfiguration` (`final readonly`):
- `__construct(LayerRegistry $registry, LayerPolicy $policy, CoverageMode $coverage)`
- `registry(): LayerRegistry`
- `policy(): LayerPolicy`
- `coverage(): CoverageMode`
- `isEmpty(): bool` — true if registry is empty

`CoverageMode` (enum: `string`):
- Cases: `Ignore = 'ignore'`, `Warn = 'warn'`, `Error = 'error'`
- Default: `Ignore`
- `static fromString(string $value): self` — case-insensitive, throws on unknown

`ArchitectureConfigurationFactory` (`final`):
- `fromArray(array $raw): ArchitectureConfiguration` — accepts the value of the `architecture` key from merged config. Validates and constructs. On invalid input throws `ConfigurationException` with a YAML-path hint (e.g., `architecture.allow.controller[0]: unknown layer "servise"`).
- Empty `$raw` (no `architecture` key) → returns a configuration with empty registry, empty policy, `CoverageMode::Ignore`.

**Validation rules in factory:**

1. `architecture.layers` must be a map; each value is a string or list of strings (patterns). Numeric keys → error. Pattern must be a non-empty string. Build a `LayerDefinition` per entry.
2. `architecture.allow` must be a map; keys must be names from `architecture.layers`. Unknown source layer name → error. Values: list of strings (long form supported but ignored for MVP — see below).
3. **Long-form support for future `types` filter:** if a value in `allow.X` is an associative array `{target: 'foo', types: [...]}`, normalize to short form `'foo'` (silently for MVP; emit a deprecation-style warning if `types` is present, since not yet wired). This protects API for Phase 2.
4. Each target in `allow.X` lists must be a known layer name.
5. `architecture.coverage` is optional. Must be one of `ignore`, `warn`, `error`. Default `ignore`.
6. **Mutual-allow detection:** after policy is built, scan for `A → B` and `B → A` pairs. Emit a PSR-3 warning (factory takes optional `LoggerInterface`; default `NullLogger`) listing the pair(s). Do NOT throw.

**ConfigSchema changes:**
- `public const string ARCHITECTURE = 'architecture';`
- Add to `ENTRIES`: `[self::ARCHITECTURE, self::ARCHITECTURE, self::MIXED]`
- Verify existing tests that enumerate `ENTRIES` length / `allowedRootKeys` still pass after addition

**Pipeline changes:**
- `buildResolved()` constructs `ArchitectureConfiguration` via factory. Pass a `LoggerInterface` from the pipeline if available (check existing pipeline DI; otherwise inject through stages or use `NullLogger` for now).
- `ResolvedConfiguration::$architecture` field is required (not nullable) — defaults to empty configuration when no YAML key.

**Test cases:**

Factory happy path:
- Empty `$raw` → empty configuration (`isEmpty()` true)
- Single layer, single pattern (string form) → registry has 1 layer
- Single layer, multi-pattern (list form) → registry has 1 layer with N patterns
- Two layers + allow → policy reflects allow correctly
- `coverage: warn` → `CoverageMode::Warn`

Factory validation:
- `layers` not a map → `ConfigurationException` mentioning `architecture.layers`
- Pattern empty string → exception
- `allow` keys not in layers → exception
- `allow` target not in layers → exception
- `coverage` unknown value → exception
- `coverage` wrong type (not string) → exception

Mutual-allow warning:
- `A: [B]`, `B: [A]` → spy/mock `LoggerInterface` receives one warning with both pairs
- `A: [A]` (self-allow — accidental redundancy) → warn or silently dedup? **Decision: silently ignore self-references in allow list, since same-layer is always allowed.**

ConfigSchema:
- `allowedRootKeys()` now includes `'architecture'`
- `ENTRIES` count increased by 1 (or whatever sentinel test exists)

Pipeline integration:
- `ResolvedConfiguration::$architecture` is populated from merged config
- Absent `architecture:` in any layer (defaults + cli) → empty configuration on resolved

**DoD:**
- [ ] All step-1 tests still pass
- [ ] New tests pass
- [ ] `composer check` green
- [ ] No `Configuration → Rules` cross-import; `Configuration` may import from `Core/Architecture/Layer` (allowed by dependency rules)
- [ ] Existing config tests for unrelated features unchanged

**Dependencies:** Step 1 (uses `LayerDefinition`, `LayerRegistry`, `LayerPolicy`).

**Subagent isolation:** Files in `src/Configuration/Architecture/` are new. Modified files are localized — risk of merge conflicts only with active step-1 work (which is already complete). One agent.

---

## Step 3: Violation hasher extension for dependency-based rules

**Goal:** Allow per-use-site baseline tracking for dependency rules without breaking existing baselines.

**Files:**
- MODIFY `src/Core/Violation/Violation.php` — add `dependencyTarget: ?SymbolPath`, `dependencyType: ?DependencyType` fields (nullable, default null)
- MODIFY `src/Baseline/ViolationHasher.php` — include both fields in hash payload when non-null
- NEW `tests/Unit/Baseline/ViolationHasherTest.php` (extend if exists)
- Regression: existing baseline-related tests must continue passing unchanged (no rehash for non-dep violations)

**Contracts:**

`Violation` field additions (back of constructor, nullable, defaulted):
- `?SymbolPath $dependencyTarget = null`
- `?DependencyType $dependencyType = null`
- Existing callers unchanged; new fields opt-in.

`ViolationHasher::hash()` algorithm:
- Existing payload: `rule + namespace + type + member + violationCode`
- New payload when `dependencyTarget !== null`: append `target.toCanonical() + dependencyType?->value`
- When both new fields are null: hash identical to existing (full backward compatibility — verify with a test that hashes existing fixtures and compares to known checksums)

**Test cases:**

Backward compat:
- All existing test cases (CCN violation, LCOM, etc.) produce the same hash as before
- Add an explicit "regression baseline" test: hash a fixed Violation with specific values, assert exact hash string. This pins behavior.

New behavior:
- Two violations from same source class, same target class, different lines → SAME hash (per-use-site dedup at baseline level only kicks in if user wants it — line is intentionally still excluded)
- Two violations from same source class, DIFFERENT target classes → DIFFERENT hashes
- Two violations same source same target but different `DependencyType` → DIFFERENT hashes
- Wait — what about the same source → target via multiple use-sites? Per the design: each use-site is a separate Violation, but baseline must dedupe them because file-line drift would invalidate. So at baseline level, source-target-type triple is the identity, and N use-sites collapse to 1 baseline entry. This is correct.

**DoD:**
- [ ] All previously-existing tests pass without modification
- [ ] Hash regression test pins existing behavior
- [ ] `composer check` green

**Dependencies:** None (independent of steps 1-2). **Can be parallelized with step 1.**

**Subagent isolation:** Touches `src/Core/Violation/Violation.php` and `src/Baseline/ViolationHasher.php`. These files are otherwise untouched in other steps.

---

## Step 4: LayerViolationRule + LayerViolationOptions

**Goal:** Implement the rule that consumes `DependencyGraph` and `ArchitectureConfiguration` to produce violations.

**Files:**
- NEW `src/Rules/Architecture/LayerViolationRule.php`
- NEW `src/Rules/Architecture/LayerViolationOptions.php`
- MODIFY `src/Core/Rule/AnalysisContext.php` — add `?ArchitectureConfiguration $architecture = null`
- MODIFY whichever code constructs `AnalysisContext` (likely `src/Infrastructure/Console/Command/CheckCommand.php` or a pipeline assembler) — pass `$resolved->architecture`
- NEW `tests/Unit/Rules/Architecture/LayerViolationRuleTest.php`
- NEW `tests/Unit/Rules/Architecture/LayerViolationOptionsTest.php`

**Contracts:**

`LayerViolationOptions` (`final`):
- Extends `AbstractRuleOptions` or implements `RuleOptionsInterface` (mirror `CircularDependencyOptions`)
- Fields: `enabled: bool = true`, `severity: Severity = Severity::Warning`
- `static getDefaults(): array` — `['enabled' => true, 'severity' => 'warning']`
- `static fromArray(array $data): self`
- Does NOT carry layer/policy data — those come via `AnalysisContext::$architecture`. This keeps the options DTO simple and avoids duplicating data across rule options and resolved configuration.

`LayerViolationRule` (`final extends AbstractRule`):
- `const string NAME = 'architecture.layer-violation'`
- `getName(): string` → `self::NAME`
- `getDescription(): string` → `'Detects dependencies between layers that are not explicitly allowed by the architecture policy.'`
- `getCategory(): RuleCategory::Architecture`
- `requires(): []` (no metric dependencies)
- `static getOptionsClass(): string` → `LayerViolationOptions::class`
- `static getCliAliases(): array` → `['layer-violation' => 'enabled']` (or similar; mirror existing rule's pattern)

**`analyze(AnalysisContext $context): array` algorithm:**

1. If `!$this->options->isEnabled()` → return `[]`
2. If `$context->architecture === null` OR `$context->architecture->isEmpty()` → return `[]`
3. If `$context->dependencyGraph === null` → return `[]`
4. Pull `$registry` and `$policy` from `$context->architecture`
5. Iterate `$context->dependencyGraph->getAllDependencies()`:
   a. `$from = $registry->resolveLayer($dep->source)` (catch `LayerCollisionException` → swallow and warn via context if available, or skip — TBD with subagent; safest: skip + add to coverage diagnostics)
   b. `$to = $registry->resolveLayer($dep->target)`
   c. If `$from === null || $to === null` → continue (handled by coverage diagnostics in step 5)
   d. If `$policy->isAllowed($from, $to)` → continue
   e. Build `Violation`:
      - `location: $dep->location`
      - `symbolPath: $dep->source`
      - `ruleName: self::NAME`
      - `violationCode: self::NAME`
      - `message: sprintf('Layer "%s" must not depend on layer "%s" via %s (%s → %s)', $from, $to, $dep->type->description(), $dep->source->toString(), $dep->target->toString())`
      - `severity: $this->options->severity` (or use `getEffectiveSeverity`)
      - `dependencyTarget: $dep->target`
      - `dependencyType: $dep->type`
      - `recommendation: <see below>`
6. Return all violations

**Recommendation format** (two lines):
```
Allowed targets for layer "controller": service, domain. Consider routing through one of them.
Dep data: {"fromLayer":"controller","toLayer":"repository","source":"App\\Controller\\Foo","target":"App\\Repository\\Bar","type":"method_call"}
```

If `allowedTargets($from)` is empty: replace first line with `Layer "controller" is not allowed to depend on any other declared layer.`

**Severity handling:**
- For MVP, single severity per rule via options. `getEffectiveSeverity()` from `AbstractRule` may not apply directly (it's threshold-based). Use `$this->options->severity` directly, or override to bypass threshold logic if `AbstractRule::getEffectiveSeverity` requires it.
- Verify by reading `AbstractRule` how `CircularDependencyRule` handles severity (it uses cycle size as threshold) and adapt.

**Test cases:**

LayerViolationOptions:
- Defaults: `enabled=true, severity=warning`
- `fromArray(['enabled' => false])` produces disabled options
- `fromArray(['severity' => 'error'])` produces error severity
- Invalid severity → exception (delegated to base options class)

LayerViolationRule (unit, with mocks):
- Disabled rule → empty
- Empty `ArchitectureConfiguration` → empty
- Null `dependencyGraph` → empty
- Single allowed edge → no violations
- Single forbidden edge → one violation with correct location, source, target, layers in message
- Multiple use-sites of same source→target → multiple violations (one per `Dependency`)
- Edge with `null` source layer → no violation (out-of-layer ignored)
- Edge with `null` target layer → no violation
- Same-layer dependency → no violation
- `LayerCollisionException` during resolve → edge skipped, no exception bubbles out of `analyze()`
- Recommendation contains JSON structured data

**DoD:**
- [ ] `composer check` green
- [ ] Rule auto-registers via DI (verify by running `bin/qmx check --help` shows the new rule, or by container compilation test)
- [ ] All existing rule tests unchanged
- [ ] `getEffectiveSeverity` integration matches project convention

**Dependencies:** Steps 1, 2, 3 (uses primitives, configuration, extended Violation).

**Subagent isolation:** Files in `src/Rules/Architecture/` (new files only). Modifies `AnalysisContext` and one caller of it — coordinate with whoever assembles `AnalysisContext` in `Infrastructure`.

---

## Step 5: Coverage diagnostics

**Goal:** Surface "edges with at least one unmatched end" as opt-in diagnostics for users adopting architecture rules incrementally.

**Files:**
- MODIFY `src/Rules/Architecture/LayerViolationRule.php` — count unmatched edges during iteration
- MODIFY `src/Configuration/Architecture/CoverageMode.php` (no change to enum — already added in step 2)
- MODIFY `src/Rules/Architecture/LayerViolationRule.php` (or new helper) — emit diagnostics based on `coverage` setting
- NEW `tests/Unit/Rules/Architecture/CoverageDiagnosticsTest.php`

**Behavior:**

- `coverage: ignore` (default) → silent. Counts not produced.
- `coverage: warn` → after iteration, log via PSR-3 logger (already in `AnalysisContext`? if not, use a context property) one summary message: `Architecture coverage: X edges have unmatched source layer, Y edges have unmatched target layer, Z classes outside all layers.`
- `coverage: error` → in addition to logging, produce a `Violation` with `ruleName: 'architecture.coverage'`, `severity: Error`, `location: Location::none()`, listing example unmatched classes (top 10 alphabetical).

**Note:** if `AnalysisContext` doesn't carry a logger today, executor should choose between:
1. Adding `?LoggerInterface $logger` to `AnalysisContext`
2. Producing diagnostics as additional Violations (with `'architecture.coverage'` ruleName and Severity::Info)

**Recommendation: option 2** — keeps diagnostics in the same channel as other violations (visible in formatters, baseline-able, suppressable). Cleaner than wiring a logger through every rule.

**Test cases:**

- `coverage: ignore` → no diagnostic violations regardless of unmatched edges
- `coverage: warn` → for project with N unmatched edges, exactly 1 diagnostic Violation with `Severity::Info` and `ruleName: 'architecture.coverage'`
- `coverage: error` → diagnostic Violation with `Severity::Error`
- Diagnostic message names example unmatched classes (max 10)
- Diagnostic only emitted if at least one unmatched edge exists

**DoD:**
- [ ] `composer check` green
- [ ] Existing rule tests still pass (no diagnostic in default mode)

**Dependencies:** Step 4.

**Subagent isolation:** Single-file modification + new test. One agent.

---

## Step 6: Integration test with synthetic fixture

**Goal:** End-to-end verification of the full pipeline: YAML → resolution → analysis → JSON output.

**Files:**
- NEW `tests/Fixtures/Architecture/SampleProject/` — minimal PHP project structure
  - `src/Controller/UserController.php` (depends on UserService, plus illegally on UserRepository)
  - `src/Service/UserService.php` (depends on UserRepository, allowed)
  - `src/Repository/UserRepository.php` (depends on a Doctrine class)
  - `src/Domain/User.php` (no dependencies)
- NEW `tests/Fixtures/Architecture/SampleProject/qmx.yaml` — declares layers + allow rules
- NEW `tests/Fixtures/Architecture/SampleProject/expected-violations.json` — golden file
- NEW `tests/Integration/Architecture/LayerViolationIntegrationTest.php`

**Test cases:**

1. Full pipeline: `qmx check tests/Fixtures/Architecture/SampleProject/src/ --config=.../qmx.yaml --format=json` produces JSON matching the golden file
2. The illegal Controller → Repository edge appears as exactly one violation
3. Allowed edges (Controller → Service, Service → Repository, Repository → Doctrine) produce no violations
4. Out-of-layer ends ignored (if fixture has any)
5. `--disable-rule=architecture.layer-violation` produces zero architecture violations
6. With `coverage: warn` in fixture config, diagnostic violation appears

Golden file regeneration: document the command to regenerate (`bin/qmx check ... > expected-violations.json`) so future intentional changes are easy to update.

**DoD:**
- [ ] Integration test passes
- [ ] Golden file committed
- [ ] Test runs in <2 seconds

**Dependencies:** Steps 1-5.

**Subagent isolation:** Single agent. New files only (no modifications to production code).

---

## Step 7: Documentation

**Goal:** User-facing documentation in EN+RU, ADR for design decisions, CHANGELOG.

**Files:**
- NEW `website/docs/rules/architecture/layer-violation.md`
- NEW `website/docs/rules/architecture/layer-violation.ru.md`
- MODIFY `website/docs/reference/default-thresholds.md` (EN + RU) — add row for new rule
- MODIFY `website/docs/rules/architecture/index.md` (EN + RU) — link to new rule
- NEW `docs/adr/NNN-architecture-rules.md` — design rationale: allow-list semantics, namespace-only matching, why we avoided full DSL, Phase 2 deferrals
- MODIFY `CHANGELOG.md` — add to `[Unreleased]` → `Changed`: "Architecture layer rules: declare layers in YAML and enforce allowed inter-layer dependencies (`architecture.layer-violation`)."
- MODIFY `src/Rules/README.md` — add new rule to table
- MODIFY `src/Core/README.md` and `src/Configuration/README.md` if file structure changed enough to warrant
- MODIFY `qmx.yaml` (dogfooding — see step 8)

**Documentation structure for `layer-violation.md` (mirror existing rule docs):**

1. Synopsis (one-line)
2. Configuration example (YAML)
3. Semantics: allow-list, namespace-based, specificity-based resolution
4. Coverage mode explanation
5. Implementation notes: only checks declared layers, vendor as first-class, per-use-site reporting
6. Suppression: `@qmx-ignore architecture.layer-violation` and baseline
7. Limitations: same-layer always allowed in MVP; no dependency-type filter yet
8. Reference: link to deptrac, ArchUnit for inspiration

**DoD:**
- [ ] `cd website && mkdocs build --strict` passes
- [ ] EN and RU pages exist and have matching structure
- [ ] CHANGELOG updated
- [ ] ADR captures Phase 2 deferred items

**Dependencies:** Steps 1-6 (must reflect actual implementation).

**Subagent isolation:** Website docs (.md files) and ADR are entirely new files or isolated edits. CHANGELOG and project READMEs may need light edits — one agent handles all docs sequentially to avoid conflicts.

---

## Step 8: Dogfooding

**Goal:** Apply architecture rules to qualimetrix itself and resolve resulting violations.

**Files:**
- MODIFY `qmx.yaml` — add `architecture:` section
- Possibly minor code refactoring if real violations surface

**Procedure:**

1. Define layers reflecting actual project domain split:
   ```yaml
   architecture:
     layers:
       core:           'Qualimetrix\Core\**'
       configuration:  'Qualimetrix\Configuration\**'
       metrics:        'Qualimetrix\Metrics\**'
       rules:          'Qualimetrix\Rules\**'
       reporting:      'Qualimetrix\Reporting\**'
       baseline:       'Qualimetrix\Baseline\**'
       analysis:       'Qualimetrix\Analysis\**'
       infrastructure: 'Qualimetrix\Infrastructure\**'
     allow:
       core:           []
       configuration:  [core]
       metrics:        [core]
       rules:          [core]
       reporting:      [core]
       baseline:       [core]
       analysis:       [core, metrics, rules, reporting, configuration, baseline]
       infrastructure: [core, configuration, analysis, reporting, baseline]
   ```
2. Run `bin/qmx check src/` and capture violations
3. For each violation:
   - Real issue → fix code
   - Threshold mismatch → tune
   - Legitimate exception → `@qmx-threshold` or `@qmx-ignore` with reason
   - Structural → adjust YAML (split layer, add allow)
4. Iterate until clean
5. Document any non-obvious threshold/ignore choices in the new `qmx.yaml` block

**DoD:**
- [ ] `bin/qmx check src/` reports zero architecture violations
- [ ] No baseline used (per dogfooding policy)
- [ ] Any inline tags include reasons
- [ ] `composer check` green

**Dependencies:** Steps 1-7.

**Subagent isolation:** Last step, no parallelization.

---

## Cross-cutting concerns

### Validation strategy

- Configuration errors surface at config-load time, not analyze-time. `ArchitectureConfigurationFactory::fromArray` is the only place that converts user input into typed objects.
- Use `ConfigurationException` from `src/Configuration/` (or whichever existing exception matches the pattern for other config errors — verify with existing stages).

### Multi-agent review trigger

Per `CLAUDE.md`: triple review required when new domain introduced OR 3+ contracts changed. This feature adds new `Core/Architecture/Layer` domain and new contracts, so **triple review (Claude + Gemini + Codex) is mandatory** after step 7, before step 8.

### Performance considerations

For a 10k-class / 100k-edge project:
- `LayerRegistry::resolveLayer` is the hot path. Cache keyed by `SymbolPath::toCanonical()` ensures O(L) per unique class (L = number of layers), then O(1) on repeat. Pre-warming via `getAllClasses()` and resolving upfront is **optional** but recommended if profiling shows hotspot.
- `LayerDefinition::match` compiles patterns once in constructor.
- Iterating `getAllDependencies()` is O(E) where E = edge count. No way around this.

### Backward compatibility

- `architecture:` is a new optional YAML key. Projects without it see zero behavior change.
- `ViolationHasher` extension preserves hash for non-dep violations (verified by regression test).
- New rule is enabled by default but short-circuits when empty `layers` — zero overhead for non-adopters.

### What we explicitly do NOT do (Phase 2 candidates)

| Feature                                                   | Reason                                                              |
| --------------------------------------------------------- | ------------------------------------------------------------------- |
| `types: [inheritance, method_call]` filter per allow rule | YAML structure ready; runtime ignores `types`; will wire in Phase 2 |
| `allow_same_layer: false`                                 | Submodule isolation rare in practice; defer                         |
| Class-name-suffix based layer membership                  | namespace-only is project convention; clarity over flexibility      |
| Interface-based layer membership                          | Niche; deptrac is the place for that                                |
| Layer-aware metrics                                       | Separate roadmap item                                               |
| Per-pair severity overrides                               | One severity per rule for MVP                                       |

---

## Subagent execution map (next session)

Recommended dispatch:

**Parallel wave 1** (no dependencies):
- Agent A: Step 1 (Core primitives + tests)
- Agent B: Step 3 (ViolationHasher extension + tests + regression hash pin)

Both touch disjoint files. Wait for both to complete before starting wave 2.

**Sequential after wave 1:**
- Step 2 (Configuration plumbing) — needs step 1
- Step 4 (Rule + Options + AnalysisContext) — needs steps 1, 2, 3
- Step 5 (Coverage diagnostics) — needs step 4
- Step 6 (Integration test) — needs steps 1-5

**Parallel wave 2** (after step 6):
- Agent C: Step 7 (Documentation, ADR, CHANGELOG, READMEs)
- Agent D: Run triple review (Claude + Gemini + Codex) on the implementation

Address any review findings before step 8.

**Final:**
- Step 8 (Dogfooding) — orchestrator runs in main thread (config tuning often needs human judgment).

### Per-step subagent prompt template

Each subagent should receive:
1. Path to this plan file (`docs/internal/plans/architecture-rules.md`)
2. Step number to execute
3. Reminder: read `CLAUDE.md` and any READMEs referenced in the step
4. Reminder: do NOT mutate files outside the step's declared file list
5. Reminder: run `composer check` before declaring done
6. Reminder: if any contract decision becomes ambiguous, document the assumption in the agent's result and choose a defensible default rather than blocking

---

## Final Definition of Done (whole feature)

- [ ] All 8 steps complete
- [ ] `composer check` green (tests + phpstan level 8 + deptrac)
- [ ] Triple review completed with all findings addressed
- [ ] Self-analysis (`bin/qmx check src/`) passes with architecture rules enabled
- [ ] Website builds with `mkdocs build --strict` (no warnings)
- [ ] CHANGELOG entry under `[Unreleased]` → `Changed`
- [ ] ADR committed in `docs/adr/`
- [ ] Demo command works end-to-end: `bin/qmx check tests/Fixtures/Architecture/SampleProject/src/`
- [ ] No regression in existing baseline-using projects (manual smoke test)
