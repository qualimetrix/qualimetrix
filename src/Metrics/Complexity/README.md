# Complexity Metrics

Complexity metrics measure the number of execution paths and cognitive load of code.

---

## Cyclomatic Complexity (CCN)

**Collector:** `CyclomaticComplexityCollector`
**Provides:** `ccn`
**Level:** Method

### Formula

```
CCN = 1 + number of branching points
```

### Branching Points

| Construct | Contribution |
|-----------|--------------|
| `if` | +1 |
| `elseif` | +1 |
| `while`, `for`, `foreach` | +1 |
| `case` (in switch) | +1 |
| `catch` | +1 |
| `&&`, `\|\|`, `and`, `or` | +1 |
| `?:` (ternary) | +1 |
| `??` (null coalescing) | +1 |
| `?->` (nullsafe) | +1 |

### Interpretation

| CCN | Quality |
|-----|---------|
| 1-5 | Simple function |
| 6-10 | Moderate complexity |
| 11-20 | Complex function |
| 21+ | Very complex, needs refactoring |

---

## Cognitive Complexity

**Collector:** `CognitiveComplexityCollector`
**Provides:** `cognitive`
**Level:** Method

### Differences from CCN

| Aspect | CCN | Cognitive |
|--------|-----|-----------|
| Goal | Number of paths | Difficulty of understanding |
| `a && b && c` | +3 | +1 (single chain) |
| Nesting | Not considered | +1 per level |
| `switch` | +N cases | +1 |

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

| Cognitive | Quality |
|-----------|---------|
| 0-10 | Simple code |
| 11-15 | Moderate complexity |
| 16-25 | Complex code |
| 25+ | Very complex, refactoring required |

---

## NPath Complexity

**Collector:** `NpathComplexityCollector`
**Provides:** `npath`
**Level:** Method

### Differences from CCN

| Aspect | CCN | NPath |
|--------|-----|-------|
| What it counts | Independent paths (linear) | All combinations (exponential) |
| Nesting | Not considered | Multiplication |
| `if + if` | +2 | x2 (2 x 2 = 4 paths) |

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

| NPath | Quality |
|-------|---------|
| 1-10 | Simple function |
| 11-50 | Moderate complexity |
| 51-200 | Complex function |
| 200+ | Practically impossible to test all paths |

---

## Aggregation

All metrics are collected at the **Method** level and aggregated upward:

```php
new MetricDefinition(
    name: 'ccn', // 'cognitive', 'npath'
    collectedAt: SymbolLevel::Method,
    aggregations: [
        SymbolLevel::Class_->value => [Sum, Average, Max],
        SymbolLevel::Namespace_->value => [Sum, Average, Max],
        SymbolLevel::Project->value => [Sum, Average, Max],
    ],
)
```

**Aggregated names:** `ccn.sum`, `ccn.avg`, `ccn.max`, `cognitive.sum`, `npath.avg`, etc.
