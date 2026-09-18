# Stage 04, package P0 — the owner derivation becomes a path parse

Executed against `main` @ `46c3deca`. That commit's
`test-ownership.tsv` is byte-identical to `e15c7f42`'s, the commit the plan
names, so the oracle's baseline is the same artifact under either reference
(`git show e15c7f42:… | diff -q - /tmp/p0-baseline.tsv` → no difference).

No test file moved. No PHPUnit test case was added or removed.

## What changed, and why

`scripts/generate-modular-architecture-test-inventory.php`:

- **A path parse replaces the prefix ladder for `tests/**/*Test.php`.** Three
  new leaf helpers carry it: `isTestClassPath()` (the population, stated as a
  path predicate because `classifyOwner()` runs before `classifyKind()`),
  `parseOwnerFromTestPath()` (the segments before the first level segment) and
  `parsesToManifestOwner()`. The 37 owners come from
  `docs/internal/modular-architecture-manifest.json` through
  `manifestOwnerPaths()`, which spells them the way the tree does and maps the
  one owner whose name is not a namespace, `Core.Neutral`, to `tests/Core`.
- **`LEGACY_UNMOVED`** — 112 rows, `current path => [owner, target]` — is the
  transitional allowance, and `assertLegacyUnmovedShrinksOnly()` is its guard.
- **`classifyOwner()`, `dispositionFor()` and `targetPath()` all read the same
  four-step order**: tooling root → allowance → parse → surviving ladder. The
  ladder is untouched for the complement, which is what keeps the oracle's
  "every other row unchanged" assertion green.
- **`failUnownedTestClass()` returns `never`**, so `classifyOwner()` narrows
  `?string` to `string` after calling it and needs no cast. The first draft used
  `: void` with a `@return never` docblock and a `(string)` cast at the call
  site; AGENTS.md forbids a cast added to silence the analyser, and the file's
  own `fail()` already spells the return type properly.
- **`validateP4Topology()` is deleted**, with its call. Under the parse nothing
  produces `closure_package === 'P4'` any more, so two of its three assertions
  would iterate an empty set and pass vacuously, and the third pins a path P1
  moves.

`scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`
— two edits, both because the existing control broke, neither adding or
removing a case. See "Test-file edits" below.

`docs/internal/generated/modular-architecture/test-ownership.tsv` — regenerated.
It is the only one of the seven generated artifacts that moved.

## Decisions

Each names the alternative rejected and the command that checks it.

