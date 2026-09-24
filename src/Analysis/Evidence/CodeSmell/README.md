# CodeSmell — Code Pattern Detectors

## Overview

The CodeSmell collector detects common anti-patterns and code smells in a single AST pass.

## Detected Patterns

| Type                | Description                                                                               | Example                                |
| ------------------- | ----------------------------------------------------------------------------------------- | -------------------------------------- |
| `goto`              | Usage of `goto`                                                                           | `goto label;`                          |
| `eval`              | Usage of `eval()`                                                                         | `eval($code);`                         |
| `exit`              | Usage of `exit()`/`die()`, including the PHP 8.4 call form `\exit()`                      | `exit(1);`                             |
| `empty_catch`       | Empty catch blocks, a comment-only body included; a foreach chain of attempts is exempt   | `catch (Exception $e) {}`              |
| `debug_code`        | Debug code                                                                                | `var_dump($x);`                        |
| `error_suppression` | The `@` operator                                                                          | `@file_get_contents()`                 |
| `count_in_loop`     | `count()` in loop condition                                                               | `for ($i = 0; $i < count($arr); $i++)` |
| `superglobals`      | Direct superglobal access                                                                 | `$_GET['id']`                          |
| `boolean_argument`  | `bool` parameter in a method/function/closure signature, not a property-hook setter value | `function save(bool $overwrite)`       |

## Metrics

Each type is collected as a `codeSmell.{type}` entry list (`MetricBag::entries()`), one
entry per occurrence, with `line` and an optional `extra` (type-specific auxiliary data,
e.g. the parameter name for `boolean_argument`).

`boolean_argument` entries additionally carry a `promoted` boolean: whether the flagged
parameter is a promoted constructor property (`public bool $x`, has parser visibility/
readonly flags) rather than a plain method/function argument. `BooleanArgumentRule`
excludes promoted entries by default (`flag_promoted_properties: false`) since a promoted
parameter declares a field, not a behavior switch — see
[`BooleanArgumentRule.php`](BooleanArgumentRule.php).

## Debug Functions

The following functions are detected:
- `var_dump`, `print_r`, `var_export`
- `dd`, `dump` (Laravel/Symfony)
- `debug_print_backtrace`, `debug_zval_dump`

`debug_backtrace` is not detected: it returns data and belongs to ordinary error handling.
A positional `true` second argument is return mode only for `print_r` and `var_export`;
the named `return: true` argument is honoured for every function. Names are matched as
written — `use function` aliases are not resolved.

## Superglobals

The following are detected by their plain variable name (a variable-variable spelling such as `${'_GET'}` is not):
- `$_GET`, `$_POST`, `$_REQUEST`
- `$_COOKIE`, `$_SESSION`
- `$_SERVER`, `$_FILES`, `$_ENV`
- `$GLOBALS`

## Empty Catch: chain of attempts

An empty catch is exempt only when its `try` is a direct statement of a `foreach` body and can
end the search on success: it holds a `return`, or a `continue` that skips statements after the
try, at its top level or inside its `if` branches
(`ControlFlow/ChainOfAttempts.php`). A try nested deeper, one inside a closure, and one whose
`continue` skips nothing are reported.

## Usage

The collector and its co-located rules are registered automatically. Rules use the collected metrics to generate findings.

---

## Identical Sub-Expression Collector

A separate collector that detects identical sub-expressions indicating copy-paste errors or logic bugs.

### Detected Patterns

| Type                    | Description                                   | Example                                |
| ----------------------- | --------------------------------------------- | -------------------------------------- |
| `identical_operands`    | Same operand on both sides                    | `$a === $a`, `$x - $x`                 |
| `duplicate_condition`   | Repeated if/elseif/`else if` chain conditions | `if ($a) {} elseif ($a) {}`            |
| `identical_ternary`     | Same expression in both branches              | `$cond ? $value : $value`              |
| `duplicate_match_arm`   | Repeated match arm conditions                 | `match($x) { 1 => 'a', 1 => 'b' }`     |
| `duplicate_switch_case` | Repeated switch case values                   | `switch ($x) { case 1: ...; case 1: }` |

