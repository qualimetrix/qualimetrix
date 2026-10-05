# Baseline

A version 14 baseline records accepted groups as reported-magnitude ceilings.
It preserves a deliberate acceptance, including its discovery exclusions.

## Create and use a baseline

```bash
bin/qmx baseline:generate baseline.json src/
bin/qmx check src/ --baseline=baseline.json
```

Commit the reviewed file with your project. A comparable breach is promoted to
Error; an inapplicable or incomparable group retains its normal severity.

## What is measured

Capture and comparison use the same post-rule findings after inline suppression
and configured path/namespace suppression, before report Git projection.
`--no-suppression-annotations` restores report findings after this seam and does
not widen measurement. Audit and configuration-error channels are not captured.
Magnitude entries store complete vectors; occurrence entries store counts.
Identity includes typed subject, channel, optional occurrence and dependency edge.

Comparison requires complete analysis and compatible evidence for the whole
identity group. Baseline-owned `RunCoverage` combines current paths, recorded
paths/exclusions, `AnalysisCoverage`, metadata and subject-region evidence.
Exact files can be judged without a complete Composer roster; namespaces and
run-dependent channels require their wider region. Unknown metadata does not
mean absence. Equal path sets and exclusion definitions can establish equality
without a metadata scan; changed definitions require evidence about their delta.
A project subject always requires whole-region coverage.

A complete comparable missing group is **stale**. An absent unselected producer
is **unmeasured**; an absent incomparable entry is **outside coverage**. A present
incomparable group is **not-compared** and keeps its own severity, accepted level
and reason. Incomplete analysis never establishes acceptance, breach or staleness.
One nonfinite magnitude makes the entire group's magnitude vector unavailable;
no finite fragment is compared or captured. Occurrence groups count all members.
A comparable breach promotes every group member to Error. Suppress mode waives
quantitative comparison after identity applicability is established.

## Lifecycle commands

The four analysing commands accept configuration, presets, rule selection/options,
include-generated/include-autoload-dev, cache, workers and memory options.
They do not accept CLI suppression narrowings. Incomplete analysis returns 4
before mutation, even with --force. Existing bytes remain untouched.
Check, update, cleanup, explain and rename-channels preflight present documents
once before analysis/carry. Invalid grammar refuses early; configured channel
semantics are resolved later from the same immutable byte snapshot. Storage and
lock failures are environment exit 3.

### Generate

```bash
bin/qmx baseline:generate baseline.json src/
bin/qmx baseline:generate baseline.json src/ --mode=suppress --force
```

Ratchet is the default mode. Suppress accepts a captured applicable identity
regardless of quantitative growth. Force replacement discards old acceptance;
it is not a migration. The destination parent must exist and permit atomic
sibling publication. File modes, locking and content-hash CAS are preserved.

### Replace an older baseline

Only v14 is loadable. For v13 retain entries, scope and generated time, change
version to 14 and explicitly add the original exclusion definition:

```json
"exclusions": {"patterns": ["subtree:vendor"], "generated": "excluded"}
```

Patterns use explicit exact/subtree/regex selectors; generated is included or
excluded. Do not infer the old acceptance definition from current configuration.
Unknown envelope, entry, edge or exclusion keys refuse with their position.
Known unusable entry values remain inert. Older identity formats require
reviewed remapping; fresh generation accepts new debt rather than converting it.

### Tighten after repairs

```bash
bin/qmx baseline:update baseline.json src/
bin/qmx baseline:update baseline.json src/ --accept-new=architecture.layer-violation
bin/qmx baseline:update baseline.json src/ --record-exclusions
```

Ordinary update only tightens existing accepted groups. Its recorded-scope guard
can be bypassed with --force, which cannot establish comparability or permit
incomplete analysis. It preserves recorded
scope, exclusions, inert payload and modes. Equal acceptance is `unchanged`;
a no-op does not publish, change generated time or acquire a writer lock.
Absent or incomparable groups are retained rather than converted into zero.

`--accept-new=channel` is repeatable and additive: only new complete comparable
measured identities of named selected channels are admitted. Existing caps are
not tightened by this mode. Exact channel admission follows configuration and
precedes analysis; undeclared, wildcard, level-qualified, configuration-error
and `baseline.unused-entry` names refuse with exit 3.

`--record-exclusions` requires exactly the recorded paths even with `--force`.
It records the complete current exclusion definition and recaptures only groups
whose sole comparison obstacle is the exclusion change, preserving modes.
Other entries follow ordinary tightening. Unknown delta, changed generated
policy without sufficient proof, incomplete analysis or unavailable required
groups refuses the whole write. The options cannot combine.

Normalized accepted payload is preserved for arbitrary human JSON input.
Exact unchanged entry bytes are guaranteed only for canonical writer-produced
entries; arbitrary field order and numeric spelling may be normalized.

