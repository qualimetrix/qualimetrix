# RFC-010: Package-level Metrics Extension

> **Status:** Draft
> **Date:** 2025-12-11

---

## Goal

Extend the existing `ClassCountCollector` to collect additional package-level metrics from PDepend.

---

## Scope

### To implement

| Metric | Description | PDepend equivalent |
|--------|-------------|-------------------|
| `functionCount` | Number of functions (not methods) in a file | `nof` |
| `interfaceCount` | Already exists in ClassCountCollector | `noi` |
| `traitCount` | Already exists in ClassCountCollector | `not` |

### Already implemented

- `classCount` - number of classes
- `abstractClassCount` - number of abstract classes
- `interfaceCount` - number of interfaces
- `traitCount` - number of traits
- `enumCount` - number of enums

### NOT implementing

| Metric | Reason |
|--------|--------|
| `noam` (Number of Added Methods) | Requires cross-file inheritance analysis |
| `norm` (Number of Overwritten Methods) | Same reason |
| `hnt` (Hierarchy Nesting Level) | Requires building a complete inheritance tree |

---

## Detailed design

### Changes in ClassCountVisitor

Add counting of `Stmt\Function_` (standalone functions, not class methods).

```php
// Current state
if ($node instanceof Class_ && $node->name !== null) { ... }
if ($node instanceof Interface_) { ... }
if ($node instanceof Trait_) { ... }
if ($node instanceof Enum_) { ... }

// Add
if ($node instanceof Function_) {
    ++$this->functionCount;
}
```

### Changes in ClassCountCollector

1. Add `functionCount` metric
2. Add `MetricDefinition` with Sum aggregation

### Validation

Verify that `Function_` refers to standalone functions, not class methods (methods are `ClassMethod`).

---

## Contracts

### ClassCountVisitor (extension)

```php
// Add:
private int $functionCount = 0;

public function getFunctionCount(): int
{
    return $this->functionCount;
}
```

### ClassCountCollector (extension)

```php
private const METRIC_FUNCTION_COUNT = 'functionCount';

public function provides(): array
{
    return [
        // ... existing
        self::METRIC_FUNCTION_COUNT,
    ];
}

public function collect(SplFileInfo $file, array $ast): MetricBag
{
    return (new MetricBag())
        // ... existing
        ->with(self::METRIC_FUNCTION_COUNT, $this->visitor->getFunctionCount());
}
```

---

## Test plan

### Unit tests

**ClassCountVisitor:**
- Counting standalone functions
- Ignoring class methods
- Counting functions in namespace

**ClassCountCollector:**
- `functionCount` metric in MetricBag
- Aggregation by namespace and project

### Fixtures

```php
// tests/Fixtures/package_metrics.php
namespace App\Utils;

function helper1() {}
function helper2() {}

class MyClass {
    public function method() {} // Not counted
}

// Expected: functionCount = 2
```

---

## Definition of Done

- [ ] `functionCount` metric added to ClassCountCollector
- [ ] Unit tests cover standalone functions
- [ ] Aggregation works (Sum by namespace/project)
- [ ] `composer check` passes
- [ ] README for Size metrics updated

---

## Complexity

**Estimate: Low** - minimal changes to existing code.

**Files to change:**
1. `src/Metrics/Size/ClassCountVisitor.php` - +15 lines
2. `src/Metrics/Size/ClassCountCollector.php` - +10 lines
3. `tests/Unit/Metrics/Size/ClassCountVisitorTest.php` - +1 test
4. `tests/Unit/Metrics/Size/ClassCountCollectorTest.php` - +1 test

---

## Change history

| Date | Change |
|------|--------|
| 2025-12-11 | RFC created |
