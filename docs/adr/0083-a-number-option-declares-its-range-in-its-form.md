# 0083. A Number Option Declares Its Range in Its Form

**Date:** 2026-09-24
**Status:** Accepted

## Context

[ADR 0055](0055-a-rule-option-declares-the-shape-of-its-value.md) made every
rule option declare the shape of its value, and the recognition walk refuses a
value of another shape with exit code 3. The shape said what *type* a number
was — whole or fractional — and nothing about which numbers made sense.

Every numeric option under `rules:` is a count or a boundary on a measurement
that is never negative: a complexity, a size, a coupling ratio, a percentage, a
maintainability index clamped to 0..100. A negative boundary does not tighten
such a rule; it inverts it. `size.method-count: {warning: -1}` makes
`value >= -1` hold for every class, so the rule reports everything;
`design.type-coverage.param: {warning: -1}` makes `value < -1` hold for none,
so the rule is silently off. Both arrived from `qmx.yaml` and from
`--rule-opt` alike, and both runs reported success. The inline
`@qmx-threshold` validators already refused a negative boundary; the two
configuration doors did not.

The `threshold` shorthand made the question sharper. It is unfolded into its
`warning`/`error` pair before the recognition walk, and the unfolding is gated
on the value having the group's declared form, so that a malformed shorthand is
refused under the key the author wrote. A range checked only by the walk would
have let `threshold: -1` unfold and be refused as `warning` — a key nobody
wrote.

## Decision

**1. The range is part of the form.** `RuleOptionValueForm::WholeNumber` and
`::Number` accept 0 and above only; `accepts()` answers type and range
together. `RuleOptionShape::integer()` and `::number()` keep their names and
gain the floor. Because the recognition walk and the shorthand unfolding both
ask the form, they cannot disagree about a negative value: `threshold: -1`
stays `threshold` and is refused under that name.

**2. One unbounded form, for the one population that needs it.**
`RuleOptionValueForm::SignedNumber` / `RuleOptionShape::signedNumber()` accept
either sign. It is declared only by a computed metric's
`warning`/`error`/`threshold`, whose formula the user writes and may well be
negative.

**3. A value out of range is refused by the value.** The refusal reads
`must be a non-negative whole number or null, got -1.` — the written value is
the answer to a range question, as the written word already is to a
closed-set one. A value of the wrong type keeps its form in the answer
(`got a string`).

**4. Zero stays in.** `warning: 0` reports every symbol of a
higher-is-worse rule and none of a lower-is-worse one; both are how a reader
asks for "all" or "none", and refusing them would eat working configurations.
No upper bound is declared: a boundary above a ratio's maximum silences a rule
without inverting it, and the canonical probe magnitudes of the promise-effect
stand lie above every such maximum.

## Rejected alternatives

- **Separate `nonNegativeInteger()` / `nonNegativeNumber()` factories beside
  the unbounded ones.** The unbounded spelling would remain the short, obvious
  one, and the next option declared with it would accept a negative boundary
  again without a word. With the floor in the default form, forgetting it is
  a loud refusal of a legitimate negative, which a test catches.
- **A bounds check in `ThresholdParser`.** It runs inside each options class's
  `fromArray()`, after the shorthand has been unfolded, and only for keys that
  go through it; counts such as `min_methods` or `max_cycle_size` never do.
- **A per-option `min()` modifier.** Every numeric option in the tree has the
  same floor; a per-declaration modifier would be a hundred copies of one fact,
  each of which could be forgotten.

## Consequences

- `--rule-opt=size.method-count:threshold=-1`, `warning: -1`,
  `--max-cycle-size=-1` and every other negative rule option end the run with
  exit code 3. A configuration that used a negative threshold to switch a
  lower-is-worse rule off writes `0` (identical effect) or `enabled: false`.
- The refusal text of every numeric rule option changes from "a whole number"
  / "a number" to "a non-negative whole number" / "a non-negative number".
- The two numbers of one rule are still not checked against each other: a
  `warning` above its `error` is accepted.
