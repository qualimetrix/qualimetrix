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
  coverage: ignore
```

Patterns support both prefix matching (no wildcards, e.g. `App\Controller`) and glob matching (`*`, `**`, `?`, `[…]`). Same-layer dependencies are always allowed (sub-module isolation is intentionally out of scope for the MVP).

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

The catch-all replaces the older `coverage: warn` recipe for "show me everything I haven't classified yet". The `architecture.coverage` mechanism still works (see "Coverage modes" below), but with a catch-all layer it is usually unnecessary.

**YAML merge semantics.** When a preset and a project config both define `architecture.layers`, the **later source replaces the entire list** — order is the user's disambiguation tool, and merging two ordered lists would silently destroy intent. The `architecture.allow` map continues to merge by source layer, and the scalar `architecture.coverage` is overridden by the later source.

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

### Membership beyond namespace patterns

Phase 1 decided layer membership purely from class FQN matched against `patterns`. Phase 2 adds four more criteria — `suffix`, `attributes`, `implements`, `extends` — and a `match: any | all` switch that controls how they combine. The default is `any`, which lets the rule meet legacy code where conventions are inconsistent (a `*Repository` that lives under `App\Service\` is still a repository).

| Criterion    | Matches when…                                                                                  |
| ------------ | ---------------------------------------------------------------------------------------------- |
| `patterns`   | Class FQN matches one of the listed glob patterns (Phase 1 behaviour).                         |
| `suffix`     | Class short-name ends with one of the listed strings (e.g. `Repository`, `Controller`).        |
| `attributes` | Class is annotated with one of the listed PHP attribute FQNs (use-statement-aware resolution). |
| `implements` | Class implements one of the listed interface FQNs, directly or transitively.                   |
| `extends`    | One of the listed class FQNs appears anywhere in the class's parent chain.                     |

Within one criterion, lists are always OR'd (`attributes: [A, B]` means "has A or B"). `match` controls how the criteria of *different* kinds combine.

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

#### Expansion limits

Cumulative expansion across all templates is bounded by `architecture.max_expanded_layers` (default **500**). Pathological broad templates that would exceed the ceiling reject at expansion with an actionable error (the template, the resulting count, the current ceiling). Raise the ceiling explicitly when a monorepo legitimately has more bounded contexts than the default allows:

```yaml
architecture:
  max_expanded_layers: 2000
```

### Excluding subtrees within a layer (`exclude:`)

A layer can carry an `exclude:` block with the same shape as the membership criteria (`patterns`, `suffix`, `attributes`, `implements`, `extends`). Classes that match the exclude block are removed from the layer regardless of positive membership — `exclude:` is a hard filter that runs after the positive criteria.

```yaml
- name: service
  patterns: ['App\Service\**']
  exclude:
    patterns: ['App\Service\Legacy\**']
    suffix: ['LegacyService']
    match: any                 # default — class is excluded if ANY exclude criterion matches
