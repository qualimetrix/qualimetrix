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

| Rule                                     | Category        | Type                            | Description                     | Default Thresholds                    |
| ---------------------------------------- | --------------- | ------------------------------- | ------------------------------- | ------------------------------------- |
| **complexity.cyclomatic**                | Complexity      | Hierarchical (Method, Class)    | Cyclomatic Complexity (CCN)     | method: 10/20, class.max: 30/50       |
| **complexity.cognitive**                 | Complexity      | Hierarchical (Method, Class)    | Cognitive Complexity            | method: 15/30, class.max: 30/50       |
| **complexity.npath**                     | Complexity      | Hierarchical (Method, Class)    | NPATH Complexity                | method: 200/1000, class (disabled)    |
| **complexity.wmc**                       | Complexity      | Simple                          | Weighted Methods per Class      | warning: 50, error: 80                |
| **size.method-count**                    | Size            | Simple                          | Method count per class          | warning: 20, error: 30                |
| **size.class-count**                     | Size            | Simple                          | Class count per namespace       | warning: 15, error: 25                |
| **size.property-count**                  | Size            | Simple                          | Class property count            | warning: 15, error: 20                |
| **maintainability.index**                | Maintainability | Simple                          | Maintainability Index           | warning: 40, error: 20                |
| **design.lcom**                          | Design          | Simple                          | Lack of Cohesion (LCOM4)        | warning: 3, error: 5                  |
| **design.noc**                           | Design          | Simple                          | Number of Children              | warning: 10, error: 15                |
| **design.inheritance**                   | Design          | Simple                          | Depth of Inheritance Tree (DIT) | warning: 4, error: 6                  |
| **coupling.instability**                 | Coupling        | Hierarchical (Class, Namespace) | Instability (Ca/Ce)             | warning: 0.8, error: 0.95             |
| **coupling.cbo**                         | Coupling        | Hierarchical (Class, Namespace) | Coupling Between Objects        | warning: ..., error: ...              |
| **coupling.distance**                    | Coupling        | Simple                          | Distance from Main Sequence     | warning: 0.3, error: 0.5              |
| **coupling.class-rank**                  | Coupling        | Simple                          | ClassRank (PageRank on deps)    | warning: 0.02, error: 0.05 (scaled)   |
| **architecture.circular-dependency**     | Architecture    | Simple                          | Circular dependencies           | enabled: true                         |
| **code-smell.boolean-argument**          | CodeSmell       | Simple                          | Boolean arguments in signatures | enabled: true                         |
| **code-smell.count-in-loop**             | CodeSmell       | Simple                          | count() calls in loops          | enabled: true                         |
| **code-smell.debug-code**                | CodeSmell       | Simple                          | Debug code (var_dump, etc.)     | enabled: true                         |
| **code-smell.empty-catch**               | CodeSmell       | Simple                          | Empty catch blocks              | enabled: true                         |
| **code-smell.error-suppression**         | CodeSmell       | Simple                          | Error suppression operator (@)  | enabled: true                         |
| **code-smell.eval**                      | CodeSmell       | Simple                          | eval() usage                    | enabled: true                         |
| **code-smell.exit**                      | CodeSmell       | Simple                          | exit/die usage                  | enabled: true                         |
| **code-smell.goto**                      | CodeSmell       | Simple                          | goto statements                 | enabled: true                         |
| **code-smell.constructor-overinjection** | CodeSmell       | Simple                          | Constructor over-injection      | warning: 8, error: 12                 |
| **code-smell.data-class**                | CodeSmell       | Simple                          | Data Class detection            | wocThreshold: 80, wmcThreshold: 10    |
| **code-smell.god-class**                 | CodeSmell       | Simple                          | God Class detection (L&M)       | wmc: 47, lcom: 3, tcc: 0.33, loc: 300 |
| **code-smell.long-parameter-list**       | CodeSmell       | Simple                          | Long parameter lists            | warning: 4, error: 6                  |
| **code-smell.superglobals**              | CodeSmell       | Simple                          | Direct superglobal access       | enabled: true                         |
| **code-smell.unreachable-code**          | CodeSmell       | Simple                          | Unreachable code detection      | warning: 1, error: 2                  |
| **design.type-coverage**                 | Design          | Simple                          | Type declaration coverage       | param/return/property: 80%/50%        |
| **security.hardcoded-credentials**       | Security        | Simple                          | Hardcoded credentials           | enabled: true                         |
| **security.sql-injection**               | Security        | Simple                          | SQL injection patterns          | enabled: true                         |
| **security.xss**                         | Security        | Simple                          | XSS patterns                    | enabled: true                         |
| **security.command-injection**           | Security        | Simple                          | Command injection patterns      | enabled: true                         |
| **security.sensitive-parameter**         | Security        | Simple                          | Missing #[\SensitiveParameter]  | enabled: true                         |
| **code-smell.unused-private**            | CodeSmell       | Simple                          | Unused private methods/props    | enabled: true                         |
| **code-smell.identical-subexpression**   | CodeSmell       | Simple                          | Identical sub-expressions       | enabled: true                         |
| **duplication.code-duplication**         | Duplication     | Simple                          | Duplicate code blocks           | min_lines: 5, min_tokens: 70, W/E     |
| **computed.health**                      | Maintainability | Simple                          | Computed health metric checks   | per-definition thresholds             |

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
- Report violations per occurrence with precise line numbers
- Have no CLI aliases (use `--disable-rule=<name>` to disable)

