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

Each is a command that can fail, and each can fail for the reason it exists.

- **C1 — monotonicity.** A subject with exactly one child must not score better
  than that child, on any dimension. Runs on CodeIgniter today and fails; needs
  no corpus, no ranking and no judgement. This is the criterion review found
  missing: it rejects the outcome the work exists to remove.
- **C2 — the top is earned.** No subject scores 100 on a dimension by clearing
  every threshold. Where a perfect score is legitimate and vacuous — full type
  coverage, a namespace with nothing to measure — the dimension declares itself
  not applicable rather than scoring perfect. A count of subjects at exactly 100
  before and after is part of the stage report.
- **C3 — applicability is stated, not implied.** A dimension whose inputs cover a
  negligible fraction of the subject reports that it does not apply. Measured
  against the `.count` keys, which already exist. Replaces the earlier C3, which
  tested how absence was spelled and would have passed CodeIgniter unchanged.
- **C4 — ordering.** No legacy anchor outranks a modern library on
  `health.overall`, against the ranking frozen in
  `measurement/02-apriori-ranking.md` before any coefficient moves. The ranking
  is produced by someone who has not seen the score tables — the author has, so
  the author does not write it.
- **C5 — namespace and class are calibrated, not just project.** The stage
  report states, per level, the median and the interquartile range across the
  corpus before and after, and what change counts as success. Premise 3 is a
  namespace-level defect and no project-level criterion can detect its repair.
- **C6 — the shift is explained.** Every dimension whose formula changed carries
  a before/after table per project per level, and a reason in the ADR.

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
