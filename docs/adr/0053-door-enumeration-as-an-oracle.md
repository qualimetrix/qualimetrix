# 53. Door Enumeration as an Oracle

**Date:** 2026-09-10
**Status:** Accepted

## Context

The previous round could prove completeness for refusals that throw: the set of
`catch` clauses is closed and can be enumerated from the source. Silent
acceptance has no such handle. It never throws, so neither the catch clauses nor
the throw sites can see it, and the class went untreated for exactly that
reason.

This round changed the axis of enumeration. The set of throw sites is open; the
set of **user inputs** is not. Each command's `InputDefinition` plus
`ConfigSchema::ENTRIES` yields it by reflection, and the measurement over that
surface found silent places where the hand-maintained position table listed
fewer.

The property the oracle measures is a door's **binding count**: how many
existing subjects the submitted value pointed at. A zero binding with no signal
is the defect.

## Decision

**The denominator is the set of doors, and the verdict per door is
differential.** Each referential door is probed three ways on a fixture — a
value that matches nothing, a value that certainly matches, and the door left
out altogether — and the classifier compares the three observations.

**There are four outcomes, not two.**

| outcome          | meaning                                                                                      |
| ---------------- | -------------------------------------------------------------------------------------------- |
| `REFUSES`        | the miss ends the run with the refusal exit code                                             |
| `SPEAKS`         | the declared signal fired on the miss, and the hit is distinguishable on the same observable |
| `SILENT`         | the miss produced no signal this oracle can see                                              |
| `NOT OBSERVABLE` | the door cannot be judged on this fixture at all                                             |

The fourth is separate on purpose and must never be folded into `SILENT`. One
door in the measurement (`coupling.frameworkNamespaces`) was observable on
neither the miss nor the hit under the first probe, and the correct move was to
change the **observable** — `--format=metrics` instead of a diff of findings —
not to change the fixture. Folding "cannot see" into "says nothing" would have
turned a limit of the stand into an accusation against the product.

**A verdict about silence counts only when the hit was observed.** Without the
pair the observation is spoiled: a door can look silent because the probe never
made it bind in the first place.

**`SPEAKS` is never inferred from a difference in output text.** The naive
criterion — "the miss output differs from the no-door output, therefore the
product spoke" — is false-green, and it fails in the worse direction: silence
passes as a signal. Measured: the miss `--format-opt=violation=2` produced a
four-line difference, and all four lines were the timestamp. The product also
echoes the submitted value into its own header, which made every miss look like
an answer. So the signal is **declared per door** and checked for specificity
against the same observable the hit is checked on, and what is not known reads
as `SILENT`. Normalization comes from the existing
`finding-gate/normalization.tsv` rather than a second list of the stand's own: a
second normalization diverges from the first silently.

**A door counts as referential until a row gives a reason otherwise**, so an
unlisted door reddens the generator instead of passing as a silent success.

**The frozen half of the before/after pair stores raw observations, not
verdicts**, so editing the classifier recomputes both halves the same way. A
comparison of verdicts recorded under two editions of the criterion is not a
property of the product.

## Claim boundary

Door enumeration is a **denominator and a regression sentinel, not a proof of
completeness**. The mapping "door → matching site" is 1:1 in neither direction:
the single door `--rule-opt` carries several matching sites, of which one was
silent.

The promise is exactly this and no wider:

> Covered are the inputs visible to reflection over `InputDefinition` and
> `ConfigSchema`.

That sentence is the round's promise as written down before any door was
treated, rendered here in the repository's language; the original wording lives
in the claim-boundary section of
`docs/internal/plans/silent-acceptance/00-overview.md`.

Not "the whole of user input is covered". Matching sites that belong to no door
at all — references inside computed-metric formulas, baseline entry keys,
`@qmx-ignore` in sources, the environment — are invisible to this enumeration by
construction, and no amount of care filling in the door table changes that.
Whoever takes those on next starts by inventing a **second** way to enumerate.

Counts of doors and of pairless classes are owned by
`docs/internal/plans/silent-acceptance/01-oracle.md` §1 and
`01-oracle-mechanics.md` §7, and are deliberately not copied here: copies of a
number diverge silently, and the first edition of that section diverged in
exactly that way.

## Rejected alternatives

**Enumerating code paths — throw sites or `catch` clauses.** Proven open by the
previous round, and blind to this class by construction: a silent accept has no
throw site to find.

**Inferring the verdict from output differences alone.** Measured false-green,
as above.

**One outcome for "silent" and "not observable".** Rejected because the two
demand opposite actions: one is a defect in the product, the other a limit of
the stand.

**Running the stand inside `composer check`.** It takes about two minutes, so it
lives outside the aggregate alongside the directive controls; only its
declarations are checked there.

## Consequences

- Adding an input door means adding a row to the door table. An unlisted door is
  a red generator, which is the property that makes the table a sentinel.
- A disagreement with the stand's verdict is settled by reading the declared
  signal and the observable for that door, not by comparing texts.
- The shape the product should give a miss once a door is found is
  [ADR 0052](0052-the-shape-of-a-signal-about-a-miss.md).
- The stand inherits some verdicts from `check` to another command. That
  inheritance is named debt with a measured size, recorded in the round's plan,
  not a property of the oracle to be relied on.
