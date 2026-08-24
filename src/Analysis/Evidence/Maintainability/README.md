# Maintainability

## Subject and boundary

`Analysis\\Evidence\\Maintainability` owns the Halstead input evidence, the
derived Maintainability Index (MI), and the `maintainability.index` rule and
its options. Halstead is an input to MI's lifecycle rather than a separate
capability.

The leaf has no `Contract/` surface. It consumes Measurement's collector,
metric, aggregation, and visitor contracts; Finding's rule, option, channel,
and finding contracts; and neutral Core identifiers. Infrastructure owns the
bounded DI registration for this internal leaf.

## Structure

```text
Maintainability/
├── HalsteadCollector.php
├── HalsteadMetrics.php
├── HalsteadVisitor.php
├── MaintainabilityIndexCalculator.php
├── MaintainabilityIndexCollector.php
├── MaintainabilityOptions.php
└── MaintainabilityRule.php
```

## Maintainability metrics

Maintainability metrics assess the difficulty of understanding, modifying, and testing code.

---

## Halstead Metrics

**Collector:** `HalsteadCollector`
**Provides:** `halstead.volume`, `halstead.difficulty`, `halstead.effort`, `halstead.bugs`, `halstead.time`
**Level:** Callable

### Base Components

| Component | Description      |
| --------- | ---------------- |
| n1        | Unique operators |
| n2        | Unique operands  |
| N1        | Total operators  |
| N2        | Total operands   |

### Derived Metrics

| Metric     | Formula                   | Description                  |
| ---------- | ------------------------- | ---------------------------- |
| Vocabulary | n1 + n2                   | Program vocabulary           |
| Length     | N1 + N2                   | Program length               |
| Volume     | Length x log2(Vocabulary) | Volume (bits)                |
| Difficulty | (n1/2) x (N2/n2)          | Comprehension difficulty     |
| Effort     | Volume x Difficulty       | Effort to understand         |
| Bugs       | Volume / 3000             | Estimated number of bugs     |
| Time       | Effort / 18               | Time to understand (seconds) |

### Qualimetrix Methodology

**Semantic approach** — only elements carrying semantic meaning are counted.

**Operators (n1, N1) — actions:**
- Arithmetic: `+`, `-`, `*`, `/`, `%`, `**`
- Logical: `&&`, `||`, `!`, `and`, `or`, `xor`
- Comparison: `==`, `===`, `!=`, `<`, `>`, `<=`, `>=`, `<=>`
- Assignment: `=`, `+=`, `-=`, `*=`, `/=`, `??=`
- Bitwise: `&`, `|`, `^`, `~`, `<<`, `>>`
- Control flow: `if`, `else`, `switch`, `case`, `for`, `foreach`, `while`, `do`, `return`, `throw`, `try`, `catch`
- Access: `->`, `::`, `??`
- Calls: function call, method call, `new`
- Arrays: `[]`, `array()`

**Operands (n2, N2) — data:**
- Variables: `$var`, `$this`
- Literals: numbers, strings, `true`, `false`, `null`
- Constants: `CONST_NAME`, `self::CONST`
- Identifiers: function names, method names, class names

**NOT counted (syntactic noise):**
- Semicolons: `;`
- Brackets: `(`, `)`, `{`, `}`, `[`, `]`
- Commas: `,`
- Type colons: `: int`

> **Note:** Modern PHP callables are interpreted semantically. A first-class
> callable capture (`target(...)`) is distinct from invocation and uses a
> capture operator; it does not add the captured target as an operand. PHP 8.5
> `clone($object, [...])` is the language `clone` operator, not a global
> function call. A promoted-parameter default belongs to its constructor;
> property and class-constant initializers do not create synthetic callable
> metrics.

### Differences from PDepend

PDepend uses a **token-oriented** approach, counting syntactic elements as operators.
This inflates the values:
- **Difficulty:** +75-220%
- **Effort:** +100-350%

Qualimetrix uses a **semantic interpretation** of Halstead's methodology (1977), measuring algorithmic complexity rather than syntactic density. The original Halstead paper counted all tokens, but was designed for languages (Fortran, PL/I) with minimal syntactic noise. Qualimetrix's approach excludes delimiters that carry no semantic meaning in C-family languages.

