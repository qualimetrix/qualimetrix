# Health scores: the model, then its calibration — overview

Round 1 of review is dispositioned in `REVIEW.md`. This document is the rewrite,
not a patch: the premise changed under measurement, and the earlier framing
("recalibrate the coefficients") is withdrawn.

## The premise, as measured

Every claim is a run on `main` @ `55297007`, with its artefact in `measurement/`.

1. **A project can score better than its only part.** CodeIgniter's `system/`
   has one namespace, `(global)`. At namespace level it carries
   `coupling.distance` 0.944 — near the worst possible — and scores
   `health.coupling` 76.1, `health.overall` 64.7. At project level, same run,
   the aggregate `coupling.distance.avg` is not published at all, every other
   penalty sits below its threshold, and the score is `health.coupling` **100.0**,
   `health.overall` 69.5. `measurement/00-whole-beats-its-part.md`.
2. **The top of the scale is an identity, not a measurement.** Every formula is
   "100 minus thresholded penalties". A subject clearing all thresholds gets
   exactly 100 because every term evaluates to zero. Flysystem's four namespaces
   all clear all three maintainability thresholds. The `mi.min` term fires in 2
   of 30 namespaces measured, and never in CodeIgniter, whose namespace minimum
   is 22.5 against a threshold of 5. `measurement/00-dead-terms.md`.
3. **At namespace level the scale is close to constant.** The median namespace
   of all fifteen corpus projects scores 78.6 or better on `health.overall`;
   twelve of fifteen medians are above 84. `health.maintainability` has a median
   of exactly 100.0 in five projects. Namespace is the level a user drills into
   to find where the problem is. `measurement/00-namespace-distribution.md`.
4. **The structural aggregate drops the code it should describe.** The project
   aggregate of `coupling.distance` is the unweighted mean over leaf namespaces
   **excluding `(global)`** — a rule that reproduces the published number to six
   decimals on WordPress, this repository and flysystem. Code written entirely
   in the global namespace therefore contributes nothing and is penalised for
   nothing, which is the mechanism behind premise 1. The same aggregate is
   unweighted, so a seven-class namespace counts as much as a 451-class one.
   Separately, no formula reads a `.count`, so no score states what share of its
   subject it covers — `cohesion.tcc` covers 80 of 139 CodeIgniter classes and
   270 of 589 WordPress classes. `measurement/00-applicability.md`.
5. The corpus is fifteen top-decile libraries; `health.overall` spans 62.9 to
   91.6 and never reaches the declared Poor band. All fourteen packages have
   newer releases, four crossing a major.

Findings 1 and 2 are arithmetic, not taste. Neither is reachable by changing a
coefficient: 1 is a property of aggregation, 2 is a property of the functional
form. **The subject of this work is therefore the scoring model, and calibration
is its last stage, not its whole.**

## The criteria

Round 2 of review was given one hypothesis — "find an execution where every
criterion is green and the measured bad outcomes survive" — and found four. The
criteria below are the answer to those four; `REVIEW.md` records which finding
each clause exists for.

Every criterion is one verdict from one command, `scripts/health-calibration.php`,
which exits non-zero when any of them fails. They are **not staged**: the single
command runs in the Definition of Done of *every* stage that touches
`ComputedMetricDefaults.php` or the aggregation, so a criterion satisfied in one
stage cannot be quietly undone by the next.

- **C1 — monotonicity, one-sided.** No parent scores **above** the maximum of
  its children, for any dimension and any parent/child level pair. Fails today
  on CodeIgniter: project `health.coupling` 100.0 against its only namespace's
  76.1, and on 233 further parent/dimension pairs across the corpus.
  "Child" is defined by **containment of symbols**, not by the namespace tree
  the product builds: the tree is what drops `(global)`, and a criterion that
  inherits the defect it is testing for is no criterion.
  The criterion is one-sided because measurement showed the other side is mostly
  legitimate — a parent scoring *below* all its children usually reflects penalty
  terms the parent formula has and the child formula does not (coupling:
  604 below, zero above). Two-sided, roughly five reported violations in six are
  of that explainable kind, which buries the ones that matter.
  `measurement/07-monotonicity-direction.md`.
- **C2 — the top is earned.** Per dimension and level, no subject scores 100
  while any of its penalty inputs lies outside the range its thresholds cover.
  The count of subjects at exactly 100 is reported before and after, per
  dimension and level, and the count is a fact in the stage report rather than
  a target the judged stage sets for itself. A maximum reached because there was
  genuinely nothing to penalise is legitimate and is distinguished from one
  reached because every threshold was out of reach.
