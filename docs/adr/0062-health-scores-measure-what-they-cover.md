# 0062. Health Scores Measure What They Cover

**Date:** 2026-09-14
**Status:** Accepted. [ADR 0080](0080-a-project-fold-reads-a-partition-not-the-leaves.md) supersedes the leaf-only aggregation rule; every other decision here remains in force.

## Context

Health scores are six 0-100 dimensions computed for every class, namespace and
project from formulas in `ComputedMetricDefaults`. Their thresholds and weights
were last set against a corpus of fifteen open-source libraries. Re-measuring
that corpus produced four findings, each reproduced by a run rather than argued.

**A project could score better than its only part.** CodeIgniter 3, whose entire
tree lives in the global namespace, scored a perfect 100.0 on `health.coupling`
while the single namespace it is made of scored 76.07. The project aggregate of
`coupling.distance` is the unweighted mean over leaf namespaces excluding the
global one — a rule that reproduces the published value to six decimals on
WordPress, this repository and flysystem, and that leaves nothing at all for a
project written entirely in the global namespace.

The exclusion was not a decision about measurement. `NamespaceTree` skipped the
empty string because an empty string has no parent chain to walk, which is
correct for building a tree and wrong as a filter on what gets measured;
`ProjectNamespaceResolver` has always held that the empty namespace is project
code. A traversal guard had become a measurement filter.

**The corpus had no floor.** All fifteen projects were top-decile libraries;
`health.overall` ran 62.9 to 91.6 and never reached the Poor band the product
documents. The lower half of a declared scale was calibrated against nothing.

**The top of the scale was an identity.** Every formula is 100 minus thresholded
penalties, so a subject clearing every threshold scores exactly 100 by
construction rather than by merit. Three projects and the median namespace of
five scored exactly 100 on maintainability.

**Scores did not say what they covered.** Every aggregate publishes a `.count`
and no formula read one. Cohesion is undefined below two methods, so a cohesion
score describes between 27% and 58% of a project's classes without saying so.

## Decision

**The global namespace participates in aggregation.** It is a leaf by
definition: no parent, no children. The leaf-only rule that stood here is
replaced by [ADR 0080](0080-a-project-fold-reads-a-partition-not-the-leaves.md):
the double counting it guarded against is real, and a partition over each
namespace's own declarations prevents it without dropping the 45% of classes
that parents declare.

**Two legacy anchors join the tracked corpus** — CodeIgniter 3 and WordPress
core, both public packages, both parsing cleanly under PHP 8.4. A floor that
exists only on one machine guards nothing in CI.

**Thresholds are recalibrated against a judgement formed without the numbers.**
A ranking of every corpus project into coarse bands per dimension was written by
someone with access only to raw metrics and sources, and committed before any
coefficient moved. Agreement went from 6 of 17 projects to 14.

Thresholds are literature-anchored where literature exists — Coleman's
maintainability lines at 85 and 65, SonarSource's cognitive default at 15,
LCOM4's semantics of 2 as "the average class splits in two" — and declared
corpus-derived where it does not. Two knees rest on practitioner convention for
average complexity per callable; because that convention was also the ranking's
stated anchor, complexity's agreement is partly true by construction, and is
recorded as such rather than counted as confirmation.

**Weights are unchanged, by measurement.** A grid search over every weight vector
at 0.05 resolution reaches at most the same 14 of 17 the current weights already
reach.

**Coverage is published alongside the score; scores are not damped by it.**
Publishing is cheap and honest. Damping was decided and then withdrawn: cohesion
coverage runs 27% to 58% across the corpus with the two legacy anchors at the
*top* of that range, so low coverage tracks small-class design rather than
decay, and no threshold separates anything. The only dimension whose coverage
collapses to zero is the structural one, repaired at its source above.

A first-class "not applicable" state was considered and rejected for this work.
The engine has no per-subject non-publication path: the only route leads to
`health.overall`'s `?? 75` fallback, and per-subject renormalisation requires a
non-canonical `health.overall` that `WeightedHealthFormula::termsOf` cannot read
off the parse tree — it reads every term of the canonical
`clamp((m["health.dim"] ?? fallback) * weight + …, 0, 100)` shape or returns
null, never a partial read — and that `HealthFormulaExcluder` therefore refuses. Reaching it means changing the evaluator, the
repository, the formatters and the excluder — a feature with its own decision.

## Consequences

**Scores move for every project.** Consumers with a recorded baseline regenerate
it. Our own ratchet took nineteen newly accepted warnings, fourteen of them
maintainability, with no channel loosened.

**Coupling remains blind to coupling that does not go through a type name.**
CodeIgniter couples through a service locator (76 `get_instance()` calls against
an average CBO of 1.69) and WordPress through global state and hooks (797 global
declarations, 1716 filter dispatches against 4.02). CBO's published algorithm is
about class references and this is it working, not failing. Calibration must not
compensate: lowering a threshold until procedural code looks appropriately
coupled would distort every project that does couple through types. A reader who
sees a legacy application score well on coupling needs to know the dimension
measures type coupling.

**Three bands remain unreached, and one is unreachable.** WordPress is judged
critical and scores 38.9; holding coupling at its measured value and putting
cohesion and maintainability at the floors of their own judged bands already
sums past the ceiling before the other two dimensions contribute. The judgement
and the scale disagree there, and the scale is not bent to close it.

**Fourteen of seventeen is the honest maximum, not a plateau.** Four agreements
hold by less than a point. Any change to a base metric will move projects across
band edges, and re-running the comparison is part of making such a change.

**Class-level coupling is still an identity.** 97.1% of classes score exactly
100, because the formula's threshold sits above the 99th percentile of the input
and its documented calibration anchor — a class with fifteen dependency packages
— exists nowhere in the corpus, whose maximum across 6922 classes is seven.
Waking the term drives upward monotonicity violations from 0 to 106 because the
namespace formula is untouched. Repairing it is a change to two levels at once
and is left as a separate decision.

**Monotonicity is repaired in one instance, not as a class.** Two of 251 upward
violations across the corpus disappear. The other 249 have causes that have not
been traced, and no claim is made about them.
