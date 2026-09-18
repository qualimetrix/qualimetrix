# Stage 04, P5 — the invariant becomes a control

Branch `x30-stage-04-subject-layout`, from `8a25ae74`. Nothing committed.

## What was built

Five files, all in `governance/TestSuiteHygiene/`, an existing group — so no
`<directory>` was added to `phpunit.xml.dist` and no row to
`testSuitePrefixTable()`, and neither file was touched.

| File                                 | What it is                                                                                                     |
| ------------------------------------ | -------------------------------------------------------------------------------------------------------------- |
| `TestSubjectPaths.php`               | the rule and the measurement: owner table from the manifest, path parse, coverage claims, one verdict per file |
| `SubjectPathExceptions.php`          | the three tracked lists: load, split, ceilings, render, derive                                                 |
| `subject-path-exceptions.php`        | the tracked lists themselves, written by the derive                                                            |
| `derive-subject-path-exceptions.php` | the write command                                                                                              |
| `TestPathsNameTheirSubjectTest.php`  | the control, nine cases                                                                                        |

`TestTree.php` was extended, not copied: `parseDeclarations()` now also answers
"what does this file claim to cover", as `covers: {classes, nothing}`. The group's
own principle is that two guards asking the same question of a file must ask one
reader, and there were already three asking two questions; this is the third
question, read in the same parse.

### Why it is shaped this way

**The rule reads the tree and the manifest, and nothing else.**
`relocation-map.csv` is not read anywhere: it records one stage's moves and is
historical the moment this lands. The owner vocabulary comes from
`docs/internal/modular-architecture-manifest.json` — its 37 owners and its 955
declarations with their owners — which is what the spec's script uses too.

**Part 3 is a prefix test, and the prefix is segment-wise.** The spec slices the
covered class's namespace by the owner's string length; this slices by segments
and refuses, loudly, a class whose name does not start with its owner's path.
That refusal has never fired: all 955 declarations satisfy it, measured before the
choice was made, so the two forms agree on this tree and only one of them can go
wrong quietly on the next.

**Parts 1 and 2 have no exception list at all.** Nothing in the tree carries either
verdict — 616 of 616 — so an exception list for them would be a list nobody could
add a row to except by hand. The derive refuses to write while any file carries
one (exit 7, observed under plant 2 below): such a file has no subject to
disagree with, so no list here can hold it, and silently omitting it from all
three lists would be the silent variant of exactly the defect this control is
for.

**The control validates paths against owners, never owners against paths.** Stated
in the docblock of both `TestSubjectPaths` and the control. `Core.Profiler` is a
manifest owner with no `tests/Core/Profiler` directory and no test class beneath
it; that is a question this does not ask, and answering it would mean deciding
that every owner owes the tree a test.

### Decisions, each with the alternative rejected

| Decision                                                                                                                           | Alternative rejected                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ---------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| One tracked file with three named lists, each with its own `ceiling` and `rows`                                                    | Three files and three derive commands: three ceilings that can drift apart in three diffs, for one measurement                                                                                                                                                                                                                                                                                                                                                                                                            |
| List keys are sentences about the file — `declares_no_coverage`, `covers_another_owner`, `remainder_is_not_a_prefix`               | `a` / `b` / `c`: a refusal naming bucket "C" sends the reader to a plan document to find out what it means                                                                                                                                                                                                                                                                                                                                                                                                                |
| Row value is the reason, not just the path — and list A's rows say `declares #[CoversNothing]` or `declares no coverage attribute` | A bare list of paths: a row could then be retired for the wrong reason, which the plan names as the thing the split is for                                                                                                                                                                                                                                                                                                                                                                                                |
| Coverage read from the syntax tree via `TestTree`, file-wide                                                                       | The spec's regex: it cannot see an alias, a grouped attribute, or a second class-like in the file. Measured first — 0 indented `#[CoversClass`, 0 string-form, 0 grouped, 0 `@covers`, 0 `#[CoversMethod]`, and the 4 aliased imports in the tree are in heredoc fixtures — so the two agree here, and the parse is the one that stays right                                                                                                                                                                              |
| `TestTree` gains the question; a second parser was not written                                                                     | A private parse in the new class: a second copy of a map that can drift from the first, which is the defect class this group exists to make loud                                                                                                                                                                                                                                                                                                                                                                          |
| Ceiling is the only budget; no value-budget like the sibling's exit 7                                                              | The sibling budgets on the declared namespace because a namespace survives a move and a path does not. Every row here is keyed on a path, so a value-budget would either be meaningless or would refuse every legitimate file move. **The hole this keeps, named in the docblock and here: retiring one exception and introducing another in the same list leaves the count where it was, so a re-derive would absorb it.** Both refusals still fire until someone re-derives; what is not closed is the re-derive itself |
| `UNJUDGEABLE_PATH = 7` on the derive                                                                                               | Writing the three lists anyway and leaving the unjudgeable file to the control: a derive that writes a partial truth is a tracked file that reads as the whole one                                                                                                                                                                                                                                                                                                                                                        |
| The control caps the lists itself, in addition to the derive                                                                       | Only the derive capping: a control that has never been shown to refuse a ceiling breach is not evidence that the ceiling is enforced at check time                                                                                                                                                                                                                                                                                                                                                                        |
| The bootstrap ceilings 84 / 19 / 4 were hand-written once, with empty rows, and the first derive filled the rows                   | Hand-writing the rows: exactly what the design forbids. The ceilings are hand-edited by construction — that is what makes them decisions                                                                                                                                                                                                                                                                                                                                                                                  |

