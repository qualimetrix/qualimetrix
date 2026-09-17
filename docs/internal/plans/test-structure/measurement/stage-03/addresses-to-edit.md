# Stage 03 — every address a package must edit, derived

Derived from `registration-addresses.md` and `pinned-references.md`, and **every
anchor below re-verified against the tree** rather than copied forward.

**This file exists because three round-1 findings shared one cause: an address
the measurement found and the plan did not carry. Round 2 then found that same
defect three times inside this very table** — `dispositionFor()`/`targetPath()`,
`P7_MEASUREMENT_PATHS` and the `P6_C_BASELINE_PATHS_SHA256` digest were all
named by this table's own sources and all missing from it. So the rule is now
mechanical: **addresses live here and nowhere else.** The plan cites numbers; no
prose anywhere restates a count, because every count it restated went stale.

`L` = omitting the edit fails loudly. `S` = silently.

## Registering the `Tooling` suite and the new roots

| #   | Address                                                                                       | Carrier                                                                                              | L/S                                         | Package                      |
| --- | --------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------- | ---------------------------- |
| 1   | `<testsuite name="Tooling">` + one `<directory>` per test dir                                 | `phpunit.xml.dist`                                                                                   | L                                           | each mover, for its own dir  |
| 2   | `SUITES` tuple                                                                                | `scripts/phpunit-aggregate.py:42`                                                                    | L (`PHPUnit suite partition mismatch`)      | P1                           |
| 3   | `SUITES` tuple, second copy                                                                   | `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py:22`                            | L (`composer test:cross-tool`)              | P1                           |
| 4   | PSR-4 root per test dir                                                                       | `composer.json` `autoload-dev`                                                                       | L (G2/G3 via `TestTree`)                    | each mover                   |
| 5   | `classmap` entry for the PhpStan fixtures                                                     | `composer.json`                                                                                      | L                                           | P1                           |
| 6   | scan-scope literal — **a test directory, never a tool root**                                  | `scripts/generate-modular-architecture-test-inventory.php:349`                                       | **S**                                       | each mover                   |
| 7   | `testSuitePrefixTable()` row per test dir                                                     | same, `:1017`                                                                                        | L                                           | each mover                   |
| 8   | `classifyOwner()` branch for the new paths                                                    | same, refusal at `:976`                                                                              | L (`Unclassified test artifact: <path>`)    | each mover                   |
| 9   | `classifyKind()` branch — **only if** the path needs a kind its existing branches do not give | same, `:982`, refusal at `:1003` (`Unclassified test artifact kind`)                                 | L                                           | each mover, if reached       |
| 10  | `createIsolatedProject()` copy list must contain every root the tracked config declares       | `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php:186-196` | L (PHPUnit exits 2 inside the scratch root) | **each mover**, not P1 alone |
| 11  | `dispositionFor()` branch for the new paths                                                   | inventory `:1152`                                                                                    | L                                           | each mover                   |
| 12  | `targetPath()` branch for the new paths                                                       | inventory `:1199`                                                                                    | L                                           | each mover                   |

**Address 8 vs 9 — corrected.** The first revision of this table attributed
`Unclassified test artifact` to `classifyKind()`. It is `classifyOwner()`'s
refusal, at `:976`; `classifyKind()`'s own refusal is a *different* message at
`:1003`. A `classifyKind()` branch is also not automatically wanted: classifying
a new path as `placeholder` would silence the guard that a test file was never
discovered. Add the owner branch always; add a kind branch only if the generator
actually refuses for kind.

**Address 10 — corrected, and it was HIGH.** The first revision gave this to P1
alone. Every mover declares a new `<directory>`, and `createIsolatedProject()`
must have every declared root present in its scratch copy or PHPUnit exits 2
there — the copy currently takes `tests`, `governance`, `src`, the generated
directory, and `mkdir`s an empty `scripts`. So **every** mover touches it. The
code's own comment says why, and is an unsolicited third witness that PHPUnit
exits 2 on an absent declared directory — the behaviour `CLAUDE.md` denies.

## Closing the `tools/` root — seven addresses

