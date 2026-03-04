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

| Rule | Category | Type | Description | Default Thresholds |
|------|----------|------|-------------|-------------------|
| **complexity** | Complexity | Hierarchical (Method, Class) | Cyclomatic Complexity (CCN) | method: 10/20, class.max: 30/50 |
| **cognitive** | Complexity | Hierarchical (Method, Class) | Cognitive Complexity | method: 15/25, class.max: 30/50 |
| **complexity.npath** | Complexity | Hierarchical (Method, Class) | NPATH Complexity | method: 200/500, class (disabled) |
| **size** | Size | Hierarchical (Class, Namespace) | Method/class count | class: 15/25, namespace: 10/15 |
| **size.propertyCount** | Size | Simple | Class property count | warning: 10, error: 15 |
| **maintainability** | Maintainability | Simple | Maintainability Index | warning: 65, error: 20 |
| **lcom** | Structure | Simple | Lack of Cohesion (LCOM4) | warning: 2, error: 3 |
| **wmc** | Structure | Simple | Weighted Methods per Class | warning: 35, error: 50 |
| **noc** | Structure | Simple | Number of Children | warning: 7, error: 15 |
| **inheritance** | Structure | Simple | Depth of Inheritance Tree (DIT) | warning: 4, error: 6 |
| **coupling** | Coupling | Hierarchical (Class, Namespace) | Instability (Ca/Ce) | class/ns: 0.8/0.95 |
| **distance** | Coupling | Simple | Distance from Main Sequence | warning: 0.3, error: 0.5 |
| **circular-dependency** | Architecture | Simple | Circular dependencies | enabled: true |
| **boolean-argument** | CodeSmell | Simple | Boolean arguments in signatures | enabled: true |
| **count-in-loop** | CodeSmell | Simple | count() calls in loops | enabled: true |
| **debug-code** | CodeSmell | Simple | Debug code (var_dump, etc.) | enabled: true |
| **empty-catch** | CodeSmell | Simple | Empty catch blocks | enabled: true |
| **error-suppression** | CodeSmell | Simple | Error suppression operator (@) | enabled: true |
| **eval** | CodeSmell | Simple | eval() usage | enabled: true |
| **exit** | CodeSmell | Simple | exit/die usage | enabled: true |
| **goto** | CodeSmell | Simple | goto statements | enabled: true |
| **superglobals** | CodeSmell | Simple | Direct superglobal access | enabled: true |

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

**CLI:** `--cc-warning=10 --cc-error=20 --cc-class-warning=30 --cc-class-error=50`

---

## Cognitive Complexity Rule (Hierarchical)

**Name:** `cognitive` | **Category:** Complexity | **Levels:** Method, Class

Checks cognitive complexity of methods and classes. Unlike CCN, it considers:
- **Nesting** — each level adds a penalty
- **Logical chains** — `a && b && c` counts as +1 (not +3)
- **Switch** — +1 for the entire switch (not for each case)

**Method-level:** Checks cognitive complexity of individual methods (default: 15/25)
**Class-level:** Checks the maximum cognitive complexity of class methods (default: 30/50)

**Configuration:**
```yaml
rules:
  cognitive:
    method:
      warning: 15
      error: 25
    class:
      max_warning: 30
      max_error: 50
```

**CLI:** `--cognitive-warning=15 --cognitive-error=25 --cognitive-class-warning=30 --cognitive-class-error=50`

**Counting rules:**
- `if`, `elseif`, `for`, `foreach`, `while`, `catch`, `?:`, `??` -> +1
- Nesting: each level adds a bonus to the base increment
- Guard clauses (`if (!$x) return;`) are fully counted

---

## NPATH Complexity Rule (Hierarchical)

**Name:** `complexity.npath` | **Category:** Complexity | **Levels:** Method, Class

Checks NPath complexity — the number of acyclic execution paths through a method.
Unlike Cyclomatic Complexity (additive), NPath is multiplicative and grows exponentially.

**Method-level:** Checks NPath of individual methods (default: 200/500)
**Class-level:** Checks the maximum NPath of class methods (disabled by default)

**Configuration:**
```yaml
rules:
  complexity.npath:
    method:
      warning: 200
      error: 500
    class:
      enabled: false
      max_warning: 500
      max_error: 1000
```

**CLI:** `--npath-warning=200 --npath-error=500 --npath-class-warning=500 --npath-class-error=1000`

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

**CLI:** `--size-class-warning=15 --size-class-error=25 --ns-warning=10 --ns-error=15`

---

## Property Count Rule

**Name:** `size.propertyCount` | **Category:** Size | **Type:** Simple

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

**CLI:** `--mi-warning=65 --mi-error=20 --mi-exclude-tests --mi-min-loc=10`