**Severity:** Most code smell rules produce **Warning** severity violations. Exceptions: `DebugCodeRule`, `EmptyCatchRule`, `EvalRule`, and `GotoRule` produce **Error** severity.

| Rule                                   | Description                     | What it detects                                                                            |
| -------------------------------------- | ------------------------------- | ------------------------------------------------------------------------------------------ |
| **code-smell.boolean-argument**        | Boolean arguments in signatures | `function save(bool $overwrite)` — suggests splitting methods or using enums               |
| **code-smell.count-in-loop**           | count() calls in loops          | `for ($i = 0; $i < count($arr); $i++)` — should be extracted to a variable                 |
| **code-smell.debug-code**              | Debug code                      | `var_dump()`, `print_r()`, `dd()`, `dump()`, etc.                                          |
| **code-smell.empty-catch**             | Empty catch blocks              | `catch (Exception $e) {}` — should at least log the error                                  |
| **code-smell.error-suppression**       | Error suppression operator      | `@fopen()` — hides errors, use proper error handling                                       |
| **code-smell.eval**                    | eval() usage                    | `eval($code)` — security risk, usually avoidable                                           |
| **code-smell.exit**                    | exit/die usage                  | `exit(1)`, `die()` — should not be used in library/application code                        |
| **code-smell.goto**                    | goto statements                 | `goto label;` — makes control flow hard to follow                                          |
| **code-smell.superglobals**            | Direct superglobal access       | `$_GET`, `$_POST`, `$_SERVER` — use request abstraction                                    |
| **code-smell.identical-subexpression** | Identical sub-expressions       | Identical operands, duplicate conditions, identical ternary branches, duplicate match arms |

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

### Security Pattern Rules

Three rules that detect common security vulnerabilities by analyzing data flow from user input to dangerous sinks.

| Rule                           | Description                                              | Severity |
| ------------------------------ | -------------------------------------------------------- | -------- |
| **security.sql-injection**     | SQL injection via string concat/interpolation in queries | Error    |
| **security.xss**               | XSS via unescaped output (echo, print)                   | Error    |
| **security.command-injection** | Command injection via shell functions with variables     | Error    |

All three extend `AbstractSecurityPatternRule` and use `SecurityPatternOptions` (single `enabled` option).

**Configuration:**
```yaml
rules:
  security.sql-injection:
    enabled: true
  security.xss:
    enabled: true
  security.command-injection:
    enabled: true
```

