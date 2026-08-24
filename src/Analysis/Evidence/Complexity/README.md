# Complexity

## Subject and boundary

`Analysis\\Evidence\\Complexity` owns the cyclomatic, cognitive and NPath
evidence collectors, their visitors and expression calculator, and the four
rules that interpret that evidence: `complexity.cyclomatic`,
`complexity.cognitive`, `complexity.npath`, and `complexity.wmc`.

The leaf does not publish a `Contract/` surface. It consumes Measurement's
collector, metric and aggregation contracts and Finding's rule and finding
contracts. Measurement retains `CallableToClassAggregator` and the
`MetricName::STRUCTURE_WMC` derived metric: WMC is the class-level sum of
callable CCN, while this leaf owns its rule and options.

## Structure

```text
Complexity/
├── CognitiveComplexityCollector.php
├── CognitiveComplexityVisitor.php
├── CyclomaticComplexityCollector.php
├── CyclomaticComplexityVisitor.php
├── NpathComplexityCollector.php
├── NpathComplexityVisitor.php
├── NpathExpressionCalculator.php
├── ClassCognitiveComplexityOptions.php
├── ClassComplexityOptions.php
├── ClassNpathComplexityOptions.php
├── CognitiveComplexityOptions.php
├── CognitiveComplexityRule.php
├── ComplexityOptions.php
├── ComplexityRule.php
├── MethodCognitiveComplexityOptions.php
├── MethodComplexityOptions.php
├── MethodNpathComplexityOptions.php
├── NpathComplexityOptions.php
├── NpathComplexityRule.php
├── WmcOptions.php
└── WmcRule.php
```

The three callable collectors retain their names, requirements and metric
definitions. Rule metadata retains the exact option-class mappings:
`ComplexityRule` -> `ComplexityOptions`, `CognitiveComplexityRule` ->
`CognitiveComplexityOptions`, `NpathComplexityRule` ->
`NpathComplexityOptions`, and `WmcRule` -> `WmcOptions`.

## Complexity metrics

Complexity metrics measure the number of execution paths and cognitive load of code.

---

## Cyclomatic Complexity (CCN)

**Collector:** `CyclomaticComplexityCollector`
**Provides:** `ccn`
**Level:** Callable

### Formula

```
CCN = 1 + number of branching points
```

### Branching Points

| Construct                 | Contribution |
| ------------------------- | ------------ |
| `if`                      | +1           |
| `elseif`                  | +1           |
| `while`, `for`, `foreach` | +1           |
| `case` (in switch)        | +1           |
| `catch`                   | +1           |
| `&&`, `\|\|`, `and`, `or` | +1           |
| `?:` (ternary)            | +1           |
| `??` (null coalescing)    | +1           |
| `?->` (nullsafe)          | +1           |

### Interpretation

| CCN   | Quality                         |
| ----- | ------------------------------- |
| 1-5   | Simple function                 |
| 6-10  | Moderate complexity             |
| 11-20 | Complex function                |
| 21+   | Very complex, needs refactoring |

---

## Cognitive Complexity

**Collector:** `CognitiveComplexityCollector`
**Provides:** `cognitive`
**Level:** Callable

### Differences from CCN

| Aspect        | CCN             | Cognitive                   |
| ------------- | --------------- | --------------------------- |
| Goal          | Number of paths | Difficulty of understanding |
| `a && b && c` | +3              | +1 (single chain)           |
| Nesting       | Not considered  | +1 per level                |
| `switch`      | +N cases        | +1                          |

### Algorithm

**Base increments (+1):**
- `if`, `elseif`, `else`, `switch`
- `for`, `foreach`, `while`, `do-while`
- `catch`, `goto`, `break LABEL`, `continue LABEL`
- Recursive call
- Logical chain (`&&`, `\|\|`)
- Ternary `?:`, `??`, `match`

**Nesting bonus:**

```php
if ($a) {                    // +1 (nesting=0)
    if ($b) {                // +2 (1 + nesting=1)
        foreach ($c as $d) { // +3 (1 + nesting=2)
            // ...
        }
    }
}
```