Side-effect expressions (function calls, method calls, etc.) are excluded from operands and
ternary branches. Conditions (`duplicate_condition`, `duplicate_match_arm`,
`duplicate_switch_case`) are compared with their calls, because a repeated call in a condition
chain is the typical copy-paste bug. An `else` holding nothing but an `if` continues the chain;
the visitor evaluates the chain once, at its head.

### Metrics

- `identicalSubExpression.{type}.count` — number of findings per type
- `identicalSubExpression.{type}.line.{i}` — line number of each finding

### Files

- `RepeatedExpression/IdenticalSubExpressionCollector.php` — repeated-expression collector implementation
- `RepeatedExpression/IdenticalSubExpressionVisitor.php` — repeated-expression AST traversal and delegation
- `RepeatedExpression/IdenticalSubExpressionFinding.php` — repeated-expression finding value object
- `RepeatedExpression/RepeatedExpressions.php` — binary/ternary structural equality and side-effect policy
- `RepeatedExpression/RepeatedConditions.php` — if, match, and switch repeated-condition policy; its companion dependencies are `RepeatedExpressions` and `IfChain`
- `RepeatedExpression/IfChain.php` — if-chain shape: an `else` holding only an `if` continues the chain
- `ControlFlow/ControlFlowSmells.php` — empty catches, goto, exit/die, and count/sizeof loop conditions
- `ControlFlow/ChainOfAttempts.php` — the foreach chain-of-attempts shape that exempts an empty catch
- `Debug/DebugCodeSmells.php` — debug-call recognition
- `BooleanArgument/BooleanArgumentSmells.php` — boolean-argument and promoted-property policy

`CodeSmellVisitor` owns AST traversal/delegation and only three residual one-node projections: `eval`, error suppression (including its direct function-name payload), and direct superglobal access. `ControlFlowSmells` owns only empty catches (including the foreach chain-of-attempts exception), `goto`, `exit`/`die`, and `count`/`sizeof` calls in `for`, `while`, and `do` conditions. Debug and boolean-argument policy stay in their named child subjects.

The complete repeated-expression stack is collector → visitor → `RepeatedExpressions` / `RepeatedConditions` / `IfChain` → finding VO. `RepeatedConditions` calls `RepeatedExpressions` only for structural equality and `IfChain` to flatten an `else if` chain; the visitor asks `IfChain` for the continuations of a chain head so that a continued `if` is not evaluated twice. `CodeSmellVisitor` asks `ChainOfAttempts` for the exempt tries of each `foreach` and passes the verdict to `ControlFlowSmells`. Direct companion tests own semantic matrices (`ControlFlowSmellsTest`, `ChainOfAttemptsTest`, `DebugCodeSmellsTest`, `BooleanArgumentSmellsTest`, `RepeatedExpressionsTest`, `RepeatedConditionsTest`, `IfChainTest`); visitor tests own traversal/delegation and residual projection. `CredentialLiteralsTest` owns the seven credential-literal shapes and exclusions; the credential visitor test owns delegation.

The only internal dogfood control is `CredentialLiterals` `@qmx-ignore health.cohesion -- Stateless credential-literal shapes share one classification policy and location boundary.` It is a structural explanation, not a metric behavior change or baseline debt. `HardcodedCredentialsVisitor` carried a matching `design.data-class` control until that rule was corrected to gate on a low share of functional public methods; a delegating traversal adapter is no longer read as a data surface.


## Rule option key declarations

`BooleanArgumentOptions`, `CodeSmellOptions`, `ConstructorOverinjectionOptions`,
`ErrorSuppressionOptions`, `IdenticalSubExpressionOptions`,
`LongParameterListOptions`, `UnreachableCodeOptions` and `UnusedPrivateOptions`
declare their accepted option keys through
`RuleOptionsInterface::acceptedOptionKeys()`. Each declaration transcribes the
class's own constructor parameters plus its shorthand keys —
`threshold`/`vo-threshold` for `LongParameterListOptions`, `threshold` for
`ConstructorOverinjectionOptions` and `UnreachableCodeOptions`. Those
declarations are what `RuleOptionKeyRecognition` compares an incoming key against: a
key none of them knows is refused with exit 3, at the rule's own level and
inside a level slot alike.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
