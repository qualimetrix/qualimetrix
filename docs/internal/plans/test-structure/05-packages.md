# Stage 05 — work packages

Subject and rules: [`05-content-defects.md`](05-content-defects.md). This file is
the decomposition only.

Every ledger file is assigned to exactly one package in
[`measurement/stage-05/packages.tsv`](measurement/stage-05/packages.tsv), which
carries the current heir of each path, not the ledger's recorded path. **The
assignment is by file, not by defect class**: a file often carries rows of
several classes, and splitting by class would put two packages in one file.

| Package                                       | Files   | Rows    | Depends on | Parallel with  |
| --------------------------------------------- | ------: | ------: | ---------- | -------------- |
| P0 — population, instruments, owner decisions | 9       | 10      | —          | nothing        |
| P1 — the high rows                            | 22      | 36      | P0         | nothing        |
| P3 — Evidence capabilities                    | 58      | 68      | P0, P1     | P4, P5, P6, P7 |
| P4 — Analysis core and `Core`                 | 41      | 52      | P0, P1     | P3, P5, P6, P7 |
| P5 — Infrastructure                           | 42      | 54      | P0, P1     | P3, P4, P6, P7 |
| P6 — Reporting                                | 16      | 19      | P0, P1     | P3, P4, P5, P7 |
| P7 — Governance and Tooling                   | 31      | 37      | P0, P1     | P3, P4, P5, P6 |
| **total**                                     | **219** | **276** |            |                |

There is no P2: the numbering follows the defect classes' priority order, and
`name-lies` was folded into P1 because its nine rows share files with the
tautologies.

## P0 — population, instruments, owner decisions

Blocking. Everything after it re-derives against what P0 settles, so a P0 that
ships a wrong adjudication ships it into six packages.

Its nine files are the ones `population.tsv` marks anything other than
`at-path` or `moved-agreed`: five with ambiguous heirs, one where the two
witnesses disagree, one unresolved at the 40% rename threshold, two resolved by
the rename witness alone. One of the ten rows is a `tautology`, so P0 also owns
one of the 24 high rows.

Deliverables beyond the nine files:

- Each ambiguous file's row is attached to the heir that carries the defect,
  decided by reading both heirs. Where a split put the defect in both, the row
  is split too, and the ledger gains a row rather than losing one.
- The dangling-name census is adjudicated to ten names and the detector becomes
  something `composer check` runs. Pinning the `NEW` name to silence it is
  excluded by the script's own text.
- The two generator constants and the fourteen ownerless inventory rows are put
  to the owner as three separate questions, not one.
- `measurement/pinned-paths-impact.txt` is re-derived, or deleted and replaced by
  a derivation command named in the DoD. It is stale today on both its numerator
  and its denominator.

**Contracts P0 must not change:** the ledger's column set, and the meaning of
`closure_package`. Both are read by later packages and by the generator.

## P1 — the high rows

23 of the 24 `high` rows (the 24th is P0's), across 22 files: 13 tautologies,
9 `name-lies`, 1 `never-runs` that stage 01 is recorded as having fixed and that
this package only re-confirms.

The repair shape is fixed by the stage file: replace the assertion with one whose
expectation comes from somewhere other than the system under test. **For each
one, the replacement is planted-broken and shown to fail**, and the refusal text
is quoted in the package report. A replacement that cannot be made to fail is
the same defect wearing new words, and nothing else in the pipeline distinguishes
them.

Files carrying a high row often carry medium and low rows too; P1 takes the whole
file, so those rows are resolved here rather than left for a later package to
reopen the file.

## P3-P7 — the remainder, grouped by manifest owner

Grouped by the owner of the file's current heir, so that a package's files share
a subject, its validation is that subject's tests, and no two packages touch one
file. Sizes are 16-58 files; P3 is the one likely to need splitting when it is
executed, and it splits by capability, not by defect class.

Each package's own DoD:

- Every row in its slice of `packages.tsv` is resolved — fixed, `already-fixed`
  with the commit, or won't-fix with a reason written into the ledger.
- Every row was re-confirmed against the body before being worked.
- `composer check:code` plus `composer architecture:check`; the full aggregate is
  the orchestrator's, once before review and once after fixes.
- The package states its executed-test count before and after, and explains any
  difference that is not the deletions it made.

## Files this stage touches that are in no package

The decomposition above covers the ledger. These are touched too and belong to
P0 by elimination; they are named here because a file with no owner is the thing
non-overlap checks never catch:

- `scripts/generate-modular-architecture-test-inventory.php` — the pinned path
  literals, and the two unread constants.
- `governance/TestSuiteHygiene/subject-path-exceptions.php` and its derive
  script — written by deriving, never by hand.
- `docs/internal/plans/test-structure/measurement/stage-04/dangling-test-names.py`
  and wherever `composer check` comes to run it.
- `docs/internal/plans/test-structure/measurement/pinned-paths-impact.txt`.
- `docs/internal/plans/test-structure/measurement/defect-ledger.tsv` — every
  package writes verdicts into it, which is the one shared file the packages
  cannot avoid. **Verdicts are appended per row, never by rewriting the table**,
  so that two packages finishing together do not silently drop each other's
  column.

## Test plan

- **Tautologies:** each replacement shown to fail under a planted break; refusal
  text quoted. This is the only check that separates a repair from a restatement.
- **Duplicates:** both bodies and both SUTs read before either is deleted. The
  audit cleared five suspected pairs on reading, so resemblance is not evidence.
- **`category-wrong`:** the body decides the level, not the directory it sits in.
- **Population:** `composer check` after every package, and the count stated. The
  executed-test count drops legitimately here; the DoD requires the drop be
  predicted first and any difference explained.
- **Ceilings:** all three exception lists are full and the floor allows 15
  retirements. Any package approaching either says so before it commits.
