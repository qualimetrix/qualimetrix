# Qualimetrix Dogfooding: Product Features + Self-Analysis Config

## Context

Qualimetrix (PHP static analysis tool) analyzes itself — 975 violations, of which ~70% are false positives suppressed by a 1048-entry baseline. The baseline masks both noise and real issues. Strategy: first improve the tool's own features to reduce false positives, then replace the baseline with proper configuration.

**Two iterations:**
1. **4 product features** — per-rule `exclude_paths`, boolean-argument options, instability leaf detection, error-suppression options
2. **Self-analysis config** — remove baseline, configure `qmx.yaml`, annotate code, update CLAUDE.md

### Review log

Plan reviewed by Claude, Codex, Gemini. Issues addressed:
- **C1 (isAllowedPrefix $ISLAND bug)**: Fixed algorithm to check `!ctype_upper($prev)` before allowing camelCase boundary
- **H1 (withOverride skipLeaf)**: Added to both Options classes DoD
- **H2 (reset pathExclusionProvider)**: Added `RuleOptionsRegistry::reset()` + `RuntimeConfigurator` to F1
- **H3 (DI wiring files)**: Specified `ConfigurationConfigurator.php` and `AnalysisConfigurator.php` explicitly
- **H4 (CI workflow baseline)**: Added `.github/workflows` update to Iteration 2
- **M1 (shouldIncludeEntry hook)**: Added `AbstractCodeSmellRule::shouldIncludeEntry()` hook for F4/F2
- **M2 (CHANGELOG behavior changes)**: Noted F2/F3 as `Changed` entries
- **M3 (F4 edge case tests)**: Added `@include`, `@require`, dynamic call tests
- **M4 (DI integration test)**: Added container smoke-test for F1

---

## Iteration 1: Product Features

### Implementation order: F3 → F1 → F4 → F2

Rationale:
- **F3** (instability leaf) — smallest scope, 3 files, quick confidence
- **F1** (per-rule exclude_paths) — infrastructure needed for Iteration 2
- **F4** (error-suppression) — touches shared `CodeSmellVisitor`, do before F2 to reduce merge risk
- **F2** (boolean-argument) — rule-only change, no shared code modified

---

### F3: Instability Namespace-Level Leaf Detection

**Problem:** Namespaces with Ca=0 (no dependents) get I=1.0 by formula — this is mathematically correct, not a design flaw. Class-level already skips Ca=0 (hardcoded). Namespace-level doesn't.

**Impact:** Eliminates majority of 162 instability false positives at namespace level.

#### Design

Add `skipLeaf: bool` (default: `true`) to both `ClassInstabilityOptions` and `NamespaceInstabilityOptions`.
- Class level: expose existing hardcoded behavior as configurable
- Namespace level: add new Ca=0 skip

**Product note:** Default `true` is a behavior change for namespace-level (fewer violations). This is noise reduction, not information loss. Users who want leaf namespace instability set `skip_leaf: false`.

#### Files

| Action | File                                                            | Change                                                    |
| ------ | --------------------------------------------------------------- | --------------------------------------------------------- |
| MODIFY | `src/Rules/Coupling/ClassInstabilityOptions.php`                | Add `skipLeaf` property, update `fromArray()`             |
| MODIFY | `src/Rules/Coupling/NamespaceInstabilityOptions.php`            | Add `skipLeaf` property, update `fromArray()`             |
| MODIFY | `src/Rules/Coupling/InstabilityRule.php`                        | Make class Ca=0 skip conditional; add namespace Ca=0 skip |
| MODIFY | `tests/Unit/Rules/Coupling/InstabilityRuleTest.php`             | Tests for skip_leaf=true/false at both levels             |
| CREATE | `tests/Unit/Rules/Coupling/NamespaceInstabilityOptionsTest.php` | fromArray() tests (if missing)                            |

#### Key changes

**`ClassInstabilityOptions`:**
```php
public function __construct(
    public bool $enabled = true,
    public float $maxWarning = 0.8,
    public float $maxError = 0.95,
    public bool $skipLeaf = true,  // NEW
) {}
```
In `fromArray()`: read `skip_leaf` / `skipLeaf`, default `true`.
In `withOverride()`: **must preserve `skipLeaf`** — pass `$this->skipLeaf` to the new instance.

