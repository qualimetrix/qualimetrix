# Size Metrics

Size metrics measure the amount of code, classes, and structural elements.

---

## Method Statement Count

**Collector:** `MethodStatementCountCollector`
**Provides:** `methodStatementCount`
**Level:** Method/function/closure, aggregated to class, namespace, and project

Counts executable statements plus control-flow statements and clauses. Container
statements and their contents are both counted; nested callable bodies belong to
their own symbol and are excluded from the enclosing callable. Arrow functions
own one synthetic statement. Declarations, empty statements, comments, and blank
lines are excluded, so the metric is independent of formatting.

Maintainability Index uses this metric as its method-size input. See
[ADR 0020](../../../docs/adr/0020-method-size-and-npath-semantics.md).

---

## LOC (Lines of Code)

**Collector:** `LocCollector`
**Provides:** `loc`, `lloc`, `cloc`
**Level:** File (physical project totals) and namespace source spans

### Metrics

| Metric | Description                                        |
| ------ | -------------------------------------------------- |
| `loc`  | Total number of lines in the file                  |
| `lloc` | Logical lines (excluding blank lines and comments) |
| `cloc` | Comment lines                                      |

### What Is Counted

**LOC (Lines of Code):**
- All lines in the file, including blank lines

**LLOC (Logical Lines of Code):**
- Lines containing code
- Excluding blank lines
- Excluding lines with only comments

**CLOC (Comment Lines of Code):**
- Single-line comments: `//`
- Multi-line comments: `/* ... */`
- DocBlocks: `/** ... */`

For files with namespace declarations, namespace LOC/LLOC/CLOC use each
`Namespace_` AST node's inclusive source span. Declarations before the first
namespace remain file-owned, so project totals always describe the physical file
exactly once. A file without namespace declarations contributes its full span to
the global namespace.

### Example

```php
<?php
// LOC = 10
// LLOC = 4
// CLOC = 3

/**
 * Calculator class
 */
class Calculator  // LLOC +1
{
    public function add(int $a, int $b): int  // LLOC +1
    {
        // Add two numbers  // CLOC +1
        return $a + $b;  // LLOC +1
    }
}  // LLOC +1
```

---

## Class Count

**Collector:** `ClassCountCollector`
**Provides:** `classCount`, `interfaceCount`, `traitCount`, `enumCount`
**Level:** File totals with namespace-owned structural contributions

### Metrics

| Metric           | Description                   |
| ---------------- | ----------------------------- |
| `classCount`     | Named classes (not anonymous) |
| `interfaceCount` | Interfaces                    |
| `traitCount`     | Traits                        |
| `enumCount`      | Enums (PHP 8.1+)              |

### What Is Counted

**Classes:**
- Named classes: `class UserService { }`
- Abstract classes: `abstract class BaseService { }`

**What is NOT counted:**
- Anonymous classes: `new class { }`

Each namespace block contributes its own six structural counts, including zero
counts for empty blocks. The physical file bag retains the whole-file totals used
at project level.

### Example

```php
<?php

interface PaymentGateway { }  // interfaceCount +1

abstract class AbstractGateway implements PaymentGateway { }  // classCount +1

class StripeGateway extends AbstractGateway { }  // classCount +1

trait LoggerTrait { }  // traitCount +1

enum Status { case Active; }  // enumCount +1

$anon = new class { };  // NOT counted

// classCount = 2
// interfaceCount = 1
// traitCount = 1
// enumCount = 1
```

---

## Property Count

**Collector:** `MethodCountCollector`
**Provides:** `propertyCount`, `propertyCountPublic`, `propertyCountProtected`, `propertyCountPrivate`, `promotedPropertyCount`
**Level:** Class

> **Note:** Property metrics are collected by `MethodCountCollector` in this capability, not by a separate collector.

### Metrics

| Metric                   | Description                          |
| ------------------------ | ------------------------------------ |
| `propertyCount`          | Total number of properties           |
| `propertyCountPublic`    | Public properties                    |
| `propertyCountProtected` | Protected properties                 |
| `propertyCountPrivate`   | Private properties                   |
| `promotedPropertyCount`  | Constructor promoted properties (8+) |

### What Is Counted

- Regular properties
- Promoted properties (PHP 8.0+)
- Typed and untyped properties

**What is NOT counted:**
- Dynamic properties: `$this->dynamicProp = 1`
- Constants: `const VERSION = '1.0'`

### Example

```php
class User
{
    public int $id;                              // propertyCountPublic +1
    protected string $name;                      // propertyCountProtected +1
    private string $email;                       // propertyCountPrivate +1

    public function __construct(
        public string $username,                 // propertyCountPublic +1, promotedPropertyCount +1
    ) {}
}

// propertyCount = 4
// propertyCountPublic = 2
// propertyCountProtected = 1
// propertyCountPrivate = 1
// promotedPropertyCount = 1
```

### Interpretation