### Assumptions

- The spec's algorithm is authoritative for membership; where the two forms could
  differ, this reproduces the spec's outcome (including: a file whose every
  coverage claim names a class the manifest does not declare lands on
  `remainder_is_not_a_prefix`, not on `covers_another_owner`, because an
  undeclared claim is not a claim about another owner).
- `tests/**/*Test.php` is `TestTree::testFilesIn('tests')`, which is `find tests
  -name '*Test.php'`: 616 files, the same population the spec walks.

## The derive command and its output

```
php governance/TestSuiteHygiene/derive-subject-path-exceptions.php
```

A write, not a check: exit 4 when it wrote, 5 the scan failed, 6 a list is over
its ceiling, 7 the tree carries a path that names no owner and level, 8 the
tracked file could not be read. It never exits 0.

```
Measured 84 file(s) into declares_no_coverage, ceiling 84.
Measured 19 file(s) into covers_another_owner, ceiling 19.
Measured 4 file(s) into remainder_is_not_a_prefix, ceiling 4.
Written to governance/TestSuiteHygiene/subject-path-exceptions.php.
This was a write, not a check: run the Governance suite to be judged against it.
```

exit 4. Run a second time against its own output, the tracked file is
byte-identical (`diff` empty) — the render is stable and needed no hand edit.

## The independent witness

The spec script from `invariant-shape.md`, run verbatim from the repository root
after the control landed:

```
{'exact': 377, 'prefix': 132, 'A': 84, 'B': 19, 'C': 4} sum 616 of 616
```

exit 0. Set-diffed against the derived lists, per list and in both directions:

| List                            | mine | spec | only mine | only spec |
| ------------------------------- | ---: | ---: | --------- | --------- |
| `declares_no_coverage` / A      | 84   | 84   | —         | —         |
| `covers_another_owner` / B      | 19   | 19   | —         | —         |
| `remainder_is_not_a_prefix` / C | 4    | 4    | —         | —         |
| exact                           | 377  | 377  | —         | —         |
| prefix                          | 132  | 132  | —         | —         |

All five buckets hold the same members, file for file, 616 of 616. Nothing to
report as a disagreement.

## The third witness: nine quotes a human can check

**`declares_no_coverage`** — one that declares nothing nameable, two that declare
nothing at all. For the second kind the fact is an absence, so what is quoted is
every attribute line the file has.

1. `tests/Analysis/Finding/Integration/ChannelCoverageTest.php`, line 94:
   `#[CoversNothing]`
2. `tests/Core/Unit/VersionTest.php`, every `#[` line in the file:
   `14:    #[Test]` and `23:    #[Test]` — no coverage attribute among them.
3. `tests/Analysis/Configuration/Unit/DeadConfigurationKeysTest.php`, every `#[`
   line in the file: `24:    #[Test]` and `25:    #[DataProvider('removedKeys')]`
   — no coverage attribute among them.

**`covers_another_owner`** — the `#[CoversClass]` and the import that says whose
class it is.

