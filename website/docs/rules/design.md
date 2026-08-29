# Design Rules

Design rules analyze the internal structure of your classes -- how focused they are, how inheritance is used, and whether classes have taken on too many responsibilities. These rules help you catch structural problems before they become expensive to fix.

---

## NOC -- Number of Children

**Rule ID:** `design.noc`

<!-- llms:skip-begin -->
### What it measures

NOC counts how many classes **directly extend** (inherit from) a given class.

For example, if 12 classes all write `extends BaseRepository`, then `BaseRepository` has NOC = 12.

**How to read the value:**

| NOC   | Interpretation                              |
| ----- | ------------------------------------------- |
| 0     | Leaf class (no subclasses)                  |
| 1--5  | Normal inheritance                          |
| 6--10 | Many subclasses -- review base class design |
| 10+   | Heavy base class -- consider composition    |

<!-- llms:skip-end -->

### Why it matters

A class with many children is a **high-impact change point**. Any modification to the parent class -- changing a method signature, altering behavior, or adding abstract methods -- affects every child class. The more children, the riskier any change becomes.

High NOC can also indicate:

- Over-reliance on inheritance instead of composition
- Potential violation of the Liskov Substitution Principle -- do all children truly behave like the parent?
- Difficulty refactoring -- changing the base class requires updating all subclasses

<!-- llms:skip-begin -->
### Thresholds

| Value  | Severity | Meaning                                              |
| ------ | -------- | ---------------------------------------------------- |
| 0--9   | OK       | Manageable number of subclasses                      |
| 10--14 | Warning  | Many children, changes will have wide impact         |
| 15+    | Error    | Too many children, consider using interfaces instead |
<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
abstract class BaseHandler
{
    abstract public function handle(Request $request): Response;
    protected function validate(Request $request): void { /* ... */ }
    protected function authorize(Request $request): void { /* ... */ }
}

// 15 handlers all extending BaseHandler -- NOC = 15 -> ERROR
class CreateUserHandler extends BaseHandler { /* ... */ }
class UpdateUserHandler extends BaseHandler { /* ... */ }
class DeleteUserHandler extends BaseHandler { /* ... */ }
class ListUsersHandler extends BaseHandler { /* ... */ }
class CreateOrderHandler extends BaseHandler { /* ... */ }
// ... 10 more handlers
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

- **Use an interface instead of a base class.** Each class implements the interface independently, so changing one does not affect the others.
- **Use the Strategy pattern.** Instead of many subclasses, parameterize behavior through constructor dependencies.
- **Move shared logic to a trait** if you still need common functionality without the tight coupling of inheritance.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.noc:
    warning: 12
    error: 20
```

```bash
bin/qmx check src/ --rule-opt="design.noc:warning=12"
bin/qmx check src/ --rule-opt="design.noc:error=20"
```

---

## Inheritance Depth

**Rule ID:** `design.inheritance`

<!-- llms:skip-begin -->
### What it measures

This rule counts how many levels of parent classes a class has. This metric is called the Depth of Inheritance Tree (DIT).

- `class A {}` -- DIT = 0 (no parent)
- `class B extends A {}` -- DIT = 1
- `class C extends B {}` -- DIT = 2
- `class D extends C {}` -- DIT = 3

**How to read the value:**

| DIT  | Interpretation                           |
| ---- | ---------------------------------------- |
| 0    | Root class (no parent)                   |
| 1--3 | Normal depth                             |
| 4--6 | Deep hierarchy -- may be fragile         |
| 6+   | Very deep -- fragile, hard to understand |

<!-- llms:skip-end -->

### Why it matters

When you read a class deep in an inheritance tree, you need to understand **all of its parent classes** to know what it does. Each level adds more implicit behavior: inherited methods, overridden methods, shared state, constructor side effects.

A class with DIT = 6 means you potentially need to read 7 classes to understand its full behavior. This is hard, error-prone, and makes the code resistant to change.

<!-- llms:skip-begin -->
### Thresholds

| DIT  | Severity | Meaning                                            |
| ---- | -------- | -------------------------------------------------- |
| 0--3 | OK       | Reasonable inheritance depth                       |
| 4--5 | Warning  | Getting deep, review whether inheritance is needed |
| 6+   | Error    | Too deep, likely a design problem                  |
<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
class BaseEntity {}                                      // DIT = 0
class TimestampedEntity extends BaseEntity {}             // DIT = 1
class SoftDeletableEntity extends TimestampedEntity {}    // DIT = 2
class AuditableEntity extends SoftDeletableEntity {}      // DIT = 3
class VersionedEntity extends AuditableEntity {}          // DIT = 4  -> Warning
class TenantEntity extends VersionedEntity {}             // DIT = 5  -> Warning
class UserEntity extends TenantEntity {}                  // DIT = 6  -> Error!
```

