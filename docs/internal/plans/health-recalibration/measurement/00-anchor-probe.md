# Anchor probe — do legacy codebases give the scale a floor?

Measured 2026-09-14 on the pre-change product (`main` @ 55297007), each project
analysed from a neutral working directory so no `qmx.yaml` leaks in.

| project                      | files | cmplx | cohsn | cplng | typing | maint | ovral | coverage |
| ---------------------------- | ----- | ----- | ----- | ----- | ------ | ----- | ----- | -------- |
| codeigniter 3.1.13 `system/` | 176   | 77.0  | 61.2  | 100.0 | 0.8    | 70.5  | 69.5  | complete |
| wordpress 6.9 `wp-includes/` | 844   | 46.8  | 73.5  | 68.2  | 9.9    | 52.8  | 53.9  | complete |

Raw inputs behind the coupling result:

| project     | classes | functions | cbo.avg | cbo.max | ce.avg | ccn.avg | ccn.max | mi.avg |
| ----------- | ------- | --------- | ------- | ------- | ------ | ------- | ------- | ------ |
| codeigniter | 139     | 165       | 1.69    | 15      | 0.89   | 3.95    | 47      | 73.48  |
| wordpress   | 589     | 3309      | 4.02    | 79      | 2.14   | 5.47    | 808     | 68.69  |

## What this says

Both parse cleanly under PHP 8.4 — `coverage.complete` is true for each, so
they are usable as tracked corpus members rather than skipped runs.

The result is stronger than "the scale is compressed into its top third":

1. **CodeIgniter 3 scores 100 on coupling** — a perfect score, the only one in
   the whole corpus including the anchors. It earns it by having almost no
   class-to-class edges (`cbo.avg` 1.69): the code is procedural, so there is
   nothing for an object-coupling metric to see. The coupling dimension reads
   the *absence of structure* as the *absence of coupling*.
2. **CodeIgniter 3 outranks Symfony DI overall** (69.5 vs 69.1) and sits above
   Composer (62.9). No reviewer would rank it that way. The scale is not merely
   compressed — for procedural legacy it is locally inverted.
3. The dimension that does separate these projects from the library corpus is
   `health.typing` (0.8 and 9.9 against 90+ for the modern corpus) — and it is
   the one dimension absent from `benchmark-baselines.json` entirely.
4. WordPress carries `ccn.max` 808 against a corpus maximum two orders of
   magnitude lower, yet `health.complexity` only falls to 46.8, because the
   `max` term is square-root damped and capped by `clamp`.

## Consequence for the recalibration

Stretching the existing library-only corpus over 0-100 would have encoded the
inversion instead of exposing it. The anchors go into the tracked corpus, and
the coupling formula needs a term that does not reward structurelessness.

phpMyAdmin 5.2.3 was probed too; its sources are under `libraries/` rather than
`src/`, so the probe run refused the path (exit 3) and it is not measured here.
