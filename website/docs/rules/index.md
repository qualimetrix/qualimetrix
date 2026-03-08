# Rules Overview

AI Mess Detector ships with a set of built-in rules that check your PHP code for common quality problems. Each rule looks at a specific aspect of your code -- complexity, size, coupling, design, maintainability, or common bad practices -- and reports violations when thresholds are exceeded.

## Severity Levels

Every violation has one of two severity levels:

- **Warning** -- the code is getting harder to maintain. You should consider refactoring, but it is not critical yet.
- **Error** -- the code has crossed a threshold where it is likely to cause real problems: bugs, difficulty testing, or resistance to change. This needs attention.

You can customize all thresholds via configuration file or command-line options.

## Rules Summary

### Complexity Rules

These rules measure how tangled and branching your code is. Complex code is harder to understand, test, and change safely.

| Rule                                   | ID                      | What it checks                             | Default Warning | Default Error |
| -------------------------------------- | ----------------------- | ------------------------------------------ | --------------- | ------------- |
| [Cyclomatic Complexity](complexity.md) | `complexity.cyclomatic` | Number of decision paths in a method       | 10 (method)     | 20 (method)   |
| [Cognitive Complexity](complexity.md)  | `complexity.cognitive`  | How hard the code is to understand         | 15 (method)     | 30 (method)   |
| [NPath Complexity](complexity.md)      | `complexity.npath`      | Total number of possible execution paths   | 200 (method)    | 1000 (method) |
| [WMC](complexity.md)                   | `complexity.wmc`        | Total complexity of all methods in a class | 50              | 80            |

[Read more about Complexity rules --&gt;](complexity.md)

### Size Rules

These rules check whether your classes and namespaces have grown too large. Big classes tend to do too many things at once.

| Rule                      | ID                    | What it checks                   | Default Warning | Default Error |
| ------------------------- | --------------------- | -------------------------------- | --------------- | ------------- |
| [Method Count](size.md)   | `size.method-count`   | Number of methods in a class     | 20              | 30            |
| [Class Count](size.md)    | `size.class-count`    | Number of classes in a namespace | 15              | 25            |
| [Property Count](size.md) | `size.property-count` | Number of properties in a class  | 15              | 20            |

[Read more about Size rules --&gt;](size.md)

### Design Rules

These rules check class cohesion, inheritance depth, and structural problems.

| Rule                           | ID                     | What it checks                                      | Default Warning | Default Error |
| ------------------------------ | ---------------------- | --------------------------------------------------- | --------------- | ------------- |
| [LCOM](design.md)              | `design.lcom`          | Whether a class does too many unrelated things      | 3               | 5             |
| [Inheritance Depth](design.md) | `design.inheritance`   | How deep the inheritance chain is                   | 4               | 6             |
| [NOC](design.md)               | `design.noc`           | Number of classes inheriting from this one          | 10              | 15            |
| [Type Coverage](design.md)     | `design.type-coverage` | Percentage of typed parameters, returns, properties | 80% (below)     | 50% (below)   |

[Read more about Design rules --&gt;](design.md)

### Coupling Rules

These rules measure how tightly your classes depend on each other. Tightly coupled code is fragile -- a change in one place can break many others.

| Rule                       | ID                     | What it checks                                            | Default Warning | Default Error |
| -------------------------- | ---------------------- | --------------------------------------------------------- | --------------- | ------------- |
| [CBO](coupling.md)         | `coupling.cbo`         | Total number of dependencies                              | 14              | 20            |
| [Instability](coupling.md) | `coupling.instability` | How much a class depends on others vs others depend on it | 0.8             | 0.95          |
| [Distance](coupling.md)    | `coupling.distance`    | Balance between abstractness and stability                | 0.3             | 0.5           |

[Read more about Coupling rules --&gt;](coupling.md)

### Maintainability Rules

| Rule                                        | ID                      | What it checks                     | Default Warning | Default Error |
| ------------------------------------------- | ----------------------- | ---------------------------------- | --------------- | ------------- |
| [Maintainability Index](maintainability.md) | `maintainability.index` | Overall code maintainability score | &lt;40          | &lt;20        |

[Read more about Maintainability rules --&gt;](maintainability.md)

### Architecture Rules

| Rule                                     | ID                                 | What it checks                              | Default Warning | Default Error |
| ---------------------------------------- | ---------------------------------- | ------------------------------------------- | --------------- | ------------- |
| [Circular Dependencies](architecture.md) | `architecture.circular-dependency` | Classes that depend on each other in a loop | --              | Error         |

[Read more about Architecture rules --&gt;](architecture.md)

### Code Smell Rules

These rules detect common bad practices that are almost always wrong, regardless of context. Most produce an **Error** severity by default.

| Rule                                 | ID                               | What it detects                                |
| ------------------------------------ | -------------------------------- | ---------------------------------------------- |
| [Boolean Argument](code-smell.md)    | `code-smell.boolean-argument`    | `bool` parameters in method signatures         |
| [Count in Loop](code-smell.md)       | `code-smell.count-in-loop`       | Calling `count()` in a loop condition          |
| [Debug Code](code-smell.md)          | `code-smell.debug-code`          | `var_dump`, `print_r`, `debug_backtrace`, etc. |
| [Empty Catch](code-smell.md)         | `code-smell.empty-catch`         | `catch` blocks with no body                    |
| [Error Suppression](code-smell.md)   | `code-smell.error-suppression`   | The `@` error suppression operator             |
| [Eval](code-smell.md)                | `code-smell.eval`                | Use of `eval()`                                |
| [Exit](code-smell.md)                | `code-smell.exit`                | Use of `exit()` or `die()`                     |
| [Goto](code-smell.md)                | `code-smell.goto`                | Use of `goto`                                  |
| [Superglobals](code-smell.md)        | `code-smell.superglobals`        | Direct access to `$_GET`, `$_POST`, etc.       |
| [Long Parameter List](code-smell.md) | `code-smell.long-parameter-list` | Methods with too many parameters               |
| [Unreachable Code](code-smell.md)    | `code-smell.unreachable-code`    | Code after return/throw/exit statements        |

[Read more about Code Smell rules --&gt;](code-smell.md)

### Security Rules

These rules detect patterns that may introduce security vulnerabilities.

| Rule                                 | ID                               | What it detects                     |
| ------------------------------------ | -------------------------------- | ----------------------------------- |
| [Hardcoded Credentials](security.md) | `security.hardcoded-credentials` | Passwords, API keys, tokens in code |

[Read more about Security rules --&gt;](security.md)

## Disabling Rules

You can disable individual rules or entire groups:

```bash
# Disable a single rule
bin/aimd check src/ --disable-rule=complexity.npath

# Disable an entire group (prefix matching)
bin/aimd check src/ --disable-rule=code-smell
```

## Customizing Thresholds

Override any threshold via the command line:

```bash
bin/aimd check src/ --rule-opt="complexity.cyclomatic:method.warning=15"
bin/aimd check src/ --rule-opt="size.method-count:warning=25"
```

Or in your `aimd.yaml` configuration file:

```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 15
      error: 25
  size.method-count:
    warning: 25
    error: 40
```
