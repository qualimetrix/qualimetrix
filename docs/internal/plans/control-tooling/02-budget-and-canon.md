# Stage 02 — footprint caps, dated expiry, and the canon cap

> **Revision 3.** Every mechanism in revision 2 was a rule about the shape of a
> commit. `main` is squash-merged — eleven commits since 2026-09-10, zero merge
> commits — so those rules have no point of enforcement. They are gone, not amended.

## What enforces what

- **The checker enforces state.** It measures the tree and compares it with declared
  caps and dates. Everything it refuses, it refuses about the tree as it stands.
- **Review enforces intent.** A cap raise is a diff in a tracked file, visible in the
  pull request whatever the merge strategy. The owner reads it and says yes or no.
- **The owner is the bound.** That was settled before this plan: approval per
  instrument, a second approval for promotion.

Saying this plainly is the point. A check that claims to bound more than it measures is
the false-green class this project has already catalogued at length; the first two
revisions of this stage were exactly that.

## Footprint caps

One cap per status class, measured at check time from Ledger B:

| cap            | covers                                                        |
| -------------- | ------------------------------------------------------------- |
| `standing`     | rows whose status is `standing`, `kind` other than `dev-glue` |
| `round:<plan>` | rows of that round                                            |
| `manual`       | rows owned by a person                                        |
| `canon`        | lines of the canon section, counted where the section lives   |

**The cap is a ratchet, not a ceiling with headroom.** Measured footprint *above* its
cap is a breach, and so is a cap *above* the measurement. There is no slack for a
later growth to consume silently.

This closes the hole the third review round found: revision 3 made lowering the cap on
retirement permitted but not required, so a tidy team accumulated headroom and the next
promotion spent it with no diff at all — the cleaner the rig was kept, the cheaper the
next silent promotion became. With equality enforced, every change of footprint in
either direction is a visible edit to the budget file. It is the same shape as this
project's own `qmx-baseline.json` ratchet.

That single rule does the work the previous revision spread across four commit-shape
mechanisms:

- **Promotion needs no rule of its own.** Moving a row to `standing` raises the
  standing footprint; if the cap did not rise, the check is red. The debit is therefore
  by **weight**, not by count — retiring a worthless row no longer buys a heavy
  promotion, which is what made the previous "retire another row" rule hollow.
- **A retirement that lowers the cap in the same commit is now consistent**, not
  forbidden. Revision 2's isolated-commit rule contradicted three other stages' DoDs.
- **Growth of an existing file** is caught for free: the footprint is measured, not
  itemised by event.

Standing and `manual` caps track the tree by the ratchet above. A `round` cap is the
one exception: it is **authored when the owner approves the instrument**, before it is
written, and is therefore an upper bound rather than an equality until the round ends.

Nothing prevents a generous round cap, and nothing should pretend to: it is an
owner-facing expectation, not a bound. Its value is that a round which blows past its
own stated expectation has to come back and say so.

## Dated expiry

`expires` is a date, not a plan's continued existence on disk. Past that date the
checker is red until someone either renews the row — a diff with a reason, which review
sees — or retires it.

Renewal is bounded: a row carries its renewal count, no single renewal may exceed one
quarter, and the checker refuses a row renewed more than twice without a status
decision. Unbounded renewal is expiry spelled differently, and would have reproduced
exactly the drift the date was introduced to stop.

Revision 2 tested expiry as "the plan file is gone". Plans here are deleted in
occasional sweeps: PR #70 retired two closed plans weeks after the work ended. Until
someone does the sweep, an instrument whose reason has expired stays invisible. A date
makes silence a failure, which is the entire problem this plan exists to solve.

## The canon section

One line per standing rig consumer: **what it asserts, and what to do when it reddens.**
The cap is on lines, counted where the section actually lives — the checker locates the
section, so moving canon into its own file does not silently uncap it.

Seeding closes a live defect: all 18 `promise-effect` / `promise-ledger` /
`input-doors` commands lack a `scripts-descriptions` entry, and three of them sit
inside the mandatory `composer check` while being named zero times in `AGENTS.md`.

## The accepted residual, named

Two consecutive commits, or one commit that raises a cap while consuming it, are
indistinguishable to a checker that reads a tree. **This is not closed and is not
claimed to be.** The compensation is that the cap lives in a tracked file whose diff
appears in the pull request, and that the owner approves each instrument anyway. If
that human step lapses, this plan bounds nothing — and it is better for the plan to say
so than to carry a mechanism that looks like a bound and is not.

## Packages

| package                                       | files                              | depends on  |
| --------------------------------------------- | ---------------------------------- | ----------- |
| P1 budget file, measured baselines, cap check | budget file, checker extension     | stage 01 P3 |
| P2 dated expiry and renewal                   | checker extension, tests           | P1          |
| P3 canon section + locator                    | `AGENTS.md`, checker extension     | P1          |
| P4 written policy                             | canon paragraph, `docs/adr/` entry | P1–P3       |

## Definition of Done

- A planted promotion without a cap raise is red, by a test — and a planted promotion
  that retires a lighter row is **also** red, by a test.
- A planted row whose `expires` date has passed is red, and renewing it clears the
  breach, by a test.
- A planted growth of an existing file past the cap is red, by a test.
- A planted retirement that lowers the measurement without lowering the cap is red, by
  a test — the ratchet is proved in the direction nobody thinks to check.
- A planted third renewal of one row is red, by a test.
- Every standing rig consumer has exactly one canon line; the set is derived by the
  stage-01 checker at check time. Moving the section to another file keeps the cap in
  force — proved by moving it in a fixture.
- An ADR records that the checker enforces state and review enforces intent, why
  commit-shape rules were withdrawn (squash-merge, measured), and what would bring
  them back.
- `composer check` green.
