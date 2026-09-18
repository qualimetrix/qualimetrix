# Stage 05 — work packages

Subject and rules: [`05-content-defects.md`](05-content-defects.md). This file is
the decomposition only.

Every ledger file is assigned to exactly one package in
[`measurement/stage-05/packages.tsv`](measurement/stage-05/packages.tsv), which
carries the current heir of each path rather than the ledger's recorded path.
**The assignment is by file, not by defect class**: a file often carries rows of
several classes, and splitting by class would put two packages in one file.

**These sizes are P0a's output, not the decomposition's input.** P0a existed to
decide nine files no witness could place, and four of them turned out to belong
to another package. The table below is what `packages.tsv` carries after that
adjudication; `measurement/stage-05/population-adjudication.md` says which file
went where and why.

| Package                            | Files   | Rows    | Blocks           | Depends on       |
| ---------------------------------- | ------: | ------: | ---------------- | ---------------- |
| P0a — population adjudication      | 5       | 5       | everything       | —                |
| P0b — the owner's decisions, taken | 0       | 0       | nothing          | —                |
| P1 — the high rows                 | 23      | 37      | P3 (ruling only) | P0a              |
| P3 — Evidence capabilities         | 58      | 68      | —                | P0a, P1's ruling |
| P4 — Analysis core and `Core`      | 41      | 52      | —                | P0a              |
| P5 — Infrastructure                | 44      | 57      | —                | P0a              |
| P6 — Reporting                     | 16      | 19      | —                | P0a              |
| P7 — Governance and Tooling        | 32      | 38      | —                | P0a              |
| **total**                          | **219** | **276** |                  |                  |

P3, P4 and P6 did not move. P0a kept only the files whose every row it closed
itself; a file with a row still open went to the package owning its decided heir,
which is the same rule that built P3-P7.

There is no P2: the numbering follows the defect classes' priority order, and
`name-lies` folded into P1 because its nine rows share files with the tautologies.

**P3-P7 do not depend on P1 as a whole.** Their file sets are disjoint from it
and from each other. The one real edge is named in P1 below; an earlier draft
wrote a blanket `P3..P7 → P1` that nothing justified and that serialises five
packages for one ruling.

## P0a — population adjudication (blocking)

It took the nine files `population.tsv` marked anything other than `at-path` or
`moved-agreed`, and their ten rows, and decided each against the body of its
heirs. It ends holding five: the files whose every row it closed itself. The
other four carry a row a package still has to work, so they went to the package
owning the decided heir — two to P5, one to P7, and the `tautology` to P1, which
is why **P1 now holds all 24 `high` rows and P0a none**.

No row split, so the 219/276 totals never moved and `counterparts.tsv` was not
re-derived.

- Each ambiguous file's row is attached to the heir that carries the defect,
  decided by reading both heirs. **Where a split puts the defect in both, the row
  splits too** — and `population.tsv`, `counterparts.tsv` and `packages.tsv` are
  re-derived in the same commit, because their totals become wrong the moment a
  row is added. That case did not arise.
- `row_id` is minted into `defect-ledger.tsv`, and the ledger moves with its new
  `verdicts/` directory to the repository root, out of reach of the plan
  directory this stage's own control may not depend on. This is the one change to
  the ledger's column set anyone is allowed to make.
- The 62 `other` rows are adjudicated to a real class or a `wont-fix` reason. No
  row may still be `other` when P0a ends.
- The dangling-name census becomes something `composer check` runs. All ten names
  are adjudicated: the nine pins are confirmed or dropped, and the tenth is
  removed from the tree. **Pinning it is excluded** — the script exits 3 on a
  pinned name that no longer dangles, so a pin is not a way to reach exit 0.
- The comment beside `assertGreaterThan(600, …)` says "sixteen" twice where the
  arithmetic gives fifteen; corrected here.

## P0b — the owner's decisions, taken

All three are settled; what remains is execution, and none of it blocks a package.

**Both generator constants are deleted.** `P6_LIVE_ADDED_TEST_IDS` (6 entries) and
`P6_RENAMED_TEST_IDS` (4) each occur exactly once in the repository — their own
declaration. Nothing reads them, no guard covers them, and one record of the
second is already false on both halves. A list nothing reads cannot be checked by
anything, and this one has started lying.