**`NamespaceInstabilityOptions`** — same changes. In `withOverride()`: **must preserve both `skipLeaf` and `minClassCount`**.

**`InstabilityRule::analyzeClassLevel()`** (line 147-151):
```php
// Before (hardcoded):
if ($ca === 0) { continue; }

// After (configurable):
if ($ca === 0 && $classOptions->skipLeaf) { continue; }
```

**`InstabilityRule::analyzeNamespaceLevel()`** — add after getting instability value, BEFORE severity check:
```php
$caRaw = $metrics->get(MetricName::COUPLING_CA);
$ca = $caRaw !== null ? (int) $caRaw : 0;
if ($ca === 0 && $namespaceOptions->skipLeaf) {
    continue;
}
```
Note: Ca is already read later at line 222 for the message. Move the read earlier to enable the skip.

#### YAML
```yaml
rules:
  coupling.instability:
    class:
      skip_leaf: true    # default, now configurable (was hardcoded)
    namespace:
      skip_leaf: true    # NEW — skip Ca=0 namespaces
```

#### Tests
- Namespace with Ca=0 → skipped by default
- Namespace with Ca=0 and `skip_leaf: false` → NOT skipped, violation reported
- Class with Ca=0 and `skip_leaf: false` → NOT skipped (regression: previously always skipped)
- Existing tests unchanged (defaults preserve behavior)

#### Definition of Done
- [ ] Both Options classes have `skipLeaf` with default `true`
- [ ] Both Options classes preserve `skipLeaf` in `withOverride()` (review fix H1)
- [ ] Rule uses `skipLeaf` instead of hardcoded check (class level)
- [ ] Rule skips Ca=0 namespaces when `skipLeaf` is true (namespace level)
- [ ] All existing tests pass unchanged
- [ ] New tests cover both levels × both values
- [ ] Test: `@qmx-threshold` override doesn't reset `skipLeaf` to default
- [ ] `composer check` passes
- [ ] Website docs updated (EN + RU) for coupling rules page
- [ ] CHANGELOG entry: `Changed — coupling.instability: namespaces with no dependents (Ca=0) are now skipped by default (configurable via skip_leaf: false)`

---

### F1: Per-Rule `exclude_paths`

**Problem:** `exclude_paths` is global-only. Can't exclude `src/Metrics/*Visitor.php` from `coupling.cbo` while keeping CBO active for other code. Only workaround is `exclude_namespaces` (coarser granularity).

**Impact:** Universal infrastructure — enables targeted exclusion for any rule. Critical for Iteration 2.

#### Design

Follow the `exclude_namespaces` pattern exactly:
1. New `RulePathExclusionProvider` (stores `PathMatcher` per rule)
2. `RuleOptionsFactory` extracts `exclude_paths` from merged config
3. `RuleExecutor` filters violations by per-rule path

**Product notes:**
- Same naming as global `exclude_paths` — consistent, at different YAML nesting levels
- Per-rule, NOT per-level (hierarchical rules get one `exclude_paths` for all levels)
- Uses existing `PathMatcher` (fnmatch glob patterns) — proven, tested
- Violations without file (namespace-level, architectural) never filtered

#### Files

| Action | File                                                                                | Change                                                                                                          |
| ------ | ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| CREATE | `src/Configuration/RulePathExclusionProvider.php`                                   | New class, parallel to `RuleNamespaceExclusionProvider`                                                         |
| MODIFY | `src/Configuration/RuleOptionsRegistry.php`                                         | Add `pathExclusionProvider` property + getter; update `reset()` to call `$this->pathExclusionProvider->reset()` |
| MODIFY | `src/Configuration/RuleOptionsFactory.php`                                          | Extract `exclude_paths` from merged config                                                                      |
| MODIFY | `src/Analysis/RuleExecution/RuleExecutor.php`                                       | Add path filtering after namespace filtering                                                                    |
| MODIFY | `src/Infrastructure/DependencyInjection/Configurator/ConfigurationConfigurator.php` | Register `RulePathExclusionProvider` as shared service, pass to `RuleOptionsRegistry`                           |
| MODIFY | `src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php`      | Inject `RulePathExclusionProvider` into `RuleExecutor` constructor                                              |
| MODIFY | `src/Infrastructure/Console/RuntimeConfigurator.php`                                | Add `pathExclusionProvider->reset()` alongside namespace exclusion reset                                        |
| CREATE | `tests/Unit/Configuration/RulePathExclusionProviderTest.php`                        |                                                                                                                 |
| MODIFY | `tests/Unit/Configuration/RuleOptionsFactoryTest.php`                               |                                                                                                                 |
| MODIFY | `tests/Unit/Analysis/RuleExecution/RuleExecutorTest.php`                            |                                                                                                                 |
| ADD    | Integration/DI test                                                                 | Container smoke-test: verify single `RulePathExclusionProvider` instance shared across services                 |

