# RFC-009: Code Smell Detection Rules

> **Status:** Draft
> **Date:** 2025-12-11
> **Source:** [FEATURE_GAP_ANALYSIS.md](../FEATURE_GAP_ANALYSIS.md)

---

## Goal

Implement a set of rules for detecting common code smells and anti-patterns that exist in PHPMD but are missing in AIMD.

---

## Scope

### To implement (Quick Wins)

| Rule | Description | PHPMD equivalent | Priority |
|------|-------------|------------------|----------|
| `goto` | Usage of `goto` | GotoStatement | High |
| `eval` | Usage of `eval()` | EvalExpression | High |
| `exit` | Usage of `exit()`/`die()` | ExitExpression | Medium |
| `empty-catch` | Empty catch blocks | EmptyCatchBlock | High |
| `debug-code` | `var_dump()`, `print_r()`, `dd()`, `dump()` | DevelopmentCodeFragment | High |
| `error-suppression` | Usage of `@` operator | ErrorControlOperator | Medium |
| `count-in-loop` | `count()` in loop condition | CountInLoopExpression | Medium |
| `superglobals` | Direct access to `$_GET`, `$_POST`, etc. | Superglobals | Medium |

### NOT implementing (require significant rework)

| Rule | Reason for rejection |
|------|---------------------|
| `UnusedPrivateField/Method` | Requires data-flow analysis, PHPStan handles it better |
| `UnusedFormalParameter` | Same reason |
| `BooleanArgumentFlag` | Subjective, questionable value |
| `StaticAccess` | Too many false positives |
| Naming conventions | PHP-CS-Fixer handles it better |

---

## Architecture

### General approach

Create **one universal collector** `CodeSmellCollector` that collects all simple patterns in a single AST pass. Then several rules read its metrics.

```
CodeSmellCollector (single AST pass)
        |
+-------+-------+-------+-------+
|       |       |       |       |
GotoRule EvalRule EmptyCatchRule ...
```

### Alternative: separate collectors

Each pattern could have its own collector, but this means:
- More boilerplate
- More files
- Same result

**Decision:** One `CodeSmellCollector` with configurable detectors.

---

## Detailed design

### 1. CodeSmellCollector

**Location:** `src/Metrics/CodeSmell/CodeSmellCollector.php`

**Collects metrics:**
- `codeSmell.goto.count` - number of `goto` in the file
- `codeSmell.goto.locations` - JSON with positions `[{line, column}]`
- `codeSmell.eval.count`
- `codeSmell.eval.locations`
- `codeSmell.exit.count`
- `codeSmell.exit.locations`
- `codeSmell.emptyCatch.count`
- `codeSmell.emptyCatch.locations`
- `codeSmell.debugCode.count`
- `codeSmell.debugCode.locations`
- `codeSmell.errorSuppression.count`
- `codeSmell.errorSuppression.locations`
- `codeSmell.countInLoop.count`
- `codeSmell.countInLoop.locations`
- `codeSmell.superglobals.count`
- `codeSmell.superglobals.locations`

**Note:** `locations` is a JSON string for storing positions in MetricBag (which only supports scalar values).

### 2. CodeSmellVisitor

**Location:** `src/Metrics/CodeSmell/CodeSmellVisitor.php`

Detects:

| Node type | Pattern |
|-----------|---------|
| `Stmt\Goto_` | goto statement |
| `Expr\Eval_` | eval() |
| `Expr\Exit_` | exit()/die() |
| `Stmt\Catch_` | empty catch (body empty or only comment) |
| `Expr\FuncCall` | var_dump, print_r, dd, dump, debug_backtrace |
| `Expr\ErrorSuppress` | @ operator |
| `Stmt\For_`, `Stmt\While_`, `Stmt\DoWhile_` | count() in condition |
| `Expr\Variable` | $_GET, $_POST, $_REQUEST, $_COOKIE, $_SESSION, $_SERVER, $_FILES |

### 3. Rules

Each rule is a separate class:

| Class | Slug | Severity |
|-------|------|----------|
| `GotoRule` | `goto` | Error |
| `EvalRule` | `eval` | Error |
| `ExitRule` | `exit` | Warning |
| `EmptyCatchRule` | `empty-catch` | Error |
| `DebugCodeRule` | `debug-code` | Error |
| `ErrorSuppressionRule` | `error-suppression` | Warning |
| `CountInLoopRule` | `count-in-loop` | Warning |
| `SuperglobalsRule` | `superglobals` | Warning |

