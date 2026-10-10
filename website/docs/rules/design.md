# Design Rules

Design rules analyze the internal structure of your classes -- how focused they are, how inheritance is used, and whether classes have taken on too many responsibilities. These rules help you catch structural problems before they become expensive to fix.

---

## NOC -- Number of Children

**Rule ID:** `design.noc`

**Judged metric:** `design.noc`

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

<!-- llms:skip-begin -->
### Implementation notes

NOC counts distinct logical child names from the analysed dependency graph,
not `extends` occurrences. A subclass declared twice counts once. Every named
class declaration, including an abstract class or a root with no edges,
receives a count, including 0. Interfaces, traits and enums receive no
`design.noc`; `interface B extends A` does not make `B` a child of `A`.
Anonymous classes do not contribute their own parent edges to their named
owner. Canonical self edges are absent from the graph NOC reads.

NOC's logical-name policy is independent of whether a class has a numeric DIT.
A class whose ancestry loops still receives NOC. Namespace and project samples
retain the existing exact-declaration aggregation: duplicate parent declarations
publish the same logical child count on each declaration.

!!! info "Deviation from original spec"
    Namespace and project NOC aggregates sample physical class declarations,
    repeating the logical child count for each declaration of a name. Their
    sums, counts and averages are declaration-weighted, rather than counts of
    logical graph nodes or inheritance edges.

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

For a simple pass/fail threshold instead of separate warning/error levels
(`threshold` cannot be combined with `warning` or `error` — mixing them is a
configuration error and the run stops with exit code 3):

```yaml
rules:
  design.noc:
    threshold: 12   # warning=12, error=12 → all violations are errors
```

```bash
bin/qmx check src/ --rule-opt="design.noc:threshold=12"
```

---

## Inheritance Depth

**Rule ID:** `design.dit`

**Judged metric:** `design.dit`

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

<!-- llms:skip-begin -->

### Implementation notes

DIT is resolved once from the named-class declaration facts and declaration
`extends` edges, including abstract classes and roots with depth 0. Each exact
declaration keeps its own parent, even when the same name is declared more than
once in a file. Interfaces, traits, enums and anonymous classes receive no DIT.
An anonymous class's own parent never becomes its enclosing class's parent.

Registered PHP builtin parents are followed transitively through a static
hierarchy, even without a Composer install: a class extending
`\RuntimeException` has DIT 2, and one extending `\ArgumentCountError` has DIT
3. Names such as `App\Exception` and `App\Error` are project names, not PHP
builtin classes. Unregistered extension classes need readable source evidence;
the analysing machine's loaded extensions do not change the measurement.

Other parents outside the analysed path are followed by reading their files
through **your** project's Composer source map. Analysed code is never loaded.
The 64-visit limit applies to each consecutive external segment. If its last
allowed parent link reaches an already analysed name, graph facts continue the
chain without an extra external read; an external name still beyond the limit
leaves a floor.
The result separates three outcomes:

- **Exact:** the chain reaches a root; `design.dit-unresolved` is 0.
- **Floor:** a source cannot be placed or read, no install supplies it, or the
  external walk reaches its 64-step cap. `design.dit` retains the known lower
  bound and `design.dit-unresolved` is 1. Findings and recommendations say
  "DIT is at least N" when this lower bound reaches a configured threshold.
- **Loop:** the chain reaches a canonical inheritance cycle, including a
  class extending itself. No numeric `design.dit` is published, and
  `design.dit-unresolved` is 1. The class and its descendants have no numeric
  DIT finding. A loop length is not an inheritance depth.

A parent name can have several declarations, including a root alongside one
with a parent. All alternatives participate: finite depths merge by maximum,
any floor makes the answer a floor, and any loop removes numeric depth. This
policy is independent of encounter order. Each class record and finding retains
its exact declaration identity. This also applies when a chain leaves the
analysed path and returns: all known declarations of the reached name participate,
rather than only the source body selected by Composer. The known external prefix
is counted once, and a mixed cycle still removes numeric depth.

DIT aggregates use the published numeric values, including roots and floors; loop declarations contribute no numeric
sample.

