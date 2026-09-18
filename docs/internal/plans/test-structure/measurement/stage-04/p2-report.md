# Stage 04, package P2 — the Reporting-owned files move

Executed against `main` @ `3213b905`, the commit P2 starts from. Nothing is
committed; the tree carries 51 renames and 9 modifications.

No PHPUnit test case was added or removed. No test file outside the 51 map rows
moved.

**One Definition-of-Done item is not met as written, and it cannot be met by any
package after the first.** `move-oracle.py` exits 1 with 104 disagreements, every
one of them naming a **P1** row. See "The judge" below: the defect is measured,
attributed line by line, and the package is proved clean under a repaired copy of
the judge that the tracked file never saw. The judge was not edited.

## What changed

**The 51 moves.** Every row of `relocation-map.csv` whose `owner` is exactly
`Reporting` — re-derived by command against the map on this tree, not read from
the brief — moved with `git mv` to its recorded `target`, and each file's
`namespace` declaration was rewritten to the PSR-4 implication of its new path.

Three numbers, each derived here rather than recalled:

| Number                                | Derivation                                                                                                                                                | Result                                                                                      |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Rows, and how many are test classes   | `csv.DictReader(relocation-map.csv)`, `owner == 'Reporting'`, then `target.endswith('Test.php')`                                                          | **51**, of which **50** are test classes                                                    |
| Generator constants naming them       | every `const X = [...]` block matched against the 51 current paths **and** against the 51 declared FQCNs in both spellings (single- and double-backslash) | `LEGACY_UNMOVED` ×50, `P6_D_REPORTING_TEST_PATHS` ×1; **no FQCN anywhere in the generator** |
| `<directory>` entries to remove / add | each declared entry's tracked-file count under the post-move path set, computed from `git ls-files` with the map applied                                  | remove **4**, add **2**                                                                     |

The support row, `tests/Unit/Reporting/Formatter/Sarif/Support/StubChannelPresentation.php`,
is the 51st and is not in `LEGACY_UNMOVED` — confirmed by the constant sweep above,
which returns 50 and not 51. The allowance therefore goes **60 → 10**, which is what
the judge prints.

The per-suite origin is Unit 48, Functional 2 and Integration 1; the targets keep
those levels exactly, which is why the package is suite-neutral.

**Directories emptied and removed from disk** — 19, of which 4 were declared in
`phpunit.xml.dist`: `tests/Functional`, `tests/Functional/Reporting`,
`tests/Reporting/FindingProjection{,/Unit}`,
`tests/Reporting/Formatter{,/Sarif{,/Integration},/Suppressed{,/Unit}}`, and the
ten under `tests/Unit/Reporting/`.

**`scripts/generate-modular-architecture-test-inventory.php`** — 50 `LEGACY_UNMOVED`
rows deleted, the one `P6_D_REPORTING_TEST_PATHS` literal repointed, and
`testSuitePrefixTable()` given the same −4 / +2 as `phpunit.xml.dist`. See D1–D3.

**`phpunit.xml.dist`** — the same −4 / +2.

**Four reference lines in four non-moving files, plus six lines inside moved
files**, closed; see "The reference channels".

**`scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`** —
its no-suite probe was planted at `tests/Reporting/Functional`, the directory this
package creates and declares. See D5; this is the one consumer no sweep in the
brief would have found.

**The generated artifacts** were regenerated: `test-ownership.tsv` and
`test-phpunit-discovery.txt`. `test-phpunit-suites.txt` is byte-identical, which
is the suite-neutrality claim showing up in an artifact rather than in prose.

## Decisions

Each names the alternative rejected and the command that checks it.