**The `closure_package` column is deleted.** Nothing decides on it anywhere. Its
only semantic consumer, `validateP4Target()` in
`generate-modular-architecture-production-inventory.php`, **is never called**, and
would fatal if it were: it reads `$manifest['p4_target']`, a key the manifest does
not have. No governance control reads the column or the artifacts carrying it.
The incoherence stage 04 measured is explained by the column being two things
under one name — a manifest field on 955 production declarations, always a package
label, and a separately computed test-side value that can read `permanent`.

Removal touches 955 manifest entries, 11 sites in the production generator, 9 in
the test generator, and drops a column from seven artifacts. **It is its own
package, taken after stage 05**: the scale is mechanical but large, and mixing an
artifact-schema change into a stage about ledger defects buries it.

**The 14 rows promising an unowned relocation are two different problems.**
`disposition` is derived — `dispositionFor()` returns "Move" exactly when
`targetPath()` differs from the current path — so the promise is not dropped by
editing prose but by changing what the generator considers the target.

- **The ten JavaScript artifacts stay where they are, for now.**
  `src/Reporting/Template/` is a self-contained npm project — `package.json`,
  lock, `vite.config.js`, `src/`, `tests/`, `dist/` — and `composer test:js` just
  `cd`s into it. Moving only its tests under `tests/` would split the project in
  half and break vite's discovery, so `targetPath()` is corrected to say they are
  already home. **The whole tree relocates to its own root folder after the
  campaign** — see below.
- **The four fixtures need adjudication, not execution.** `empty_file.php` and
  `invalid_syntax.php` are used from two manifest owners at once
  (`Infrastructure/Ast` and four `Infrastructure/Console` tests), so assigning
  them to Ast makes Console reach into another owner's fixtures. Their proposed
  targets happen to preserve the `Fixtures/Ast/` and `Fixtures/Schema/` path
  suffixes, which is the only reason `.php-cs-fixer.dist.php` and
  `.githooks/pre-commit` — both of which name `Fixtures/Ast/invalid_syntax.php`
  by substring — survive the move. A target that dropped the suffix would break
  both silently.

## Parked until the campaign ends: the front-end tree moves to its own root

Agreed with the owner, and deliberately not part of stage 05.

`src/Reporting/Template/` is an npm project living inside the PSR-4 root. The
argument for moving it is not tidiness: **`.gitattributes` has no `export-ignore`
for it, so all 28 tracked files — JavaScript sources, the eight tests,
`dev.html`, `package-lock.json` — ship to composer consumers**, who need only the
built `dist/`. Moving it to a root folder and export-ignoring it fixes the layout
and the package at once.

Measured cost: **18 files outside the tree name its path**, and several fail
silently — the `.gitignore` negations that un-ignore `package.json` and
`package-lock.json`, the CI workflow, both inventory generators, two `finding-gate`
enumerations, and `governance/RuleDeclaration/RuleIdentifierLiteralGuardTest.php`,
which pins `src/Reporting/Template/dev.html`. One is user-visible:
`HtmlFormatter.php` tells the user to run `cd src/Reporting/Template && npm run build`.

## P1 — the high rows

All 24 `high` rows, across 23 files: 14 tautologies, 9 `name-lies`, and one
`never-runs` that stage 01 is recorded as having fixed and that this package only
re-confirms.

An earlier draft said 23 rows across 22 files, because the fourteenth tautology —
`ChannelRenameMapTest::itAnswersTheSharedCorpusAsDeclared` — sat on a file no
witness could place, and P0a held it. P0a read both heirs, found the tautology
live in the Baseline one, and handed the file here: a tautology whose replacement
is proven by a case in the controls stand does not want repairing in a package
that is not building that stand.

P1's 23 files span **15 manifest owners** — the fifteenth is
`Analysis/Policy/Baseline`, which arrived with that file — so its validation is
closer to a full run than to one subject's tests. It is the stage's critical path
and should be sized by the number of controls-stand cases it must build, not by
its 37 rows.

**The ruling P3 waits on.** The group `itDeliberatelyDoesNotProvideCallableMetrics`
is 14 files: 13 in P3 and the fourteenth
(`IdenticalSubExpressionCollectorTest`) in P1, because it carries a `name-lies`
row. P1 decides whether the repeated statement of intent is legitimate and
**records the ruling in its verdict file against its own row**, in terms P3 can
apply without re-reading the group. That record is the whole of the dependency.

