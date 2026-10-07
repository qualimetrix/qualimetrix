# Architecture Rules

Architecture rules detect structural problems in your codebase that can lead to maintenance nightmares. These problems are often invisible in day-to-day work but cause significant pain when you need to refactor, test, or deploy parts of your application independently.

---

## Circular Dependencies

**Rule ID:** `architecture.circular-dependency`

<!-- llms:skip-begin -->
### What it measures

Detects when classes depend on each other in a loop. A dependency means one class uses another (via constructor injection, method calls, type hints, etc.).

**Direct cycle (size 2):**

```
OrderService --> PaymentService --> OrderService
```

OrderService uses PaymentService, and PaymentService uses OrderService. Neither can exist without the other.

**Transitive cycle (size 3+):**

```
A --> B --> C --> A
```

A depends on B, B depends on C, and C depends back on A. The loop is longer but the problem is the same.

<!-- llms:skip-end -->

### Why it matters

Circular dependencies cause real problems:

- **Cannot test in isolation.** To test class A, you need class B, which needs class C, which needs A again.
- **Cannot deploy independently.** If packages A, B, and C form a cycle, they must always be deployed together.
- **Tight coupling.** Changes to any class in the cycle can break all other classes in the cycle.
- **Harder to understand.** There is no clear "top" or "bottom" -- you cannot read the code in a linear order.

<!-- llms:skip-begin -->
### Thresholds

| Cycle type           | Severity | Meaning                                   |
| -------------------- | -------- | ----------------------------------------- |
| Direct (size 2)      | Error    | Two classes directly depend on each other |
| Transitive (size 3+) | Warning  | A longer chain of classes forms a loop    |

!!! note
    Direct cycles (A depends on B, B depends on A) are reported as **Error** by default because they represent the tightest coupling. Transitive cycles are reported as **Warning** because they are often easier to break.
<!-- llms:skip-end -->

### How a cycle is identified

A cycle has no natural starting point, so one member is picked as its representative: the first fully qualified class name of the cycle in byte order (`Beta` before `alpha`, since uppercase sorts first). The violation is reported against that class, the displayed path starts and ends there, and the baseline entry is keyed by it.

The choice is deliberate rather than incidental. It depends only on the names of the classes in the cycle, so adding or removing unrelated files never re-keys an existing cycle and never silently invalidates its baseline entry. A change to the cycle's own membership can still re-key it -- that is unavoidable under any choice of representative.

The representative is not "the cause" of the cycle: every class in a cycle participates equally. Note also that the displayed path is the **shortest** loop through the representative, not a tour of every member -- a class that only lies on a longer route back does not appear in it. The `(N classes)` counter in the message is the authoritative size of the cycle.

### How the cycle is reported

The violation message and the `Cycle path:` line in the recommendation render the path with a short label per class: `Circular dependency (N classes): A → B → A`. A member keeps its bare class name when no other member of the cycle ends with that name. Otherwise it grows by whole namespace segments until it does tell them apart, and when even its fully qualified name is a suffix of another member's -- `App\Log\Writer` against `Acme\App\Log\Writer`, or a class in the global namespace against a namespaced namesake -- it is anchored at the root instead: `\App\Log\Writer`, the way PHP itself writes it.

For example, a cycle between `App\Billing\Service` and `App\Orders\Service` renders as:

```
Billing\Service → Orders\Service → Billing\Service
```

rather than the useless `Service → Service → Service`. Disambiguation is computed over the cycle's whole membership, not just the displayed loop, so a namesake that the displayed path skips still counts and a member is labelled the same way in every rendering.

For cycles in the `large` category (21+ classes), the message truncates the displayed path to the first 5 members plus `... (N more)`, and the recommendation truncates further, to 3, when pointing at entry-point classes to focus on. A loop short enough to fit is printed whole -- the displayed loop can be much shorter than the cycle it belongs to.

!!! info
    The recommendation also carries a `Cycle data:` JSON trailer meant for AI agent consumption rather than reading. Its `cycle` array uses fully qualified class names -- the short labels used elsewhere are ambiguous across namespaces and would defeat automated processing. `length` is the number of distinct classes; `category` is `small` (2-5), `medium` (6-20), or `large` (21+).

    ```json
    {
      "cycle": ["App\\Billing\\Service", "App\\Orders\\Service", "App\\Billing\\Service"],
      "length": 2,
      "category": "small"
    }
    ```

Cycle *identity* -- the violation's symbol path and the baseline key -- is unaffected by any of this: it still comes from the representative class described above.

### Options

| Option          | Default | Description                                         |
| --------------- | ------- | --------------------------------------------------- |
| `enabled`       | `true`  | Enable or disable this rule                         |
| `maxCycleSize`  | `0`     | Maximum cycle size to report (0 = report all sizes) |
| `directAsError` | `true`  | Treat direct cycles (size 2) as errors              |

### Configuration example

```yaml
# qmx.yaml
rules:
  architecture.circular-dependency:
    maxCycleSize: 5        # ignore very large cycles
    directAsError: true    # direct cycles are errors
```

Cycle findings are currently project-scoped and remain visible in Git reports,
including `--report-strict`, regardless of which files changed.

<!-- llms:skip-begin -->
### Example

```php
// OrderService.php
class OrderService
{
    public function __construct(
        private PaymentService $paymentService,  // depends on PaymentService
    ) {}

    public function createOrder(Cart $cart): Order
    {
        $order = new Order($cart);
        $this->paymentService->charge($order);
        return $order;
    }

    public function getOrderTotal(int $orderId): float
    {
        // ...
        return $total;
    }
}

// PaymentService.php
class PaymentService
{
    public function __construct(
        private OrderService $orderService,  // depends on OrderService -- CYCLE!
    ) {}

    public function charge(Order $order): void
    {
        $total = $this->orderService->getOrderTotal($order->id);
        // process payment...
    }
}
```

`OrderService` depends on `PaymentService`, and `PaymentService` depends on `OrderService`. This is a direct cycle of size 2.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

1. **Introduce an interface (Dependency Inversion).** Make one class depend on an abstraction instead of the concrete class:

    ```php
    interface OrderTotalProviderInterface
    {
        public function getOrderTotal(int $orderId): float;
    }

    class OrderService implements OrderTotalProviderInterface
    {
        public function __construct(
            private PaymentService $paymentService,
        ) {}

        public function getOrderTotal(int $orderId): float { /* ... */ }
    }

    class PaymentService
    {
        public function __construct(
            private OrderTotalProviderInterface $totalProvider,  // no cycle!
        ) {}
    }
    ```

2. **Move shared logic to a third class.** If both classes need the same data, extract it:

    ```php
    class OrderRepository
    {
        public function getTotal(int $orderId): float { /* ... */ }
    }

    // Both services depend on OrderRepository, not on each other
    ```

3. **Use events.** Instead of direct calls, emit an event that the other service listens to:

    ```php
    class OrderService
    {
        public function createOrder(Cart $cart): Order
        {
            $order = new Order($cart);
            $this->eventDispatcher->dispatch(new OrderCreated($order));
            return $order;
        }
    }

    // PaymentService listens for OrderCreated -- no direct dependency
    ```

!!! tip
    Use the `maxCycleSize` option to focus on the most critical cycles first. Direct cycles (size 2) are the easiest to fix and the most harmful. Start there, then work on larger cycles.

<!-- llms:skip-end -->

---

## Layer Violations

**Rule ID:** `architecture.layer-violation`

<!-- llms:skip-begin -->
### What it measures

Detects dependencies between **named layers** in your project that the architecture policy does not explicitly allow.

You declare layers as an **ordered list** of `name`/`patterns` entries. Every class in the project is assigned to **at most one** layer based on namespace match — when a class FQN matches the patterns of multiple layers, the **first layer in declaration order wins** (same mechanism as deptrac, ArchUnit, `.gitignore`, Apache). For every dependency edge in the graph (`extends`, `implements`, type hint, method call, etc.), the rule looks up the source layer and the target layer; if the edge crosses two declared layers and the policy's allow-list does not permit that direction, a violation is reported.

Out-of-layer ends (a class that does not match any declared pattern) are silently ignored by default, so you can adopt the rule incrementally — start with the most important layers and grow coverage over time.

<!-- llms:skip-end -->

### Why it matters

Layered architecture is a contract: each layer is allowed to depend on a fixed set of others. When that contract erodes, problems compound:

