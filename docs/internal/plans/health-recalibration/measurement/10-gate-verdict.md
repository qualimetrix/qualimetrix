# The finding gate on the recalibration

`composer gate -- --reference=48e5278d` — the commit the formula change starts
from. Exit 1, verdict RED, 14 failures.

## Red is the expected verdict, and its shape is what matters

All fourteen failures are in a single corpus case, `health`, one per compared
surface: the generated baseline file, eleven output formats, the suppressed
report, and the finding count. **The other eighteen corpus cases are green.**

Within that case:

- **No finding disappeared.** The gate reports two lines "only in candidate" —
  `health.complexity` on a class and `health.maintainability` on a namespace,
  both newly reported because the formulas got stricter — and **zero** lines
  "only in reference".
- **No identity changed.** Channel names, symbols and file anchors are
  identical on both sides. The differences are magnitudes: 89.0 against 99.0,
  69.5 against 80.1, and so on, consistently across every format.

That distinction is the one the repository's gate documentation asks for: a
health finding whose magnitude moved is the intended effect of a recalibration,
while a health finding whose identity moved would be a different defect
entirely. Only the first kind is present.

## What this rules out

A recalibration could have leaked in three ways the gate would have caught, and
none is present: a changed channel name reaching a published surface, a finding
vanishing from one format while surviving in another, and a non-health case
moving because a shared code path changed underneath it.

---

## Second run, after coverage publication and the hint-vocabulary repair

`composer gate -- --reference=48e5278d`, exit 1, RED, 32 failures — up from 14,
and spread across all nineteen corpus cases rather than one. Wider red is a
different claim from more red, so it was taken apart before being accepted.

| where                    | surfaces                | cause                                                        |
| ------------------------ | ----------------------- | ------------------------------------------------------------ |
| `case:health`            | all fourteen, as before | magnitudes moved, and the score now carries a coverage field |
| the other eighteen cases | `format:html` only      | aggregate metric keys gained their own tooltip bands         |

Nothing outside the health case moves in JSON, SARIF, checkstyle, GitLab,
GitHub, metrics, text or the baseline file. Coverage did not leak past the
health section.

The HTML difference is the repair, not a side effect. `complexity.ccn.avg` had
no bands of its own and fell back to the raw `complexity.ccn` bands, so the
tooltip under an **average** advertised the thresholds written for a **single
method** — "Simple, easy to test" up to 4, "Moderate complexity" up to 10. It
now carries bands keyed to the formula's own knee. Eighteen cases differ because
eighteen cases render HTML with those keys, not because eighteen behaviours
changed.

Still true, and still the point of running this: no finding vanished, and no
channel, symbol or file anchor changed identity on any surface.
