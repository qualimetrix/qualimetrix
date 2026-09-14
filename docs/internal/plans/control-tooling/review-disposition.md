# Review disposition

> Round 1 is recorded below as history. **Round 2 superseded parts of it** — read
> the round-2 section at the end before treating any round-1 resolution as current.

Reviewers: `dvizh-vr-review:comprehensive` (native) and `dvizh-vr-review:codex`, one
instance each, same material, both read-only. 26 findings: 10 HIGH, 11 MEDIUM, 3 LOW,
4 self-refuted by Codex. No finding was rejected outright.

Findings were grouped by mechanism before any edit, and stages 01–03 were **rewritten
whole** rather than patched — a plan amended by inserted paragraphs argues with itself.

## Mechanism A — the checker was asked to refuse where it can only surface

`claude-01`, `claude-05`, `claude-06`, `codex-02`, half of `claude-03`.

A cap measured after the fact and raised by the diff that consumes it is a ledger with
a number on it. For a round budget it is circular: the row is created with the round.

**Taken.** Stage 02 is rebuilt around four named growth events. A cap raise may not
share a commit with any other change. Round caps are **authored at approval**, before
the instrument is written. Promotion requires a retirement and may not be satisfied by
a raise in the same commit. The pre-plan reference point is recorded in the overview so
the plan can be judged by its own metric.

## Mechanism B — the rig was a hand-written directory list

`claude-02`, `claude-09`, `claude-10`, `claude-14`, `codex-01`, `codex-03`,
`codex-05`, the other half of `claude-03`.

**Taken.** Membership is now a consumer channel. Two ledgers: consumers and files, with
data counted as directory aggregates and `owners` as a list. `manual` is breached from
any automatic channel, not only composer. Every count is derived at check time; no test
may read a planning snapshot.

**Partly declined, with reason.** `bin/qmx directives` stays product: users run it on
their own trees. The rig consumer is `composer directives:audit`, and that takes the
row. `docs/internal/generated/` enters as a directory aggregate, not per file.

## Mechanism C — freezing

`claude-04`, `claude-11`, `codex-04`.

**Withdrawn entirely,** not amended: deleting the control's code made the prescribed
answer to `Stale` unexecutable; the digest was insufficient by construction once stage
05 lands; and runtime was never the constraint, so the mechanism bought nothing
measured. Controls now inherit their instrument's status and expiry.

## Mechanism D — layout and packaging

`claude-07`, `claude-08`, `claude-12`, `claude-15`, `codex-06`, `codex-07`,
`codex-08`, `codex-09`.

**Taken.** `assurance/` is declared a navigation taxonomy, which forces stage 05's
substrate to be a named family. `dev-glue` does not move. Move and repoint are one
delivery unit. The `scripts/` grep is scoped to executable consumers. The canon cap is
counted where the section lives. Stage 02 is an ancestor of 03–05 in the table. The
two-copies rule is restored in stage 05's DoD.

## Arithmetic

`codex-11` is confirmed and both parties were wrong: the count of undescribed
`promise-effect` / `promise-ledger` / `input-doors` commands is **18**, not the 13 the
material claimed nor the 16 Codex proposed. `MATERIAL.md` now states it by reference to
the artifact rather than from memory. `claude-03`'s related finding — that the planning
snapshot's 23 "standing" commands include 10 that are not rig tools — is confirmed and
fixed by defining a standing rig consumer as reachable from `check` **and** invoking
rig code (13 of them).

## Reviewer usefulness

- **native (`comprehensive`)** — deepest on mechanism. Found the two DoDs satisfiable
  with the goal unmet (`claude-03`, `claude-04`) and the plan exempting its own tooling
  from its own budget (`claude-06`), which nothing else would have caught. Declared its
  own coverage gaps honestly.
- **codex** — strongest on contract coherence, as in previous rounds. `codex-05` (a
  scalar owner column cannot describe the substrate the plan itself introduces two
  stages later) and `codex-08` (the move-set is wider than the declared subject) are
  cross-document inconsistencies native did not reach. Verified its own citations by
  reading the tree rather than trusting the material.

Overlap was heavy on mechanism A, which raised its priority but added no facts.

## Round 2

Required and narrow: stages 01–03 were rewritten wholesale, so they are material the
first round never saw. One hypothesis — does the rewritten budget bound anything the
old one did not?


---

# Round 2 — narrow, one hypothesis

Reviewers: native and Codex again, one instance each. Hypothesis: *does the rewritten
budget bound anything the old one did not?* Answer: **partially, and every mechanical
refusal in revision 2 failed.** 15 findings, 11 HIGH.

## Mechanism E — commit-shape rules have no enforcer here

`claude-16`, `claude-17`, `claude-18`, `codex-02`.

Revision 2's central device — "a cap raise may not share a commit with anything else" —
has no point of enforcement: the checker walks a tree, and `main` is squash-merged
(measured: eleven commits since 2026-09-10, zero merge commits). It was also
self-contradictory, forbidding the same-commit cap changes that three other stages'
DoDs require, and bypassable by two consecutive commits — which the plan itself
permitted.

