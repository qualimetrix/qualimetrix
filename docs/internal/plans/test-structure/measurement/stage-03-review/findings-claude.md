# Stage 03 — plan review, findings (reviewer `claude`)

Material: `194dbfea` on `x29-stage-03-tooling-tests`, documentation only. Every
"confirmed" verdict below was produced by a command run either read-only against
the working tree or against an `rsync` copy under `mktemp -d`; the working tree
was not modified, and `git status --short` was empty before and after.

Findings are ordered by descending severity.

---

### claude-01

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: tests
- **title**: The plan re-points CLAUDE.md's `createIsolatedProject()` row and drops what the row demands; P1 cannot go green without editing a file the plan gives to P5
- **mechanism**: `CLAUDE.md`'s table names `createIsolatedProject()` — "copies the roots it names" — as a **silent** address for a new test root. Correction 4 of the plan updates only the *address* of that row (the method moved out of `governance/` into `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php`) and carries no obligation into any DoD item or package cell. The obligation is real and immediate: the inventory generator runs `vendor/bin/phpunit --list-suites --no-coverage` inside whatever project root it is handed (`scripts/generate-modular-architecture-test-inventory.php:387`), and `--list-suites` resolves **every** `<testsuite>` `<directory>` on disk. `createIsolatedProject()` copies `tests/`, `governance/`, `src/`, `docs/internal/generated/modular-architecture/`, exactly one file into a fresh `scripts/`, `.gitignore` and `phpunit.xml.dist` — so the moment a `Tooling` `<directory>` naming `tools/phpstan/tests` or `scripts/<tool>/tests` exists in the tracked config, the copied config names a directory the isolated project does not have. Two outcomes, and the plan handles neither: (a) the three refusal controls in that file fail — they assert specific refusal text, not merely a non-zero exit, so a `--list-suites` exit 2 does not pass for the wrong reason; (b) the cheapest repair — creating the missing directories empty — makes `--list-suites` succeed again while the isolated tree no longer contains any tooling test, which is the silent narrowing `CLAUDE.md` warns about. P1 owns "the `Tooling` suite"; the file holding `createIsolatedProject()` is row 16, which the package table assigns to **P5**. So the plan's own account of the shared file set ("every mover package touches `phpunit.xml.dist`, `composer.json`, the inventory generator and the generated artifacts") is short by this file.
- **trigger**: воспроизводится в нормальной работе — исполнение P1 как описано
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:236` (P1 row) and `:261-263` (correction 4), against `CLAUDE.md`'s registration table row for `createIsolatedProject()`; code at `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php:179-203` and `scripts/generate-modular-architecture-test-inventory.php:387`
- **evidence**: CLAUDE.md's row: "`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php` — `createIsolatedProject()` copies the roots it names | **silently**". Measured in the isolated copy, with one extra `<testsuite name="Tooling"><directory>tools/phpstan/tests</directory></testsuite>` added to `phpunit.xml.dist` and that directory absent:
  ```
  vendor/bin/phpunit -c phpunit.probe3.xml --list-suites --no-coverage ; EXIT=2
  Test directory ".../tools/phpstan/tests" not found
  ```
  and with an explicit file list instead of a suite selection the same config exits 0 — so the refusal comes specifically from the `--list-suites` call at generator line 387, which `createIsolatedProject()` reaches. The controls' assertions are `assertNotSame(0, $exitCode, $output)` **plus** `assertStringContainsString('classified as suite "none"', $output)` (`:105-107`) and an equivalent text assertion at `:143-144`.
- **verification**: confirmed
- **verification_note**: `--list-suites` exit 2 on an absent declared directory measured directly; the copy-list of `createIsolatedProject()` read in full; the controls' text assertions read in full. The only unmeasured step is the end-to-end failure of the control after a real `Tooling` declaration, which would require registering the suite in the copy.
- **fix_direction**: make the isolated-project builder's copy list derive from the declared roots rather than from a hand-written list, and give the obligation an owner and a DoD item in the package that first declares a `Tooling` `<directory>`; state which package owns that file, since it is a mover and a registration file at once. If the builder keeps a hand-written list, the DoD needs an item that the isolated tree contains the tooling tests, not merely the directories.

---

### claude-02

- **reviewer**: claude
- **severity**: HIGH
- **kind**: contract
- **domain**: tests
- **title**: The rename enumeration's `tests` surface stops covering the 16 movers, and the address the plan's own measurement names is in no DoD item and no package
- **mechanism**: `scripts/generate-rename-enumeration.php::surfaces()` declares the `tests` surface as `roots => ['tests', 'governance']`. Neither `scripts` nor `tools` is a root of any surface. The 16 moving files carry hundreds of channel and metric-key spellings that this surface currently counts — `complexity.ccn` 44 times, `health.overall` 41, `coupling.distance` 22, `health.typing` 20 and so on. After the move those occurrences leave every surface, so `enumeration:renames:check` (inside `composer check:artifacts`) reddens once on changed counts, the executor regenerates the tracked TSVs, and the drop is absorbed as expected. What is silently lost is the guarantee the enumeration exists to give: a future channel or metric rename will no longer see those spellings at all. This is the address `CLAUDE.md` marks **silent** ("`surfaces()`, or a later move reads as a drop in a column nobody re-derives") and which the stage's own `registration-addresses.md` re-derived and named exactly (`:56-61`, "Silent for both `scripts/` and `tools/`"). The plan's prose, its DoD and its package table name it nowhere; `composer check` green is not an oracle for it. **This is a direct recurrence**: the comment sitting on that very entry records stage 02 solving the identical problem by adding `governance` to the same list, and says why — "A separate column would have split the count in the same step that moves the files, so a sweep reading one column would have read a drop that means nothing."
- **trigger**: воспроизводится в нормальной работе — исполнение любого mover-пакета (P1–P6)
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:180-207` (Definition of Done, no item) and `:233-248` (package table, no owner), against `scripts/generate-rename-enumeration.php:56-61`
- **evidence**: the surface entry as it stands:
  ```php
  'tests' => [
      'roots' => ['tests', 'governance'],
  ```
  and the count of currently-swept spellings inside the 16 movers, taken by intersecting column 1 of `finding-gate/enumeration-renames.tsv` with those files: `complexity.ccn -> 44`, `health.overall -> 41`, `coupling.distance -> 22`, `health.typing -> 20`, `health.complexity -> 16`, `health.coupling -> 14`, and further rows.
