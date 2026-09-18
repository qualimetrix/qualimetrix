# Stage 05 — predictions recorded before the packages ran

A prediction written after the measurement is a description. These are committed
before the package that produces the number starts, so a miss is visible.

## Baseline, measured on `476ce1ac` (the branch point)

| Suite          | Tests    |
| -------------- | -------: |
| Unit           | 6705     |
| Integration    | 383      |
| Functional     | 152      |
| Infrastructure | 1029     |
| Tooling        | 181      |
| Governance     | 758      |
| **total**      | **9208** |

Measured with `--exclude-group=benchmark --exclude-group=live-freshness
--list-tests`, which is the aggregate's own population. The discovery artifact
prints 9210: that is 9208 plus the two `live-freshness` cases the aggregate never
runs. Every count in this stage means 9208's population unless it says otherwise.

The delta prediction per package is made after P0a lands and before P1/P3-P7
start, because P0a settles the `other` class and adds controls of its own. It
goes in the section below rather than in any package's report: a package cannot
attribute a global delta to itself while four others are running.

## P0a — the `wont-fix` share of the 62 `other` rows

Recorded before any of the 62 notes were read in full; the orchestrator had seen
eight of them. An adjudicator clearing a queue drifts toward `wont-fix`, and only
a number written beforehand makes that drift visible.

**Predicted: 12-20 rows `wont-fix` (20-32%), centre 16.** The remainder lands
mostly in `misplaced`, `category-wrong` and `name-lies` — the eight sampled notes
are dominated by "the oracle is weaker than the name", "this file mixes two
genres" and "the namespace does not match the path", which are real defects with
a real class, not observations to be dismissed.

An observed share above 32% is not automatically wrong, but it is the shape of a
queue being cleared rather than judged, and P0a is asked to justify it row by row
rather than in aggregate.

## `misplaced` — the share that closes as `already-fixed`

The stage file predicts it and this records it: **37 of 46 rows** sit on files
stage 04 moved, so the prediction is that most of the class closes as
`already-fixed` and that a surviving `misplaced` row is a file stage 04's
invariant *permits* — a question about the invariant, not a misfiling.

## The population floor

Measured on the branch point: `TestSubjectPaths::population()` returns **616**,
so `assertGreaterThan(600, …)` permits 601 and the margin is **15 files, not
sixteen**. The comment beside that assertion says "sixteen" twice and is
corrected by P0a.
