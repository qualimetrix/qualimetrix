# Stage 03 — execution review, findings

- **reviewer**: `claude`
- **material**: `c49fc0b4..8629a5c7` (13 commits), branch `x29-stage-03-tooling-tests`
- **schema**: `vr/review/reference/finding-schema.md`
- Findings are ordered by descending severity. Coverage is the section after
  them; refuted hypotheses are the last section.
- **The branch advanced during this review.** `8629a5c7` was `HEAD` when the
  material was assigned; while I was working, `2f4a3da7`
  ("add the two Python test roots to the rename surface") landed on the branch.
  Everything asserted about the reviewed material is pinned to `8629a5c7` via
  `git show`, and where the follow-up commit has already cured a finding that
  is said so by name — see claude-exec-10. The command runs recorded under
  coverage were executed against the working tree as it stood, which differed
  from `8629a5c7` only by that one line; the runs it could affect
  (`enumeration:renames:check`) report the same figures either way, which
  `2f4a3da7`'s own message independently measured.

## claude-exec-01

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: `scripts/cross-tool-comparison/tests/` is the one moved test directory with no owner, disposition or target branch — its inventory owner is still the Measurement capability
- **mechanism**: Twelve test directories were materialized. Eleven got a
  `classifyOwner()` branch, a `dispositionFor()` term and a `targetPath()` term.
  `scripts/cross-tool-comparison/tests/` got none of the three. It nevertheless
  classifies, because the moved file's path literal was edited **in place**
  inside `P7_MEASUREMENT_PATHS`, and `classifyOwner()` returns
  `Analysis/Evidence/Measurement` for every member of that constant
  (`:700`), while `dispositionFor()`/`targetPath()` take the
  `in_array($path, P7_MEASUREMENT_PATHS, true)` arm (`:1186`, `:1233`). So the
  generator never refuses — the omission is silent — and the published
  inventory says a tooling test in `scripts/` is owned by an `Analysis/Evidence`
  capability and belongs to closure package P7. Its sibling Python test, moved
  in the same commit, got `Tooling/PhpunitAggregate` / `P8`. The edit-in-place
  left its own trace: the literal still sits in the old sort position, between
  the `Fixtures/` and `Unit/` blocks of a list whose other sixteen members are
  all under `tests/Analysis/Evidence/Measurement/`, and the constant's docblock
  still reads "Exact Measurement artifacts governed as one closed set", which
  is now false of one of its members.
  This is a sixth instance of the shape the stage documents five times — one
  of three found here, see coverage question 1: the
  address text for 6/8/11/12 says "each mover", and P6's row in `03-packages.md`
  carries only `6, 21, 38, 40-42`. For `scripts/phpunit-aggregate/tests/` the
  owner branch was written anyway, because address 40 loudly removed the old
  `tests/System/TestRunnerConfiguration/` prefix it replaced; for
  `cross-tool-comparison` the old classification came from the
  `tests/Analysis/Evidence/...` regex, nothing went loud, and nothing was added.
  `03-tooling-tests.md` contains no occurrence of "cross-tool" at all, so no
  recorded decision assigns this file to the Measurement owner.
  **This is not the question `2f4a3da7` already answered.** That commit's
  message reports the same sweep reaching this path and calling it "sound":
  "cross-tool-comparison is classified through `P7_MEASUREMENT_PATHS` rather
  than a prefix branch". That is a statement about *presence* — the path does
  classify, and the generator does not refuse. This finding is about *what* it
  classifies to: `Analysis/Evidence/Measurement` / `P7`, where its eleven
  siblings get `Tooling/*` / `P8`, and a closed set documented as "Exact
  Measurement artifacts" now has a member in `scripts/`. Presence was checked
  and is fine; correctness was not the thing checked.
- **trigger**: воспроизводится в нормальной работе — every
  `composer architecture:check` regenerates the wrong owner and package for that
  row, and stages 04/05 read closure packages out of this artifact