```

`exclude.match: all` is also supported, useful for narrow "exclude suffix X only inside namespace Y" cases. The block must declare at least one criterion (an empty `exclude:` is a configuration error). For template layers, exclude criteria may reference the **same** capture variables as the layer name (`exclude: { patterns: ['App\Module\{module}\Generated\**'] }`) — they filter within the same-binding instance. Exclude cannot introduce new capture variables that don't appear in the layer name.

Under declaration-order matching, the same effect is often achievable by declaring a narrower layer earlier. `exclude:` is the right tool when the excluded subtree should remain **genuinely unclassified** (so it falls through to a catch-all or to coverage diagnostics) or when the positive criteria mix `patterns` with `suffix`/`implements`/`extends` and a single early layer cannot cleanly express the carve-out.

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

Available relation tokens come from two sources. **Direct values** mirror `Qualimetrix\Core\Dependency\DependencyType`:

```
extends, implements, trait_use,
new,
static_call, static_property_fetch, class_const_fetch,
type_hint, property_type, intersection_type, union_type,
catch, instanceof,
attribute
```

**Aliases** are configuration-layer shorthand that expand to constituent direct values:

| Alias            | Expands to                                                      |
| ---------------- | --------------------------------------------------------------- |
| `inheritance`    | `extends`, `implements`, `trait_use`                            |
| `static_access`  | `static_call`, `static_property_fetch`, `class_const_fetch`     |
| `type_reference` | `type_hint`, `property_type`, `intersection_type`, `union_type` |
| `runtime_check`  | `catch`, `instanceof`                                           |

`attribute` stands alone — there is no group it belongs to. Aliases and direct values can be mixed in the same `relations:` list and are deduplicated after expansion. Direct values are validated against `DependencyType::cases()` reflectively, so adding a new dependency kind to the collector automatically becomes accepted in YAML without a release.

When multiple allow targets within one source resolve to the same target layer (for instance via overlapping glob selectors), their permissions **union**. If any matching entry uses the bare/short form (no `relations:`), the union is "all relations allowed" — short-form dominates.

> **Note.** There is currently no instance method-call relation kind in the collector — only `static_call`. Track instance calls via the broader `type_reference` alias if your policy needs to constrain them.

### Coverage modes

`architecture.coverage` controls what happens with dependency edges whose source or target class does not belong to any declared layer. It is independent from `architecture.layer-violation` itself: coverage diagnostics are emitted under a separate rule name, `architecture.coverage`, so you can baseline, suppress, or filter them independently.

| Mode               | Behaviour                                                                                                                                                                                               |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ignore` (default) | Out-of-layer edges are silently skipped. Adopt the rule incrementally without noise.                                                                                                                    |
| `warn`             | One summary `architecture.coverage` violation per analysis with `Info` severity, listing example unclassified classes. Informational only — does not fail the run under the default `fail_on: warning`. |
| `error`            | Same diagnostic but with `Error` severity, suitable for CI gating once you intend to cover the whole codebase.                                                                                          |

<!-- llms:skip-begin -->
The diagnostic message looks like:

```
Architecture coverage: 12 edge(s) with unmatched source layer, 5 edge(s) with unmatched target layer,
3 class(es) outside all declared layers.
Examples of unclassified classes: App\Legacy\Foo, App\Legacy\Bar, App\Legacy\Baz. ...
```

To suppress the diagnostic for a known set of unclassified classes, declare a catch-all layer covering them (or accept the gap by leaving `coverage: ignore`).
<!-- llms:skip-end -->

### Unreachable-layer diagnostic

`architecture.unreachable-layer` (severity `Info`) fires once per declared layer — or per concrete instance produced by a template — whose patterns matched zero classes during analysis. Three possible causes:

1. **Shadowed by a broader layer earlier in the order.** A pattern like `'**'` or `'App\**'` declared before a narrower one captures every class first.
2. **Pattern matches no class in the analysed codebase.** The layer is declared for a namespace that doesn't exist yet — or the namespace was renamed.
3. **DTO-only layer with no outgoing dependencies that happens not to have any classes registered yet.** Hit counting is over all analysed classes (not the dependency graph), so layers with classes but no outgoing dependencies still register hits — this case only arises when the layer truly contains no classes.

For template-expanded layers, the per-instance variant means a specific binding tuple was created but every candidate class for that instance is shadowed by an earlier layer or removed by an `exclude:` block.

