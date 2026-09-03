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

| Channel                            | Meaning                                                                                                                                                                                                                                          | Kind                | Severity                                                        |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------- | --------------------------------------------------------------- |
| `annotation.unresolved-directive`  | The directive names something it is not allowed to address — a typo, a rule name written where a channel was expected, an `X.*` wildcard that matches nothing, or a dangling reference to a computed metric that was removed from configuration. | Configuration error | `Error`, not configurable                                       |
| `annotation.unsupported-threshold` | `@qmx-threshold` targets a rule that declares no threshold-override support.                                                                                                                                                                     | Configuration error | `Error`, not configurable                                       |
| `annotation.invalid-threshold`     | The `@qmx-threshold` payload itself is malformed — the wrong shape or an unparsable value for that rule's options.                                                                                                                               | Configuration error | `Error`, not configurable                                       |
| `annotation.unused-directive`      | The directive is valid and addresses something real, but nothing it addressed fired this run.                                                                                                                                                    | Ordinary debt       | `Info` by default, configurable via `unused_directive_severity` |

The first three are **configuration errors**: they report a mistake in what was written, not debt in the analysed code. Like the architecture configuration diagnostics (see [Architecture Rules](architecture.md#coverage-modes)), they fail the run unconditionally whenever they fire — `fail_on` is not consulted, not even `fail_on: none` — and none of them can be accepted into a baseline or silenced with another `@qmx-ignore`. A severity option on any of them would look like a behaviour switch while changing nothing, so none of the three exposes one.

`annotation.unused-directive` is different: the directive was well-formed and once mattered, it just did not suppress or override anything this particular run. That is ordinary cleanup debt, not a mistake — it behaves like any other rule, with a configurable severity, and it can be accepted into a baseline, excluded by path or namespace, and narrowed by a git scope like any other finding.

The one thing it cannot be is **suppressed by a directive**. `@qmx-ignore`, `@qmx-ignore-next-line` and `@qmx-ignore-file` are all refused when their target reaches `annotation.unused-directive` — by its exact name, through `annotation.*`, or with `:file` after either — and the refusal is reported as `annotation.unresolved-directive` on the line the directive was written on. A directive that hid this channel would hide the answer to the question the channel exists to ask. A bare `@qmx-ignore-file` with no channel at all is not refused, since it names nothing to refuse, but it no longer silences the channel either.

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
 * @qmx-threshold coupling.cbo.class warning=20
 */
final class OrderAggregate
{
    // ...
}
```

`@qmx-threshold` always addresses a *rule*, never a channel —
`coupling.cbo.class` is a channel of the rule `coupling.cbo`. This also
reports `annotation.unresolved-directive`:

```
@qmx-threshold "coupling.cbo.class" names no rule. "coupling.cbo.class" is a channel of rule "coupling.cbo" — a threshold addresses the rule.
```

```php
/**
 * @qmx-ignore complexity.cyclomatic.callable reason="stable for now"
 */
public function calculateShipping(Order $order): float
{
    // the method was later simplified below the complexity threshold
}
```

The annotation is well-formed and once suppressed a real finding, but
`calculateShipping()` no longer trips `complexity.cyclomatic.callable`. This
reports `annotation.unused-directive` at `Info` severity — a prompt to delete
the now-pointless annotation, not a configuration mistake.

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
first, so `@qmx-ignore complexity.cyclomatic.callable Legacy state machine`
is unambiguous without a separator — though writing `--` there too keeps the
three tags reading the same way.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### How to fix

- **`annotation.unresolved-directive`** — fix the name. Use the exact channel name for `@qmx-ignore` (or `X.*` for every descendant of `X`), and the exact rule name for `@qmx-threshold`. If a computed metric annotation started failing, either restore the metric in `computed_metrics:` or remove the now-dangling annotation. If the message points at the first word of your reason, you wrote `@qmx-ignore-file` followed directly by prose with no channel — add `--` before the reason (see the example above).
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
