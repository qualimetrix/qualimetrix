# Rules Overview

Qualimetrix ships with a set of built-in rules that check your PHP code for common quality problems. Each rule looks at a specific aspect of your code -- complexity, size, coupling, design, maintainability, or common bad practices -- and reports violations when thresholds are exceeded.

## Rule IDs and Judged Metrics

A rule ID and a metric key are two names in two vocabularies, and they are not the same name even where they look alike. The rule ID -- `complexity.ccn` -- is what you configure, suppress and retune. The metric key -- `complexity.ccn` -- is the measured number the rule compares against its thresholds.

Where a rule reads its number out of the metric catalog, its section on the group page names it as **Judged metric**, right below the rule ID; `bin/qmx rules` prints the same pair. Rules that report a number of their own making -- a cycle's member count, a count of matched criteria -- name no metric, and neither does the listing.

## Severity Levels

Every violation has one of two severity levels:

- **Warning** -- the code is getting harder to maintain. You should consider refactoring, but it is not critical yet.
- **Error** -- the code has crossed a threshold where it is likely to cause real problems: bugs, difficulty testing, or resistance to change. This needs attention.

You can customize all thresholds via configuration file or command-line options.

## Rules Summary

<!-- llms:skip-begin -->
### Complexity Rules

These rules measure how tangled and branching your code is. Complex code is harder to understand, test, and change safely.

| Rule                                   | ID                     | What it checks                             | Default Warning | Default Error |
| -------------------------------------- | ---------------------- | ------------------------------------------ | --------------- | ------------- |
| [Cyclomatic Complexity](complexity.md) | `complexity.ccn`       | Number of decision paths in a method       | 10 (method)     | 20 (method)   |
| [Cognitive Complexity](complexity.md)  | `complexity.cognitive` | How hard the code is to understand         | 15 (method)     | 30 (method)   |
| [NPath Complexity](complexity.md)      | `complexity.npath`     | Total number of possible execution paths   | 200 (method)    | 1000 (method) |
| [WMC](complexity.md)                   | `complexity.wmc`       | Total complexity of all methods in a class | 50              | 80            |

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

These rules check inheritance depth, type coverage, and structural problems.

| Rule                                 | ID                              | What it checks                                         | Default Warning | Default Error |
| ------------------------------------ | ------------------------------- | ------------------------------------------------------ | --------------- | ------------- |
| [Inheritance Depth](design.md)       | `design.dit`                    | How deep the inheritance chain is                      | 4               | 6             |
| [NOC](design.md)                     | `design.noc`                    | Number of classes inheriting from this one             | 10              | 15            |
| [Parameter Type Coverage](design.md) | `design.type-coverage.param`    | Percentage of typed parameters                         | 80% (below)     | 50% (below)   |
| [Return Type Coverage](design.md)    | `design.type-coverage.return`   | Percentage of typed return declarations                | 80% (below)     | 50% (below)   |
| [Property Type Coverage](design.md)  | `design.type-coverage.property` | Percentage of typed properties                         | 80% (below)     | 50% (below)   |
| [Data Class](design.md)              | `design.data-class`             | Public interface is mostly data access, low complexity | Warning         | --            |
| [God Class](design.md)               | `design.god-class`              | Overly complex, large classes with low cohesion        | 3+ criteria     | all criteria  |

[Read more about Design rules --&gt;](design.md)

### Cohesion Rules

These rules measure how well the methods inside a class work together. Low cohesion indicates a class is doing too many unrelated things.

| Rule                | ID              | What it checks                                 | Default Warning | Default Error |
| ------------------- | --------------- | ---------------------------------------------- | --------------- | ------------- |
| [LCOM](cohesion.md) | `cohesion.lcom` | Whether a class does too many unrelated things | 3               | 5             |

| Metric             | ID             | What it checks                                     | Recommended |
| ------------------ | -------------- | -------------------------------------------------- | ----------- |
| [TCC](cohesion.md) | `cohesion.tcc` | Fraction of public method pairs sharing properties | >= 0.5      |
| [LCC](cohesion.md) | `cohesion.lcc` | Fraction including transitive connections          | >= 0.5      |

!!! note
    TCC and LCC are **metrics**, not rules. They cannot be enabled or disabled via `--disable-rule` / `--only-rule`, and do not generate violations. They appear in reports as informational values and are used by the God Class rule as inputs. LCOM, above, is a rule with its own thresholds.

[Read more about Cohesion rules --&gt;](cohesion.md)

### Coupling Rules

These rules measure how tightly your classes depend on each other. Tightly coupled code is fragile -- a change in one place can break many others.

| Rule                       | ID                     | What it checks                                            | Default Warning | Default Error |
| -------------------------- | ---------------------- | --------------------------------------------------------- | --------------- | ------------- |
| [CBO](coupling.md)         | `coupling.cbo`         | Total number of dependencies                              | 14              | 20            |
| [Instability](coupling.md) | `coupling.instability` | How much a class depends on others vs others depend on it | 0.8             | 0.95          |
| [Distance](coupling.md)    | `coupling.distance`    | Balance between abstractness and stability                | 0.3             | 0.5           |
| [ClassRank](coupling.md)   | `coupling.class-rank`  | Critical hub classes via PageRank algorithm               | 0.02            | 0.05          |

