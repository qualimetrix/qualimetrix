# Cohesion Rules

Cohesion rules measure how well the methods inside a class work together. A cohesive class has methods that operate on the same data -- they share properties and pursue a single purpose. Low cohesion is a strong signal that a class is doing too many things and should be split.

See also: [LCOM (Lack of Cohesion of Methods)](design.md#lcom----lack-of-cohesion-of-methods) in the Design rules -- a complementary cohesion metric that counts disconnected method groups.

---

## TCC -- Tight Class Cohesion

**Metric ID:** `tcc`

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

### Thresholds

TCC and LCC are currently reported as **metrics only** (visible in `--format=metrics` output). They do not produce violations on their own. Use them alongside [LCOM](design.md#lcom----lack-of-cohesion-of-methods) for a fuller picture of class cohesion.

Recommended interpretation:

| TCC Value | Meaning                                                    |
| --------- | ---------------------------------------------------------- |
| 1.0       | Perfect cohesion -- all public methods share properties    |
| >= 0.5    | Good cohesion                                              |
| 0.3--0.5  | Moderate -- review whether the class has too many concerns |
| < 0.3     | Low cohesion -- the class likely needs to be split         |

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

**Metric ID:** `lcc`

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

Qualimetrix implements a **simplified variant** of the Bieman & Kang (1995) TCC/LCC specification:

- **Constructors and destructors** (`__construct`, `__destruct`) are excluded from the method set, per the B&K spec (these are setup/teardown, not behavioral methods).
- **Enums are excluded** -- they cannot have instance properties, so TCC would always be 0.0, which is misleading.
- **Interfaces are excluded** -- they have no method bodies, so property access cannot be measured.
- **Only public methods** are considered. The original B&K paper specifies "visible methods" (public + protected); Qualimetrix follows the stricter industry convention (public only), consistent with most tools (PHPMD, PHPMetrics).
- **Only direct `$this->property` access** is counted. B&K also defines "invocation trees" where a public method calling a private helper that accesses a property counts as indirect access. This is **not implemented** -- delegation through private methods is not tracked. This means TCC may be **underestimated** for classes that heavily use the delegation pattern.
- **Static methods and abstract methods** are excluded -- they do not operate on instance state.
- **Classes with 0 or 1 tracked public methods** default to TCC = 1.0 and LCC = 1.0 (a single-method class is trivially cohesive).

!!! note "Comparing with other tools"
    Most tools (PHPMD, PHPMetrics, JArchitect) implement the same simplified variant -- direct property access only, no invocation trees. Values should be comparable across tools, though minor differences may arise from how constructors or static methods are handled.

---

## Configuration

TCC and LCC are collected as metrics and do not have configurable thresholds. They appear in the metrics JSON output:

```bash
bin/qmx check src/ --format=metrics
```

To use TCC/LCC for quality gates, you can process the metrics JSON output programmatically (e.g., in a CI pipeline script).