- **verification**: confirmed
- **verification_note**: `surfaces()` read in full — `scripts` and `tools` appear in no surface's `roots`; the spelling counts measured over exactly the 16 paths. Not measured: the exact TSV diff after the move, which would require doing the move.
- **fix_direction**: decide, in the plan, whether the tool test directories join the existing `tests` surface (one column, the stage-02 precedent) or get their own, and record the choice plus a DoD item that the re-derived enumeration still reaches the moved files — not merely that the check is green. Whichever is chosen, give it a package.

---

### claude-03

- **reviewer**: claude
- **severity**: HIGH
- **kind**: point
- **domain**: tests
- **title**: The two-step plant cannot execute as written: step 1 names the wrong location and reddens, and step 2's "widen the literal" has a natural form that breaks the generator
- **mechanism**: Step 1 says to put the orphan probe "in a new directory **under the old scan scope**" and asserts `composer architecture:check` must stay **green**. Measured: a probe inside the scan scope makes `architecture:check` exit 1 with `Unclassified test artifact`, because `classifyOwner()` ends in a `fail()` for any path no branch matches. The claim only holds one directory-level away — a probe *outside* the scope leaves the run green at the identical 9198 cases. So the step as written reddens, and an executor who follows it cannot tell "the hole is not there" from "I put the probe in the place the plan named". Step 2 is then under-specified in a way that matters: widening the literal to the bare roots `scripts` and `tools` does not produce a refusal on the probe at all — it pulls every non-test PHP file under those roots into the file list handed to `vendor/bin/phpunit --list-tests`, and that command fails, so the generator dies for an unrelated reason. Only a pathspec naming the test directory precisely refuses on the probe. A pathspec is also not free to guess: `scripts/*/tests` matches nothing (git treats a wildcard pathspec as a full-path glob), while `scripts/*/tests/*` matches. The difference decides whether P1 widens the scope once for the whole stage or whether P2–P5 must each widen it again — and the plan gives the scan scope to P1 alone.
- **trigger**: воспроизводится в нормальной работе — исполнение шагов 1-2 как написано
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:209-222`
- **evidence**: same probe class, two locations, in the isolated copy:
  ```
  tests/ProbeOrphan/ProbeOrphanTest.php          -> EXIT=1  Unclassified test artifact: tests/ProbeOrphan/ProbeOrphanTest.php
  scripts/promise-effect/tests/ProbeOrphanTest.php -> EXIT=0  ... and 9198 expanded cases.
  ```
  widening, same probe:
  ```
  '--', 'tests', 'governance', 'scripts', 'tools', ...   -> EXIT=1 Command failed with exit 1: vendor/bin/phpunit --list-tests ... (no Unclassified line)
  '--', 'tests', 'governance', 'scripts/promise-effect/tests', ... -> EXIT=1 Unclassified test artifact: scripts/promise-effect/tests/ProbeOrphanTest.php
  ```
  and `git ls-files --cached --others --exclude-standard -- 'scripts/*/tests'` returns nothing while `'scripts/*/tests/*'` returns the probe.
- **verification**: confirmed
- **verification_note**: all four runs executed in an `rsync` copy under `mktemp -d`; baseline `architecture:check` in that copy is exit 0 and reports the same 9198, so step 1's premise "green before" is sound at the *other* location and the ordering is not theatre — the location is simply stated inverted.
- **fix_direction**: restate step 1 with the probe at a path the new roots will occupy and say that it is outside the scan scope; state the exact shape of the widened pathspec and, if it is per-directory rather than a glob, add the scan-scope edit to every package that creates a directory plus a per-package check that the discovered total is still 9198. Also name the generator branches a new root needs beyond the three edits step 3 lists — `classifyOwner()` alone refuses first.

---

### claude-04

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: P3 claims it empties `tests/Unit/RuleVocabulary/`; D3-3 in the same document proves it cannot
- **mechanism**: The package table gives P3 rows 4, 5, 6, 10 and the note "empties `tests/Unit/RuleVocabulary/`". That directory holds five test files: rows 4, 5, 6, 10 **and row 12** (`RenameEnumerationRetirementTest`), which is one of the six flat-script tools and therefore belongs to P5. D3-3 states this correctly in its rejected alternative — deferring the six "leaves `tests/Unit/RuleVocabulary/` alive with exactly one file (`RenameEnumerationRetirementTest`)" — so the two halves of the plan contradict each other. The consequence is not cosmetic: the DoD item "`tests/Unit/PromiseEffect/` and `tests/Unit/RuleVocabulary/` no longer exist" is unreachable until P5 lands, and any registration edit P3 makes on the belief that the directory is gone (the `classifyOwner()` branch at `scripts/generate-modular-architecture-test-inventory.php:960`) would be premature.
- **trigger**: воспроизводится в нормальной работе — исполнение P3 по его собственной формулировке
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:238` against `:66-69`
- **evidence**: the directory as it stands — `DirectiveAuditControlsSuiteKeyTest.php`, `DirectiveAuditGateTest.php`, `DirectiveAuditReportReadingTest.php`, `RenameEnumerationRetirementTest.php`, `ThresholdPopulationAgreementTest.php`, plus `Fixtures/`; row 12 is `RenameEnumerationRetirementTest` and the package table puts rows 11-16 in P5.
- **verification**: confirmed
- **verification_note**: directory listed directly; D3-3's arithmetic independently re-checked and it is the half that is right.
- **fix_direction**: move the "empties" note to the package that removes the last file, or move row 12 into P3 and say so in D3-3's arithmetic; then state which package owns the dead `classifyOwner()` prefix for that directory.

