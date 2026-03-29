# T12: @qmx-threshold Annotations

**Proposal:** #9 | **Priority:** Batch 6 (threshold infrastructure) | **Effort:** ~8h | **Dependencies:** none

## Motivation

Some classes are architecturally expected to have elevated metrics (DI configurators, pipeline
orchestrators, facade classes). `@qmx-ignore` suppresses entirely — losing visibility. What's
needed is per-class threshold overrides that keep the class under analysis but with adjusted limits.

## Current Threshold Data Flow

```
YAML config
  → ConfigurationPipeline (stages parse YAML)
    → RuleOptionsFactory.create() per rule
      → RuleOptionsInterface.fromArray(config)
        → e.g. MethodComplexityOptions(warning=10, error=20)

RuleExecutor.execute(AnalysisContext)
  → foreach rule: rule.analyze(context)
    → rule reads metrics from context.metrics
    → rule calls options.getSeverity(metricValue)
      → returns null (ok) | Severity::Warning | Severity::Error
    → if severity != null → creates Violation

ViolationFilterPipeline.filter(violations)   ← POST-RULE, TOO LATE FOR OVERRIDES
  → baseline filter
  → suppression filter (@qmx-ignore)
  → path exclusion filter
  → git scope filter
```

**Key insight:** `getSeverity()` is called INSIDE the rule, with options set BEFORE rule execution.
Threshold overrides must be available to rules at `analyze()` time, NOT in the filter pipeline.

## Design

### Annotation Syntax

Mirrors the YAML config threshold/warning/error model:

```php
/**
 * @qmx-threshold coupling.cbo 30
 * @qmx-threshold complexity.cyclomatic warning=15 error=25
 */
class ContainerFactory { ... }
```

**Shorthand** (`@qmx-threshold rule value`): Sets both warning and error to `value`.
**Explicit** (`@qmx-threshold rule warning=W error=E`): Sets warning and error separately.
**Partial** (`@qmx-threshold rule warning=W`): Override warning only, error stays default.

### Data Flow — Where Overrides Are Injected

```
AST Traversal (collection phase)
  → SuppressionExtractor.extract(ast)      ← EXISTING: extracts @qmx-ignore
  → ThresholdOverrideExtractor.extract(ast) ← NEW: extracts @qmx-threshold
    → returns list<ThresholdOverride> per symbol

CollectionOrchestrator.collect()
  → FileProcessor.process(file)
    → extractSuppressions(ast) → suppressions     ← EXISTING
    → extractThresholdOverrides(ast) → overrides   ← NEW
  → CollectionPhaseOutput includes overrides

AnalysisPipeline.analyze()
  → creates AnalysisContext with overrides         ← NEW FIELD
  → RuleExecutor.execute(context)
    → rule.analyze(context)
      → rule reads metric value
      → rule resolves effective options:            ← CHANGED
         1. Get default options (from config)
         2. Check context for @qmx-threshold override for this symbol + rule
         3. If override exists → create modified options with overridden thresholds
         4. Call effectiveOptions.getSeverity(value)
```

### Data Model

**ThresholdOverride VO** — `src/Core/Suppression/ThresholdOverride.php`:

```php
final readonly class ThresholdOverride {
    public function __construct(
        public string $rulePattern,      // Rule name or prefix (supports RuleMatcher)
        public int|float|null $warning,  // null = keep default
        public int|float|null $error,    // null = keep default
        public int $line,                // Docblock line (for diagnostics)
        public ?int $endLine = null,     // Symbol end line (scope)
    ) {}
}
```

**Storage:** `array<string, list<ThresholdOverride>>` keyed by file path (same pattern as suppressions).

### Parsing — ThresholdOverrideExtractor

**File:** `src/Baseline/Suppression/ThresholdOverrideExtractor.php` (sibling of SuppressionExtractor)

```
Pattern: /@qmx-threshold\s+([\w.*-]+)\s+(.+)/
```

Parse capture group 2:
- Matches `/^\d+(\.\d+)?$/` → shorthand: `warning = error = value`
- Matches `/warning=(\d+(?:\.\d+)?)/` → explicit warning
- Matches `/error=(\d+(?:\.\d+)?)/` → explicit error
- Both can appear: `warning=15 error=25`

Scope: same as `@qmx-ignore` — symbol-level (class/method docblock).

### Integration with Rules — Options Override

**The injection point is inside each rule's analyze() method**, where `options.getSeverity(value)` is called.

**Approach: Wrap options with override at rule execution time.**

Add to `AnalysisContext`:

