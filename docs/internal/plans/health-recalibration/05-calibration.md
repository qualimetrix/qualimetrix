# P4 — the calibration

Only after P3, and only against the ranking frozen in P2.

## The dead zone, which is the reason coefficients alone were never enough

`measurement/00-dead-terms.md`. Each formula is 100 minus thresholded penalties;
every term is identically zero once its input clears its threshold, so a subject
above all thresholds scores exactly 100 by identity. Flysystem's namespaces all
clear all three maintainability thresholds. The `mi.min` term, thresholded at 5,
fires in 2 of 30 namespaces measured and never in CodeIgniter, whose namespace
minimum is 22.5.

Raising multipliers steepens only the region where terms already fire. The
subjects sitting at exactly 100 stay at exactly 100. Threshold placement is the
lever; multipliers are secondary.

## What is calibrated

- **Threshold placement**, per dimension per level, so that the observed range of
  real code lands inside the region where terms are live.
- **Weights in `health.overall`**, which were set against a corpus with no floor.
  Typing carries 0.10 at namespace and project and is the only dimension that
  separates the anchors from the libraries — that is a reason to examine the
  weight, not automatically to raise it: a weight states what matters, not what
  discriminates.
- **Level distributions**, per C5. Premise 3 of the overview is that the median
  namespace of every corpus project scores 78.6 or better; the stage states the
  target distribution per level and reports before and after.

## The constraint, and what it yields to

Round 1 held that every surviving threshold must be defensible from the metric
literature rather than from where a project landed. Review (c-08) showed this
collides with the range criterion: a corpus-derived range target is corpus
fitting by construction.

The resolution: a threshold is defensible from the literature *or* declared in
the ADR as corpus-derived, with the corpus and the date. Both are legitimate;
silently mixing them is not. Where the two conflict, the literature value stays
and the range target yields — `PRODUCT_VISION.md` principle 7 puts faithful
metrics above convenient distributions.

## Definition of Done

- `php scripts/health-calibration.php --verdicts` exits 0 — every criterion
  C1-C7 re-checked, not only the ones this stage set out to move. A criterion
  satisfied by an earlier stage must not be quietly undone here.
- C2, C4, C5 hold, each reported by the bench with the command that produced it.
- Per dimension per level, a before/after distribution table across the corpus.
- Every threshold that moved is listed with its justification and its kind
  (literature or corpus-derived).
- The count of subjects scoring exactly 100, per dimension per level, before and
  after.
- `composer check` and `composer benchmark:check` green, against baselines this
  stage regenerates.

## Files

`src/Analysis/Evidence/ComputedMetrics/ComputedMetricDefaults.php`, the
ComputedMetrics unit tests, `docs/internal/benchmark-baselines.json`,
`qmx-baseline.json`, `measurement/04-*`.

Shares `ComputedMetricDefaults.php` and the baselines with P3 — strictly
sequential, never parallel.