#### Key changes

**`RulePathExclusionProvider`:**
```php
final class RulePathExclusionProvider
{
    /** @var array<string, PathMatcher> */
    private array $matchers = [];

    public function setExclusions(string $ruleName, array $patterns): void
    {
        if ($patterns === []) { return; }
        $this->matchers[$ruleName] = new PathMatcher($patterns);
    }

    public function isExcluded(string $ruleName, string $filePath): bool
    {
        if (!isset($this->matchers[$ruleName])) { return false; }
        return $this->matchers[$ruleName]->matches($filePath);
    }

    public function reset(): void { $this->matchers = []; }
}
```

**`RuleOptionsFactory::create()`** — add after `extractExcludeNamespaces()` (line 65):
```php
$this->extractExcludePaths($ruleName, $merged);
```

**`RuleOptionsFactory::extractExcludePaths()`** — same pattern as `extractExcludeNamespaces()`:
```php
private function extractExcludePaths(string $ruleName, array &$merged): void
{
    $raw = $merged['excludePaths'] ?? $merged['exclude_paths'] ?? null;
    unset($merged['excludePaths'], $merged['exclude_paths']);

    if (\is_string($raw)) {
        $patterns = [$raw];
    } elseif (\is_array($raw)) {
        $patterns = array_values($raw);
    } else {
        return;
    }

    if ($patterns !== []) {
        $this->registry->getPathExclusionProvider()->setExclusions($ruleName, $patterns);
    }
}
```

**`RuleExecutor::execute()`** — after namespace exclusion (line 58):
```php
// Filter violations from excluded paths (per-rule)
$ruleViolations = array_filter(
    $ruleViolations,
    fn(Violation $v) => $v->location->file === ''
        || !$this->pathExclusionProvider->isExcluded($ruleName, $v->location->file),
);
```

#### YAML
```yaml
rules:
  coupling.cbo:
    exclude_paths:
      - src/Metrics/*Visitor.php
      - src/Infrastructure/DependencyInjection/*
  complexity.cyclomatic:
    exclude_paths:
      - src/Metrics/Halstead/*    # large switch on operator types
```

#### Edge cases
- Path format: `PathMatcher` uses `fnmatch()` — matches both absolute and relative paths. Violations store paths as provided by file discovery (typically relative). Same format as global `exclude_paths`.
- Empty file in violation (`''`): never filtered — consistent with `PathExclusionFilter`
- Performance: O(violations × patterns) per rule. Acceptable (<20 patterns typical).

#### Definition of Done
- [ ] `RulePathExclusionProvider` created with tests
- [ ] `RuleOptionsFactory` extracts `exclude_paths` from config
- [ ] `RuleExecutor` filters violations by per-rule paths
- [ ] DI wiring: `ConfigurationConfigurator` + `AnalysisConfigurator` updated
- [ ] `RuleOptionsRegistry::reset()` clears path exclusions
- [ ] `RuntimeConfigurator` resets path exclusions between runs
- [ ] Integration: YAML `exclude_paths` under `rules.X` → violations suppressed
- [ ] Container smoke-test: single shared instance
- [ ] String value coerced to single-element array
- [ ] `composer check` passes
- [ ] Website docs updated — add `exclude_paths` to universal per-rule options section
- [ ] CHANGELOG entry added

---

### F4: Error-Suppression Configurable Options

**Problem:** `error-suppression` has only `enabled: true/false`. Legitimate `@` usage (I/O functions that return false + emit warning) triggers violations with no way to whitelist.

**Impact:** 14 violations in our project; universally useful for any PHP project using `@fopen`, `@unlink`, etc.