Because it is `Info` severity, the diagnostic does not fail the run by default. Set `fail_on: info` to opt into stricter CI behaviour. Run [`qmx debug:layer-assignment <class>`](#debug-layer-assignment) to inspect specific classes when triaging.

### Empty-template diagnostic

`architecture.empty-template` (severity `Warning`) fires once per template layer that expanded to **zero** concrete instances — typically a typo in the template pattern, an excluded module, or a single-segment `{var}` used where the binding spans multiple namespace segments (use `{var:**}` for cross-segment captures).

The severity is intentionally `Warning` rather than `Info`: a template that expands to zero instances **silently disables** the policy attached to it, and that failure mode deserves attention. Three common causes:

1. **Typo in the template pattern.** `App\Modul\{module}\Domain\**` instead of `App\Module\{module}\Domain\**` — no class matches and no instance is created.
2. **Excluded modules.** Every candidate class is removed by `exclude:`, by `exclude_paths`, or by being in a non-analysed directory.
3. **Single-segment capture spanning namespace separators.** `App\{path}\Domain\**` where `path` is meant to capture `Module\Order` (two segments). Switch to `{path:**}` to allow cross-segment captures.

The default `fail_on: error` does not fail the run on warnings. Switch to `fail_on: warning` (or stricter) if you want CI to gate on empty templates.

### Potential-shadow diagnostic

`architecture.potential-shadow` (severity `Info`) detects the quiet failure mode of declaration-order matching: when a class matches multiple layers, only the first wins, and earlier layers can silently steal classes that a user expected a later, narrower layer to own.

Detection is **evidence-based**. The rule walks every analysed class, collects all layers whose patterns match, and records `(assigned, shadowed)` pairs that actually occur in the codebase. This catches every real shadow regardless of pattern shape — prefix overlap (`App\**\Foo` shadowing `App\Service\**`), suffix theft (`**\*Service` shadowing `App\Domain\**`), or any other intersection.

One diagnostic is emitted per `(assigned, shadowed)` pair, with a sample of up to 5 example class FQNs (sorted lexicographically). Output is **deterministic across runs** — the pair list is sorted before emission so CI diffs are stable.

The fix is either to:
- Re-order the layers so the more-specific one is declared first (often what the user meant), or
- Tighten the broader pattern so the layers no longer overlap.

Use [`qmx debug:layer-assignment <class>`](#debug-layer-assignment) to verify the fix per specific class.

### Inspecting layer assignment for a single class { #debug-layer-assignment }

When a class ends up in an unexpected layer — or you want to verify a fix for an `architecture.unreachable-layer` or `architecture.potential-shadow` diagnostic — use the `debug:layer-assignment` command for per-class introspection:

```bash
bin/qmx debug:layer-assignment 'App\Service\Foo'
bin/qmx debug:layer-assignment 'App\Service\Foo' --config qmx.yaml
```

The command delegates to the same `LayerRegistry::resolveAll()` API the runtime rule uses, so the assignment it reports is exactly what `architecture.layer-violation` will observe at analysis time — there is no parallel matching path that could drift from runtime semantics. It walks the configured layers in declaration order, reports the layer the class is assigned to, and lists every other layer whose patterns would also have matched (a potential shadow source if it had been declared earlier).

Example output for a uniquely-assigned class:

```
Class: App\Service\UserService

  Assigned to: service
    Matching pattern: App\Service\**

  Would also match (in declaration order):
    (none — the assignment is unique)
```

Example output for a shadowed class:

```
Class: App\Service\Foo

  Assigned to: any-foo
    Matching pattern: App\**\Foo

  Would also match (in declaration order):
    - service (pattern: 'App\Service\**')

  Diagnostic hint:
    Class is shadowed: would have matched 'service' if 'any-foo' was declared later.
    See architecture.potential-shadow diagnostic for the broader picture.
```

Exit codes follow the standard convention: `0` for any informational result (including "class matches no declared layer"), `2` for invalid input (empty or malformed FQN), `1` for configuration-load errors.

### Options

| Option     | Default   | Description                                                                                                                                                            |
| ---------- | --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `enabled`  | `true`    | Enable or disable this rule. When disabled, the rule short-circuits before walking the dependency graph. The rule is also a no-op when `architecture.layers` is empty. |
| `severity` | `warning` | Severity used for every reported layer-violation. Allowed values: `warning`, `error`.                                                                                  |

```yaml
rules:
  architecture.layer-violation:
    enabled: true
    severity: error
```

The CLI alias `--layer-violation` toggles the `enabled` option, matching the convention used by other architecture rules.

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

To suppress every layer violation in the project, use the standard prefix form: `@qmx-ignore architecture` (which also covers `architecture.circular-dependency`) or `@qmx-ignore architecture.layer-violation`.

The baseline file stores layer violations by source layer, target layer, dependency target class, and dependency type — not by file line — so re-formatting or moving the use-site within the same file does not invalidate the baseline. Multiple use-sites of the same forbidden edge collapse into a single baseline entry.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Implementation notes

- **Five membership criteria, default `match: any`.** Membership is decided by `patterns`, `suffix`, `attributes`, `implements`, `extends` — combined per-entry via `match: any` (default) or `match: all`. The default lets the rule meet legacy code where naming and namespace conventions are inconsistent. See [ADR 0007](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0007-architecture-rules-phase-2-design.md) for the rationale.
- **Single layer per class, declaration-order matching.** Every class belongs to at most one layer. When patterns from two layers match the same class, the **layer declared first** in `architecture.layers` wins (the same mechanism used by deptrac, ArchUnit, `.gitignore`, and Apache config). There is no specificity scoring — order is the user's tool to express intent, and the engine does not second-guess it. See [ADR 0006](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0006-architecture-rules-declaration-order.md) for the rationale.
- **Templates expand by observed binding tuples, after collection.** A template layer like `'domain-{module}'` is expanded by `LayerExpansionStage` (which runs between Collection and RuleExecution), producing one concrete `LayerDefinition` per binding tuple actually observed in the codebase — never the cartesian product of distinct values. Capture-binding in the allow-list (`'app-{m}': ['domain-{m}']`) ships in the same release as the templates themselves, not as a follow-up. See [ADR 0007](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0007-architecture-rules-phase-2-design.md).
- **`relations:` is a whitelist; aliases expand reflectively.** Long-form allow targets accept a `relations:` list that constrains which `DependencyType` kinds are permitted. Direct values are validated against `DependencyType::cases()` reflectively, so adding a new dependency kind to the collector automatically becomes accepted in YAML. There is no `forbid_relations:` — whitelist-only avoids resolution ambiguity and the maintenance cost of a parallel enum.
- **Vendor namespaces are first-class layers.** Declare a `doctrine` or `symfony` layer with `Doctrine\**` / `Symfony\**` patterns to write policy against vendor edges (e.g., "only repositories may use Doctrine"). Vendor layers behave identically to project layers.
- **Same-layer dependencies are always allowed** in the MVP. Sub-module isolation within a single layer is deferred to Phase 2.
- **Reporting granularity is per use-site.** Each forbidden dependency edge in `Qualimetrix\Analysis\Collection\Dependency\DependencyGraph` produces one violation. If a class violates the policy through five different method calls, you get five violations. Baseline identity collapses them to a single entry (see Suppression above).
- **Out-of-layer ends are silently ignored** for layer-violation purposes. Their count is reported separately via the `coverage` mode.
- **Default-enabled, but inert without layers.** The rule reports `enabled: true` by default and short-circuits when `architecture.layers` is empty, so projects without architecture configuration see zero overhead.
- **Safety nets, not ambiguity errors.** The previous specificity-based algorithm rejected ambiguous configurations at load time. Under declaration-order matching, ambiguity does not exist — the order disambiguates — but the user can still **misorder** layers. Two info-severity diagnostics catch this: `architecture.unreachable-layer` (a layer that captured nothing) and `architecture.potential-shadow` (an earlier layer that silently stole classes from a later one). See the dedicated sections above.

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

For the design rationale behind Phase 2 — including why templates expand by observed binding tuples, why capture-binding is mandatory, and why `relations:` is whitelist-only — see [ADR 0007: Architecture Rules Phase 2 design decisions](https://github.com/qualimetrix/qualimetrix/blob/main/docs/adr/0007-architecture-rules-phase-2-design.md).

<!-- llms:skip-end -->
