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

## The delta prediction for P1 and P3-P7

Recorded after P0a landed and before any other package started, which is the only
window in which it is a prediction. P0a's own effect is already measured and is
not part of it: **+6 in `Governance`, all six the completeness control's methods,
taking the total from 9208 to 9214.** Everything below is measured against 9214.

A package cannot make this prediction, because it cannot attribute a global delta
to itself while four others are running. So the reconciliation rule is the point
of the exercise: **each package reports the methods it deleted and the cases it
added, by name, and the sum of those reports must equal the observed global
delta.** A difference that no package claims is a lost test, not a rounding error.

### What moves the count, and what only looks like it does

Count-neutral by construction, and named here so a change in them is a signal
rather than noise: `category-wrong` (38 open rows) and `misplaced` (52) move files
and directories; `tautology` (14) replaces an assertion in place; `weak-oracle`,
`brittle-pin`, `state-leak` and `undeclared-subject` (22 together) strengthen a
case without removing it. An explicitly skipped case still counts as a case.

**The hazard inside the neutral classes:** a file moved into a directory that
`phpunit.xml.dist` does not enumerate stops being executed, and the count falls
with nothing failing. `Functional` registers only two directories today against
`Unit`'s 26, so a level change upward is the likeliest place for it. A fall this
stage cannot attribute to a named deletion is assumed to be this until proven
otherwise.

### The driver: 107 open `dupe` rows

| Package | Open `dupe` rows | Predicted deletions |
| ------- | ---------------: | ------------------- |
| P3      | 46               | −10 to −18          |
| P4      | 23               | −7 to −12           |
| P5      | 14               | −4 to −8            |
| P7      | 14               | −4 to −8            |
| P1      | 6                | −1 to −4            |
| P6      | 4                | −1 to −3            |
| total   | **107**          | **−27 to −53**      |

Deletions are predicted well below the row count for three measured reasons. The
byte-identical instrument holds 20 groups and 58 methods, so collapsing every
group to one removes at most 38 — the other ~50 `dupe` rows were found by reading
and a row found by reading may resolve by merging rather than deleting. The audit
cleared five suspected pairs on reading, so resemblance already has a measured
false-positive rate here. And **P3's 46 is damped hardest**: 13 of them are the
`itDeliberatelyDoesNotProvideCallableMetrics` group.

**A named sub-prediction, because P1 rules on it and the ruling is falsifiable:**
that group is ruled **legitimate** and deletes nothing. The plan calls it
"plausibly legitimate — one deliberate statement of intent per collector", and the
byte-identical instrument undercounts the conceptual group (14 files, not 13),
which is the shape of an intent restated per collector rather than a copied
assertion. If P1 rules the other way, P3's range moves by about −13 and the
prediction was wrong in a way worth saying out loud.

### The one addition

P1 builds a controls stand for the tautologies. In the shape the repository
already uses (`scripts/directive-audit-controls/`), a stand carries its own test
file under `scripts/<tool>/tests/`, which lands in `Tooling`: **+1 to +8**.

### The number

**9214 → 9178, band 9161 to 9195.** Centre is −40 from deletions and +4 from the
stand. Outside the band is not automatically wrong; it is the point at which the
per-package reports must account for the difference by name before the stage is
called done.

## The four capped lists, predicted before deriving

Deriving rewrites each list from the tree, so the result is a measurement and the
arithmetic below is a prediction against it. It is assembled from what the six
packages reported, each of which named its rows rather than its count.

| List                            | Now | Predicted | Made of                                    |
| ------------------------------- | --: | --------: | ------------------------------------------ |
| `declares_no_coverage` (A)      | 84  | **74**    | P4 −3, P5 −4, P3 −2, P1 −1, P6 ±0 (a swap) |
| `covers_another_owner` (B)      | 19  | **16**    | P4 −3                                      |
| `remainder_is_not_a_prefix` (C) | 4   | **4**     | nobody named a row                         |
| namespace allow-list            | 55  | **44**    | P3 −6, P5 −3, P4 −1, P1 −1                 |

**The prediction's known weakness, named before it is tested.** Deriving recomputes
each list from the whole tree, so it can *add* rows no package asked for: this
stage created new files — P7's three support classes, P1's new governance test and
its tooling root, P5's relocated fixtures — and any of them that declares no
coverage attribute earns a row. The packages reported removals because removals
are what they caused; additions are what the derive discovers. A result above the
predicted count is therefore the expected direction of error, and each added row
has to be read rather than accepted.

**The swap that no machine will ask about.** P6 moved two files up a level, so two
`declares_no_coverage` rows die and two appear with the same reasons — the count
stays 84 through that change alone. The gate is `count($rows) > $ceiling`, so a
substitution passes silently; the plan's rule is that the package which swaps says
so in its commit, and P6 could not, because the list is a file no package may edit.
The obligation therefore lands on the derive commit, which is the only durable
place left for it.

## The test count, reconciled two ways

The prediction was 9214 → 9178, band 9161-9195. It is checked against **two**
independent things, and they answer different questions:

- **the sum of the packages' own reports** — P1 −8, P3 −41 (plus 19 provider rows),
  P4 −8, P5 −6, P6 −3, P7 +7 — which asks whether the delta is attributable;
- **the band** — which asks whether the prediction held.

A run that lands above the band means fewer tests were removed than predicted, and
the reason is already measured: `identical-bodies.txt` overcounts duplicates
wherever the distinguishing input sits in a heredoc (7 groups of 20, 15 methods of
58), in `$this->detector`'s concrete type, or in a `#[DataProvider]` attribute
outside the hashed body. Three packages reached that conclusion independently.

A run that lands *below* the band is the dangerous direction: it means a file
stopped being executed. The first place to look is a `<directory>` that no longer
matches where a file went.