| #   | Address                                                                                    | Carrier                                                               | L/S                                    |
| --- | ------------------------------------------------------------------------------------------ | --------------------------------------------------------------------- | -------------------------------------- |
| 13  | `paths:` gains `tools`                                                                     | `phpstan.neon:11`                                                     | S                                      |
| 14  | finder gains `__DIR__ . '/tools'`                                                          | `.php-cs-fixer.dist.php:6`                                            | S                                      |
| 15  | staged-path filter gains `tools`                                                           | `.githooks/pre-commit:53`                                             | S                                      |
| 16  | `/tools/ export-ignore`                                                                    | `.gitattributes`                                                      | S (ships in the composer dist package) |
| 17  | `DEVELOPMENT_NAMESPACE_PREFIXES` gains every new dev prefix **and** `Qualimetrix\PhpStan\` | `scripts/generate-modular-architecture-production-inventory.php:994`  | S                                      |
| 18  | `ROOTS` gains `tools`                                                                      | `governance/TestSuiteHygiene/ScratchPathsCarryRealEntropyTest.php:42` | S                                      |
| 19  | nested test dirs excluded — `**/tests/` plus the explicit paths                            | `.dockerignore`                                                       | S (ships in the Docker image)          |

Address 18 is the one the first DoD dropped; `scripts` is already in `ROOTS`, so
only the `tools/` movers were exposed. Measured: `tools/` is already clean under
PHPStan level 8 and cs-fixer, so 13 and 14 cost no fixes.

**Address 17 was mis-assigned, and P2-P4 inherited the hole.** The `tools/`
section groups it with the addresses P1 owns, but its own text says "every new
dev prefix" — and P1 cannot know the prefixes P2 through P6 will declare. P1
added `Qualimetrix\PhpStan\` and stopped; P2, P3 and P4 each declared a new
`autoload-dev` root and none added its prefix, so four of them sat unguarded
until P4's acceptance:
`Qualimetrix\PromiseEffect\Tests\`, `QmxDirectiveAudit\Tests\`,
`QmxDirectiveAuditControls\Tests\`, `QmxFindingGate\Tests\`
(`Qualimetrix\PhpStan\Tests\` is covered by its parent entry). Nothing checks
this, so nothing complained. **Address 17 is per-mover, like 1, 4, 6-12 and 20 —
not P1's alone**, and the four were added when the mis-assignment was found.

The general shape, now seen five times in this stage: an address whose *text*
quantifies over the whole stage while its *assignment* names one package is a
hole with a green DoD on either side of it.

**Address 19 — found after round 1, missing from this table's first revision.**
`Dockerfile:29` is `COPY . .`, so `.dockerignore` is fully load-bearing.
Its `tests/` and `governance/` entries are root-anchored, so they do not exclude
`scripts/promise-effect/tests/` or `tools/phpstan/tests/`, and neither `scripts/`
nor `tools/` appears in the file. The cure is written so it does not depend on
settling Docker's pattern semantics: `**/tests/` **plus** the explicit paths
excludes them either way. **Not verified by building an image** — grounded in
`COPY . .` read from the file and Docker's documented matching. Recorded as
unverified rather than asserted; the implementing package confirms with a build
or takes the belt-and-braces cure.

## The rename-enumeration surface

| #   | Address                                                                  | Carrier                                         | L/S   | Package                  |
| --- | ------------------------------------------------------------------------ | ----------------------------------------------- | ----- | ------------------------ |
| 20  | `'tests' => ['roots' => ['tests', 'governance'], …]` gains the new roots | `scripts/generate-rename-enumeration.php:56-61` | **S** | each mover, including P1 |

The 16 files leave this surface and nothing adds them back, so
`composer enumeration:renames:check` reads a pure drop in one column. The
comment at `:51-55` sets the precedent: the governance move added a **second
root to the same surface** rather than a new column, exactly so a sweep would
not read a meaningless drop. Follow it. Baseline to preserve:
`58 channel, 54 producer, 82 metric-key rows, 113 executed`.

**Add the test directory, not the tool's parent — measured in P2.** P1 could add
`tools` wholesale because `tools/` holds nothing but the one tool. Adding
`scripts` the same way **moves the baseline**: other tools under `scripts/` pin
older channel and producer spellings that the surface would then begin counting,
and P2 saw dozens of occurrence-column disagreements when it tried. So each
mover adds its own `scripts/<tool>/tests` path. The roots list is asymmetric on
purpose — `tools` covers a future `tools/*/tests` for free, a
`scripts/<tool>/tests` entry covers only itself.

## Tracked literals and digests the movers invalidate

| #   | Address                                                                                        | Carrier                                                          | L/S                                                                                 |
| --- | ---------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| 21  | `P7_MEASUREMENT_PATHS:81` pins `test_cross_tool_comparison.py`                                 | inventory `:70-81`, checked by `assertPathLiteralsResolve()`     | L                                                                                   |
| 22  | `P6_C_BASELINE_PATHS_SHA256` — the digest of every file under `tests/Analysis/Policy/Baseline` | inventory `:17` and `:328`, built by `p6CBaselinePaths()` `:634` | L (`P6-C Baseline test artifact set differs from the reviewed finite path digest.`) |

Address 21 is P6's: it moves that Python file. Address 22 is **P4's**, because
row 7 (`ChannelRenameTsvGateAgreementTest.php`) lives under
`tests/Analysis/Policy/Baseline/Unit/` and the digest walks that tree
recursively. Both were named by this table's sources and missing from its first
revision. A path that is truly gone moves to `RETIRED_PATH_ASSERTIONS` with its
reason; the digest is re-taken.

## Literals that go dead and must be removed, not left

| #   | Address                                                                           | Carrier                                                           | L/S                                                                                                                          |
| --- | --------------------------------------------------------------------------------- | ----------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| 23  | `<directory>tests/TestSupport/ArchitectureStaticAnalysis/Unit</directory>`        | `phpunit.xml.dist`                                                | **L on a fresh clone, S locally** — git tracks no empty directory, so the emptied tree is *absent* in CI and PHPUnit exits 2 |
| 24  | `testSuitePrefixTable()` row `tests/TestSupport/ArchitectureStaticAnalysis/Unit/` | inventory `:1040`                                                 | L                                                                                                                            |
| 25  | `classifyOwner()` prefix `tests/TestSupport/ArchitectureStaticAnalysis/`          | inventory `:667`                                                  | L                                                                                                                            |
| 26  | `classifyOwner()` prefix `tests/Unit/RuleVocabulary/`                             | inventory `:960`                                                  | L                                                                                                                            |
| 27  | `classifyOwner()` prefix `tests/Unit/PromiseEffect/`                              | inventory `:966`                                                  | L                                                                                                                            |
| 28  | `systemSupportContents()` literal `tests/TestSupport`                             | inventory `:1505`                                                 | L                                                                                                                            |
| 29  | two rows removed                                                                  | `governance/TestSuiteHygiene/namespace-path-allow-list.php:77-78` | L                                                                                                                            |
| 30  | `ceiling` lowered to match                                                        | same, `:20`                                                       | **S** — deriving only ever lowers it, so a stale-high ceiling absorbs a future violation silently; the file says so itself   |
| 31  | fixture ignore path                                                               | `phpstan.neon:34`                                                 | L (`reportUnmatchedIgnoredErrors`)                                                                                           |
| 32  | fixture exclude path                                                              | `phpstan.neon:22`                                                 | L                                                                                                                            |

29 and 30 are done by **re-deriving** with
`php governance/TestSuiteHygiene/derive-namespace-path-allow-list.php`, never by
hand. Splitting them is the point: 29 is loud, 30 is not, and the first revision
graded the pair `L`.

`tests/Reporting/Formatter/Suppressed/Unit` **survives** — `SuppressedFormatterTest`
stays. `tests/TestSupport/Logging/` (inventory `:664`) exists and is untouched.

## Pinned references the movers rename

| #   | Address                                                                                                                                            | Carrier                                                 | L/S                                                                                                                       |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| 33  | 119 dot-separated `FQN::method` literals: 16 `DirectiveAuditGateTest`, 38 `DirectiveAuditReportReadingTest`, 65 `ThresholdPopulationAgreementTest` | `scripts/directive-audit-controls/Probes.php`           | L (`stale declaration:`)                                                                                                  |
| 34  | `FIXTURE` const → `AuthoredThresholdForms.php`                                                                                                     | same, `:91`                                             | L                                                                                                                         |
| 35  | three of eight test paths                                                                                                                          | `scripts/directive-audit-controls/Suite.php:50-52`      | L (PHPUnit exits 2)                                                                                                       |
| 36  | pinned path literal                                                                                                                                | `scripts/generate-rename-enumeration.php:1980`          | S (comment only)                                                                                                          |
| 37  | two `path:line` citations of `DirectiveAuditReportReadingTest.php`                                                                                 | `directive-audit/enumeration-unguarded-cases.tsv:15,17` | **ungraded** — whether any script reads this tracked declaration file was not established; treat as unknown, not as prose |
| 38  | generated artifacts, refreshed by `composer architecture:generate`                                                                                 | `docs/internal/generated/modular-architecture/*`        | L                                                                                                                         |

The 119 count on address 33 comes from `grep -coE` over the whole file; a
line-by-line count gives 16 / 37 / 49, which is consistent with several literals
sharing a line but does **not** independently reproduce the totals. Whoever
executes P3 re-derives the count rather than trusting it.

**No repo-wide find/replace on `Qualimetrix\Tests\Unit\RuleVocabulary\`.** That
prefix maps to **three** destinations — `scripts/directive-audit/tests/`,
`scripts/directive-audit-controls/tests/` and a flat-tool directory — so one
substitution is wrong by construction. Inside `Probes.php` alone the three
pinned classes do share one destination (`DirectiveAuditControlsSuiteKeyTest`
appears there 0 times), so a `Probes.php`-scoped substitution is safe; the
repo-wide one is not.

**Spellings a sweep must walk, not names:** project-relative path; backslashed
FQN; **dot-separated FQN**; `FQN::method`; bare `itXxx` method name; bare class
name; namespace prefix with no class; **and a hardcoded count over a generated
artifact** — the spelling address 39 was written in, which carries no path and
no name at all, so every path-and-name sweep in this stage was blind to it. The
dot form is invisible to a backslash grep and is the one in live use.

## 39 — found by executing P1, not by enumerating

| #   | Address                                                        | Carrier                                                                            | L/S | Package                                                  |
| --- | -------------------------------------------------------------- | ---------------------------------------------------------------------------------- | --- | -------------------------------------------------------- |
| 39  | `assertCount(3, $this->tsv('test-system-support-owners.tsv'))` | `governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php:106` | L   | whichever package changes `systemSupportContents()` — P1 |

A hardcoded row count over a generated artifact. Address 28 removes two dead
rows from `systemSupportContents()`, which takes that artifact from 3 data rows
to 1, and the governance test fails with "actual size 1 matches expected size
3". Neither this table, nor `registration-addresses.md`, nor
`pinned-references.md` enumerated it: all three swept for *paths and names*, and
this is a **count**.

**That is the fourth instance in this stage of an address the enumeration
missed**, after round 1's three and round 2's three. The direction is always the
same, and the lesson is not "sweep harder" — it is that a count derived from an
artifact is a reference spelling nobody listed. Add counts to the spellings a
sweep must walk.

## 40-42 — the Python mover's dead literals, missed by the same enumeration

The "literals that go dead" section was derived for the PHP movers only. P6
moves the two Python tests, which empties `tests/System/` **entirely** and
`tests/Analysis/Evidence/Measurement/Tests/` with it, and that invalidates three
more literals nobody listed.

| #   | Address                                                                    | Carrier                                                                           | L/S | Package |
| --- | -------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | --- | ------- |
| 40  | `classifyOwner()` prefix `tests/System/TestRunnerConfiguration/`           | inventory `:661`                                                                  | L   | P6      |
| 41  | `systemSupportContents()` iterates `['tests/System', 'tests/TestSupport']` | inventory `:1528`                                                                 | L   | P6      |
| 42  | the exact `test:cross-tool` command string, path included                  | `governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php:59` | L   | P6      |

**Address 42 shares its carrier with address 39, and that carrier is the richest
source of missed addresses in this stage.**
`ModularArchitectureGovernanceIntegrationTest.php` pins repository facts as
literals throughout: exact composer script step arrays (`:50`, `:62`), a named
step at a fixed index (`:55`), the `test:cross-tool` command string (`:59`), and
TSV row counts (`:105`, `:106`). None of those is a path or a name, so every
path-and-name sweep behind this table was blind to all of them. When a later
stage moves anything this repository declares, **read that file first.**

## Deliberately not addresses

Named so that the completeness claim above means something. Each was checked.

- **`.github/workflows/*.yml`** — `docs.yml`, `qmx.yml`, `release.yml`. The only
  root-shaped literal is `qmx.yml:59` `paths: 'src/'`, the input to the Action
  smoke-test analysing the product. No duplicate of the pre-commit hook's root
  list exists in CI. Checked and clear.
- **`scripts/init-environment.sh:297-305`** — `CRITICAL_DIRS` only
  `log_warning`s a missing directory. Adding the new roots is cosmetic; omitting
  it costs a warning and nothing else.
- **`currentSuite()`** — has no per-path literal of its own; it walks
  `testSuitePrefixTable()`, so address 7 covers it.
- **`scripts/phpunit-aggregate.py`'s docstring and `--jobs` bound** — the
  docstring describes a runtime proof and enumerates no suite; the bound is
  `len(SUITES)`. Address 2 covers both.
- **The five dead prose mentions** (`@see`, "split off from" comments) — nothing
  reads them; confirmed by renaming a fixture away and watching PHPStan still
  report `[OK] No errors`. Documentation debt, owned by P7.

## What this table still cannot claim

It is derived from two measurements plus round-2's four independent channels
(against `CLAUDE.md`'s table, against `registration-addresses.md`, a basename
sweep outside `tests/`, and a pass over the generator's tracked constants). It
is **not** proven complete — it has already been wrong three times in the same
direction. An address is missing until something refuses; the packages'
verification, not this table, is what establishes coverage.
