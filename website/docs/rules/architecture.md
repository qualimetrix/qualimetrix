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

You declare layers by mapping each layer name to one or more namespace patterns. Every class in the project is then assigned to **at most one** layer based on namespace match. For every dependency edge in the graph (`extends`, `implements`, type hint, method call, etc.), the rule looks up the source layer and the target layer; if the edge crosses two declared layers and the policy's allow-list does not permit that direction, a violation is reported.

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

```yaml
# qmx.yaml
architecture:
  layers:
    controller: 'App\Controller\**'
    service:    'App\Service\**'
    repository: 'App\Repository\**'
    domain:     'App\Domain\**'
    doctrine:   'Doctrine\**'        # vendor as a first-class layer

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

If a class matches patterns from two layers, the layer with the **longer literal prefix** wins — `App\Service\Internal\**` is preferred over `App\Service\**`. Ties on specificity are a configuration error and are reported at config-load time.

#### Configuration example with vendor and shared layers

```yaml
architecture:
  layers:
    domain:    'App\Domain\**'
    app:       'App\Application\**'
    infra:     'App\Infrastructure\**'
    web:       'App\UserInterface\Web\**'
    cli:       'App\UserInterface\Cli\**'
    symfony:   'Symfony\**'
    doctrine:  'Doctrine\**'

  allow:
    domain:   []
    app:      [domain]
    infra:    [domain, app, doctrine]
    web:      [app, symfony]
    cli:      [app, symfony]
    # symfony and doctrine omitted -- they are "leaf" vendor layers nobody is allowed to bypass
```

<!-- llms:skip-end -->

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

### Layer collision diagnostics

`architecture.layer-collision` is emitted whenever a class FQN matches two or more layer definitions with **equal specificity** — the configuration is ambiguous and the rule cannot decide which layer owns the class. One `Error`-severity violation per ambiguous class is reported under this dedicated rule name. The configuration validator catches many such cases at load time (identical patterns, equal-prefix patterns with matching specificity), but corner cases involving glob patterns are caught here, at analysis time.

The fix is always to tighten one of the colliding patterns so its literal prefix is more specific, or to move the class out of the overlap. The diagnostic message lists every colliding layer name and the pattern that matched.

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

- **Namespace-based membership only.** Layer membership is decided purely by matching the class FQN against namespace patterns. Class-name suffixes, marker interfaces, and PHP attributes are intentionally not supported — keeping the model namespace-only avoids drift between layer rules and your project's naming conventions, and matches the way most teams already organise their code.
- **Single layer per class.** Every class belongs to at most one layer. When two patterns match the same class, the layer with the **longer literal prefix** wins (specificity = length of the substring before the first wildcard character). Equal-specificity ties are a configuration error and surface at config-load time, not analysis time.
- **Vendor namespaces are first-class layers.** Declare `doctrine: 'Doctrine\**'` or `symfony: 'Symfony\**'` to write policy against vendor edges (e.g., "only repositories may use Doctrine"). Vendor layers behave identically to project layers.
- **Same-layer dependencies are always allowed** in the MVP. Sub-module isolation within a single layer is deferred to Phase 2.
- **Reporting granularity is per use-site.** Each forbidden dependency edge in `Qualimetrix\Analysis\Collection\Dependency\DependencyGraph` produces one violation. If a class violates the policy through five different method calls, you get five violations. Baseline identity collapses them to a single entry (see Suppression above).
- **Out-of-layer ends are silently ignored** for layer-violation purposes. Their count is reported separately via the `coverage` mode.
- **Layer collisions during resolution are skipped silently per-edge.** The configuration validator is responsible for rejecting ambiguous configurations up front; the runtime defence is a guard against edge cases that slip through.
- **Default-enabled, but inert without layers.** The rule reports `enabled: true` by default and short-circuits when `architecture.layers` is empty, so projects without architecture configuration see zero overhead.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Limitations / Future work

- **No dependency-type filter yet.** The YAML structure already accepts the long form `{target: 'foo', types: [extends, method_call, ...]}` for forward compatibility, but the `types` filter is not enforced in the current release; declaring `types:` triggers a configuration warning. Wiring it through to the rule is on the Phase 2 roadmap.
- **Sub-module isolation deferred.** There is no way to forbid edges within a single layer. A future `allow_same_layer: false` flag is planned for teams that want to enforce sub-module boundaries.
- **No interface- or attribute-based membership.** This is intentional — see "Namespace-based membership only" above.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Reference

For users migrating from a dedicated architecture-testing tool:

- [**deptrac**](https://github.com/qossmic/deptrac) — closest neighbour. Qualimetrix's layer rules use a deliberately smaller surface (namespace-only layer membership, single allow-list per source layer, no DSL for include/exclude lists) but cover the most common deptrac use-cases without a second tool in your CI pipeline.
- [**ArchUnit**](https://www.archunit.org/) — Java-world inspiration for the "architecture as test" model. The model fits PHP just as well.

<!-- llms:skip-end -->
