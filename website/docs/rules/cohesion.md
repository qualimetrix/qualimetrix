# Cohesion Rules

Cohesion rules measure how well the methods inside a class work together. A cohesive class has methods that operate on the same data -- they share properties and pursue a single purpose. Low cohesion is a strong signal that a class is doing too many things and should be split.

See also: [LCOM (Lack of Cohesion of Methods)](#lcom----lack-of-cohesion-of-methods) -- a complementary cohesion metric that counts disconnected method groups.

---

## LCOM -- Lack of Cohesion of Methods

**Rule ID:** `cohesion.lcom`

**Judged metric:** `cohesion.lcom`

LCOM4 counts disconnected groups of related instance methods. A value of `1`
means that the class is cohesive; values above `1` indicate independent groups
of responsibilities that may be split. Qualimetrix connects methods that share
a property or call one another through `$this->method()`, `self::method()` or `static::method()`
(or refer to one another through a callable reference), excludes static,
constructor and destructor methods, and groups only uncalled stateless constant methods
into one virtual node. A called method remains its own graph vertex. Private helpers
that only the constructor or destructor reaches are initialization, not behavior, and
leave the graph with them (see the deviation note below).

The default warning/error thresholds are `3` and `5`, inclusive at equality. Readonly classes are
excluded by default, and classes must have at least three graph-eligible methods after exclusions. You can also
exclude interface-mandated methods from the graph:

```yaml
rules:
  cohesion.lcom:
    warning: 3
    error: 5
    exclude_readonly: true
    min_methods: 3
    exclude_methods: [getName, getDescription]
```

`exclude_methods` requires a YAML sequence of strings. Use
`exclude_methods: [getName]`; a scalar string or CSV is refused. String items
retain their authored spelling and match method names case-insensitively.

For a simple pass/fail threshold instead of separate warning/error levels
(`threshold` cannot be combined with `warning` or `error` — mixing them is a
configuration error and the run stops with exit code 3):

```yaml
rules:
  cohesion.lcom:
    threshold: 3   # warning=3, error=3 → all violations are errors
```

```bash
bin/qmx check src/ --rule-opt="cohesion.lcom:threshold=3"
```

### Implementation notes

Qualimetrix implements the graph-based LCOM4 algorithm. Instance methods are
graph nodes, and the metric is the number of connected components. An empty
graph has no LCOM value. `cohesion.lcom-method-count` exposes its eligible
method population; `cohesion.unread-trait-count` exposes unresolved trait uses. Resolvable
trait methods participate after aliases, visibility changes, `insteadof`
selections and class overrides; an unread trait is reported, not assumed empty.

!!! info "Deviation from original spec"
    [Hitz and Montazeri](https://www.researchgate.net/publication/238729882_Measuring_coupling_and_cohesion_in_object-oriented_systems)
    define connections through shared instance variables **or method calls**.
    Qualimetrix follows those edge kinds with case-insensitive PHP method names
    and effective trait methods. Its scope adaptation concerns the method
    population and empty graphs, which publish no value. Stateless
    constant methods with no property access or instance method calls are
    grouped into one virtual node only when uncalled; this prevents interface-mandated metadata
    methods such as `getName()` and `getDescription()` from each inflating the
    number of disconnected components. A method whose body is a single
    `return` of a class constant qualifies regardless of which class the
    constant belongs to -- `self::X`, `static::X`, `parent::X`, an imported
    class's `Foo::X`, and an enum case such as `Suit::Hearts` all read no
    instance state, so they are all recognized as constant expressions. A
    dynamic class or constant name (`$var::X`, `Foo::{$name}`) is resolved at
    run time and is not.
    Constructors and destructors (`__construct`, `__destruct`) are excluded
    from the LCOM method population as a scope adaptation. TCC/LCC also exclude
    them, as Bieman and Kang specify. Initialization is not a behavioral group;
    constructor edges must not bridge unrelated responsibilities.

    A reference to an own method is an edge, counted as a deferred call: a
    first-class callable (`$this->m(...)`), a callable array `[X, 'm']`,
    `Closure::fromCallable([X, 'm'])`, `new ReflectionMethod(X, 'm')`, or a string
    `'self::m'`, `'static::m'` or `'Own::m'`, where `X` is `$this`, `self::class`,
    `static::class`, `Own::class`, `$this::class`, `__CLASS__`, `get_class($this)`
    or `get_called_class()`. Direct calls are also recognised through `Own::m()`,
    `$this::m()`, `$this->{'m'}()` and `self::{'m'}()`. The original definition
    knows only direct calls; PHP code registers behavior through callables
    (route callbacks, event listeners), and ignoring them would leave the
    referenced methods as isolated components.

    The private instance methods that only the constructor or destructor reaches
    through such calls, directly or through other such helpers, are initialization
    helpers and are excluded as well. Otherwise each would form its own
    disconnected component, since the constructor edge that would join it to the
    class is removed. A method stays in the graph when any other method reaches
    it: public and protected methods are never helpers, even when the constructor
    calls them, nor are static methods or `exclude_methods` entries, and the
    callees of those are ordinary. A callable reference or closure created in the
    constructor or in a helper runs after construction, so the method it names
    stays in the graph; every closure counts this way, even one invoked at once
    (`array_map`), which keeps its callees in the graph. A helper that hands state
    on registers behavior and stays as well: it touches a property inside a
    closure, arrow function or nested function (by name or dynamically), binds a
    property or its element or member by reference (on either side of `=&`, as an
    array element or as a `foreach` value), or is a generator. Removing a helper
    can raise LCOM when it was the only bridge between two property groups, and
    can empty the graph, which then publishes no value. TCC/LCC are unchanged.

    No method leaves the graph when the call map may be incomplete: when a used
    trait was not read (`cohesion.unread-trait-count` above zero) or when a
    non-literal name appears in any of the forms above (`$this->$name()`,
    `self::$name()`, `$this::{$x}()`, `[$this, $name]`, `new ReflectionMethod($this, $name)`).
    Not recorded, so a helper used only that way counts as initialization: calls on
    another instance (`$other->m()`, including an alias `$self = $this`), the
    nullsafe `$this?->m()`, `parent::m()`, own names reached through other strings
    or reflection lookups, a property passed by reference as an argument, and an
    object taken from a property and captured by value in a static closure.
    `call_user_func($this)` reaches only the public `__invoke`, an ordinary root.

!!! note "Comparing with other tools"
    phpmetrics uses the Henderson-Sellers LCOM formula, whose values are on a
    different scale. Its values are not directly comparable with Qualimetrix's
    LCOM4 values.

---

## TCC -- Tight Class Cohesion

**Metric ID:** `cohesion.tcc`

<!-- llms:skip-begin -->
### What it measures

TCC measures how connected the public methods of a class are through shared property access. If two public methods both read or write the same property (`$this->property`), they are considered **directly connected**.

`TCC = NDC / NP`

Where:

- **NDC** = Number of Directly Connected method pairs (pairs of public methods that share at least one property)
- **NP** = Maximum Possible pairs = N x (N - 1) / 2
- **N** = number of tracked public methods

The result is a ratio from 0.0 to 1.0:

- **TCC = 1.0** -- every public method shares properties with every other. The class is perfectly cohesive.
- **TCC >= 0.5** -- good cohesion. Most methods work on the same data.
- **TCC < 0.3** -- low cohesion. Methods are working on different subsets of properties -- the class likely has multiple responsibilities.

Think of it like a dinner party: if every guest knows every other guest, the group is tightly knit (TCC = 1.0). If guests form isolated cliques with no overlap, the party should have been two separate events (TCC near 0.0).

**How to read the value:**

| TCC       | Interpretation                                   |
| --------- | ------------------------------------------------ |
| 0.5--1.0  | Good -- methods are well interconnected          |
| 0.3--0.5  | Moderate cohesion                                |
| Below 0.3 | Low method interconnection -- consider splitting |

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Thresholds

TCC and LCC are currently reported as **metrics only** (visible in `--format=metrics` output). They do not produce violations on their own. Use them alongside [LCOM](#lcom----lack-of-cohesion-of-methods) for a fuller picture of class cohesion.

Recommended interpretation:

| TCC Value | Meaning                                                    |
| --------- | ---------------------------------------------------------- |
| 1.0       | Perfect cohesion -- all public methods share properties    |
| >= 0.5    | Good cohesion                                              |
| 0.3--0.5  | Moderate -- review whether the class has too many concerns |
| < 0.3     | Low cohesion -- the class likely needs to be split         |
<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

```php
class OrderService
{
    private array $items = [];
    private float $total = 0.0;
    private string $customerEmail;
    private string $customerName;

    // Group 1: works with $items and $total
    public function addItem(string $item, float $price): void  // -> $this->items, $this->total
    {
        $this->items[] = $item;
        $this->total += $price;
    }

    public function getTotal(): float  // -> $this->total
    {
        return $this->total;
    }

    public function getItems(): array  // -> $this->items
    {
        return $this->items;
    }

    // Group 2: works with $customerEmail and $customerName
    public function setCustomer(string $name, string $email): void  // -> $this->customerName, $this->customerEmail
    {
        $this->customerName = $name;
        $this->customerEmail = $email;
    }

    public function getCustomerEmail(): string  // -> $this->customerEmail
    {
        return $this->customerEmail;
    }
}
```

With 5 public methods, NP = 5 x 4 / 2 = 10 possible pairs. Only a few pairs share properties (e.g., `addItem`-`getTotal` share `$total`, `addItem`-`getItems` share `$items`, `setCustomer`-`getCustomerEmail` share `$customerEmail`). The two groups have no overlap, so TCC will be low (around 0.3).

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

- **Split the class** along the property boundaries. In the example: `OrderCart` for items/total and `CustomerInfo` for name/email.
- **Look at which properties cluster together.** Methods that share properties belong together; methods that don't should be in separate classes.
- **Use the TCC value alongside LCOM.** LCOM counts disconnected groups; TCC tells you what fraction of method pairs are connected. Together, they give a complete cohesion picture.

---

<!-- llms:skip-end -->

## LCC -- Loose Class Cohesion

**Metric ID:** `cohesion.lcc`

<!-- llms:skip-begin -->
### What it measures

LCC extends TCC by including **transitive connections**. Two methods are considered loosely connected if they are linked through a chain of directly connected methods, even if they don't share a property themselves.

`LCC = NIC / NP`

Where:

- **NIC** = Number of Indirectly Connected pairs (all pairs reachable via direct connections)
- **NP** = Maximum Possible pairs

LCC is always >= TCC for the same class. If TCC = LCC, there are no transitive-only connections. If LCC is significantly higher than TCC, the class has a "chain" structure where methods are connected through intermediaries.

| Relationship | Meaning                                                       |
| ------------ | ------------------------------------------------------------- |
| TCC = LCC    | All connections are direct -- no transitive chains            |
| LCC >> TCC   | Methods form chains -- consider whether design is intentional |

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Example

Consider three methods A, B, and C:

- A and B both access `$this->data` (directly connected)
- B and C both access `$this->cache` (directly connected)
- A and C share no properties (not directly connected)

TCC counts 2 direct pairs out of 3 possible: TCC = 2/3 = 0.67.
LCC counts all 3 pairs (A-C are transitively connected through B): LCC = 3/3 = 1.0.

---

<!-- llms:skip-end -->

## Implementation notes

Qualimetrix implements a **simplified variant** of the
[Bieman & Kang (1995)](https://www.cs.colostate.edu/~bieman/Pubs/bieman-kang-ssr95.pdf)
TCC/LCC specification. Sections 2–3 include state reached through directly or
transitively called methods on the same object:

- **Constructors and destructors** (`__construct`, `__destruct`) are excluded from the method set, per the B&K spec (these are setup/teardown, not behavioral methods).
- **Enums are excluded** -- they cannot have instance properties, so TCC would always be 0.0, which is misleading.
- **Interfaces are excluded** -- they have no method bodies, so property access cannot be measured.
- **Only public methods** are considered. The original B&K paper specifies "visible methods"; Qualimetrix measures the public interface and follows private helpers indirectly through public callers.
- **Invocation trees are followed.** Property access reached through an instance-method call, including a private helper, contributes to the public caller's property set. TCC counts pairs sharing these resolved sets; LCC additionally counts chains of such pairs.
- **Static methods and abstract methods** are excluded -- they do not operate on instance state.
- **Classes with 0 or 1 tracked public methods** get no TCC or LCC value at all: with fewer than two methods there is no pair to connect, so the ratio is undefined (0/0). The metrics are absent from the class's output, not 1.0.
- **Namespace and project aggregates** (`cohesion.tcc.avg`, `cohesion.tcc.min` and the LCC equivalents) are computed only over classes that have a value; `.count` states how many that is. Health cohesion combines only available TCC and LCOM contributions, without substituting TCC 0.5 or 0; classes with no eligible methods have no measured LCOM (see [Health Scores](../reference/health-scores.md)).

!!! info "Deviation from original spec"
    Classes with **no applicable instance property use** also get no TCC or LCC value, even with two or more tracked methods. Under the Bieman & Kang formula such a class has method pairs but no shared instance variable, so TCC = LCC = 0. Qualimetrix treats cohesion through shared state as not applicable to a stateless class instead of reporting it as maximally incohesive, which would pull namespace averages down for every stateless service.

!!! note "Comparing with other tools"
    Tools that use direct property access only can report different TCC/LCC values for delegation-heavy classes; compare their method populations and invocation treatment before comparing values.

---

## Configuration

TCC and LCC are collected as metrics and do not have configurable thresholds. They appear in the metrics JSON output:

```bash
bin/qmx check src/ --format=metrics
```

To use TCC/LCC for quality gates, you can process the metrics JSON output programmatically (e.g., in a CI pipeline script).

## Unmatched configured method names

exclude_methods is a sequence and uses PHP case-insensitive method matching, with the first authored spelling retained. When measured scope permits declaration-absence claims, the LCOM producer also publishes cohesion.unmatched-exclude-method once per unmatched normalized configured name. Functions and property hooks do not witness Method-kind declarations; partial runs do not claim project-wide absence. This secondary warning carries project magnitude 1 and a semantic occurrence for the normalized name, without a configured warning boundary. The primary LCOM 3/5 band and secondary-channel lifecycle are unchanged. CLI: --lcom-exclude-methods='[getName, getDescription]'.


### Baseline comparison

The seven composed class metrics (LCOM, TCC, LCC, their two method populations,
pure-method count and unread-trait count) declare Run reach. A trait body in
another file can change the selected class's metrics. Checking only the class
file against a whole-project baseline therefore leaves these ceilings
not compared, even when that particular class has no missing trait body.
Use matching whole-run coverage to compare them. Raw file-local fact rows
retain Members reach; exact declaration subjects are preserved in both phases.