#### Design

1. **Collector change:** Capture function name in `extra` for `ErrorSuppress` nodes
2. **Base class hook:** Add `shouldIncludeEntry(array $entry): bool` to `AbstractCodeSmellRule` (default: `true`). This avoids duplicating the entire `analyze()` method in subclasses. F2 will reuse the same hook.
3. **New options class:** `ErrorSuppressionOptions` with `allowedFunctions` list
4. **Rule change:** Override `shouldIncludeEntry()` to filter by allowed functions

**Product note:** `allowedFunctions` default is `[]` — backwards compatible. No existing behavior changes.

#### Files

| Action | File                                                         | Change                                                                                    |
| ------ | ------------------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| CREATE | `src/Rules/CodeSmell/ErrorSuppressionOptions.php`            | New options with `allowedFunctions`                                                       |
| MODIFY | `src/Rules/CodeSmell/AbstractCodeSmellRule.php`              | Add `shouldIncludeEntry(array $entry): bool` hook (default: `true`) called in `analyze()` |
| MODIFY | `src/Metrics/CodeSmell/CodeSmellVisitor.php`                 | Capture function name in `extra` for `error_suppression`                                  |
| MODIFY | `src/Rules/CodeSmell/ErrorSuppressionRule.php`               | Use new options, override `shouldIncludeEntry()`, improve `buildMessage()`                |
| CREATE | `tests/Unit/Rules/CodeSmell/ErrorSuppressionOptionsTest.php` |                                                                                           |
| MODIFY | `tests/Unit/Metrics/CodeSmell/CodeSmellVisitor*Test*.php`    | Test function name capture                                                                |
| MODIFY | `tests/Unit/Rules/CodeSmell/ErrorSuppressionRuleTest.php`    | Test allowed_functions filtering                                                          |

#### Key changes

**`CodeSmellVisitor`** — line 122-123, change:
```php
// Before:
} elseif ($node instanceof ErrorSuppress) {
    $this->addLocation('error_suppression', $node);
}

// After:
} elseif ($node instanceof ErrorSuppress) {
    $funcName = null;
    if ($node->expr instanceof FuncCall && $node->expr->name instanceof Name) {
        $funcName = $node->expr->name->toLowerString();
    }
    $this->addLocation('error_suppression', $node, $funcName);
}
```
Note: `FuncCall` and `Name` are already imported.

**`ErrorSuppressionOptions`:**
```php
final readonly class ErrorSuppressionOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        /** @var list<string> Lowercase function names where @ is allowed */
        public array $allowedFunctions = [],
    ) {}

    public static function fromArray(array $config): self { /* ... */ }
    public function isEnabled(): bool { return $this->enabled; }
    public function getSeverity(int|float $value): ?Severity { return $value > 0 ? Severity::Warning : null; }

    public function isFunctionAllowed(string $funcName): bool
    {
        return $this->allowedFunctions !== []
            && \in_array(strtolower($funcName), $this->allowedFunctions, true);
    }
}
```

**`AbstractCodeSmellRule`** — add hook in `analyze()`:
```php
// In the foreach ($entries as $entry) loop, before creating violation:
if (!$this->shouldIncludeEntry($entry)) {
    continue;
}
```
```php
/** Override in subclasses to filter entries before violation creation. */
protected function shouldIncludeEntry(array $entry): bool
{
    return true;
}
```

**`ErrorSuppressionRule`** — override `shouldIncludeEntry()` + `buildMessage()`:
```php
protected function shouldIncludeEntry(array $entry): bool
{
    if (!$this->options instanceof ErrorSuppressionOptions) { return true; }
    $funcName = $entry['extra'] ?? null;
    return $funcName === null || !$this->options->isFunctionAllowed($funcName);
}
```
Override `buildMessage()` to include function name: `"Error suppression (@) on fopen() — handle errors explicitly"`

#### YAML
```yaml
rules:
  code-smell.error-suppression:
    allowed_functions:
      - fopen
      - file_get_contents
      - unlink
      - mkdir
      - json_decode
```