| Property Count | Quality                        |
| -------------- | ------------------------------ |
| 0-5            | Normal                         |
| 6-10           | Moderate complexity            |
| 11-15          | Too much state (SRP?)          |
| 15+            | Too many, refactoring required |

---

## Method Count

**Collector:** `MethodCountCollector`
**Provides:** `methodCount`, `methodCountTotal`, `methodCountPublic`, `methodCountProtected`, `methodCountPrivate`, `getterCount`, `setterCount`
**Level:** Class

### Metrics

| Metric                 | Description                                   |
| ---------------------- | --------------------------------------------- |
| `methodCount`          | Methods excluding getters/setters             |
| `methodCountTotal`     | All methods                                   |
| `methodCountPublic`    | Public methods (excluding getters/setters)    |
| `methodCountProtected` | Protected methods (excluding getters/setters) |
| `methodCountPrivate`   | Private methods (excluding getters/setters)   |
| `getterCount`          | Getters (`get*`, `is*`, `has*`)               |
| `setterCount`          | Setters (`set*`)                              |

### Getter/Setter Detection

**Getters** (case-insensitive):
- `get*` — `getName()`, `getValue()`
- `is*` — `isActive()`, `isValid()`
- `has*` — `hasChildren()`, `hasErrors()`

**Setters** (case-insensitive):
- `set*` — `setName()`, `setValue()`

### Example

```php
class User
{
    public function getName(): string { }      // getterCount +1
    public function setName(string $name): void { }  // setterCount +1
    public function isActive(): bool { }       // getterCount +1
    public function hasPermission(): bool { }  // getterCount +1

    public function save(): void { }           // methodCountPublic +1
    protected function validate(): bool { }    // methodCountProtected +1
    private function hash(): string { }        // methodCountPrivate +1
}

// methodCountTotal = 7
// methodCount = 3 (save, validate, hash)
// getterCount = 3
// setterCount = 1
```

---

## Aggregation

### LOC, Class Count

```php
new MetricDefinition(
    name: 'loc', // 'lloc', 'cloc', 'classCount', 'interfaceCount', ...
    collectedAt: SymbolLevel::File,
    aggregations: [
        SymbolLevel::Namespace_->value => [Sum, Average],
        SymbolLevel::Project->value => [Sum, Average],
    ],
)
```

**Aggregated names:** `loc.sum`, `loc.avg`, `classCount.sum`

### Property Count, Method Count

```php
new MetricDefinition(
    name: 'propertyCount', // 'methodCount', ...
    collectedAt: SymbolLevel::Class_,
    aggregations: [
        SymbolLevel::Namespace_->value => [Sum, Average, Max],
        SymbolLevel::Project->value => [Sum, Average, Max],
    ],
)
```

**Aggregated names:** `propertyCount.sum`, `propertyCount.avg`, `methodCount.max`

---

## Usage

**LOC** — basic metric for measuring codebase size. Use it to track project growth.

**LLOC** — a more precise metric that excludes "noise" (blank lines, comments). Use it to assess the volume of logic.

**Class Count** — number of classes in the project. Useful for understanding the architecture and code organization.

**Property Count** — indicator of class state complexity. A high value may indicate an SRP violation.

**Method Count** — shows the class API surface. Many methods (especially public) may indicate overly broad responsibility.

---

## Capability Boundary

`Analysis\Evidence\Size` owns size and cardinality evidence: line, class,
method-statement, method, and property counts, together with their rule options
and rules. It is a private leaf capability; it publishes no `Contract/`
namespace. Cross-capability consumers use Measurement's metric and repository
contracts rather than these implementations.

Metric keys and rule IDs remain stable. In particular, `MethodCountCollector`
continues to publish the method/property metrics and the WOC input consumed by
design policy, while WMC remains the Measurement aggregation of callable CCN.

## Structure

```text
Size/
├── ClassCountCollector.php
├── ClassCountVisitor.php
├── LocCollector.php
├── LocVisitor.php
├── MethodStatementCountCollector.php
├── MethodStatementCountVisitor.php
├── MethodCountCollector.php
├── MethodCountMetrics.php
├── MethodCountVisitor.php
├── ClassCountOptions.php
├── ClassCountRule.php
├── MethodCountOptions.php
├── MethodCountRule.php
├── PropertyCountOptions.php
└── PropertyCountRule.php
```

Collectors keep visitor state per file and reset it between files. Named classes
only are counted; anonymous classes never create class, method, or property
evidence.

## Test Scope

Owned tests live in `tests/Analysis/Evidence/Size/Unit/`. They cover the three
collectors and the three size rules, including LOC, statements, named versus
anonymous classes, methods, properties, thresholds, and property exclusions.

## Definition of Done

- All 15 Size declarations remain in this flat leaf without a `Contract/` or
  role-based subdirectory.
- `size.class-count`, `size.method-count`, and `size.property-count`, their
  metric keys, and WOC/WMC inputs retain their existing behaviour.
- The seven owned tests remain discovered and cover anonymous-class exclusion,
  LOC, statement, method, property, and class counts.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
