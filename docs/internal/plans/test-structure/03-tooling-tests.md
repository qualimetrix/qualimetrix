# Stage 03 — tooling tests move to the code they test

Rewritten in full against the tree at `c49fc0b4`. The previous text stood on a
census taken before stage 02 ran; its population, its registration claims and
its DoD were each wrong, and the corrections are in
[`measurement/stage-03/`](measurement/stage-03/). Read those rather than
counts in this prose.

## What these are

A test belongs to this stage when its **subject under test is a repository
tool** — code under `scripts/`, `tools/` or `benchmarks/` that exists to
maintain this repository — rather than the product under `src/` reached through
`bin/qmx`. They are ordinary behavioural tests: they assert what a class or a
script returns, refuses or exits with, for input the test built. They are not
repository controls, and filing them with the controls would group by role.

**The population is 16 PHP files plus 2 Python files, not the 9 this stage
previously claimed.** Two causes, and only the first was foreseen: stage 02's
splits turned four tooling *halves* into whole files, and two files were never
in the census at all. Both were found by a mechanical sweep, not by reading.
The table, the per-file SUT and the channel that reaches it are in
[`measurement/stage-03/population.md`](measurement/stage-03/population.md).
It was derived three times: by a witness holding stage 02's answers, by a
witness denied them and given only the criterion, and by a third channel running
the opposite way — enumerate the 131 tool files, grep each exact basename inside
`tests/`. All three return the same 16.

**That is not the same as a complete population, and the claim is stated at its
narrowest deliberately:** no test under `tests/` names a tool by its namespace,
by a literal `scripts`/`tools` path, or by the tool's own basename, other than
these 16. A test reaching its SUT with none of those three spellings anywhere in
the file is outside every sweep run here. Nobody read all 632 files, and both
plan reviewers looked for a 17th without finding one.

**There is one agreement test, not three.** `ThresholdPopulationAgreementTest`
runs `src/`'s `ThresholdOverrideExtractor` and the tool's
`ThresholdDirectiveScan` over one fixture and requires `assertSame`.
`ChannelRenameTsvGateAgreementTest` and `SuppressionSnapshotKeyTest` were read
as agreement tests and are not: neither runs a `src/` reader against a tool
reader inside its own body.

## The three decisions this stage stands on

**D3-1. The `autoload-dev` PSR-4 root is the test directory, not the tool
directory — one root per moving tool, including the tool whose parent root
already exists.**

Measured: of the five tool directories that have a test, **four are PSR-4-clean**
(`tools/phpstan`, `scripts/directive-audit`, `scripts/directive-audit-controls`,
`scripts/finding-gate`) and one is not — `scripts/promise-effect` violates PSR-4
in 13 of its 15 files, `Classifier.php` alone declaring four types. So a
tool-directory root is available for four tools and impossible for the fifth
without splitting that tool's source one class per file, which is a change to
the tool and not to its tests.

*Rejected — a root on each tool directory.* Unavailable for `promise-effect`,
and it is the largest of the five.

*Rejected — the hybrid: a tool-directory root where the tool is clean, a
test-directory root where it is not.* This is the option the first draft
dismissed in a subordinate clause, and it deserves its ground stated. It would
autoload the SUT for four tools out of five and delete their `require_once`
boilerplate — a real gain. It is rejected because the convention then cannot be
read off any single place: whether a given tool's test may `use` its SUT becomes
a per-tool lookup against a PSR-4 audit, and the next person to add a tooling
test has to redo that audit to know which shape to write. One rule that holds
everywhere beats a better rule that has to be looked up, and the `require_once`
the uniform rule keeps is the tool's own loading mechanism, unchanged.

**Nested roots are safe, but only once the test directory is itself a root — and
this is why declaring one per tool is mandatory rather than tidy.** The rule is
`NamespacePathAllowList::expectedNamespace()`, which selects the longest
matching root, as Composer does, and appends the **leftover path segments
literally**. Executed against a probe at `tools/phpstan/tests/`, whose parent
`tools/phpstan` is already a root, G3 refused with:

> declares `Qualimetrix\PhpStan\Tests`, and its path says `Qualimetrix\PhpStan\tests`

So a lowercase `tests/` segment *is* carried into the expected namespace while
the longest match is the tool directory, and stops being carried the moment the
test directory is declared. `TestTree::testFiles()` dedupes by path, so the
doubly-covered file is judged once. Consequence: **this stage adds no
`namespace-path-allow-list` row and removes two.**

**D3-2. One new PHPUnit suite, `Tooling`.** Follows the `Governance` precedent:
a separate root gets a separate suite, so the aggregate keeps one shard per
root and the four-suite partition proof stays a partition.

*Rejected:* declaring the new directories under the existing `Unit` and
`Integration` suites. Cheaper — no `SUITES` edit — but it puts directories
outside `tests/` under a suite name that means "a level within the product",
and it hides the move from the per-suite counts that are this stage's check.