[Read more about Coupling rules --&gt;](coupling.md)

### Maintainability Rules

| Rule                                        | ID                   | What it checks                     | Default Warning | Default Error |
| ------------------------------------------- | -------------------- | ---------------------------------- | --------------- | ------------- |
| [Maintainability Index](maintainability.md) | `maintainability.mi` | Overall code maintainability score | &lt;40          | &lt;20        |

[Read more about Maintainability rules --&gt;](maintainability.md)

### Architecture Rules

| Rule                                     | ID                                 | What it checks                                            | Default Warning | Default Error |
| ---------------------------------------- | ---------------------------------- | --------------------------------------------------------- | --------------- | ------------- |
| [Circular Dependencies](architecture.md) | `architecture.circular-dependency` | Classes that depend on each other in a loop               | --              | Error         |
| [Layer Violations](architecture.md)      | `architecture.layer-violation`     | Inter-layer dependencies that violate the declared policy | --              | Warning       |

[Read more about Architecture rules --&gt;](architecture.md)

### Duplication Rules

These rules detect duplicated code blocks across your codebase using token-stream analysis.

| Rule                               | ID                  | What it detects                                 | Default Warning | Default Error |
| ---------------------------------- | ------------------- | ----------------------------------------------- | --------------- | ------------- |
| [Code Duplication](duplication.md) | `duplication.clone` | Structurally identical code blocks across files | < 50 lines      | >= 50 lines   |

[Read more about Duplication rules --&gt;](duplication.md)

### Code Smell Rules

These rules detect common bad practices that are almost always wrong, regardless of context. Most produce an **Error** severity by default.

| Rule                                        | ID                                     | What it detects                                                 |
| ------------------------------------------- | -------------------------------------- | --------------------------------------------------------------- |
| [Boolean Argument](code-smell.md)           | `code-smell.boolean-argument`          | `bool` parameters in method signatures                          |
| [Count in Loop](code-smell.md)              | `code-smell.count-in-loop`             | Calling `count()` in a loop condition                           |
| [Debug Code](code-smell.md)                 | `code-smell.debug-code`                | `var_dump`, `print_r`, `debug_backtrace`, etc.                  |
| [Empty Catch](code-smell.md)                | `code-smell.empty-catch`               | `catch` blocks with no body                                     |
| [Error Suppression](code-smell.md)          | `code-smell.error-suppression`         | The `@` error suppression operator                              |
| [Eval](code-smell.md)                       | `code-smell.eval`                      | Use of `eval()`                                                 |
| [Exit](code-smell.md)                       | `code-smell.exit`                      | Use of `exit()` or `die()`                                      |
| [Goto](code-smell.md)                       | `code-smell.goto`                      | Use of `goto`                                                   |
| [Superglobals](code-smell.md)               | `code-smell.superglobals`              | Direct access to `$_GET`, `$_POST`, etc.                        |
| [Long Parameter List](code-smell.md)        | `code-smell.long-parameter-list`       | Methods with too many parameters                                |
| [Unreachable Code](code-smell.md)           | `code-smell.unreachable-code`          | Code after return/throw/exit statements                         |
| [Identical Sub-expression](code-smell.md)   | `code-smell.identical-subexpression`   | Identical operands, duplicate conditions, same ternary branches |
| [Constructor Over-injection](code-smell.md) | `code-smell.constructor-overinjection` | Too many constructor dependencies                               |
| [Unused Private](code-smell.md)             | `code-smell.unused-private`            | Unused private methods, properties, constants                   |

[Read more about Code Smell rules --&gt;](code-smell.md)

### Security Rules

These rules detect patterns that may introduce security vulnerabilities.

| Rule                                 | ID                               | What it detects                          |
| ------------------------------------ | -------------------------------- | ---------------------------------------- |
| [Hardcoded Credentials](security.md) | `security.hardcoded-credentials` | Passwords, API keys, tokens in code      |
| [SQL Injection](security.md)         | `security.sql-injection`         | Superglobals in SQL queries              |
| [XSS](security.md)                   | `security.xss`                   | Unsanitized superglobals in echo/print   |
| [Command Injection](security.md)     | `security.command-injection`     | Superglobals in shell functions          |
| [Sensitive Parameter](security.md)   | `security.sensitive-parameter`   | Missing #[\SensitiveParameter] attribute |

[Read more about Security rules --&gt;](security.md)

### Annotation Rules

This rule validates the `@qmx-ignore` / `@qmx-threshold` annotations written in your code, rather than the code itself. It reports through four channels — `annotation.unresolved-directive`, `annotation.unsupported-threshold`, and `annotation.invalid-threshold` are configuration errors that fail the run unconditionally; `annotation.unused-directive` is ordinary debt with a configurable severity, and the one channel no `@qmx-ignore` may address.