### Example

```php
// CCN = 4, Cognitive = 7
function processItems(array $data): void {
    if ($data) {                      // +1
        foreach ($data as $item) {    // +2 (1 + nesting=1)
            if ($item->isValid()) {   // +3 (1 + nesting=2)
                $this->process($item);
            }
        }
    }
}
```

### Interpretation

| Cognitive | Quality                            |
| --------- | ---------------------------------- |
| 0-10      | Simple code                        |
| 11-15     | Moderate complexity                |
| 16-25     | Complex code                       |
| 25+       | Very complex, refactoring required |

---

## NPath Complexity

**Collector:** `NpathComplexityCollector`
**Provides:** `npath`
**Level:** Callable

### Differences from CCN

| Aspect         | CCN                        | NPath                          |
| -------------- | -------------------------- | ------------------------------ |
| What it counts | Independent paths (linear) | All combinations (exponential) |
| Nesting        | Not considered             | Multiplication                 |
| `if + if`      | +2                         | x2 (2 x 2 = 4 paths)           |

### Formulas

**Sequence:** `NPath = NPath(A) x NPath(B)`

**Branching:**
```
if (cond) { then } else { else }
NPath = NPath(cond) + NPath(then) + NPath(else)
```

**Loops:**
```
while (cond) { body }
NPath = NPath(cond) + NPath(body)
```

**Switch:**
```
NPath = sum of NPath(case_i)
```

### Examples

```php
// NPath = 1
function simple(int $x): int {
    return $x + 1;
}

// NPath = 4, CCN = 3
function nested(int $x, int $y): int {
    if ($x > 0) {           // 2 paths
        if ($y > 0) {       // x 2 = 4 combinations
            return $x + $y;
        }
        return $x;
    }
    return 0;
}

// NPath = 16, CCN = 5
function manyIfs(bool $a, bool $b, bool $c, bool $d): int {
    $result = 0;
    if ($a) $result += 1;
    if ($b) $result += 2;
    if ($c) $result += 4;
    if ($d) $result += 8;
    return $result;  // 2^4 = 16 combinations
}
```

### Interpretation

| NPath  | Quality                                  |
| ------ | ---------------------------------------- |
| 1-10   | Simple function                          |
| 11-50  | Moderate complexity                      |
| 51-200 | Complex function                         |
| 200+   | Practically impossible to test all paths |

---

## Aggregation

All metrics are collected at the **Callable** hierarchy level and aggregated upward.
Concrete PHP methods and global functions are both represented as callables:

```php
new MetricDefinition(
    name: 'ccn', // 'cognitive', 'npath'
    collectedAt: SymbolLevel::Callable,
    aggregations: [
        SymbolLevel::Class_->value => [Sum, Average, Max],
        SymbolLevel::Namespace_->value => [Sum, Average, Max],
        SymbolLevel::Project->value => [Sum, Average, Max],
    ],
)
```

**Aggregated names:** `ccn.sum`, `ccn.avg`, `ccn.max`, `cognitive.sum`, `npath.avg`, etc.

## WMC (Weighted Methods per Class)

WMC is derived by Measurement as `MetricName::STRUCTURE_WMC`: the sum of the
callable-level CCN values for a class. `WmcRule` consumes that metric together
with data-class and method-count evidence; it does not collect or aggregate
WMC itself. Its `complexity.wmc` channel retains the existing warning/error
thresholds and `excludeDataClasses` option.

## Test ownership and Definition of Done

Owned tests live under `tests/Analysis/Evidence/Complexity/`: thirteen unit
test classes cover the three collector/visitor families and four rules; the
integration test verifies WMC aggregation and reporting. The package is done
when all 14 test classes (374 PHPUnit IDs) are discovered, collector
`requires()`/`provides()` sets and all rule IDs/channels/options are unchanged,
and no old Complexity production or test FQCN remains in this leaf.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