- **C3 — coverage is stated and bounded.** For every subject and dimension the
  bench prints the share the score was computed over, by symbol count and, at
  the levels where symbols carry lines, by lines. A score whose coverage falls
  below a declared fraction is damped toward the neutral value rather than
  published at face value, and the coverage travels with the score into the
  report. The fraction and the damping are chosen in P3 and written into this
  file; "negligible" is not a threshold.

  **This is deliberately not "the dimension declares itself not applicable".**
  Round 2 established that per-subject refusal is the one candidate the engine
  cannot express: there is no per-subject non-publication path, the only route
  leads to `health.overall`'s `?? 75`, and per-subject renormalisation needs a
  non-canonical `health.overall` that `WeightedHealthFormula` does not parse and
  `HealthFormulaExcluder` refuses. Round 1 of these criteria demanded exactly
  that and would have forced a change to the evaluator, the repository, twelve
  formatters, the excluder and its tests under the heading of a recalibration.
  Publishing the coverage alongside a damped score is engine-compatible, and a
  first-class "not applicable" state remains available as its own decision with
  its own ADR.

- **C4 — the aggregate describes the whole.** For every dimension with a
  level aggregate, the project number computed by the current rule and the
  number computed over the pooled set of the level's members, weighted by size,
  differ by no more than a declared tolerance. This is the criterion round 2
  found missing: the unweighted mean was listed among the defects and rejected
  by nothing, so `Avifinfo` with seven classes counting as much as a 451-class
  namespace passed every other criterion.
- **C5 — ordering.** No legacy anchor outranks a modern library on
  `health.overall`, against `measurement/02-apriori-ranking.md`, frozen before
  any coefficient moves and written by someone who has not seen the scores.
- **C6 — every level is calibrated.** Median and interquartile range per
  dimension per level, across the corpus, before and after. The target is
  declared in P4's stage report *and* in this file before P4 runs, so the stage
  under judgement does not write its own passing condition.
- **C7 — the shift is explained.** Every dimension whose formula or aggregation
  changed carries a before/after table per project per level, and a reason in
  the ADR.

C1 to C4 are properties of a single run and can fail on today's product; C5 and
C6 need the frozen ranking and the declared targets; C7 is a property of the
writeup.

## Decisions

- **The model changes before the calibration.** Monotonicity and applicability
  are corrections; thresholds and weights are calibration. Tuning first would let
  a coefficient absorb a structural error.
- **Anchors join the tracked corpus** — CodeIgniter 3.1.13 and WordPress 6.9,
  both measured `coverage.complete = true`. A floor on one developer's machine
  guards nothing in CI.
- **Corpus bump and model change are separate commits with a measurement
  between them.** Review's point stands that "a newer release exists" is not
  "users run it": the rule taken is *the latest release of each package that is
  itself a supported stable line*, stated per package in P1.
- **The stage that invalidates an artefact regenerates it.** P3 owns the
  baselines and ratchet entries its own change moves; P6 shrinks to the guards
  no single stage owns.
- **`health.overall` stays a canonical weighted sum.**
  `HealthFormulaExcluder.php:167-186` parses the weights out of the formula text
  to renormalise them for `--exclude-health`, and refuses explicitly when the
  shape does not match. Any model change must keep that shape or change the
  excluder and its tests in the same stage.

## Stage map

| stage                   | subject                                                         | depends on |
| ----------------------- | --------------------------------------------------------------- | ---------- |
| [P0](01-instrument.md)  | the instruments: neutral-cwd collection, raw capture, the bench | —          |
| [P1](02-corpus.md)      | corpus versions and anchors, re-measured and re-baselined       | P0         |
| [P2](03-apriori.md)     | the independent ranking, frozen before any tuning               | P1         |
| [P3](04-model.md)       | monotonicity and applicability — the model corrections          | P0, P1     |
| [P4](05-calibration.md) | thresholds, weights, and the level distributions                | P2, P3     |
| [P5](06-display.md)     | what the report says a score was made of                        | P3, P4     |
| [P6](07-writeup.md)     | ADR, CHANGELOG, website docs, capability README                 | P4         |
| [P7](08-guards.md)      | the guards no earlier stage owns                                | P4, P5     |

P0 first: today the collector measures under this repository's configuration and
produces a digest the bench cannot read, so no stage after it can be trusted or
even executed.

P3 before P4 follows from the premise. P5, P6 and P7 may run in parallel once P4
is accepted.

## Invariants

- Every measurement runs from a neutral working directory. This now includes the
  collector, which did not.
- Every long run is redirected to a file and judged by its explicit exit code.
- `coverage.complete !== true` is a failed measurement, never a low score.
- A number quoted in a stage is re-measured at the start of that stage. Three
  numbers in round 1 came from a recon draft and two were wrong.
