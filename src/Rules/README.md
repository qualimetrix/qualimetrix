# Rules — Analysis Rule Implementations

## Overview

Rules are analysis rule implementations for static analysis. Rules are **completely stateless**:
- They do not collect data — they read from `MetricRepository`
- They do not store state between calls
- A single `analyze()` method is the only entry point

### Rule Types

| Type         | Interface                   | Description                                |
| ------------ | --------------------------- | ------------------------------------------ |
| Simple       | `RuleInterface`             | Single analysis level                      |
| Hierarchical | `HierarchicalRuleInterface` | Multiple levels (method, class, namespace) |

---

## Implemented Rules

| Rule                                 | Category        | Type                            | Description                     | Default Thresholds                 |
| ------------------------------------ | --------------- | ------------------------------- | ------------------------------- | ---------------------------------- |
| **complexity.cyclomatic**            | Complexity      | Hierarchical (Method, Class)    | Cyclomatic Complexity (CCN)     | method: 10/20, class.max: 30/50    |
| **complexity.cognitive**             | Complexity      | Hierarchical (Method, Class)    | Cognitive Complexity            | method: 15/30, class.max: 30/50    |
| **complexity.npath**                 | Complexity      | Hierarchical (Method, Class)    | NPATH Complexity                | method: 200/1000, class (disabled) |
| **complexity.wmc**                   | Complexity      | Simple                          | Weighted Methods per Class      | warning: 50, error: 80             |
| **size.method-count**                | Size            | Simple                          | Method count per class          | warning: 20, error: 30             |
| **size.class-count**                 | Size            | Simple                          | Class count per namespace       | warning: 15, error: 25             |
| **size.property-count**              | Size            | Simple                          | Class property count            | warning: 15, error: 20             |
| **maintainability.index**            | Maintainability | Simple                          | Maintainability Index           | warning: 40, error: 20             |
| **design.lcom**                      | Design          | Simple                          | Lack of Cohesion (LCOM4)        | warning: 3, error: 5               |
| **design.noc**                       | Design          | Simple                          | Number of Children              | warning: 10, error: 15             |
| **design.inheritance**               | Design          | Simple                          | Depth of Inheritance Tree (DIT) | warning: 4, error: 6               |
| **coupling.instability**             | Coupling        | Hierarchical (Class, Namespace) | Instability (Ca/Ce)             | warning: 0.8, error: 0.95          |
| **coupling.cbo**                     | Coupling        | Hierarchical (Class, Namespace) | Coupling Between Objects        | warning: ..., error: ...           |
| **coupling.distance**                | Coupling        | Simple                          | Distance from Main Sequence     | warning: 0.3, error: 0.5           |
| **architecture.circular-dependency** | Architecture    | Simple                          | Circular dependencies           | enabled: true                      |
| **code-smell.boolean-argument**      | CodeSmell       | Simple                          | Boolean arguments in signatures | enabled: true                      |
| **code-smell.count-in-loop**         | CodeSmell       | Simple                          | count() calls in loops          | enabled: true                      |
| **code-smell.debug-code**            | CodeSmell       | Simple                          | Debug code (var_dump, etc.)     | enabled: true                      |
| **code-smell.empty-catch**           | CodeSmell       | Simple                          | Empty catch blocks              | enabled: true                      |
| **code-smell.error-suppression**     | CodeSmell       | Simple                          | Error suppression operator (@)  | enabled: true                      |
| **code-smell.eval**                  | CodeSmell       | Simple                          | eval() usage                    | enabled: true                      |
| **code-smell.exit**                  | CodeSmell       | Simple                          | exit/die usage                  | enabled: true                      |
| **code-smell.goto**                  | CodeSmell       | Simple                          | goto statements                 | enabled: true                      |
| **code-smell.long-parameter-list**   | CodeSmell       | Simple                          | Long parameter lists            | warning: 4, error: 6               |
| **code-smell.superglobals**          | CodeSmell       | Simple                          | Direct superglobal access       | enabled: true                      |
| **code-smell.unreachable-code**      | CodeSmell       | Simple                          | Unreachable code detection      | warning: 1, error: 1               |
| **design.type-coverage**             | Design          | Simple                          | Type declaration coverage       | param/return/property: 80%/50%     |
| **security.hardcoded-credentials**   | Security        | Simple                          | Hardcoded credentials           | enabled: true                      |

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

**CLI with prefix matching:**
```bash
--disable-rule=complexity.cyclomatic.class  # Disable a specific level
--only-rule=complexity.cyclomatic.method    # Enable only method-level
--disable-rule=complexity                   # Disable all complexity.* rules (prefix match)
--disable-rule=complexity.cyclomatic        # Disable the entire rule
```

---

## Complexity Rule (Hierarchical)

