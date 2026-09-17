# Stage 02 — the 81-path census is provably incomplete

The stage's DoD is "every `repo-control` path is out of `tests/`". That sentence
quantifies over a population, and the population is `controls-verdict.tsv`'s 81
paths — the union of two wave-1 witnesses, re-ruled. `controls-verdict-notes.md`
named this exact risk under "What this method cannot see":

> **Input completeness.** The input itself is the union of two wave-1 witnesses.
> A control both of them missed (say, a test with no file calls and a "unit"
> category) never entered these 81 paths and is not caught by this method at all.

It happened. `tests/` holds 688 test files; 81 were examined and 607 never were.

## Three found, two of them controls

Found while establishing facts for an unrelated fork, not by searching for them:

| file (all `tests/Analysis/Finding/Integration/`) | methods | reading                                                                                                                                                                                                                                                                                                                                           |
| ------------------------------------------------ | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ChannelUniverseCoverageTest`                    | 11      | **repo-control.** "Every declared channel names its producer", proved by two independent enumerations of the channel universe — the assembled container and the rule registry plus the tracked fixture — compared against each other. Its own docblock says a hand-written inventory of channel-identity mechanisms "has been wrong three times". |
| `ChannelLevelDeclarationDriftTest`               | 4       | **repo-control.** Declared levels against levels observed over the `finding-gate/cases/` corpus, with a test-owned fixture as the oracle. The same shape as `ChannelDeclarationFixtureDriftTest` and `ChannelOrderFixtureDriftTest`, both of which the TSV rules `repo-control`.                                                                  |
| `ChannelCoverageTest`                            | 12      | **product-test**, on the discriminating pair: twelve `itDeclaresTheXChannel` methods, each running one real rule against a hand-built context. It reads `excluded.txt`, but per channel, not as a census.                                                                                                                                         |

Both controls belong to `Channel` — the group the plan uses to *prove* its
co-change axis. That proof was computed over a population missing them.

## Why no cheap sweep closes the gap

Four witnesses looked, each finding what the previous one could not see. The
numbers below are the record; the conclusion they support is at the end, and it
is not the one this file first argued.

**Witness A — signatures** (a filesystem walk, a tracked artifact, a pinned
`private const` list, a control-shaped class name). Calibrated before being
believed: over the 40 files the audit ruled `repo-control` plus the two found by
hand, its recall at "one signal or more" is **42/42**. Reduced to the smallest
signal subset holding that recall, it accuses 100 of the 607 unexamined files.
A triage of those 100 returned **12 whole controls and 7 mixed**.

**Witness B — a universal quantifier in the method name**. It fires on 25 of 40
known controls and on 4 of 15 known product tests. Kept as a cross-check, not
used as an oracle.

**Witness C — a reviewer reading code.** During review, Codex named three
controls that witness A scores at **zero** signals: their census is over a
product class's own static table, held in memory, with no path literal, no
pinned list and no control-shaped name. `ComputedMetricDefaultsTest`
quantifies over `ComputedMetricDefaults::getDefaults()` and pins the count at
six.

**Witness D — a signal for that shape**, written after C found it: iteration
over a product class's static table together with a universally quantified
method name. It accuses 16 further files, and a triage of those returned **3
whole controls and 7 mixed**.

## What the numbers say, against what this file first claimed

The first version of this section argued that recall 42/42 made the 100 a
complete work list for that witness. That claim has now been falsified twice —
once by witness C, once by the yield of witness D — and the honest reading is
the opposite one:

**Recall measured on a calibration set says nothing about the shapes that set
does not contain.** The 42 known controls happened to include no table-class
census with an ordinary name, so the instrument built from them was blind to an
entire family, and the blindness was invisible from inside the measurement.

Yields per round: 57 of 81 examined by the audit, 19 of 100, 10 of 16. The hit
rate did not fall, which is the argument against declaring the population
closed. What did change is the character of the marginal case: the fourth
triage reported, unprompted, where the criterion stops cutting cleanly —
`RemediationTimeRegistryTest` copies its expected side from the repository but
asserts an identity true of any map; `ComputedMetricEvaluatorTest` uses the real
defaults table as input while asserting arithmetic on named keys. That boundary,
between the repository as fixture and the repository as subject, is where
further sweeping buys disputes rather than controls.

So the stage stops sweeping here, with **86 files** relocated and **491 never
read by anyone**, and states the population as open rather than closed.

## What this does to the stage

The moves are unaffected: every path relocated is classed correctly, and moving
it was right. The **DoD** is what changes, and it cannot be read as "`tests/`
now holds no control". It reads: *no control that four witnesses found is still
in `tests/`*, with the witnesses, their yields and their measured blind spots
named above.

`check-verdict-conformance.py` checks exactly that sentence and no more. It is
a one-off, not a tracked guard: a guard for "is this file a control" would be a
model of the question standing in for the measurement, which is the defect this
campaign exists to remove.

## What the next reader inherits

- 491 files below every instrument's threshold, read by nobody.
- A measured blind spot in the signature witness, and the shape that revealed
  it — a census over a product class's own static table, in memory.
- One boundary where the criterion stops deciding by itself, named in the
  fourth triage: a repository table used as a test's input versus as the
  subject of its assertion.

The first two are work. The third is a question for whoever sharpens the
criterion, and sweeping again before it is answered will produce disputes.