```php
final readonly class AnalysisContext {
    // ... existing fields ...
    /** @var array<string, list<ThresholdOverride>> file → overrides */
    public array $thresholdOverrides = [],
}
```

Add helper method to `AnalysisContext`:

```php
public function getThresholdOverride(string $ruleName, string $file, int $line): ?ThresholdOverride
{
    if (!isset($this->thresholdOverrides[$file])) {
        return null;
    }
    foreach ($this->thresholdOverrides[$file] as $override) {
        if (!RuleMatcher::matches($override->rulePattern, $ruleName)) {
            continue;
        }
        if ($line >= $override->line && ($override->endLine === null || $line <= $override->endLine)) {
            return $override;
        }
    }
    return null;
}
```

**How rules use it — two approaches:**

**Option A (per-rule, explicit):** Each rule checks for overrides before calling getSeverity():

```php
// In a rule's analyze() method:
$override = $context->getThresholdOverride($this->getName(), $symbol->file, $symbol->line);
$effectiveOptions = $override !== null
    ? $this->options->withOverride($override->warning, $override->error)
    : $this->options;
$severity = $effectiveOptions->getSeverity($value);
```

Requires adding `withOverride(?int|float $warning, ?int|float $error): self` to RuleOptionsInterface.

**Option B (centralized, in RuleExecutor):** RuleExecutor wraps rule options with overrides
before calling `rule.analyze()`. Rules don't know about overrides.

This is harder because overrides are per-symbol, but options are per-rule (global).
The rule itself resolves per-symbol threshold in its loop.

**Recommendation: Option A.** It's explicit, doesn't require changing RuleExecutor, and each
rule already has access to both the options and the context. The `withOverride()` method is
the cleanest integration point.

### RuleOptionsInterface Extension

Add `withOverride()` to the interface:

```php
interface RuleOptionsInterface {
    // ... existing methods ...

    /**
     * Returns a copy with overridden thresholds.
     * Null values keep the original threshold.
     */
    public function withOverride(int|float|null $warning, int|float|null $error): static;
}
```

**For simple options** (e.g., `MethodComplexityOptions`):
```php
public function withOverride(int|float|null $warning, int|float|null $error): static {
    return new static(
        warning: $warning ?? $this->warning,
        error: $error ?? $this->error,
    );
}
```

**For hierarchical options** (e.g., `NpathComplexityOptions`):
`@qmx-threshold` on a method → override applies to method-level options.
`@qmx-threshold` on a class → override applies to class-level options.
The rule determines which level to override based on where the annotation appears.

Hierarchical rules call `$options->forLevel($level)->getSeverity($value)`. So `withOverride()`
at the level options (e.g., `MethodNpathComplexityOptions`) is sufficient. The parent hierarchical
options class delegates:

```php
// In NpathComplexityOptions:
public function withOverride(int|float|null $warning, int|float|null $error): static {
    // For hierarchical: not applicable at this level, override happens at forLevel() level
    return $this;
}

// Override is applied at LevelOptionsInterface level instead
```

**Simpler approach for hierarchical:** Rules already resolve the level inside their `analyzeLevel()`.
At that point they have `$levelOptions = $this->options->forLevel($level)`. Apply override there:

```php
$levelOptions = $this->options->forLevel($level);
$override = $context->getThresholdOverride($this->getName(), $file, $line);
if ($override !== null) {
    $levelOptions = $levelOptions->withOverride($override->warning, $override->error);
}
$severity = $levelOptions->getSeverity($value);
```

This means `withOverride()` is needed on `LevelOptionsInterface`, not on `HierarchicalRuleOptionsInterface`.

### Integration Point — AbstractRule Convenience

Most rules extend `AbstractRule`. Add a helper:

```php
// In AbstractRule:
protected function getEffectiveSeverity(
    AnalysisContext $context,
    RuleOptionsInterface|LevelOptionsInterface $options,
    string $file,
    int $line,
    int|float $value,
): ?Severity {
    $override = $context->getThresholdOverride($this->getName(), $file, $line);
    if ($override !== null) {
        $options = $options->withOverride($override->warning, $override->error);
    }
    return $options->getSeverity($value);
}
```

Rules call `$this->getEffectiveSeverity($context, $options, $file, $line, $value)` instead of
`$options->getSeverity($value)`. This centralizes override logic without changing RuleExecutor.

## Files to modify