**D1. The ladder survives; it is not pruned.** After P0, 86 of the 104 path
literals inside `classifyOwner()`'s ladder match no worktree path that still
reaches the ladder (154 rows do: 921 − 151 tooling-root − 112 allowance − 504
conforming test classes). The alternative — deleting them now — was rejected
because the plan scopes the complement out in as many words ("its owner, target
and disposition come from the surviving ladder unchanged"), and because a
literal in that function is a claim about an arbitrary *input* path, including
one handed to `--classification-probe=`, not about the tree. Recorded as a
finding below rather than acted on. Checked by: the oracle, which asserts all
four columns unchanged for every row outside the population.

**D2. Three literals are removed, not kept.** `dispositionFor()` and
`targetPath()` each lose `^tests/Infrastructure/(Unit|Integration)/` and
`tests/Unit/Reporting/Health/`; `targetPath()` also loses the
`SourceControlsTest.php` rename special case. Measured first: the two prefixes
match 19 worktree files and every one of them is a `*Test.php`, so the parse
subsumes them exactly; the rename's source file is not on disk at all and its
path now reaches the parse before the ladder, which makes the literal
unreachable as well as dead. The alternative — keeping them — leaves three
branches that can never be taken. Checked by:
`git ls-files tests | grep -E '^tests/(Infrastructure/(Unit|Integration)|Unit/Reporting/Health)/'`
(19 files, all `Test.php`) and the oracle.

**D3. The allowance guard has three arms, not the plan's four.** The plan lists
"every key's recorded target differs from the key" as a separate check. Written
out, PHPStan level 8 refuses it as `identical.alwaysFalse` — and PHPStan is
right for a reason that outlives the current constant contents: a key equal to
its target either conforms, and arm 2 names it, or it does not, and arm 3 names
its target for not being at a manifest owner. The implication is written into
the function's docblock. The alternative — keeping a statically false
comparison, or routing the constant through an accessor purely to blind the
analyser — was rejected as dead code and as a hack respectively. Checked by:
`composer phpstan` (exit 0), and by the docblock's argument being reproducible
by hand.

**D4. `LEGACY_UNMOVED` is generated from `relocation-map.csv` by script**, never
typed: filter the map's `*Test.php` rows, convert the owner to slash spelling
(`Core.Neutral` → `Core`), sort by key. A typo in an owner would have surfaced
as ~100 oracle disagreements to be chased one at a time. Checked by: the
oracle, whose expected owner and target for an allowance row come from the map
itself.

**D5. The parse precedes the Baseline digest branch in `classifyOwner()`.** That
branch refuses a `tests/Analysis/Policy/Baseline/` artifact absent from
`P6_C_BASELINE_PATHS_SHA256`; a test class under Baseline now reaches the parse
first and skips it. Nothing is lost: the digest itself is verified at the top of
the script, before any classification, and it covers *every* file under that
root. Checked by: planting a new file under `tests/Analysis/Policy/Baseline/`
would fail the digest first — the digest assertion runs at line 358 of the
pre-P0 file and is unmoved.

## Assumptions

- **A1.** The manifest's `owners` array is the authority for "the 37", and the
  only owner whose tree spelling differs from its manifest spelling is
  `Core.Neutral`. Verified: the array has 37 entries. Verified separately, and
  the reason the assumption is stated this narrowly: the parse validates *paths
  against owners*, never owners against paths, so an owner with no tests is
  legal and one exists — `Core.Profiler` has neither a `tests/Core/Profiler`
  directory nor a relocation-map target beneath it, and that is not a defect
  here. The 36 others each have one or the other.
- **A2.** The population `tests/**/*Test.php` and the set of `tests/` rows with
  `kind === 'phpunit-test-class'` coincide at 616 rows. Re-measured on this
  tree, not taken from the plan: `git ls-files tests | grep -c 'Test\.php$'` →
  616, of which 112 are in the allowance and 504 parse to a manifest owner, and
  **zero** are neither. This is the enumeration the plan rests on, and it holds.
- **A3.** The plant demonstrations are evidence about the guard, not about the
  tree, so a plant is reverted from a pristine copy of the P0 file kept outside
  git — not with `git checkout`, which restores the *pre-P0* file and silently
  turns every later plant into a no-op. This bit once during this package and is
  recorded because it would bite anyone repeating the procedure.

## Deviations from the plan text

**V1 (D3 above).** Three guard arms instead of four; the fourth is implied and
statically false.

**V2. The probe plant's literal.** The plant table's last row reads
`--classification-probe=tests/Reporting/Formatter/Unit/X.php`. Taken verbatim,
that path is **outside the population this package's own rule names**: `X.php`
is not a test class, so the parse never sees it and the surviving ladder answers
`Reporting  P8  none  tests/Reporting/none/X.php`. Widening the population to
every `.php` under a level directory was considered and rejected — it would
refuse legitimate support files such as
`tests/Unit/Reporting/Formatter/Sarif/Support/StubChannelPresentation.php`,
which parses to an empty owner, and the plan excludes support and fixture files
from the invariant deliberately. The row's substance — "a guard that accepts
`{owner}/Formatter/Unit` has not understood the rule" — is demonstrated with
`XTest.php`, the same shape inside the population. Both runs are quoted below,
so the reader can see exactly what the verbatim literal does.

**V3. No PHPUnit case was added**, per the brief; the plants are demonstrations.

## Test-file edits

Both are inside the allowed path set and both are repairs, not new coverage.

1. `createIsolatedProject()` now copies
   `docs/internal/modular-architecture-manifest.json`. The generator reads the
   manifest, and the fixture copied only `docs/internal/generated/…`; without
   this both isolated controls would die on a missing file rather than on their
   own subject.
2. `itFailsWhenAPhpunitTestClassHasNoConfiguredSuite()` plants its probe at
   `tests/Reporting/Functional/GuardProbeTest.php` instead of
   `tests/Reporting/Formatter/Suppressed/UnwiredLevelProbe/GuardProbeTest.php`.
   The old path has no level segment, so the owner parse now refuses it before
   `validateInventory()` is reached and the test's own assertion string never
   appears. The new path has a real owner and a real level and no
   `<directory>` declaring it, which is the shape the control is about; the
   test still asserts `classified as suite "none"` and still names its file.
   The suppressed-formatter history in the docblock is untouched and still true.

## The six planted refusals, quoted

Every one reverted afterwards; the generator file was byte-identical to the
pristine P0 copy after each (`diff -q` → no difference), and `git status
--porcelain tests` was empty after plant 4.

**1 — a row naming a file that is not on disk.** Added
`'tests/Analysis/Run/Unit/NeverExistedTest.php' => ['Analysis/Run', 'tests/Analysis/Run/Unit/Pipeline/NeverExistedTest.php']`.

```
The stage-04 allowance disagrees with the tree:
  tests/Analysis/Run/Unit/NeverExistedTest.php is allowed but absent from the worktree.
```
exit 1

**2 — a row for a file that is on disk and already conforms.** Added
`'tests/Analysis/Run/Unit/Pipeline/AnalysisResultTest.php' => ['Analysis/Run', 'tests/Analysis/Run/Unit/AnalysisResultTest.php']`.

```
The stage-04 allowance disagrees with the tree:
  tests/Analysis/Run/Unit/Pipeline/AnalysisResultTest.php already conforms, so its row describes nothing; the allowance may only shrink.
```
exit 1

**3 — a row deleted without moving its file.** Removed the
`tests/Functional/Console/Command/HookInstallCommandTest.php` row.

```
tests/Functional/Console/Command/HookInstallCommandTest.php has no segment before its level segment, so it declares no owner. A test class lives at tests/{manifest owner}/{Unit|Integration|Functional}/...
```
exit 1

This is the parse refusing, not the allowance guard — the file is on disk, in no
allowance, and does not conform, which is exactly the plan's expected refusal.
The message names the reason a bucket path has no owner: the level segment is
the first segment, so nothing precedes it.

**4 — the file moved to its target, its row left in place.**
`tests/Functional/Console/Command/HookInstallCommandTest.php` moved to its
recorded target and moved back afterwards. The file is named by no guarded
constant, so `assertPathLiteralsResolve()` does not refuse first.

```
The stage-04 allowance disagrees with the tree:
  tests/Functional/Console/Command/HookInstallCommandTest.php has already moved to tests/Infrastructure/Console/Functional/Command/HookInstallCommandTest.php, so its row no longer describes anything unmoved.
```
exit 1

The guard distinguishes this from plant 1 by whether the recorded target exists,
which is what gives the plan's two distinct expected messages two distinct
refusals rather than one message twice.

**5 — a row whose recorded target is not at a manifest owner.** The same row's
target changed to `tests/Reporting/Formatter/Unit/X.php`.

```
The stage-04 allowance disagrees with the tree:
  tests/Functional/Console/Command/HookInstallCommandTest.php records target tests/Reporting/Formatter/Unit/X.php, which is not at a manifest owner with its level segment immediately below it.
```
exit 1

**6 — the classification probe.** Both forms, per deviation V2.

`--classification-probe=tests/Reporting/Formatter/Unit/X.php`, the table's
literal — **not refused**, because it is not a test class:

```
Reporting	P8	none	tests/Reporting/none/X.php
```
exit 0

`--classification-probe=tests/Reporting/Formatter/Unit/XTest.php`, the same
shape inside the population:

```
tests/Reporting/Formatter/Unit/XTest.php parses to owner "Reporting/Formatter", which is not one of the 37 manifest owners: the segment "Formatter" leaves the manifest. Move the file under its manifest owner, or record the move in LEGACY_UNMOVED.
```
exit 1

Two further probes, recorded because they exercise the other two refusal
messages and neither is reachable from the table above:

```
$ php scripts/generate-modular-architecture-test-inventory.php --classification-probe=tests/Infrastructure/Unit/NewProbeTest.php
tests/Infrastructure/Unit/NewProbeTest.php parses to owner "Infrastructure", which is not one of the 37 manifest owners: "Infrastructure" is a taxonomy above its owners, not an owner. Move the file under its manifest owner, or record the move in LEGACY_UNMOVED.
exit 1

$ php scripts/generate-modular-architecture-test-inventory.php --classification-probe=tests/Reporting/Formatter/Suppressed/UnwiredLevelProbe/GuardProbeTest.php
tests/Reporting/Formatter/Suppressed/UnwiredLevelProbe/GuardProbeTest.php names no test level, so it declares no owner. A test class lives at tests/{manifest owner}/{Unit|Integration|Functional}/...
exit 1
```

## The three positive probes, quoted

Columns are `owner  closure_package  current_suite  target_path`.

```
$ php scripts/generate-modular-architecture-test-inventory.php --classification-probe=tests/Core/Unit/VersionTest.php
Core	permanent	none	tests/Core/Unit/VersionTest.php

$ php scripts/generate-modular-architecture-test-inventory.php --classification-probe=tests/Reporting/Unit/Formatter/Html/HtmlFormatterTest.php
Reporting	permanent	Unit	tests/Reporting/Unit/Formatter/Html/HtmlFormatterTest.php

$ php scripts/generate-modular-architecture-test-inventory.php --classification-probe=tests/Infrastructure/Git/Unit/GitClientTest.php
Infrastructure/Git	permanent	Infrastructure	tests/Infrastructure/Git/Unit/GitClientTest.php
```

All three match the plan's table: owner `Core` with the target identical to the
probed path; owner `Reporting` with the `Formatter/Html` remainder preserved,
which the pre-P0 `targetPath()` could not express at all; owner
`Infrastructure/Git`, not the coarse `Infrastructure`.

`current_suite` for the first is `none` because `tests/Core/Unit` is not yet
declared in `phpunit.xml.dist` — P3 adds it with the six files. The plan pins
owner and target for this probe and not the suite, and a `none` here is not a
refusal: `validateInventory()` only refuses `none` for a file that actually
exists in the worktree.

## Definition of Done

| #   | Item                                                     | Result                                             |
| --- | -------------------------------------------------------- | -------------------------------------------------- |
| 1   | `p0-oracle.py` against the `46c3deca` baseline           | **exit 0**, "agreed"                               |
| 2   | Every plant observed to refuse                           | 6 rows, all quoted above (row 6 with deviation V2) |
| 3   | The three positive probes                                | quoted above, all matching the table               |
| 4   | `composer architecture:check`                            | **exit 0**                                         |
| 5   | `composer check:code`                                    | **exit 0**                                         |
| 6   | `ModularArchitectureGovernanceIntegrationTest`           | **exit 0**, `OK (4 tests, 785 assertions)`         |
| 7   | The six per-suite counts                                 | 6987 / 419 / 203 / 660 / 179 / 748 — unchanged     |
| 8   | `git status --porcelain` limited to the four path groups | holds; one pre-existing untracked file, see below  |

Item 1, verbatim:

```
rows 921 baseline, 921 actual
allowance 112 test classes, 2 support rows outside it
cells changed as expected: subject_owner 116, target_path 242, disposition 244, closure_package 560
agreed
```

116 + 242 + 244 + 560 = 1162, which is the size the plan predicted, column by
column. The assertion is still cell by cell; the total is the corroboration.

Item 6, verbatim: `OK (4 tests, 785 assertions)`. Its two pinned counts —
`assertCount(28, …)` over `test-orphan-dispositions.tsv` and `assertCount(1, …)`
over `test-system-support-owners.tsv` — were not touched and did not move.
Neither artifact was regenerated with different content: of the seven generated
files, only `test-ownership.tsv` differs from `46c3deca`.

Item 7, measured with the runner's own exclusions:

```
for S in Unit Integration Functional Infrastructure Tooling Governance; do
  echo -n "$S "; vendor/bin/phpunit --testsuite=$S --no-coverage \
    --exclude-group=benchmark --exclude-group=live-freshness --list-tests | grep -c '^ - '
done
```

| Suite          | Expected | Measured |
| -------------- | -------: | -------: |
| Unit           | 6987     | 6987     |
| Integration    | 419      | 419      |
| Functional     | 203      | 203      |
| Infrastructure | 660      | 660      |
| Tooling        | 179      | 179      |
| Governance     | 748      | 748      |

Item 8: `git status --porcelain` names
`docs/internal/generated/modular-architecture/test-ownership.tsv`,
`scripts/generate-modular-architecture-test-inventory.php`,
`scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`
and this report. It also shows one untracked file this package did not create
and did not touch,
`docs/internal/plans/test-structure/measurement/stage-04/p1-fileset.md`. The
tree was clean when this session started, so the file appeared **during** it,
from another process; its own first line says it was measured against `main` @
`46c3deca`, which reads as P1 preparation from a concurrent session. It is left
exactly as found.

## Findings, recorded rather than acted on

- **The ladder is now 86/104 dead by path literal.** After P0, 154 of the 921
  inventoried rows still reach `classifyOwner()`'s ladder, and 86 of its 104
  `tests/…` path literals match none of them. Before P0 the comparable figure
  was 103 of 320. Removing them is a rewrite of the complement, which this
  package is scoped out of (D1); it is cheap to do once the allowance closes in
  P4, and the oracle-style check that makes it safe is "all four columns
  unchanged for every non-test-class row".
- **The probe plant's literal is outside the population it was written for**
  (V2). Whoever reviews the plant table should either change the literal to
  `XTest.php` or state that the row is about a target string rather than about a
  probe input.
- **A plant and the aggregate must not run at the same time.** The second
  `composer check:code` of this package went red in
  `ModularArchitectureGeneratorRefusalTest` because a plant was live in the
  working tree while the aggregate ran: that control copies the generator into
  an isolated project at the moment it executes, so it copied the planted file
  and then quoted the planted refusal in its own failure message. The tree, not
  the commit, is that control's input. Re-run with nothing else touching the
  tree: exit 0, all six suites exit 0. Worth knowing for P5, whose whole
  Definition of Done is a sequence of plants.
- **`git checkout --` is the wrong revert for a plant in this campaign.** It
  restores the file as of `HEAD`, which during a package is the *pre-package*
  file, so every subsequent plant silently becomes a no-op against the old code
  and reports a green that means nothing. Revert from a copy of the package's
  own working file. This happened once here and was caught because plant 2
  returned exit 0 and plant 3's own anchor assertion failed; the plants were
  redone from scratch after the implementation was rebuilt, and the rebuilt file
  reproduces the oracle's four counts exactly.

## Left uncompensated

Five pinned literals still name old paths — `P3_TEST_PATHS`,
`P6_A_FINDING_TEST_PATHS`, `P6_D_REPORTING_TEST_PATHS`, `P6_D_GIT_TEST_PATHS`
and the `FQCN::method` literals in `P6_LIVE_ADDED_TEST_IDS` /
`P6_RENAMED_TEST_IDS`. They are correct until their files move; each move
package retires its own, as the plan says.
