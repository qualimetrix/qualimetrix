# Why the top of the scale is a dead zone, term by term

> **Numbering note.** This file was written against round 1 of the plan, whose
> defects were labelled D1-D5 and whose stages were `04-formulas.md` onward.
> Round 2 renumbered: the model corrections are M1 (monotonicity) and M2
> (applicability) in `04-model.md`, and the threshold work is `05-calibration.md`.
> The measurements below are unchanged; only the labels around them moved.

The maintainability formula is three penalties subtracted from 100
(`ComputedMetricDefaults.php:105-109`):

```
100
  - max(82 - mi.avg, 0) * 2.0
  - max(55 - mi.p5,  0) ** 0.5 * 4.5
  - max(5  - mi.min, 0) ** 0.4 * 1.5
```

Each term is identically zero once its input clears the threshold, so a subject
above all three thresholds scores exactly 100 — not "measured as excellent", but
*not measured at all*. Namespace-level counts, measured 2026-09-14:

| project     | namespaces | `mi.avg` < 82 | `mi.p5` < 55 | `mi.min` < 5 | median `mi.min` |
| ----------- | ---------- | ------------- | ------------ | ------------ | --------------- |
| flysystem   | 4          | 0 / 4         | 0 / 4        | 0            | 64.3            |
| codeigniter | 1          | 1 / 1         | 1 / 1        | 0            | 22.5            |
| wordpress   | 25         | 23 / 25       | 14 / 25      | 2            | 46.6            |

Three things follow.

1. **Flysystem's 100.0 is the identity element.** All four of its namespaces
   clear all three thresholds, every term evaluates to zero, and the formula
   returns its constant. The same holds for the five projects whose *median*
   namespace maintainability is exactly 100 (`00-namespace-distribution.md`).
2. **The `mi.min` term is very nearly dead.** Its threshold is 5 on a scale
   whose observed namespace minimum is 22.5 even in CodeIgniter. Across the
   three projects measured here it fires in 2 of 30 namespaces, both in
   WordPress. A term that cannot fire on CodeIgniter is not distinguishing
   anything in the library corpus.
3. **The problem is the threshold placement, not the penalty weights.** Raising
   the multipliers would only steepen the part of the range where the terms
   already fire — the projects in the dead zone would stay at exactly 100.

This is the mechanism behind D3 and the reason D3 cannot be fixed by tuning
coefficients alone. It also bounds the overview's C2: while the formula shape is
"100 minus thresholded penalties", any subject clearing every threshold scores
exactly 100, so "at most one project >= 99" is a constraint on threshold
placement, not on weights.
