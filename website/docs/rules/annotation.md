# Annotation Rules

Inline `@qmx-ignore` and `@qmx-threshold` annotations used to fail in two silent ways: an annotation that addressed nothing (a typo, a removed metric, a rule name written where a channel was meant) was simply a no-op, and an annotation that once mattered but stopped matching anything this run produced no signal either. Annotation rules close both gaps — every authored directive is now checked, and a directive that cannot do what it claims is reported instead of ignored.

---

## Directive Validation

**Rule ID:** `annotation.directive`

<!-- llms:skip-begin -->
### What it measures

Every `@qmx-ignore`, `@qmx-ignore-next-line`, `@qmx-ignore-file`, and `@qmx-threshold` annotation written in the analysed code is checked against the run's own configuration: does the name it addresses exist, is it the right *kind* of name for that directive, and did it do anything this run?

Nothing is ever reported under the producer name `annotation.directive` itself — it exists only so the four channels below have one owner to disable and configure as a family. Each channel has its own rule name and its own meaning.

<!-- llms:skip-end -->

### Why it matters

An annotation is a claim about the code: "this finding is expected and accepted." When the claim is wrong — the name is misspelled, the rule was renamed, the metric it refers to was removed from configuration — the previous behavior was to say nothing. That is worse than a normal false negative: a reviewer who sees `@qmx-ignore` assumes the suppression is doing its job, when in fact nothing is being suppressed at all. Loud failure on a broken directive, and a routine cleanup nudge on a directive that quietly stopped mattering, keep the annotation surface trustworthy.

### The four channels

| Channel                            | Meaning                                                                                                                                                                                                                                                                                                                                                                                                                                  | Kind                | Severity                                                        |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- | --------------------------------------------------------------- |
| `annotation.unresolved-directive`  | The directive names something it is not allowed to address — a typo, a rule name written where a channel was expected, an `X.*` wildcard that matches nothing, or a dangling reference to a computed metric that was removed from configuration. It also carries the two ways a directive fails before its channel is ever consulted: a `@qmx-` tag this tool does not read, and the declaration form written where nothing is measured. | Configuration error | `Error`, not configurable                                       |
| `annotation.unsupported-threshold` | `@qmx-threshold` targets a rule that declares no threshold-override support.                                                                                                                                                                                                                                                                                                                                                             | Configuration error | `Error`, not configurable                                       |
| `annotation.invalid-threshold`     | The `@qmx-threshold` payload itself is malformed — the wrong shape or an unparsable value for that rule's options.                                                                                                                                                                                                                                                                                                                       | Configuration error | `Error`, not configurable                                       |
| `annotation.unused-directive`      | The directive is valid and addresses something real, but nothing it addressed fired this run.                                                                                                                                                                                                                                                                                                                                            | Ordinary debt       | `Info` by default, configurable via `unused_directive_severity` |

