# Design Rules

Design rules analyze the internal structure of your classes -- how focused they are, how inheritance is used, and whether classes have taken on too many responsibilities. These rules help you catch structural problems before they become expensive to fix.

---

## LCOM -- Lack of Cohesion of Methods

**Rule ID:** `design.lcom`

<!-- llms:skip-begin -->
### What it measures

LCOM answers the question: "Is this class doing one thing, or several unrelated things?"

It works by looking at which properties (fields) each method uses. If two methods use the same property, they are considered related. LCOM counts how many **disconnected groups** of related methods exist in the class.

- **LCOM = 1** -- all methods are connected. The class is cohesive and focused.
- **LCOM = 2** -- there are two groups of methods that share no properties between them. The class might be doing two separate jobs.
- **LCOM = 5** -- five unrelated groups. This class is almost certainly doing too many things.

Think of it like a team: if all team members work on the same project, the team is cohesive (LCOM = 1). If half the team works on project A and the other half on project B with no overlap, the team should probably be split in two (LCOM = 2).

**How to read the value:**

| LCOM | Interpretation                                  |
| ---- | ----------------------------------------------- |
| 1    | Cohesive -- single responsibility               |
| 2--3 | Moderate, may have distinct concerns            |
| 4--5 | Low cohesion -- consider splitting              |
| 6+   | Very low cohesion -- class does too many things |

<!-- llms:skip-end -->

### Thresholds

| Value | Severity | Meaning                                      |
| ----- | -------- | -------------------------------------------- |
| 1--2  | OK       | Cohesive class, all methods work together    |
| 3--4  | Warning  | Class may have multiple responsibilities     |
| 5+    | Error    | Class clearly does too much, should be split |

<!-- llms:skip-begin -->
### Example

This class has low cohesion -- two groups of methods working on unrelated data:

```php
class UserManager
{
    private string $name;
    private string $email;
    private float $balance;
    private array $transactions;

    // Group 1: works with $name and $email
    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function updateProfile(string $name, string $email): void
    {
        $this->name = $name;
        $this->email = $email;
    }

    // Group 2: works with $balance and $transactions
    public function getBalance(): float { return $this->balance; }
    public function addTransaction(float $amount): void
    {
        $this->transactions[] = $amount;
        $this->balance += $amount;
    }
    public function getTransactionHistory(): array { return $this->transactions; }
}
```

This class has LCOM = 2. The profile methods and the financial methods use completely different properties.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

- **Split the class** into smaller classes, one per responsibility. In the example: `UserProfile` for name/email, `UserWallet` for balance/transactions.
- **Look for natural boundaries.** If methods cluster around different sets of properties, those clusters are your new classes.
- **Use composition.** The original class can delegate to the new focused classes if needed.

!!! tip
    Readonly classes (DTOs, value objects) are excluded by default because their properties are typically set once in the constructor and read individually -- this naturally produces high LCOM values even though the class design is fine. You can control this with the `excludeReadonly` option.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Implementation notes

Qualimetrix uses the **LCOM4** algorithm (Hitz & Montazeri, 1995), which is graph-based:

1. Build a graph where each instance method is a node (static methods are excluded)
2. Add an edge between two methods if they share a property (`$this->property`) or one calls the other (`$this->method()`)
3. LCOM4 = the number of **connected components** in this graph

This is the most widely accepted LCOM variant in modern literature. A value of 1 means all methods are interconnected — the class is cohesive.

!!! info "Deviation from original spec"
    The original LCOM4 (Hitz & Montazeri, 1995) defines edges only through shared property access. Qualimetrix extends this with method-call edges (`$this->method()`), following the standard approach used by modern tools (SonarQube, JDepend). Without this extension, a well-factored class that accesses properties through getters would appear to have poor cohesion.

!!! note "Comparing with other tools"
    phpmetrics uses the **Henderson-Sellers LCOM** formula, which produces values on a completely different scale (0.0 to 1.0+). These values are **not comparable** with Qualimetrix's LCOM4. A class that scores LCOM=2 in Qualimetrix might show LCOM=0.8 in phpmetrics — both indicate low cohesion, but the numbers mean different things.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.lcom:
    warning: 4
    error: 6
    min_methods: 5
    exclude_readonly: true
```

```bash
bin/qmx check src/ --rule-opt="design.lcom:warning=4"
bin/qmx check src/ --rule-opt="design.lcom:error=6"
bin/qmx check src/ --rule-opt="design.lcom:min_methods=5"
bin/qmx check src/ --rule-opt="design.lcom:exclude_readonly=false"
```

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

### Thresholds

| Value  | Severity | Meaning                                              |
| ------ | -------- | ---------------------------------------------------- |
| 0--9   | OK       | Manageable number of subclasses                      |
| 10--14 | Warning  | Many children, changes will have wide impact         |
| 15+    | Error    | Too many children, consider using interfaces instead |

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

### Thresholds

| DIT  | Severity | Meaning                                            |
| ---- | -------- | -------------------------------------------------- |
| 0--3 | OK       | Reasonable inheritance depth                       |
| 4--5 | Warning  | Getting deep, review whether inheritance is needed |
| 6+   | Error    | Too deep, likely a design problem                  |

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

## Type Coverage

**Rule ID:** `design.type-coverage`

<!-- llms:skip-begin -->
### What it measures

Checks the percentage of type declarations in a class. Produces up to three violations per class:

- **Parameter type coverage** -- percentage of method parameters with type declarations
- **Return type coverage** -- percentage of methods with return type declarations
- **Property type coverage** -- percentage of properties with type declarations

Unlike most rules, this one uses **inverted thresholds**: lower values are worse. A warning is reported when coverage drops below the warning threshold, and an error when it drops below the error threshold.

**How to read the value:**

| Coverage | Interpretation         |
| -------- | ---------------------- |
| 0--49%   | Low type coverage      |
| 50--79%  | Moderate type coverage |
| 80--100% | Good type coverage     |

<!-- llms:skip-end -->

### Thresholds

| Aspect    | Warning (below) | Error (below) |
| --------- | --------------- | ------------- |
| Parameter | 80%             | 50%           |
| Return    | 80%             | 50%           |
| Property  | 80%             | 50%           |

<!-- llms:skip-begin -->
### Example

```php
class LegacyService
{
    private $cache;       // no type -> reduces property coverage
    public $debug = true; // no type -> reduces property coverage

    // No return type -> reduces return coverage
    // $data has no type -> reduces parameter coverage
    public function process($data)
    {
        // ...
    }

    public function reset(): void
    {
        // has return type -- good
    }
}
// Parameter coverage: 0% (0 of 1 typed) -> Error
// Return coverage: 50% (1 of 2 typed) -> Warning
// Property coverage: 0% (0 of 2 typed) -> Error
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

Add type declarations:

```php
class LegacyService
{
    private CacheInterface $cache;
    public bool $debug = true;

    public function process(array $data): Result
    {
        // ...
    }

    public function reset(): void { /* ... */ }
}
```

!!! tip
    Start by adding types to new code and gradually add types to existing code during refactoring. PHP 8.0+ supports union types (`string|int`) and PHP 8.1+ supports intersection types (`Countable&Iterator`) for complex cases.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.type-coverage:
    param_warning: 80
    param_error: 50
    return_warning: 80
    return_error: 50
    property_warning: 80
    property_error: 50
```

```bash
bin/qmx check src/ --rule-opt="design.type-coverage:param_warning=90"
bin/qmx check src/ --rule-opt="design.type-coverage:param_error=60"
```
