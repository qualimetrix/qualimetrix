# Baseline

A baseline records accepted debt so an existing project can adopt Qualimetrix without treating every current finding as new work. Version 13 is a **reported-magnitude ceiling**, not a list of hashes to ignore: an existing group stays accepted only while it does not grow or become worse.

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

Each baseline entry identifies a canonical typed subject, a channel, an optional semantic occurrence, and an optional dependency edge. The subject distinguishes exact declarations from logical classes and file/namespace/project aggregates. For a magnitude channel, the file stores the group's reported values only — its count is the length of that list, not a separate field; for an occurrence channel, it stores the count. The current group is accepted when it has no more findings at every severity level than the stored group. This handles repairs without guessing which individual finding disappeared.

The baseline does not make a non-firing rule fire. A finding that vanishes is stale, not proven fixed.

Configuration-error channels never enter a baseline on any path: the five layer-policy diagnostics (`architecture.coverage`, `architecture.unreachable-layer`, `architecture.pending-layer-matched`, `architecture.potential-shadow`, `architecture.empty-template`) and the three inline-directive diagnostics (`annotation.unresolved-directive`, `annotation.unsupported-threshold`, `annotation.invalid-threshold`) end the run unconditionally instead — see [Inline suppression](#inline-suppression) below.

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

### Replace an older baseline

```bash
bin/qmx baseline:generate baseline-v13.json src/
```

Only version 13 is loadable. Neither a version 5 hash nor a version 10 logical symbol key can infer the exact declaration subject, semantic occurrence, or dependency edge now required; a version 11 file cannot supply the shortened occurrence key or the derived `count`; and a version 12 declaration key stores a byte offset from which the declaration it meant cannot be recovered — there is no converter from any prior version. Run a fresh analysis, map or split every previously accepted group deliberately, review the result, and write a new v13 file. `baseline:generate --force` may replace bytes only after that review; it is not an automatic converter and does not infer old identity. The removed migration command has no alias or compatibility shim.

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

An entry over a closure, over a member of an anonymous class, or over one of two
declarations sharing a name in one file is keyed by a rank. Adding, removing, or
moving the declarations that rank counts renumbers it, and the vacated number is
reused — so the entry is not reported stale, its acceptance moves to whatever
holds the number now. Regenerate after such an edit.

```bash
bin/qmx check src/ --baseline=baseline.json --show-resolved
```

## Inline suppression

Use an inline suppression for an intentional exception rather than silently accepting it in a baseline. The tags work in PHPDoc, line comments, and block comments; place them on a separate line before their target.

| Tag                                           | Scope                 | Example                                                              |
| --------------------------------------------- | --------------------- | -------------------------------------------------------------------- |
| `@qmx-ignore <channel> [-- reason]`           | Symbol                | `@qmx-ignore complexity.cyclomatic.callable -- Legacy state machine` |
| `@qmx-ignore * [-- reason]`                   | All rules on a symbol | `@qmx-ignore * -- Generated mapper`                                  |
| `@qmx-ignore-next-line <channel> [-- reason]` | Next line             | `@qmx-ignore-next-line code-smell.exit -- CLI entry point`           |
| `@qmx-ignore-file [channel] [-- reason]`      | Whole file            | `@qmx-ignore-file` or `@qmx-ignore-file -- Generated code`           |

### Reason separator

The channel argument and the reason are both bare words, so `--` is how you
tell them apart. It is **mandatory** on `@qmx-ignore-file` whenever the
channel is left out and a reason follows directly: `@qmx-ignore-file
Generated code, do not analyse` reads `Generated` as the channel, which
addresses nothing, and fails with `annotation.unresolved-directive`:

```
Suppression "Generated" addresses no channel. No declared name is close to it. Prose belongs after "--".
```

Write it as `@qmx-ignore-file -- Generated code, do not analyse` instead. On
`@qmx-ignore` and `@qmx-ignore-next-line` the channel is not optional — it is
always the first word — so `--` before the reason is optional there too; the
project's own convention is to write it anyway so all three tags read the
same way.

### Channels, not rule names

`@qmx-ignore`, `@qmx-ignore-next-line`, and `@qmx-ignore-file` address a **channel** — the exact `violationCode` a finding is reported under — not the producer rule that emits it. A channel selector is either:

- an **exact** channel name (`complexity.wmc`, `code-smell.eval`), or
- `X.*` for strictly the **descendants** of `X` — `X` itself is not included, so write two directives if you mean both.

A bare prefix without the star (`@qmx-ignore complexity`) is an error, not a guess at intent, and an `X.*` that matches nothing is an error too:

```text
Suppression "complexity" addresses no channel. Addressable names closest to it: complexity.wmc.
```

For most rules the rule name and its one channel are the same string, so the distinction never surfaces. It surfaces for the rules below, which report through more than one channel — their bare rule name is **not** a valid `@qmx-ignore` argument:

| Rule                    | Channels                                                        |
| ----------------------- | --------------------------------------------------------------- |
| `complexity.cyclomatic` | `complexity.cyclomatic.callable`, `complexity.cyclomatic.class` |
| `complexity.cognitive`  | `complexity.cognitive.callable`, `complexity.cognitive.class`   |
| `complexity.npath`      | `complexity.npath.callable`, `complexity.npath.class`           |
| `coupling.cbo`          | `coupling.cbo.class`, `coupling.cbo.namespace`                  |
| `coupling.instability`  | `coupling.instability.class`, `coupling.instability.namespace`  |

Suppress one channel with its exact name, or every channel of the rule with the wildcard, e.g. `@qmx-ignore complexity.cyclomatic.*`.

A channel can also be a computed metric, e.g. `@qmx-ignore health.cohesion` — valid as long as `computed_metrics:` still defines that metric. Removing the metric turns the annotation into an error: a dangling reference is the same mistake as a typo.

!!! warning "Five channels can never be suppressed here"
    `architecture.coverage`, `architecture.unreachable-layer`, `architecture.pending-layer-matched`, `architecture.potential-shadow`, and `architecture.empty-template` are configuration errors, not debt: `@qmx-ignore` cannot suppress them, and a baseline can never accept them. Use the architecture configuration's `exclude:` block, or `coverage: ignore` for the coverage diagnostic specifically. `architecture.layer-violation` is unaffected — `@qmx-ignore architecture.layer-violation` and baseline entries still work for it.

### When a directive is wrong

A directive that names something invalid, or that no longer fires, is not silently ignored — it becomes a finding of its own under the built-in `annotation.directive` rule, reported on the file that carries the directive. See [Annotation rules](../rules/annotation.md) for the full reference. Three of its four channels are configuration errors that end the run regardless of `--fail-on` and can never be baselined or suppressed:

| Channel                            | Fires when                                                                                                                                               |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `annotation.unresolved-directive`  | the directive names a channel that does not exist (typo, a rule name where a channel was meant, an `X.*` matching nothing, or a removed computed metric) |
| `annotation.unsupported-threshold` | `@qmx-threshold` targets a rule that declares no threshold override support                                                                              |
| `annotation.invalid-threshold`     | the `@qmx-threshold` payload itself is malformed                                                                                                         |
| `annotation.unused-directive`      | the directive is valid but nothing it addressed fired this run — ordinary cleanup debt                                                                   |

Only `annotation.unused-directive` behaves like an ordinary finding: it defaults to `Info`, its severity is configurable via the `unused_directive_severity` rule option, and it can be baselined or suppressed like any other channel. `@qmx-threshold` never counts toward it.

An inline same-line comment is not supported.

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

`@qmx-threshold` addresses the **rule** by its exact name — never a channel, and never a wildcard. A threshold belongs to the rule's one options object, not to an individual channel, so `@qmx-threshold complexity.cyclomatic.callable` is an error even though `complexity.cyclomatic` has two channels; use the rule name `complexity.cyclomatic` instead:

```text
@qmx-threshold "coupling.cbo.class" names no rule. "coupling.cbo.class" is a channel of
rule "coupling.cbo" — a threshold addresses the rule.
```

This is the mirror image of `@qmx-ignore`, which always addresses the channel — the asymmetry is deliberate. `@qmx-threshold` on a disabled rule is valid and silent: enabledness is an execution filter, not a fact about whether the rule name exists.

Numbers are non-negative. The explicit form accepts only `warning` and `error`; a non-empty reason follows `--` or an em dash. Class overrides apply inside the class (including methods), method overrides apply to that method, and the smallest matching source span wins. Prefer this to `@qmx-ignore` when a useful limit remains.
