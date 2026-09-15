# The project scores better than its only namespace

Measured 2026-09-14 on `codeigniter/framework` 3.1.13 `system/`, one run,
`--format=metrics --workers=0`, neutral working directory.

CodeIgniter's `system/` tree declares no namespaces, so the analysis produces
exactly one namespace symbol, `(global)`, covering all 139 classes. The project
symbol therefore aggregates over a single part.

| level                | `coupling.distance` | `health.coupling` | `health.overall` |
| -------------------- | ------------------- | ----------------- | ---------------- |
| namespace `(global)` | 0.944               | 76.1              | 64.7             |
| project              | `.avg` **absent**   | **100.0**         | **69.5**         |

The whole scores better than its only part, on both dimensions.

## Mechanism

`coupling.distance` is collected on the namespace symbol and carries 0.944 —
close to the worst possible distance from the main sequence. At project level
the aggregate `coupling.distance.avg` is not published at all: the project key
list contains neither `coupling.distance` nor `coupling.distance.avg` nor
`coupling.distance.count`, while WordPress and this repository publish both
`.avg` and `.count`. Whatever suppresses the aggregate for a single-namespace
project, the effect on the formula is unambiguous — the term
`(m["coupling.distance.avg"] ?? 0) * 6` contributes nothing, and with every
other penalty below its threshold the denominator collapses to the constant 18.

## Why this outranks the earlier findings

`00-anchor-probe.md` framed the CodeIgniter result as "an object-coupling metric
finds nothing to measure in procedural code". That framing is too generous. The
metric *did* measure it, at namespace level, and produced a near-worst value.
The project score is not a different opinion about the same evidence — it is the
same evidence with the damaging term dropped in aggregation.

It also settles a question raised in review: whether CodeIgniter's global
namespace exists and yields D = 1 (maximum penalty), or whether the input is
simply absent. Both are true, at different levels, and the disagreement between
the levels is the defect.

## Consequence for the plan

A monotonicity property belongs in the acceptance criteria, and it is
falsifiable in a way C1-C3 are not: **a project with exactly one namespace must
not score better than that namespace.** Any subject whose parts are all bad and
whose whole is good is a defect regardless of where the thresholds sit, and this
check needs no a-priori ranking, no corpus and no judgement call to run.