**Name:** `complexity.cyclomatic` | **Category:** Complexity | **Levels:** Method, Class

Checks cyclomatic complexity of methods and classes.

**Method-level:** Checks CCN of individual methods (default: 10/20)
**Class-level:** Checks the maximum CCN of class methods (default: 30/50)

**Configuration:**
```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 10
      error: 20
    class:
      max_warning: 30
      max_error: 50
```

**CLI:** `--cyclomatic-warning=10 --cyclomatic-error=20 --cyclomatic-class-warning=30 --cyclomatic-class-error=50`

---

## Cognitive Complexity Rule (Hierarchical)

**Name:** `complexity.cognitive` | **Category:** Complexity | **Levels:** Method, Class

Checks cognitive complexity of methods and classes. Unlike CCN, it considers:
- **Nesting** — each level adds a penalty
- **Logical chains** — `a && b && c` counts as +1 (not +3)
- **Switch** — +1 for the entire switch (not for each case)

**Method-level:** Checks cognitive complexity of individual methods (default: 15/30)
**Class-level:** Checks the maximum cognitive complexity of class methods (default: 30/50)

**Configuration:**
```yaml
rules:
  complexity.cognitive:
    method:
      warning: 15
      error: 30
    class:
      max_warning: 30
      max_error: 50
```

**CLI:** `--cognitive-warning=15 --cognitive-error=30 --cognitive-class-warning=30 --cognitive-class-error=50`

**Counting rules:**
- `if`, `elseif`, `for`, `foreach`, `while`, `catch`, `?:`, `??` -> +1
- Nesting: each level adds a bonus to the base increment
- Guard clauses (`if (!$x) return;`) are fully counted

---

## NPATH Complexity Rule (Hierarchical)

**Name:** `complexity.npath` | **Category:** Complexity | **Levels:** Method, Class

Checks NPath complexity — the number of acyclic execution paths through a method.
Unlike Cyclomatic Complexity (additive), NPath is multiplicative and grows exponentially.

**Method-level:** Checks NPath of individual methods (default: 200/1000)
**Class-level:** Checks the maximum NPath of class methods (disabled by default)

**Configuration:**
```yaml
rules:
  complexity.npath:
    method:
      warning: 200
      error: 1000
    class:
      enabled: false
      max_warning: 500
      max_error: 1000
```

**CLI:** `--npath-warning=200 --npath-error=1000 --npath-class-warning=500 --npath-class-error=1000`

---

## Method Count Rule

**Name:** `size.method-count` | **Category:** Size | **Type:** Simple

Checks the number of methods in a class (default: 20/30).

**Configuration:**
```yaml
rules:
  size.method-count:
    warning: 20
    error: 30
```

**CLI:** `--method-count-warning=20 --method-count-error=30`

---

## Class Count Rule

**Name:** `size.class-count` | **Category:** Size | **Type:** Simple

Checks the number of classes in a namespace (default: 15/25).

**Configuration:**
```yaml
rules:
  size.class-count:
    warning: 15
    error: 25
```

**CLI:** `--class-count-warning=15 --class-count-error=25`

---

## Property Count Rule

**Name:** `size.property-count` | **Category:** Size | **Type:** Simple

Checks the number of properties in a class (default: 15/20).

**Filters (RFC-008):**
- `excludeReadonly: true` — exclude readonly classes
- `excludePromotedOnly: true` — exclude classes with only promoted properties

**CLI:** `--property-exclude-readonly --property-exclude-promoted-only`

---

## Maintainability Rule

**Name:** `maintainability.index` | **Category:** Maintainability | **Type:** Simple

Checks Maintainability Index of methods (default: 40/20).
MI = 171 - 5.2xln(HV) - 0.23xCCN - 16.2xln(LOC)

**Filters (RFC-008):**
- `excludeTests: true` — exclude test files
- `minLoc: 10` — minimum LOC for checking

**CLI:** `--mi-warning=40 --mi-error=20 --mi-exclude-tests --mi-min-loc=10`

---

## LCOM Rule

**Name:** `design.lcom` | **Category:** Design | **Type:** Simple

Checks Lack of Cohesion (LCOM4) of classes (default: 3/5).
LCOM4 = number of connected components in the method graph.

**Filters (RFC-008):**
- `excludeReadonly: true` — exclude readonly classes
- `minMethods: 3` — minimum methods for checking

**CLI:** `--lcom-warning=3 --lcom-error=5 --lcom-exclude-readonly --lcom-min-methods=3`

---

## WMC Rule

**Name:** `complexity.wmc` | **Category:** Complexity | **Type:** Simple

Checks Weighted Methods per Class (default: 50/80).
WMC = sum of complexities of all class methods.

**Filters (RFC-008):**
- `excludeDataClasses: false` — exclude data classes (opt-in)

