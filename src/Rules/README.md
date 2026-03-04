# Rules — Analysis Rule Implementations

## Overview

Rules are analysis rule implementations for static analysis. Rules are **completely stateless**:
- They do not collect data — they read from `MetricRepository`
- They do not store state between calls
- A single `analyze()` method is the only entry point

### Rule Types

| Type | Interface | Description |
|------|-----------|-------------|
| Simple | `RuleInterface` | Single analysis level |
| Hierarchical | `HierarchicalRuleInterface` | Multiple levels (method, class, namespace) |

---

## Implemented Rules

| Rule | Category | Description | Default Thresholds |
|------|----------|-------------|-------------------|
| **complexity** | Complexity | Cyclomatic Complexity (CCN) | method: 10/20, class.max: 30/50 |
| **cognitive** | Complexity | Cognitive Complexity | threshold: 15 |
| **npath** | Complexity | NPATH Complexity | method: 200/500, class.max: 500/1000 |
| **size** | Size | Method/class count | class: 15/25, namespace: 10/15 |
| **property-count** | Size | Class property count | warning: 10, error: 15 |
| **maintainability** | Maintainability | Maintainability Index | warning: 65, error: 20 |
| **lcom** | Structure | Lack of Cohesion (LCOM4) | warning: 2, error: 3 |
| **wmc** | Structure | Weighted Methods per Class | warning: 35, error: 50 |
| **noc** | Structure | Number of Children | warning: 5, error: 10 |
| **inheritance** | Structure | Depth of Inheritance Tree (DIT) | warning: 4, error: 6 |
| **coupling** | Coupling | Instability (Ca/Ce) | class/ns: 0.8/0.95 |
| **distance** | Coupling | Distance from Main Sequence | warning: 0.3, error: 0.5 |
| **circular-dependency** | Architecture | Circular dependencies | enabled: true |

---

## Hierarchical Rules

Rules that operate on multiple levels of the code hierarchy (method/class/namespace).

**Interface:**
```php
interface HierarchicalRuleInterface extends RuleInterface {
    public function getSupportedLevels(): array; // [RuleLevel::Method, RuleLevel::Class_]
    public function analyzeLevel(RuleLevel $level, AnalysisContext $context): array;
}
```

**CLI with dot notation:**
```bash
--disable-rule=complexity.class    # Disable a specific level
--only-rule=complexity.method      # Enable only method-level
--disable-rule=complexity          # Disable the entire rule
```

---

## Complexity Rule (Hierarchical)

**Name:** `complexity` | **Category:** Complexity | **Levels:** Method, Class

Checks cyclomatic complexity of methods and classes.

**Method-level:** Checks CCN of individual methods (default: 10/20)
**Class-level:** Checks the maximum CCN of class methods (default: 30/50)

**Configuration:**
```yaml
rules:
  complexity:
    method:
      warning: 10
      error: 20
    class:
      max_warning: 30
      max_error: 50
```

**CLI:** `--cc-warning=10 --cc-error=20 --cc-class-warning=30`

---

## Cognitive Complexity Rule

**Name:** `cognitive` | **Category:** Complexity | **Type:** Simple

Checks cognitive complexity of methods. Unlike CCN, it considers:
- **Nesting** — each level adds a penalty
- **Logical chains** — `a && b && c` counts as +1 (not +3)
- **Switch** — +1 for the entire switch (not for each case)

**Configuration:**
```yaml
rules:
  cognitive:
    threshold: 15  # warning when exceeded
```

**CLI:** `--cognitive-threshold=15`

**Counting rules:**
- `if`, `elseif`, `for`, `foreach`, `while`, `catch`, `?:`, `??` -> +1
- Nesting: each level adds a bonus to the base increment
- Guard clauses (`if (!$x) return;`) are fully counted

---

## Size Rule (Hierarchical)

**Name:** `size` | **Category:** Size | **Levels:** Class, Namespace

**Class-level:** Checks the number of methods in a class (default: 15/25)
**Namespace-level:** Checks the number of classes in a namespace (default: 10/15)

**Configuration:**
```yaml
rules:
  size:
    class:
      warning: 15
      error: 25
    namespace:
      warning: 10
      error: 15
```

**CLI:** `--size-class-warning=15 --ns-warning=10`

---

## Property Count Rule

**Name:** `property-count` | **Category:** Size | **Type:** Simple

Checks the number of properties in a class (default: 10/15).

**Filters (RFC-008):**
- `excludeReadonly: true` — exclude readonly classes
- `excludePromotedOnly: true` — exclude classes with only promoted properties

**CLI:** `--property-exclude-readonly --property-exclude-promoted-only`

---

## Maintainability Rule

**Name:** `maintainability` | **Category:** Maintainability | **Type:** Simple

Checks Maintainability Index of methods (default: 65/20).
MI = 171 - 5.2xln(HV) - 0.23xCCN - 16.2xln(LOC)

**Filters (RFC-008):**
- `excludeTests: true` — exclude test files
- `minLoc: 10` — minimum LOC for checking

**CLI:** `--mi-exclude-tests --mi-min-loc=10`

---

## LCOM Rule

**Name:** `lcom` | **Category:** Structure | **Type:** Simple

Checks Lack of Cohesion (LCOM4) of classes (default: 2/3).
LCOM4 = number of connected components in the method graph.

**Filters (RFC-008):**
- `excludeReadonly: true` — exclude readonly classes
- `minMethods: 3` — minimum methods for checking

