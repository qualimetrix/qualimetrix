# Coupling Metrics

Coupling metrics measure dependencies between components. All collectors in this category use `GlobalContextCollectorInterface` and work with the dependency graph.

---

## Coupling (Ca, Ce, Instability)

**Collector:** `CouplingCollector`
**Type:** `GlobalContextCollectorInterface`
**Provides:** `ca`, `ce`, `instability`
**Level:** Class

### Metrics

| Metric        | Description                               | Formula             |
| ------------- | ----------------------------------------- | ------------------- |
| `ca`          | Afferent Coupling — incoming dependencies | count(dependents)   |
| `ce`          | Efferent Coupling — outgoing dependencies | count(dependencies) |
| `instability` | Class instability                         | Ce / (Ca + Ce)      |

### Instability Interpretation

| Value | Description                                     |
| ----- | ----------------------------------------------- |
| 0.0   | Maximally stable (only incoming dependencies)   |
| 0.5   | Balanced                                        |
| 1.0   | Maximally unstable (only outgoing dependencies) |

**Stable classes (I ~ 0):** Used by many, depend on few. Difficult to change.

**Unstable classes (I ~ 1):** Used by few, depend on many. Easy to change.

---

## Abstractness

**Collector:** `AbstractnessCollector`
**Type:** `GlobalContextCollectorInterface`
**Requires:** `classCount.sum`, `abstractClassCount.sum`, `interfaceCount.sum`, `enumCount.sum`, `traitCount.sum`
**Provides:** `abstractness`
**Level:** Namespace

### Formula

```
A = (abstractClassCount + interfaceCount) / (classCount + enumCount + traitCount + interfaceCount)
```

### Interpretation

| Value | Description                         |
| ----- | ----------------------------------- |
| 0.0   | All classes are concrete            |
| 0.5   | Balanced abstraction                |
| 1.0   | All classes are abstract/interfaces |

---

## Distance from Main Sequence

**Collector:** `DistanceCollector`
**Type:** `GlobalContextCollectorInterface`
**Requires:** `instability`, `abstractness`
**Provides:** `distance`
**Level:** Namespace

### Formula

```
D = |A + I - 1|
```

### Main Sequence

Ideal packages lie on the line `A + I = 1`:
- **High abstraction (A=1) + Stability (I=0)** = abstract interfaces
- **Low abstraction (A=0) + Instability (I=1)** = concrete implementation details

### Distance Interpretation

| Value   | Description                  |
| ------- | ---------------------------- |
| 0.0     | On the main sequence (ideal) |
| 0.1-0.3 | Acceptable                   |
| 0.3-0.5 | Needs attention              |
| 0.5+    | Problem zone                 |

### Problem Zones

**Zone of Pain (A~0, I~0):**
- Concrete and stable classes
- Many dependencies, difficult to change
- Example: God classes, legacy code

**Zone of Uselessness (A~1, I~1):**
- Abstract but unstable
- Useless abstractions without real usage
- Example: Over-engineering

---

## Aggregation

### CouplingCollector

```php
new MetricDefinition(
    name: 'instability',
    collectedAt: SymbolLevel::Class_,
    aggregations: [
        SymbolLevel::Namespace_->value => [Average],
    ],
)
```

### AbstractnessCollector

```php
new MetricDefinition(
    name: 'abstractness',
    collectedAt: SymbolLevel::Namespace_,
    aggregations: [], // Computed globally
)
```

### DistanceCollector

```php
new MetricDefinition(
    name: 'distance',
    collectedAt: SymbolLevel::Namespace_,
    aggregations: [], // Derived metric
)
```

---

## Example

```php
// Stable interface (I = 0.0, A = 1.0, D = 0.0)
interface PaymentGateway  // Ca = 10, Ce = 0
{
    public function process(Payment $p): Result;
}

// Unstable implementation (I = 1.0, A = 0.0, D = 0.0)
class StripeGateway implements PaymentGateway  // Ca = 0, Ce = 5
{
    public function __construct(
        private HttpClient $client,
        private Logger $logger,
        private Config $config,
    ) {}
}

// Problematic class in the Zone of Pain (I ~ 0, A ~ 0, D ~ 1)
class GodClass  // Ca = 20, Ce = 0 — everything depends on it
{
    // 1000+ lines of concrete logic
}
```
