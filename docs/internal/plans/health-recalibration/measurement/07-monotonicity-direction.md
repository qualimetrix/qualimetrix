# Monotonicity has two directions and only one of them is a defect

Measured 2026-09-14 with `scripts/health-calibration.php` over the fifteen-project
capture, three levels. C1 as first written — "the parent score lies within
`[min(children), max(children)]`" — is reported by the bench as 1332 violations.
The table below is a separate count, taken directly from the capture with
children resolved by name containment; it totals 1436. The two differ because
the bench resolves a namespace's children as the classes it directly holds while
this count assigns every class to its enclosing namespace by name. The split by
direction is the point here, not the total, and both counts agree on the shape.

| level pair         | dimension       | checked | parent **above** max | parent **below** min |
| ------------------ | --------------- | ------- | -------------------- | -------------------- |
| namespace->class   | cohesion        | 712     | 29                   | 90                   |
| namespace->class   | complexity      | 712     | 12                   | 158                  |
| namespace->class   | coupling        | 712     | 0                    | 604                  |
| namespace->class   | maintainability | 712     | 92                   | 43                   |
| namespace->class   | overall         | 712     | 91                   | 284                  |
| namespace->class   | typing          | 712     | 9                    | 16                   |
| project->namespace | coupling        | 15      | 0                    | 7                    |
| project->namespace | overall         | 15      | 0                    | 1                    |
| project->namespace | others          | 15 each | 0                    | 0                    |
|                    | **total**       |         | **233**              | **1203**             |

## The two directions mean different things

**Parent below min(children) — mostly legitimate.** The parent formula is not
the child formula applied to bigger inputs; it carries terms the child level has
no equivalent for. A namespace is scored on its distance from the main sequence
and on its own efferent breadth, neither of which exists for a class. A
namespace of five individually well-decoupled classes can still, as a unit,
depend widely. Coupling shows this at its cleanest: 604 below, **zero** above.

**Parent above max(children) — the defect.** Nothing about aggregation should
make a whole healthier than every one of its parts. A penalty present at the
child level failed to reach the parent. This is the CodeIgniter case, and 233
instances of it exist across the corpus.

## Correction to C1

C1 becomes one-sided: **no parent scores above the maximum of its children.**
The two-sided form reddens 1203 legitimate cases and would drown the 233 real
ones — a criterion that fails for the wrong reason five times out of six teaches
whoever runs it to stop reading the output.

The below-min direction is not discarded; it is a distribution to watch in C6,
where a parent far below all its children says the level formulas have drifted
apart, not that an aggregate lost something.

## Also measured: the cost of the unweighted mean

C4 compares the current aggregation with size-weighted pooling. The drift in
`health.coupling` runs from 0.03 (phpunit) to 2.65 (php-parser) points, and in
`health.overall` from 0.01 to 0.53. The unweighted mean is a real defect and a
small one; the plan's earlier wording implied a larger magnitude than the
measurement supports.