- **in_scope**: да
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:70-81`, `:700`, `:1182`, `:1226`; `docs/internal/generated/modular-architecture/test-ownership.tsv:131`
- **evidence**:
  ```
  # test-ownership.tsv:131 — the moved Python test, owner and package unchanged by the move
  scripts/cross-tool-comparison/tests/test_cross_tool_comparison.py  non-php-test-process  ...  Analysis/Evidence/Measurement  ...  P7
  # test-ownership.tsv:141 — its sibling, moved in the same commit, correctly re-owned
  scripts/phpunit-aggregate/tests/test_phpunit_aggregate.py          non-php-test-process  ...  Tooling/PhpunitAggregate       ...  P8
  ```
  Mechanical cross-check of all twelve directories against the eight
  registration sites (scan scope, `classifyOwner`, `dispositionFor`,
  `targetPath`, `testSuitePrefixTable`, `phpunit.xml.dist`, `autoload-dev`,
  `surfaces()`), run against `8629a5c7` rather than the working tree:
  `cross-tool-comparison` is present in the scan scope and **nowhere else**;
  `phpunit-aggregate` is present in the scan scope and in the three generator
  functions; the ten PHP directories are complete for every site that applies
  to them. The full table is under coverage, question 4.
- **verification**: confirmed
- **verification_note**: Owner and package read out of the regenerated artifact,
  not inferred; `composer architecture:check` is green at `921 artifacts, 120
  fixture directories, 725 PHPUnit classes, 9198 expanded cases`, which is the
  point — the defect is invisible to it.
- **fix_direction**: give the directory its own owner branch and its own
  disposition/target treatment the way the other eleven have, and take the moved
  path back out of the Measurement closed set so that constant's docblock is
  true again. The set's role as the anchor for the nine fixtures is a separate
  question — see claude-exec-02.

## claude-exec-02

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: point
- **domain**: tests
- **title**: The moved Python test's nine fixtures stayed behind in the product test tree, so the tooling test still reads out of `tests/`
- **mechanism**: `test_cross_tool_comparison.py` moved to
  `scripts/cross-tool-comparison/tests/` but its `FIXTURES` constant was
  re-pointed *back* into `tests/Analysis/Evidence/Measurement/Fixtures`. Nine
  files there — `qmx-current.json`, `qmx-incomplete-coverage.json`,
  `qmx-malformed-coverage.json`, `qmx-missing-coverage.json`, `qmx-polluted.txt`,
  `qmx-stale-keys.json`, `pdepend-fqn.xml`, `pdepend-collision.xml`,
  `phpmetrics-fqn.json` — have exactly one consumer each, and it is that test.
  So the stage's subject ("tooling tests move to their code") is half done for
  this file: the test left `tests/`, its inputs did not, and the Measurement
  capability now owns nine fixtures no Measurement test reads. The population
  the stage worked from counted files, not fixtures: P6's row is
  "2 files + `Fixtures/fake_phpunit.py`", and `fake_phpunit.py` is the only
  fixture of the two Python tests that anyone enumerated. The DoD item the
  execution satisfied is "their SUTs resolve from the new location" — it says
  nothing about inputs, so this passed its own gate.
- **trigger**: воспроизводится в нормальной работе — the coupling is live on
  every `composer test:cross-tool`; it becomes a defect the moment anything
  reorganizes `tests/Analysis/Evidence/Measurement/Fixtures`, which stage 04 is
  scoped to touch
- **in_scope**: да
- **anchor**: `scripts/cross-tool-comparison/tests/test_cross_tool_comparison.py:12-14`
- **evidence**:
  ```python
  PROJECT_ROOT = Path(__file__).parents[3]
  SCRIPT = PROJECT_ROOT / "scripts" / "cross-tool-comparison.py"
  FIXTURES = PROJECT_ROOT / "tests" / "Analysis" / "Evidence" / "Measurement" / "Fixtures"
  ```
  Single-consumer proof, per basename, over all tracked files: each of the nine
  is named only by this test, plus the inventory generator's own path literal.
- **verification**: confirmed
- **verification_note**: The nine were checked one by one with a tracked-file
  grep on the basename; no PHP test, no `src/` file and no other script names
  any of them. `AnonymousClassContext.php` and the `GoldenMetrics/`,
  `GlobalNamespaceOnly/` subtrees in the same directory **do** have PHP
  consumers and are correctly staying.
- **fix_direction**: decide whether the nine belong to the tool or to the
  Measurement capability and make one of the two statements true, rather than
  leaving the split. If they move, the addresses they carry are the nine
  `P7_MEASUREMENT_PATHS` literals, the `test-fixture-directories.tsv` row for
  the Measurement `Fixtures` directory, and `.gitignore:76`, whose negation
  un-ignores `*.json` only under the old path — that last one is silent.

## claude-exec-03

- **reviewer**: claude
- **severity**: MEDIUM
- **kind**: pattern
- **domain**: architecture
- **title**: The tooling-root set is spelled out four times in one file with nothing cross-checking the four against each other
- **mechanism**: The same list of tooling test directories now exists as four
  independent literals inside the inventory generator: the `git ls-files`
  pathspec (twelve entries), eleven `classifyOwner()` branches, an eleven-term
  `str_starts_with` disjunction on one line in `dispositionFor()`, and the same
  eleven-term disjunction copy-pasted into `targetPath()`. No constant is
  shared, and no control compares them — so a directory present in one and
  absent from another produces a wrong row rather than a refusal.
  That the pair `phpunit.xml.dist` ↔ `testSuitePrefixTable()` *is* cross-checked
  (`assertSuiteClassifierAgreesWithPhpunit()` walks both directions and names the mismatch) shows
  the file already knows how to make this class of drift loud; the four-way set
  got no equivalent. claude-exec-01 is that drift, already realized, on the
  stage's own first use of the new shape. Each further tooling test directory
  adds one more term to two one-line disjunctions that are already over 700
  characters.
- **trigger**: воспроизводится в нормальной работе — the next tooling test
  directory is four edits with no backstop on three of them
- **in_scope**: да
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:349` (scan scope), `:661-696` (`classifyOwner`), `:1182` (`dispositionFor`), `:1226` (`targetPath`); the guarded counter-example is `:1138-1174` (`assertSuiteClassifierAgreesWithPhpunit`)
- **evidence**:
  ```php
  // :1182 and :1226 — the identical eleven-term disjunction, twice, verbatim
  if (str_starts_with($path, 'governance/') || str_starts_with($path, 'tools/phpstan/tests/') || str_starts_with($path, 'scripts/promise-effect/tests/') || /* ...nine more... */) {
  ```
