# D1 probed offline, and half refuted

> **Numbering note.** This file was written against round 1 of the plan, whose
> defects were labelled D1-D5 and whose stages were `04-formulas.md` onward.
> Round 2 renumbered: the model corrections are M1 (monotonicity) and M2
> (applicability) in `04-model.md`, and the threshold work is `05-calibration.md`.
> The measurements below are unchanged; only the labels around them moved.

The project coupling formula was re-evaluated outside the product, in a script
that reads the published `--format=metrics` output and recomputes the formula
from its inputs. Measured 2026-09-14.

**Instrument check first.** The recomputation reproduces the published
`health.coupling` exactly for every project tested — qmx 51.0, CodeIgniter
100.0, WordPress 68.2, flysystem 90.2. The comparison below is therefore a
comparison of formulas, not of two different measurements.

| project     | current project formula | namespace (efferent-only) formula on project inputs | `cbo.max` | `ce.max` |
| ----------- | ----------------------- | --------------------------------------------------- | --------- | -------- |
| qmx         | 51.0                    | 65.6                                                | 125       | 124      |
| wordpress   | 68.2                    | 79.7                                                | 79        | 35       |
| codeigniter | 100.0                   | 100.0                                               | 15        | 4        |
| flysystem   | 90.2                    | 90.2                                                | 20        | 20       |

## What holds

The D1 diagnosis holds for this repository: 51.0 to 65.6, against the 64.6
predicted from the earlier decomposition. The afferent magnets that fill
`cbo.max` (125, against `ce.max` 124 — a different class) stop counting, which
is the intended effect.

## What is refuted

1. **CodeIgniter does not move at all.** It has no efferent coupling to speak of
   (`ce.max` 4), so an efferent-only formula gives it the same perfect 100.
   D1 and D2 are therefore independent defects, and fixing D1 does not advance
   C3 by a single point.
2. **WordPress moves the wrong way**, 68.2 to 79.7 — the alignment makes the
   legacy anchor look *more* like the libraries, which is the opposite of what
   the recalibration needs. This is not an argument against D1, which is a
   correctness fix, but it does mean D1 makes C1 and C2 harder rather than
   easier, and the D3 work has to recover the difference.

## Correction to the plan

`04-formulas.md` states as a stop condition that "this repository's
`health.coupling` rises from 51.0 and CodeIgniter's falls from 100.0", and that
the opposite direction means the diagnosis was wrong. The CodeIgniter half of
that condition is unsatisfiable by construction: the stage would have halted for
the absence of an effect that the change cannot produce. The stop condition is
the qmx movement alone; CodeIgniter belongs to D2's criterion, not D1's.