- **Implementation leaks across boundaries.** Controllers reach into repositories, services skip the domain, repositories call back into infrastructure. Each shortcut makes the next one easier.
- **Refactoring becomes risky.** Moving a class breaks code in places nobody expected to look. The "blast radius" grows unbounded.
- **Tests stop being isolated.** A unit test for a service ends up needing the controller layer because of an accidental upward dependency.
- **Architecture documents lie.** The diagram says "Controller -> Service -> Repository", but the actual edges form a mesh. New developers learn the diagram, then learn that the codebase ignores it.

Declaring layers as YAML and enforcing them in CI turns the architecture diagram into something the build can verify.

<!-- llms:skip-begin -->
### Configuration

`architecture.layers` is an **ordered list** of layer entries. Each entry has a `name` and a `patterns` list. When a class FQN matches the patterns of multiple layers, the **first match in declaration order wins** — the same mechanism used by deptrac, ArchUnit, `.gitignore`, and Apache config blocks.

```yaml
# qmx.yaml
architecture:
  layers:
    - name: controller
      patterns: ['App\Controller\**']
    - name: service
      patterns: ['App\Service\**']
    - name: repository
      patterns: ['App\Repository\**']
    - name: domain
      patterns: ['App\Domain\**']
    - name: doctrine
      patterns: ['Doctrine\**']        # vendor as a first-class layer

  allow:
    controller: [service]                 # controllers may only call services
    service:    [domain, repository]      # services may use repositories and the domain
    repository: [domain, doctrine]        # repositories may use the domain and Doctrine
    domain:     []                        # the domain is self-contained

  # Optional. What to do with edges whose source or target is not in any layer.
  # See "Coverage modes" below.
  coverage-gap: ignore
```

Architecture patterns use their own closed binding DSL, not the public `exact | subtree | regex` selector language. A wildcard-free FQN selects an inclusive namespace subtree; `*` and `?` stay inside one namespace segment, `**` may cross segments, and `{module}` / `{path:**}` capture values for template layers. Character classes and raw PCRE are rejected. Same-layer dependencies are always allowed (sub-module isolation is intentionally out of scope for the MVP).

**Ordering and the catch-all idiom.** Declaration order is meaningful. Put **narrow** layers first and **broad** layers after — `App\Service\Internal\**` before `App\Service\**`. To capture everything left, declare a final layer with the pattern `**`:

```yaml
architecture:
  layers:
    - name: service
      patterns: ['App\Service\**']
    - name: catchall
      patterns: ['**']                # captures every remaining class
  allow:
    service:  [catchall]
    catchall: []
```

The catch-all replaces the older `coverage-gap: warn` recipe for "show me everything I haven't classified yet". The `architecture.coverage-gap` mechanism still works (see "Coverage modes" below), but with a catch-all layer it is usually unnecessary.