**D3-3. All 16 move; the six tools that are a flat `scripts/*.php` file get a
subject directory for their tests, and their entry script is not moved.**

*Rejected — defer the six.* It reads cheaper and is not: it leaves
`tests/Unit/RuleVocabulary/` alive with exactly one file
(`RenameEnumerationRetirementTest`), which contradicts this stage's own DoD and
hands stage 04 a remnant its relocation map does not carry.

*Rejected — move the entry scripts in too.* Correct by ADR 0016 and the right
eventual shape. Its live blast radius is small per script (3–7 files), but it
edits tracked *artifacts* that embed the script path —
`finding-gate/enumeration-renames*.tsv`, `docs/internal/benchmark-baselines.json`
— and every `composer` script name. That is its own stage with its own review,
not a step inside this one.

**Recorded debt, with its closing condition.** A flat tool ends this stage as
`scripts/<subject>/tests/` beside `scripts/<subject-ish>.php`, so the directory
names a subject whose code sits one level up. Owner: whoever next touches that
tool. Close it when the tool grows a second file — at that point the script
moves in and the directory becomes the subject it already claims to be.

## Placement

```
tools/phpstan/tests/                      rows 8, 9   (root already exists)
scripts/promise-effect/tests/             rows 1-3
scripts/directive-audit/tests/            rows 4, 5, 10
scripts/directive-audit-controls/tests/   row 6
scripts/finding-gate/tests/               row 7
scripts/<subject>/tests/                  rows 11-16, five new subject directories
```

A single `tools-tests/` root is rejected on the same ADR 0016 grounds as
`controls/`: it names a role and collects one file per tool.

## Registration — the part that fails silently

`CLAUDE.md`'s table of addresses for a new test root was re-derived against the
tree, and it is wrong in two places. Full result in
[`measurement/stage-03/registration-addresses.md`](measurement/stage-03/registration-addresses.md).

- **The silent one it does not name.** The inventory generator's scan scope is a
  literal at `scripts/generate-modular-architecture-test-inventory.php:349`
  (`git ls-files -- tests governance scripts/tests …`). A test file outside that
  list never enters the pipeline, so none of the generator's own loud checks —
  `testSuitePrefixTable()`, `currentSuite()`, `classifyOwner()`,
  `validateInventory()` — ever runs against it. Both sides of every cross-check
  stay blind to the same file and agree.
- **The loud backstop is a different file, and the table conflates the two.**
  `governance/TestSuiteHygiene/TestFilesAreExecutedTest.php` builds its corpus
  from `TestTree::autoloadDevRoots()`, which reads `composer.json` directly and
  therefore covers a new PSR-4 root the moment it is declared, whatever the
  generator's scope says.
- **`CLAUDE.md`'s claim that an absent `<directory>` "warns, exits 0, and hands
  back an empty suite" is false for the pinned PHPUnit.** Probed four ways:
  exit 2, nothing run, and the aggregate, `architecture:check` and G2 each
  refuse behind it. So this stage needs **no** new "every declared directory
  exists" control. A directory that exists and holds no tests *is* silent, and
  that is the shape this stage can produce — which is why a stale `<directory>`
  is removed here as a lie about the suite map, not as a silent hazard.

**The aggregate needs one edit, not three.** `CLAUDE.md` names
`scripts/phpunit-aggregate.py`'s `SUITES`, "the partition proof in its
docstring" and "the `--jobs` bound" as three addresses. Only `SUITES` is one:
the docstring describes a proof the runner performs at *runtime*
(`discover_partition()` / `assert_partition()`) and enumerates no suite, and the
`--jobs` bound is `len(SUITES)`. The real second address is
`tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py:22`, which
holds its own literal copy of the five-tuple; everything else in that file
derives from it. Both are loud — the runner refuses with
`PHPUnit suite partition mismatch` when a configured suite is missing from
`SUITES`.

