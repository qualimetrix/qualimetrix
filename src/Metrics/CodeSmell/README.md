# CodeSmell — Code Pattern Detectors

## Overview

The CodeSmell collector detects common anti-patterns and code smells in a single AST pass.

## Detected Patterns

| Type                | Description                                             | Example                                |
| ------------------- | ------------------------------------------------------- | -------------------------------------- |
| `goto`              | Usage of `goto`                                         | `goto label;`                          |
| `eval`              | Usage of `eval()`                                       | `eval($code);`                         |
| `exit`              | Usage of `exit()`/`die()`                               | `exit(1);`                             |
| `empty_catch`       | Empty catch blocks                                      | `catch (Exception $e) {}`              |
| `debug_code`        | Debug code                                              | `var_dump($x);`                        |
| `error_suppression` | The `@` operator                                        | `@file_get_contents()`                 |
| `count_in_loop`     | `count()` in loop condition                             | `for ($i = 0; $i < count($arr); $i++)` |
| `superglobals`      | Direct superglobal access                               | `$_GET['id']`                          |
| `boolean_argument`  | `bool` parameter in a method/function/closure signature | `function save(bool $overwrite)`       |

## Metrics

Each type is collected as a `codeSmell.{type}` entry list (`MetricBag::entries()`), one
entry per occurrence, with `line` and an optional `extra` (type-specific auxiliary data,
e.g. the parameter name for `boolean_argument`).

`boolean_argument` entries additionally carry a `promoted` boolean: whether the flagged
parameter is a promoted constructor property (`public bool $x`, has parser visibility/
readonly flags) rather than a plain method/function argument. `BooleanArgumentRule`
excludes promoted entries by default (`flag_promoted_properties: false`) since a promoted
parameter declares a field, not a behavior switch — see
[`src/Rules/CodeSmell/BooleanArgumentRule.php`](../../Rules/CodeSmell/BooleanArgumentRule.php).

## Debug Functions

The following functions are detected:
- `var_dump`, `print_r`, `var_export`
- `dd`, `dump` (Laravel/Symfony)
- `debug_backtrace`, `debug_print_backtrace`

## Superglobals

The following are detected:
- `$_GET`, `$_POST`, `$_REQUEST`
- `$_COOKIE`, `$_SESSION`
- `$_SERVER`, `$_FILES`, `$_ENV`

## Usage

The collector is registered automatically. Rules in `src/Rules/CodeSmell/` use its metrics to generate violations.

---

## Identical Sub-Expression Collector

A separate collector that detects identical sub-expressions indicating copy-paste errors or logic bugs.

### Detected Patterns

| Type                  | Description                      | Example                            |
| --------------------- | -------------------------------- | ---------------------------------- |
| `identical_operands`  | Same operand on both sides       | `$a === $a`, `$x - $x`             |
| `duplicate_condition` | Repeated if/elseif conditions    | `if ($a) {} elseif ($a) {}`        |
| `identical_ternary`   | Same expression in both branches | `$cond ? $value : $value`          |
| `duplicate_match_arm` | Repeated match arm conditions    | `match($x) { 1 => 'a', 1 => 'b' }` |

Side-effect expressions (function calls, method calls, etc.) are excluded to avoid false positives.

### Metrics

- `identicalSubExpression.{type}.count` — number of findings per type
- `identicalSubExpression.{type}.line.{i}` — line number of each finding

### Files

- `IdenticalSubExpressionCollector.php` — collector implementation
- `IdenticalSubExpressionVisitor.php` — AST visitor
- `IdenticalSubExpressionFinding.php` — finding value object