| File                                                      | Change                                                     |
| --------------------------------------------------------- | ---------------------------------------------------------- |
| `src/Core/Suppression/ThresholdOverride.php`              | **New VO**                                                 |
| `src/Core/Rule/AnalysisContext.php`                       | Add `$thresholdOverrides` field + `getThresholdOverride()` |
| `src/Core/Rule/RuleOptionsInterface.php`                  | Add `withOverride()` method                                |
| `src/Core/Rule/LevelOptionsInterface.php`                 | Add `withOverride()` method                                |
| `src/Core/Rule/AbstractRule.php`                          | Add `getEffectiveSeverity()` helper                        |
| `src/Baseline/Suppression/ThresholdOverrideExtractor.php` | **New class** — parse @qmx-threshold                       |
| `src/Analysis/Collection/FileProcessor.php`               | Call ThresholdOverrideExtractor in process()               |
| `src/Analysis/Collection/CollectionOrchestrator.php`      | Collect overrides alongside suppressions                   |
| `src/Analysis/Collection/CollectionPhaseOutput.php`       | Add overrides to output                                    |
| `src/Analysis/Pipeline/AnalysisPipeline.php`              | Pass overrides to AnalysisContext                          |
| All rule classes using `getSeverity()`                    | Migrate to `getEffectiveSeverity()`                        |
| Tests                                                     | Threshold override parsing + rule behavior tests           |
| `src/Baseline/README.md`                                  | Document @qmx-threshold                                    |
| Website: suppression documentation (EN + RU)              | Document @qmx-threshold syntax                             |

## Acceptance criteria

- [ ] `@qmx-threshold coupling.cbo 30` raises both warning and error to 30 for that class
- [ ] `@qmx-threshold complexity.cyclomatic warning=15 error=25` sets separate thresholds
- [ ] `@qmx-threshold rule warning=20` overrides warning only, error stays default
- [ ] Class still analyzed and metrics computed — only thresholds change
- [ ] Violation severity reflects the overridden threshold
- [ ] Rule prefix matching works (`coupling` matches `coupling.cbo`, `coupling.instability`)
- [ ] Hierarchical rules: override on method applies to method-level thresholds
- [ ] Hierarchical rules: override on class applies to class-level thresholds
- [ ] `@qmx-threshold` combined with `@qmx-ignore` for same rule → ignore takes precedence
- [ ] Float thresholds work (e.g., `@qmx-threshold coupling.instability 0.8`)
- [ ] Conflicting annotations (same rule, different values on same symbol) → error diagnostic
- [ ] `warning > error` → validation error diagnostic
- [ ] Negative values → validation error diagnostic
- [ ] All existing rules work with `getEffectiveSeverity()` (no behavioral change without annotations)
- [ ] PHPStan passes, tests pass

## Edge cases

- `@qmx-threshold` on a method → applies to method-level rules only for that method
- `@qmx-threshold` on a class → applies to class-level AND method-level rules within that class
- `@qmx-threshold * 30` → wildcard raises all thresholds (uses RuleMatcher)
- Multiple non-conflicting annotations: `@qmx-threshold coupling.cbo 30` + `@qmx-threshold complexity.* 20` → both applied
- Same rule, conflicting values → error diagnostic reported as violation
- Rule without threshold semantics (e.g., boolean rules like debug-code) → withOverride() is no-op
- Options classes with non-standard threshold structure → implement withOverride() to do nothing

## Migration strategy

Adding `withOverride()` to `RuleOptionsInterface` and `LevelOptionsInterface` requires implementing
it in ALL existing options classes. This can be done via:
1. Add default implementation in `AbstractRule` that returns `$this` (no-op)
2. Implement properly in options classes that have warning/error fields
3. Options without thresholds (boolean rules) → return `$this`

Or: add `withOverride()` as a method on a new `ThresholdAwareOptionsInterface` that extends
`RuleOptionsInterface`. Rules that don't support thresholds don't implement it. The
`getEffectiveSeverity()` helper checks `instanceof ThresholdAwareOptionsInterface` before
attempting override.

**Recommendation:** Use `ThresholdAwareOptionsInterface` to avoid forcing no-op implementations
on all options classes. Most rules with warning/error already follow the same pattern.

## Relationship to @qmx-ignore

| Annotation       | Effect             | Metrics computed? | Violation possible? |
| ---------------- | ------------------ | ----------------- | ------------------- |
| (none)           | Default thresholds | Yes               | Yes                 |
| `@qmx-threshold` | Custom thresholds  | Yes               | Yes (if exceeded)   |
| `@qmx-ignore`    | Suppressed         | Yes               | No (filtered out)   |

Processing order: `@qmx-ignore` is checked in `ViolationFilterPipeline` (post-rule).
`@qmx-threshold` is applied during rule execution (pre-violation). They are independent
mechanisms operating at different pipeline stages.