To understand `UserEntity`, you need to read all 7 classes in the chain.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

- **Prefer composition over inheritance.** Instead of extending a chain of base classes, inject behavior through dependencies:

    ```php
    class UserEntity
    {
        public function __construct(
            private Timestamps $timestamps,
            private SoftDelete $softDelete,
            private AuditLog $auditLog,
        ) {}
    }
    ```

- **Use interfaces + traits** for shared behavior that does not require deep hierarchies:

    ```php
    class UserEntity implements Timestamped, SoftDeletable
    {
        use TimestampsTrait;
        use SoftDeleteTrait;
    }
    ```

- **Flatten the hierarchy.** Ask whether each intermediate class is really necessary or if it can be merged with its parent or child.

!!! note
    Framework base classes (like Doctrine entities or Symfony controllers) count toward DIT. If your framework forces 2--3 levels of inheritance, adjust the thresholds accordingly.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.inheritance:
    warning: 5
    error: 7
```

```bash
bin/qmx check src/ --rule-opt="design.inheritance:warning=5"
bin/qmx check src/ --rule-opt="design.inheritance:error=7"
```

---

## Parameter Type Coverage

**Rule ID:** `design.type-coverage.param`

<!-- llms:skip-begin -->
### What it measures

The percentage of method and function parameters in a class that carry a type declaration.

Like the two rules below, this one uses **inverted thresholds**: lower values are worse. A warning is reported when coverage drops below the warning threshold, and an error when it drops below the error threshold. A class with no parameters at all has nothing to type and is never reported.

**How to read the value:**

| Coverage | Interpretation         |
| -------- | ---------------------- |
| 0--49%   | Low type coverage      |
| 50--79%  | Moderate type coverage |
| 80--100% | Good type coverage     |

!!! info "Three rules, not one"
    Parameters, return types and properties used to be three channels of a single `design.type-coverage` rule, tuned by one set of options. They are now three rules with a threshold, a suppression and a baseline entry each, because a codebase usually types them at different speeds — see the [migration note](../changelog.md).

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Thresholds

| Warning (below) | Error (below) |
| --------------- | ------------- |
| 80%             | 50%           |
<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
class LegacyService
{
    // $data has no type -> reduces parameter coverage
    public function process($data)
    {
        // ...
    }

    public function reset(int $attempts): void
    {
        // typed -- good
    }
}
// Parameter coverage: 50% (1 of 2 typed) -> Warning
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Add type declarations to the parameters:

```php
public function process(array $data)
{
    // ...
}
```

!!! tip
    Start by typing new code and add types to existing code during refactoring. PHP 8.0+ supports union types (`string|int`) and PHP 8.1+ intersection types (`Countable&Iterator`) for the awkward cases.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.type-coverage.param:
    warning: 80
    error: 50
```

```bash
bin/qmx check src/ --rule-opt="design.type-coverage.param:warning=90"
bin/qmx check src/ --param-type-coverage-error=60
```

---

## Return Type Coverage

**Rule ID:** `design.type-coverage.return`

<!-- llms:skip-begin -->
### What it measures

