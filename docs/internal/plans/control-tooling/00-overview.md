# Control tooling: give the rig an owner, a ledger and a budget

> **Revision 3.** Two extended review rounds, 41 findings, 21 of them HIGH. Stages
> 01–02 have now been rewritten twice, wholesale each time; the disposition is
> `review-disposition.md`. Two mechanisms were **withdrawn rather than amended**: the
> freeze of stage 03, and every rule about the shape of a commit in stage 02 — `main`
> is squash-merged, so such rules have no point of enforcement.

## The problem, in one sentence

The control rig is 47 947 LOC against a 108 461 LOC product, grew by four families in
three weeks, has no index, and has already produced a duplicate — so the thing missing
is not a rule about the next tool, it is an owner and a bounded budget for the rig as
a class.

Measurement: `MATERIAL.md`. Enumeration and its blind spots: `enumeration-method.md`,
`enumeration-tools.tsv`, `enumeration-commands.tsv`.

## Why an entry filter is not the answer

A per-tool test is applied to each tool independently and therefore cannot bound the
sum: every tool can pass and the total still grows. Each size-based bound is evaded by
an ordinary, well-intentioned change — a ratchet on `scripts/` by moving code into
`tests/` (twice the size of `src/`) or into generated data; one-in-one-out by merging
two commands; a `scripts/ ≤ X% of src/` cap by growing the product.

## What the two review rounds changed

Round 1 killed the idea that a checker can *refuse* growth: a cap measured after the
fact and raised by the diff that consumes it is a ledger with a number on it.

Round 2 killed the replacement. Revision 2 made every growth event a rule about the
shape of a commit — and `main` is squash-merged (measured: eleven commits since
2026-09-10, zero merge commits), so no such rule has a point of enforcement. It also
found that revision 2's membership predicate pushed roughly 13 000 LOC of controls
outside every budget, a larger hole than the one it closed.

What survives is a division of labour, stated plainly instead of disguised as a
mechanism:

- **the checker enforces state** — the tree against declared caps and dates;
- **review enforces intent** — a cap raise is a diff in a pull request, whatever the
  merge strategy;
- **the owner is the bound** — approval per instrument, a second approval for promotion.

A check that claims to bound more than it measures is the false-green class this
project has catalogued at length. Both earlier revisions of stage 02 were that.

## The decision

1. **A fail-closed ledger keyed by consumer, not by directory.** Membership is
   "invoked by **any** consumer — a composer command in `check` or outside it, a hook,
   a CI step, a test — plus what it requires". A hand-written root list is itself a
   file-in-no-set; and restricting membership to consumers inside `check` exempts the
   entire manual half of the rig, which is how revision 2 lost the controls.
2. **Two ledgers.** Consumers (what a canon line and standing status attach to) and
   files (rig code per file; rig data and `docs/internal/generated/` as directory
   aggregates, because per-file `loc` on 232+ regenerated files makes the ledger a
   treadmill that teaches people to disable it).
3. **Dated expiry, and promotion debited by weight.** A round row carries a review
   **date**, not a dependence on someone deleting its plan; past it the check is red
   until the row is renewed or retired. Promotion needs no rule of its own: moving a
   row to `standing` raises the measured standing footprint, and an unchanged cap is a
   breach — so the debit is by weight, and retiring a worthless row buys nothing. A
   control carries its instrument's status and expiry.
4. **The owner approves each new instrument;** promotion to standing is a second,
   separate approval. Settled by the owner; not reopened here.
5. **Attention is budgeted in canon lines,** counted where the section actually lives.

## The reference point this plan is judged against

Recorded before the first line of it is executed, because a plan that measures its
baseline after its own tooling is in the tree has exempted itself:

```
pre-plan: 47 947 LOC rig code · 122 code files · 232 data files
          57 commands · 12.9 MB generated
```

This is measured over `scripts/` plus the four data roots — the old definition. The
caps of stage 02 measure the *consumer population*, which is a different set: it adds
rig-driving tests and excludes `dev-glue`. **Stage 01 P1 restates this reference point
in the new units before any cap is set,** or the plan's final comparison compares two
incomparable numbers and can report neither success nor failure.

## What is deliberately not asserted

That 44% is "too much". The rig has paid for itself where it was measured — the
promise-effect family alone landed ~3 500 lines of product cure across PR #63/#64/#65.
The claim is about unbounded growth without an owner.

## Stages

| stage                    | subject                                                        | depends on |
| ------------------------ | -------------------------------------------------------------- | ---------- |
| `01-ledger.md`           | the two fail-closed ledgers, their walker and checker          | —          |
| `02-budget-and-canon.md` | footprint caps, dated expiry, the canon cap                    | 01         |
| `03-retirement.md`       | orphans deleted; controls inherit their instrument's lifecycle | 01, 02     |
| `04-relocation.md`       | one home for the rig, code beside its data                     | 01, 02, 03 |
| `05-substrate.md`        | consolidate the duplicated walkers                             | 02, 04     |

02 is an ancestor of every later stage: each writes to the budget file, so each
depends on it existing. The first revision's table omitted this and the DoDs
contradicted it.

05 last and separately: a substrate lowers the marginal cost of the next stand, so it
must not land while the budget is still being argued.

## Cross-cutting Definition of Done

- `composer check` green before and after each stage.
- Every number a stage asserts is read from a **generated** artifact at check time.
  No stage's test may compare against `enumeration-*.tsv`: those are planning
  snapshots with no generator, and a stale snapshot makes an oracle that passes while
  the goal is unmet.
- The final measured rig footprint is compared with the pre-plan reference point above,
  and the difference is stated — whichever way it went.