---

### claude-05

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: contract
- **domain**: tests
- **title**: The `tools/` guard list in the DoD is five addresses and the measured set is six — the scratch-path control is dropped
- **mechanism**: `governance/TestSuiteHygiene/ScratchPathsCarryRealEntropyTest.php` walks `ROOTS = ['tests', 'governance', 'scripts']` and refuses any clock-derived scratch path it finds. P1 puts test files under `tools/phpstan/tests/`, which that list does not reach, so the rule stops applying to them — silently, because the control's own floor (`assertGreaterThan(500, $counted)`, plus a non-empty `scripts` listing) is met by the remaining tree regardless. `CLAUDE.md` names this address explicitly as silent-failing ("`ScratchPathsCarryRealEntropyTest.php` — `ROOTS`, and every other control that carries its own root list — **silently**") and the stage's `registration-addresses.md` re-measured it ("Silent for `tools/`, matching CLAUDE.md's row exactly"). The plan's prose section on `tools/` and its DoD item both enumerate five addresses — `phpstan.neon`, the cs-fixer finder, the pre-commit filter, `.gitattributes`, `DEVELOPMENT_NAMESPACE_PREFIXES` — and omit this one. An enumeration that its own measurement file contradicts is the shape the planning rule about generalising over a set is meant to catch.
- **trigger**: воспроизводится в нормальной работе — исполнение P1
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:136-146` and `:201-204`, against `governance/TestSuiteHygiene/ScratchPathsCarryRealEntropyTest.php:42`
- **evidence**:
  ```php
  private const ROOTS = ['tests', 'governance', 'scripts'];
  ```
  and the control's floor two methods later: `self::assertGreaterThan(500, $counted);` over the same three roots.
- **verification**: confirmed
- **verification_note**: the const and the floor read directly; the floor arithmetic holds because 616 `*Test.php` files remain under `tests/` after the move.
- **fix_direction**: make the `tools/` DoD item enumerate every root list the measurement found rather than the five the prose happens to name, and prefer deriving these lists from the declared dev roots over extending them by hand — the same list appears in at least three controls and two tool scripts.

---

### claude-06

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: pattern
- **domain**: tests
- **title**: Four `classifyOwner()` prefixes and one `<directory>` go dead and the plan names none of them by path — the census-drift shape stage 02's review already measured
- **mechanism**: The stage empties four path prefixes the inventory generator classifies against: `tests/Unit/RuleVocabulary/` (`:960`), `tests/Unit/PromiseEffect/` (`:966`), `tests/TestSupport/ArchitectureStaticAnalysis/` (`:667`) and `tests/System/TestRunnerConfiguration/` (P6 moves both Python files and that tree holds nothing else). Exactly one `phpunit.xml.dist` `<directory>` is emptied — `tests/TestSupport/ArchitectureStaticAnalysis/Unit`, whose only two test files are rows 8 and 9 — together with its `testSuitePrefixTable()` row. The plan mentions "a stale `<directory>` is removed here" without naming it, assigns it to no package, and names no dead prefix at all. Stage 02's review already measured this exact shape and recorded it: removing one dead `classifyOwner()` branch "exposed the same fault at scale: **50 of the 84** `tests/`-or-`governance/` prefixes the generator tests against name a directory that is not there", left deliberately for that script's owner. This stage adds four more to the 50 while its only statement about the subject is an unnamed singular.
- **trigger**: воспроизводится в нормальной работе — исполнение P1, P2, P3+P5, P6
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:116-122` and `:233-248`; code at `scripts/generate-modular-architecture-test-inventory.php:667`, `:960`, `:966`, the `tests/System/TestRunnerConfiguration/` branch, and `phpunit.xml.dist` `<directory>tests/TestSupport/ArchitectureStaticAnalysis/Unit</directory>`
- **evidence**: the emptied-directory enumeration, derived by intersecting each declared `<directory>` with the 16 movers (`tests now | moving | left`):
  ```
  Unit | tests/TestSupport/ArchitectureStaticAnalysis/Unit | 2 | 2 | 0
  Unit | tests/Unit                                        | 83 | 8 | 75
  Integration | tests/Analysis/Evidence/ComputedMetrics/Integration | 3 | 2 | 1
  ```
  and `git ls-files tests/System` returns only `Tests/test_phpunit_aggregate.py` and `Tests/Fixtures/fake_phpunit.py`.