**Withdrawn entirely.** Replaced by footprint caps measured from tree state. Promotion
then needs no rule of its own, and the debit becomes weight rather than count — which
also answers `claude-21` and `codex-04`: retiring a worthless row no longer buys a
heavy promotion, because the footprint still exceeds the cap.

## Mechanism F — membership was the standing predicate

`claude-19`, `claude-24`, `claude-20`, `codex-05`.

Revision 2 defined rig membership as "reachable from `check`". That is the *standing*
predicate; under it the four control commands and everything they drive — roughly
13 000 LOC — belonged to no ledger and no budget. The revision opened a hole larger
than the one it closed. The walker had also not been updated to the new definition and
would have missed `scripts/directive-narrow-control.php:79-80`, which loads its family
by iterating class names.

**Taken.** Membership is *any* consumer; status is a column. The walker implements the
enumeration channels including the six dynamic loaders, with a test on that channel.

## Mechanism G — expiry depended on somebody's housekeeping

`codex-01`.

Revision 2 tested expiry as "the plan file is gone". Plans here are deleted in
occasional sweeps — PR #70 retired two closed plans weeks after the work ended — so an
instrument whose reason had expired stayed invisible until someone tidied.

**Taken.** `expires` is a date; past it the check is red until renewal or retirement.

## Accepted without a fix, and said so

- `claude-23` / `codex-03`: nothing stops a generous round cap. It is an owner-facing
  expectation, not a bound, and is now labelled as such rather than dressed as a
  mechanism.
- `codex-06`: a shared file's status is the strongest of its owners', and it may be
  retired only when every owner is gone.
- `codex-07`: growth of an existing file is caught by the footprint measure, so the
  "exhaustive list of events" it was missing from no longer exists.
- The two-commit bypass is gone with the rule it bypassed. What remains — a cap raised
  in the same breath as it is consumed — is named in stage 02 as an accepted residual
  whose only compensation is the owner's review.

## Found by the author, not by review

Stage 01's DoD pinned the standing-consumer count at 13. Measured correct today, but
three of those thirteen become `dev-glue` once stage 01 classifies them, so the DoD
would have gone false exactly when the stage worked. Removed; the count is derived.

## Reviewer usefulness, round 2

- **native** — found six of the seven HIGHs, including the two that forced this
  revision (no enforcer for commit-shape rules; membership excluding the controls).
  Measured the tree itself rather than citing the material: 33 958 LOC behind non-`check`
  commands, zero merge commits since 2026-09-10.
- **codex** — no HIGH that native did not also reach, but `codex-01` (expiry depends on
  housekeeping) was uniquely its own and is now a load-bearing part of the design.

Round 3 is narrow and goes to native only: with commit-shape rules gone, does the
footprint-cap-plus-date design have a bypass ordinary work would hit?

---

# Round 3 — narrow, native only

Hypothesis: *does the footprint-cap-plus-date design have a bypass ordinary work would
hit?* Answer: **yes, five — four of which ordinary work hits without intent.** Nine
findings, four HIGH. None required another wholesale rewrite: they are about the
design's parameters, not about a mechanism with no enforcer. That is the signal the
skeleton has converged.

| finding                                                                    | resolution                                                                                                                                                                                                                    |
| -------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `claude-25` cap accumulates slack; promotion spends it with no diff        | **The cap is a ratchet:** measurement above cap *and* cap above measurement are both breaches. The tidier the rig is kept, the cheaper the next silent promotion was — now there is no headroom to spend.                     |
| `claude-26` weight lives only in Ledger B; a rig-driving test carries none | Every rig file takes a Ledger B row, tests included. Otherwise footprint hides in `tests/` — the largest tree in the repository.                                                                                              |
| `claude-27` the unit of a directory aggregate is undefined                 | Fixed as **file count**. Bytes would make a regenerated artifact move the budget, the defect the aggregate exists to prevent.                                                                                                 |
| `claude-28` `dev-glue` is a one-word exemption in a churning column        | `dev-glue` becomes a closed list enumerated in the plan; adding to it is a budget decision of the same weight as a cap raise.                                                                                                 |
| `claude-30` renewal is unbounded                                           | A renewal horizon of one quarter, a renewal count on the row, and a refusal after the second. Unbounded renewal is expiry spelled differently.                                                                                |
| `claude-31` a file only people run by hand is outside every channel        | `manual` rows carry it, and the root list returns as a **safety net beneath** the definition: a file under the roots with neither an automatic consumer nor a `manual` row is a breach. Two such files are in the tree today. |
| `claude-32` the reference point and the caps measure different populations | Stage 01 P1 restates the reference point in consumer-population units before any cap is set.                                                                                                                                  |
| `claude-29` a shared file with one standing owner is standing forever      | Accepted and named: correct but permanent; it returns to `round` when its last standing owner leaves, and the cap bounds it meanwhile.                                                                                        |
| `claude-33` the canon cap discourages making a tool standing               | Accepted. A pressure not to add standing checks is the intended direction, not a defect — recorded so it is a choice rather than a surprise.                                                                                  |

## Where this stops

Three rounds, 50 findings, 25 HIGH. Rounds 1 and 2 each killed the revision's central
mechanism; round 3 killed none. The plan is handed to the owner here rather than
reviewed a fourth time: the remaining uncertainty is about parameters a first execution
will measure better than a reader can.