**Location:** `src/Rules/CodeSmell/`

### 4. Configuration

```yaml
# aimd.yaml
rules:
  goto:
    enabled: true
  eval:
    enabled: true
  exit:
    enabled: true
    # Can add allow_in_cli: true for CLI scripts
  empty-catch:
    enabled: true
  debug-code:
    enabled: true
    # Additional functions for detection
    extra_functions: ['ray', 'clockwork']
  error-suppression:
    enabled: true
  count-in-loop:
    enabled: true
  superglobals:
    enabled: true
    # Allowed in specific files
    allowed_in: ['public/index.php']
```

---

## Contracts

### CodeSmellVisitor

```php
interface CodeSmellDetectorInterface
{
    /** @return list<CodeSmellLocation> */
    public function getDetectedSmells(): array;
    public function reset(): void;
}

final readonly class CodeSmellLocation
{
    public function __construct(
        public string $type,    // 'goto', 'eval', etc.
        public int $line,
        public int $column,
        public ?string $extra = null,  // e.g., function name for debug-code
    ) {}
}
```

### Rule Options (example)

```php
final readonly class DebugCodeOptions implements RuleOptionsInterface
{
    /** @param list<string> $extraFunctions */
    public function __construct(
        public bool $enabled = true,
        public array $extraFunctions = [],
    ) {}

    public static function fromArray(array $data): self;
    public function toArray(): array;
}
```

---

## Implementation sequence

### Phase 1: Infrastructure (1 PR)

1. Create `src/Metrics/CodeSmell/` directory
2. Implement `CodeSmellLocation` VO
3. Implement `CodeSmellVisitor` (all patterns)
4. Implement `CodeSmellCollector`
5. Unit tests for visitor and collector

### Phase 2: Core rules (1 PR)

1. Create `src/Rules/CodeSmell/` directory
2. Implement `AbstractCodeSmellRule` (base class)
3. Implement high-priority rules:
   - `GotoRule`
   - `EvalRule`
   - `EmptyCatchRule`
   - `DebugCodeRule`
4. Unit tests for each rule
5. Integration tests

### Phase 3: Additional rules (1 PR)

1. `ExitRule`
2. `ErrorSuppressionRule`
3. `CountInLoopRule`
4. `SuperglobalsRule`
5. Tests

### Phase 4: Documentation

1. Update README.md
2. Add rule descriptions to documentation
3. Configuration examples

---

## Test plan

### Unit tests

**CodeSmellVisitor:**
- Detection of each smell type
- Correct positions (line, column)
- Reset between files
- Edge cases (nested structures)

**CodeSmellCollector:**
- Correct metrics
- JSON serialization of locations

**Rules:**
- Violation generation when smell is present
- No violations when enabled: false
- Correct severity, message, location

### Integration tests

- Full pipeline with enabled rules
- Output format verification (text, json, sarif)
- Baseline/suppression for code smell violations

---

## Definition of Done

- [ ] `CodeSmellCollector` collects all metrics
- [ ] All 8 rules implemented and tested
- [ ] `composer check` passes (tests + phpstan + deptrac)
- [ ] Documentation updated
- [ ] Examples in README

---

## Edge cases and limitations

### Empty catch with comment

```php
try {
    // ...
} catch (Exception $e) {
    // Intentionally empty
}
```

**Decision:** Consider empty if there are no statements. A comment is not a statement.

### exit() in CLI scripts

Legitimate usage in entry points.

**Decision:** `allowed_files` option in config.

### Debug code in tests

```php
// tests/SomeTest.php
var_dump($result); // May be intentional
```

**Decision:** The rule checks all files. The user can:
- Exclude tests in config (`exclude: [tests/]`)
- Add to baseline
- Use `@aimd-ignore debug-code`

### count() in for with variable

```php
$count = count($items);
for ($i = 0; $i < $count; $i++) {} // OK
```

vs

```php
for ($i = 0; $i < count($items); $i++) {} // BAD
```

**Decision:** Only check for direct count() call in condition.

---

## Implementation notes

### Storing locations in MetricBag

`MetricBag` stores `array<string, int|float|string>`. For locations we use JSON:

```php
$bag->with('codeSmell.goto.locations', json_encode($locations));
```

### Alternative: additionalData in AnalysisContext

Could pass through `AnalysisContext::$additionalData`, but this complicates the architecture.

**Decision:** JSON in MetricBag - simpler and consistent with current patterns.

---

## Change history

| Date | Change |
|------|--------|
| 2025-12-11 | RFC created |