- **verification**: confirmed
- **verification_note**: The four lists were compared mechanically, pinned to
  `8629a5c7`, rather than by eye; the table is under coverage, question 4.
  Reading a line of this length by eye is itself unreliable: I misread one of
  them twice in this review, once in each direction, and only the mechanical
  pass settled it.
- **fix_direction**: name the set once and have all four sites read it, so that
  registering a directory is one edit; or, if the four must stay separate
  because they answer different questions, add the missing-from-one-of-four
  refusal next to `assertSuiteClassifierAgreesWithPhpunit()`, which is the control that already
  makes exactly this comparison for the other pair.

## claude-exec-04

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: `.dockerignore`'s belt-and-braces exclusion was written for one of the twelve nested test directories
- **mechanism**: Address 19 prescribes "`**/tests/` **plus** the explicit
  paths", and says in as many words that the belt exists so the exclusion "does
  not depend on settling Docker's glob semantics" — the file's own comment
  repeats it. P1 added `**/tests/` and the explicit `tools/phpstan/tests/`.
  P2 through P6 materialized eleven more nested test directories and added no
  explicit path, and none of them touched `.dockerignore` at all. So if the
  `**/tests/` semantics the comment refuses to rely on do not hold, exactly one
  of twelve directories is still excluded and eleven ship in the image
  (`Dockerfile:29` is `COPY . .`, so the file is fully load-bearing).
  Another instance of that same shape: address 19's text
  quantifies over the whole stage, its assignment names P1, and P1 could not
  know the directories P2-P6 would create.
- **trigger**: только на рукотворном входе — it costs nothing unless Docker's
  `**` matching differs from what P1 assumed; the practical consequence is image
  contents, not behaviour
- **in_scope**: да
- **anchor**: `.dockerignore:32-36`
- **evidence**:
  ```
  # Matched both ways
  # so the exclusion does not depend on settling Docker's glob semantics.
  **/tests/
  tools/phpstan/tests/
  ```
  Eleven further nested test directories exist on disk
  (`scripts/{promise-effect,directive-audit,directive-audit-controls,finding-gate,suppression-snapshot,rename-enumeration,health-calibration,benchmark,modular-architecture,cross-tool-comparison,phpunit-aggregate}/tests`);
  none appears in the file.
- **verification**: confirmed
- **verification_note**: The asymmetry is read off the file. The **consequence**
  is not verified — no image was built, exactly as address 19 itself records
  ("Not verified by building an image"). So this is a broken symmetry in a
  deliberate belt, not a demonstrated leak.
- **fix_direction**: either extend the explicit list to every nested test
  directory, or settle the `**/tests/` semantics once with a build and delete
  the belt — keeping a belt that covers one twelfth is the worst of the three,
  because it reads as if the question were handled.

## claude-exec-05

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: The suite-count floor was exact at five suites and the stage made it slack without re-deriving it
- **mechanism**: `itAsksPhpunitAboutEverySuiteTheRunnerShards()` exists so that
  "a listing that silently came back short would otherwise make every other
  refusal in this class vacuously green", and it enforces that with
  `assertGreaterThanOrEqual(5, count($suites))`. Before the stage there were
  exactly five suites, so the floor was tight. The stage added `Tooling` and did
  not raise the floor, so one whole `<testsuite>` can now disappear from
  `phpunit.xml.dist` and this guard still passes. Same class as address 39 (a
  hardcoded count over a generated artifact) and address 30 (a ceiling that
  goes stale-high), both of which the stage did catch and re-derive — this one is
  the count nobody looked for, because the file it lives in is untouched by the
  diff.
