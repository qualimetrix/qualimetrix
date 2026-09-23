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

Baseline lifecycle commands measure the findings after source/configuration `@qmx-ignore` suppression and configured path or namespace exclusions. `check` uses that same set; its `--suppress-path` and `--suppress-namespace` options can safely narrow it further, but can leave an entry inert. The lifecycle commands do not accept those CLI-only exclusions, so capture and maintenance cannot silently use a different option surface. `--no-suppression-annotations` is report-only: it restores annotated findings after baseline measurement and never widens the set. `--report=git:...` likewise narrows presentation only.

Each baseline entry identifies a canonical typed subject, a channel, an optional semantic occurrence, and an optional dependency edge. The subject distinguishes exact declarations from logical classes and file/namespace/project aggregates. For a magnitude channel, the file stores the group's reported values only — its count is the length of that list, not a separate field; for an occurrence channel, it stores the count. The current group is accepted when it has no more findings at every severity level than the stored group. This handles repairs without guessing which individual finding disappeared.

The baseline does not make a non-firing rule fire. A finding that vanishes is stale, not proven fixed.

Configuration-error channels never enter a baseline on any path: the five layer-policy diagnostics (`architecture.coverage-gap`, `architecture.unreachable-layer`, `architecture.pending-layer-matched`, `architecture.potential-shadow`, `architecture.empty-template`) and the three inline-directive diagnostics (`annotation.unresolved-directive`, `annotation.unsupported-threshold`, `annotation.invalid-threshold`) end the run unconditionally instead — see [Inline suppression](#inline-suppression) below.

## Lifecycle commands

All analysis-bearing baseline commands accept the same configuration options needed to reproduce the measured set:

```text
--preset=PRESET
--rule-opt=RULE-OPT
--only-rule=ONLY-RULE
--disable-rule=DISABLE-RULE
```

They also accept `--config=CONFIG`. They do **not** accept `--suppress-path` or `--suppress-namespace`, because those safe `check` narrowings would otherwise make lifecycle operations asymmetric. They also do not accept `--no-suppression-annotations`, which is report-only and cannot widen the measured set.

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

### Carry a baseline onto renamed channels

```bash
bin/qmx baseline:rename-channels baseline.json channels.tsv
bin/qmx baseline:rename-channels baseline.json channels.tsv --format=json
```

`baseline:rename-channels <baseline> <map>` rewrites the `channel` field of the
entries a declared map names, and nothing else. It **runs no analysis**: subject
keys, `occurrence`, `count`, `magnitudes`, `mode`, `edge`, `scope` and
`generated` are carried through untouched, and no project code is read. Use it
when an upgrade renames a channel you have accepted debt on, instead of
regenerating — a regeneration silently accepts whatever the tree has
accumulated since.

The map is tab-separated with the header `old`, `new`, `reason`, one row per
rename; blank lines and `#` comments are skipped:

```text
old	new	reason
complexity.cyclomatic	complexity.ccn	renamed in vX.Y
```

Of the file itself it refuses exactly what loading it would refuse, and nothing
more; the map has refusals of its own. So it is refused, with the file left
byte-identical, when: the file is not version
13; its envelope is not a readable baseline document, including a `generated`
that is not an ISO 8601 datetime or a `scope` that is not a list of paths; two
rows rename one name; two rows produce one name; a row's two sides are equal;
one row's target is renamed again by another; or *this carry* would give two
entries in one subject a single identity — a duplicate the file already held is
carried, not refused, even when it stands on a renamed channel. A declared
rename that matches nothing in this file is reported, not refused. Exit codes:
`0` carried (including "nothing matched"), `3` refused — on content, on the
baseline or the map not being a readable file, or on a malformed `--format`
value; every refusal takes the same code regardless of which of those caused
it. A refusal is reported in the chosen format: under `--format=json` it is
the `{error, exit_code}` envelope every other machine-readable refusal in the
tool uses, not a bespoke `error`-only object.

Two consequences are worth knowing before you run it:

- **A new name is not checked against the channels this build declares.** The
  carry is released before the renames it exists to perform, so until the
  release that declares the new name lands, `check` reports a carried entry as
  one it cannot apply. That intermediate state is by design.
- **Entry selectors change.** A selector is a digest of the identity, which the
  channel name is part of, so a saved `baseline:cleanup --remove=<selector>`
  stops addressing a carried entry. Re-read the selectors from a fresh
  `baseline:cleanup` listing.

Entries this build cannot read are carried rather than dropped, and counted in
the report. The count is deliberately narrower than what `check` calls inert:
the carry runs no analysis, so it only counts what the document itself
shows. Of the five kinds it counts, two still have a readable `channel` and
are renamed like any other entry — one whose `occurrence` or `edge` is
malformed, and one that already shared its identity with another. The other
three have no channel for the map to act on and are carried unchanged: an
entry that is not an object, one without a readable `channel`, and a subject
that stores its entries as something other than a JSON array. Being counted
never means dropped either way — an unreadable entry is never removed from
the file — but only the first two are renamed onto the new spelling.

Renaming a channel can move an entry among its siblings. The carried file
places every line in the same canonical order the product itself writes, so a
later command that rewrites the file does not move a line again. Each line
keeps the bytes the file spelled it in, which is what lets a file written by
another build come through unreshaped; a hand-edited line whose fields are in
an unusual order is therefore re-rendered in place — not moved — the next time
a command rewrites the file.

### Explain a boundary

```bash
bin/qmx baseline:explain 'callable:App\OrderService::calculate' src/ --baseline=baseline.json
bin/qmx baseline:explain 'callable:App\OrderService::calculate' src/ --channel=complexity.ccn
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

| Tag                                           | Scope                 | Example                                                       |
| --------------------------------------------- | --------------------- | ------------------------------------------------------------- |
| `@qmx-ignore <channel> [-- reason]`           | Symbol                | `@qmx-ignore complexity.ccn:callable -- Legacy state machine` |
| `@qmx-ignore * [-- reason]`                   | All rules on a symbol | `@qmx-ignore * -- Generated mapper`                           |
| `@qmx-ignore-next-line <channel> [-- reason]` | Next line             | `@qmx-ignore-next-line code-smell.exit -- CLI entry point`    |
| `@qmx-ignore-file [channel] [-- reason]`      | Whole file            | `@qmx-ignore-file` or `@qmx-ignore-file -- Generated code`    |

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

- an **exact** channel name (`complexity.wmc`, `code-smell.eval`), optionally narrowed with `:<level>` (`complexity.ccn:callable`) when the channel reports at more than one level, or
- `X.*` for strictly the **descendants** of `X` — `X` itself is not included, so write two directives if you mean both.

A bare prefix without the star (`@qmx-ignore complexity`) is an error, not a guess at intent, and an `X.*` that matches nothing is an error too:

```text
Suppression "complexity" addresses no channel. Addressable names closest to it: complexity.wmc.
```

Every rule now reports through exactly one channel, but the channel itself can report at more than one level of the symbol tree — a class-level and a namespace-level view of coupling, or a method-level and a class-level view of complexity. The bare channel name addresses **every** level at once; the rules below are the ones where that matters, because their two levels disagree often enough that suppressing only one is the common case:

| Channel                | Levels               |
| ---------------------- | -------------------- |
| `complexity.ccn`       | `callable`, `class`  |
| `complexity.cognitive` | `callable`, `class`  |
| `complexity.npath`     | `callable`, `class`  |
| `coupling.cbo`         | `class`, `namespace` |
| `coupling.instability` | `class`, `namespace` |

Suppress every level with the bare channel name, or one level with `:level`, e.g. `@qmx-ignore complexity.ccn:callable`.

A channel can also be a computed metric, e.g. `@qmx-ignore health.cohesion` — valid as long as `computed_metrics:` still defines that metric. Removing the metric turns the annotation into an error: a dangling reference is the same mistake as a typo.

!!! warning "Five channels can never be suppressed here"
    `architecture.coverage-gap`, `architecture.unreachable-layer`, `architecture.pending-layer-matched`, `architecture.potential-shadow`, and `architecture.empty-template` are configuration errors, not debt: `@qmx-ignore` cannot suppress them, and a baseline can never accept them. Use the architecture configuration's `exclude:` block, or `coverage-gap: ignore` for the coverage diagnostic specifically. `architecture.layer-violation` is unaffected — `@qmx-ignore architecture.layer-violation` and baseline entries still work for it.

### When a directive is wrong

A directive that names something invalid, or that no longer fires, is not silently ignored — it becomes a finding of its own under the built-in `annotation.directive` rule, reported on the file that carries the directive. See [Annotation rules](../rules/annotation.md) for the full reference. Three of its four channels are configuration errors that end the run regardless of `--fail-on` and can never be baselined or suppressed:

| Channel                            | Fires when                                                                                                                                                                                                                                                                                             |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `annotation.unresolved-directive`  | the directive names a channel that does not exist (typo, a rule name where a channel was meant, an `X.*` matching nothing, or a removed computed metric), **or** it never became a directive at all — a `@qmx-` tag this tool does not read, or the declaration form written where nothing is measured |
| `annotation.unsupported-threshold` | `@qmx-threshold` targets a rule that declares no threshold override support                                                                                                                                                                                                                            |
| `annotation.invalid-threshold`     | the `@qmx-threshold` payload itself is malformed                                                                                                                                                                                                                                                       |
| `annotation.unused-directive`      | the directive is valid but nothing it addressed fired this run — ordinary cleanup debt                                                                                                                                                                                                                 |

Only `annotation.unused-directive` behaves like an ordinary finding: it defaults to `Info`, its severity is configurable via the `unused_directive_severity` rule option, and it can be baselined, dropped by the top-level `suppress_paths` or narrowed by a git scope like any other channel. `suppress_namespaces` does not reach it — the finding's subject is the file the annotation sits in, which carries no namespace — and neither do the rule's own exclusions, which run before this channel is assembled. It is the one channel no `@qmx-ignore` can silence — a directive addressing it is refused as an `annotation.unresolved-directive` — so a baseline entry is the way to accept it in place. `@qmx-threshold` never counts toward it.

An inline same-line comment is not supported.

A tag that is misspelled, and a `@qmx-ignore` written above a statement or on a property, used to do nothing quietly; both are now `annotation.unresolved-directive` errors. See [Forms that never become a directive](../rules/annotation.md#forms-that-never-become-a-directive).

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
 * @qmx-threshold complexity.ccn warning=20 error=40 -- Legacy state machine
 */
final class ComplexStateMachine
{
}
```

```text
@qmx-threshold <rule> <number> [-- <reason>]
@qmx-threshold <rule> warning=<number> [error=<number>] [-- <reason>]
```

`@qmx-threshold` addresses the **rule** by its exact name — never a channel, and never a level. A threshold belongs to the rule's one options object, not to an individual level, so `@qmx-threshold complexity.ccn:callable` is an error even though `complexity.ccn` reports at two levels; use the rule name `complexity.ccn` instead, or narrow with `--rule-opt` if only one level's options need to change:

```text
@qmx-threshold "complexity.ccn:callable" addresses a rule at a level, and a threshold addresses the
producing rule by its own name: it does not distinguish levels (ADR 0024). Retune the whole rule
"complexity.ccn", or set the level alone with --rule-opt complexity.ccn:callable.<option>=<value>.
```

This is the mirror image of `@qmx-ignore`, which always addresses the channel — the asymmetry is deliberate. `@qmx-threshold` on a disabled rule is valid and silent: enabledness is an execution filter, not a fact about whether the rule name exists.

Numbers are non-negative. The explicit form accepts only `warning` and `error`; a non-empty reason follows `--` or an em dash. Class overrides apply inside the class (including methods), method overrides apply to that method, and the smallest matching source span wins. Prefer this to `@qmx-ignore` when a useful limit remains.