- **verification**: confirmed
- **verification_note**: the "exactly one `<directory>` empties" count in the plan is correct — measured, and it is `tests/TestSupport/ArchitectureStaticAnalysis/Unit`. What is missing is the name and the owner, and the four dead prefixes.
- **fix_direction**: enumerate, in the plan, the exact prefixes and declared directories each package makes dead, and give each to that package; decide explicitly whether the dead branches are removed here or added to the 50 already recorded as that script's owner's debt — the current silence makes it neither.

---

### claude-07

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: architecture
- **title**: D3-1's rejected alternative is understated by its own measurement (four tools of five, not three), and the third option is dismissed in a subordinate clause
- **mechanism**: D3-1 rejects "one root per tool directory" partly on the ground that "a convention that holds for three tools out of five is worse than one that holds for all". The stage's own PSR-4 table says otherwise: of the five tool directories that receive tests, `tools/phpstan` (0 violations), `scripts/directive-audit` (0), `scripts/directive-audit-controls` (0) and `scripts/finding-gate` (2, both "classless helper files nothing autoloads", marked "yes") can be roots as they stand, and only `scripts/promise-effect` (13 of 15) cannot. Four of five, not three. The number is load-bearing because it is the whole argument against the alternative. Separately, the third option is never weighed: making `scripts/promise-effect` PSR-4-clean is dismissed as "a change to the tool, not to its tests" in a subordinate clause, with no cost measured and no debt recorded — although the tool is in-repo, has tests, has no external consumers under this repository's stated compatibility policy, and the repository's own dogfooding rule makes refactoring the default response to a structural signal rather than accommodation. D3-1's "forced by measurement, not chosen" therefore overstates what the measurement forces: it forces only that `scripts/promise-effect` cannot be a root *today*.
- **trigger**: только на рукотворном входе — читатель плана, принимающий решение по названному числу
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:36-46`, against `docs/internal/plans/test-structure/measurement/stage-03/population.md:88-95`
- **evidence**: independently re-measured per tool directory (one declared type whose name matches its file, for every tracked `.php`):
  ```
  tools/phpstan                    files=3  psr4_suspect=0
  scripts/directive-audit          files=11 psr4_suspect=0
  scripts/directive-audit-controls files=6  psr4_suspect=0
  scripts/finding-gate             files=42 psr4_suspect=2
  scripts/promise-effect           files=15 psr4_suspect=13
  ```
- **verification**: confirmed
- **verification_note**: my sweep reproduces the measurement table exactly, including the 13/15 for `promise-effect`; the disagreement is only with the prose that summarises it.
- **fix_direction**: correct the count to what the table says and re-state the argument on the corrected number; and either weigh "split `promise-effect` one class per file, then root at the tool directory" as a named alternative with its cost, or record declining it as debt with an owner and a closing condition, the way D3-3 does for its own debt.

---

### claude-08

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: The DoD item "G2 and G3 cover every new root" is satisfied by a run that measured nothing about the new roots
- **mechanism**: The item's stated oracle is "shown by naming the roots `TestTree::roots()` returns". That is a read of `composer.json` — it proves the root is declared, not that any guard judged a file inside it. The floors that would otherwise make the claim bite do not: G3's `itJudgesEveryTestFileInTheTree` asserts `assertGreaterThan(500, count($judged))` and G2 asserts `>= 5` suites, `> 500` test files, `> 600` classes and `> 5000` case ids — all of which the 616 `*Test.php` files remaining under `tests/` satisfy on their own, with every new root walking zero files. The item's second half ("shown to *bite* by the two-step plant below") is the part that can refuse, and it covers one probe in one directory, not "every new root". The stage creates eleven directories under the new suite.
- **trigger**: воспроизводится в нормальной работе — приёмка этапа по этому пункту
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:192-193`
- **evidence**: the floors, as they stand:
  ```php
  self::assertGreaterThanOrEqual(5, \count($suites));
  self::assertGreaterThan(500, \count(TestTree::testFiles()));
  self::assertGreaterThan(600, \count(self::classesIn(self::reachableIds())));
  self::assertGreaterThan(5000, \count(self::reachableIds()));
  ```
  and G3's `self::assertGreaterThan(500, \count($judged));`.
- **verification**: confirmed
- **verification_note**: floors read directly; the arithmetic (632 `*Test.php` under `tests/` today, 16 leaving) is measured. `TestTree::autoloadDevRoots()` does throw for a declared root that is not a directory, so the item is not vacuous — it is just weaker than "cover".
- **fix_direction**: restate the oracle as a per-root count of files the two guards actually judged, compared against the 16 named paths, rather than as a listing of declared roots; a floor that the untouched remainder satisfies cannot stand in for coverage of the part that moved.

---

