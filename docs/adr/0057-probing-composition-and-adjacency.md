# 57. Probing Composition, and a Key Beside Its Neighbour

**Date:** 2026-09-12
**Status:** Accepted

## Context

[ADR 0054](0054-an-oracle-for-effect-diverging-from-promise.md) built the
promise-effect stand around two probes: a triple `omitted / value / equivalent`
that asks about the **form of a value**, and a pair probe `onlyA / onlyB / both`
that asks about **adjacency inside one document**.

Neither can ask who won. "Both layers wrote this path; whose value is in the
object" is not expressible by either triple, and the round that owns source
composition needs exactly that. A second gap appeared beside it: axis A asks the
form of one key **alone**, the pair axis asks about adjacency at a canonical
magnitude and **never at `~`** — so "a key written with no value, standing
beside a neighbour" belongs to no axis.

## Decision

**Two new axes, each with its own probe, its own verdicts and its own declared
magnitudes.**

Composition probes `onlyLow / onlyHigh / both` and yields five verdicts:
`COMPOSED_AS_PROMISED`, `MISLAYERED`, `LOST_SIBLING`, `COMPOSITION_REFUSED`,
`FRANKENSTEIN`, plus `NOT OBSERVABLE` when the two sides cannot be told apart.

Four choices carry the weight:

**`LOST_SIBLING` is its own verdict, not a flavour of `MISLAYERED`.** The loss
happens to a slot the higher side never wrote. Folding it into "the wrong side
won" would lose precisely the triples the denominator counted.

**`FRANKENSTEIN` exists because whole-text comparison is blind to element-wise
merging.** A list merged from both sides equals neither, and comparing texts
would file that under "the wrong side won". Observation is per leaf, and leaf
order is normalized — a merged value must not read as a frankenstein for a
reason belonging to the stand.

**Magnitudes are declared in the axis's own file, never in the frozen
`forms.tsv`.** Composition needs two distinguishable values on one path;
`forms.tsv` fixes a single canonical magnitude and is the frozen input of axis
A. Distinguishability is then proved by running the probe, not assumed.

**An effect-asking axis chooses its magnitude against the product's default.**
The canonical `bool` is `true`, which is also the default of nearly every
`*.enabled` key, so writing it is indistinguishable from writing nothing. The
stand writes the canonical value, compares it with the omitted side it already
took, and only reaches for a declared alternative when the two cannot be told
apart. Nothing guesses a default from a key's spelling — that is the heuristic
this programme intends to remove from the product, and a stand may not use it
either.

**The framework keys get a second observation point.** `suppress_paths` and its
siblings are drained by the factory before any options object exists, so a grid
green at `optionsObject` would be silent about the three consumers that read
them. Measured: all four such cells are indistinguishable at the object point
and distinguishable at theirs.

**The adjacency coordinate's population is a product, not a hand-picked list** —
keys whose declared shape accepts `null`, crossed with their neighbours from the
measured pair denominator. A guard requires the two effects the previous round
measured to fall inside it; had either been outside, the population would have
been built wrong, and the answer is to rebuild it rather than to add the case by
hand.

## Consequences

- The stand spans five axes. The list, the snapshot commit and the subset of
  axes that move the exit code are declared in one file rather than written into
  the code four times.
- A stage that must *produce* a grid cannot also require it to be green, so the
  axes under measurement are excluded from the exit code by declaration.
- The floor requires a named verdict **and** the defect bit. The two came apart
  in this round, and a floor comparing labels alone would have gone on reporting
  withdrawn rows as reproduced.
- A refused door ends the observation. Before this, the probe caught a door's
  refusal and went on building an options object from the document alone, so the
  deepest point voted "accepted, no effect" over a value the product had refused
  — 186 cells of axis A said so.
- Two cure packages of the same round were corrected by these probes rather than
  by review: a cure that defaulted where the carrier promised a refusal, and a
  repair that blinded the very probe it fixed.
