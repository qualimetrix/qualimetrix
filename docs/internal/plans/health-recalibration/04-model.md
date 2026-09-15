# P3 — the model corrections

Two defects that thresholds cannot reach. Both are corrections with a stated
expected direction, so both are judged without reference to the a-priori ranking
— which is why they come before P4 and not with it.

## M1 — a whole must not outscore its only part

`measurement/00-whole-beats-its-part.md`: CodeIgniter's project score is 100.0
`health.coupling` against 76.1 for the single namespace it aggregates.

**The mechanism is identified, not open.** The project aggregate of
`coupling.distance` is the unweighted mean over leaf namespaces **excluding
`(global)`**. That rule reproduces the published value to six decimals on
WordPress (20 namespaces, 0.466809), this repository (123, 0.282460) and
flysystem (2, 0.325); CodeIgniter, whose whole tree is in the global namespace,
has nothing left after the exclusion, publishes no aggregate, and so takes no
structural penalty — `measurement/00-applicability.md`.

Round 1 of this plan proposed two candidate mechanisms, "a single-namespace
project" and "an aggregate dropped at count one". Both are wrong; the data
showed the third. A named puzzle is as much a hypothesis as a named cure.

Two decisions follow, and they are separable:

- **Whether `(global)` should be excluded from structural aggregates at all.**
  The exclusion is defensible for a project that merely has a few loose helpers
  in the global namespace, and indefensible for one written entirely there. A
  rule keyed on the share of code involved is the obvious shape; the share is
  computable from data already collected.
- **Whether the aggregate should be unweighted.** `Avifinfo`, seven classes,
  D = 1.0, currently counts as much as a 451-class namespace. This is a second
  defect in the same aggregate and is in scope for M1 because it has the same
  consequence: a project number that does not describe the project.

C1 is broader than this metric. The stage runs it across the corpus at every
level pair, not only on the anchor that exposed it, and reports every parent
scoring outside the range of its children.

## M2 — a score states what it covers

`measurement/00-applicability.md`: WordPress's `coupling.distance.avg` is the
mean over 20 namespaces of a 589-class project, most of them a vendored library.
The number is present, plausible, and about a different subject. CodeIgniter's
`cohesion.tcc` covers 80 of 139 classes.

Every aggregate already publishes `.count`. No formula reads one.

The decision this stage owes: what a dimension does when its coverage is
negligible. Three candidates, and the choice is an ADR-level one because it
changes what the product publishes:

- **damp the score** toward a neutral value as coverage falls — cheapest, one
  file, but it still publishes a number that claims to be about the subject;
- **refuse the dimension for that subject** — honest, and **ruled out for this
  work**. The engine has no per-subject non-publication path: the only route
  leads to `health.overall`'s `?? 75`, and per-subject renormalisation needs a
  non-canonical `health.overall` that `WeightedHealthFormula` does not parse and
  `HealthFormulaExcluder` refuses. `HealthFormulaExcluder` works at
  configuration time over the definition list, not per subject at evaluation
  time, so citing it as "a shape the engine already has" is wrong. Reaching this
  candidate means changing the evaluator, the repository, the formatters, the
  excluder and its tests — a product feature with its own ADR, not a step of a
  recalibration;
- **publish the coverage alongside the score** and leave interpretation to the
  reader — smallest behaviour change, largest reporting change.

Review (c-10) established that "make absence explicit" has no safe form in the
engine today: dropping a `??` makes the runtime validator throw, the throw is
caught, the dimension is silently not published, and `health.overall` then reads
its `?? 75` fallback — an invented neutral that hides the whole event. Whichever
candidate is chosen, the `?? 75` fallbacks in `health.overall` are part of this
stage, not a separate concern.

## The decision this stage takes on M2

Coverage is **published alongside the score**. Damping is **not** applied.

Round 2 chose "publish and damp"; the damping half is withdrawn on measurement
(`measurement/05-coverage-has-no-threshold.md`). Cohesion coverage runs 27% to
58% across the seventeen-project corpus, with doctrine-dbal lowest and the two
legacy anchors near the top — low coverage tracks small classes, which is modern
library design, not decay. No threshold separates anything in that range, and
the only dimension whose coverage collapses to zero is the structural one, which
M1 repairs at its source rather than by damping its symptom.

So M2's whole content is: every score carries the share of its subject it was
computed over, and that share reaches the report. The `.count` keys already
exist; nothing new is collected.

This also answers the question the plan left open — "the fraction, the
denominator and the damping are numbers this stage chooses". The fraction is
none, and the reason is a measurement rather than a preference.

## What this stage must not do

- Not move a threshold or a weight. That is P4, and mixing them lets a
  coefficient absorb a structural error.
- Not change `health.overall` away from a canonical weighted sum without
  changing `HealthFormulaExcluder` and its tests in the same commit
  (`HealthFormulaExcluder.php:167-186` refuses any other shape).

## A withdrawn prediction

Round 1 of this plan claimed that aligning the project coupling formula with the
namespace one moves this repository 51.0 → 65.6. Review (c-02) showed the
namespace formula reads bare `coupling.distance` and `coupling.ce`, neither of
which exists at project level, so the probe's two silent zeroes inflated it.
The number is withdrawn and is not a stop condition. Re-derive it in this stage,
against keys verified present in a real project-level run.

The other half of that prediction — that CodeIgniter's 100.0 would fall — was
refuted by measurement before review
(`measurement/00-d1-probe.md`): with `ce.max` 4 the efferent formula gives it
the same 100.0. Alignment of inputs and the anchor's perfect score are separate
problems; only M1 and M2 touch the latter.

## Definition of Done

- `php scripts/health-calibration.php --verdicts` exits 0 — every criterion
  C1-C7 re-checked, not only the ones this stage set out to move. A criterion
  satisfied by an earlier stage must not be quietly undone here.
- C1 holds across the corpus, reported by the bench. CodeIgniter's project
  coupling is at or below its namespace value.
- C3 holds: every dimension whose inputs cover a negligible share of a subject
  reports so, and the bench prints the coverage it used.
- The mechanism behind M1 is written down in the stage report, not only fixed.
- The `?? 75` fallbacks in `health.overall` have a stated, documented meaning.
  Removing them is not an option: `WeightedHealthFormula::termsOf` reads the
  canonical `(m["health.dim"] ?? fallback) * weight` terms off the formula's
  parse tree, returning null rather than a partial read, and
  `HealthFormulaExcluder` refuses anything it cannot read whole — so a formula
  without `??` breaks `--exclude-health`. Round 1 of this stage asked for "or are gone",
  which is unsatisfiable.
- `composer check` green, including the ratchet and baselines this stage moves —
  this stage regenerates what it invalidates rather than leaving it to P7.
- Unit tests: a single-child parent (C1), a subject with a 1-of-500 aggregate
  (C3), and a subject where the dimension legitimately does not apply.

## Files

`src/Analysis/Evidence/ComputedMetrics/ComputedMetricDefaults.php`, whatever
the M1 mechanism turns out to live in (likely `DistanceCollector` or the
namespace-to-project aggregation), the ComputedMetrics unit tests,
`qmx-baseline.json`, `docs/internal/benchmark-baselines.json`,
`measurement/03-*`.
