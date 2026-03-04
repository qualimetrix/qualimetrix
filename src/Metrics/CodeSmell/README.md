# CodeSmell — Code Pattern Detectors

## Overview

The CodeSmell collector detects common anti-patterns and code smells in a single AST pass.

## Detected Patterns

| Type | Description | Example |
|------|-------------|---------|
| `goto` | Usage of `goto` | `goto label;` |
| `eval` | Usage of `eval()` | `eval($code);` |
| `exit` | Usage of `exit()`/`die()` | `exit(1);` |
| `empty_catch` | Empty catch blocks | `catch (Exception $e) {}` |
| `debug_code` | Debug code | `var_dump($x);` |
| `error_suppression` | The `@` operator | `@file_get_contents()` |
| `count_in_loop` | `count()` in loop condition | `for ($i = 0; $i < count($arr); $i++)` |
| `superglobals` | Direct superglobal access | `$_GET['id']` |

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
