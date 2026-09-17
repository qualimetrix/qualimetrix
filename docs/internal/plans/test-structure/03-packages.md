# Stage 03 — execution: the plant, the packages, the Definition of Done

Split out of [`03-tooling-tests.md`](03-tooling-tests.md) when that file passed
400 lines. **That file decides; this one executes.** It carries no decision of
its own: the population, the three decisions, the placement and the registration
narrative live there, and every address referred to by number lives in
[`measurement/stage-03/addresses-to-edit.md`](measurement/stage-03/addresses-to-edit.md).

Nothing here restates a count from either. Both review rounds found the same
defect — a number copied into prose and left to go stale — so a number appears
in exactly one place and is cited from the others.

## Definition of Done

- All 16 PHP files and both Python files are out of `tests/`, and their SUTs
  resolve from the new location — **proved by running them**, not by reading the
  config.
- `tests/Unit/PromiseEffect/`, `tests/Unit/RuleVocabulary/` and
  `tests/TestSupport/ArchitectureStaticAnalysis/` no longer exist.
- **Every address in
  [`measurement/stage-03/addresses-to-edit.md`](measurement/stage-03/addresses-to-edit.md)
  is edited or explicitly retired.** That file is the checklist — 31 addresses,
  each graded loud or silent, each assigned. It exists because three review
  findings shared one cause: an address the measurement found and this plan did
  not carry. Do not restate it here; a second copy would drift.
- **The two totals are unchanged: 9198 discovered, 9196 executed**, and the
  per-suite rows are these six integers, compared as a diff rather than read.
  Where the two totals come from, why they differ by exactly two, and the two
  `live-freshness` cases that account for it are in
  [`measurement/stage-03/baseline.md`](measurement/stage-03/baseline.md); the
  per-file arithmetic these six are summed from is in
  [`measurement/stage-03/prediction.md`](measurement/stage-03/prediction.md):

| suite          | expected |
| -------------- | -------- |
| Unit           | 6987     |
| Integration    | 419      |
| Functional     | 203      |
| Infrastructure | 660      |
| Governance     | 748      |
| Tooling        | 179      |

  from `python3 scripts/phpunit-aggregate.py`, and 9198 from
  `composer architecture:check`. Totals matching while a row does not means a
  file landed in a suite the prediction did not send it to — the defect a
  total-only check cannot see.
- **G2 and G3 are shown to bite on the new roots, not merely to be green over
  them.** Green proves nothing here: the guards were green before the roots
  existed. The evidence is the step-1b refusal above, re-run once per new root,
  plus `TestTree::roots()` listing every new root.

  The refusal is not reproducible the same way everywhere, and the difference
  matters. `tools/phpstan` already has a parent PSR-4 root, so a probe under it
  enters `TestTree`'s corpus immediately and G2/G3 refuse with no config change
  — that is the plant as executed. Every `scripts/**` root has no parent root,
  so a probe there is invisible to `TestTree` until address 4 is added. For
  those the order is: declare the PSR-4 root, plant the probe, take the refusal,
  then register the `<directory>`. A package that skips straight to
  registration has not shown the guard covering its root.
- `namespace-path-allow-list.php` has two fewer rows and a `ceiling` lowered to
  match, **by re-deriving with
  `php governance/TestSuiteHygiene/derive-namespace-path-allow-list.php`**, never
  by hand.