**CLI:** `--wmc-warning=50 --wmc-error=80 --wmc-exclude-data-classes`

---

## NOC Rule

**Name:** `design.noc` | **Category:** Design | **Type:** Simple

Checks Number of Children — number of direct subclasses (default: 10/15).

**CLI:** `--noc-warning=10 --noc-error=15`

---

## Inheritance Rule

**Name:** `design.inheritance` | **Category:** Design | **Type:** Simple

Checks Depth of Inheritance Tree — depth of the inheritance tree (default: 4/6).

**CLI:** `--dit-warning=4 --dit-error=6`

---

## Instability Rule (Hierarchical)

**Name:** `coupling.instability` | **Category:** Coupling | **Levels:** Class, Namespace

Checks instability = Ce / (Ca + Ce), where:
- **Ce** — efferent coupling (outgoing dependencies)
- **Ca** — afferent coupling (incoming dependencies)

**Default:** max_instability: 0.8/0.95

**CLI:**
```bash
--instability-class-warning=0.8 --instability-class-error=0.95
--instability-ns-warning=0.8 --instability-ns-error=0.95
```

---

## CBO Rule (Hierarchical)

**Name:** `coupling.cbo` | **Category:** Coupling | **Levels:** Class, Namespace

Checks Coupling Between Objects (CBO) — the number of classes a given class depends on.

**CLI:**
```bash
--cbo-warning=... --cbo-error=...
--cbo-ns-warning=... --cbo-ns-error=...
```

---

## Distance Rule

**Name:** `coupling.distance` | **Category:** Coupling | **Type:** Simple

Checks Distance from Main Sequence at the namespace level.
Distance = |A + I - 1|, where A = abstractness, I = instability.

**Interpretation:**
- Main sequence: A + I = 1
- **Zone of Pain** (D high, A~0, I~0): difficult to change
- **Zone of Uselessness** (D high, A~1, I~1): useless abstractions

**Default:** max_distance: 0.3/0.5

**CLI:** `--distance-warning=0.3 --distance-error=0.5`

---

## Type Coverage Rule

**Name:** `design.type-coverage` | **Category:** Design | **Type:** Simple

Checks type declaration coverage per class. Produces up to 3 violations per class:
- **Parameter type coverage** — percentage of typed method parameters
- **Return type coverage** — percentage of methods with return type declarations
- **Property type coverage** — percentage of typed properties

Lower values are worse (inverted thresholds compared to most rules).

**Default:** param: 80%/50%, return: 80%/50%, property: 80%/50%

**Configuration:**
```yaml
rules:
  design.type-coverage:
    param_warning: 80
    param_error: 50
    return_warning: 80
    return_error: 50
    property_warning: 80
    property_error: 50
```

**CLI:** `--type-coverage-param-warning=80 --type-coverage-param-error=50 --type-coverage-return-warning=80 --type-coverage-return-error=50 --type-coverage-property-warning=80 --type-coverage-property-error=50`

---

## Circular Dependency Rule

**Name:** `architecture.circular-dependency` | **Category:** Architecture | **Type:** Simple

Detects circular dependencies between classes using Tarjan's algorithm (SCC).

**Severity:**
- **Error** for direct cycles (A -> B -> A)
- **Warning** for transitive cycles (A -> B -> C -> A)

**Configuration:**
```yaml
rules:
  architecture.circular-dependency:
    enabled: true
    max_cycle_size: 0  # 0 = report all
```

**CLI:** `--circular-deps --max-cycle-size=0`

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
- Report violations per occurrence with precise line numbers
- Have no CLI aliases (use `--disable-rule=<name>` to disable)

| Rule                             | Description                     | What it detects                                                              |
| -------------------------------- | ------------------------------- | ---------------------------------------------------------------------------- |
| **code-smell.boolean-argument**  | Boolean arguments in signatures | `function save(bool $overwrite)` — suggests splitting methods or using enums |
| **code-smell.count-in-loop**     | count() calls in loops          | `for ($i = 0; $i < count($arr); $i++)` — should be extracted to a variable   |
| **code-smell.debug-code**        | Debug code                      | `var_dump()`, `print_r()`, `dd()`, `dump()`, etc.                            |
| **code-smell.empty-catch**       | Empty catch blocks              | `catch (Exception $e) {}` — should at least log the error                    |
| **code-smell.error-suppression** | Error suppression operator      | `@fopen()` — hides errors, use proper error handling                         |
| **code-smell.eval**              | eval() usage                    | `eval($code)` — security risk, usually avoidable                             |
| **code-smell.exit**              | exit/die usage                  | `exit(1)`, `die()` — should not be used in library/application code          |
| **code-smell.goto**              | goto statements                 | `goto label;` — makes control flow hard to follow                            |
| **code-smell.superglobals**      | Direct superglobal access       | `$_GET`, `$_POST`, `$_SERVER` — use request abstraction                      |

