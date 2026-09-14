# Stage 01 — two fail-closed ledgers, keyed by consumer

> **Revision 3.** Membership was conflated with status, which pushed roughly
> 13 000 LOC of controls outside every budget — a bigger hole than the one the
> revision was closing. Rewritten whole.

## Membership is any consumer; status is which consumer

A file belongs to the rig when it is invoked by **any** consumer — a composer command
whether or not it is in `check`, a git hook, a CI step, or a test — plus everything
that file requires, statically or through one of the six dynamic loaders the
enumeration already names. It is not rig if it is the product: `bin/qmx` subcommands
stay product, because users run them on their own trees. The rig consumer is
`composer directives:audit`, and that is what takes a row.

The previous revision used "reachable from `check`" as the membership predicate. That
is the *standing* predicate. Under it, `gate:controls`, `directives:controls`,
`promise-effect:controls` and `input-doors:controls` — the whole manual half of the
rig — belonged to nothing and were bounded by nothing.

Status is a column, not a gate on membership:

| status     | what makes it so                |
| ---------- | ------------------------------- |
| `standing` | reachable from `composer check` |
| `round`    | named plan plus a review date   |
| `manual`   | named person plus a review date |

A composer command that invokes nothing is itself a breach: an orphan command is how a
consumer row survives the thing it consumed.

**A file nobody invokes but people run by hand is still a member.** It takes a `manual`
row naming the person, and the fail-closed direction needs a safety net the consumer
channels cannot provide: a file under the rig's roots with neither an automatic
consumer nor a `manual` row is a breach. The root list returns here — as a net beneath
the definition, never as the definition. Two such files exist in the tree today
(`enumerate-refusal-fallback.php`, `enumerate-rule-option-keys.php`), which is why this
is not hypothetical.

## Ledger A — consumers

`consumer` · `family` · `status` · `owner` · `expires`

`owner` is the canon line for `standing`, the plan for `round`, a person for `manual`.
`expires` is a **date** for `round` and `manual` (see stage 02), `-` for `standing`.

## Ledger B — files and aggregates

`path` · `unit` (`file`｜`directory`) · `kind` · `owners` (a list) · `weight`

`kind` is `instrument`｜`control`｜`freshness-generator`｜`data`｜`fixture`｜`dev-glue`.

**`weight` has one unit per `unit`:** lines for a code file, **file count** for a
directory aggregate. Bytes would make a regenerated artifact move the budget, which is
the defect the aggregate exists to avoid. Stated here because a weight whose unit is
implied cannot be tested, and every DoD in stage 02 compares weights.

**Every rig file carries a row, tests included.** A test that drives an instrument is a
consumer in Ledger A *and* a file in Ledger B; otherwise weight escapes into `tests/`,
which is the largest tree in the repository and the first place a footprint would hide.

**`dev-glue` is a closed list, not a value anyone may write.** It is enumerated in this
plan — `init-environment.sh`, `check-private-leaks.sh`, `format-md-tables.py`,
`pre-commit-hook.sh` — and adding to it is a budget decision of the same weight as
raising a cap, because it is one word that removes a file from every budget.

Two shapes a scalar schema could not express:

- **Data as an aggregate.** Rig `*.tsv` and `docs/internal/generated/` are counted by
  directory, so regenerating an artifact does not move the ledger and nobody learns to
  switch the check off.
- **Shared ownership.** `owners` is a list because `input-doors-bootstrap.php` already
  has two owners today and stage 05's substrate will have more. A shared file's status
  is the **strongest** of its owners' — standing if any owner stands — and it may be
  retired only when every owner is gone.

`dev-glue` (`init-environment.sh`, the hooks, the leak scan) takes rows but sits in no
budget: it is the development environment, not evidence about the product. It also
does not move in stage 04.

## The walker

The definition changed, so the walk must change with it. `walkRig()` implements the
channels of `enumeration-method.md` — every composer command, hooks, CI, tests, the
literal `require` graph **and** the six dynamic loaders — not a scan of one directory.
A walk rooted in `scripts/` would reproduce the hand-written-root defect under a new
name, and would miss `scripts/directive-narrow-control.php:79-80`, which loads its
family by iterating an array of class names.

```
ledgerRows(): Consumers, Files
walkRig(): Measured
reconcile(rows, measured): list<Breach>
```

## Breaches

1. rig code with no row in Ledger B;
2. a consumer invoking rig code with no row in Ledger A;
3. a row naming a path or consumer that no longer exists;
4. a composer command that invokes nothing;
5. a `standing` row whose canon line is absent;
6. a `round` or `manual` row whose `expires` date has passed;
7. `weight` disagreeing with a fresh measurement — per code file, per directory
   aggregate for data, never per data file.

## What the checker cannot do

- It does not verify a declaration. A file reachable only from `.claude/` or from a
  test that builds its path by concatenation is declared, not detected.
- **It enforces state, not intent.** `main` is squash-merged — measured: eleven
  commits since 2026-09-10, zero merge commits — so no rule about the shape of a
  commit survives the merge. The checker judges the tree. Review judges the change.
  This plan does not pretend otherwise, and stage 02 is built on that division.
- It defends against drift, not against a false row.

## Packages

| package                                  | files                                                          | depends on |
| ---------------------------------------- | -------------------------------------------------------------- | ---------- |
| P1 schemas + machine columns             | ledger files, README                                           | —          |
| P2 the decided columns                   | `kind`, `status`, `expires`, `owners` — an owner-approved list | P1         |
| P3 walker + checker + seven breach tests | checker, tests                                                 | P1         |
| P4 wiring                                | `composer.json`, canon line                                    | P2, P3     |

P1 is mechanical. **P2 is not:** `kind` and `status` are the first budget decisions in
the plan and go to the owner as an explicit list.

P2 proposes that list from a default rule rather than from a blank page, so the owner
reviews disagreements instead of 354 rows:

- `status` follows the consumer — in `check` is `standing`, named by an open plan is
  `round` with that plan's date, otherwise `manual` with a person.
- `kind` follows what the file does to the product: runs it and judges the result is
  `instrument`; plants a breakage to prove an instrument bites is `control`; compares a
  generated artifact with a fresh measurement is `freshness-generator`.
- `dev-glue` is the closed list above and is never assigned by the rule.
- Every row where the default and the author's intent disagree is listed separately,
  with the disagreement stated — that short list is the owner's actual decision, and
  it is the thing review should read.

## Definition of Done

- `composer rig:check` green, a member of `check:artifacts`, with its own row.
- Each of the seven breaches has a test planting exactly it.
- The walker finds the family loaded by `directive-narrow-control.php` — the dynamic
  channel is covered by a test, not by the prose above.
- Every consumer of rig code has a row, `gate:controls` and the three other control
  commands included; the count is **derived by the checker**, and no test asserts a
  number written in this plan or in a planning snapshot.
- Regenerating any data artifact leaves the ledgers untouched — proved by doing it.
- `composer check` green.
