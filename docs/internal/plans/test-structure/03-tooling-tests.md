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
[`measurement/stage-03/population.md`](measurement/stage-03/population.md);
it was derived twice, by a witness holding stage 02's answers and by a witness
denied them, and the two agree on all 16 paths.

**There is one agreement test, not three.** `ThresholdPopulationAgreementTest`
runs `src/`'s `ThresholdOverrideExtractor` and the tool's
`ThresholdDirectiveScan` over one fixture and requires `assertSame`.
`ChannelRenameTsvGateAgreementTest` and `SuppressionSnapshotKeyTest` were read
as agreement tests and are not: neither runs a `src/` reader against a tool
reader inside its own body.

## The three decisions this stage stands on

**D3-1. The `autoload-dev` PSR-4 root is the test directory, not the tool
directory.** This is forced by measurement, not chosen: `scripts/promise-effect`
violates PSR-4 in 13 of its 15 files (`Classifier.php` alone declares four
types), so it cannot be a PSR-4 root without splitting its source one class per
file — a change to the tool, not to its tests. So each moving tool gets
`"<ToolNamespace>\\Tests\\": "<tool-dir>/tests/"`, and `require_once` stays as
the tool's own loading mechanism where the tool is not PSR-4 clean.

*Rejected:* one root per tool directory, which would also have deleted the
`require_once` boilerplate. Unavailable for the largest tool, and a convention
that holds for three tools out of five is worse than one that holds for all.

Nested roots are safe: `NamespacePathAllowList::expectedNamespace()` selects the
longest matching root, the same rule Composer applies, and
`TestTree::testFiles()` dedupes by path. So a lowercase `tests/` segment is
consumed by the prefix and no namespace carries it — **this stage adds no
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
- `tests/Unit/PromiseEffect/` and `tests/Unit/RuleVocabulary/` no longer exist.
- **The two totals are unchanged: 9198 discovered, 9196 executed**, and the
  per-suite rows match
  [`measurement/stage-03/prediction.md`](measurement/stage-03/prediction.md)
  (`Tooling` 179; Unit 7148 → 6987; Integration 437 → 419). A run whose totals
  match but whose per-suite rows do not is a file that landed in the wrong
  suite — the defect a total-only check cannot see.
- G2 and G3 cover every new root, shown by naming the roots
  `TestTree::roots()` returns, and shown to *bite* by the two-step plant below.
- `namespace-path-allow-list.php` has two fewer rows and its `ceiling` is
  lowered to match, by re-deriving.
- `directives:controls:coverage` reports `0 not as declared`, no
  `stale declaration:` line, and the two pre-existing `guarded by nothing` rows
  — and `composer directives:controls` is run **in full, once, without
  `--only`**, at acceptance: coverage proves the declarations are consistent,
  only the full run proves a planted breakage still reddens the renamed test.
- `tools/` is in `phpstan.neon` `paths`, the cs-fixer finder, the pre-commit
  path filter, `.gitattributes` `export-ignore`, and every new dev namespace
  prefix — plus `Qualimetrix\PhpStan\` — is in
  `DEVELOPMENT_NAMESPACE_PREFIXES`.
- `phpstan.neon`'s fixture ignore and exclude paths point at the new locations,
  and `composer.json`'s `classmap` entry with them.
- `composer architecture:check` green, `composer check` green.

## The two-step plant, because one step proves nothing

The scan-scope hole at line 349 is silent, so "it reddens after I widened it"
does not establish that it was blind before. Order:

1. Put an orphan probe test in a new directory **under the old scan scope**.
   `composer architecture:check` must stay **green** — that is the hole,
   demonstrated. With the root declared, G2 must be **red** — that is the
   backstop, demonstrated.
2. Widen the literal. The generator must now **refuse on the same probe**.
3. Remove the probe, add the `<directory>`, the prefix-table row and the
   `SUITES` entry, and go green.
4. Only then move real files.

## Work packages

Sequential, not parallel, and the reason is the file sets: every mover package
touches `phpunit.xml.dist`, `composer.json`, the inventory generator and the
generated artifacts. Parallel packages with those in common would overwrite
each other, and a package cannot declare its directory ahead of time either —
`TestTree::autoloadDevRoots()` refuses a declared root that is not on disk, and
G2 refuses a declared suite whose listing is empty. So each package registers
the directory it fills, in the same commit that fills it.

| #   | Package                                | Moves                                           | Also owns                                                                                                                                                                |
| --- | -------------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| P0  | Measurement                            | —                                               | `measurement/stage-03/*`; **done, commit before P1**                                                                                                                     |
| P1  | The seam, proved, plus `tools/phpstan` | rows 8, 9 + 4 fixtures                          | the `Tooling` suite; `tools` in phpstan/cs-fixer/hook/`.gitattributes`; `DEVELOPMENT_NAMESPACE_PREFIXES`; `SUITES` in both Python copies; scan scope; the two-step plant |
| P2  | promise-effect                         | rows 1-3                                        | empties `tests/Unit/PromiseEffect/`                                                                                                                                      |
| P3  | directive-audit and its controls       | rows 4, 5, 6, 10 + `AuthoredThresholdForms.php` | the 119 `Probes.php` literals; `Suite.php`'s path list; `phpstan.neon` ignore path; empties `tests/Unit/RuleVocabulary/`                                                 |
| P4  | finding-gate                           | row 7                                           | the shared `ChannelRenameTsvCorpus` stays in `tests/`; the cross-root import is stated or the corpus moves                                                               |
| P5  | The flat-script tools                  | rows 11-16                                      | five new subject directories                                                                                                                                             |
| P6  | The Python tooling tests               | 2 files                                         | `test:cross-tool`'s `-s` paths. Last on purpose: droppable without re-cutting anything                                                                                   |
| P7  | Documentation                          | —                                               | `CLAUDE.md` corrections; affected `src/`/component READMEs; `CHANGELOG.md`                                                                                               |

**Files of this stage's subject that no package owns:** none of the 16, none of
their fixtures. Deliberately out of scope and named so: the entry scripts of the
six flat tools (D3-3); `scripts/input-doors` and `scripts/promise-effect-controls`,
which have no test to move; the five dead-prose mentions; the pre-existing
`2 cases guarded by nothing`; and the 616 `tests/` files neither witness read.

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