4. `tests/Analysis/Policy/Baseline/Functional/BaselineExplainCommandTest.php`
   line 60 `#[CoversClass(BaselineExplainCommand::class)]`, line 45
   `use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;` —
   filed under `Analysis.Policy.Baseline`, covers `Infrastructure.Console`.
5. `tests/Analysis/Policy/Inline/Unit/StandardOverrideValidatorTest.php` line 13
   `#[CoversClass(StandardOverrideValidator::class)]`, line 11
   `use Qualimetrix\Analysis\Finding\Rule\Override\StandardOverrideValidator;` —
   filed under `Analysis.Policy.Inline`, covers `Analysis.Finding`.
6. `tests/Infrastructure/Console/Unit/RuleOptionKeyDoorSymmetryTest.php` line 39
   `#[CoversClass(RuleOptionsFactory::class)]`, line 11
   `use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;` —
   filed under `Infrastructure.Console`, covers `Analysis.Finding`.

**`remainder_is_not_a_prefix`** — the `#[CoversClass]` and the import showing the
`Contract` segment the path drops.

7. `tests/Analysis/Run/Unit/Collection/FileProcessingResultTest.php` line 24
   `#[CoversClass(FileProcessingResult::class)]`, line 14
   `use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;` —
   path `Collection`, actual `Contract/Collection`.
8. `tests/Analysis/Run/Unit/Configuration/RunConfigurationScopeTest.php` line 19
   `#[CoversClass(RunConfiguration::class)]`, line 11
   `use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;` —
   path `Configuration`, actual `Contract/Configuration`.
9. `tests/Analysis/Run/Unit/Pipeline/AnalysisCoverageTest.php` line 16
   `#[CoversClass(AnalysisCoverage::class)]`, line 11
   `use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;` —
   path `Pipeline`, actual `Contract/Pipeline`.

Each quote matches the row the derive wrote, verbatim: lines 25, 40, 68, 119, 128,
131, 137, 138 and 139 of `subject-path-exceptions.php`.

## Observed to refuse, eight times

Each planted alone, run as
`vendor/bin/phpunit --testsuite=Governance --filter TestPathsNameTheirSubjectTest --no-coverage`,
each reverted immediately, `git status --porcelain` checked clean after each.
Nothing else ran while a plant was in the tree.

**1. A test file under `tests/Reporting/Formatter/Unit/`** — exit 1.

```
1) Qualimetrix\Governance\TestSuiteHygiene\TestPathsNameTheirSubjectTest::itFindsNoTestFileWhosePathNamesNoManifestOwner
A test file's path names the subject that owns it, and these name no owner the manifest has.
There is no exception list for this: move the file under its owner, or the segments before its
level are a subject nobody declared:
tests/Reporting/Formatter/Unit/PlantedOwnerTest.php Reporting/Formatter is before its Unit segment, and no manifest owner is spelled that way
```

**2. A test file with no level segment** (`tests/Reporting/PlantedLevelTest.php`)
— exit 1.

```
1) Qualimetrix\Governance\TestSuiteHygiene\TestPathsNameTheirSubjectTest::itFindsNoTestFileThatNamesNoSingleAnalysisLevel
Every test file carries exactly one Unit, Integration or Functional segment, directly below its
owner. A file with none is in no suite the owner declares; a file with two is in whichever the
reader guesses:
tests/Reporting/PlantedLevelTest.php names 0 of Unit, Integration, Functional, and a test file names exactly one
```

The derive was run under the same plant, because no list can hold such a file and
a derive that wrote the other three lists anyway would publish a partial truth —
**exit 7**, and the tracked file byte-unchanged (same md5 before and after):

```
1 test file(s) carry a path that names no owner and level, so nothing was written.
No list here can hold one: an exception is a path whose subject disagrees with it, and these
have no subject to disagree with. Move the file, or fix the segment:
tests/Reporting/PlantedLevelTest.php names 0 of Unit, Integration, Functional, and a test file names exactly one
```

**3. A real owner and level whose remainder invents a segment** —
`tests/Reporting/Unit/Formatter/Whatever/` covering
`Reporting\Formatter\Html\HtmlDebtCalculator`. Exit 1, two refusals.