### claude-09

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: judgement
- **domain**: tests
- **title**: No package has a Definition of Done, and P4 carries an undecided fork inside its ownership cell
- **mechanism**: The stage has one DoD, at stage level. The package table gives each package a "Moves" and an "Also owns" column and no acceptance criterion, so nothing states what a package must show before the next one starts — in a stage whose packages are declared sequential precisely because they share registration files. The one oracle that would catch a package forgetting the scan-scope widening is the discovered total (9198), and it is stated once, at the end: within `composer check`, a package that leaves its files outside the generator's scan scope simply produces generated artifacts with fewer rows, which the normal workflow regenerates and commits green. P4's cell is worse than missing: "the shared `ChannelRenameTsvCorpus` stays in `tests/`; the cross-root import is stated or the corpus moves" is an undecided fork — a placement decision handed to the executor, which is the one category of content a plan is supposed to settle. A package whose specification contains "or" cannot be verified on its own.
- **trigger**: недостижим напрямую — свойство документа, проявляется через исполнение
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:223-248` (the whole package table), especially the P4 row at `:239`
- **evidence**: the P4 cell reads "the shared `ChannelRenameTsvCorpus` stays in `tests/`; the cross-root import is stated or the corpus moves"; no row in the table carries an acceptance criterion; the only totals check is the stage DoD item at `:186-191`.
- **verification**: unverifiable
- **verification_note**: a judgement about the plan's structure; the underlying facts (single stage-level DoD, the "or" in P4, artifacts regenerate silently) are each read directly, but "each package verifiable on its own" is not a property a command decides.
- **fix_direction**: give every package a short DoD naming the command that refuses if that package is incomplete — for the movers, the discovered/executed pair plus the per-suite row for the directory it filled — and settle P4's corpus question in the plan, stating the resulting cross-root dependency direction explicitly if the corpus stays.

---

### claude-10

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: The no-owner audit quantifies over "the 16", so the Python mover's fixture and the two directories it empties fall outside it
- **mechanism**: The stage's subject is 18 files, and its no-owner statement reads "none of the 16, none of their fixtures". `tests/System/TestRunnerConfiguration/Tests/Fixtures/fake_phpunit.py` is the fixture of a Python mover, not of one of the 16, so it is neither owned nor declared out of scope — and it must move with its test, which locates it as `TEST_ROOT / "Fixtures/fake_phpunit.py"` relative to its own `__file__`. The same quantifier misses that P6 empties two whole directories, `tests/System/` (which holds nothing but those two files) and `tests/Analysis/Evidence/Measurement/Tests/` (which holds nothing but the other Python test). The defect is the audit's scope rather than the outcome: forgetting the fixture is loud, because the test resolves it by path.
- **trigger**: воспроизводится в нормальной работе — исполнение P6
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:244-248`
- **evidence**: `git ls-files tests/System` → `tests/System/TestRunnerConfiguration/Tests/Fixtures/fake_phpunit.py`, `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py`; and in the test, `FAKE_PHPUNIT = TEST_ROOT / "Fixtures/fake_phpunit.py"` with `TEST_ROOT = Path(__file__).parent`.
- **verification**: confirmed
- **verification_note**: both directory listings and the fixture reference read directly.
- **fix_direction**: state the no-owner audit over the stage's 18 files and their fixtures rather than over the 16, and name the two emptied Python directories together with the `classifyOwner()` prefix for `tests/System/TestRunnerConfiguration/` that they make dead.

---

### claude-11

- **reviewer**: claude
- **severity**: LOW
- **kind**: judgement
- **domain**: tests
- **title**: The prose-reference debt grows by five sites with no reference to stage 02's recorded principal inheritance
- **mechanism**: The plan names five files carrying dead prose mentions (`@see`, "split off from"), confirms nothing checks them, and leaves them as documentation debt "named here so nobody mistakes leaving them for an oversight". Stage 02's review already grouped this as its first mechanism — "A reference living in prose has no resolver" — fixed six of seven named sites, left one still stale today, and recorded the absence of a resolver as **the stage's principal inheritance**. Stage 03 adds five more sites to that inheritance without citing it, without an owner and without a closing condition, which is the difference between recorded debt and an unnamed accumulation. Naming it honestly is better than the alternative; the finding is that the honest naming is not connected to the standing inheritance it adds to.
- **trigger**: недостижим — свойство документа
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:175-178`, against `docs/internal/plans/test-structure/measurement/stage-02-review/REPORT.md:19-45`
- **evidence**: the plan: "Five further files carry only dead prose mentions … They are documentation debt, named here so nobody mistakes leaving them for an oversight." The stage-02 report: "Also not fixed here: the absence of a resolver. Building one is a new guard and this stage adds none; it is recorded as the stage's principal inheritance."
- **verification**: unverifiable
- **verification_note**: both texts read directly; whether a growing debt with no owner is a defect of this plan is a judgement.
- **fix_direction**: tie the five sites to the inheritance the previous stage recorded — one line saying whose debt it now is and what would close it — so the count is tracked in one place rather than restated per stage.

---

### claude-12

- **reviewer**: claude
- **severity**: LOW
- **kind**: judgement
- **domain**: tests
- **title**: "A directory that exists and holds no tests is silent" is a local-only property: git tracks no empty directory, so the same state exits 2 on a fresh clone
- **mechanism**: The plan derives "this stage needs **no** new 'every declared directory exists' control" partly from the measured fact that a declared directory which exists and holds no test files is silent — "that is the shape this stage can actually produce". On the machine that did the move it is; in a fresh clone it is not, because git does not track an empty directory, so the emptied directory is absent there and PHPUnit exits 2. The practical consequence is a state that is green locally and red in CI, which is the opposite of the reasoning's direction: the shape the stage can produce is not reliably silent, it is machine-dependent.
- **trigger**: воспроизводится в нормальной работе — свежий clone / CI после частично исполненного пакета
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:116-122` and `docs/internal/plans/test-structure/measurement/stage-03/baseline.md:77-82`
- **evidence**: the absent-directory behaviour reproduced in the isolated copy — `Test directory ".../tests/Vanished/Unit" not found`, exit 2 for both `--list-tests` and a run; and the empty-directory case is silent only while the directory is present on disk, which tracking cannot guarantee.
- **verification**: unverifiable
- **verification_note**: the exit-2 half is measured; that git tracks no empty directory is a property of git, not of this tree, hence the judgement framing for the conclusion rather than the fact.
- **fix_direction**: say in the DoD that the removal of an emptied directory and of its `<directory>` happen in the same commit, and note that the silent variant is local-only — so nobody reasons from it that CI would tolerate the intermediate state.