#### Edge cases
- `@$obj->method()` → `$node->expr` is `MethodCall`, not `FuncCall` → `extra` is `null` → always reported. Correct.
- `@SomeClass::staticMethod()` → `StaticCall`, not `FuncCall` → same. Correct.
- `@($callback)()` → dynamic call, `name` is not `Name` → `extra` is `null`. Correct.
- `@include 'file.php'` / `@require` → `Include_` node, not `FuncCall` → `extra` is `null`. Correct.
- Fully-qualified: `@\json_decode(...)` → `Name` resolves via `toLowerString()` → correct.
- Visitor change is additive: `extra` was `null` for `error_suppression`, now populated. No other rule reads this field for this type.

#### Definition of Done
- [ ] `ErrorSuppressionOptions` created with `allowedFunctions`
- [ ] Visitor captures function name in `extra`
- [ ] Rule filters by allowed functions
- [ ] Messages include function name when available
- [ ] Tests for all scenarios
- [ ] `composer check` passes
- [ ] Website docs updated (EN + RU)
- [ ] CHANGELOG entry added

---

### F2: Boolean-Argument Configurable Options

**Problem:** `boolean-argument` has only `enabled: true/false`. No way to whitelist self-documenting params like `$isActive`, `$hasPermission`. Rule is effectively unusable without project-wide `@qmx-ignore`.

**Impact:** 63 violations in our project; highly requested feature for any project.

#### Design

New `BooleanArgumentOptions` with `allowedPrefixes` list. Prefix matching uses camelCase boundary detection to avoid false matches like `$island` against prefix `is`.

**`maxPerMethod` is deferred** — requires collector-level method context that `CodeSmellLocation.extra` doesn't store. `allowedPrefixes` alone eliminates ~80% of false positives.

**Product note on defaults:** Default `allowedPrefixes: ['is', 'has', 'can', 'should', 'will', 'did', 'was']` — this IS a behavior change (fewer violations). Consistent with other rules' philosophy (`exclude_readonly`, `exclude_promoted_only`) of having sensible defaults. Users who want all bool params flagged set `allowed_prefixes: []`.

Uses `shouldIncludeEntry()` hook added in F4 — no need to duplicate `analyze()`.

#### Files

| Action | File                                                        | Change                                |
| ------ | ----------------------------------------------------------- | ------------------------------------- |
| CREATE | `src/Rules/CodeSmell/BooleanArgumentOptions.php`            | New options with `allowedPrefixes`    |
| MODIFY | `src/Rules/CodeSmell/BooleanArgumentRule.php`               | Use new options, override `analyze()` |
| CREATE | `tests/Unit/Rules/CodeSmell/BooleanArgumentOptionsTest.php` |                                       |
| MODIFY | `tests/Unit/Rules/CodeSmell/BooleanArgumentRuleTest.php`    | Test prefix filtering                 |

#### Key changes

**`BooleanArgumentOptions`:**
```php
final readonly class BooleanArgumentOptions implements RuleOptionsInterface
{
    private const DEFAULT_PREFIXES = ['is', 'has', 'can', 'should', 'will', 'did', 'was'];

    public function __construct(
        public bool $enabled = true,
        /** @var list<string> Prefixes for allowed boolean param names (camelCase boundary) */
        public array $allowedPrefixes = self::DEFAULT_PREFIXES,
    ) {}

    /**
     * Check if param name matches an allowed prefix at a word boundary.
     *
     * Uses strncasecmp for case-insensitive prefix match, then checks that
     * the boundary is valid: end of string, underscore (snake_case), or
     * uppercase following lowercase (camelCase). This prevents $ISLAND
     * matching "is" — both "IS" and "LA" are uppercase, so no camelCase boundary.
     *
     * Results: $isActive=yes, $island=no, $has_value=yes, $ISLAND=no,
     *          $IS_ACTIVE=yes (underscore), $is=yes (exact), $cannon=no
     */
    public function isAllowedPrefix(string $paramName): bool
    {
        $name = ltrim($paramName, '$');
        if ($name === '') { return false; }

        foreach ($this->allowedPrefixes as $prefix) {
            $len = \strlen($prefix);
            if (\strncasecmp($name, $prefix, $len) !== 0) { continue; }

            $next = $name[$len] ?? '';
            if ($next === '') { return true; }           // exact match: $is, $has
            if ($next === '_') { return true; }          // snake_case: $has_value, $IS_ACTIVE
            // camelCase boundary: next char uppercase AND previous char lowercase
            // This rejects $ISLAND (prev='S' uppercase) but allows $isActive (prev='s' lowercase)
            $prev = $name[$len - 1];
            if (\ctype_upper($next) && !\ctype_upper($prev)) { return true; }
        }
        return false;
    }
}
```

