# Stage 03 — retire what is dead; controls live and die with their instrument

## The freeze mechanism is withdrawn

The first revision proposed freezing a control's verdict under a digest of its
instrument. Both reviewers killed it, on three independent grounds, and they are right:

1. The same stage deleted the control's code, so the prescribed answer to a `Stale`
   verdict — "re-run and re-freeze" — was **unexecutable**. The only available action
   would be to edit the digest, and `verify()` cannot tell a re-derived digest from a
   hand-edited one. The false green moved one step; it did not go.
2. The digest covered the instrument's own files, but a control proves an instrument
   bites *on a planted breakage* — so fixtures, the product, and the shared
   `input-doors-bootstrap.php` all belong to the binding. Stage 05's substrate makes a
   per-family digest insufficient **by construction**, for every frozen control at once.
3. Runtime was never the constraint: the whole standing rig measures 48 s. Freezing
   without deleting code saves nothing measurable; freezing with deletion breaks (1).

No amendment survives all three. The mechanism is withdrawn, not repaired.

## What replaces it

A control has no independent lifecycle. **It carries its instrument's `status` and
`expires`.** They stand together, or they are retired together when the instrument's
review date passes without renewal. Promotion of the instrument raises the standing
footprint for both, so it is debited by their combined weight.

This is only expressible because stage 01 revision 3 fixed membership: under the
previous predicate the control commands — `gate:controls`, `directives:controls`,
`promise-effect:controls`, `input-doors:controls` — were outside the ledger entirely
and could not inherit anything.

This is smaller and it is honest: an instrument that still changes keeps its control
running (48 s is affordable); an instrument whose round has closed loses both, by
deletion, with the git history as the record that the proof once existed.

It also dissolves the classification problem the first revision created. That
classification would have rested on commit history the measurement itself declared an
artefact of squash-merging — "few commits" is not evidence of stability for a family
whose whole round is one commit.

## Deletions

From `enumeration-method.md`, after the two false orphans are excluded:

| file                                     | why it goes                                  |
| ---------------------------------------- | -------------------------------------------- |
| `scripts/x8-overlap-sites.php`           | orphan; its name carries a round that closed |
| `scripts/check-review-disposition.sh`    | orphan; named only in a `.gitignore` comment |
| `review-x15/`                            | untracked session debris, 0 tracked files    |
| `scripts/enumerate-refusal-fallback.php` | one-shot; owner decision above               |
| `scripts/enumerate-rule-option-keys.php` | one-shot; owner decision above               |

Two more are one-shot measurements named only in prose —
`scripts/enumerate-refusal-fallback.php` (288 LOC), `scripts/enumerate-rule-option-keys.php`
(401 LOC). Verified again before this decision: neither has an executable consumer;
each is named only in a comment, one of them only inside the other.

**Owner decision, 2026-09-14: both are deleted.** The standing rule it sets: doubtful
value is deleted, not archived behind a `manual` row. A `manual` row is for an
instrument someone will actually run again and is willing to be named for; it is not a
resting place for work nobody wants to throw away.

Not orphans, and explicitly retained: `scripts/benchmark-comparison.sh` and
`scripts/compare-metrics.py`. An earlier reading of the enumeration called them
consumerless; `tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkConsumersCoverageTest.php`
guards both by name. That test is itself the live example of a rig-driving test which
carries weight — the case stage 01 now gives a Ledger B row.

## Edge cases

- Deleting a control must not delete fixtures another family reads.
  `scripts/input-door-controls.php:97` builds a scratch tree containing a
  `finding-gate` directory, so cross-family fixture use exists and is checked before
  any deletion. (Asserted by the plan and to be verified by the implementer, not
  assumed.)
- A deletion leaves ledger rows behind: they go in the same commit, or stage 01
  breach 3 fires. That is the check working.
- `review-control-tooling/` — this review's own directory — is already covered by
  `.gitignore: review-*/`. Verified; it is not a new instance of the `review-x15/`
  class.

## Packages

| package                               | files                                              | depends on    |
| ------------------------------------- | -------------------------------------------------- | ------------- |
| P1 controls inherit status and expiry | Ledger A/B rows, checker rule, tests               | stages 01, 02 |
| P2 delete the orphans                 | three deletions, ledger rows, `.gitignore` comment | stage 01      |
P2 carries all five deletions; the owner's decision on the two one-shots is recorded
above, so there is no separate package waiting on it.

## Definition of Done

- A planted control row whose `status` differs from its instrument's is rejected, by a
  test.
- The three deletions leave `composer check` green and no ledger breach.
- All five deletions land, `composer check` green after each.
- The footprint after this stage is written to the budget file as the new baseline in
  the same commit — a retirement that does not lower the cap has not been paid.