**CLI:**
```bash
--disable-rule=security.sql-injection     # Disable a specific pattern rule
--disable-rule=security                   # Disable all security.* rules
```

---

### Sensitive Parameter Rule

**Name:** `security.sensitive-parameter` | **Category:** Security | **Type:** Simple

Detects function/method parameters with sensitive names (e.g., `$password`, `$secret`, `$apiKey`) that lack the `#[\SensitiveParameter]` attribute (PHP 8.2+). This attribute prevents sensitive values from appearing in stack traces.

**Severity:** Warning

**Configuration:**
```yaml
rules:
  security.sensitive-parameter:
    enabled: true
```

**CLI:**
```bash
--disable-rule=security.sensitive-parameter
```

---

## ClassRank Rule

**Name:** `coupling.class-rank` | **Category:** Coupling | **Type:** Simple

Checks ClassRank — a PageRank-based metric computed on the dependency graph. High ClassRank indicates a class is heavily depended upon (directly and transitively), making it a critical coupling point.

**Threshold scaling:** Since PageRank sums to 1.0, individual class ranks dilute as the project grows. Thresholds are automatically scaled by `sqrt(classCount / 100)`: at 100 classes thresholds are unchanged, at 1600 classes they are divided by 4, at 25 classes they are multiplied by 2.

**Default (base thresholds):** warning: 0.02, error: 0.05

**Configuration:**
```yaml
rules:
  coupling.class-rank:
    warning: 0.02
    error: 0.05
```

**CLI:** `--class-rank-warning=0.02 --class-rank-error=0.05`

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
instead of `CodeSmellOptions`. By default, any unreachable code produces a warning (warning=1, error=2).

**Default:** warning: 1, error: 2

**Configuration:**
```yaml
rules:
  code-smell.unreachable-code:
    warning: 1
    error: 2
```

**CLI:** `--unreachable-code-warning=1 --unreachable-code-error=2`

---

## Code Duplication Rule

**Name:** `duplication.code-duplication` | **Category:** Duplication | **Type:** Simple

Detects duplicate code blocks across analyzed files using token-based comparison. Violations include `relatedLocations` pointing to all other occurrences of the same block.

**Default:** min_lines: 5, min_tokens: 70, severity: Warning (Error for large blocks)

**Configuration:**
```yaml
rules:
  duplication.code-duplication:
    min_lines: 5
    min_tokens: 70
```

**CLI:** `--duplication-min-lines=5 --duplication-min-tokens=70`

**Files:**
- `src/Rules/Duplication/CodeDuplicationRule.php` — rule implementation
- `src/Rules/Duplication/CodeDuplicationOptions.php` — rule options

---

## Computed Metric Rule

**Name:** `computed.health` | **Category:** Maintainability | **Type:** Simple

Checks computed health metrics against thresholds. Evaluates derived metrics defined in the `computed_metrics` config section (or 6 built-in `health.*` scores) and generates violations when values cross thresholds.

**Built-in health scores (inverted — higher is better, 0-100):**

| Metric                   | Default Warning | Default Error | Components                        |
| ------------------------ | --------------- | ------------- | --------------------------------- |
| `health.complexity`      | 50              | 25            | CCN + Cognitive (avg, p95, max)   |
| `health.cohesion`        | 50              | 25            | TCC + LCOM                        |
| `health.coupling`        | 50              | 25            | CBO + Distance from Main Sequence |
| `health.typing`          | 80              | 50            | Type Coverage Percentage          |
| `health.maintainability` | 50              | 25            | Maintainability Index (stretched) |
| `health.overall`         | 50              | 30            | Weighted average of 5 sub-scores  |

All health scores are computed at class, namespace, and project levels with per-level formulas.

**Configuration:**
```yaml
computed_metrics:
  # Override default thresholds
  health.complexity:
    warning: 50
    error: 25
  # Disable a health score
  health.typing:
    enabled: false
  # Define a custom metric
  computed.risk_score:
    formula: "ccn__avg * (1 - (tcc__avg ?? 0))"
    levels: [namespace]
    warning: 30
    error: 60
```