### Example

```php
function add(int $a, int $b): int
{
    return $a + $b;
}

// n1 = 2 (return, +)
// N1 = 2
// n2 = 3 (add, a, b)
// N2 = 4 (add, a, b, a, b — second usage)
// Vocabulary = 5
// Length = 6
// Volume ~ 13.9
// Difficulty ~ 1.3
// Effort ~ 18.7
```

---

## Maintainability Index

**Collector:** `MaintainabilityIndexCollector`
**Type:** `DerivedCollectorInterface`
**Requires:** `halstead.volume`, `ccn`
**Provides:** `mi`
**Level:** Method

### Formula

```
MI = 171 - 5.2xln(V) - 0.23xCCN - 16.2xln(LOC)
```

Where:
- V = Halstead Volume
- CCN = Cyclomatic Complexity
- LOC = Logical Lines of Code (LLOC -- statement count, not physical line count; estimated from Halstead volume when not available directly)

**Normalization:** The result is clamped to the 0-100 range.

### Interpretation

| MI     | Quality                   |
| ------ | ------------------------- |
| 85-100 | Excellent maintainability |
| 65-84  | Good maintainability      |
| 20-64  | Moderate maintainability  |
| 0-19   | Poor maintainability      |

### What Affects MI

**Lowers MI (worse):**
- High Halstead Volume (many operators/operands)
- High cyclomatic complexity (CCN)
- Large method size (LOC)

**Raises MI (better):**
- Simple methods with few operations
- Few branches (low CCN)
- Compact code

### Example

```php
// MI ~ 95 (excellent maintainability)
function add(int $a, int $b): int
{
    return $a + $b;
}

// MI ~ 40 (moderate maintainability)
function processComplexData(array $data): array
{
    $result = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                if ($subValue > 100) {
                    $result[$key][$subKey] = $subValue * 2;
                } else {
                    $result[$key][$subKey] = $subValue;
                }
            }
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}
// High Volume (many operations)
// High CCN (many branches)
// High LOC (many lines)
```

---

## Aggregation

### HalsteadCollector

```php
new MetricDefinition(
    name: 'halstead.volume', // same for others
    collectedAt: SymbolLevel::Callable,
    aggregations: [
        SymbolLevel::Class_->value => [Average, Max],
        SymbolLevel::Namespace_->value => [Average, Max],
        SymbolLevel::Project->value => [Average, Max],
    ],
)
```

### MaintainabilityIndexCollector

```php
new MetricDefinition(
    name: 'mi',
    collectedAt: SymbolLevel::Callable,
    aggregations: [
        SymbolLevel::Class_->value => [Average, Min],
        SymbolLevel::Namespace_->value => [Average, Min],
        SymbolLevel::Project->value => [Average, Min],
    ],
)
```

**Aggregated names:**
- `halstead.volume.avg`, `halstead.volume.max`
- `halstead.difficulty.avg`, `halstead.effort.max`
- `mi.avg`, `mi.min` (minimum = worst MI in a class/namespace)

---

## Usage

**Halstead Volume** — shows the amount of information in the code. High Volume means the code contains many operations and data.

**Halstead Difficulty** — estimates comprehension difficulty. High Difficulty means the code uses many unique operators and variables are reused frequently.

**Maintainability Index** — a composite metric for quick code quality assessment. Use MI as a primary indicator of problematic methods, then examine details through CCN, Volume, and other metrics.

## Reading set and Definition of Done

Read this README with:

- `src/Analysis/Evidence/Measurement/README.md` for collector and derived
  collector contracts;
- `src/Analysis/Finding/README.md` for rule, option, and channel contracts;
- `src/Core/README.md` for neutral path and symbol identifiers; and
- `tests/Analysis/Evidence/Maintainability/Unit/` for the Halstead algorithm,
  MI calculation and derived-collector ordering, and rule/default behavior.

Changes are complete when the seven flat leaf declarations retain collector
name `halstead`, all five `halstead.*` metrics, derived requirements exactly
`halstead`, `cyclomatic-complexity`, and `method-statement-count`, and the
`maintainability.index` ID, aliases, channels, option defaults, thresholds,
and 101 owned PHPUnit IDs. Do not add a `Contract/` directory without a named
external consumer.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
