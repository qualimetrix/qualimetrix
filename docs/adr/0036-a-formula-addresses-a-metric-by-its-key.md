# 0036. A Formula Addresses a Metric by Its Key

**Date:** 2026-08-28
**Status:** Accepted
**Supersedes** the variable encoding introduced with computed metrics, in which
the key `a.b` was written `a__b` inside a formula.

## Context

Expression Language forbids a dot in an identifier, so a formula could not name
a metric key directly. The encoding answered that: `ccn.avg` was written
`ccn__avg`, and `ComputedMetricDefinition` grew a guard refusing any computed
metric name containing `__`, so that a user-chosen name could never be mistaken
for an encoded key. A costume, and a second costume guarding the first.

Four separate places knew the encoding: the formula validator, the evaluator,
the dependency-graph calculator and the health formula excluder. Each decoded
`__` back to `.` with its own regular expression.

[ADR 0035](0035-a-metric-key-names-its-family-in-kebab.md) made every key kebab.
A hyphen is no more legal in an Expression Language identifier than a dot, so
the encoding would have had to grow a second escape and the guard a second
prohibition.

## Decision

A formula sees one variable, `m`, holding the symbol's metrics, and reaches a
metric by its published key: `m["complexity.ccn.avg"]`. The encoding is removed,
and the guard that protected it is removed with it — the name grammar for a
computed metric becomes lower-case kebab, checked as one template rather than by
its first segment.

**The typo protection the encoding gave away for free is restated explicitly.**
Under the encoding, `ccnn__avg` was an unknown *variable* and Expression
Language refused it at parse time. With one variable that check is worth
nothing, so `FormulaMetricReference` reads the string literals a formula indexes
`m` with, and a computed index — `m[$x]`, a concatenation — is refused loudly
rather than validated as far as it can be. Absence answers rather than warns:
`m['x'] ?? 0` is the idiom for an optional metric, and a missing key never
becomes a PHP warning inside an expression.

## Consequences

Every formula in a user's `qmx.yaml` must be rewritten. The mapping is
mechanical — `a__b` becomes `m["a.b"]` under the new key spelling — and it is in
`CHANGELOG.md`.

A computed metric name containing `_` is refused where it used to be accepted.
`computed.branch_load` becomes `computed.branch-load`; the same rename closes
the last snake-cased producer in the channel universe.

The four decoders become one reader, and it is the only place that knows how a
formula names a metric.
