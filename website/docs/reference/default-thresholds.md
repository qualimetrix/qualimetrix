# Default Thresholds Reference

This page lists the default thresholds for every rule in AI Mess Detector. When a metric exceeds the **warning** threshold, a warning is reported. When it exceeds the **error** threshold, an error is reported.

## Complexity Rules

Rules that measure how hard code is to understand and test.

| Rule                  | ID                      | Level       | Warning | Error | Scope            |
| --------------------- | ----------------------- | ----------- | ------- | ----- | ---------------- |
| Cyclomatic Complexity | `complexity.cyclomatic` | Method      | 10      | 20    | Method           |
| Cyclomatic Complexity | `complexity.cyclomatic` | Class (max) | 30      | 50    | Class            |
| Cognitive Complexity  | `complexity.cognitive`  | Method      | 15      | 30    | Method           |
| Cognitive Complexity  | `complexity.cognitive`  | Class (max) | 30      | 50    | Class            |
| NPath Complexity      | `complexity.npath`      | Method      | 200     | 1000  | Method           |
| NPath Complexity      | `complexity.npath`      | Class (max) | 500     | 1000  | Class (disabled) |
| WMC                   | `complexity.wmc`        | -           | 50      | 80    | Class            |

**Cyclomatic Complexity** counts the number of independent paths through a method. A method with CCN of 10 has 10 different paths to test.

**Cognitive Complexity** measures how hard code is to read. Unlike cyclomatic complexity, it penalizes nested structures more heavily.

**NPath Complexity** counts the number of possible execution paths. It grows much faster than cyclomatic complexity for code with many conditions.

**WMC (Weighted Methods per Class)** is the sum of cyclomatic complexities of all methods in a class. A high WMC means the class is doing too much.

## Size Rules

Rules that check if classes and namespaces are too large.

| Rule           | ID                    | Warning | Error | Scope     |
| -------------- | --------------------- | ------- | ----- | --------- |
| Method Count   | `size.method-count`   | 20      | 30    | Class     |
| Class Count    | `size.class-count`    | 15      | 25    | Namespace |
| Property Count | `size.property-count` | 15      | 20    | Class     |

## Design Rules

Rules that check class design and inheritance structure.

| Rule                     | ID                     | Warning    | Error      | Scope |
| ------------------------ | ---------------------- | ---------- | ---------- | ----- |
| LCOM                     | `design.lcom`          | 3          | 5          | Class |
| NOC                      | `design.noc`           | 10         | 15         | Class |
| DIT                      | `design.inheritance`   | 4          | 6          | Class |
| Type Coverage (param)    | `design.type-coverage` | 80 (below) | 50 (below) | Class |
| Type Coverage (return)   | `design.type-coverage` | 80 (below) | 50 (below) | Class |
| Type Coverage (property) | `design.type-coverage` | 80 (below) | 50 (below) | Class |

**LCOM (Lack of Cohesion of Methods)** measures how well the methods in a class belong together. A high LCOM suggests the class should be split.

**NOC (Number of Children)** counts direct subclasses. Too many children means the parent class may be too general.

**DIT (Depth of Inheritance Tree)** counts how many levels of inheritance a class has. Deep hierarchies are harder to understand and maintain.

**Type Coverage** measures the percentage of typed declarations. Unlike most rules, violations are reported when values fall **below** the threshold.

## Coupling Rules

Rules that check how tightly classes and namespaces are connected to each other.

| Rule        | ID                     | Warning | Error | Scope     |
| ----------- | ---------------------- | ------- | ----- | --------- |
| CBO         | `coupling.cbo`         | 14      | 20    | Class     |
| CBO         | `coupling.cbo`         | 14      | 20    | Namespace |
| Instability | `coupling.instability` | 0.8     | 0.95  | Class     |
| Instability | `coupling.instability` | 0.8     | 0.95  | Namespace |
| Distance    | `coupling.distance`    | 0.3     | 0.5   | Namespace |
| ClassRank   | `coupling.class-rank`  | 0.02    | 0.05  | Class     |

**CBO (Coupling Between Objects)** counts the number of other classes a class depends on. High coupling makes code harder to change.

**Instability** is a ratio from 0 (fully stable) to 1 (fully unstable). A class that depends on many others but is not depended upon is unstable.

**Distance from the Main Sequence** measures how well a namespace balances abstractness and stability. A distance close to 0 is ideal.

**ClassRank** uses the PageRank algorithm on the dependency graph to identify the most critical classes. Ranks sum to 1.0 across the project; a high rank means many (or important) classes depend on it.

## Maintainability Rules

These rules are **inverted**: a violation is reported when the metric falls **below** the threshold, not above it.