The percentage of methods and functions in a class that declare a return type. Inverted thresholds, exactly as for [parameter type coverage](#parameter-type-coverage): lower is worse.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Thresholds

| Warning (below) | Error (below) |
| --------------- | ------------- |
| 80%             | 50%           |
<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
class LegacyService
{
    // No return type -> reduces return coverage
    public function process(array $data)
    {
        // ...
    }

    public function reset(): void
    {
        // has a return type -- good
    }
}
// Return coverage: 50% (1 of 2 typed) -> Warning
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Declare what the method returns; use `void` when it returns nothing and `never` when it always throws or exits.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.type-coverage.return:
    warning: 80
    error: 50
```

```bash
bin/qmx check src/ --rule-opt="design.type-coverage.return:warning=90"
bin/qmx check src/ --return-type-coverage-error=60
```

---

## Property Type Coverage

**Rule ID:** `design.type-coverage.property`

<!-- llms:skip-begin -->
### What it measures

The percentage of declared properties in a class that carry a type. Inverted thresholds, exactly as for [parameter type coverage](#parameter-type-coverage): lower is worse.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Thresholds

| Warning (below) | Error (below) |
| --------------- | ------------- |
| 80%             | 50%           |
<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
class LegacyService
{
    private $cache;                 // no type -> reduces property coverage
    public bool $debug = true;      // typed -- good
}
// Property coverage: 50% (1 of 2 typed) -> Warning
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Type the properties, and prefer constructor promotion for the ones a constructor assigns:

```php
public function __construct(private readonly CacheInterface $cache) {}
```

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.type-coverage.property:
    warning: 80
    error: 50
```

```bash
bin/qmx check src/ --rule-opt="design.type-coverage.property:warning=90"
bin/qmx check src/ --property-type-coverage-error=60
```

---

## Data Class

**Rule ID:** `design.data-class`
**Severity:** Warning

<!-- llms:skip-begin -->
### What it measures

Detects classes whose public interface is mostly data access rather than behavior. WOC (Weight of Class, Lanza & Marinescu) is the share of the public interface that carries behavior: functional public methods divided by all public members -- public methods, accessors included, plus public properties. A Data Class combines a **low** WOC with a low WMC (Weighted Methods per Class): it exposes state and does little with it.

Intentional DTOs are excluded: readonly classes and promoted-properties-only classes are not flagged, along with interfaces, abstract classes, exception classes and classes without properties. Traits are not excluded: a trait carrying fields and their accessors is a Data Class spread across a reuse unit.

!!! info "How a method is classified"
    Accessor-ness is decided by **name**, not by body: `get*`, `is*`, `has*` and `set*` (and the bare `get`/`is`/`has`/`set`) count as data access, everything else counts as behavior. The body is never read, so a public method that only forwards to a collaborator -- a visitor's `enterNode()`, a routing table's `dispatch()` -- is behavior. WOC describes the shape of the public interface, not the weight of the work behind it. The constructor counts on neither side: Lanza & Marinescu define a functional method as neither accessor nor constructor. A class with no public members at all scores 100 and is never flagged. Only members declared by the class itself are counted — inherited and trait-imported ones are invisible.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Thresholds

| Metric          | Condition   | Default |
| --------------- | ----------- | ------- |
| WOC             | ≤ threshold | 33%     |
| WMC             | ≤ threshold | 10      |
| Minimum members | ≥           | 3       |

The bound is inclusive: exactly 33% is a finding. Both metric axes are upper bounds, so `@qmx-threshold design.data-class W E`
takes a WOC bound below the WMC bound without that being an ordering error.
Minimum members counts every declared method (accessors included) plus every declared property: a struct of public fields declares no methods at all and must still fall within the rule's reach.
<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Flagged: the whole public interface is data access, not readonly
class UserProfile
{
    private string $name;
    private string $email;
    private string $phone;

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function getPhone(): string { return $this->phone; }
    public function setPhone(string $phone): void { $this->phone = $phone; }
}

// Not flagged: intentional DTO (readonly)
readonly class UserDTO
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

1. **Encapsulate behavior** -- move operations that use this data into the class itself. Replacing a getter/setter pair with a method that expresses the operation raises WOC directly.
2. **Convert to a DTO** -- if the class is intentionally just data, make it `readonly` to signal intent.
3. **Merge with its consumer** -- if a class only holds data for another class, consider inlining it.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.data-class:
    woc_threshold: 33
    wmc_threshold: 10
    min_members: 3
    exclude_readonly: true
    exclude_promoted_only: true
```

---

## God Class

**Rule ID:** `design.god-class`
**Severity:** Warning (3+ criteria) / Error (all evaluable criteria)

<!-- llms:skip-begin -->
### What it measures

Detects God Classes -- overly complex, large classes with low cohesion. Uses Lanza & Marinescu's multi-criteria approach: a class is flagged when it matches at least `minCriteria` out of up to 4 evaluable criteria.

Criteria (4 total):

| Criterion | Condition   | Default | Source                          |
| --------- | ----------- | ------- | ------------------------------- |
| WMC       | ≥ threshold | 47      | Weighted Methods per Class      |
| LCOM4     | ≥ threshold | 3       | Lack of Cohesion                |
| TCC       | < threshold | 0.33    | Tight Class Cohesion (inverted) |
| Class LOC | ≥ threshold | 300     | Physical lines of code          |

Missing metrics reduce the evaluable count (e.g., if TCC is unavailable, 3 criteria are evaluated). If fewer criteria are evaluable than `minCriteria`, no violation is raised.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
// Flagged: high WMC, high LCOM, low TCC, large size
class ApplicationManager
{
    // 400+ LOC, 25 methods, handles:
    // - user authentication
    // - session management
    // - request routing
    // - response formatting
    // - error handling
    // - logging
    // - caching
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

1. **Extract classes by responsibility** -- identify method clusters that work on the same data and extract them into separate classes.
2. **Apply Single Responsibility Principle** -- each class should have one reason to change.
3. **Use composition** -- replace inheritance hierarchies with composed objects.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.god-class:
    wmc_threshold: 47
    lcom_threshold: 3
    tcc_threshold: 0.33
    class_loc_threshold: 300
    min_criteria: 3
    min_members: 3
    exclude_readonly: true
```
