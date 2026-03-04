# Size Metrics

Size metrics measure the amount of code, classes, and structural elements.

---

## LOC (Lines of Code)

**Collector:** `LocCollector`
**Provides:** `loc`, `lloc`, `cloc`
**Level:** File

### Metrics

| Metric | Description |
|--------|-------------|
| `loc` | Total number of lines in the file |
| `lloc` | Logical lines (excluding blank lines and comments) |
| `cloc` | Comment lines |

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
**Level:** File

### Metrics

| Metric | Description |
|--------|-------------|
| `classCount` | Named classes (not anonymous) |
| `interfaceCount` | Interfaces |
| `traitCount` | Traits |
| `enumCount` | Enums (PHP 8.1+) |

### What Is Counted

**Classes:**
- Named classes: `class UserService { }`
- Abstract classes: `abstract class BaseService { }`

**What is NOT counted:**
- Anonymous classes: `new class { }`

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

**Collector:** `PropertyCountCollector`
**Provides:** `propertyCount`, `propertyCountPublic`, `propertyCountProtected`, `propertyCountPrivate`, `propertyCountStatic`
**Level:** Class

### Metrics

| Metric | Description |
|--------|-------------|
| `propertyCount` | Total number of properties |
| `propertyCountPublic` | Public properties |
| `propertyCountProtected` | Protected properties |
| `propertyCountPrivate` | Private properties |
| `propertyCountStatic` | Static properties (any visibility) |

### What Is Counted

- Regular properties
- Promoted properties (PHP 8.0+)
- Static properties
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
    private static array $instances = [];       // propertyCountPrivate +1, propertyCountStatic +1

    public function __construct(
        public string $username,                 // propertyCountPublic +1 (promoted)
    ) {}
}

// propertyCount = 5
// propertyCountPublic = 2
// propertyCountProtected = 1
// propertyCountPrivate = 2
// propertyCountStatic = 1
```

### Interpretation

| Property Count | Quality |
|----------------|---------|
| 0-5 | Normal |
| 6-10 | Moderate complexity |
| 11-15 | Too much state (SRP?) |
| 15+ | Too many, refactoring required |

---

## Method Count

**Collector:** `MethodCountCollector`
**Provides:** `methodCount`, `methodCountTotal`, `methodCountPublic`, `methodCountProtected`, `methodCountPrivate`, `getterCount`, `setterCount`
**Level:** Class

### Metrics

| Metric | Description |
|--------|-------------|
| `methodCount` | Methods excluding getters/setters |
| `methodCountTotal` | All methods |
| `methodCountPublic` | Public methods (excluding getters/setters) |
| `methodCountProtected` | Protected methods (excluding getters/setters) |
| `methodCountPrivate` | Private methods (excluding getters/setters) |
| `getterCount` | Getters (`get*`, `is*`, `has*`) |
| `setterCount` | Setters (`set*`) |

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
