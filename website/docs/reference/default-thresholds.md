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
| NPath Complexity      | `complexity.npath`      | Class (max) | 200     | 1000  | Class (disabled) |
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

| Rule | ID                   | Warning | Error | Scope |
| ---- | -------------------- | ------- | ----- | ----- |
| LCOM | `design.lcom`        | 3       | 5     | Class |
| NOC  | `design.noc`         | 10      | 15    | Class |
| DIT  | `design.inheritance` | 4       | 6     | Class |

**LCOM (Lack of Cohesion of Methods)** measures how well the methods in a class belong together. A high LCOM suggests the class should be split.

**NOC (Number of Children)** counts direct subclasses. Too many children means the parent class may be too general.

**DIT (Depth of Inheritance Tree)** counts how many levels of inheritance a class has. Deep hierarchies are harder to understand and maintain.

## Coupling Rules

Rules that check how tightly classes and namespaces are connected to each other.

| Rule        | ID                     | Warning | Error | Scope     |
| ----------- | ---------------------- | ------- | ----- | --------- |
| CBO         | `coupling.cbo`         | 14      | 20    | Class     |
| CBO         | `coupling.cbo`         | 14      | 20    | Namespace |
| Instability | `coupling.instability` | 0.8     | 0.95  | Class     |
| Instability | `coupling.instability` | 0.8     | 0.95  | Namespace |
| Distance    | `coupling.distance`    | 0.3     | 0.5   | Namespace |

**CBO (Coupling Between Objects)** counts the number of other classes a class depends on. High coupling makes code harder to change.

**Instability** is a ratio from 0 (fully stable) to 1 (fully unstable). A class that depends on many others but is not depended upon is unstable.

**Distance from the Main Sequence** measures how well a namespace balances abstractness and stability. A distance close to 0 is ideal.

## Maintainability Rules

These rules are **inverted**: a violation is reported when the metric falls **below** the threshold, not above it.

| Rule                  | ID                      | Warning (below) | Error (below) | Scope  |
| --------------------- | ----------------------- | --------------- | ------------- | ------ |
| Maintainability Index | `maintainability.index` | 40              | 20            | Method |

**Maintainability Index** combines complexity, lines of code, and Halstead metrics into a single score from 0 to 100. Higher is better. A score below 20 means the code is very hard to maintain.

## Code Smell Rules

These rules detect specific patterns that are usually bad practice. They do not have numeric thresholds -- they either find the pattern or they don't.

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

## How to Customize Thresholds

### Using a YAML Config File

Create an `aimd.yaml` file in your project root:

```yaml
rules:
  complexity.cyclomatic:
    method_warning: 15
    method_error: 30
    class_warning: 40
    class_error: 60

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