- **trigger**: только на рукотворном входе — reachable only when a suite is
  removed or renamed, and compensated: the `SUITES` partition proof in
  `phpunit-aggregate.py` refuses, and `assertSuiteMapAgrees()` refuses on the
  orphaned `testSuitePrefixTable()` rows
- **in_scope**: нет — the anchor is outside the diff; the diff is what made it slack
- **anchor**: `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php:398`
- **evidence**:
  ```php
  self::assertContains('Unit', $suites);
  self::assertContains('Governance', $suites);
  self::assertGreaterThanOrEqual(5, \count($suites));
  ```
  `self::suites()` is `array_keys(self::aggregate()['commands'])`, and the
  aggregate enumerates `SUITES`, which is now six entries.
- **verification**: confirmed
- **verification_note**: Base tree had five `<testsuite>` elements
  (Unit, Integration, Functional, Infrastructure, Governance); HEAD has six.
  Both numbers read from `phpunit.xml.dist`.
- **fix_direction**: re-derive the floor against the current suite count in the
  same place the stage re-derived the allow-list ceiling, and treat
  "a count literal calibrated to a number this change moves" as the sweep
  spelling AGENTS.md now tells the next stage to walk — this one was in scope of
  that very instruction and was missed by it.

## claude-exec-06

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: The generated fixture-directory artifact now asserts a pending relocation for three directories that just landed at their final home
- **mechanism**: `dispositionFor()` was extended so that a file under a tooling
  test root reads "Retain at the materialized subject-owned path."
  `fixtureDirectoryRows()` (`:1426`) is a second, independent disposition producer and has
  only three outcomes — orphan, split, or "Move atomically with the owning
  subject." — with no retain branch. So the three fixture directories the stage
  materialized are published as moves that no plan has. It is a fourth
  disposition-producing site standing next to addresses 11 and 12, and the
  address table never enumerated it; `governance/` fixture directories already
  read the same way, so the outcome is consistent with precedent rather than
  novel, which is why this is LOW and not MEDIUM.
- **trigger**: воспроизводится в нормальной работе — regenerated on every
  `composer architecture:check`; it misleads a reader of the artifact rather
  than breaking anything
- **in_scope**: да — the three rows are added by this diff
- **anchor**: `scripts/generate-modular-architecture-test-inventory.php:1455-1457`; `docs/internal/generated/modular-architecture/test-fixture-directories.tsv:17,18,121`
- **evidence**:
  ```
  scripts/directive-audit/tests/Fixtures     Tooling/DirectiveAudit     P8  "Move atomically with the owning subject."
  scripts/phpunit-aggregate/tests/Fixtures   Tooling/PhpunitAggregate   P8  "Move atomically with the owning subject."
  tools/phpstan/tests/Fixtures               Tooling/PhpStan            P8  "Move atomically with the owning subject."
  ```
- **verification**: confirmed
- **verification_note**: `fixtureDirectoryRows()` read in full; it has no
  path-prefix branch of any kind, so the three rows are the only possible
  output for a non-orphan single-owner directory.
- **fix_direction**: give the fixture-directory producer the same retain notion
  its per-file sibling has, so that "already at its subject-owned path" is one
  statement in the artifact rather than two contradictory ones — and add it to
  the address inventory as a disposition site, next to 11 and 12.

## claude-exec-07

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: style
- **title**: `03-packages.md` restates the address count twice, and both copies are stale — the exact defect its own preamble forbids
- **mechanism**: The file opens with "Nothing here restates a count from either.
  Both review rounds found the same defect — a number copied into prose and left
  to go stale — so a number appears in exactly one place and is cited from the
  others." It then says "That file is the checklist — 31 addresses" in the DoD
  and "none of the 31 addresses" in the closing section. The checklist carries
  42; addresses 39-42 were added during execution by the commits in this range
  and neither prose copy was re-derived. The rule the file states is right and
  the file breaks it twice.
- **trigger**: воспроизводится в нормальной работе — the next reader checking
  the DoD against the checklist counts eleven addresses the DoD does not know
  about
- **in_scope**: да — `03-packages.md` is created by this diff and addresses 39-42 are added by it
- **anchor**: contract "a count lives in exactly one place" — `docs/internal/plans/test-structure/03-packages.md`, DoD bullet on the address checklist and the closing "Files of this stage's subject that no package owns"; the single source is `measurement/stage-03/addresses-to-edit.md`
- **evidence**: `03-packages.md` — "That file is the checklist — 31 addresses,
  each graded loud or silent, each assigned"; and "none of the 31 addresses".
  `addresses-to-edit.md` — sections numbered through 42, with its own heading
  "40-42 — the Python mover's dead literals".
