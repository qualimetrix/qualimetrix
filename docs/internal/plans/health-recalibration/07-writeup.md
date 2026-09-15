# P6 — the writeup

`PRODUCT_VISION.md` principle 7 makes this a release event: the corpus is
re-measured and the shift is explained. Runs in parallel with P5 and P7.

## ADR

One ADR. The decisions it must hold, none recoverable from the diff:

- Monotonicity as a property of the scoring model, with the CodeIgniter
  measurement that exposed it — the ADR is where "the project outscored its only
  namespace" survives once it no longer does.
- How applicability is expressed after P3, and what the product publishes when a
  dimension does not apply. This is the decision with the widest blast radius:
  it changes output, not just numbers.
- Why the corpus gained legacy anchors.
- Every threshold kept as corpus-derived rather than literature-derived, named
  as such per P4's rule.
- The `?? 75` fallbacks: what they meant, and what replaced them.

## CHANGELOG

A `Changed` entry from the user's side: health scores move, with direction per
dimension, and an existing baseline may need regenerating.

**Breaking** is judged per level, not only at project level (review x-09): a
class- or namespace-level health finding crossing a warning or error threshold
changes a consumer's exit code exactly as a project-level one does. The stage
measures how many subjects cross a band at each level and writes `Breaking` if
any do.

If applicability changes what is published, that is breaking regardless of
magnitude — a consumer parsing `health.coupling` as a number meets something
else.

## Website documentation

`website/docs/reference/health-scores.md` and `.ru.md` together:

- the threshold table and the `health.overall` weights;
- the per-dimension prose;
- **line 93's range claim, false already today** ("~95 to ~48" against a measured
  70.6-100) — restated as the post-change measured range;
- the band labels, if the model change alters what a band means;
- applicability, if the product now publishes it.

`website/docs/reference/default-thresholds.md` (+ RU) if any default moved.
`src/Analysis/Evidence/ComputedMetrics/README.md` per the repository rule.

## Definition of Done

- `composer check:docs` green.
- No number in `health-scores.md` or its RU twin disagrees with
  `ComputedMetricDefaults.php`; the checked pairs are listed in the stage report.
- EN and RU in the same commit.

## Files

`docs/adr/NNNN-*.md`, `docs/adr/README.md`, `CHANGELOG.md`,
`website/docs/reference/health-scores.md` + `.ru.md`, `default-thresholds.md` +
`.ru.md` if touched, `src/Analysis/Evidence/ComputedMetrics/README.md`.