**One defect is split across P1 and P3 by the by-file rule.**
`IdenticalSubExpressionCollectorTest` (P1, `name-lies`, high) and
`IdenticalSubExpressionVisitorTest` (P3, `misplaced`, low) are the two halves of
one misfiling, and the ledger says so in both notes. P1 repairs both files; P3's
row is then closed as `fixed` naming P1's commit. This is the one deliberate
exception to by-file packaging, and it is written down rather than discovered.

Repair shape is fixed by the stage file: the replacement's expectation comes from
somewhere other than the system under test, and **it is proven by a case in the
controls stand**, not by a quotation in a report.

## P3-P7 — the remainder, grouped by manifest owner

Grouped by the owner of the file's current heir, so a package's files share a
subject and its validation is that subject's tests. Sizes are 16-58 files; P3 is
the one likely to need splitting when executed, and it splits by capability.

Each package's own DoD:

- Every row in its slice of `packages.tsv` carries one of the three verdicts in
  `defect-ledger/verdicts/<package>.tsv`, with its commit or its reason.
- Every row was re-confirmed against the body before being worked.
- `composer check:code` plus `composer architecture:check`. The full aggregate is
  the orchestrator's, once before review and once after fixes.
- The package reports what it deleted. **It does not predict the global test
  count** — that is the orchestrator's, made once before and compared once after,
  because a package cannot attribute a global delta to itself while four others
  are running.

## Files this stage touches that are in no package

A file with no owner is what non-overlap checks never catch, so this list is
derived rather than remembered.

**20 counterparts.** The ledger's `counterpart` column names 60 files, **20 of
which never appear in its `file` column** and so belong to no package, while 23
rows depend on them — a `dupe` is resolved by editing a pair. They are enumerated
with their current heirs in
[`measurement/stage-05/counterparts.tsv`](measurement/stage-05/counterparts.tsv);
all 20 resolve to live files (14 `at-path`, 6 moved). **The package owning the
row owns the pair**, including the counterpart's file, and says so in its commit
when the counterpart belongs to another package's subject.

**Shared files every package writes.**

- `defect-ledger/defect-ledger.tsv` — `row_id` only, minted once by P0a.
- `defect-ledger/verdicts/` — append-only, one **file per package** and one line
  per row. A single shared file is what two packages appending at once would
  lose, because an editing tool rewrites a file whole; separate files turn that
  into nothing at all. No package rewrites another's file.
- `phpunit.xml.dist` — **five packages write it.** `category-wrong` is 32 rows
  spread over P3 (5), P4 (6), P5 (13), P6 (7) and P7 (1), and every level change
  is a `<directory>` edit. An earlier draft called the ledger "the one shared file
  the packages cannot avoid"; this is the second, and it is the one that produces
  merge conflicts rather than lost data.
- `scripts/generate-modular-architecture-test-inventory.php` — the pinned path
  literals, plus the two constants P0b deletes.
- `governance/TestSuiteHygiene/subject-path-exceptions.php`,
  `namespace-path-allow-list.php` and their derive scripts — written by deriving,
  never by hand.
- the dangling-name detector and wherever `composer check` comes to run it.

## Test plan

- **Tautologies:** a controls stand with one case per tautology, each planting one
  break, in an isolated clone whose `vendor` is copied rather than symlinked.
  Prose in a package report is not evidence and does not re-run.
- **Duplicates:** both bodies and both SUTs read before either is deleted; the
  audit cleared five suspected pairs on reading, so resemblance is not evidence.
- **`category-wrong`:** the body decides the level, not the directory.
- **`misplaced`:** predict the `already-fixed` share first — 37 of 46 rows sit on
  files stage 04 moved — then compare.
- **Name references:** one **stage-level** sweep for the FQCN of every population
  heir, because 33 heirs are named from elsewhere and for most of them the
  referring file is in another package. `ResidualLimitationsCoverageTest` pins 13
  class-plus-method pairs reached by reflection, so a method rename here breaks a
  file owned by someone else.
- **Ceilings:** four lists, all full, and the gate catches growth but not
  substitution. A package that swaps or retires says so in its commit.