- **verification**: confirmed
- **verification_note**: Both numbers read from the file at HEAD; the checklist's
  own highest number read from its headings.
- **fix_direction**: cite the checklist without a count, the way the same file
  already does for the per-suite integers.

## claude-exec-08

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: The per-root reachability guard still says "the two that exist today" and still floors only two of twelve roots
- **mechanism**: `itReadsEveryTestRootItJudges()` iterates `TestTree::roots()`,
  which is derived from `autoload-dev` and therefore picked up all ten new roots
  for free — that part of the design worked, which is why this is LOW. But the
  guard's assertions name `tests` and `governance` only, and its docblock says
  it "names the two that exist today as a floor rather than a ceiling". There
  are now twelve. Two consequences: the prose is false, and any single tooling
  test directory can go empty with only the global `> 500` file floor watching —
  the per-suite `assertNotEmpty` in `TestFilesAreExecutedTest` is per suite, not
  per `<directory>`, so the whole `Tooling` suite would have to empty out before
  it fires.
- **trigger**: только на рукотворном входе — needs a directory to lose its files
  while keeping the directory; `TestTree::autoloadDevRoots()` still refuses a
  declared root that is absent, and git tracks no empty directory, so a fresh
  clone turns the case loud
- **in_scope**: нет — the file is untouched by the diff; the diff is what made its prose false
- **anchor**: `governance/TestSuiteHygiene/TestMethodsAreReachableTest.php:170-189`
- **evidence**:
  ```php
  self::assertArrayHasKey('tests', $perRoot);
  self::assertArrayHasKey('governance', $perRoot);
  self::assertGreaterThan(500, $perRoot['tests']);
  self::assertGreaterThan(0, $perRoot['governance']);
  ```
  with the docblock above it: "names the two that exist today".
- **verification**: confirmed
- **verification_note**: `TestTree::roots()` returns twelve entries at HEAD
  (ten new PSR-4 test roots plus `tests`, `governance`, and `tools/phpstan` as
  the pre-existing parent root); the guard's own assertions name two.
- **fix_direction**: decide whether the design is "roots join the scan on their
  own and only the two big ones are pinned" — in which case the docblock needs
  to stop counting — or "every declared root carries a non-emptiness floor", in
  which case the floor belongs where the roots are derived, not in a hand-kept
  pair.

## claude-exec-09

- **reviewer**: claude
- **severity**: LOW
- **kind**: contract
- **domain**: style
- **title**: P7's assigned `CHANGELOG.md` was neither written nor explicitly declined
- **mechanism**: `03-packages.md` assigns P7 "`CLAUDE.md` corrections; affected
  READMEs; `CHANGELOG.md`; the five dead prose sites". `CHANGELOG.md` has no
  commit in the range. The project's changelog policy does exempt test and chore
  work, so skipping it is defensible — but the stage also added
  `/tools/ export-ignore`, which changes what the composer dist package ships
  (`tools/` shipped before this range and does not now), and that is an outward
  change of the kind the policy does cover. Either way the package item is open
  with no recorded decision, which is the state the stage's own bookkeeping
  style exists to prevent.
- **trigger**: недостижим — documentation completeness, no runtime effect
- **in_scope**: да — the `export-ignore` line is added by this diff
- **anchor**: contract "P7 owns `CHANGELOG.md`" — `docs/internal/plans/test-structure/03-packages.md`, work-package table row P7; `.gitattributes:17`
- **evidence**: `git log --oneline c49fc0b4..HEAD -- CHANGELOG.md` returns
  nothing. `.gitattributes` gains `/tools/ export-ignore`; `composer.json`
  declares no `archive.exclude`, so `export-ignore` is the sole mechanism that
  decides dist-package contents.
- **verification**: confirmed
- **verification_note**: Range restricted to the reviewed commits; the file is
  untouched by all thirteen.
- **fix_direction**: either add the entry for the packaging change or record the
  refusal where the package's DoD is, so the item stops reading as unfinished.

## claude-exec-10