**`tools/` is an unguarded root today, before this stage touches it.**
`Qualimetrix\PhpStan\` → `tools/phpstan/` is a live `autoload-dev` root that is
absent from `phpstan.neon`'s `paths`, the `.php-cs-fixer.dist.php` finder, the
`.githooks/pre-commit` path filter, `.gitattributes`'s `export-ignore` (so
`tools/phpstan/` ships in the composer dist package) and
`DEVELOPMENT_NAMESPACE_PREFIXES` in
`scripts/generate-modular-architecture-production-inventory.php:993-996` (so an
`src/` import of it is not flagged). This stage puts tests there, so closing
these is in scope rather than reported. **Measured before promising: `tools/` is
already clean under PHPStan level 8 and under cs-fixer**, both probed with the
configs temporarily widened, so bringing it under coverage costs no fixes.

## What the tools pin by name, which the previous cost estimate missed

The old cost section counted 12 pinned path literals in the inventory
generator. It missed that **the tools themselves pin these tests as data**.
Full table in
[`measurement/stage-03/pinned-references.md`](measurement/stage-03/pinned-references.md).

- `scripts/directive-audit-controls/Probes.php` carries **119 dot-separated
  `FQN::method` literals** naming the three moving RuleVocabulary tests — 16,
  38 and 65 — out of 363 in the file, plus a `FIXTURE` const pinning
  `AuthoredThresholdForms.php`. The dot spelling is invisible to a backslash
  grep, so a sweep must enumerate spellings, not names.
- `scripts/directive-audit-controls/Suite.php:45-52` is a hardcoded list of 8
  test paths, three of which move.
- `governance/TestSuiteHygiene/namespace-path-allow-list.php` carries two rows
  that must go, and go by re-deriving, never by hand.

**Those literals are individually machine-checked, and the check is loud** —
demonstrated by planting one bogus method name: `stale declaration: … names
"…itNamesEveryFormTheFixtureDeclaresXYZ", which no case in this run carries`.

**But its exit code is not the oracle.** `directives:controls:coverage` is
already red on `main` — exit 1, `2 cases guarded by nothing`, both pre-existing
and unrelated — and it is not part of `composer check`. Exit 1 before, exit 1
after. The oracle is the text: `0 not as declared`, no `stale declaration:`
line, and exactly those two pre-existing rows.

Five further files carry only dead prose mentions (`@see`, a "split off from"
comment). Nothing checks them — confirmed by renaming a fixture away and
watching PHPStan still report `[OK] No errors`. They are documentation debt,
named here so nobody mistakes leaving them for an oversight.

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
  per-suite rows are these six integers, compared as a diff rather than read:

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
- `namespace-path-allow-list.php` has two fewer rows and a `ceiling` lowered to
  match, **by re-deriving with
  `php governance/TestSuiteHygiene/derive-namespace-path-allow-list.php`**, never
  by hand.
- `php scripts/directive-audit-controls.php` — **run directly, not through
  `composer directives:controls`** — passes. The composer script is
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

| #   | Package                                | Moves                                           | Addresses it owns                                                                                                                                         |
| --- | -------------------------------------- | ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P0  | Measurement and this plan              | —                                               | done, committed before P1                                                                                                                                 |
| P1  | The seam, proved, plus `tools/phpstan` | rows 8, 9 + 4 fixtures                          | 1-16, 18-20, 23-26, 31; the two-step plant                                                                                                                |
| P2  | promise-effect                         | rows 1-3                                        | 1, 4, 6, 7, 17, 22, 31; empties `tests/Unit/PromiseEffect/`                                                                                               |
| P3  | directive-audit and its controls       | rows 4, 5, 6, 10 + `AuthoredThresholdForms.php` | 1, 4, 6, 7, 17, 25, 27-29, 31                                                                                                                             |
| P4  | finding-gate                           | row 7                                           | 1, 4, 6, 7, 17, 31                                                                                                                                        |
| P5  | The flat-script tools                  | rows 11-16                                      | 1, 4, 6, 7, 17, 21, 30, 31; empties `tests/Unit/RuleVocabulary/`                                                                                          |
| P6  | The Python tooling tests               | 2 files                                         | `test:cross-tool`'s `-s` paths; empties `tests/Analysis/Evidence/Measurement/Tests/` and `tests/System/TestRunnerConfiguration/Tests/` except its fixture |
| P7  | Documentation                          | —                                               | `CLAUDE.md` corrections; affected READMEs; `CHANGELOG.md`; the five dead prose sites                                                                      |

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
to move; `tests/System/TestRunnerConfiguration/Tests/Fixtures/fake_phpunit.py`,
which moves with P6 as its test's fixture; the five dead prose sites (P7);
`tests/TestSupport/Logging/`, which exists and this stage does not touch; the
pre-existing two `guarded by nothing` rows; the pre-existing dead
`classifyOwner()` prefixes this stage does not create; and the 616 `tests/`
files no witness read.

## `CLAUDE.md` corrections P7 owes

Four, each established here rather than reasoned:

1. The "`<directory>` naming a path that no longer exists — PHPUnit warns, exits
   0, empty suite / **silently**" row is false: exit 2, measured four ways.
2. The inventory-generator row conflates two mechanisms and must be two: the
   scan-scope literal (**silent**) and `TestTree`'s `autoload-dev` derivation
   (**loud**).
3. The `scripts/phpunit-aggregate.py` row names three addresses where there is
   one; the second real address is the Python test's own copy of the tuple.
4. `createIsolatedProject()` is no longer in the governance file — stage 02 split
   it into `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php`.
   The tree diagram also omits `tools/`.

## Ordering

After stage 02, before stage 04. Stage 04's relocation map describes the tree as
it is *before* this stage runs, so the rows for `tests/Unit/PromiseEffect/` and
`tests/Unit/RuleVocabulary/` must be re-derived once this stage lands.