The first three are **configuration errors**: they report a mistake in what was written, not debt in the analysed code. Like the architecture configuration diagnostics (see [Architecture Rules](architecture.md#coverage-modes)), they fail the run unconditionally whenever they fire — `fail_on` is not consulted, not even `fail_on: none` — and none of them can be accepted into a baseline or silenced with another `@qmx-ignore`. A severity option on any of them would look like a behaviour switch while changing nothing, so none of the three exposes one.

`annotation.unused-directive` is different: the directive was well-formed and once mattered, it just did not suppress or override anything this particular run. That is ordinary cleanup debt, not a mistake — it has a configurable severity, and it can be accepted into a baseline, dropped by the top-level `suppress_paths`, and narrowed by a git scope like any other finding. Two exclusions other channels answer to do not reach it, and did not before the ban either: the top-level `suppress_namespaces` never matches it, because the finding's subject is the **file** the annotation is written in and carries no namespace to match; and the rule's own `suppress_paths` / `suppress_namespaces` never see it, because a run assembles this channel after rule execution, once the per-rule exclusion ledger has closed. Switching the whole `annotation.directive` rule off does remove it — together with the three configuration-error diagnostics above.

The one thing it cannot be is **suppressed by a directive**. `@qmx-ignore`, `@qmx-ignore-next-line` and `@qmx-ignore-file` are all refused when their target reaches `annotation.unused-directive` — by its exact name, through `annotation.*`, or with `:file` after either — and the refusal is reported as `annotation.unresolved-directive` on the line the directive was written on. A directive that hid this channel would hide the answer to the question the channel exists to ask. A bare `@qmx-ignore-file` with no channel at all is not refused, since it names nothing to refuse, but it no longer silences the channel either.

### Forms that never become a directive

A directive can be wrong in ways that have nothing to do with the channel it names: the tag can be one this tool does not read — misspelled, or written without the channel it requires — and it can be written where nothing is measured. Each of these used to be discarded during extraction, which made them invisible to every check downstream — the annotation simply did nothing, and nothing said so. They now report `annotation.unresolved-directive` on the line they were written on.

**A `@qmx-` tag this tool does not read.** The tags are `@qmx-ignore`, `@qmx-ignore-next-line`, `@qmx-ignore-file` and `@qmx-threshold`. Anything else under the `@qmx-` prefix — a misspelling, or an invented tag — is refused by name:

```php
/**
 * @qmx-ignore-lines complexity.ccn reason="no such tag"
 */
```

```
Directive "@qmx-ignore-lines complexity.ccn" is not a tag this tool reads. The tags are @qmx-ignore, @qmx-ignore-next-line, @qmx-ignore-file and @qmx-threshold; the first two name a channel before the reason.
```

**A tag that requires a channel, written without one.** `@qmx-ignore` and `@qmx-ignore-next-line` are refused the same way — no grammar read them — and that now holds in every comment carrier: `// @qmx-ignore`, `/* @qmx-ignore */`, and `@qmx-ignore` standing alone on a docblock line. A comment's own closing delimiter is punctuation, not an argument, and the block and docblock carriers used to read its `*` as the channel. `*` is the spelling that means *no rule filter*, so those two forms silenced every channel on the declaration they stood over and said nothing about it. The authored star is unaffected — `@qmx-ignore *` still means "everything here" — and so is a selector written hard against the delimiter, such as `complexity.*` with no space before the `*/`.

**A declaration-form directive with no declaration to bind to.** `@qmx-ignore` names the symbol it is written on, so it needs a symbol that is measured: a class, interface, trait, enum, method, function or closure. Written above a statement, or on a property, it binds to nothing:

```php
public function run(int $n): int
{
    // @qmx-ignore complexity.ccn reason="above a statement"
    if ($n > 0) {
        return $n;
    }

    return 0;
}
```

```
Suppression "@qmx-ignore complexity.ccn" is written where no declaration is measured, so it binds to nothing. Write @qmx-ignore-next-line to silence the line below it, or move the tag onto the class, method or function it is about.
```

The physical forms — `@qmx-ignore-next-line` and `@qmx-ignore-file` — are bound to a line and to a file rather than to a declaration, so where they are written is not restricted this way.

!!! warning "This is a breaking change"
    Code carrying any of these forms used to analyse cleanly. It now fails the run as a configuration error, which is the point: the form was doing nothing, and a reviewer reading it assumed otherwise. The declaration form on a docblock over a property used to be worse than silent — it aborted the whole file, so every metric and every finding in that file disappeared and the run reported one file as failed. The channelless form in a block comment or a docblock was worse still: it silenced every channel on the declaration it stood over, so a run that used to pass may now report findings that annotation was hiding.

### Quoting a directive without addressing it

A docblock that documents this syntax has to be able to name the tags without addressing them. Wrap the quoted directive in backticks, or put it in a fenced block:

```php
/**
 * Write `@qmx-ignore complexity.ccn` to silence one channel.
 *
 * ```
 * @qmx-ignore-file -- generated
 * ```
 */
```

An inline quoted region opens and closes **within one line**: a backtick with no partner on its own line is an ordinary character and quotes nothing. That is what keeps a stray tick in prose from shifting where every region below it begins and ends — which would silently turn a correctly quoted example into a live directive and make the live directive under it disappear. A fenced block is recognised as itself, not as inline regions that happen to pair up, so a multi-line example needs no per-line ticks.

<!-- llms:skip-begin -->
### Example

```php
/**
 * @qmx-ignore complexity reason="legacy algorithm"
 */
final class PricingEngine
{
    // ...
}
```

`complexity` names neither a rule nor a channel — it is a bare prefix, and a bare
prefix is no longer a group. `@qmx-ignore` always addresses a channel
(`violationCode`), exactly or as `X.*`. This reports
`annotation.unresolved-directive` with a message naming the nearest valid
channels:

```
Suppression "complexity" addresses no channel. Addressable names closest to it: complexity.wmc.
```

```php
/**
 * @qmx-threshold coupling.cbo:class warning=20
 */
final class OrderAggregate
{
    // ...
}
```

`@qmx-threshold` always addresses a *rule*, never a channel narrowed to a
level — `coupling.cbo:class` names the rule `coupling.cbo` at its `class`
level, and a threshold does not distinguish levels. This also reports
`annotation.unresolved-directive`:

```
@qmx-threshold "coupling.cbo:class" addresses a rule at a level, and a threshold addresses the producing rule by its own name: it does not distinguish levels (ADR 0024). Retune the whole rule "coupling.cbo", or set the level alone with --rule-opt coupling.cbo:class.<option>=<value>.
```

```php
/**
 * @qmx-ignore complexity.ccn:callable reason="stable for now"
 */
public function calculateShipping(Order $order): float
{
    // the method was later simplified below the complexity threshold
}
```

The annotation is well-formed and once suppressed a real finding, but
`calculateShipping()` no longer trips `complexity.ccn` at its `callable`
level. This reports `annotation.unused-directive` at `Info` severity — a
prompt to delete the now-pointless annotation, not a configuration mistake.

```php
/**
 * @qmx-ignore-file Generated code, do not analyse
 */
```

`@qmx-ignore-file` is the one form whose channel is optional, so a bare word
right after the tag is genuinely ambiguous: it could be the channel, or the
first word of the reason. It is read as the channel, `Generated` addresses
nothing, and this reports `annotation.unresolved-directive`:

```
Suppression "Generated" addresses no channel. No declared name is close to it. Prose belongs after "--".
```

Write `--` before the prose to say "the reason starts here":

```php
/**
 * @qmx-ignore-file -- Generated code, do not analyse
 */
```

`--` is required only for this ambiguous case. On `@qmx-ignore` and
`@qmx-ignore-next-line` the channel argument is mandatory and always comes
first, so `@qmx-ignore complexity.ccn:callable Legacy state machine`
is unambiguous without a separator — though writing `--` there too keeps the
three tags reading the same way.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

- **`annotation.unresolved-directive`** — fix the name. Use the exact channel name for `@qmx-ignore` (or `X.*` for every descendant of `X`), and the exact rule name for `@qmx-threshold`. If a computed metric annotation started failing, either restore the metric in `computed_metrics:` or remove the now-dangling annotation. If the message points at the first word of your reason, you wrote `@qmx-ignore-file` followed directly by prose with no channel — add `--` before the reason (see the example above). If the message says the tag is *not one this tool reads*, fix the spelling against the four tags it lists. If it says the tag *binds to nothing*, the declaration form is sitting where nothing is measured — switch to `@qmx-ignore-next-line`, or move it onto the class, method or function it is about (see [Forms that never become a directive](#forms-that-never-become-a-directive)).
- **`annotation.unsupported-threshold`** — remove the `@qmx-threshold`; the targeted rule has no options a threshold can override. Check the rule's `Options` section on its own page for what it does accept.
- **`annotation.invalid-threshold`** — fix the payload to match the rule's option shape (see that rule's `Configuration` section for the expected keys and value types).
- **`annotation.unused-directive`** — delete the annotation. It is not doing anything, and leaving it in place misleads the next reader into thinking a finding is still being suppressed. If deleting it is not an option yet, accept the finding into a baseline or exclude the path; another `@qmx-ignore` is not one of the choices, and is refused.

<!-- llms:skip-end -->

### Accounting scope

`annotation.unused-directive` accounting is deliberately narrow, so it never manufactures noise from configuration choices that are working as intended:

- Only directives that address **enabled** rules are counted. Disabling a whole rule family — as the built-in `legacy` preset does — does not make its annotations "unused."
- Only directives inside the **analysed file set** are counted. Narrowing analysis with `--report=git:staged` or similar does not flag annotations in files outside that run.
- `@qmx-threshold` never participates in unused-directive accounting — a threshold override is either valid and silent, or it is a configuration error under one of the first three channels.
- **One authored directive produces exactly one finding.** A class-level `@qmx-ignore` that also binds to every method inside the class does not print once per method; the finding's subject is always the **file**, because that is where the annotation is physically written.

!!! note "Validation happens after configuration resolves"
    The channel universe used for validation is built from the run's own resolved configuration, including the computed-metric family (`health.*` and any `computed.*` metrics the project defines). So `@qmx-ignore health.cohesion` resolves exactly like a statically declared channel. Two consequences follow: removing a computed metric from configuration turns every annotation that referenced it into an `annotation.unresolved-directive` error, the same as a typo; and `@qmx-threshold` on a **disabled** rule is valid and silent — enabledness is an execution filter, not a fact about whether the rule's name exists.

### Auditing what a directive still does

The channels above answer whether a directive is *addressable* — whether it names something, and whether a suppression silenced anything. Neither of them answers what a `@qmx-threshold` is doing, because nothing a rule publishes says which boundary it decided with.

[`bin/qmx directives`](../usage/cli-options.md#directives) answers that separately, for both tags at once: it removes each threshold directive on its own and executes the rules again over the same run's measurements. It is not part of `qmx check` — one rule execution per directive is a price a normal run should not pay — and it is meant to be run deliberately, or as its own CI step.

### Options

| Option                      | Default | Description                                                                             |
| --------------------------- | ------- | --------------------------------------------------------------------------------------- |
| `enabled`                   | `true`  | Enable or disable directive validation as a whole.                                      |
| `unused_directive_severity` | `info`  | Severity for `annotation.unused-directive`. Allowed values: `info`, `warning`, `error`. |

The other three channels — `annotation.unresolved-directive`, `annotation.unsupported-threshold`, and `annotation.invalid-threshold` — have no severity option: they gate the run unconditionally, the same way the five architecture configuration diagnostics do (see [Architecture Rules](architecture.md#coverage-modes)).

### Configuration

```yaml
# qmx.yaml
rules:
  annotation.directive:
    enabled: true
    unused_directive_severity: warning   # raise cleanup nudges to warning
```

```bash
bin/qmx check src/ --rule-opt="annotation.directive:unused_directive_severity=warning"
```
