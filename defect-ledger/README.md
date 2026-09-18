# The test-content defect ledger

| File                        | Holds                                                                    |
| --------------------------- | ------------------------------------------------------------------------ |
| `defect-ledger.tsv`         | 276 defects over 219 test files, measured at `585b7c72` and frozen there |
| `verdicts/<package>.tsv`    | how each row was answered, one file per package working the stage        |
| `reproduce-commit-reach.py` | re-derives whether each verdict's commit touches the row it closes       |

`defect-ledger.tsv` is a measurement, not a worklist: it is not edited, and
neither is a verdict once written. `DefectLedgerVerdictClosureTest` carries a
digest over each, so both are facts rather than conventions — and they are what
makes the commit-existence check it used to run safe to have removed. The one
exception already happened — a `row_id` column was minted so verdicts have a key
to join on, because `file` + `class` + `line` is not one (a row carries no line).

The verdict vocabulary is exactly three values; `verdicts/README.md` has the
schema and the refusals.
`governance/PlanningRecords/DefectLedgerVerdictClosureTest.php` reads both and is
red until every row is answered exactly once.

## Why this is a root folder and not a plan file

It was written under `docs/internal/plans/test-structure/measurement/`, which is
where it was measured. A control cannot read it there:
`PlanningRecordIsolationTest` refuses any executable source naming a concrete
plan path, and that refusal serves a rule the plan index states — a completed
plan is removed once its durable decisions, obligations and **verification
assets have moved to their permanent owners**. A ledger a control reads is such
an asset, so it moved before the control was written rather than after the plan
directory was swept.

The shape is `promise-effect/`'s: a root folder, `export-ignore`d so it does not
reach composer consumers, with `merge=union` on the append-only files.

The rest of the stage-05 measurement — `population.tsv`, `packages.tsv`,
`counterparts.tsv`, `other-adjudication.tsv` — stayed under the plan. Packages
read those; no control does, so nothing outlives the plan by depending on them.

## This folder is not permanent

The ledger is frozen and finite. On the day every row carries a verdict the
control passes for good, and a control that can no longer fail is a monument.
When the campaign's plan directory is retired, this folder and that control are
candidates for retirement with it — not furniture to be worked around.
