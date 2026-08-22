# Remediation Time Reference

Every violation carries an estimated remediation time, shown in debt summaries and reports. Each rule declares its own base estimate — the average effort for a typical violation of that kind. When a violation also carries a metric value and a threshold, the base time is scaled by how far the metric overshoots the threshold: `base * max(1, ln(overshoot))`. Minor overshoots get close to the base time; extreme ones get much more.

This page lists every rule's base estimate side by side, so a reader can ask whether SQL injection really deserves four times the estimate of a debug statement. It is generated from the same constants the rules themselves declare — see `src/Analysis/Evidence/Prioritization/Debt/RemediationTimeRegistry.php`.

## Complexity Rules

| Rule                  | ID                      | Minutes |
| --------------------- | ----------------------- | ------- |
| Cyclomatic Complexity | `complexity.cyclomatic` | 30      |
| Cognitive Complexity  | `complexity.cognitive`  | 30      |
| NPath Complexity      | `complexity.npath`      | 30      |
| WMC                   | `complexity.wmc`        | 30      |

## Coupling Rules

| Rule        | ID                     | Minutes |
| ----------- | ---------------------- | ------- |
| CBO         | `coupling.cbo`         | 45      |
| ClassRank   | `coupling.class-rank`  | 30      |
| Instability | `coupling.instability` | 30      |
| Distance    | `coupling.distance`    | 30      |

## Cohesion Rules

| Rule | ID              | Minutes |
| ---- | --------------- | ------- |
| LCOM | `cohesion.lcom` | 45      |

## Design Rules

| Rule                    | ID                     | Minutes |
| ----------------------- | ---------------------- | ------- |
| DIT (Inheritance Depth) | `design.inheritance`   | 30      |
| NOC                     | `design.noc`           | 20      |
| Type Coverage           | `design.type-coverage` | 15      |
| Data Class              | `design.data-class`    | 30      |
| God Class               | `design.god-class`     | 120     |

## Size Rules

| Rule           | ID                    | Minutes |
| -------------- | --------------------- | ------- |
| Class Count    | `size.class-count`    | 30      |
| Method Count   | `size.method-count`   | 20      |
| Property Count | `size.property-count` | 15      |

## Maintainability Rules

| Rule                  | ID                      | Minutes |
| --------------------- | ----------------------- | ------- |
| Maintainability Index | `maintainability.index` | 60      |

## Code Smell Rules

| Rule                       | ID                                     | Minutes |
| -------------------------- | -------------------------------------- | ------- |
| Constructor Over-injection | `code-smell.constructor-overinjection` | 60      |
| Boolean Argument           | `code-smell.boolean-argument`          | 10      |
| Debug Code                 | `code-smell.debug-code`                | 5       |
| Empty Catch                | `code-smell.empty-catch`               | 10      |
| eval()                     | `code-smell.eval`                      | 15      |
| exit()/die()               | `code-smell.exit`                      | 10      |
| goto                       | `code-smell.goto`                      | 15      |
| Superglobals               | `code-smell.superglobals`              | 15      |
| Error Suppression          | `code-smell.error-suppression`         | 10      |
| count() in Loop            | `code-smell.count-in-loop`             | 10      |
| Long Parameter List        | `code-smell.long-parameter-list`       | 20      |
| Unreachable Code           | `code-smell.unreachable-code`          | 10      |
| Unused Private             | `code-smell.unused-private`            | 15      |
| Identical Sub-expression   | `code-smell.identical-subexpression`   | 15      |

## Security Rules

| Rule                  | ID                               | Minutes |
| --------------------- | -------------------------------- | ------- |
| Hardcoded Credentials | `security.hardcoded-credentials` | 30      |
| SQL Injection         | `security.sql-injection`         | 60      |
| XSS                   | `security.xss`                   | 45      |
| Command Injection     | `security.command-injection`     | 60      |
| Sensitive Parameter   | `security.sensitive-parameter`   | 10      |

## Duplication Rules

| Rule             | ID                             | Minutes |
| ---------------- | ------------------------------ | ------- |
| Code Duplication | `duplication.code-duplication` | 15      |

## Architecture Rules

| Rule                  | ID                                 | Minutes |
| --------------------- | ---------------------------------- | ------- |
| Circular Dependencies | `architecture.circular-dependency` | 120     |
| Layer Violations      | `architecture.layer-violation`     | 15      |

## Annotation Rules

| Rule      | ID                     | Minutes |
| --------- | ---------------------- | ------- |
| Directive | `annotation.directive` | 15      |

## Computed Metrics

| Rule            | ID                | Minutes |
| --------------- | ----------------- | ------- |
| Computed Metric | `computed.health` | 15      |

## Why These Values Differ From Default Thresholds

This page is calibration, not detection. [Default Thresholds](default-thresholds.md) says *when* a rule fires; this page says *how long fixing one instance is expected to take*. `coupling.class-rank` scales its own thresholds by project size and is excluded from the overshoot scaling this page's model applies to every other magnitude channel — see [ClassRank](../rules/coupling.md#classrank) for why.