- `php scripts/directive-audit-controls.php` — **run directly, not through
  `composer directives:controls`** — reports **exactly the pre-existing state
  measured below, and no more**. It does not "pass": it is red on `main` before
  this stage, for a reason no package here causes.

  **The pre-existing state, measured during P1 because the composer wrapper had
  never let this script run to completion:**
  `120 probes, 1 not as declared, 3 cases guarded by nothing`. The one
  disagreement is probe `unreadable-config-is-not-a-config-error`, which
  **refuses**: its mutation target in `Probes.php` no longer occurs in
  `src/Analysis/Configuration/Pipeline/Stage/ConfigFileStage.php` — the product
  moved in `59f08352` and the declaration did not follow. The control says so
  itself rather than mutating nothing quietly. Its expected-reddening case is
  therefore unguarded, which is the third `guarded by nothing` row; the other
  two are the long-standing `DirectivesCommandTest` pair.

  Isolate one probe with
  `php scripts/directive-audit-controls.php --only=unreadable-config-is-not-a-config-error`
  rather than re-running all 120 to check it.

  **The first draft of this item demanded that the command pass, without a
  baseline for it** — because `composer directives:controls` aborts at the red
  coverage control and had never reached the full run. That is the same defect
  as round 1's unreachable-command finding, one layer deeper: an oracle whose
  baseline was never measured. Re-pointing that mutation is real work and is
  **not** this stage's: it belongs to whoever owns the directive control rig. The composer script is
  `['@directives:controls:coverage', …]`, coverage is **already red on `main`**
  (exit 1, two pre-existing `guarded by nothing` rows, both
  `DirectivesCommandTest`), and Composer halts a chain on failure, so the
  composer form never reaches the full control at all. Its coverage half's
  oracle is the **text**, not the exit code: `0 not as declared`, no
  `stale declaration:` line, and exactly those two pre-existing rows.
- `composer enumeration:renames:check` still reports
  `58 channel, 54 producer, 82 metric-key rows, 113 executed`. A drop here is
  the `surfaces()` address (17) unedited.
- `composer architecture:check` green, `composer check` green.

## The two-step plant — executed, not specified

The scan-scope hole at line 349 is silent, so "it reddens after I widened it"
does not establish that it was blind before. This stage ran the plant on
`main` before writing the packages, against a probe at
`tools/phpstan/tests/OrphanProbeTest.php`, reverted after. Both steps hold, and
running it corrected two things a specified-only version got wrong.