---

## LCOM Rule

**Name:** `lcom` | **Category:** Structure | **Type:** Simple

Checks Lack of Cohesion (LCOM4) of classes (default: 2/3).
LCOM4 = number of connected components in the method graph.

**Filters (RFC-008):**
- `excludeReadonly: true` — exclude readonly classes
- `minMethods: 3` — minimum methods for checking

**CLI:** `--lcom-warning=2 --lcom-error=3 --lcom-exclude-readonly --lcom-min-methods=3`

---

## WMC Rule

**Name:** `wmc` | **Category:** Structure | **Type:** Simple

Checks Weighted Methods per Class (default: 35/50).
WMC = sum of complexities of all class methods.

**Filters (RFC-008):**
- `excludeDataClasses: false` — exclude data classes (opt-in)

**CLI:** `--wmc-warning=35 --wmc-error=50 --wmc-exclude-data-classes`

---

## NOC Rule

**Name:** `noc` | **Category:** Structure | **Type:** Simple

Checks Number of Children — number of direct subclasses (default: 7/15).

**CLI:** `--noc-warning=7 --noc-error=15`

---

## Inheritance Rule

**Name:** `inheritance` | **Category:** Structure | **Type:** Simple

Checks Depth of Inheritance Tree — depth of the inheritance tree (default: 4/6).

**CLI:** `--dit-warning=4 --dit-error=6`

---

## Coupling Rule (Hierarchical)

**Name:** `coupling` | **Category:** Coupling | **Levels:** Class, Namespace

Checks instability = Ce / (Ca + Ce), where:
- **Ce** — efferent coupling (outgoing dependencies)
- **Ca** — afferent coupling (incoming dependencies)

Also supports CBO (Coupling Between Objects) thresholds.

**Default:** max_instability: 0.8/0.95 for class and namespace

**CLI:**
```bash
# Instability thresholds
--coupling-class-warning=0.8 --coupling-class-error=0.95
--coupling-ns-warning=0.8 --coupling-ns-error=0.95

# CBO thresholds
--cbo-class-warning=... --cbo-class-error=...
--cbo-ns-warning=... --cbo-ns-error=...
```

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

**CLI:** `--no-circular-deps --max-cycle-size=0`

**How to break a cycle:**
1. Introduce Interface — depend on an interface
2. Extract Service — extract a shared dependency
3. Event-driven — use events instead of direct dependencies

---

## Code Smell Rules

Code smell rules detect common anti-patterns and bad practices. All code smell rules:
- Extend `AbstractCodeSmellRule`
- Use `CodeSmellOptions` with a single `enabled` option (default: `true`)
- Produce **Warning** severity violations
- Operate at file level — report counts per file
- Have no CLI aliases (use `--disable-rule=<name>` to disable)

| Rule | Description | What it detects |
|------|-------------|-----------------|
| **boolean-argument** | Boolean arguments in signatures | `function save(bool $overwrite)` — suggests splitting methods or using enums |
| **count-in-loop** | count() calls in loops | `for ($i = 0; $i < count($arr); $i++)` — should be extracted to a variable |
| **debug-code** | Debug code | `var_dump()`, `print_r()`, `dd()`, `dump()`, etc. |
| **empty-catch** | Empty catch blocks | `catch (Exception $e) {}` — should at least log the error |
| **error-suppression** | Error suppression operator | `@fopen()` — hides errors, use proper error handling |
| **eval** | eval() usage | `eval($code)` — security risk, usually avoidable |
| **exit** | exit/die usage | `exit(1)`, `die()` — should not be used in library/application code |
| **goto** | goto statements | `goto label;` — makes control flow hard to follow |
| **superglobals** | Direct superglobal access | `$_GET`, `$_POST`, `$_SERVER` — use request abstraction |

**Configuration:**
```yaml
rules:
  boolean-argument:
    enabled: true   # or false to disable
  debug-code:
    enabled: false  # disable this rule
```

**CLI:**
```bash
--disable-rule=debug-code         # Disable a specific code smell rule
--disable-rule=boolean-argument   # Disable another
--only-rule=debug-code            # Enable only this rule
```

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

### Code Smell Rule

1. Create a `{Name}Rule extends AbstractCodeSmellRule` class
2. Implement `getName(): string` — return the NAME constant
3. Implement `getDescription(): string` — short description
4. Implement `getSmellType(): string` — metric key from `CodeSmellCollector`
5. Implement `getSeverity(): Severity` — typically `Severity::Warning`
6. Implement `getMessageTemplate(): string` — use `{count}` placeholder
7. Use `CodeSmellOptions` as the options class
8. Write unit tests

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
  size.propertyCount:
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