- **reviewer**: claude
- **severity**: LOW
- **kind**: point
- **domain**: tests
- **title**: The rename-enumeration surface did not gain the two Python test roots — address 20 left open for the one mover it was not written into (cured after the reviewed HEAD)
- **mechanism**: Address 20 is per-mover: each package adds its own test
  directory to the `tests` surface of the rename enumeration, or the surface
  stops counting the spellings inside that directory and a later rename reads a
  drop in a column nobody re-derives. Nine of eleven `scripts/*/tests`
  directories were added, plus `tools` for the tenth. P6 added neither of its
  two, and `scripts/generate-rename-enumeration.php` is not in P6's commit at
  all — so at `8629a5c7` both Python test directories were outside the surface.
  Another instance of that same shape, and the one `2f4a3da7` names as the
  stage's sixth: address 20's text says
  "each mover, including P1", P6's row in `03-packages.md` omits it.
  **Cured outside the reviewed range** by `2f4a3da7`, which adds both roots and
  records the measurement that it costs nothing today.
- **trigger**: недостижим сегодня — measured, not assumed: the enumeration
  reports `58 channel, 54 producer, 82 metric-key rows, 113 executed` both with
  and without the two roots, so those two files carry no counted spelling. The
  hole is latent: a channel name written into a Python tooling test later would
  go uncounted, which is the silent drop the address exists to prevent.
- **in_scope**: да — at the reviewed HEAD; the anchor is edited by the diff and
  left incomplete
- **anchor**: `scripts/generate-rename-enumeration.php:57` at `8629a5c7`
- **evidence**:
  ```
  # git show 8629a5c7:scripts/generate-rename-enumeration.php | sed -n 57p
  'roots' => ['tests', 'governance', 'tools', ... 'scripts/modular-architecture/tests'],
  #                                  twelve entries; neither Python directory among them
  ```