**D1. The `P6_D_REPORTING_TEST_PATHS` literal follows the rename; it is not
retired.** It now spells `tests/Reporting/Unit/FindingProjection/FindingProjectorTest.php`.
Moving the entry to `RETIRED_PATH_ASSERTIONS` was rejected for P1's D1 reason: the
guard's own refusal text prescribes the choice ("Follow the rename, or move the
entry to RETIRED_PATH_ASSERTIONS with the reason it is gone") and the file is not
gone. Checked by: `composer architecture:check` exit 0 — `assertPathLiteralsResolve()`
runs at startup and would `fail()` naming the constant and the absent path, and
the `RETIRED_PATH_ASSERTIONS` arm would `fail()` the opposite way had the entry
been retired while the file exists.

**D2. `P6_D_REPORTING_TEST_PATHS` keeps its two consumers, which are now
unreachable.** After the move its single path is a `tests/{owner}/{level}/…` test
class, so `isTestClassPath()` answers first in both `classifyOwner()` (line 930)
and `dispositionFor()` (line 1521); neither `in_array()` branch can be taken again.
Deleting the constant and its branches was rejected for P1's D2 reason, and P4's
section of the plan names this exact constant as its own sweep. Recorded as a
finding. Checked by: the inventory row for the moved file reports
`permanent` / `Retain at the materialized subject-owned path.`, which is the parse
answering, not the constant.

**D3. The prefix table loses `tests/Functional/` as well as the three Reporting
entries.** `assertSuiteClassifierAgreesWithPhpunit()` reconciles the table and
`phpunit.xml.dist` in both directions, so a literal kept after its `<directory>`
is removed is refused by name. The alternative — keeping the literal because
`currentSuite()` also classifies pre-migration paths handed in through
`--classification-probe=` — was rejected: the backward arm makes it not a choice.
Checked by: `composer architecture:check` exit 0, which runs that assertion.