| Rule                  | ID                      | Warning (below) | Error (below) | Scope  |
| --------------------- | ----------------------- | --------------- | ------------- | ------ |
| Maintainability Index | `maintainability.index` | 40              | 20            | Method |

**Maintainability Index** combines complexity, lines of code, and Halstead metrics into a single score from 0 to 100. Higher is better. A score below 20 means the code is very hard to maintain.

## Code Smell Rules

These rules detect specific patterns that are usually bad practice. Most do not have numeric thresholds -- they either find the pattern or they don't. Two rules (Long Parameter List and Unreachable Code) use numeric thresholds.

| Rule              | ID                             | Severity | Default |
| ----------------- | ------------------------------ | -------- | ------- |
| Boolean Argument  | `code-smell.boolean-argument`  | Warning  | enabled |
| count() in Loop   | `code-smell.count-in-loop`     | Warning  | enabled |
| Debug Code        | `code-smell.debug-code`        | Error    | enabled |
| Empty Catch       | `code-smell.empty-catch`       | Error    | enabled |
| Error Suppression | `code-smell.error-suppression` | Warning  | enabled |
| eval()            | `code-smell.eval`              | Error    | enabled |
| exit()/die()      | `code-smell.exit`              | Warning  | enabled |
| goto              | `code-smell.goto`              | Error    | enabled |
| Superglobals      | `code-smell.superglobals`      | Warning  | enabled |
| Long Parameter List | `code-smell.long-parameter-list` | 4 params | 6 params | enabled |
| Unreachable Code  | `code-smell.unreachable-code`  | 1        | 2        | enabled |
| Unused Private    | `code-smell.unused-private`    | Warning  | -        | enabled |
| Identical Sub-expression | `code-smell.identical-subexpression` | Warning | - | enabled |

## Duplication Rules

Rules that detect duplicated code.

| Rule             | ID                             | Warning   | Error      | Scope  |
| ---------------- | ------------------------------ | --------- | ---------- | ------ |
| Code Duplication | `duplication.code-duplication` | <50 lines | >=50 lines | Method |

**Code Duplication** detects duplicate code blocks. Configured with `min_lines: 5` and `min_tokens: 70` -- blocks shorter than these thresholds are ignored. Duplicates under 50 lines produce a warning; 50 lines or more produce an error.

## Security Rules

Rules that detect potential security vulnerabilities.

| Rule                  | ID                               | Severity | Default |
| --------------------- | -------------------------------- | -------- | ------- |
| Hardcoded Credentials | `security.hardcoded-credentials` | Error    | enabled |
| SQL Injection         | `security.sql-injection`         | Error    | enabled |
| XSS                   | `security.xss`                   | Error    | enabled |
| Command Injection     | `security.command-injection`     | Error    | enabled |
| Sensitive Parameter   | `security.sensitive-parameter`   | Warning  | enabled |

**Hardcoded Credentials** detects passwords, API keys, and tokens hardcoded directly in source code.

**SQL Injection** detects superglobals used in SQL contexts without parameterized queries.

**XSS** detects unsanitized superglobals in `echo`/`print` statements.

**Command Injection** detects superglobals passed to shell execution functions without escaping.

**Sensitive Parameter** detects parameters with sensitive names missing the `#[\SensitiveParameter]` attribute.

## How to Customize Thresholds

### Using a YAML Config File

Create an `aimd.yaml` file in your project root:

```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 15
      error: 30
    class:
      max_warning: 40
      max_error: 60

  size.method-count:
    warning: 25
    error: 40

  coupling.cbo:
    warning: 18
    error: 25

  maintainability.index:
    warning: 30
    error: 15
```

Then run the analysis with the config file:

```bash
vendor/bin/aimd check src/ --config=aimd.yaml
```

### Disabling Rules

To disable a rule entirely, set `enabled: false`:

```yaml
rules:
  code-smell.boolean-argument:
    enabled: false
```

### Disabling a Group of Rules

You can disable all rules in a group via the CLI:

```bash
vendor/bin/aimd check src/ --disable-rule=code-smell
```

This disables all rules whose ID starts with `code-smell.`.

### Using the CLI

Override thresholds from the command line:

```bash
vendor/bin/aimd check src/ --disable-rule=complexity.npath
```

### Suppressing Individual Violations

Add `@aimd-ignore` in a docblock to suppress a specific violation:

```php
/**
 * @aimd-ignore complexity.cyclomatic
 */
function complexButNecessary(): void
{
    // ...
}
```

You can also suppress all rules in a group:

```php
/**
 * @aimd-ignore complexity
 */
```