- **verification**: confirmed
- **verification_note**: Read out of `8629a5c7` with `git show`, after the
  working tree had already moved past it. I first filed this from the range
  diff, then wrongly refuted it by re-reading the working tree, which by then
  carried `2f4a3da7`. The pinned read is the verdict. Note that `2f4a3da7`'s
  message reports the same finding, reached the same way ("cross-checking all
  twelve tooling test roots against every registration site one at a time
  instead of sampling") — so this is corroborated by a second, independent pass.
- **fix_direction**: already carried by `2f4a3da7`. What remains is the reason
  it was missable: the surface's root list is a thirteenth spelling of the same
  set that claude-exec-03 is about, and it is the only one of the eight sites
  whose omission is graded silent — so it belongs in whatever single source that
  finding's fix establishes, rather than being a list that each mover remembers.

## Coverage — what was checked and found clean

Measured green, in this tree, at HEAD:

- `composer architecture:check` — 0. `955 declarations, 37 semantic-owner
  layers, 0 seams, 73 exact internal grants -> 13 coarse edges`;
  `921 artifacts, 120 fixture directories, 725 PHPUnit classes, 9198 expanded
  cases`. The 9198 total is unchanged from the DoD baseline.
- `python3 scripts/phpunit-aggregate.py` — 0, and **all six per-suite rows match
  the prediction exactly**: Unit 6987, Integration 419, Functional 203,
  Infrastructure 660, Tooling 179, Governance 748. This is the DoD's own check
  and it is the one that would have caught a file landing in the wrong suite.
- `vendor/bin/phpunit --testsuite Tooling` — 0, `179 tests, 39094 assertions`.
- `composer test:cross-tool` — 0, both Python suites (17 and 15 cases).
- `composer enumeration:renames:check` — 0, exactly the pinned baseline
  `58 channel, 54 producer, 82 metric-key rows, 113 executed`.
- `composer phpstan` — 0, `[OK] No errors` over 1841 files with `tools` in
  `paths`. `composer cs-check` — 0, `tools` in the finder. So addresses 13 and
  14 cost no fixes, as measured.
- `bash scripts/check-private-leaks.sh` — 0. It builds its target list from
  `git ls-files`, carries no root list, and therefore covered `tools/` the
  moment the files were tracked. This was a candidate for "a form of input no
  package took" and it is **not** one.
- `composer directives:controls:coverage` — exit 1 with
  `120 probes, 0 not as declared, 2 cases guarded by nothing`, both rows
  `DirectivesCommandTest`, no `stale declaration:` line. That is precisely the
  pre-existing state the plan recorded, and it is positive evidence that all 119
  pinned `FQN::method` literals in `Probes.php` resolve after the rename.

Per the six questions:

1. **Seams between packages.** Checked per commit, for every file two or more
   packages touched. No package undid or partially undid another. Specifically:
   the ten `autoload-dev` roots arrived one package at a time and all ten are
   present; `DEVELOPMENT_NAMESPACE_PREFIXES` reached twelve entries across three
   commits (P1 +1, the `3bd4a8c8` fix +4, P5 +5) and covers all ten new
   prefixes; `surfaces()` roots were extended by every **PHP** mover — P6 extended
   nothing there, which is claude-exec-10 — and
   `finding-gate/enumeration-renames.tsv` is **not in the range diff at all**,
   so no intermediate `main` read a drop in the counts;
   `phpunit.xml.dist` grew monotonically to ten `Tooling` directories.
   Instances of the documented shape found here: claude-exec-01,
   claude-exec-10 and claude-exec-04, the first two being the same package's
   omission at two different sites.
2. **The 42 addresses, closed against the tree.** Walked all 42. Closed and
   verified: 1-18, 21-38, 39, 41, 42. Notably, the silent ones —
   6 (scan scope holds all twelve directories), 13, 14, 15, 16, 17 (ten prefixes,
   counted), 18 (`ROOTS` gains `tools`), 30 (ceiling 55 with exactly 55 rows,
   i.e. genuinely re-derived, not hand-lowered), 36, 37 — each checked by
   reading the carrier rather than by trusting a green run. **Address 20 is the
   second exception**: ten of twelve roots, claude-exec-10.
   `tools` plus all eleven `scripts/*/tests` directories, twelve in total.
   Address 19 is the other exception and is claude-exec-04. Address 40 is closed for
   `phpunit-aggregate` and is claude-exec-01 for `cross-tool-comparison`.
   The three directories the DoD requires gone — `tests/Unit/PromiseEffect/`,
   `tests/Unit/RuleVocabulary/`, `tests/TestSupport/ArchitectureStaticAnalysis/`
   — do not exist, and neither do `tests/System/` or
   `tests/Analysis/Evidence/Measurement/Tests/`.
3. **Forms of input no package took.** Four found: the owner/disposition/target
   treatment of `cross-tool-comparison` (claude-exec-01), the moved test's
   fixtures (claude-exec-02), the count literal keyed to the number of suites
   (claude-exec-05), and the fixture-directory disposition producer
   (claude-exec-06). Checked and clean: `.github/workflows/*` (CI runs
   `composer check`, so the new suite runs in CI through
   `test:aggregate`/`SUITES`; no workflow carries a root list);
   `phpunit.xml.dist`'s `<source>` block (`src` only, unaffected);
   `composer.json` has no `archive` section; `scripts/init-environment.sh`
   `CRITICAL_DIRS` only `log_warning`s, as the measurement claimed;
   `check-private-leaks.sh` as above.
4. **The ten roots, one by one.** Compared mechanically, not by sample, across
   eight registration sites. All ten PHP tooling directories are complete on
   every site that applies to them — `autoload-dev`, the `Tooling`
   `<directory>` list, `testSuitePrefixTable()`, `classifyOwner()`,
   `dispositionFor()`, `targetPath()`, the scan scope, and `surfaces()`
   (`tools/phpstan/tests` via the deliberately broader `tools` root, which is a
   documented asymmetry, not a gap). The measured table, pinned to `8629a5c7`
   — `Y` present, `.` absent, columns scan scope / `classifyOwner` /
   `dispositionFor` / `targetPath` / `testSuitePrefixTable` /
   `phpunit.xml.dist` / `autoload-dev` / `surfaces()`:

   ```
   tools/phpstan/tests                      Y Y Y Y Y Y Y .   (. = covered by `tools`)
   scripts/promise-effect/tests             Y Y Y Y Y Y Y Y
   scripts/directive-audit/tests            Y Y Y Y Y Y Y Y
   scripts/directive-audit-controls/tests   Y Y Y Y Y Y Y Y
   scripts/finding-gate/tests               Y Y Y Y Y Y Y Y
   scripts/suppression-snapshot/tests       Y Y Y Y Y Y Y Y
   scripts/rename-enumeration/tests         Y Y Y Y Y Y Y Y
   scripts/health-calibration/tests         Y Y Y Y Y Y Y Y
   scripts/benchmark/tests                  Y Y Y Y Y Y Y Y
   scripts/modular-architecture/tests       Y Y Y Y Y Y Y Y
   scripts/cross-tool-comparison/tests      Y . . . . . . .   (claude-exec-01, -10)
   scripts/phpunit-aggregate/tests          Y Y Y Y . . . .   (claude-exec-10)
   ```

   The last three columns are legitimately blank for the two Python
   directories. The ten
   namespaces are consistent by a rule that holds for all ten: the tool's own
   production namespace plus `\Tests` where the tool has one
   (`Qualimetrix\PromiseEffect`, `QmxDirectiveAudit`,
   `QmxDirectiveAuditControls`, `QmxFindingGate`, `Qualimetrix\PhpStan`,
   `Qualimetrix\HealthCalibration`), and `Qualimetrix\<Tool>\Tests` where the
   tool is a global-namespace flat script. The `Qmx*`/`Qualimetrix\*` mix is
   therefore not a divergence. **No divergence among the ten.** The divergence
   is in the two Python directories, and it is claude-exec-01.
5. **What should not have broken.** `src/` is untouched — confirmed, no `src/`
   path in the range diff. None of the edits to the generators, the hook,
   `.gitattributes`, `phpstan.neon` or `.php-cs-fixer.dist.php` narrows
   coverage: each is a strict addition of `tools` to an existing root list, and
   the two removals from `phpstan.neon` (fixture exclude and fixture ignore
   path) are re-pointed to the new fixture location rather than dropped, with
   `reportUnmatchedIgnoredErrors: true` making the ignore loud if it had not
   been. `systemSupportContents()` lost two rows and its `tests/System`
   taxonomy, and the matching `assertCount(3, ...)` was lowered to 1 — address
   39 closed. `namespace-path-allow-list.php` was genuinely re-derived. The one
   thing the stage made weaker without touching it is the suite-count floor
   (claude-exec-05), and the one thing it made false without touching it is the
   per-root docblock (claude-exec-08).
6. **`require_once` and path depths in the moved tests.** **Clean — no
   findings.** All eighteen moved files were read and each resolver was
   recomputed by hand. The ten `scripts/<tool>/tests/*.php` files that reach the
   repository root use `dirname(__DIR__, 3)`, which is correct for that depth;
   `ChannelRenameTsvGateAgreementTest.php` uses `dirname(__DIR__)` to reach
   `scripts/finding-gate/`, which is correct precisely because its SUT is the
   parent directory — the one collapsed path in the diff is the one that should
   have collapsed; `ModularArchitectureGeneratorRefusalTest.php` uses
   `realpath(__DIR__ . '/../../../')`, three levels, correct; the two
   `tools/phpstan/tests` files use `__DIR__ . '/Fixtures/...'`, unchanged and
   correct; `test_cross_tool_comparison.py` `parents[3]` and
   `test_phpunit_aggregate.py` `parents[2]` are both correct for their new
   depths. Every one of them was also **executed** — the `Tooling` suite is 179
   green and both Python suites are green — so the SUTs resolve in fact, not
   only on paper. The only path-related finding is claude-exec-02, and it is
   about where a resolver points, not about its depth.

Known and pre-existing, checked and deliberately **not** filed: the red
`scripts/directive-audit-controls.php` mutation target, which produced no new
line (`0 not as declared`, no `stale declaration:`, the same two
`DirectivesCommandTest` rows); the dead `classifyOwner()` prefix
`tests/Unit/PhpStan/` at `:982`, whose directory was already absent at
`c49fc0b4` and which `03-packages.md` scopes out by name; `scripts/tests` still
in the scan-scope pathspec although the directory is gone, also pre-existing;
`.gitignore:89`; `src/Infrastructure/README.md:336`. The `Qualimetrix.Tests.Unit.RuleVocabulary.CollisionSite{A,B}`
strings in `DirectiveAuditControlsSuiteKeyTest` are invented XML input, not
references to anything, and are correctly left alone.

Not attempted, named rather than implied:

- **Reproducing the G2/G3 plant on each new root.** The DoD asks for the step-1b
  refusal once per root. Doing it honestly needs an isolated copy with a
  *copied* `vendor` — a symlinked one resolves PSR-4 back into the source tree
  and gives a false green — which is expensive, and read-only review cannot
  plant probes here. I read the guards instead and can say the mechanism is
  sound (`TestTree` derives its corpus from `autoload-dev`, so a declared root
  cannot be outside it, and it refuses a declared-but-absent root rather than
  skipping it). I did **not** witness a refusal on any of the ten.
- **A Docker build.** claude-exec-04's consequence is unverified for that reason.
- **The full 20-minute `scripts/directive-audit-controls.php`.** Only the
  coverage half was run; it is belt evidence for the 119 literals, not the
  whole control.
- **`composer check` end to end.** Its four groups were run as separate
  commands (leaks, cs-check, phpstan, aggregate, cross-tool, architecture,
  enumeration) and each was green; `check:docs` (mkdocs) and the rest of
  `check:self` were not run.
- **The 616 `tests/` files no witness read** — out of this stage's scope and out
  of this review's.

## Refuted

None. One finding was refuted mid-review and then reinstated, which is worth
recording rather than hiding:

- `claude-exec-10` | the rename surface omits the two Python test roots | I
  filed it from the range diff, refuted it by re-reading the working tree, then
  reinstated it on a `git show 8629a5c7` read. The refutation was wrong for a
  reason that is not about this branch: the tree had moved under me, and the
  commit that moved it is the cure for this very finding. Re-reading the
  working tree is not a check on a pinned range — only `git show` is.
