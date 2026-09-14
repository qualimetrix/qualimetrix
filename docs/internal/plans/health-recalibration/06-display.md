# P5 — what the report says a score was made of

After P3 and P4, because the correct content depends on both.

## The defect

`Health/Metadata/HealthDimensionCatalog.php` holds one input list and one
"ideal" string per dimension — one list, not one per level. The report prints
class- and namespace-shaped inputs beneath a project-level score computed from
different metrics.

Two divergences found while enumerating, both predating this work:

- The "ideal" strings are hand transcriptions of formula constants and have
  drifted: MI p5 is thresholded at 55 and advertised as ">= 65"; MI min is
  thresholded at 5 and advertised as ">= 40".
- The JS mirror in `hints.test.js` carries "85+" for MI avg where PHP carries
  "82+". Review (c-15) established this is a stale test fixture rather than a
  live divergence between two shipped catalogs — the fix is to update the
  fixture, not to build a cross-language binding for it.

After P3 and P4 every one of these constants moves, so the drift will be total
rather than partial unless the transcription stops being a transcription.

## The shape of the fix

Derive the advertised threshold from the formula rather than restating it. Where
derivation is impractical, a test that reads both and fails on disagreement is
the minimum — the constants have drifted once already without anything noticing.

Whether a per-level input list is needed depends on what P3 and P4 left: if the
levels end up sharing inputs, one list is correct and the work is only the
constants.

If P3 chose to report applicability, this stage owns how that is rendered in
each format — it is the difference between a number and "not applicable" in
twelve output formats.

## Definition of Done

- No advertised "ideal" contradicts the formula it describes, proven by a test
  that reads both.
- The inputs shown under a score are the inputs that score was computed from, at
  the level it was computed at.
- `composer test:js` green and `dist/report.min.js` rebuilt if the JS changed.

## Files

`src/Analysis/Evidence/ComputedMetrics/Health/Metadata/HealthDimensionCatalog.php`
and its facade, their tests, `src/Reporting/Template/**` including `hints.test.js`
and the rebuilt bundle.