The enabled `design.dit` rule writes at most one warning per execution,
distinguishing floors, loops, or both.
It names `design.dit-unresolved` and up to five distinct cause/class pairs,
including the actual unread boundary or cycle, then counts any remaining pairs.
A missing Composer installation is distinguished from a class missing in an
existing installation. Disabling the rule silences the warning while metrics
remain collected. Warnings go to the error stream; `-q` and
`--log-level=error` silence them, and machine-format payloads remain parseable.

<!-- llms:skip-end -->

### Configuration

```yaml
# qmx.yaml
rules:
  design.dit:
    warning: 5
    error: 7
```

```bash
bin/qmx check src/ --rule-opt="design.dit:warning=5"
bin/qmx check src/ --rule-opt="design.dit:error=7"
```

For a simple pass/fail threshold instead of separate warning/error levels
(`threshold` cannot be combined with `warning` or `error` — mixing them is a
configuration error and the run stops with exit code 3):

```yaml
rules:
  design.dit:
    threshold: 5   # warning=5, error=5 → all violations are errors
```

```bash
bin/qmx check src/ --rule-opt="design.dit:threshold=5"
```

---

## Parameter Type Coverage

**Rule ID:** `design.type-coverage.param`

**Judged metric:** `design.type-coverage.param`

<!-- llms:skip-begin -->
### What it measures

The percentage of method and function parameters in a class that carry a type declaration.

The raw parameter, return and property typed/total counters retain measured 0.
If the combined typeable total is zero, percentage metrics are absent rather
than 100%; a positive total with zero typed declarations publishes 0%. Builtin
[typing health](../reference/health-scores.md) uses the actual summed denominator.

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

For a simple pass/fail threshold instead of separate warning/error levels
(`threshold` cannot be combined with `warning` or `error` — mixing them is a
configuration error and the run stops with exit code 3):

```yaml
rules:
  design.type-coverage.param:
    threshold: 80   # warning=80, error=80 → all violations below 80% are errors
```

```bash
bin/qmx check src/ --rule-opt="design.type-coverage.param:threshold=80"
```

---

## Return Type Coverage

**Rule ID:** `design.type-coverage.return`

**Judged metric:** `design.type-coverage.return`

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

For a simple pass/fail threshold instead of separate warning/error levels
(`threshold` cannot be combined with `warning` or `error` — mixing them is a
configuration error and the run stops with exit code 3):

```yaml
rules:
  design.type-coverage.return:
    threshold: 80   # warning=80, error=80 → all violations below 80% are errors
```

```bash
bin/qmx check src/ --rule-opt="design.type-coverage.return:threshold=80"
```

---

## Property Type Coverage

**Rule ID:** `design.type-coverage.property`

**Judged metric:** `design.type-coverage.property`

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

For a simple pass/fail threshold instead of separate warning/error levels
(`threshold` cannot be combined with `warning` or `error` — mixing them is a
configuration error and the run stops with exit code 3):

```yaml
rules:
  design.type-coverage.property:
    threshold: 80   # warning=80, error=80 → all violations below 80% are errors
```

```bash
bin/qmx check src/ --rule-opt="design.type-coverage.property:threshold=80"
```

---

## Data Class

**Rule ID:** `design.data-class`

**Judged metric:** `design.woc`
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

Exception exclusion follows resolved ancestry to PHP's `Throwable`, including
project descendants and readable external parents; it does not match a parent's
short name. `design.is-exception` is 1 for proven exceptions, 0 for proven
non-exceptions, and absent for unknown ancestry. Duplicate parent alternatives
must agree; a known depth can still have unknown exception status.
With `exclude_exceptions: true` (the default), both proven exceptions and
unknown status are skipped. With `false`, the rule judges the remaining WOC,
WMC and member criteria without consulting exception classification.

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
    exclude_exceptions: true   # skip proven exceptions and unknown ancestry
```

```bash
bin/qmx check src/ --rule-opt="design.data-class:exclude_exceptions=false"
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
    min_methods: 3   # default: classes with fewer methods are never flagged
    exclude_readonly: true
```

```bash
bin/qmx check src/ --rule-opt="design.god-class:min_methods=5"
```