```
1) …::itFindsNoPathDisagreeingWithItsSubjectThatTheListDoesNotCarry
1 test file(s) belong on remainder_is_not_a_prefix and are not on it.
The path below the level must be a prefix of where the covered class actually sits. These invent a
segment their subject does not have, or skip one in the middle — which a reader scanning the tree
does not notice. Rename the directory to the subject's own, or file the test flat.
Do not add a row to governance/TestSuiteHygiene/subject-path-exceptions.php by hand:
tests/Reporting/Unit/Formatter/Whatever/PlantedInventedTest.php Formatter/Whatever against Formatter/Html

2) …::itKeepsEveryExceptionListUnderItsCeiling
remainder_is_not_a_prefix measures 5 file(s) and admits 4
```

This is the one a two-part control misses: the owner is real, the level is real,
the path is wrong.

**4. A file whose path owner differs from every `#[CoversClass]` owner, not on the
list** — `tests/Reporting/Unit/` covering
`Analysis\Finding\RuleConfiguration\RuleOptionsFactory`. Exit 1.

```
2) …::itFindsNoTestFileCoveringAnotherOwnerThatTheListDoesNotCarry
1 test file(s) belong on covers_another_owner and are not on it.
A test filed under one owner whose every coverage claim names another is filed under a subject it
does not test. Move it to the owner it covers, or cover the owner it is filed under.
Do not add a row to governance/TestSuiteHygiene/subject-path-exceptions.php by hand:
tests/Reporting/Unit/PlantedForeignTest.php is filed under Reporting and covers only Analysis.Finding
```

and, in the same run, `covers_another_owner measures 20 file(s) and admits 19`.

**5. A file whose remainder drops an interior segment, not on the list** —
`tests/Analysis/Run/Unit/Collection/` covering
`Analysis\Run\Contract\Collection\SuccessfulFileProcessing`. Exit 1.

```
1) …::itFindsNoPathDisagreeingWithItsSubjectThatTheListDoesNotCarry
tests/Analysis/Run/Unit/Collection/PlantedInteriorTest.php Collection against Contract/Collection
```

and `remainder_is_not_a_prefix measures 5 file(s) and admits 4`.

**6. A file with no `#[CoversClass]` added beyond ceiling 84** —
`tests/Reporting/Unit/PlantedSilentTest.php`. Exit 1.

```
1) …::itKeepsEveryExceptionListUnderItsCeiling
1 exception list(s) grew past the ceiling they carry.
Deriving may only ever lower a ceiling, so this cannot be fixed by re-running the derive command:
fix the file, or raise that number by hand in governance/TestSuiteHygiene/subject-path-exceptions.php and say in the commit why the tree is allowed to
get worse:
declares_no_coverage measures 85 file(s) and admits 84

2) …::itFindsNoUncoveredTestFileTheListDoesNotCarry
tests/Reporting/Unit/PlantedSilentTest.php declares no coverage attribute
```

The derive was run under the same plant, to check that the cure is not to re-run
it — **exit 6**, nothing written:

```
The tree carries 85 file(s) that belong on declares_no_coverage and governance/TestSuiteHygiene/subject-path-exceptions.php admits 84, so nothing was written.
This command cannot absorb a new exception: file the test under the subject it covers, or
raise that ceiling by hand and say in the commit why the tree is allowed to get worse.
```

**7. A row deleted while its file still needs it** — the
`tests/Core/Unit/VersionTest.php` row removed from `declares_no_coverage`. Exit 1.

```
1) …::itFindsNoUncoveredTestFileTheListDoesNotCarry
1 test file(s) belong on declares_no_coverage and are not on it.
…
tests/Core/Unit/VersionTest.php declares no coverage attribute
```

**8. A row kept after its file stopped needing it** — the row left in place while
`tests/Core/Unit/VersionTest.php` was given a real
`#[CoversClass(Version::class)]`, so it is no longer an exception. Exit 1. This is
the row two drafts asserted in prose and planted zero times.

```
1) …::itCarriesNoStaleExceptionRow
1 row(s) in governance/TestSuiteHygiene/subject-path-exceptions.php describe an exception that is not there any more.
Re-derive: a row that describes nothing hides the next one that would.
tests/Core/Unit/VersionTest.php is allowed to be on declares_no_coverage as "declares no coverage attribute", and no longer is
```