**D4. Prose and declarations that merely share a basename are not edited.**
`governance/SymbolVocabulary/SymbolTypeDeclarationPositionCensusTest.php:15`
("Split off from `MetricsJsonFormatterTest`") is a bare basename, which does not
change (P1's D4). `governance/Channel/SarifRuleDescriptorCoverageTest.php:40` is a
governance class that happens to carry the same basename as a moved test — a
collision, not a reference. `scripts/generate-modular-architecture-test-inventory.php:1643`
names `SuppressedFormatterTest.php` in a comment about a historical defect shape,
also basename-only. Checked by: the word-anchored bare-name sweep, which returns
these three and nothing else outside the generated artifacts.

**D5. The no-suite control is repointed at `tests/Reporting/GraphProjection/Functional`,
not deleted and not weakened.** The control plants a probe test class under a
directory `currentSuite()` cannot place and asserts the generator refuses with
`classified as suite "none"`. Its address was `tests/Reporting/Functional` — which
this package creates and declares, so the control began failing on its *first*
assertion (`assertDirectoryDoesNotExist`) rather than reaching its refusal. Three
alternatives were rejected:

- *Delete the control* — it is the only witness that a silently unrun test class
  is refused.
- *Weaken it to skip when the directory exists* — that is a control that stops
  asserting exactly when the thing it guards has changed.
- *Derive the probe directory at runtime* — a larger rewrite of a file this
  package does not own the design of, and one whose derivation could itself go
  vacuous.

The replacement must be a directory that is declared by no `<testsuite>` **and**
still parses to one of the 37 manifest owners with a level segment, or the
generator refuses it earlier for a different reason and the control passes without
exercising suite classification at all. `Reporting/GraphProjection` is a manifest
owner, `tests/Reporting/GraphProjection/Functional/` appears in no prefix-table
row, and no row of `relocation-map.csv` targets anything beneath it (checked:
the target list filtered on that prefix is empty). The docblock now says that
registering this directory disarms the control, so the next package that adds a
`<directory>` is told rather than left to rediscover it. Checked by:
`vendor/bin/phpunit --testsuite=Tooling --filter=ModularArchitectureGeneratorRefusalTest`
exit 0, and by the observation that it was red before the repoint — the failure
text is quoted under "Deviations".

**D6. The `{@see}` at `governance/Channel/SarifRuleDescriptorCoverageTest.php:36`
and the three like it are repointed, not removed.** Same call as P1's D5: the
class each names is real and is one of the 51.

## Assumptions

- **A1.** The map is the authority for which 51 files move and where they land.
  The partition was re-derived by command against `relocation-map.csv` on this
  tree; the map's `current` and `target` were used verbatim.
- **A2.** PSR-4 for tests is `Qualimetrix\Tests\ => tests/`, one namespace segment
  per path segment. Verified empty-handed first: all 51 files agreed with their
  *pre-move* path under that rule, and none of the 51 appears in
  `governance/TestSuiteHygiene/namespace-path-allow-list.php` — so the rewrite
  carries no pre-existing namespace defect and introduces none. The judge
  re-checks the post-move side independently.
- **A3.** Depth-sensitive path arithmetic survives. Exactly one of the 51 contains
  `__DIR__` or `dirname(`: `SarifSchemaValidationTest.php`, with
  `__DIR__ . '/../../../../Fixtures/Schema/sarif-2.1.0.schema.json'`. Its path
  segment count is 6 before and 6 after, so the four `..` still land on `tests/`.
  Measured per file, not inferred from the map's shape.
- **A4.** The support file's inventory row was verified against the tree rather
  than taken from the brief: after regeneration,
  `tests/Reporting/Support/StubChannelPresentation.php` reports `subject_owner`
  `Reporting` and `target_path` equal to itself, which is what the brief claimed
  the surviving ladder already returns and what the judge's arm 3 checks.

## Deviations from the plan text

**V1.** The plan's P2 file set is "rows whose `owner` is `Reporting` — 51 — plus
the constants naming them, which is `P6_D_REPORTING_TEST_PATHS` ×1". Two files
outside that set are edited:

- `scripts/modular-architecture/tests/ModularArchitectureGeneratorRefusalTest.php`
  (D5). It names no moved file and no moved class; it names a *directory this
  package creates*. Every sweep the brief prescribes — declared FQCN, PHP-escaped
  FQCN, bare basename, current path literal — is keyed on things that exist
  before the move, so none of them can reach it. It was found by `composer check:code`,
  which is the only reason this package did not ship a red tree. Its failure,
  verbatim:

  ```
  1) Qualimetrix\ModularArchitecture\Tests\ModularArchitectureGeneratorRefusalTest::itFailsWhenAPhpunitTestClassHasNoConfiguredSuite
  Failed asserting that directory ".../tests/Reporting/Functional" does not exist.
  ```

  **The generalisation is the finding:** a move package must sweep the directory
  prefixes it *fills* as well as the ones it empties. P1 recorded the emptied-prefix
  half (its V2, `src/Infrastructure/README.md` naming `tests/Infrastructure/Unit/`);
  the filled half is new here, and it is the more dangerous of the two because
  nothing about it is stale — the literal was correct until the moment the package
  made it wrong. **The prescribed sweep was then run rather than only recommended** —
  `git grep -n -F` over `tests/Reporting/{Functional,Integration,Support,Unit}` and
  the control's new address, repository-wide by content, excluding plans, ADRs, the
  generated artifacts and the two files this package rewrites wholesale. It returns
  only the repointed control's own three lines and nothing else, so this channel has
  exactly one member here. That matters because `composer check:code`, which is how
  the member was actually found, excludes the `live-freshness` and `benchmark` groups
  — a control in either would have stayed silent.

- Four `use` statements and two docblock path literals **inside** the 51 moved
  files. Rewriting a file's own `namespace` does not rewrite a `use` importing a
  different moved class, and both sweeps in the brief are phrased over carriers
  outside the set. See "The reference channels".

**V2.** DoD item 1 is not met as written. See "The judge".

## The reference channels, and how they were derived

Five sweeps over the whole tracked tree by content (`git grep`, no file-type
restriction), with `docs/internal/plans/**` and `docs/adr/**` excluded as history
and `docs/internal/generated/**` excluded as regenerated output:

1. **Declared FQCN** — `namespace` + class name read from each of the 51, then
   `git grep -l -F <FQCN>`.
2. **PHP-escaped FQCN** — the same string with every `\` doubled.
3. **Bare class basename**, word-anchored (`git grep -l -F -w`).
4. **Current path literal** — `git grep -l -F <path>`.
5. **Dangling-FQCN detector** — every `Qualimetrix\Tests\…` name appearing
   anywhere in the tree, in either spelling, resolved back to a file path and
   reported when no such file exists. This is the sweep that reaches the
   **earlier-epoch channel** P1 named, because it does not need to know the
   current name of anything.

Sweeps 1–4 were first run with the 51 carriers excluded, which is how the brief
phrases them; that run returns four lines and **misses six**. Re-running with the
51 included as carriers is what found the intra-set references. Both halves are
listed:

| File                                                                                         | Line | Shape                          | Found by         |
| -------------------------------------------------------------------------------------------- | ---- | ------------------------------ | ---------------- |
| `tests/Infrastructure/Console/Functional/Command/CheckCommandConfigurationErrorGateTest.php` | 22   | `{@see}` FQCN                  | 1, 3             |
| `tests/Infrastructure/Console/Functional/Command/CheckCommandProjectScopedGateTest.php`      | 23   | `{@see}` FQCN                  | 1, 3             |
| `governance/Channel/ChannelPresentationCoverageTest.php`                                     | 79   | `{@see}` FQCN                  | 1, 3             |
| `governance/Channel/SarifRuleDescriptorCoverageTest.php`                                     | 36   | `{@see}` FQCN                  | 1, 3             |
| `tests/…/Functional/Formatter/JsonShapePreservationTest.php`                                 | 36   | `use` of a moved support class | 1, 3 (intra-set) |
| `tests/…/Unit/Formatter/ArchitectureViolationSmokeTest.php`                                  | 60   | `use` of a moved support class | 1, 3 (intra-set) |
| `tests/…/Unit/Formatter/Sarif/SarifFormatterPosixSeparatorTest.php`                          | 19   | `use` of a moved support class | 1, 3 (intra-set) |
| `tests/…/Unit/Formatter/Sarif/SarifSchemaValidationTest.php`                                 | 21   | `use` of a moved support class | 1, 3 (intra-set) |
| `tests/Reporting/Support/StubChannelPresentation.php`                                        | 15   | path literal in a docblock     | 4 (intra-set)    |
| `tests/…/Unit/Formatter/Sarif/SarifFormatterTest.php`                                        | 35   | path literal in a docblock     | 4 (intra-set)    |

**The earlier-epoch channel has no member naming a file this package moves.**
Sweep 5 returns 22 distinct dangling `Qualimetrix\Tests\…` names, carried by three
files: 17 by `governance/TestSuiteHygiene/namespace-path-allow-list.php`, whose rows
record the *allowed* namespace of a file whose namespace deliberately does not follow
its path; 4 by the generator, which are the `P6_RENAMED_TEST_IDS` /
`P6_LIVE_ADDED_TEST_IDS` halves P1 already recorded; and 1 by a fixture-namespace
constant in `FailClosedModularTopologyIntegrationTest.php`. Not one carries a basename
of the 51, in either their pre- or post-move spelling. That is a measured negative, not an absence of looking.

Nothing was found in `.gitattributes`, `.githooks/pre-commit`, `.dockerignore`,
`scripts/init-environment.sh`, `phpstan.neon`, `.php-cs-fixer.dist.php` or
`composer.json`: no test *root* moves here. The mover's `createIsolatedProject()`
copy list was checked by reading it — it copies the roots `tests`, `governance`,
`tools` and `src` wholesale rather than enumerating directories inside them, so
removing `tests/Functional` does not reach it.

After the moves, sweeps 1, 2 and 4 re-run against each file's **pre-move** FQCN
read from `3213b905` return **0** hits repository-wide outside plans and ADRs.

## `phpunit.xml.dist` — the derivation and the fresh-clone proof

The set is computed, not recalled. For every declared `<directory>`, the count of
tracked files beneath it was taken before and after applying the map's Reporting
partition to `git ls-files`:

```
tests/Unit                                 48 -> 7      stays
tests/Reporting/Unit                        3 -> 50     stays
tests/Reporting/FindingProjection/Unit      6 -> 0      REMOVE
tests/Reporting/Formatter/Suppressed/Unit   1 -> 0      REMOVE
tests/Reporting/Formatter/Sarif/Integration 1 -> 0      REMOVE
tests/Functional                            2 -> 0      REMOVE
```

No other declared entry's count changes. **Added 2**: `tests/Reporting/Functional`
(2 files) and `tests/Reporting/Integration` (1 file). Those two ancestors, together
with the already-declared `tests/Reporting/Unit`, cover each of the 50 moved test
classes under **exactly one** declared entry — checked by counting covering entries
per target, which is 1 for all 50. Declaring the leaves
(`tests/Reporting/Functional/Formatter`, `tests/Reporting/Integration/Formatter/Sarif`)
instead was rejected: more addresses, each able to go stale, for no coverage gain.

The support file at `tests/Reporting/Support/StubChannelPresentation.php` is under
no declared entry, by design — it is not a test class, PHPUnit need not discover
it, and Composer's `autoload-dev` PSR-4 root loads it. Its P3 analogue,
`tests/Analysis/Finding/RuleConfiguration/Support/FromArrayKeyReader.php`, already
sits outside every declared entry today, so this is the tree's existing shape and
not a new one.

Each of the six edits was made in both places. `assertSuiteClassifierAgreesWithPhpunit()`
reconciles `testSuitePrefixTable()` and `phpunit.xml.dist` in both directions and
runs inside `composer architecture:check`, which is exit 0.

**A fresh clone of this commit runs**, proved over *every* declared entry rather
than over the six that were touched:

```
sed -n 's/.*<directory>\(.*\)<\/directory>.*/\1/p' phpunit.xml.dist | sort -u |
  while read -r d; do
    printf '%s %s %s\n' "$(git ls-files "$d" | wc -l)" "$([ -d "$d" ] && echo present || echo ABSENT)" "$d"
  done
```

79 distinct declared entries; every one is `present` on disk and every one has
`git ls-files` ≥ 1. The second column is the fresh-clone half: `git ls-files`
reads the index, which is exactly what a clone of this commit would materialise,
so an entry that git tracks nothing under is an entry that would be absent there.
Eighteen entries track exactly one file; none tracks zero.

## The judge

```
php scripts/generate-modular-architecture-test-inventory.php                     # exit 0
python3 .../move-oracle.py --package=P2 --base=3213b905 --max-failures=0         # exit 1
```

`package P2: 51 rows, allowance now 10` — both numbers as expected — then **104
disagreements**.

**All 104 are P1's, and this is asserted rather than eyeballed.** Every
disagreement line was parsed back into (arm, path) and the path sets compared
against the map's three partitions:

| Arm                                            | Lines | Path set                            |
| ---------------------------------------------- | ----: | ----------------------------------- |
| 2 — "moved, but it belongs to another package" | 52    | **exactly** P1's 52 `current` paths |
| 4 — "left LEGACY_UNMOVED early"                | 52    | **exactly** P1's 52 `current` paths |
| any other shape                                | 0     | —                                   |

Intersection with P2's 51 rows: empty. Intersection with P3's 11 rows: empty.

**The defect is structural, not incidental.** `others` is every row of the map
outside the package under test, and both arms assume those rows are still at their
pre-move paths with their allowance rows intact. That is true only for the *first*
move package. P1 has run and committed, so its 52 files are no longer tracked at
`current` (arm 2 fires) and its 52 allowance rows are gone (arm 4 fires). No P2
edit can change either, and no P3 run will be able to either — it will inherit 103
of these plus P2's own 51/50.

**The package is clean under every arm once those two conditions are corrected.**
The tracked judge was not edited. A wrapper at
`scratchpad/run-fixed-judge.py` loads the tracked source, applies three text
substitutions in memory and executes it with `__file__` still pointing at the
tracked path so its `REPOSITORY_ROOT` arithmetic is unchanged:

- `others` becomes `{current: target}` rather than a set of currents;
- arm 2 fires only when **neither** the `current` nor the map `target` is tracked;
- arm 4 fires only when the path is still tracked at `current`.

Result: `package P2: 51 rows, allowance now 10` / `agreed`, **exit 0**. So arms 1,
3, 5, 6 and the stray-rename arm all pass on this tree; the two repaired arms lose
nothing P2 needs, because the stray-rename arm (`git diff --name-status -M <base>`
against the working tree) independently covers what arm 2 exists for, and arm 1
independently covers this package's own rows in both directions.

`git status --porcelain docs/internal/plans/` is empty: the judge, the map and
every plan document are untouched.

**Proposed fix, for the orchestrator to make at the judge** — the three
substitutions above, verbatim, are in the wrapper.

## Definition of Done

| #   | Item                                                              | Result                                                                                                                                                                         |
| --- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | `php scripts/generate-modular-architecture-test-inventory.php`    | **exit 0** — 921 artifacts, 120 fixture directories, 725 classes, 9198 cases                                                                                                   |
| 1   | `move-oracle.py --package=P2 --base=3213b905`                     | **exit 1**, 104 disagreements, **all 104 attributable to P1** — see "The judge". Repaired copy: **exit 0**, `agreed`                                                           |
| 2   | The six per-suite counts                                          | **6705 / 383 / 152 / 1029 / 179 / 748** — exact, all six identical to P1's row                                                                                                 |
| 3   | A fresh clone runs: every declared `<directory>` present, tracked | **79 / 79** present with ≥ 1 tracked file                                                                                                                                      |
| 4   | `composer architecture:check`                                     | **exit 0**                                                                                                                                                                     |
| 5   | `composer check:code`                                             | **exit 0** (run with nothing else touching the tree)                                                                                                                           |
| 5+  | `composer check:artifacts`                                        | **exit 0** — not in the brief's DoD; run because P2 is the only move package that edits `phpunit.xml.dist`, and AGENTS.md names this group as what a config change invalidates |
| 6   | `git status --porcelain` / `git diff --stat -M HEAD`              | 51 `R`, 9 `M`, this report untracked; 60 files changed, 815 insertions, 862 deletions, all 51 recorded as renames                                                              |

Item 2, measured with the runner's own exclusions:

```
for S in Unit Integration Functional Infrastructure Tooling Governance; do
  echo -n "$S "; vendor/bin/phpunit --testsuite=$S --no-coverage \
    --exclude-group=benchmark --exclude-group=live-freshness --list-tests | grep -c '^ - '
done
```

| Suite          | Expected | Measured |
| -------------- | -------: | -------: |
| Unit           | 6705     | 6705     |
| Integration    | 383      | 383      |
| Functional     | 152      | 152      |
| Infrastructure | 1029     | 1029     |
| Tooling        | 179      | 179      |
| Governance     | 748      | 748      |

Functional stays at 152 although `tests/Functional` ceases to exist: its two files
keep their level and change only their owner root.

Item 4 verbatim: `Checked modular-architecture governance: 955 declarations, 37
semantic-owner layers, 0 seams, 73 exact internal grants -> 13 coarse edges.` and
`Checked 921 artifacts, 120 fixture directories, 725 PHPUnit classes, and 9198
expanded cases.`

Item 5, the six suite results inside the aggregate: Unit `OK (6705 tests, 16633
assertions)`, Integration `OK (383, 2199)`, Functional `OK (152, 486)`,
Infrastructure `1029 tests, 3529 assertions, 1 skipped`, Tooling `OK (179, 39108)`,
Governance `OK (748, 15503)`, plus the JS tests and both cross-tool suites.

Item 6: the nine modifications are the generator, the two changed generated
artifacts, `phpunit.xml.dist`, the four reference carriers outside the move set,
and the repointed refusal control. `test-phpunit-suites.txt` is unchanged.

`composer check:code` was run twice. The first run was red on the single control
of V1 and is the evidence that the repoint was needed; the second, after the
repoint and a regeneration, is exit 0. Both were run with nothing else touching
the tree.

## What the judge does not check, measured rather than assumed

- **Arm 6 (the old-FQCN sweep) cannot see a PHP-escaped literal**, as P1 measured.
  Compensated here by sweeps 2 and 5, which return nothing for any of the 51.
- **Arm 4 says nothing about a filled directory.** Nothing in the judge looks at
  `phpunit.xml.dist`, at `testSuitePrefixTable()`, or at any address that becomes
  wrong *because* a package created something. The whole `<directory>` half of
  this package — and V1's control — is checked by `composer architecture:check`
  and `composer check:code`, never by the judge. A package that got the six
  `<directory>` edits wrong in a way PHPUnit tolerates would still read `agreed`.
- **Nothing in the judge checks that a row *outside* the package stayed put in the
  inventory.** Arm 3 reads only `mine`. A prefix-table or `<directory>` edit can
  reclassify a file this package never touched — between `none` and a suite inside
  the TSV — without moving it between PHPUnit suites, so the six counts are blind to
  it too. Checked here separately:
  `git diff --numstat HEAD -- docs/internal/generated/modular-architecture/test-ownership.tsv`
  is exactly `51 51`, one line changed per moved file and no other.
  `test-phpunit-discovery.txt` is `688 688`, and a sorted set difference of the two
  revisions is 1376 lines of which **0** fail to name Reporting — that is the moved
  test cases' fully qualified ids being renamed and nothing else.
- **The repaired arm 2 is weaker than the original by exactly one case:** a file of
  another package moved *to its own recorded target* ahead of schedule now passes.
  The stray-rename arm still names it, because that arm reads
  `git diff --name-status -M <base>` against the working tree and compares the
  rename set to this package's rows.

## Findings, recorded rather than acted on

- **`move-oracle.py` can only be run for the first move package.** The measurement,
  the attribution and the three-substitution fix are in "The judge". P3 inherits a
  strictly worse version of this, measured now rather than projected:
  `move-oracle.py --package=P3 --base=3213b905` on this tree already exits 1 with
  **492** disagreements — 103 arm-2, 102 arm-4, and 287 from arm 1 and arm 3 for P3's
  own rows, which are the ones P3 will actually cure.
- **A move package must sweep the prefixes it fills, not only the ones it empties.**
  V1. The address that broke here was correct until the package made it wrong, so
  no sweep over pre-existing names could reach it. The cheapest detector is a
  by-content grep for each *target* directory prefix before the first `git mv`.
- **`P6_D_REPORTING_TEST_PATHS` is now dead in both of its consumers** (D2), joining
  `P6_D_GIT_TEST_PATHS` from P1. P4 is the package that sweeps them together.
- **The ladder branches `tests/Unit/Reporting/`, `tests/Functional/Reporting/` and
  `tests/Unit/Reporting/{Health,Impact,Filter}/` in `classifyOwner()` are now
  unreachable for every tracked path.** They remain because the generator's own
  docblock declares classifier literals to be claims about arbitrary inputs, not
  about the tree — including `--classification-probe=` inputs. Same sweep, same
  package: P4.
- **The moved support file's `closure_package` went `permanent` → `P8` and its
  `disposition` still reads "Move atomically with the named owner and closure
  package." while `target_path` now equals `current_path`.** Both come from the
  surviving ladder answering about the new path (`tests/Reporting/` → `['Reporting',
  'P8']`, then the default arm of `dispositionFor()`); the owner and target — the
  two columns the brief's claim and the judge's arm 3 are about — are correct. A
  row that says "move" about a file already at its target is inert rather than
  wrong, and curing it means adding a `tests/Reporting/` arm to `dispositionFor()`,
  which touches fixtures belonging to other packages. Flagged, not edited. Its P3
  analogue `FromArrayKeyReader.php` will land in the same shape.