**CLI:**
```bash
--disable-rule=computed.health    # Disable all computed metric violations
--disable-rule=health              # Disable health.* violations (prefix match on violationCode)
--disable-rule=health.complexity   # Disable a specific health score
```

**Files:**
- `src/Rules/ComputedMetric/ComputedMetricRule.php` — rule implementation
- `src/Rules/ComputedMetric/ComputedMetricRuleOptions.php` — rule options (reads from ComputedMetricDefinitionHolder)

---

## Unused Private Rule

**Name:** `code-smell.unused-private` | **Category:** CodeSmell | **Type:** Simple

Detects unused private methods and properties in classes.

**Configuration:**
```yaml
rules:
  code-smell.unused-private:
    enabled: true
```

**CLI:** `--disable-rule=code-smell.unused-private`

---

## Identical Sub-Expression Rule

**Name:** `code-smell.identical-subexpression` | **Category:** CodeSmell | **Type:** Simple

Detects identical sub-expressions that indicate copy-paste errors or logic bugs. Four detection types:

| Detection Type                 | Example                            | What it catches                              |
| ------------------------------ | ---------------------------------- | -------------------------------------------- |
| Identical binary operands      | `$a === $a`, `$a - $a`             | Same expression on both sides of an operator |
| Duplicate if/elseif conditions | `if ($a) {} elseif ($a) {}`        | Repeated conditions in if/elseif chains      |
| Identical ternary branches     | `$cond ? $x : $x`                  | Same expression in both ternary branches     |
| Duplicate match arm conditions | `match($x) { 1 => ..., 1 => ... }` | Repeated conditions in match arms            |

Side-effect expressions (function calls, method calls, etc.) are excluded to avoid false positives.

**Severity:** Warning

**Configuration:**
```yaml
rules:
  code-smell.identical-subexpression:
    enabled: true  # or false to disable
```

**CLI:**
```bash
--disable-rule=code-smell.identical-subexpression  # Disable this rule
--disable-rule=code-smell                          # Disable all code-smell.* rules
```

**Files:**
- `src/Rules/CodeSmell/IdenticalSubExpressionRule.php` — rule implementation
- `src/Rules/CodeSmell/IdenticalSubExpressionOptions.php` — rule options (simple enabled/disabled)

---

## Universal Per-Rule Options

These options are available for **any** rule and are handled at framework level by `RuleExecutor`:

| Option               | Type           | Description                                             |
| -------------------- | -------------- | ------------------------------------------------------- |
| `exclude_namespaces` | `list<string>` | Namespaces to exclude from violations (prefix matching) |

```yaml
rules:
  complexity.cyclomatic:
    exclude_namespaces: [App\Tests, App\Legacy]
  coupling.cbo:
    exclude_namespaces: [App\Tests]
```

Violations with `symbolPath->namespace` matching any prefix are filtered out by `RuleExecutor`.
File-level violations (namespace = null) and global namespace violations (namespace = '') are never filtered.

The option is extracted by `RuleOptionsFactory` and stored in `RuleNamespaceExclusionProvider`,
so it does not leak into `Options::fromArray()`.

---

## Threshold Semantics

Violations are triggered when a metric value meets or exceeds the threshold (`>=`). For inverted metrics like Maintainability Index and Type Coverage, violations are triggered when the value is below the threshold (`<`).

---

## Creating a New Rule

### Simple Rule

1. Create a `{Name}Rule extends AbstractRule` class
2. Implement `requires(): array` — required metrics
3. Implement `analyze(AnalysisContext): array` — validation logic
4. Create a `{Name}Options implements RuleOptionsInterface` class
5. Write unit tests
6. Add value hints to `src/Reporting/Template/src/hints.js` — range-based interpretations for the HTML report
7. Add "How to read the value" table to `website/docs/rules/` page (both EN and RU)

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
            if ($value >= $this->options->threshold) {
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