| Rule                                  | ID                     | What it detects                                                                               |
| ------------------------------------- | ---------------------- | --------------------------------------------------------------------------------------------- |
| [Directive Validation](annotation.md) | `annotation.directive` | Invalid, unsupported, malformed, or unused inline `@qmx-ignore` / `@qmx-threshold` directives |

[Read more about Annotation rules --&gt;](annotation.md)

### Discovery Rules

This rule reports on the run's own file selection rather than on the code: an `--exclude` value or an `exclude:` entry that matched no directory. The report then covers files the author meant to leave out, and without this channel a missed exclusion and no exclusion at all produce byte-identical output.

| Rule                              | ID                            | What it detects                                                              |
| --------------------------------- | ----------------------------- | ---------------------------------------------------------------------------- |
| [Unmatched exclude](discovery.md) | `discovery.unmatched-exclude` | An exclude pattern that removed no directory, or one the run could not check |

[Read more about Discovery rules --&gt;](discovery.md)

### Suppression Rules

This rule reports on the run's own suppression configuration rather than on the code: a `suppress_paths` or `suppress_namespaces` value — global or under `rules.<name>` — that names no file this run analysed and no namespace it declared. Such a value hides nothing and never will, while the author believes it is hiding something.

| Rule                                        | ID                          | What it detects                                         |
| ------------------------------------------- | --------------------------- | ------------------------------------------------------- |
| [Suppression configuration](suppression.md) | `suppression.configuration` | A suppression value that names nothing the run measured |

[Read more about Suppression rules --&gt;](suppression.md)

## Disabling Rules

You can disable individual rules or entire groups:

```bash
# Disable a single rule
bin/qmx check src/ --disable-rule=complexity.npath

# Disable an entire group (wildcard match; matches descendants only, not "code-smell" itself)
bin/qmx check src/ --disable-rule=code-smell.*
```

## Excluding Namespaces

Any rule supports explicit `exact`, `subtree`, and `regex` namespace selectors. The files are still analyzed and metrics are collected, but violations are not reported:

```yaml
rules:
  complexity.ccn:
    suppress_namespaces:
      - subtree: App\Tests
      - subtree: App\Legacy
```

```bash
bin/qmx check src/ --rule-opt="complexity.ccn:suppress_namespaces=subtree:App\Tests"
```

This is useful for test code, generated code, or legacy modules that you want to keep in metrics but exclude from violation reports for a specific rule.

## Customizing Thresholds

Override any threshold via the command line:

```bash
bin/qmx check src/ --rule-opt="complexity.ccn:callable.warning=15"
bin/qmx check src/ --rule-opt="size.method-count:warning=25"
```

Or in your `qmx.yaml` configuration file:

```yaml
rules:
  complexity.ccn:
    callable:
      warning: 15
      error: 25
  size.method-count:
    warning: 25
    error: 40
```
<!-- llms:skip-end -->

<!-- llms-only
Compact rule catalog. For warning/error thresholds, see [Default Thresholds Reference](../reference/default-thresholds.md). For configuration syntax, see [Configuration](../getting-started/configuration.md).

- **Complexity:** `complexity.ccn`, `complexity.cognitive`, `complexity.npath`, `complexity.wmc`
- **Size:** `size.method-count`, `size.class-count`, `size.property-count`
- **Design:** `design.dit`, `design.noc`, `design.type-coverage.param`, `design.type-coverage.return`, `design.type-coverage.property`, `design.data-class`, `design.god-class`
- **Cohesion:** `cohesion.lcom` (rule); `cohesion.tcc`, `cohesion.lcc` (metrics only, no rule — used as inputs by `design.god-class`)
- **Coupling:** `coupling.cbo`, `coupling.instability`, `coupling.distance`, `coupling.class-rank`, `coupling.unmatched-framework-namespace`
- **Maintainability:** `maintainability.mi`
- **Architecture:** `architecture.circular-dependency`, `architecture.layer-violation`, `architecture.unassigned-class`
- **Duplication:** `duplication.clone`
- **Code Smell:** `code-smell.boolean-argument`, `code-smell.count-in-loop`, `code-smell.debug-code`, `code-smell.empty-catch`, `code-smell.error-suppression`, `code-smell.eval`, `code-smell.exit`, `code-smell.goto`, `code-smell.superglobals`, `code-smell.long-parameter-list`, `code-smell.unreachable-code`, `code-smell.identical-subexpression`, `code-smell.constructor-overinjection`, `code-smell.unused-private`
- **Security:** `security.hardcoded-credentials`, `security.sql-injection`, `security.xss`, `security.command-injection`, `security.sensitive-parameter`
- **Annotation:** `annotation.directive` (reports through four channels — see [Annotation rules](annotation.md))
- **Discovery:** `discovery.unmatched-exclude` (see [Discovery rules](discovery.md))
- **Suppression:** `suppression.configuration` (its channels are listed on [Suppression rules](suppression.md))

Disable a single rule: `--disable-rule=complexity.npath`. Disable a whole group: `--disable-rule=code-smell.*` (wildcard; matches descendants only, not `code-smell` itself).
-->
