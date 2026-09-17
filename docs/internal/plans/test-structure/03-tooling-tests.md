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

Scope first: this decision is about the **five tools that already have a
directory** and so could in principle offer it as a root. The six flat tools get
a test directory that did not exist before, so no tool directory is available to
be their root and the question does not arise for them — eleven subjects end up
with a test directory, and five of them had a choice.

Measured: of those five, **four are PSR-4-clean**
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
  back an empty suite" is false for the pinned PHPUnit** — the probes and their
  exit codes are in
  [`measurement/stage-03/baseline.md`](measurement/stage-03/baseline.md).
  Probed four ways:
  exit 2, nothing run, and the aggregate, `architecture:check` and G2 each
  refuse behind it. A third, unsolicited witness agrees — the comment on
  `createIsolatedProject()` says "PHPUnit exits 2 when a `<testsuite>` names a
  directory that is not there". So this stage needs **no** new "every declared
  directory exists" control.
- **The case this stage can actually produce is the other one, and it is silent
  only on a developer's disk.** A declared directory that exists and holds no
  tests is silent — measured. But git tracks no empty directory, so a directory
  this stage empties is *absent* on a fresh clone, where the identical commit
  hits the exit-2 case. Local green and CI red would be the same tree. Removing
  a stale `<directory>` is therefore mandatory, not tidy.

**`tools/` is an unguarded root today, before this stage touches it.**
`Qualimetrix\PhpStan\` → `tools/phpstan/` is a live `autoload-dev` root that
no static analysis, no style check, no commit hook and no packaging rule
covers — so `tools/phpstan/` ships in both the composer dist package and the
Docker image right now, and an `src/` import of it would not be flagged. This
stage puts tests there, so closing that is in scope rather than reported.
**The addresses are rows 13-19 of
[`addresses-to-edit.md`](measurement/stage-03/addresses-to-edit.md); this
paragraph deliberately does not list them.** An earlier draft did, said "five",
and was wrong twice over — the scratch-path control and `.dockerignore` were
both missing. Counts restated in prose are what went stale in both review
rounds, so the prose now points and stops.

**Measured before promising: `tools/` is already clean under PHPStan level 8 and
under cs-fixer**, both probed with the configs temporarily widened, so bringing
it under coverage costs no fixes.

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

## Execution

The two-step plant, the Definition of Done and the seven work packages are in
[`03-packages.md`](03-packages.md). They were split out when this file passed
the 400-line threshold; that file executes what this one decides.

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
