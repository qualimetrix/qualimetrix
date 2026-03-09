# CodeSmell — Code Pattern Detectors

## Overview

The CodeSmell collector detects common anti-patterns and code smells in a single AST pass.

## Detected Patterns

| Type                | Description                 | Example                                |
| ------------------- | --------------------------- | -------------------------------------- |
| `goto`              | Usage of `goto`             | `goto label;`                          |
| `eval`              | Usage of `eval()`           | `eval($code);`                         |
| `exit`              | Usage of `exit()`/`die()`   | `exit(1);`                             |
| `empty_catch`       | Empty catch blocks          | `catch (Exception $e) {}`              |
| `debug_code`        | Debug code                  | `var_dump($x);`                        |
| `error_suppression` | The `@` operator            | `@file_get_contents()`                 |
| `count_in_loop`     | `count()` in loop condition | `for ($i = 0; $i < count($arr); $i++)` |
| `superglobals`      | Direct superglobal access   | `$_GET['id']`                          |

## Metrics

For each type, two metrics are collected:

- `codeSmell.{type}.count` — number of occurrences in the file
- `codeSmell.{type}.locations` — JSON with positions `[{line, column, extra}]`

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