**Step 1 — the probe goes in a directory the current literal does *not* cover,
and the literal is not touched yet.** (The first draft said "under the old scan
scope", which reads as *inside* a covered path and would defeat the probe.)
`composer architecture:check` stayed green at exactly the baseline figures —
`921 artifacts, 120 fixture directories, 725 PHPUnit classes, 9198 expanded
cases`. The probe is invisible to it. **That is the hole, demonstrated.**

**Step 1b — with the root declared, the backstop bites and names its own cure.**
`TestFilesAreExecutedTest::itExecutesEveryTestClassTheTreeDeclares` refused with
"no class from any file in `tools/phpstan/tests` is listed: check that a
`<directory>` entry in phpunit.xml.dist reaches `tools/phpstan/tests` and that
`currentSuite()` … has the matching branch", and
`TestNamespacesFollowTheirPathTest` refused alongside it.

**Step 2 — widen the literal; the generator must refuse on the same probe. The
natural widening is the wrong one.** Widening to `tools` refuses on a
*production* file instead:

```
Unclassified test artifact: tools/phpstan/Rules/BannedStringPathPromotedPropertyRule.php
```

because all of `tools/` drags the tool's own source into a pipeline that cannot
classify it. Narrowed to `tools/phpstan/tests`, it refuses on the probe:

```
Unclassified test artifact: tools/phpstan/tests/OrphanProbeTest.php
```

Two rules follow, and neither was in the first draft: **every scan-scope entry
is a test directory, never a tool root**; and `classifyKind()` and
`classifyOwner()` need a branch for the new paths — "Unclassified test artifact"
*is* their refusal.

**Step 3** — remove the probe, add the `<directory>`, the prefix-table row, the
classifier branches and the `SUITES` entries, and go green. **Step 4** — only
then move real files.

## Work packages

Sequential, not parallel, and the reason is the file sets: every mover touches
`phpunit.xml.dist`, `composer.json`, the inventory generator and the generated
artifacts. Parallel packages sharing those would overwrite each other, and a
package cannot declare its directory ahead of filling it either —
`TestTree::autoloadDevRoots()` refuses a declared root that is not on disk, and
G2 refuses a declared suite whose listing is empty. So each package registers
the directory it fills, in the same commit that fills it.

Address numbers refer to
[`measurement/stage-03/addresses-to-edit.md`](measurement/stage-03/addresses-to-edit.md).

| #   | Package                                | Moves                                           | Addresses it owns                                                                    |
| --- | -------------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------ |
| P0  | Measurement, this plan, both reviews   | —                                               | done, committed before P1                                                            |
| P1  | The seam, proved, plus `tools/phpstan` | rows 8, 9 + 4 fixtures                          | 1-20, 23-25, 28-30, 32, 38                                                           |
| P2  | promise-effect                         | rows 1-3                                        | 1, 4, 6-12, 20, 27, 38                                                               |
| P3  | directive-audit and its controls       | rows 4, 5, 6, 10 + `AuthoredThresholdForms.php` | 1, 4, 6-12, 20, 31, 33-35, 37, 38                                                    |
| P4  | finding-gate                           | row 7                                           | 1, 4, 6-12, 20, 22, 38                                                               |
| P5  | The flat-script tools                  | rows 11-16                                      | 1, 4, 6-12, 20, 26, 36, 38                                                           |
| P6  | The Python tooling tests               | 2 files + `Fixtures/fake_phpunit.py`            | 21, 38, and `test:cross-tool`'s `-s` paths                                           |
| P7  | Documentation                          | —                                               | `CLAUDE.md` corrections; affected READMEs; `CHANGELOG.md`; the five dead prose sites |

Addresses 1, 4, 6-12, 20 and 38 recur in every mover because each is per-root or
per-artifact: a directory to declare, a root to register, a scan-scope entry, a
prefix-table row, four generator branches, a copy-list entry, a surface root,
and a regenerated artifact. That repetition is the shape of the work, not
redundancy in the table.

**Two boundary facts that the first cut got wrong.**

*P1 edits a file that P5 moves, and this is deliberate.*
`createIsolatedProject()` (address 10) lives in
`ModularArchitectureGeneratorRefusalTest.php`, which is row 16 and moves in P5 —
but it copies the roots the tracked configuration declares into a scratch
project, and its own comment says why that matters: "PHPUnit exits 2 when a
`<testsuite>` names a directory that is not there". The moment P1 declares a
`<directory>` under `tools/`, that copy is incomplete and the test fails. So
**P1 owns the copy-list edit and P5 owns only the file's relocation.** The
comment is also a third, unsolicited witness that PHPUnit exits 2 — the
behaviour `CLAUDE.md` denies.

*`tests/Unit/RuleVocabulary/` is emptied by P5, not P3.* It holds five tests;
P3 moves four, and `RenameEnumerationRetirementTest` leaves only with the
flat-script tools. **Between P3 and P5 the directory survives with one file**,
and its `<directory>` entry and prefix-table row must stay valid until P5
removes them — which is why address 21 is P5's, not P3's. The earlier table
claimed P3 emptied it; it cannot.

**What each package leaves uncompensated until the next.** P1 makes `Tooling`
real, so from P1 onward a mover that forgets address 1 or 7 is loud rather than
silent — that is the point of doing it first. P2-P4 each leave the
`surfaces()` count (17) correct only for the roots added so far; the count is
whole again at every package boundary, so no intermediate `main` reads a drop.
P3 leaves `tests/Unit/RuleVocabulary/` alive, as above. P6 is last so it can be
dropped without re-cutting anything.

**Files of this stage's subject that no package owns.** None of the 16, none of
their fixtures, and none of the 31 addresses. Deliberately out of scope, named
so rather than omitted: the entry scripts of the six flat tools (D3-3);
`scripts/input-doors` and `scripts/promise-effect-controls`, which have no test
to move; the five dead prose sites (P7);
`tests/TestSupport/Logging/`, which exists and this stage does not touch; the
pre-existing two `guarded by nothing` rows; the pre-existing dead
`classifyOwner()` prefixes this stage does not create; and the 616 `tests/`
files no witness read.
