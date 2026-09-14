# P2 — the independent ranking

Seventeen projects against roughly five coefficients per formula is enough
freedom to fit any story. The defence is a statement of what the answer should
look like, written before the coefficients move and by someone who has not seen
them.

## Who writes it

Not the author of this plan. By this point the author has measured, tabulated and
committed every current score; a rule that reasons "may not cite a health value"
does not unsee them, and review (c-12) called this out as contamination by
construction.

The ranking is produced by a separate executor given only: the project list, the
raw non-health metrics (`ccn.avg`, `mi.avg`, class and function counts, typed
ratio, raw coupling counts), and the sources. No score table, no earlier measurement
file, no draft of this plan beyond this page.

## What is produced

`measurement/02-apriori-ranking.md`, committed **before** the first edit to
`ComputedMetricDefaults.php` in P4:

- Coarse bands per dimension plus overall — excellent / good / fair / poor /
  critical. Not a total order: "flysystem beats monolog by two points" is not
  defensible; "neither belongs in WordPress's band" is.
- One reason per placement, citing raw metrics, purpose and reputation, or
  inspection.
- An explicit list of projects the author is unsure about. An admitted gap beats
  a band chosen to make later arithmetic work.

## An open question this stage answers

Whether `qmx` stays a member of the calibration corpus. It is the product's own
source, and calibrating the product's formulas against it invites the same
circularity this stage exists to prevent — a threshold that flatters us is
indistinguishable from one that is right. Either it stays with that noted, or it
becomes a watched project that is measured but does not inform thresholds.

## Where it is used

C4 in `00-overview.md`, and nowhere else. Review (x-04) found the ranking was
produced in round 1 and then consumed by nothing, which made it decoration. P4
cannot be accepted without a comparison against this file.

## Definition of Done

- The file exists and is committed, and its commit precedes every commit
  touching `ComputedMetricDefaults.php` for calibration. Checkable with `git log`.
- Every corpus project appears exactly once per dimension.
- No reason line contains a `health.*` value.
- Its author had no access to a score table — stated in the stage report, with
  what they were given.

## Files

`measurement/02-apriori-ranking.md` only.
