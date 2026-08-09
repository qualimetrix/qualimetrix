# Baseline

A baseline records accepted debt so an existing project can adopt Qualimetrix without treating every current finding as new work. Version 10 is a **reported-magnitude ceiling**, not a list of hashes to ignore: an existing group stays accepted only while it does not grow or become worse.

## Create and use a baseline

Capture the current measured findings into a new file:

```bash
bin/qmx baseline:generate baseline.json src/
```

Then check against it:

```bash
bin/qmx check src/ --baseline=baseline.json
```

Commit the file with the project so local development and CI use the same accepted boundary.

!!! warning "A baseline can make a warning fail"

    A finding that currently fires but exceeds its accepted boundary is promoted to Error. With the default `--fail-on=error`, it fails the run even if the rule's configured severity was Warning. A malformed or inapplicable entry never promotes a finding: it is reported as inert and the finding keeps its normal severity.

## What is measured

Baseline lifecycle commands measure the findings after source/configuration `@qmx-ignore` suppression and configured path or namespace exclusions. `check` uses that same set; its `--exclude-path` and `--exclude-namespace` options can safely narrow it further, but can leave an entry inert. The lifecycle commands do not accept those CLI-only exclusions, so capture and maintenance cannot silently use a different option surface. `--no-suppression-annotations` is report-only: it restores annotated findings after baseline measurement and never widens the set. `--report=git:...` likewise narrows presentation only.

Each baseline entry identifies a symbol, a channel, and a dependency edge when the finding has one. For a magnitude channel, the file stores the group count and its reported values; for an occurrence channel, it stores the count only. The current group is accepted when it has no more findings at every severity level than the stored group. This handles repairs without guessing which individual finding disappeared.

The baseline does not make a non-firing rule fire. A finding that vanishes is stale, not proven fixed.

## Lifecycle commands

All analysis-bearing baseline commands accept the same configuration options needed to reproduce the measured set:

```text
--preset=PRESET
--rule-opt=RULE-OPT
--only-rule=ONLY-RULE
--disable-rule=DISABLE-RULE
```

They also accept `--config=CONFIG`. They do **not** accept `--exclude-path` or `--exclude-namespace`, because those safe `check` narrowings would otherwise make lifecycle operations asymmetric. They also do not accept `--no-suppression-annotations`, which is report-only and cannot widen the measured set.

### Generate

```bash
bin/qmx baseline:generate baseline.json src/
bin/qmx baseline:generate baseline.json src/ --mode=suppress --force
```

`baseline:generate <baseline> [<paths>...]` captures every currently measured finding. Its default `--mode=ratchet` records a ceiling; `--mode=suppress` accepts each captured identity regardless of later count or magnitude. `--force` overwrites an existing baseline file and discards its recorded acceptances.

### Migrate version 5

```bash
bin/qmx baseline:migrate baseline.json src/
```

`baseline:migrate <baseline> [<paths>...]` is the required migration for a version 5 file. It makes a fresh v10 capture because v5 stored no magnitude boundary, and reports v5 entries that no longer fire. Its `--force` allows a fresh capture even when the destination is not a v5 file; use it only when replacing that destination is intentional.

### Tighten after repairs

```bash
bin/qmx baseline:update baseline.json src/
```

`baseline:update <baseline> [<paths>...]` only moves an entry toward a stricter boundary. It never adds identities and leaves an absent identity unchanged. It refuses a run whose analysed scope does not cover the scope recorded in the file; `--force` overrides that scope guard.

### Inspect and explicitly remove stale entries

```bash
bin/qmx baseline:cleanup baseline.json src/
bin/qmx baseline:cleanup baseline.json src/ --remove=<selector>
```

Without `--remove`, `baseline:cleanup <baseline> [<paths>...]` only lists candidates and never writes the file. Repeat `--remove=<selector>` for exactly the entries you have reviewed. There is no bulk removal: absence can be caused by a configuration change, not only a repair. `--force` has the same scope-guard meaning as `baseline:update`.

### Explain a boundary

```bash
bin/qmx baseline:explain 'callable:App\OrderService::calculate' src/ --baseline=baseline.json
bin/qmx baseline:explain 'callable:App\OrderService::calculate' src/ --channel='complexity.cyclomatic#complexity.cyclomatic.callable'
```

`baseline:explain <symbol> [<paths>...]` shows the accepted level, what fires now, the configured threshold, and any `@qmx-threshold` override. Use `--baseline=BASELINE` to include accepted levels and `--channel=CHANNEL` to restrict the answer.

A symbol absent from both the current analysis and the baseline is invalid input,
not a clean result. A baseline-only symbol remains explainable and is labelled as
absent from the current scope or result.

All lifecycle commands require complete analysis. A parse or processing failure
returns exit 4 before any baseline is interpreted, classified, created, or
mutated. `--force` does not override this invariant; existing destinations remain
byte-identical.

## Stale, inert, and resolved entries

With `--baseline`, `check` reports stale entries, inert entries, and a scope mismatch without failing the run or disabling other entries. Use `--show-resolved` to count entries whose complete identity no longer appears in the measured set. A group that shrinks but still fires is not resolved.

```bash
bin/qmx check src/ --baseline=baseline.json --show-resolved
```

## Inline suppression

Use an inline suppression for an intentional exception rather than silently accepting it in a baseline. The tags work in PHPDoc, line comments, and block comments; place them on a separate line before their target.

| Tag                                     | Scope                 | Example                                                  |
| --------------------------------------- | --------------------- | -------------------------------------------------------- |
| `@qmx-ignore <rule> [reason]`           | Symbol                | `@qmx-ignore complexity.cyclomatic Legacy state machine` |
| `@qmx-ignore * [reason]`                | All rules on a symbol | `@qmx-ignore * Generated mapper`                         |
| `@qmx-ignore-next-line <rule> [reason]` | Next line             | `@qmx-ignore-next-line code-smell.exit CLI entry point`  |
| `@qmx-ignore-file`                      | Whole file            | `@qmx-ignore-file`                                       |

Rule names support prefix matching: `@qmx-ignore complexity` suppresses every `complexity.*` rule. An inline same-line comment is not supported.

### View what annotations hide

```bash
bin/qmx check src/ --show-suppressed
bin/qmx check src/ --no-suppression-annotations
```

`--show-suppressed` lists suppressed findings. `--no-suppression-annotations` restores findings hidden by `@qmx-ignore` only for the report: they remain outside the baseline's measured set, keep their own severity, and cannot be promoted by a baseline entry.

## Per-symbol threshold overrides with @qmx-threshold

Use `@qmx-threshold` when a symbol needs a different limit but should still be checked:

```php
/**
 * @qmx-threshold complexity.cyclomatic warning=20 error=40 -- Legacy state machine
 */
final class ComplexStateMachine
{
}
```

```text
@qmx-threshold <rule> <number> [-- <reason>]
@qmx-threshold <rule> warning=<number> [error=<number>] [-- <reason>]
```

Numbers are non-negative. The explicit form accepts only `warning` and `error`; a non-empty reason follows `--` or an em dash. Class overrides apply inside the class (including methods), method overrides apply to that method, and the smallest matching source span wins. Prefer this to `@qmx-ignore` when a useful limit remains.