**CLI:** `--lcom-exclude-readonly --lcom-min-methods=3`

---

## WMC Rule

**Name:** `wmc` | **Category:** Structure | **Type:** Simple

Checks Weighted Methods per Class (default: 35/50).
WMC = sum of complexities of all class methods.

**Filters (RFC-008):**
- `excludeDataClasses: false` — exclude data classes (opt-in)

**CLI:** `--wmc-exclude-data-classes`

---

## NOC Rule

**Name:** `noc` | **Category:** Structure | **Type:** Simple

Checks Number of Children — number of direct subclasses (default: 5/10).

---

## Inheritance Rule

**Name:** `inheritance` | **Category:** Structure | **Type:** Simple

Checks Depth of Inheritance Tree — depth of the inheritance tree (default: 4/6).

---

## Coupling Rule (Hierarchical)

**Name:** `coupling` | **Category:** Coupling | **Levels:** Class, Namespace

Checks instability = Ce / (Ca + Ce), where:
- **Ce** — efferent coupling (outgoing dependencies)
- **Ca** — afferent coupling (incoming dependencies)

**Default:** max_instability: 0.8/0.95 for class and namespace

**CLI:** `--coupling-class-warning=0.8 --coupling-ns-warning=0.8`

---

## Distance Rule

**Name:** `distance` | **Category:** Coupling | **Type:** Simple

Checks Distance from Main Sequence at the namespace level.
Distance = |A + I - 1|, where A = abstractness, I = instability.

**Interpretation:**
- Main sequence: A + I = 1
- **Zone of Pain** (D high, A~0, I~0): difficult to change
- **Zone of Uselessness** (D high, A~1, I~1): useless abstractions

**Default:** max_distance: 0.3/0.5

**CLI:** `--distance-warning=0.3 --distance-error=0.5`

---

## Circular Dependency Rule

**Name:** `circular-dependency` | **Category:** Architecture | **Type:** Simple

Detects circular dependencies between classes using Tarjan's algorithm (SCC).

**Severity:**
- **Error** for direct cycles (A -> B -> A)
- **Warning** for transitive cycles (A -> B -> C -> A)

**Configuration:**
```yaml
rules:
  circular-dependency:
    enabled: true
    max_cycle_size: 0  # 0 = report all
```

**CLI:** `--disable-rule=circular-dependency` or `--no-circular-deps`

**How to break a cycle:**
1. Introduce Interface — depend on an interface
2. Extract Service — extract a shared dependency
3. Event-driven — use events instead of direct dependencies

---

## Creating a New Rule

### Simple Rule

1. Create a `{Name}Rule extends AbstractRule` class
2. Implement `requires(): array` — required metrics
3. Implement `analyze(AnalysisContext): array` — validation logic
4. Create a `{Name}Options implements RuleOptionsInterface` class
5. Write unit tests

**Example:**
```php
final class ExampleRule extends AbstractRule {
    public const NAME = 'example';

    public static function getOptionsClass(): string {
        return ExampleOptions::class;
    }

    public function requires(): array {
        return ['metricName'];
    }

    public function analyze(AnalysisContext $context): array {
        $violations = [];
        foreach ($context->metrics->all(SymbolType::Method) as $method) {
            $value = $context->metrics->get($method->symbolPath, 'metricName');
            if ($value > $this->options->threshold) {
                $violations[] = Violation::create(/* ... */);
            }
        }
        return $violations;
    }
}
```

### Hierarchical Rule

1. Create a `{Name}Rule extends AbstractRule implements HierarchicalRuleInterface` class
2. Implement `getSupportedLevels(): array` — list of levels
3. Implement `analyzeLevel(RuleLevel, AnalysisContext): array`
4. Create `{Level}{Name}Options implements LevelOptionsInterface` for each level
5. Create `{Name}Options implements HierarchicalRuleOptionsInterface`
6. Write unit tests for each level

**Automatic registration:**
- Rules are registered automatically via Symfony DI (autoconfiguration)
- No need to modify `ContainerFactory` manually
- Rules must be in `src/Rules/{Category}/*Rule.php`

---

## Edge Cases

- Method without the required metric -> skip
- Namespace without classes -> do not generate a violation
- Global functions -> `SymbolPath::forGlobalFunction(namespace, name)`
- Anonymous classes -> do not consider
- Methods in a trait -> `SymbolPath::forMethod(namespace, trait, method)`
- `--disable-rule=complexity` -> disables all rule levels
- `--disable-rule=complexity.class` -> disables only class-level
- DependencyGraph = null -> skip rules that require the graph

---

## False Positive Filtering (RFC-008)

Rules support filters to reduce false positives:

**Class metrics:**
- `isReadonly` — class is declared as `readonly class`
- `isPromotedPropertiesOnly` — all properties are promoted
- `isDataClass` — methods are only getters/setters/constructor

**Configuration:**
```yaml
rules:
  lcom:
    exclude_readonly: true
    min_methods: 3
  property-count:
    exclude_readonly: true
    exclude_promoted_only: true
  wmc:
    exclude_data_classes: false  # opt-in
  maintainability:
    exclude_tests: true
    min_loc: 10
```

---

## Related Documents

- [src/Core/README.md](../Core/README.md) — contracts and interfaces
- [src/Metrics/README.md](../Metrics/README.md) — metric collectors
- [docs/ARCHITECTURE.md](../../docs/ARCHITECTURE.md) — overall architecture