**Configuration:**
```yaml
rules:
  code-smell.debug-code:
    enabled: true   # or false to disable
  code-smell.boolean-argument:
    enabled: false  # disable this rule
```

**CLI:**
```bash
--disable-rule=code-smell.debug-code       # Disable a specific code smell rule
--disable-rule=code-smell                  # Disable all code-smell.* rules (prefix match)
--only-rule=code-smell.debug-code          # Enable only this rule
```

---

## Security Rules

Security rules detect patterns that may lead to security vulnerabilities.

### Hardcoded Credentials Rule

**Name:** `security.hardcoded-credentials` | **Category:** Security | **Type:** Simple

Detects hardcoded credentials in PHP code: string literal values assigned to variables, properties, constants, array keys, and parameters with credential-related names.

**Detection patterns:**
| Pattern             | Example                        | AST match                              |
| ------------------- | ------------------------------ | -------------------------------------- |
| Variable assignment | `$password = 'secret';`        | `Assign(Variable, String_)`            |
| Array item          | `['api_key' => 'abc123']`      | `ArrayItem(String_, String_)`          |
| Class constant      | `const DB_PASSWORD = 'root';`  | `ClassConst(String_)`                  |
| define() call       | `define('API_KEY', '...');`    | `FuncCall('define', String_, String_)` |
| Property default    | `private string $token = 'x';` | `Property(String_ default)`            |
| Parameter default   | `function f($pwd = 'root')`    | `Param(String_ default)`               |

**Sensitive name matching:**
- Suffix words (match anywhere): `password`, `passwd`, `pwd`, `secret`, `credential(s)`
- Compound "key" (only with qualifier): `apiKey`, `secretKey`, `privateKey`, `encryptionKey`, `signingKey`, `authKey`, `accessKey`
- Compound "token" (only with qualifier): `authToken`, `accessToken`, `bearerToken`, `apiToken`, `refreshToken`
- Context blacklists filter out non-credential names like `$passwordHash`, `$tokenStorage`, `$cacheKey`, `OPTION_PASSWORD`

**Value filtering:** skips empty strings, strings shorter than 4 characters, and strings of identical characters (`***`, `xxx`).

**Severity:** Error

**Configuration:**
```yaml
rules:
  security.hardcoded-credentials:
    enabled: true  # or false to disable
```

**CLI:**
```bash
--disable-rule=security.hardcoded-credentials  # Disable this rule
--disable-rule=security                        # Disable all security.* rules
--only-rule=security.hardcoded-credentials     # Enable only this rule
```

---

## Long Parameter List Rule

**Name:** `code-smell.long-parameter-list` | **Category:** CodeSmell | **Type:** Simple

Checks the number of parameters per method/function. Too many parameters indicate
a method may need a parameter object or is doing too much.

Unlike other code smell rules, this rule uses threshold-based options (`LongParameterListOptions`)
instead of `CodeSmellOptions`, allowing configurable warning/error thresholds.

**Default:** warning: 4, error: 6

**Configuration:**
```yaml
rules:
  code-smell.long-parameter-list:
    warning: 4
    error: 6
```

**CLI:** `--long-parameter-list-warning=4 --long-parameter-list-error=6`

---

## Unreachable Code Rule

**Name:** `code-smell.unreachable-code` | **Category:** CodeSmell | **Type:** Simple

Detects unreachable code after terminal statements (return, throw, exit/die, continue, break, goto).
Dead code should always be removed.

Unlike other code smell rules, this rule uses threshold-based options (`UnreachableCodeOptions`)
instead of `CodeSmellOptions`. By default, any unreachable code is an error (warning=1, error=1).

**Default:** warning: 1, error: 1

**Configuration:**
```yaml
rules:
  code-smell.unreachable-code:
    warning: 1
    error: 1
```

**CLI:** `--unreachable-code-warning=1 --unreachable-code-error=1`

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
    public const NAME = 'category.example';

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
- `--disable-rule=complexity` -> disables all complexity.* rules (prefix match)
- `--disable-rule=complexity.cyclomatic` -> disables the entire cyclomatic rule (all levels)
- `--disable-rule=complexity.cyclomatic.class` -> disables only class-level
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
  design.lcom:
    exclude_readonly: true
    min_methods: 3
  size.property-count:
    exclude_readonly: true
    exclude_promoted_only: true
  complexity.wmc:
    exclude_data_classes: false  # opt-in
  maintainability.index:
    exclude_tests: true
    min_loc: 10
```

---

## Related Documents

- [src/Core/README.md](../Core/README.md) — contracts and interfaces
- [src/Metrics/README.md](../Metrics/README.md) — metric collectors
- [docs/ARCHITECTURE.md](../../docs/ARCHITECTURE.md) — overall architecture