Note that plant 8 was made by editing a *test file*, not the list: the list row
stayed exactly as the derive wrote it, and the tree moved out from under it. That
is the shape the refusal is for.

## Counts

N = **9** cases, measured by the runner's own oracle filtered to the new class:

```
vendor/bin/phpunit --testsuite=Governance --no-coverage \
  --exclude-group=benchmark --exclude-group=live-freshness --list-tests | grep -c '^ - '
```

| Suite          | before  | after   |
| -------------- | ------: | ------: |
| Unit           | 6705    | 6705    |
| Integration    | 383     | 383     |
| Functional     | 152     | 152     |
| Infrastructure | 1029    | 1029    |
| Tooling        | 179     | 179     |
| **Governance** | **748** | **757** |

748 + 9 = **757**. The other five are unchanged at 6705 / 383 / 152 / 1029 / 179.

**One thing to know about the 748.** There are two numbers, and they differ by the
two `live-freshness` cases the runner excludes and a bare invocation does not.
Runner's oracle: **748 before, 757 after**. Bare `--list-tests`: **750 before, 759
after**. The plan's 748 is the runner's number and is the one used here;
`test-phpunit-suites.txt`, which the generator writes from a bare invocation,
therefore moves 750 → 759.

## Definition of Done

| #   | Item                                                                                                       | Result                                                                                                                    |
| --- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------: |
| 1   | Green over the whole population; the derive reproduces 84 / 19 / 4 with no hand edit                       | `phpunit --filter TestPathsNameTheirSubjectTest` **exit 0**, `OK (9 tests, 27 assertions)`; derive **exit 4**, idempotent |
| 2   | The spec script still prints 377 / 132 / 84 / 19 / 4 summing to 616 of 616, and the lists hold its members | script **exit 0**, all five buckets set-identical                                                                         |
| 3   | Nine human-checkable quotes                                                                                | above, each matching its derived row                                                                                      |
| 4   | Observed to refuse eight times, each planted, reverted, quoted                                             | eight plants, each **exit 1**, plus the derive's **exit 6** under plant 6                                                 |
| 5   | Governance is 748 + N, the other five unchanged                                                            | N = 9, total **757**; 6705 / 383 / 152 / 1029 / 179 unchanged                                                             |
| 6   | `composer architecture:check` and `composer check`                                                         | **exit 0** and **exit 0**                                                                                                 |
| 7   | `git status --porcelain` clean but for the intended changes                                                | five new files in `governance/TestSuiteHygiene/`, `TestTree.php` modified, four regenerated artifacts                     |

The regenerated artifacts are
`test-ownership.tsv` (5 rows), `test-phpunit-discovery.txt` (9 lines),
`test-phpunit-suites.txt` (the Governance line) and `test-topology.tsv`
(`mapped_artifacts` 921 → 926, `phpunit_classes` 725 → 726, `phpunit_ids`
9198 → 9207). `fixture_directories` and `orphan_dispositions` did not move, so
`ModularArchitectureGovernanceIntegrationTest`'s two hardcoded counts still hold —
checked, not assumed.

## Deviations

1. **The Governance baseline in the brief is 748; the tree's bare `--list-tests`
   says 750.** Neither is wrong: 748 is the runner's oracle from
   `prediction.md`, 750 is the bare form. 748 has been the runner's number since
   `c49fc0b4`, well before this stage, and this package used it. Stated so the
   next reader does not rediscover the `live-freshness` gap.
2. **`TestTree.php` was modified.** The brief did not name it; it names
   `phpunit.xml.dist` and the generator as the files that would send the package
   back, and neither was touched. The change is additive — one new key in the
   parse result, two new constants — and the four existing callers read keys that
   did not move.
3. **`TestTree`'s class docblock was corrected in the same change** — it said
   "three guards ask the same two questions", which stopped being true the moment
   a fourth guard and a third question landed. This was the last edit; `cs-check`
   and PHPStan on the group and the whole Governance suite (759 bare / 757 under
   the runner) were re-run after it, all exit 0.
4. **A second derive exit code was added that the sibling does not have**
   (`UNJUDGEABLE_PATH = 7`). The sibling's 7 is its value-budget refusal, which
   this design does not have; the number was reused for the refusal this design
   does have, so the two commands still share the shape "4 wrote, 5 could not
   measure, 6 over the ceiling, 8 could not read the list".
