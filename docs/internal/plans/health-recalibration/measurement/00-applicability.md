# The aggregate's sample, and what it is a sample of

Measured 2026-09-14, refined 2026-09-14 after a first reading of the same data
turned out to be wrong. The correction is kept visible below rather than
silently replaced.

## The mechanism, identified exactly

The project-level aggregate of `coupling.distance` is the unweighted mean over
**leaf namespaces, excluding `(global)`**. Predicting the published value from
that rule reproduces it to six decimal places on every project tested:

| project     | leaf namespaces minus `(global)` | predicted | published  | published `.count` |
| ----------- | -------------------------------- | --------- | ---------- | ------------------ |
| wordpress   | 20                               | 0.466809  | 0.466809   | 20                 |
| qmx         | 123                              | 0.282460  | 0.282460   | 123                |
| flysystem   | 2                                | 0.325     | 0.325      | 2                  |
| codeigniter | **0**                            | —         | **absent** | absent             |

CodeIgniter's entire `system/` tree lives in the global namespace. Its one
namespace symbol carries `coupling.distance` 0.944 — near the worst possible —
and is excluded from the aggregate by the `(global)` rule. Nothing remains, no
aggregate is published, and the formula term
`(m["coupling.distance.avg"] ?? 0) * 6` contributes zero.

That is the whole of `measurement/00-whole-beats-its-part.md`: the project
outscores its only namespace because the one value that would have penalised it
is filtered out on the way up.

## What was read wrong the first time

The earlier version of this file said WordPress's `distance.avg` of 0.467 was
"the mean over 20 namespaces, largely the vendored `WpOrg\Requests`", and that
it therefore "describes a third-party dependency rather than the project".

That is wrong. `(global)` holds 451 of WordPress's 589 classes and *is* a leaf;
it is excluded by the rule above, but the remaining 20 are not predominantly
vendored — they include `SimplePie`, `ParagonIE\Sodium` and others. The number
is not about a single dependency.

The real defect in that number is different and still real: **the mean is
unweighted across namespaces of wildly different size.** `Avifinfo`, with seven
classes and D = 1.0, contributes as much as `(global)` would with 451. A
structural average over namespaces says nothing about how much code sits behind
each one.

## Coverage is still unstated

Separately from the `(global)` rule, no formula reads a `.count`, so no score
says what share of its subject it covers. `cohesion.tcc` covers 80 of 139
classes in CodeIgniter and 270 of 589 in WordPress, because TCC is undefined
below a method-count floor. A cohesion score for those projects is a statement
about the minority of classes for which it could be computed, published as a
statement about the project.

## Consequence

Three distinct defects, which the first reading of this data ran together:

1. `(global)` is dropped from the structural aggregate, so wholly procedural
   code receives no structural penalty at all.
2. Namespace aggregates are unweighted, so a seven-class namespace and a
   451-class namespace carry equal weight in the project number.
3. No score states its coverage, though every aggregate publishes a `.count`.