**YAML merge semantics.** When a preset and a project config both define `architecture.layers`, the **later source replaces the entire list** — order is the user's disambiguation tool, and merging two ordered lists would silently destroy intent. The `architecture.allow` map merges by source layer name, and the target list of one source layer is replaced whole by a later source that writes it — `[]` included, which allows that layer nothing. A higher source cannot delete a lower source's entry. Each source name under `allow` is checked against the layers `layers` declares once every source is merged, so a file may allow a layer its preset declares; a name written with `~` is still checked. Scalars such as `architecture.coverage-gap` are overridden by the later source. The same rules for every configuration key are in [How layers combine](../getting-started/configuration.md#how-layers-combine).

#### Configuration example with vendor and shared layers

```yaml
architecture:
  layers:
    - name: domain
      patterns: ['App\Domain\**']
    - name: app
      patterns: ['App\Application\**']
    - name: infra
      patterns: ['App\Infrastructure\**']
    - name: web
      patterns: ['App\UserInterface\Web\**']
    - name: cli
      patterns: ['App\UserInterface\Cli\**']
    - name: symfony
      patterns: ['Symfony\**']
    - name: doctrine
      patterns: ['Doctrine\**']

  allow:
    domain:   []
    app:      [domain]
    infra:    [domain, app, doctrine]
    web:      [app, symfony]
    cli:      [app, symfony]
    # symfony and doctrine omitted -- they are "leaf" vendor layers nobody is allowed to bypass
```

<!-- llms:skip-end -->

Architecture membership `patterns` and public selectors mutually reject
the other's form. Write `patterns: ['App\Domain']` here and an explicit
`subtree:App\Domain` for graph/discovery/suppression selection. A mapping
`{subtree: ...}` is not a membership pattern; an Architecture DSL string is
not a public `exact | subtree | regex` selector. Allow selectors are a separate
language of layer names with their own captures and bindings.

For a template with `match: any`, every positive `patterns` value must produce
a capture tuple: a captureless pattern is refused. Under `match: all`, a
captureless pattern may add a condition. Static layers have no such template
restriction.

### Membership beyond namespace patterns

Layer membership has six criterion kinds. Values within one kind are OR'd;
`match: any` (default) accepts a hit in any kind, while `match: all` requires
all written kinds. An omitted kind adds no condition.

| Criterion           | Matches when…                                                                                                                            |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `patterns`          | The observed class FQN matches the Architecture capture-pattern DSL.                                                                     |
| `suffix`            | The short class name ends with a listed literal suffix.                                                                                  |
| `attributes`        | The class's own declaration header carries a listed attribute FQN.                                                                       |
| `member_attributes` | An own method, property, parameter, class constant, enum case or property hook (including its parameter) carries a listed attribute FQN. |
| `implements`        | A listed interface occurs in the class or interface's supertype closure.                                                                 |
| `extends`           | A listed class occurs in the parent chain; for interfaces, a listed interface occurs in its interface-parent closure.                    |

`attributes` and `member_attributes` are separate: a method carrying
`#[\Override]` does not satisfy class `attributes`. Promoted parameters can
carry both parameter and property sites; nested callable and anonymous-class
sites do not become declaration facts of the enclosing named class.

An ancestry link outside the analysed paths is followed through an exactly
placed source file from the analysed project's Composer install, without
loading or executing that file. Unmapped, unreadable, conditional or unresolved
declarations remain cuts. Each parent/interface branch follows at most 256
links; a further relation is retained as a cut. A hit before the cut still
matches, but a missing criterion that the cut could hide remains undecidable.
External ancestry facts do not prove an external class's own attributes.

An unanswered layer does not withdraw a later match, and an unanswered
`exclude:` does not withdraw its layer's match. The assignment stands with
[`architecture.doubted-assignment`](#doubted-assignment). If no layer matches
and one could not answer, coverage and debug report *undecided*, not
unclassified. `patterns` and `suffix` use the class name and are always decided.

PHP built-in ancestry comes from a shipped table, independent of the runner's
extensions. Enums include `UnitEnum` and backed enums include `BackedEnum`.
Implicit `Stringable` is derived from declaration facts, parents, interfaces
and nested trait uses: a class inheriting `__toString()` from a trait matches,
while the trait itself is not Stringable. Interfaces expose Stringable in both
interface closures; classes expose it through `implements`. An unresolved
trait adaptation aliasing a method to `__toString` leaves that question in doubt.
These inferred facts do not invent dependency edges or increase coupling.

All six kinds accept one string or a list. Attribute and ancestry kinds require
an FQN; use a leading backslash for a global name, such as `extends: '\Exception'`.
Leading backslashes are removed. PHP built-ins have their PHP name regardless
of case; your own and vendor criterion FQNs are compared exactly against the
observed canonical declaration spelling. Wrong case can receive a did-you-mean
suggestion; it is not silently accepted.

```yaml
# Migration-friendly default (match: any)
- name: repository
  patterns: ['App\Repository\**']
  suffix: ['Repository']
  implements: ['Doctrine\Persistence\ObjectRepository']
  # Member if the class lives in App\Repository, OR ends in Repository,
  # OR implements ObjectRepository.
```

```yaml
# Strict convention (match: all)
- name: command-handler
  match: all
  attributes: ['App\Messenger\AsCommandHandler']
  suffix: ['Handler']
  patterns: ['App\Handler\**']
  # Member only if all three hold simultaneously.
```

```yaml
# Combined extends + implements
- name: domain-aggregate
  match: all
  extends: ['App\Domain\AggregateRoot']
  implements: ['App\Domain\HasIdentity']
```

A criterion that is omitted is **trivially satisfied** under `match: all` — there is no need to write empty `patterns: []` to opt out. Attribute names must be **fully-qualified** (the parser refuses bare `Entity`); `implements` and `extends` traverse the supertype chain, so declaring a base interface or class catches every descendant without listing them.

!!! note
    Declaration facts belong to the declaration that owns their site. A nested
    anonymous class's header or members do not assign the enclosing class;
    ordinary dependency edges remain in the graph.

### Layer templates

Listing `domain-Order`, `domain-Inventory`, `domain-Billing`, … in YAML stops scaling once a project has more than a handful of bounded contexts. Phase 2 lets a single layer entry carry a **capture variable** in its name and patterns; after collection, the engine walks the discovered class set, observes which binding tuples actually appear, and produces one concrete layer per tuple — never the cartesian product.

```yaml
architecture:
  layers:
    - name: 'domain-{module}'
      patterns: ['App\Module\{module}\Domain\**']
    - name: 'app-{module}'
      patterns: ['App\Module\{module}\Application\**']
    - name: shared-kernel
      patterns: ['App\Shared\**']

  allow:
    'domain-*': [shared-kernel]
    'app-*':
      - 'domain-*'      # PERMISSIVE — any app-* may depend on any domain-*
      - shared-kernel
```

Concrete layers from a template appear at the template's position in the declared list, in lexicographic order of the captured values. Allow-list selectors against expanded layers use the existing glob form (`'domain-*': [...]`).

#### Capture-variable grammar

- A reference is `{name}` where `name` matches `[A-Za-z_][A-Za-z0-9_]*` (PHP-identifier-like). Names are **case-sensitive**.
- A captured value matches a **single namespace segment** by default — `[^\\]+`, no backslashes. Case is preserved exactly as it appears in the class FQN.
- For multi-segment captures, use the explicit form `{name:**}` — matches one or more segments.
- A cross-segment capture (`{name:**}`) may be used in *patterns* and *relations*, but **not** embedded in a layer *name*: the expanded name must match `[A-Za-z][A-Za-z0-9_-]*`, and a multi-segment value contains `\`. Use `{name}` (single-segment) when a capture variable appears in the layer name.
- Variables in the name template MUST also appear in at least one capture-producing criterion. Reuse of the same variable across criteria binds to the same value (co-binding within a layer entry).
- Variables in different layer entries are independent — there is no global variable namespace.
- Layer names and patterns cannot contain literal `*`, `?`, `[`, `{`, `}` outside selector syntax — these characters are reserved.
- Unbalanced braces (`'domain-{module'`) are rejected at config load with a `ConfigLoadException` rather than silently treated as exact-match.

#### Same-instance allows (capture-binding in the allow-list)

A wildcard allow like `'app-*': ['domain-*']` lets `app-Order` depend on every `domain-X`, defeating bounded-context isolation. Phase 2 ships **capture-binding** for this case:

```yaml
allow:
  'app-{m}':
    - 'domain-{m}'      # same-{m} only — app-Order may use domain-Order, NOT domain-Inventory
    - shared-kernel
```

`{m}` on the source side establishes a binding; `{m}` on the target side requires the **same** captured value. The variable name is local to the entry — `{m}` here is unrelated to any `{m}` elsewhere.

A wildcard-on-both-sides entry like `'domain-*': ['domain-*']` is still legal but surfaces a configuration-load **warning** through the user logger — you almost certainly meant `'domain-{m}': ['domain-{m}']`. To silence the warning when the all-to-all permission is intentional, switch to long-form and set `allow_cross_instance: true`:

```yaml
allow:
  'domain-*':
    - target: 'domain-*'
      allow_cross_instance: true   # acknowledge — any domain-* may depend on any domain-*
```

#### Exact allow graph must be acyclic

At configuration load, Qualimetrix projects every exact-source to exact-target
allow entry into a declared layer graph. That graph must be a DAG. An exact
self-reference, a mutual pair, or a longer directed cycle fails immediately
with `ConfigLoadException`; analysis does not start. This validates the declared
module topology independently of `architecture.circular-dependency`, which
detects cycles actually present between classes.

```yaml
allow:
  application: [domain]
  domain: [application] # rejected: application -> domain -> application
```

Exact self-references were previously stripped silently, and mutual exact
permissions produced only a warning. Remove redundant self-edges. For a cycle,
remove or reorient at least one allow edge so the module dependency direction
is acyclic. Different `relations:` filters do not make opposing permissions
acyclic.

Glob and captured selectors are not projected into this static graph. Their
concrete layer matches can be produced only after observation-driven template
expansion, so projecting the selector strings would invent edges. Wildcard
self-shaped entries remain legal and retain the warning described above.

#### Expansion limits

Cumulative expansion across all templates is bounded by `architecture.max_expanded_layers` (default **500**). Pathological broad templates that would exceed the ceiling reject at expansion with an actionable error (the template, the resulting count, the current ceiling). Raise the ceiling explicitly when a monorepo legitimately has more bounded contexts than the default allows:

```yaml
architecture:
  max_expanded_layers: 2000
```

#### Semantic notes — `match: any | all` and non-pattern criteria

**A template layer may not combine a non-pattern criterion with `match: any`.** Only `patterns` carry the capture variables, so `suffix`, `attributes`, `member_attributes`, `implements` and `extends` are copied into every expanded layer verbatim. Under `match: all` that is exactly what you want — the criterion narrows each instance inside the scope its own substituted pattern already fixes. Under `match: any` it is OR-ed with that pattern instead, so every instance carries the same project-wide net and the first instance in expansion order — binding-value alphabetical, not anything you wrote — claims every class the net catches. The configuration is therefore refused at load time:

```
Configuration error: architecture.layers[0] ("aggregate-{module}"): "suffix" cannot be
combined with "match: any" on a template layer. Only "patterns" carry the capture
variables, so it would be copied into every expanded layer unchanged and, OR-ed with
the substituted pattern, would make every instance claim the same classes project-wide
— the instance that wins one is then decided by binding-value order rather than by the
declaration. Add "match: all" so the criterion narrows each instance, or declare a
static layer if the criterion really is meant to apply project-wide.
```

The refusal is deliberate rather than a silent narrowing: scoping the criterion to the instance's own pattern would make it a subset of that pattern and therefore inert — a clause that looks like it does something and does nothing. Add `match: all` if you meant to narrow each instance, or declare a static (non-template) layer if the criterion really is meant to apply project-wide.

| Mode            | Capture-producing patterns                                               | Non-pattern criteria (`suffix` / `attributes` / `member_attributes` / `implements` / `extends`) |
| --------------- | ------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| `any` (default) | At least one must match to bind                                          | Refused — the configuration does not load                                                       |
| `all`           | Every capture-producing pattern must match (bindings union consistently) | Every declared non-pattern criterion must also match — AND filter on top of the bindings        |

Captureless **patterns** on a template require `match: all`. They filter observed bindings before expansion; `match: any` refuses them rather than expanding a pattern that supplies no tuple. Values in the expanded `patterns` kind still follow its OR semantics. Declare `match: all` when combining a capturing scope with non-pattern criteria:

```yaml
- name: 'aggregate-{module}'
  match: all
  patterns: ['App\Module\{module}\Domain\**']
  suffix: ['Aggregate']
  # Tuple is observed only for modules with a class matching BOTH the
  # capture pattern AND the `Aggregate` short-name suffix.
```

### Excluding subtrees within a layer (`exclude:`)

A layer can carry an `exclude:` block with the same shape as the membership criteria (`patterns`, `suffix`, `attributes`, `member_attributes`, `implements`, `extends`). Classes that match the exclude block are removed from the layer regardless of positive membership — `exclude:` is a hard filter that runs after the positive criteria.

```yaml
- name: service
  patterns: ['App\Service\**']
  exclude:
    patterns: ['App\Service\Legacy\**']
    suffix: ['LegacyService']
    match: any                 # default — class is excluded if ANY exclude criterion matches
```

An `exclude:` clause the run cannot answer — an `extends` / `implements` / `attributes` criterion on a class whose inheritance chain leaves `paths` — does not remove the class: it stays in the layer, and the doubt is reported as described under [Assignments in doubt](#doubted-assignment). Removing it would leave its edges unjudged, which is the worse of the two errors. Such a clause is not reported as one that removed nothing either — see [When the clause removes nothing](#unmatched-exclude).

`exclude.match: all` is also supported, useful for narrow "exclude suffix X only inside namespace Y" cases. A block that writes nothing — `exclude: {}`, `exclude: []`, or criteria written only as `~` — excludes nothing, like any other empty map in the configuration; a block that writes only `match`, or a criterion written as an empty list (`patterns: []`), is a configuration error. For template layers, exclude criteria may reference the **same** capture variables as the layer name (`exclude: { patterns: ['App\Module\{module}\Generated\**'] }`) — they filter within the same-binding instance. Exclude cannot introduce new capture variables that don't appear in the layer name.

Under declaration-order matching, the same effect is often achievable by declaring a narrower layer earlier. `exclude:` is the right tool when the excluded subtree should remain **genuinely unclassified** (so it falls through to a catch-all or to coverage diagnostics) or when the positive criteria mix `patterns` with `suffix`/`implements`/`extends` and a single early layer cannot cleanly express the carve-out.

The classes a clause removes go to the next layer that matches them, and that layer may declare the **same pattern**. This is the plain form of a carve-out:

```yaml
- name: repo-plain
  patterns: ['App\Repository\**']
  exclude:
    extends: ['Doctrine\ORM\EntityRepository']
- name: repo-doctrine
  patterns: ['App\Repository\**']   # receives exactly what repo-plain excludes
```

Two layers declaring the same pattern are otherwise refused at load time: the first takes every class the pattern names, so the second can never own one. Only the earlier layer's `exclude:` makes room — a later layer's own `exclude:` narrows what it takes, not what the earlier one already took — and so does an earlier `match: all` layer that narrows its pattern with another criterion. A third layer on the same pattern is refused behind the layer that received the carved-out classes. Whether the receiving layer gets any class is a fact about the code, reported at run time by [`architecture.unreachable-layer`](#unreachable-layer-diagnostic). The layers that loading accepts on a repeated pattern are exactly the ones [`architecture.potential-shadow`](#potential-shadow-diagnostic) does not report: losing the classes the earlier layer keeps is what the receiving layer was declared for.

#### When the clause removes nothing { #unmatched-exclude }

An `exclude:` clause that matches no class is silent damage: the layer holds
everything its positive criteria caught, which is more than the declaration
asks for, and every verdict about that layer — the forbidden edges it is
allowed, the coverage it accounts for — is drawn from the wider set.
`architecture.unmatched-exclude` reports it, at **warning** severity, once per
declaration:

```
The "exclude" clause of layer "service" (patterns: "App\Service\Legacy\**")
removed no class from it, while the layer's own criteria (patterns:
"App\Service\**") matched 12 symbol(s).
```

The usual causes are a renamed namespace the clause was never updated for, a
typo in the pattern, and a carve-out whose classes were deleted in a refactor.

What the channel deliberately does not do:

- **It says nothing about a layer whose own criteria matched nothing.** The
  clause is only evaluated after the positive criteria succeed, so there the
  count is zero for an unrelated reason — and that layer is already reported by
  [`architecture.unreachable-layer`](#unreachable-layer-diagnostic). A layer
  declared [`pending: true`](#pending-layers) is skipped for the same reason
  that diagnostic skips it.
- **It reports the clause, not the individual criterion.** Under the default
  `match: any`, a clause whose `suffix` fires while its `patterns` never do has
  removed classes, and this channel stays silent about the pattern.
- **It judges a template's clause once, across every layer it expanded to.**
  One `exclude:` under [`domain-{module}`](#layer-templates) becomes one layer
  per module, and a clause that carves classes out of one module is doing its
  job even where another module has nothing to carve. Dropping it, as a
  per-module finding would advise, would break the module where it works. So
  the counts are summed: the clause is reported only when it removed nothing
  anywhere while the template matched something somewhere.
- **It does not judge a clause the run could not answer.** An `extends`,
  `implements` or `attributes` exclusion cannot be answered about a class whose
  inheritance chain leaves `paths`. Such a clause has not been shown to remove
  nothing — the class it cannot answer about may be exactly the one it was
  written for — so while any class the layer caught leaves it unanswered, the
  clause is not reported, and nothing advises dropping it. The doubt it leaves
  is reported under [Assignments in doubt](#doubted-assignment).
- **It is judged only with complete measured declaration evidence.** Together
  with `architecture.unreachable-layer` and `architecture.empty-template`, it
  asks declaration absence. Missing PHP, authored PHP removal, generated
  exclusions and an uncertain universe withhold that answer. A complete named
  PHP roster can cover paths while removed PHP still withholds this question.
  Without a usable Composer universe, the whole root may establish completeness;
  an arbitrary subset is not assumed to be the project. Reports name the causes
  under [Project scope](../usage/output-formats.md#project-scope-in-every-format).

This is an ordinary project finding of `architecture.layer-declaration`: it follows `fail_on`, exact channel selection and the baseline. Inline directives cannot suppress a project aggregate with no declaration subject. Disabling `architecture.layer-violation` does not disable it.

### Reserving a layer for code not written yet (`pending:`) { #pending-layers }

A layer that intentionally matches nothing — a module boundary declared before the module is written, or a layer temporarily emptied by a refactor in flight — would otherwise fire [`architecture.unreachable-layer`](#unreachable-layer-diagnostic) on every run. Declare the intent instead of relaxing the diagnostic:

```yaml
- name: reporting
  patterns: ['App\Reporting\**']
  pending: true
```

`pending: true` suppresses `architecture.unreachable-layer` **for that layer only**. Nothing else changes: allow-list edges, coverage, the unassigned-class gate and every other diagnostic behave exactly as if the key were absent. The value must be a real boolean — anything else is a configuration error rather than a truthy string — and the key is rejected on a template layer, whose instances exist only because a tuple was observed in the analysed code and therefore always match something. A template that expanded to nothing is [`architecture.empty-template`](#empty-template-diagnostic), which `pending` deliberately does not reach.

The flag is not a permanent opt-out. The moment the layer's criteria match anything, [`architecture.pending-layer-matched`](#pending-layer-matched-diagnostic) says so.

### Restricting allowed dependencies by relation kind (`relations:`)

Phase 1's allow-list answers "may A depend on B?" with yes/no. Phase 2's long-form allow target adds an optional `relations:` whitelist that restricts **how** the dependency may be expressed.

```yaml
allow:
  domain:
    - target: contracts
      relations: [implements, extends]    # inheritance only — no method calls or instantiation
    - target: vendor
      relations: [extends]                # may subclass vendor types only
```

Bare allow entries (`allow: { domain: [contracts] }`) keep "any relation kind" semantics — fully back-compatible.

Available relation tokens come from two sources. **Direct values** mirror `Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType`:

```
extends, implements, trait_use,
new,
static_call, static_property_fetch, class_const_fetch,
type_hint, property_type, constant_type,
catch, instanceof,
attribute
```

**Aliases** are configuration-layer shorthand that expand to constituent direct values:

| Alias            | Expands to                                                  |
| ---------------- | ----------------------------------------------------------- |
| `inheritance`    | `extends`, `implements`, `trait_use`                        |
| `static_access`  | `static_call`, `static_property_fetch`, `class_const_fetch` |
| `type_reference` | `type_hint`, `property_type`, `constant_type`               |
| `runtime_check`  | `catch`, `instanceof`                                       |

`attribute` stands alone — there is no group it belongs to. Aliases and direct values can be mixed in the same `relations:` list and are deduplicated after expansion. Direct values are validated against `DependencyType::cases()` reflectively, so adding a new dependency kind to the collector automatically becomes accepted in YAML without a release.

An anonymous class nested inside a named class contributes its `extends`,
`implements`, `trait_use`, and `attribute` edges to `relations:` filtering
exactly as a named class's own would, with the enclosing class as the edge's
source — `relations:` restricts *how* a dependency may be expressed, and that
question is unaffected by whether the target belongs to the enclosing class
or to an anonymous class nested inside it. This is the one place the two
cases stay symmetric: membership (above) treats them differently, `relations:`
does not.

`relations: ~` is the same as leaving `relations:` out: the target allows any relation.

When multiple allow targets within one source resolve to the same target layer (for instance via overlapping glob selectors), their permissions **union**. If any matching entry uses the bare/short form (no `relations:`), the union is "all relations allowed" — short-form dominates.

> **Note.** There is currently no instance method-call relation kind in the collector — only `static_call`. Track instance calls via the broader `type_reference` alias if your policy needs to constrain them.

Type shape is not a relation token. Nullable, union, intersection and DNF
retain their position: parameter/return is `type_hint`, a property or promoted
parameter is `property_type`, and a typed constant is `constant_type`. Graph JSON always publishes `shape` as an object mapping each type position
to its sorted observed shape names (`single`, `nullable`, `union`,
`intersection`, `dnf`), for example `{"property_type": ["nullable"],
"type_hint": ["union"]}`. An edge with no type-shape facts has `shape: {}`. `union_type` and `intersection_type` are no longer accepted in `relations:`.

### Coverage modes

`architecture.coverage-gap` controls what happens when an analysed logical class
does not belong to any declared layer, or when a dependency edge has an
unclassified source or target. Isolated analysed classes are covered even when
they have no dependency edges.

!!! warning "Configuration diagnostic, not code debt"
    `architecture.coverage-gap` is one of five architecture diagnostics that flag a
    mistake in the architecture *configuration* rather than debt in the
    analysed code — the others are `architecture.unreachable-layer`,
    `architecture.pending-layer-matched`, `architecture.potential-shadow`, and
    `architecture.empty-template`. All
    five fail the run unconditionally whenever they fire: `fail_on` is not
    consulted, not even `fail_on: none`, and none of the five can be accepted
    into a baseline or silenced with `@qmx-ignore`. A severity option on any of
    them would look like a behaviour switch while changing nothing, so none
    exposes one. What remains to decline them: `coverage-gap: ignore` for this
    diagnostic specifically, and the `exclude:` block inside a layer.
    `architecture.layer-violation` itself is unaffected by any of this — it
    reports real code debt and stays suppressible and baselineable as usual.

| Mode               | Behaviour                                                                                                                                                      |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ignore` (default) | Out-of-layer classes and edge endpoints are silently skipped. Adopt the rule incrementally without noise.                                                      |
| `warn`             | One summary `architecture.coverage-gap` violation per analysis with `Warning` severity, listing example unclassified classes. Fails the run whenever it fires. |
| `error`            | Same diagnostic but with `Error` severity. Fails the run whenever it fires, same as `warn` — pick it to signal fail-closed CI ownership in the config itself.  |

<!-- llms:skip-begin -->
The diagnostic message looks like:

```
Architecture coverage-gap: 12 edge(s) with unmatched source layer, 5 edge(s) with unmatched target layer,
3 class(es) outside all declared layers.
Examples of unclassified classes: App\Legacy\Foo, App\Legacy\Bar, App\Legacy\Baz. ...
```

When part of the gap was never decided, a second sentence says so and names examples:

```
Architecture coverage-gap: 2 edge(s) with unmatched source layer, 2 edge(s) with unmatched target layer,
3 class(es) outside all declared layers. 2 of them could not be decided: a declared
"extends"/"implements"/"attributes" criterion reads facts this run did not collect, because the symbol
or a link in its inheritance chain is outside the analysed paths — for example App\Web\OrderController,
Vendor\Lib\Middle.
```

When the gap is reported and some assignments also stand on a layer the run could not fully answer — an earlier layer that went unanswered, or the assigned layer's own `exclude:` — a further sentence counts them:

```
2 assigned class(es) rest on a layer the run could not fully decide — for example App\Domain\LegacyGateway,
App\Web\OrderController.
```

They are not part of the gap. Alone they never raise the diagnostic, and the recommendation sends the reader to [`architecture.doubted-assignment`](#doubted-assignment), which reports them on their own.

The recommendation then says what each edit does to the undecided share, with one sentence for analysed classes and one for symbols outside the analysed paths — each only when such a symbol is among them:

```
For the undecided ones, a layer declared after the one that could not answer (a catch-all included)
covers them, but as a guess: each may belong to the unanswered layer. For an analysed class, widening
paths to include the declaration where its chain stops decides it — "qmx debug:layer-assignment <class>"
names that declaration. A symbol outside the analysed paths is decided by analysing it when it is your
own code — widening paths to include it — and otherwise by a patterns layer for its namespace declared
before the layer that could not answer.
```

To suppress the diagnostic for a known set of unclassified classes, declare a catch-all layer covering them (or accept the gap by leaving `coverage-gap: ignore`). A catch-all silences the undecided part too — an undecidable layer does not stop a later layer from matching — but it silences it by *assigning* those classes to the catch-all, which is a guess, not an answer. The class is then judged against the catch-all's allow-list while it may really belong to the layer that went unanswered. To make the assignment right instead, analyse the declaration where the chain stops: widen `paths` to include it when it is your own code. A vendor type that is itself undecided (the far end of a dependency edge) is settled by a `patterns` layer for its namespace declared *before* the layer that could not answer it. `debug:layer-assignment` names where the chain stops and keeps naming the unanswered layer beside the assignment either way.
<!-- llms:skip-end -->

#### Assignments in doubt { #doubted-assignment }

An unanswered layer never withdraws a match, so a symbol can stand assigned while a layer that bears on the assignment went unanswered: a layer declared *before* the assigned one whose `extends` / `implements` / `attributes` criterion could not be answered, or the assigned layer's own `exclude:`. The assignment stands and its edges are judged; what the run cannot say is whether the unanswered layer would have changed it. A layer declared *after* the assigned one is not a doubt — first match wins, so it could not have owned the symbol — unless the assigned layer's own `exclude:` is the one that went unanswered: the clause may remove the symbol, and then a later layer owns it.

`architecture.doubted-assignment` counts these symbols, and beside them the symbols that are in no layer only because a layer could not answer about them — those are judged against no allow-list and may belong to that layer. It is information, not a gap: the finding is reported at **info** severity, so it never fails the run, whatever `fail_on` says, and it is published whatever the `coverage-gap` mode — it says whether membership is right, not how much of the code the layers cover. One finding per run splits each count into analysed classes and symbols outside the analysed paths, names every layer that could not answer with how many symbols it left in doubt, and gives examples of each kind — at most 10 of each, by name:

```
3 assigned symbol(s) rest on a layer the run could not fully decide (1 analysed class(es), 2 outside the
analysed paths). Layers that could not answer: "models" (3 assigned in doubt). Examples, at most 10 of
each kind by name — analysed: App\Web\OrderController; outside the analysed paths:
Symfony\Component\HttpFoundation\Request, Symfony\Component\HttpFoundation\Response. Each assignment
stands and its edges are judged against its layer's allow-list; whether a layer the run could not answer
— an earlier one, or the assigned layer's own "exclude" — would change it is unknown.
```

When an unanswered `exclude:` holds a symbol that a later layer would own once the clause removed it, that layer is named as well, in a list of its own — `Layers that would own some of them if an unanswered "exclude" removed them: "repos" (1 assigned in doubt).` Together the two lists name every layer that [`architecture.unreachable-layer`](#unreachable-layer-diagnostic) leaves out because of a doubt.

The recommendation names only what applies to the kinds present:

- For an analysed class, `debug:layer-assignment` names the unanswered layer and where the chain stops; widening `paths` to include that declaration settles it.
- For a symbol outside the analysed paths, it depends on whose code it is. "Outside the analysed paths" is a fact about the run: a run over one directory leaves the project's own classes outside too, and those are settled by analysing them — widening `paths`, or running over the whole project. A dependency's class — typically a vendor class at the far end of a dependency edge — is settled by a `patterns` layer for its namespace declared *before* the layer that could not answer. `debug:layer-assignment` cannot help with either: it refuses a class the run did not analyse, which is why the finding itself names the layers.

A run over part of the project therefore reports different doubts from a run over all of it, not fewer: a class of your own that the whole-project run decides can be in doubt when the run did not read it.

The layout that produces the most doubt is the common one: an `extends:` layer over a vendor base class declared first, `patterns` layers for your own and vendor namespaces after it. Every vendor class at the end of an edge is then in doubt, because the `extends:` layer cannot be answered about a class the run never read. Declaring the vendor `patterns` layers first removes those. A layout with no doubt at all declares the `extends:` layer last and carves its population out of the earlier layers with an `exclude:` the run can always answer (`patterns` or `suffix`).

The channel is published by `architecture.layer-violation`, so disabling that rule silences it too. To decline the channel alone, list `architecture.doubted-assignment` under `disabled_rules` (or pass `--disable-rule=architecture.doubted-assignment`).

### Unassigned classes { #unassigned-class }

**Rule ID:** `architecture.unassigned-class`

This rule answers the one question `architecture.coverage-gap`
cannot: *is every declaration I analysed assigned to a layer?* Coverage also
counts the ends of dependency edges, and those include classes outside `paths:`
— `Symfony\...`, `PHPUnit\...` — which no layer can classify, so the number it
prints is dominated by code the project does not own. This gate counts only
**analysed class-like declarations**: classes, interfaces, traits and enums that
the run itself measured. A declaration for which no collector recorded any
class-level metric is not in the set and counts as assigned.

It is a rule of its own, inactive by default because its mode is `ignore`.
The owner declares `mode`; framework `enabled` controls producer admission:

```yaml
rules:
  architecture.unassigned-class:
    mode: warn   # ignore (default) | warn | error
```

It reads the same single walk over classes and dependency edges that
`architecture.layer-violation` does, so turning it on costs no extra traversal.
Framework `enabled` and owner `mode` answer different questions: selection and
reportability. A muted mode stays inactive; explicit enable with mode:ignore
refuses. enabled:false with mode:warn is lawful and off.

| Mode               | Behaviour                                                                                       |
| ------------------ | ----------------------------------------------------------------------------------------------- |
| `ignore` (default) | The set is not even collected. No diagnostic.                                                   |
| `warn`             | One summary violation per run with `Warning` severity, listing example unassigned declarations. |
| `error`            | The same diagnostic with `Error` severity.                                                      |

Unlike the five architecture *configuration* diagnostics, this one reports
ordinary debt: it goes through `fail_on` as usual and it
**can be accepted into a baseline**. It is still out of reach of `@qmx-ignore`,
for a different reason than they are: it is a single per-run summary reported
against the project, not against any file or declaration, so no inline directive
is ever placed where it could address it. A `@qmx-ignore
architecture.unassigned-class` written in a source file leaves the diagnostic
standing and is itself reported as `annotation.unused-directive`. To decline the
gate, set `mode: ignore`, or cover the declarations with a layer.
Its reported metric value is the absolute
count of unassigned declarations, which is what makes the baseline useful — the
percentage the message also prints would stay flat while the count grew with the
project, so only the count can be ratcheted down. The CLI alias is
`--unassigned-class-mode`.

<!-- llms:skip-begin -->
The diagnostic message looks like:

```
3 of 240 analysed class-like declaration(s) (1.3%) are not assigned to any declared layer.
Unassigned declarations: App\Legacy\Bar, App\Legacy\Baz, App\Legacy\Foo. ...
```
<!-- llms:skip-end -->

An active `architecture.unassigned-class` (`mode: warn|error` and `enabled: true`)
requires non-empty `architecture.layers`, including when `[]` was written
explicitly. The refusal precedes Discovery and names the writing source.
`mode: ignore`, `enabled: false`, or final selection that does not run this
producer does not impose that requirement.

### Unreachable-layer diagnostic

`architecture.unreachable-layer` reports a layer that owns no analysed class and
cannot own one from an unresolved assignment. It names complete precedence loss,
its own exclusions, external-only matches, and unmet named types as applicable.
`pending: true` exempts that layer. Named types are judged individually; a known
neighbour does not hide a misspelled name. `member_attributes` is a distinct
criterion here too.

Vendor inheritance and traits are followed through exactly placed declarations
read as data. An available chain can establish a match; missing, conflicting or
cut facts remain undecidable and can keep an analysed class's layer in doubt.
External existence alone does not establish attributes on the analysed class.
Unresolved assignments are explained by [`architecture.doubted-assignment`](#doubted-assignment).
The diagnostic requires complete measured declaration evidence, like
[`architecture.unmatched-exclude`](#unmatched-exclude). Missing PHP, authored PHP
removal, generated exclusions and an uncertain universe withhold absence claims.
A whole-root fallback may establish completeness without a usable Composer
universe; an arbitrary subset cannot. Reports name measured causes rather than
using report state alone as permission.
It is a configuration diagnostic (see the note under [Coverage modes](#coverage-modes)): it fails the run unconditionally whenever it fires, and it is not configurable, baselineable, or suppressible with `@qmx-ignore`. Three possible causes:

1. **Shadowed by a broader layer earlier in the order.** A pattern like `'**'` or `'App\**'` declared before a narrower one captures every class first.
2. **Pattern matches no class in the analysed codebase and is never seen as a dependency-edge end either.** The layer is declared for a namespace that doesn't exist yet — or the namespace was renamed.
3. **DTO-only layer with no outgoing dependencies that happens not to have any classes registered yet.** Hit counting covers both classes in the analysed set and dependency-graph edge ends (the source and target layer of every recorded dependency), so a layer that matches no analysed class but is still observed as one end of a dependency edge — e.g. a vendor namespace outside `paths:` such as `ClickHouseDB\**`, which is only ever seen as a dependency *target* — counts as reached and does not fire this diagnostic. This case only arises when the layer truly matches neither a class nor an edge end.

For template-expanded layers, the per-instance variant means a specific binding tuple was created but every candidate class for that instance is shadowed by an earlier layer or removed by an `exclude:` block.

Run [`qmx debug:layer-assignment <class>`](#debug-layer-assignment) to inspect specific classes when triaging.

A layer that is empty **on purpose** — declared ahead of the module it describes — is not one of these cases: declare it [`pending: true`](#pending-layers) and this diagnostic skips it.

### Pending-layer-matched diagnostic

`architecture.pending-layer-matched` fires once per layer declared [`pending: true`](#pending-layers) whose criteria matched at least one class or dependency-edge end. It is a configuration diagnostic (see the note under [Coverage modes](#coverage-modes)): it fails the run unconditionally whenever it fires, and it is not configurable, baselineable, or suppressible with `@qmx-ignore`.

It exists because `pending: true` switches a safety net off, and a switched-off safety net has to be temporary. Without this diagnostic the flag would keep suppressing `architecture.unreachable-layer` after the code finally arrived, and the layer would silently stop being checked for typos and shadowing for the rest of the project's life.

**A match counts even when the layer did not win it.** A pending layer whose classes are all captured by a broader layer declared earlier is assigned nothing, so counting assignments would report zero — exactly in the case where the declaration lies loudest: the code exists, and the layer meant to own it is being shadowed. The diagnostic therefore counts every match, winning or not. The fix is then two edits: remove `pending: true`, and move the layer above the broader one.

### Empty-template diagnostic

`architecture.empty-template` fires once per template layer that expanded to **zero** concrete instances when measured scope permits declaration absence. Typical causes are a typo in the pattern or a single-segment `{var}` where the binding spans multiple namespace segments (use `{var:**}`).

Like `architecture.unreachable-layer`, the diagnostic requires complete measured declaration evidence; missing PHP, authored/generated removal and uncertain universe withhold it.

A template that expands to zero instances **silently disables** the policy attached to it, which is why — like the other four configuration diagnostics — it fails the run unconditionally instead of waiting on a severity or `fail_on` setting; see the note under [Coverage modes](#coverage-modes). Three common causes:

1. **Typo in the template pattern.** `App\Modul\{module}\Domain\**` instead of `App\Module\{module}\Domain\**` — no class matches and no instance is created.
2. **No candidate binding tuple exists in the measured universe.** Authored/generated PHP removal or incomplete paths withhold this diagnostic rather than proving the module absent. `suppress_paths` limits publication, not class analysis.
3. **Single-segment capture spanning namespace separators.** `App\{path}\Domain\**` where `path` is meant to capture `Module\Order` (two segments). Switch to `{path:**}` to allow cross-segment captures.

### Potential-shadow diagnostic

`architecture.potential-shadow` is a configuration error for a comparable
namespace subtree declared after a broader layer that takes its classes.
Pairs are drawn only between established matches; an unanswered `exclude:`
does not establish a shadow. One occurrence names each `(assigned, shadowed)`
pair with a deterministic sample of up to five classes.

Three first-match exemptions have explicit names:

| Exemption                 | Meaning                                                                                    |
| ------------------------- | ------------------------------------------------------------------------------------------ |
| `narrower-declared-first` | A narrower declared namespace subtree precedes a broader one, including a final catch-all. |
| `receives-what-it-leaves` | An equal pattern receives the classes an earlier `exclude:` or `match: all` layer leaves.  |
| `non-pattern-precedence`  | Suffix, attribute, ancestry or otherwise incomparable criteria resolve by declared order.  |

The third is ordinary precedence, not a configuration error. When a reachable
non-pattern layer loses some classes to an earlier layer,
`architecture.layer-overlap` reports that partial loss at fixed **info**,
once per pair. It does not duplicate an unreachable-layer finding for a layer
that received nothing. Reorder or refine the criteria if that precedence was
unintended; [debug](#debug-layer-assignment) shows the exact exemption for a class.

### Unmatched criterion types { #unmatched-type }

`architecture.unmatched-type` is an ordinary project-level **warning** from
`architecture.layer-declaration`. Each authored type in `attributes`,
`member_attributes`, `implements`, `extends`, or their `exclude:` counterparts
is judged independently. A known name beside a typo does not hide the typo.
Different authored positions have distinct occurrences; expansion of one
template value does not multiply it, even when the template creates no instance.
The message names the configuration source, path and available line, with a
case-only did-you-mean when observed or exactly placed spelling is available.

Absence requires complete measured declaration scope and a read Composer
install. With narrowed scope or an unread install there is no finding; when
this exact project channel is selected, stderr instead carries one warning
naming the reason or both reasons. Foreign `--only-rule`, disabling this channel,
its project level, its producer or `architecture.*` also withholds that line.
If every authored type was met, an unread install alone produces no warning.
Unreachable-layer and unmatched-exclude retain their own predicates and add
spelling suggestions; pattern suggestions are limited to plain namespace
subtrees and trailing `\**`, preserving that suffix.

### Declaration diagnostics { #layer-declaration }

**Rule ID:** `architecture.layer-declaration`

This producer has its own `enabled` switch and no severity or numeric threshold
option. It owns five configuration-error channels (`coverage-gap`,
`unreachable-layer`, `pending-layer-matched`, `potential-shadow`,
`empty-template`) and four ordinary channels (`unmatched-exclude`,
`doubted-assignment`, `layer-overlap`, `unmatched-type`), each prefixed
`architecture.`. Configuration errors always fail when published; baseline and
inline suppression cannot accept them.

| Selection door                                                   | Five configuration-error channels            | Four ordinary channels                |
| ---------------------------------------------------------------- | -------------------------------------------- | ------------------------------------- |
| Disable `architecture.layer-violation`                           | Still enabled by declaration producer        | Still enabled by declaration producer |
| Select only a foreign rule                                       | FilterExempt: still published                | Not published                         |
| Disable a diagnostic channel or its project level                | FilterExempt: still published                | Not published                         |
| Disable `architecture.*`                                         | Declaration producer disabled: not published | Not published                         |
| Disable `architecture.layer-declaration` or its `enabled` option | Not published                                | Not published                         |

`architecture.layer-violation` owns only forbidden edges; the independent
`architecture.unassigned-class` owns the assignment count. Layer policy is
prepared for any of these three producers that runs, and the evidence walk is
shared. Selection does not override a channel's measured-scope predicate.
Ordinary project diagnostics can be disabled or accepted in a baseline; an
inline directive has no declaration subject to reach for these project findings.

### Inspecting layer assignment for a single class { #debug-layer-assignment }

```bash
bin/qmx debug:layer-assignment 'app\service\userservice' --format=json
```

The command runs Discovery and Collection and uses the same prepared policy
as analysis. Lookup covers observed declarations and dependency-graph ends,
not every class in the Composer install. PHP ASCII case variants resolve to
the observed canonical spelling; high-byte names observed by the parser work
as well. Before collection only an empty normalized spelling is refused.
Any other unknown spelling is refused after lookup with exit 3, even when the
policy is disabled. Informational answers about observed names exit 0.

For a project declaring `service` (`App\Service\**`) before `rest` (`App\**`),
the JSON response has this form:

```json
{
    "meta": {
        "version": "dev-main",
        "package": "qmx",
        "timestamp": "2026-10-07T13:52:15+00:00",
        "docs": "https://qualimetrix.dev",
        "llmsTxt": "https://qualimetrix.dev/llms.txt"
    },
    "fqn": "App\\Service\\UserService",
    "assigned": {
        "layer": "service",
        "criteria": [
            "pattern \"App\\Service\\**\""
        ]
    },
    "contendingMatches": [],
    "shadowed": [
        {
            "layer": "rest",
            "criteria": [
                "pattern \"App\\**\""
            ],
            "reported": false,
            "exemption": "narrower-declared-first"
        }
    ],
    "shadowedBy": "service",
    "undecided": [],
    "contenders": [],
    "chainStopsAt": [],
    "hasLayers": true,
    "policyDisabled": false,
    "edgeEndOnly": false
}
```

`meta` contains the tool/version, timestamp and documentation addresses.
`fqn` is the observed spelling, not the query spelling. `edgeEndOnly` is true
when the name was observed only in graph state rather than analysed declarations.
`assigned` is null when no layer matched; with non-empty `undecided`, that
means the run could not tell, rather than that no layer claims the class.
`contenders` names the possible owners and `chainStopsAt` names unread links.
`hasLayers` distinguishes an empty policy from an unclassified observed name.

`shadowed` contains established later matches; `shadowedBy` is the first
established match, which can differ from the provisional assignment. Each
shadow verdict carries `reported` and, when exempt, the exact `exemption`
value from the table above. `contendingMatches` contains the other matches
whose ownership depends on unanswered criteria, with `reported: false`.
The text view prints the same facts, including `First-match exemptions:`;
it gives a potential-shadow hint only for a reported established shadow.

If final enablement runs none of the three layer-policy producers,
`policyDisabled` is true. Known names still resolve, but text states
`Architecture layer policy is disabled in this configuration.` and prints no
diagnostic/docs hint. JSON keeps the observed facts and omits `reported` and
`exemption` from shadow/contending matches. Configure producer options in YAML;
this debug command does not add the check command's `--disable-rule` option.
Errors under `--format=json` replace the response with the standard error envelope.

### Options { #layer-violation-options }

| Option     | Default   | Description                                                                                                                                                            |
| ---------- | --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `enabled`  | `true`    | Enable or disable this rule. When disabled, the rule short-circuits before walking the dependency graph. The rule is also a no-op when `architecture.layers` is empty. |
| `severity` | `warning` | Severity used for every reported `architecture.layer-violation`. Allowed values: `info`, `warning`, `error`.                                                           |

```yaml
rules:
  architecture.layer-violation:
    enabled: true
    severity: error
```

Layer-violation findings are currently project-scoped and remain visible in Git
reports, including `--report-strict`, regardless of which files changed.

The five architecture configuration diagnostics — `architecture.coverage-gap`,
`architecture.unreachable-layer`, `architecture.pending-layer-matched`,
`architecture.potential-shadow`, and
`architecture.empty-template` — have no severity options of their own; they
gate the run unconditionally instead of going through `fail_on`. See the note
under [Coverage modes](#coverage-modes).
[`architecture.unmatched-exclude`](#unmatched-exclude) has none either, for the
opposite reason: it is an ordinary finding at a fixed `warning`, and `fail_on`
is what decides whether it stops the run.
[`architecture.doubted-assignment`](#doubted-assignment) has none because it is
information at a fixed `info`, which no `fail_on` threshold reaches.

The CLI aliases are `--layer-violation` for the `enabled` option and
`--layer-violation-severity` for the severity, matching the convention used by
other architecture rules. The unassigned-class gate moved to its own rule and
its own alias, `--unassigned-class-mode` — see
[Unassigned classes](#unassigned-class).

<!-- llms:skip-begin -->
### Examples

**Forbidden — controller talks to a repository directly:**

```php
// src/Controller/UserController.php
namespace App\Controller;

use App\Repository\UserRepository;   // BAD: controller -> repository
use Symfony\Component\HttpFoundation\Response;

final class UserController
{
    public function __construct(private UserRepository $users) {}

    public function show(int $id): Response
    {
        return new Response($this->users->find($id)->getName());
    }
}
```

With the policy `controller: [service]`, this produces one violation per use-site (constructor type hint, plus any method call) under `architecture.layer-violation`.

**Allowed — go through the service layer:**

```php
// src/Controller/UserController.php
namespace App\Controller;

use App\Service\UserPresenter;       // OK: controller -> service
use Symfony\Component\HttpFoundation\Response;

final class UserController
{
    public function __construct(private UserPresenter $presenter) {}

    public function show(int $id): Response
    {
        return new Response($this->presenter->render($id));
    }
}

// src/Service/UserPresenter.php
namespace App\Service;

use App\Repository\UserRepository;   // OK: service -> repository

final class UserPresenter
{
    public function __construct(private UserRepository $users) {}

    public function render(int $id): string
    {
        return $this->users->find($id)->getName();
    }
}
```

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Suppression

Per-class or per-method `@qmx-ignore` works the same way as for any other rule:

```php
/**
 * Temporary shortcut while the new presenter is being introduced.
 *
 * @qmx-ignore architecture.layer-violation reason="legacy hotfix, see ticket #1234"
 */
final class LegacyAdminController
{
    public function __construct(private UserRepository $users) {}
    // ...
}
```

To suppress a layer violation, address the exact channel: `@qmx-ignore architecture.layer-violation` on the source declaration suppresses its outgoing projections. A target annotation does not suppress them and may be unused when its coverage can be judged. `architecture.layer-violation.*` matches nothing; `architecture.*` also reaches independent circular-dependency. Project diagnostics have their separate [selection doors](#layer-declaration).

!!! info "Deviation from original spec"
    Layer matching is logical; the finding subject is the exact source declaration.
    An unowned target produces one finding; an owned target produces one projection
    per exact target declaration. Targets remain in occurrence identity and
    cardinality, while symbol/namespace controls judge the source of each projection.
    Next-line/file controls use the physical dependency site. Repeated identical
    edges retain one count-bounded baseline identity without using its presentation line.

**Per-rule `suppress_namespaces` / `suppress_paths` also work here.** The [global `suppress_namespaces`](../getting-started/configuration.md#suppress-namespaces) is deliberately exempt for `architecture.*` rules (see the warning there) — a project-wide, metric-shaped exclusion should not double as a silent way to switch off architecture enforcement. The *per-rule* form is a different, explicit mechanism and is **not** exempt:

```yaml
rules:
  architecture.layer-violation:
    suppress_namespaces:
      - App\Legacy
    suppress_paths:
      - src/Legacy
```

This works because the framework (`RuleOptionsBuild`) extracts `suppress_namespaces` / `suppress_paths` for any rule name unconditionally, before the rule's own Options class ever sees the config. Naming `architecture.layer-violation` explicitly is an unambiguous, auditable choice — unlike a blanket `suppress_namespaces` entry, it cannot be read as "just exclude this namespace from metrics" and accidentally take architecture violations down with it. Suppressions applied this way are counted and reported the same way as any other per-rule exclusion — see [Visibility](../getting-started/configuration.md#rules) in the configuration guide.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Implementation notes

- **Six membership criteria, default `match: any`.** Membership is decided by `patterns`, `suffix`, `attributes`, `member_attributes`, `implements`, `extends` — combined per-entry via `match: any` (default) or `match: all`. The default lets the rule meet legacy code where naming and namespace conventions are inconsistent. See [ADR 0059](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0059-declared-layer-policy-and-architecture-governance.md) for the rationale.
- **Single layer per class, declaration-order matching.** Every class belongs to at most one layer. When patterns from two layers match the same class, the **layer declared first** in `architecture.layers` wins (the same mechanism used by deptrac, ArchUnit, `.gitignore`, and Apache config). There is no specificity scoring — order is the user's tool to express intent, and the engine does not second-guess it. See [ADR 0006](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0006-architecture-rules-declaration-order.md) for the rationale.
- **Templates expand by observed binding tuples, after collection.** A template layer like `'domain-{module}'` is expanded by `LayerExpansionStage` (which runs between Collection and RuleExecution), producing one concrete `LayerDefinition` per binding tuple actually observed in the codebase — never the cartesian product of distinct values. Capture-binding in the allow-list (`'app-{m}': ['domain-{m}']`) ships in the same release as the templates themselves, not as a follow-up. See [ADR 0059](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0059-declared-layer-policy-and-architecture-governance.md).
- **`relations:` is a whitelist; aliases expand reflectively.** Long-form allow targets accept a `relations:` list that constrains which `DependencyType` kinds are permitted. Direct values are validated against `DependencyType::cases()` reflectively, so adding a new dependency kind to the collector automatically becomes accepted in YAML. There is no `forbid_relations:` — whitelist-only avoids resolution ambiguity and the maintenance cost of a parallel enum.
- **Vendor namespaces are first-class layers.** Name patterns work on external edge ends. `extends` and `implements` follow exactly placed installed declarations as data; missing, conflicting or cut branches remain undecidable. Own attributes of an unanalysed external declaration are not inferred from its ancestry. Place a namespace-pattern fallback before an unresolved criterion when that is the intended policy.
- **Same-layer dependencies are always allowed** in the MVP. Sub-module isolation within a single layer is deferred to Phase 2.
- **Exact source and target occurrences.** A forbidden dependency is attributed to its exact source declaration. Zero owned targets gives one logical-target occurrence; owned targets give one occurrence per exact target declaration. Baseline groups keep this occurrence identity and count.
- **Out-of-layer ends are silently ignored** for layer-violation purposes. Their count is reported separately via the `coverage-gap` mode, which splits it: classes every declared criterion answered "no" about, and classes some criterion could not be answered for at all. Assignments that stand on a layer the run could not fully answer are not part of the gap; [`architecture.doubted-assignment`](#doubted-assignment) counts them.
- **Default-enabled, but inert without layers.** The rule reports `enabled: true` by default and short-circuits when `architecture.layers` is empty, so projects without architecture configuration see zero overhead.
- **Safety nets, not ambiguity errors.** The previous specificity-based algorithm rejected ambiguous configurations at load time. Under declaration-order matching, ambiguity does not exist — the order disambiguates — but the user can still **misorder** layers. Two diagnostics catch this: `architecture.unreachable-layer` (a layer that captured nothing) and `architecture.potential-shadow` (an earlier layer that silently stole classes from a later one). Both are configuration diagnostics — they fail the run unconditionally and have no severity option (see the note under [Coverage modes](#coverage-modes)). See the dedicated sections above.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Limitations / Future work

- **No `forbid_relations:`.** Phase 2 ships whitelist-only — `relations:` lists what is permitted, and everything else is implicitly forbidden. A `forbid_relations:` keyword is rejected as redundant; if a real use case appears it can be added later without breaking whitelist users.
- **No instance method-call relation kind.** The collector tracks `static_call` but not instance method invocation. Use the broader `type_reference` alias if your policy needs to constrain instance dependencies. Wiring an instance-call relation through requires extending the collector first and is a Phase 3 candidate.
- **No per-edge severity.** Allow entries do not carry a `level:` field — every layer-violation reuses the rule's `severity` option. Workaround: split the policy across two named rules with different severity if you need a finer gradient.
- **Sub-module isolation deferred.** There is no way to forbid edges within a single layer. Template layers reduce the need (`domain-{m}` produces one layer per module, so cross-module edges are naturally cross-layer), but a future `allow_same_layer: false` flag is still planned for teams that want intra-layer boundaries.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Reference

For users migrating from a dedicated architecture-testing tool:

- [**deptrac**](https://github.com/qossmic/deptrac) — closest neighbour. After Phase 2, Qualimetrix covers the same ground for the common cases: multi-criterion membership (`patterns` + `suffix` + `attributes` + `implements` + `extends`), template layers with capture-binding for DDD bounded contexts, sub-tree exclusion within a layer, and a `relations:` whitelist on allow targets. The surface is still smaller than deptrac's (single allow-list per source layer, no full predicate DSL), but the rule covers the long-tail use cases without a second tool in CI.
- [**ArchUnit**](https://www.archunit.org/) — Java-world inspiration for the "architecture as test" model. The capture-binding allow form (`'app-{m}': ['domain-{m}']`) is conceptually similar to ArchUnit's `slices()`. The model fits PHP just as well.

For the design rationale behind the current layer policy — including why templates expand by observed binding tuples, why capture-binding is mandatory, and why `relations:` is whitelist-only — see [ADR 0059: Declared-Layer Policy and Architecture Governance](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0059-declared-layer-policy-and-architecture-governance.md).

<!-- llms:skip-end -->

## Enablement and reportable mode

Unassigned-class accepts the framework enabled switch in addition to mode. enabled:false with mode:warn is lawful and off; an explicit enabled:true with mode:ignore refuses. Use warn/error to report, or remove the exact enable. Shared layer evidence preparation runs when either lawful producer needs it; disabling a sibling does not disable this producer. Layer-violation keeps its own default-enabled/no-layers short circuit. [Configuration forms](../getting-started/configuration.md#declared-rule-forms-and-prepared-execution) apply before discovery.