**`BooleanArgumentRule`** — override `shouldIncludeEntry()` (hook from F4):
```php
protected function shouldIncludeEntry(array $entry): bool
{
    if (!$this->options instanceof BooleanArgumentOptions) { return true; }
    $paramName = $entry['extra'] ?? null;
    return $paramName === null || !$this->options->isAllowedPrefix($paramName);
}
```

#### YAML
```yaml
rules:
  code-smell.boolean-argument:
    allowed_prefixes:
      - is
      - has
      - can
      - should
    # To flag ALL boolean params:
    # allowed_prefixes: []
```

#### Edge cases (verified with `strncasecmp` + `!ctype_upper($prev)` algorithm)
- **`$isActive`**: prefix `is`, next=`A` upper, prev=`s` lower → **allowed** (correct)
- **`$island`**: prefix `is`, next=`l` lower → **not allowed** (correct)
- **`$ISLAND`**: prefix `is`, next=`L` upper, prev=`S` upper → `!ctype_upper('S')` = false → **not allowed** (correct, review fix C1)
- **`$IS_ACTIVE`**: prefix `is`, next=`_` → **allowed** (correct, underscore boundary)
- **`$has_value`**: prefix `has`, next=`_` → **allowed** (correct)
- **`$hasMore`**: prefix `has`, next=`M` upper, prev=`s` lower → **allowed** (correct)
- **`$disco`**: prefix `did`/`dis` — no prefix match → **not allowed** (correct)
- **`$cannon`**: prefix `can`, next=`n` lower → **not allowed** (correct)
- **`$is`**: exact match (next=`''`) → **allowed** (correct)
- **`$dishonest`**: prefix `did` no match; prefix `dis` — not in defaults → **not allowed** (correct)
- **No param name** (`extra` is `null` or `?`): Not filtered → always reported. Correct.
- **Empty prefix list**: No filtering → all boolean params reported (backwards compatible).

#### Definition of Done
- [ ] `BooleanArgumentOptions` created with `allowedPrefixes` and `isAllowedPrefix()`
- [ ] `BooleanArgumentRule` uses new options via `shouldIncludeEntry()` hook
- [ ] `buildMessage()` continues to show param name
- [ ] Edge cases tested: `$isActive`, `$island`, `$ISLAND`, `$has_value`, `$cannon`, `$is`, `$disco`
- [ ] `composer check` passes
- [ ] Website docs updated (EN + RU)
- [ ] CHANGELOG entry: `Changed — code-smell.boolean-argument: parameters with common boolean prefixes (is*, has*, can*, ...) are now allowed by default (configurable via allowed_prefixes: [])`

---

## Iteration 2: Self-Analysis Configuration

**Depends on:** All 4 features from Iteration 1.

### Step 1: Remove baseline
- Delete `baseline.json`
- Update `.github/workflows/` — remove `--baseline=baseline.json` from CI commands (review fix H4)
- Run `bin/qmx check src/` — get full violation list

### Step 2: Design `qmx.yaml` using new features

Analyze each violation category and decide: fix code / tune config / `@qmx-ignore`.

**Decision tree for each violation:**
1. Is this a real code quality issue? → **Fix the code**
2. Is this structural (nature of project)? → **`exclude_paths`** or **`exclude_namespaces`**
3. Is this a threshold mismatch? → **Tune threshold** in `qmx.yaml`
4. Is this a one-off exception? → **`@qmx-threshold`** on the class/method
5. Is this genuinely inapplicable? → **`@qmx-ignore`** with reason