### Inspect and explicitly remove stale entries

```bash
bin/qmx baseline:cleanup baseline.json src/
bin/qmx baseline:cleanup baseline.json src/ --remove=<selector>
```

Default cleanup lists candidates without writing. Remove only reviewed selectors;
repeat --remove for several entries. A shrinking group that still fires is not
stale. Unmeasured/outside entries do not prove repair. Old undeclared subject
levels remain inert rather than becoming zero-count groups.

### Carry a baseline onto renamed channels

```bash
bin/qmx baseline:rename-channels baseline.json channels.tsv
bin/qmx baseline:rename-channels baseline.json channels.tsv --format=json
```

A tab-separated map has old/new channel names. The command analyses no source,
substitutes only named channel values, preserves entries/scope/exclusions/generated,
and refuses newly created identity collisions. Shared closed grammar applies;
unknown keys are not carried. Entry selectors change when channel identity changes.

### Explain a boundary

```bash
bin/qmx baseline:explain 'class:App\OrderService' src/ --baseline=baseline.json
```

Separate baseline and now lines show independent acceptance and current evidence,
including without a baseline or with an inert entry. Current states are reported,
nothing reported, not measured, outside coverage, not compared and level not
reported. Undeclared levels list admitted levels. Unknown channel shape stays
unknown; nonfinite magnitude groups show total/missing counts without inventing a
partial vector. Stored caps, suppress mode and inert reason stay visible.

### Intentionally empty excluded input

A complete run with no analysed files but deliberate authored/generated exclusions
can generate an empty baseline. Incomplete input still takes priority (exit 4).
Unknown metadata or exclusion is never proof that an accepted subject disappeared.

## Stale, inert, and resolved entries

--show-resolved counts complete comparable identities that disappeared, not
individual members repaired inside a surviving group. Stale and inert entries
produce the [baseline audit warning](../rules/baseline.md). Unselected audit and
uncompared entries retain count-only stderr diagnostics, without path dumps.
Full ceiling judgement precedes Git/hook projection; project audit warnings remain
visible beside selected file findings. Narrow Run-dependent channels can be
not-compared rather than accepted or promoted.


## Inline suppression

Use an inline suppression for an intentional exception rather than silently accepting it in a baseline. The tags work in PHPDoc, line comments, and block comments; place them on a separate line before their target.

| Tag                                           | Scope                 | Example                                                       |
| --------------------------------------------- | --------------------- | ------------------------------------------------------------- |
| `@qmx-ignore <channel> [-- reason]`           | Symbol                | `@qmx-ignore complexity.ccn:callable -- Legacy state machine` |
| `@qmx-ignore * [-- reason]`                   | All rules on a symbol | `@qmx-ignore * -- Generated mapper`                           |
| `@qmx-ignore-next-line <channel> [-- reason]` | Next line             | `@qmx-ignore-next-line code-smell.exit -- CLI entry point`    |
| `@qmx-ignore-file [channel] [-- reason]`      | Whole file            | `@qmx-ignore-file` or `@qmx-ignore-file -- Generated code`    |

### Comment-line grammar

A tag starts its **physical comment line**, after whitespace and comment
decoration (`//`, `/*`, `*`, `#`). A one-line `/** @qmx-ignore ... */`
is valid: PHP source may precede the comment, but prose inside the comment may
not precede the tag. A later exact tag in prose is refused as
`annotation.unresolved-directive`; write separate comment lines for separate
tags. Arguments must stay on the tag's own line, and a closing `*/` is never
an argument.

The tag spellings `@qmx-ignore`, `@qmx-ignore-next-line`,
`@qmx-ignore-file` and `@qmx-threshold` are exact. Near spellings at line start,
including case changes, underscores, spaces or a missing separator/`@`,
are reported as typos rather than silently ignored. Ordinary prose such as
`qmx ignores` is not a directive.

### Quote examples without addressing them

On a single line, surround an example with matching runs of one or more
backticks. They must have equal lengths; between the opening run and the tag
there may be only whitespace, comment decoration or backticks. A lone tick,
unequal lengths, or prose before the tag inside the span do not quote it.
For example, `` Write `@qmx-ignore complexity.ccn` `` documents a tag,
while `` `example @qmx-ignore complexity.ccn` `` is refused.

For multiline examples, open a fence after whitespace/decoration with at least
three backticks or tildes. The opener may carry an info suffix, but a backtick
opener's suffix cannot contain a backtick. A closer uses the same character
with at least the opening length, followed only by whitespace and an optional
comment closer. A shorter or mixed closer does not close it. A closed fence
quotes its contents; an unclosed fence reports directive-shaped lines inside
it as refusals and names the opening line.

### Declaration binding and member reach

