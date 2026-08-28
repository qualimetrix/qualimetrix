# Size Metrics

Size metrics measure the amount of code, classes, and structural elements.

---

## Method Statement Count

**Collector:** `MethodStatementCountCollector`
**Provides:** `size.method-statement-count`
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
**Provides:** `size.loc`, `size.lloc`, `size.cloc`
**Level:** File (physical project totals) and namespace source spans

### Metrics

| Metric      | Description                                        |
| ----------- | -------------------------------------------------- |
| `size.loc`  | Total number of lines in the file                  |
| `size.lloc` | Logical lines (excluding blank lines and comments) |
| `size.cloc` | Comment lines                                      |

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

> **Note:** `size.loc` is counted as the number of line breaks, so a trailing newline
> at end of file is counted as an extra line. This matches common line-count
> definitions and may differ by +1 per file from `wc -l`.

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
**Provides:** `size.class-count`, `size.abstract-class-count`, `size.interface-count`, `size.trait-count`, `size.enum-count`, `size.implementing-enum-count`, `size.function-count`
**Level:** File totals with namespace-owned structural contributions

### Metrics

| Metric                         | Description                                                                |
| ------------------------------ | -------------------------------------------------------------------------- |
| `size.class-count`             | Named classes (not anonymous)                                              |
| `size.interface-count`         | Interfaces                                                                 |
| `size.trait-count`             | Traits                                                                     |
| `size.enum-count`              | Enums (PHP 8.1+)                                                           |
| `size.implementing-enum-count` | Enums with an explicit `implements` clause (a subset of `size.enum-count`) |

### What Is Counted

**Classes:**
- Named classes: `class UserService { }`
- Abstract classes: `abstract class BaseService { }`

**What is NOT counted:**
- Anonymous classes: `new class { }`

Each namespace block contributes its own structural counts, including zero
counts for empty blocks. The physical file bag retains the whole-file totals used
at project level.

### Example

```php
<?php

interface PaymentGateway { }  // size.interface-count +1

abstract class AbstractGateway implements PaymentGateway { }  // size.class-count +1

class StripeGateway extends AbstractGateway { }  // size.class-count +1

trait LoggerTrait { }  // size.trait-count +1

enum Status { case Active; }  // size.enum-count +1

enum Currency implements PaymentGateway { case Eur; }  // size.enum-count +1, size.implementing-enum-count +1

$anon = new class { };  // NOT counted

// size.class-count = 2
// size.interface-count = 1
// size.trait-count = 1
// size.enum-count = 2
// size.implementing-enum-count = 1
```

> **Note:** `size.implementing-enum-count` counts only explicit `implements` clauses. The
> `UnitEnum` / `BackedEnum` interfaces every enum satisfies implicitly are not counted,
> since they carry no author intent. Abstractness consumes this split: a bare literal
> enumeration is neutral, an enum implementing a declared contract is concrete. See
> `src/Analysis/Evidence/Coupling/README.md`.

---

## Property Count

**Collector:** `MethodCountCollector`
**Provides:** `size.property-count`, `size.property-count.public`, `size.property-count.protected`, `size.property-count.private`, `size.promoted-property-count`
**Level:** Class

> **Note:** Property metrics are collected by `MethodCountCollector` in this capability, not by a separate collector.

### Metrics

| Metric                          | Description                          |
| ------------------------------- | ------------------------------------ |
| `size.property-count`           | Total number of properties           |
| `size.property-count.public`    | Public properties                    |
| `size.property-count.protected` | Protected properties                 |
| `size.property-count.private`   | Private properties                   |
| `size.promoted-property-count`  | Constructor promoted properties (8+) |

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
    public int $id;                              // size.property-count.public +1
    protected string $name;                      // size.property-count.protected +1
    private string $email;                       // size.property-count.private +1

    public function __construct(
        public string $username,                 // size.property-count.public +1, size.promoted-property-count +1
    ) {}
}

// size.property-count = 4
// size.property-count.public = 2
// size.property-count.protected = 1
// size.property-count.private = 1
// size.promoted-property-count = 1
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
**Provides:** `size.method-count`, `size.method-count.total`, `size.method-count.public`, `size.method-count.protected`, `size.method-count.private`, `size.getter-count`, `size.setter-count`
**Level:** Class

### Metrics

| Metric                        | Description                                   |
| ----------------------------- | --------------------------------------------- |
| `size.method-count`           | Methods excluding getters/setters             |
| `size.method-count.total`     | All methods                                   |
| `size.method-count.public`    | Public methods (excluding getters/setters)    |
| `size.method-count.protected` | Protected methods (excluding getters/setters) |
| `size.method-count.private`   | Private methods (excluding getters/setters)   |
| `size.getter-count`           | Getters (`get*`, `is*`, `has*`)               |
| `size.setter-count`           | Setters (`set*`)                              |

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
    public function getName(): string { }      // size.getter-count +1
    public function setName(string $name): void { }  // size.setter-count +1
    public function isActive(): bool { }       // size.getter-count +1
    public function hasPermission(): bool { }  // size.getter-count +1

    public function save(): void { }           // size.method-count.public +1
    protected function validate(): bool { }    // size.method-count.protected +1
    private function hash(): string { }        // size.method-count.private +1
}

// size.method-count.total = 7
// size.method-count = 3 (save, validate, hash)
// size.getter-count = 3
// size.setter-count = 1
```

---

## Aggregation

### LOC, Class Count

```php
new MetricDefinition(
    name: 'size.loc', // 'size.lloc', 'size.cloc', 'size.class-count', 'size.interface-count', ...
    collectedAt: SymbolLevel::File,
    aggregations: [
        SymbolLevel::Namespace_->value => [Sum, Average],
        SymbolLevel::Project->value => [Sum, Average],
    ],
)
```

**Aggregated names:** `size.loc.sum`, `size.loc.avg`, `size.class-count.sum`

### Property Count, Method Count

```php
new MetricDefinition(
    name: 'size.property-count', // 'size.method-count', ...
    collectedAt: SymbolLevel::Class_,
    aggregations: [
        SymbolLevel::Namespace_->value => [Sum, Average, Max],
        SymbolLevel::Project->value => [Sum, Average, Max],
    ],
)
```

**Aggregated names:** `size.property-count.sum`, `size.property-count.avg`, `size.method-count.max`

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

Rule IDs remain stable. `MethodCountCollector` publishes the method/property
metrics and the WOC input consumed by design policy, while WMC remains the
Measurement aggregation of callable CCN.

`design.woc` follows Lanza & Marinescu: functional public methods — neither accessor
nor constructor — over all other public members, which are public methods
(accessors included) plus public properties. Accessor-ness is decided by method
name in `MethodCountVisitor`, so a public method whose body only forwards to a
collaborator counts as functional. Only members declared by the class itself
are counted. A class with no public members scores 100 rather than being left
undefined. `size.method-count.total` moved to `MetricName::SIZE_METHOD_COUNT_TOTAL`
so Design can require it without importing a Size constant.

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
- `size.class-count`, `size.method-count`, and `size.property-count` and their
  metric keys retain their existing behaviour. `design.woc` is the Lanza & Marinescu
  ratio described above; WMC stays the Measurement aggregation of callable CCN.
- The seven owned tests remain discovered and cover anonymous-class exclusion,
  LOC, statement, method, property, and class counts.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