**Draft config (to be refined after running):**
```yaml
failOn: error

coupling:
  framework-namespaces: [Symfony, PhpParser, Psr, Amp]

rules:
  # Core VOs are coupling magnets by design
  coupling.cbo:
    exclude_namespaces:
      - Qualimetrix\Core\Violation
      - Qualimetrix\Core\Symbol
    exclude_paths:
      - src/Metrics/*Visitor.php
      - src/Infrastructure/DependencyInjection/Configurator/*
    namespace:
      warning: 16
      error: 25

  coupling.class-rank:
    exclude_namespaces:
      - Qualimetrix\Core\Violation
      - Qualimetrix\Core\Symbol

  coupling.distance:
    max_distance_warning: 0.4
    max_distance_error: 0.6

  coupling.instability:
    class:
      skip_leaf: true
    namespace:
      skip_leaf: true
      warning: 0.9
      error: 1.0

  code-smell.boolean-argument:
    allowed_prefixes: [is, has, can, should, will, did, was]

  code-smell.error-suppression:
    allowed_functions:
      - file_get_contents
      - file_put_contents
      - fopen
      - unlink
      - mkdir
      - rename
      - json_decode

  maintainability.index:
    warning: 35
    error: 20
```

### Step 3: Annotate remaining hot spots
Review remaining violations after config changes. Add targeted `@qmx-threshold` or `@qmx-ignore` with documented reasons.

### Step 4: Verify
- Run `bin/qmx check src/` — target: <100 remaining violations, all actionable
- `composer check` passes

### Step 5: Update CLAUDE.md
Add a "Self-Analysis (Dogfooding)" section:

```markdown
### Self-Analysis: Decision Framework

When `bin/qmx check src/` reports a violation, use this decision tree:
1. **Real issue?** → Fix the code, add regression test
2. **Structural (project nature)?** → Add `exclude_paths` or `exclude_namespaces` to `qmx.yaml`
3. **Threshold mismatch?** → Tune threshold in `qmx.yaml`
4. **One-off exception?** → Add `@qmx-threshold` with reason
5. **Genuinely inapplicable?** → Add `@qmx-ignore` with reason

**Never** add violations to a baseline. The baseline file should not exist.
```

### Definition of Done
- [ ] `baseline.json` removed
- [ ] `.github/workflows` updated — no baseline references
- [ ] `qmx.yaml` uses new features (exclude_paths, allowedPrefixes, skipLeaf, allowedFunctions)
- [ ] Remaining violations are actionable (<100 target)
- [ ] No `baseline.json` in project
- [ ] CLAUDE.md updated with dogfooding decision framework
- [ ] `composer check` passes
- [ ] CI green without baseline

---

## Documentation Plan (per feature)

Each feature requires:

| Artifact               | Location                                       | What to update                           |
| ---------------------- | ---------------------------------------------- | ---------------------------------------- |
| Website rule docs (EN) | `website/docs/rules/{group}.md`                | Add new options to Configuration section |
| Website rule docs (RU) | `website/docs/rules/{group}.ru.md`             | Mirror EN changes                        |
| Default thresholds     | `website/docs/reference/default-thresholds.md` | Add new defaults                         |
| Component README       | `src/Rules/{Category}/README.md` (if exists)   | Update structure                         |
| CHANGELOG              | `CHANGELOG.md`                                 | Add under `[Unreleased]`                 |
| `qmx.yaml.example`     | Root                                           | Add examples of new options              |

**F1 (exclude_paths):** Universal feature — document in a cross-cutting section (e.g., "Per-rule configuration" page)
**F2 (boolean-argument):** Update `website/docs/rules/code-smells.md`
**F3 (instability leaf):** Update `website/docs/rules/coupling.md`
**F4 (error-suppression):** Update `website/docs/rules/code-smells.md`

---

## Commit Strategy

| Commit                                                                             | Scope       |
| ---------------------------------------------------------------------------------- | ----------- |
| `feat(instability): add configurable skip_leaf for namespace-level leaf detection` | F3          |
| `feat(config): add per-rule exclude_paths configuration`                           | F1          |
| `feat(error-suppression): add allowed_functions configuration`                     | F4          |
| `feat(boolean-argument): add allowed_prefixes configuration`                       | F2          |
| `docs: update website and CHANGELOG for new rule options`                          | Docs batch  |
| `refactor(config): replace baseline with proper qmx.yaml configuration`            | Iteration 2 |

---

## Verification

After each feature:
```bash
composer check          # tests + phpstan + deptrac
```

After Iteration 2:
```bash
bin/qmx check src/      # self-analysis with new config
composer check           # ensure no regressions
```

After website docs:
```bash
cd website && .venv/bin/mkdocs build --strict    # if venv exists
```