A declaration-form suppression binds to the measured declaration it stands
on. Docblocks anywhere in a declaration header, including between attribute
groups or between `function` and its name, belong to that declaration.
Extraction reads the original source without modifying the cached AST.

A closure or arrow function binds when it is the direct value of an argument,
array element, return statement, expression statement or assignment chain
(`=` or `??=`). A named argument or array key before that value is allowed.
A call, ternary, array wrapper or other expression merely containing a closure
does not bind to it: move the comment directly before `function` or `fn`.
A statement containing no measured declaration is refused; use the physical
next-line form when that is the intended scope.

| Location of `@qmx-ignore`                           | Suppression reach                                                                |
| --------------------------------------------------- | -------------------------------------------------------------------------------- |
| Class, interface, trait or enum                     | Whole class-like declaration and its measured callable members                   |
| Method                                              | Whole method; class findings located on that method's lines                      |
| Function, closure, arrow function or property hook  | Whole callable                                                                   |
| Property with hooks                                 | Whole hooks; class findings on the property's lines                              |
| Property without hooks, class constant or enum case | Class findings on that member's lines                                            |
| Parameter                                           | Callable findings on that parameter's lines                                      |
| Promoted parameter                                  | Parameter lines in callable and class findings; whole property hooks, if present |

A member annotation cannot suppress a whole-class finding located on the class
line or a finding on a neighbouring member. Reach uses inclusive **line**
ranges, so parameters sharing a line cannot be distinguished by column.
An explicit `:level` must be declared by the channel and reachable at this
location: `:class` on a method or promoted parameter is lawful;
`:callable` on a constant or property without hooks is refused.
A bare channel selector covers the reachable levels without inventing another
level check.

`@qmx-threshold` does **not** gain member containment: class-like declarations
retune themselves and their measured callables; methods/functions/closures/
arrows/hooks retune themselves; a hooked property retunes its hooks.
A plain property, constant, enum case or parameter has no threshold binding.

### Physical sites and blanket controls

Every read tag is one authored site even if it creates several declaration
bindings. Suppressions and threshold diagnostics retain the tag's byte
position: identical tags in two comments on one line remain separate sites.
A next-line site's reported line is the tag line; its target is the line
after the end of the comment, including for multiline comments.
Threshold overrides do not carry a position, so overrides of the same rule on
the same line still coalesce.

`@qmx-ignore *` and a bare `@qmx-ignore-file` are judged effective when they
silence a produced finding and inert when they do not. They cannot silence
`annotation.unused-directive` or `duplication.clone`, and configuration-error
findings remain exempt from annotation suppression. An explicit selector
addressing either banned channel is refused; declaration/level reach is checked
before the ban.

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

Only `annotation.unused-directive` behaves like an ordinary finding: it defaults to `Warning`, its severity is configurable via the `unused-directive-severity` rule option (set `info` explicitly for the former severity), and it can be baselined, dropped by the top-level `suppress_paths` or narrowed by a git scope like any other channel. `suppress_namespaces` does not reach it — the finding's subject is the file the annotation sits in, which carries no namespace — and neither do the rule's own exclusions, which run before this channel is assembled. Like `duplication.clone`, no `@qmx-ignore` can silence it — a directive addressing it is refused as an `annotation.unresolved-directive` — so a baseline entry is the way to accept it in place. `@qmx-threshold` never counts toward it.

A tag with a typo, wrong line placement or no valid declaration binding is refused; a property suppression now has bounded member reach. See [Forms that never become a directive](../rules/annotation.md#forms-that-never-become-a-directive).

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

Duplication is an explicit exception: `@qmx-threshold duplication.clone` is refused with `annotation.unsupported-threshold` because that rule does not support local overrides.

`@qmx-threshold` addresses the **rule** by its exact name — never a channel, and never a level. A threshold belongs to the rule's one options object, not to an individual level, so `@qmx-threshold complexity.ccn:callable` is an error even though `complexity.ccn` reports at two levels; use the rule name `complexity.ccn` instead, or narrow with `--rule-opt` if only one level's options need to change:

```text
@qmx-threshold "complexity.ccn:callable" addresses a rule at a level, and a threshold addresses the
producing rule by its own name: it does not distinguish levels (ADR 0024). Retune the whole rule
"complexity.ccn", or set the level alone with --rule-opt complexity.ccn:callable.<option>=<value>.
```

This is the mirror image of `@qmx-ignore`, which always addresses the channel — the asymmetry is deliberate. `@qmx-threshold` on a disabled rule is valid and silent: enabledness is an execution filter, not a fact about whether the rule name exists.

Numbers are non-negative. The explicit form accepts only `warning` and `error`; a non-empty reason follows `--` or an em dash. Class overrides apply inside the class (including methods), method overrides apply to that method, and the smallest matching source span wins. Prefer this to `@qmx-ignore` when a useful limit remains.
