# Structure Metrics

Structure metrics measure cohesion, hierarchy, and class organization.

---

## TCC/LCC (Tight/Loose Class Cohesion)

**Collector:** `TccLccCollector`
**Provides:** `tcc`, `lcc`
**Level:** Class

### Formulas

**TCC (Tight Class Cohesion):**
```
TCC = NDC / NP

Where:
- NDC = Number of Direct Connections (method pairs sharing an attribute)
- NP = Maximum Possible Pairs = N x (N - 1) / 2
- N = number of public methods
```

**LCC (Loose Class Cohesion):**
```
LCC = NIC / NP

Where:
- NIC = Number of Indirect Connections (transitively connected pairs)
```

### Differences from LCOM

| Aspect         | LCOM                     | TCC/LCC            |
| -------------- | ------------------------ | ------------------ |
| Range          | 0 to infinity            | 0 to 1             |
| Interpretation | Complex (lower = better) | Simple (1 = ideal) |
| Methods        | All methods              | Only public        |
| Transitivity   | No                       | LCC considers it   |

### Examples

**Perfect cohesion (TCC = 1.0):**

```php
class Rectangle
{
    private float $width;
    private float $height;

    public function getArea(): float {
        return $this->width * $this->height;  // uses both
    }

    public function getPerimeter(): float {
        return 2 * ($this->width + $this->height);  // uses both
    }
}
// NDC = 1, NP = 1, TCC = 1.0
```

**No cohesion (TCC = 0.0):**

```php
class GodClass
{
    private UserRepository $users;
    private OrderRepository $orders;

    public function findUser(int $id): User {
        return $this->users->find($id);  // only $users
    }

    public function createOrder(array $data): Order {
        return $this->orders->create($data);  // only $orders
    }
}
// NDC = 0, NP = 1, TCC = 0.0
```

### Interpretation

| TCC     | Quality                     |
| ------- | --------------------------- |
| 1.0     | Perfect cohesion            |
| 0.5-1.0 | Good cohesion               |
| 0.3-0.5 | Moderate cohesion           |
| < 0.3   | Low cohesion (SRP violated) |
| 0.0     | Class should be split       |

---

## LCOM4 (Lack of Cohesion of Methods)

**Collector:** `LcomCollector`
**Provides:** `lcom`
**Level:** Class

### Algorithm

LCOM4 = number of connected components in the method graph.

**The graph is built as follows:**
1. Vertices = all class methods
2. Edges = two methods access the same property (`$this->property`)
3. Count connected components (BFS)

### Interpretation

| LCOM | Description                     |
| ---- | ------------------------------- |
| 0    | Class with no methods           |
| 1    | Perfectly cohesive class        |
| 2+   | Class can be split into N parts |

**Example:**

```php
class Service
{
    private $a;
    private $b;

    public function m1() { return $this->a; }
    public function m2() { return $this->a; }
    public function m3() { return $this->b; }
    public function m4() { return $this->b; }
}
// m1-m2 are connected (share $a), m3-m4 are connected (share $b)
// LCOM = 2 (two components)
```

---

## RFC (Response For Class)

**Collector:** `RfcCollector`
**Provides:** `rfc`, `rfc_own`, `rfc_external`
**Level:** Class

### Formula

```
RFC = |RS|

Where RS (Response Set) includes:
- All class methods (public, protected, private)
- All methods/functions called from class methods
```

### Components

| Metric         | Description              |
| -------------- | ------------------------ |
| `rfc_own`      | Class's own methods      |
| `rfc_external` | Unique external calls    |
| `rfc`          | `rfc_own + rfc_external` |

### Example

```php
class OrderProcessor
{
    public function process(Order $order): void
    {
        $user = $this->userRepo->find($order->userId);      // +1
        $this->validator->validate($order);                  // +1
        $this->priceCalculator->calculate($order);           // +1
        $payment = $this->paymentGateway->process($order);   // +1
    }
}
// rfc_own = 1 (process)
// rfc_external = 4 (find, validate, calculate, process)
// RFC = 5
```

### Interpretation

| RFC    | Quality                    |
| ------ | -------------------------- |
| 0-20   | Simple class               |
| 20-50  | Moderate complexity        |
| 50-100 | Many dependencies          |
| 100+   | Very complex, hard to test |

---

## WMC (Weighted Methods per Class)

**Collector:** `WmcCollector`
**Provides:** `wmc`
**Level:** Class

### Formula

```
WMC = sum of CCN(method_i)
```

Sum of cyclomatic complexity of all class methods.

### Interpretation

| WMC    | Quality                            |
| ------ | ---------------------------------- |
| 0-20   | Simple class                       |
| 20-50  | Moderate complexity                |
| 50-100 | Complex class                      |
| 100+   | Very complex, refactoring required |

---

## DIT (Depth of Inheritance Tree)

**Collector:** `InheritanceDepthCollector`
**Provides:** `dit`
**Level:** Class

### Algorithm

DIT = depth of the class in the inheritance hierarchy.

- Classes without parents: DIT = 0
- Subclasses of standard PHP classes (Exception, DateTime, etc.): DIT = 1
- For others: recursive counting through the inheritance chain

### Interpretation

| DIT | Quality            |
| --- | ------------------ |
| 0-2 | Normal             |
| 3-4 | Attention needed   |
| 5+  | Too deep hierarchy |

---

## NOC (Number of Children)

**Collector:** `NocCollector`
**Type:** `GlobalContextCollectorInterface`
**Provides:** `noc`
**Level:** Class

### Formula

```
NOC = number of direct subclasses (extends/implements)
```

**Important:** Only direct subclasses, not transitive (grandchildren are not counted).

### Example

```php
interface PaymentGateway { }

class StripeGateway implements PaymentGateway { }  // +1
class PayPalGateway implements PaymentGateway { }  // +1

// NOC(PaymentGateway) = 2
```

### Interpretation

| NOC  | Quality             |
| ---- | ------------------- |
| 0    | Leaf class          |
| 1-5  | Normal              |
| 6-10 | Wide hierarchy      |
| 10+  | Too many subclasses |

**High NOC is normal for:**
- Strategy interfaces
- Abstract factory patterns
- Plugin architectures

**High NOC is a problem for:**
- God classes
- Fragile Base Class Problem
- LSP violations

---

## Method Count

**Collector:** `MethodCountCollector`
**Provides:** `methodCount`, `methodCountTotal`, `methodCountPublic`, `methodCountProtected`, `methodCountPrivate`, `getterCount`, `setterCount`
**Level:** Class

### Metrics

| Metric              | Description                                |
| ------------------- | ------------------------------------------ |
| `methodCount`       | Methods excluding getters/setters          |
| `methodCountTotal`  | All methods                                |
| `methodCountPublic` | Public methods (excluding getters/setters) |
| `getterCount`       | Methods `get*`, `is*`, `has*`              |
| `setterCount`       | Methods `set*`                             |

---

## Aggregation

All class-level metrics are aggregated upward:

```php
new MetricDefinition(
    name: 'lcom', // 'tcc', 'lcc', 'rfc', 'wmc', 'dit', 'noc', 'methodCount'
    collectedAt: SymbolLevel::Class_,
    aggregations: [
        SymbolLevel::Namespace_->value => [Sum, Average, Max],
        SymbolLevel::Project->value => [Sum, Average, Max],
    ],
)
```

**Aggregated names:** `lcom.avg`, `tcc.max`, `rfc.sum`, `wmc.avg`, etc.