---

### claude-13

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: The `Probes.php` cure is mechanical at prefix level but must not be applied repository-wide: one mover shares the prefix and lands in a different root
- **mechanism**: All 119 dot-separated literals in `scripts/directive-audit-controls/Probes.php` carry one prefix, `Qualimetrix.Tests.Unit.RuleVocabulary`, and all three classes they name go to the same destination, so a prefix-level rewrite of that file is mechanical and safe. The same prefix, however, also occurs in row 6 (`DirectiveAuditControlsSuiteKeyTest`), as synthetic class names inside a fake JUnit XML fixture and its expectations — and row 6 moves to a *different* root (`scripts/directive-audit-controls/tests/`) from rows 4, 5 and 10 (`scripts/directive-audit/tests/`). A repository-wide sweep on the prefix therefore rewrites strings that are not references at all, into a namespace that will not exist. It stays green (the fixture and its expectation are rewritten together) and is wrong. The plan's instruction, "a sweep must enumerate spellings, not names", is the right rule; what it does not say is that the sweep must also be scoped per class, because one prefix maps to two destinations.
- **trigger**: только на рукотворном входе — исполнитель, применяющий sed по префиксу
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:155-159`, against `tests/Unit/RuleVocabulary/DirectiveAuditControlsSuiteKeyTest.php:38-62`
- **evidence**: the occurrence counts in `Probes.php` — `DirectiveAuditGateTest` 16, `DirectiveAuditReportReadingTest` 38, `ThresholdPopulationAgreementTest` 65, total 119 across 101 lines — and in row 6 the prefix appears on synthetic names:
  ```php
  'Qualimetrix.Tests.Unit.RuleVocabulary.CollisionSiteA::itSameName',
  ```
- **verification**: confirmed
- **verification_note**: counts and the row-6 occurrences measured directly; the Placement table is what sends row 6 to a different root.
- **fix_direction**: scope the rewrite per moving class rather than per namespace prefix, and say so where the 119 literals are named; the synthetic fixture strings inside row 6 are not references and should be decided separately.

---

### claude-14

- **reviewer**: claude
- **severity**: LOW
- **kind**: judgement
- **domain**: tests
- **title**: The only oracle for the 119 pinned literals is a text read of a command outside `composer check`, so nothing enforces it after acceptance
- **mechanism**: The plan is right that `directives:controls:coverage` cannot be gated on its exit code — it is already 1 on `main` for two unrelated cases — and the text oracle it prescribes can refuse (a third unguarded row or a `stale declaration:` line changes the text). What the plan does not carry is that the command is not part of `composer check`, so the oracle exists only for as long as a human reads it at acceptance. After the stage lands, a later change that staled those literals again would be caught by nothing in CI. The stage does not create this gap, but it makes 119 new machine-checked-but-ungated literals depend on it.
- **trigger**: недостижим в рамках этапа — проявляется после приёмки
- **in_scope**: да
- **anchor**: `docs/internal/plans/test-structure/03-tooling-tests.md:196-200`
- **evidence**: reproduced on a pristine copy — `120 probes, 0 not as declared, 2 cases guarded by nothing.` with the two `DirectivesCommandTest` rows, and the process exit code measured separately as 1; `check:self` is `gate:self-test`, `selfcheck:analysis`, `directives:audit`, which does not include it.
- **verification**: unverifiable
- **verification_note**: the text, the exit code and `check:self`'s composition each measured; whether the residual gap is this stage's problem is the judgement.
- **fix_direction**: either name, as a follow-up with an owner, the condition under which the coverage control joins `composer check` (the two pre-existing rows are what currently prevent it), or state in the DoD that the 119 literals are guarded only at acceptance so the next reader does not assume CI holds them.

---

## Coverage

What was checked and came back clean, and which of the brief's questions were
actually answered.

**Q1 — is the population complete? Answered; no 17th found, and the claim can be
stated more narrowly than "16 + 2".** Three channels neither witness swept were
swept here, all clean:

- **Tool basename without its directory.** Every tracked file under `scripts/`
  and `tools/` was reduced to its basename and each basename grepped across
  `tests/`. Both witnesses narrowed by the directory substrings `scripts`/`tools`
  or a tooling namespace; this sweep does not. It surfaced no file outside the
  16 + 2. The only new hits were coincidences (`src/Shell.php` as a synthetic
  path in `ChannelCoverageTest`, `Options.php` matching product classes) and
  `HookStatusCommandTest`, a product test of `HookStatusCommand` that builds a
  `scripts/pre-commit-hook.sh` fixture — the same grounds on which witness B
  rejected `HookInstallCommandTest`, though only the latter is recorded in its
  rejected table.
- **Indirection through a non-test support file.** Exactly one of the 129
  non-`*Test.php` PHP files under `tests/` mentions `scripts/` or `tools/`:
  `tests/Analysis/Policy/Baseline/Fixtures/ChannelRenameTsvCorpus.php`, already
  in scope as row 7's fixture. So no shared helper hides a tool path from a
  substring sweep — which is the mechanism that would have made a basename-blind
  test invisible to both witnesses.
- **Test files in a language nobody enumerated.** The file-extension census
  under `tests/` is 761 `.php` (632 of them `*Test.php`), 3 `.py`, 11 `.json`,
  6 `.yaml`, 2 `.xml`, 1 `.txt`. There is no third test language, so the two
  witnesses' PHP + Python scope is exhaustive by extension.
- Also checked: no test invokes a tool through a `composer` script name;
  `website/hooks/generate_llms_txt.py` is a repository tool outside the stage's
  criterion whose only test lives in `governance/`, not `tests/`; and
  `benchmarks/` — named in the stage's criterion at `03-tooling-tests.md:12` —
  holds no PHP tool code at all (README, composer files, vendor), so the
  criterion names a scope that is empty and witness B's narrower sweep over
  `scripts/` and `tools/` loses nothing by it.

  **Narrowest true form of the claim:** the population is complete over every
  test under `tests/` that names a tool file, a tool basename, a tooling
  namespace, or a `scripts`/`tools` path fragment anywhere in its text. What
  remains outside it is a test reaching a repository tool through a path with no
  textual trace in any file under `tests/` — and the support-file sweep above
  shows there is no indirection layer in which such a path could currently live.

**Q2 — does the two-step plant prove the hole? Answered; see claude-03.**
Baseline `architecture:check` in an isolated copy is exit 0 and reports the same
9198 cases the measurement claims, so step 1's premise is not spoiled by a
pre-existing red. The hole itself is real and reproduced. What fails is the
location the step names and the shape of the widening.

**Q3 — is the DoD checkable? Answered.** One item is satisfiable by a run that
measured nothing (claude-08). The per-suite prediction is **clean and
reproduced exactly**: all sixteen per-file case counts match
`prediction.md` (12, 19, 4, 12, 25, 1, 17, 3, 1, 25, 11, 8, 23, 5, 10, 3), total
179, split 161 Unit + 18 Integration, giving Unit 7148 → 6987 and Integration
437 → 419; and neither the `benchmark` nor the `live-freshness` group returns a
single row over those sixteen paths. The `directives:controls:coverage` text
oracle is sound and can refuse — reproduced verbatim on a pristine copy, exit 1
— with the residual noted as claude-14.

**Q4 — is D3-1 forced? Answered; see claude-07.** The measurement itself is
clean: my independent per-file PSR-4 check reproduces the table exactly.

**Q5 — D3-3's arithmetic and the accepted alternative. Answered, clean on both
counts except claude-04.** The arithmetic holds: `tests/Unit/RuleVocabulary/`
holds five test files plus `Fixtures/`, four of them rows 4/5/6/10 and the fifth
row 12, so deferring the six flat tools does leave exactly one file. Judged
against ADR 0016 and ADR 0022, the accepted alternative is the better one: the
rejected "defer" leaves a role-named remnant directory alive with one file and
hands stage 04 a row its map does not carry, while the accepted shape names a
subject and records the mismatch as debt **with both things the temporary-grant
form requires** — a named owner ("whoever next touches that tool") and a
checkable closing condition ("when the tool grows a second file"). The
`tools-tests/` rejection on role-naming grounds is consistent with the same
rule.

**Q6 — are the packages independently verifiable? Answered; claude-01,
claude-04, claude-09, claude-10.** The package whose completion is blocked by a
file the plan gives to a later package is P1 (claude-01); the file of the
subject that no package owns and that is not placed out of scope is
`fake_phpunit.py` (claude-10).

**Q7 — the 119 pinned literals. Answered.** The count is exact: 119 occurrences
across 101 lines, split 16/38/65 as stated, and the prefix appears 119 times
with every occurrence class-qualified. Spellings were enumerated rather than
names, and the two the measurement names as its blind spots were probed: the
**bare method name with no class qualifier** does occur in the tree — column 1
of `directive-audit/enumeration-unguarded-cases.tsv` is a bare `itXxx` name of a
mover — but it is harmless for this stage, because a move changes the namespace
half of a reference and not the method name, and that TSV is read by nothing.
The **namespace-only** spelling does not occur. The cure's residual risk is
claude-13.

**Q8 — recurrence from stage 02's review. Answered; three pairs named.**
`surfaces()` against the comment recording stage 02 adding `governance` to that
exact list (claude-02, review §B/§A shape); four dead `classifyOwner()` prefixes
against the measured "50 of the 84" (claude-06, §B); five new prose-only
mentions against the recorded principal inheritance (claude-11, §A). The
lessons stage 02's review taught that this plan **did** apply are worth stating
too: the population is derived twice by independent witnesses and its blind spot
is named with the instrument that produced it; the base is two numbers rather
than one, with the difference named case by case; and three premises of the
earlier draft are recorded as refused by measurement rather than quietly
rewritten.

**Confirmed clean, checked and not turned into findings:**

- All four `CLAUDE.md` corrections P7 owes are correct. Correction 1
  reproduced directly: an absent `<directory>` gives `Test directory "…" not
  found` and **exit 2** on the pinned PHPUnit 12.5.25, for both `--list-tests`
  and a run — `CLAUDE.md`'s "warns, exits 0, empty suite / silently" row is
  false. Correction 3 verified line by line: `SUITES` is one tuple at
  `scripts/phpunit-aggregate.py:42`, the `--jobs` bound is `len(SUITES)`, the
  docstring describes a runtime proof and enumerates no suite, and the second
  real address is the tuple's literal copy at
  `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py:22`.
  Correction 4 verified: `createIsolatedProject()` now exists only in
  `ModularArchitectureGeneratorRefusalTest.php` — with the consequence recorded
  as claude-01.
- **`tools/` is genuinely clean, measured rather than trusted.** With
  `phpstan.neon`'s `paths` widened by `tools` in an isolated copy, PHPStan level
  8 reports `[OK] No errors`; with the cs-fixer finder widened the same way, the
  dry run reports `"files":[]`. The plan's "costs no fixes" promise holds.
- The five gaps the plan claims for `tools/` all exist as described:
  `phpstan.neon:11-15`, the `.php-cs-fixer.dist.php` finder, the
  `.githooks/pre-commit` four-root grep, `.gitattributes`'s `export-ignore`
  block (no `/tools/`, so `tools/phpstan/` does ship in the dist package) and
  `DEVELOPMENT_NAMESPACE_PREFIXES` (two entries, neither of them
  `Qualimetrix\PhpStan\`).
- D3-1's nested-root claim holds:
  `NamespacePathAllowList::expectedNamespace()` keeps the longest matching root
  and `TestTree::testFiles()` dedupes by path, so a lowercase `tests/` segment
  is consumed by the prefix. The two `namespace-path-allow-list.php` rows the
  stage removes are lines 77-78, and the `ceiling` is derived downward only, so
  "two fewer rows and the ceiling lowered by re-deriving" is checkable.
- `TestTree::autoloadDevRoots()` reads only the PSR-4 map and throws for a
  declared root that is not a directory, so the loud-backstop claim for a new
  root is correct, and the plan's reason for registering each directory in the
  commit that fills it is sound.
- The plan's count of emptied `<directory>` entries is right: exactly one,
  measured. The finding is that it is not named (claude-06).
- `assertSuiteClassifierAgreesWithPhpunit()` checks both directions from
  `phpunit.xml.dist` and `testSuitePrefixTable()` without touching the scan
  scope, so the suite-map half of the registration is loud independently of the
  scan-scope hole.
- The loudness of the 119 literals holds for the half this stage actually
  changes: the control intersects whole dotted `Class::method` strings against
  the run's case universe, so a namespace change lands in `missing` by the same
  code path the method-name plant demonstrated.
- G2 derives its suite list from the aggregate's own commands and reads
  `phpunit.xml.dist` dynamically, so the new suite adds no hand-carried list
  there.
- `baseline.md`'s discovered figure is reproduced: `composer architecture:check`
  on a pristine copy prints `Checked 921 artifacts, 120 fixture directories,
  725 PHPUnit classes, and 9198 expanded cases`, exit 0.

**Not reached:**

- The **executed** total 9196 and the per-suite execution rows were not
  re-measured; `python3 scripts/phpunit-aggregate.py` was not run. Only the
  discovered total and the per-file case counts were reproduced, so
  `baseline.md`'s per-suite table (Unit 7148, Integration 437, Functional 203,
  Infrastructure 660, Governance 748) and its two named `live-freshness` cases
  stand on the author's measurement, not mine.
- `composer check` in full was not run.
- No end-to-end rehearsal of any package: no root was registered in
  `composer.json`, no suite declared, no file moved. Every claim about what
  happens after a real registration — including claude-01's end state — rests on
  reading plus the single-address probes recorded above.
- Stage 04's relocation map was not read, so the ordering claim at the end of
  the plan ("stage 04's rows for the two emptied directories must be
  re-derived") is neither confirmed nor disputed.
- CI workflow YAML was not read, so a duplicated path filter there remains
  unchecked — the same gap `registration-addresses.md` declares.

## Refuted

Hypotheses formed during this review and dropped, so the next reader does not
re-derive them:

- `claude-R1 | An untracked probe is invisible to the scan scope, so step 1 is green for the wrong reason | Refuted: the literal is git ls-files --cached --others --exclude-standard, which includes untracked non-ignored files; measured — the untracked probe inside tests/ did redden.`
- `claude-R2 | The Probes.php loudness was proved on the wrong half (method name, not namespace) | Refuted: the control intersects whole dotted Class::method strings against the run's case universe, so both halves fail through the same path.`
- `claude-R3 | "G3" in the DoD names an artifact defined nowhere | Refuted: G1/G2/G3 are defined in 01-suite-integrity.md, which the brief names as context.`
- `claude-R4 | A test reaching a tool via a composer script name would escape both witnesses | Refuted: no test under tests/ invokes composer; the three grep hits are product assertions about a configuration stage named "composer".`
- `claude-R5 | The refusal controls in ModularArchitectureGeneratorRefusalTest would pass for the wrong reason after a Tooling declaration | Refuted: each asserts specific refusal text in addition to a non-zero exit, so they fail loudly — recorded as the loud half of claude-01.`
