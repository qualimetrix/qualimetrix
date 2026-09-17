# Stage 03 — every address a package must edit, derived

Derived from `registration-addresses.md` and `pinned-references.md`, re-checked
against the tree with the line numbers below. **This table exists because three
review findings shared one cause: an address the measurement found and the plan
did not carry.** The plan's package table cites this file; it does not restate
it.

`L` = omitting the edit fails loudly. `S` = silently.

## Registering the `Tooling` suite and the new roots

| #   | Address                                                       | Carrier                                                                                              | L/S                                         | Package                             |
| --- | ------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------- | ----------------------------------- |
| 1   | `<testsuite name="Tooling">` + one `<directory>` per test dir | `phpunit.xml.dist`                                                                                   | L                                           | each mover, for its own dir         |
| 2   | `SUITES` tuple                                                | `scripts/phpunit-aggregate.py:42`                                                                    | L (`PHPUnit suite partition mismatch`)      | P1                                  |
| 3   | `SUITES` tuple, second copy                                   | `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py:22`                            | L (`test:cross-tool`)                       | P1                                  |
| 4   | PSR-4 root per test dir                                       | `composer.json` `autoload-dev`                                                                       | L (G2/G3 via `TestTree`)                    | each mover                          |
| 5   | `classmap` entry for the PhpStan fixtures                     | `composer.json`                                                                                      | L                                           | P1                                  |
| 6   | scan-scope literal — **a test directory, never a tool root**  | `scripts/generate-modular-architecture-test-inventory.php:349`                                       | **S**                                       | each mover                          |
| 7   | `testSuitePrefixTable()` row per test dir                     | same, `:1017`                                                                                        | L                                           | each mover                          |
| 8   | `classifyKind()` branch for the new paths                     | same, `:982`                                                                                         | L (`Unclassified test artifact`)            | P1                                  |
| 9   | `classifyOwner()` branch for the new paths                    | same, `:653`                                                                                         | L                                           | P1                                  |
| 10  | `createIsolatedProject()` copy list gains `tools/`            | `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php:186-196` | L (PHPUnit exits 2 inside the scratch root) | **P1**, though the file moves in P5 |

## Closing the `tools/` root — six addresses, not five

| #   | Address                                                                                    | Carrier                                                                  | L/S                           |
| --- | ------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------ | ----------------------------- |
| 11  | `paths:` gains `tools`                                                                     | `phpstan.neon:11-16`                                                     | S                             |
| 12  | finder gains `__DIR__ . '/tools'`                                                          | `.php-cs-fixer.dist.php:6-12`                                            | S                             |
| 13  | staged-path filter gains `tools`                                                           | `.githooks/pre-commit:53`                                                | S                             |
| 14  | `/tools/ export-ignore`                                                                    | `.gitattributes`                                                         | S (ships in the dist package) |
| 15  | `DEVELOPMENT_NAMESPACE_PREFIXES` gains every new dev prefix **and** `Qualimetrix\PhpStan\` | `scripts/generate-modular-architecture-production-inventory.php:993-996` | S                             |
| 16  | `ROOTS` gains `tools`                                                                      | `governance/TestSuiteHygiene/ScratchPathsCarryRealEntropyTest.php:42`    | S                             |

Address 16 is the one the first DoD dropped. `scripts` is already in `ROOTS`,
so only the `tools/` movers were exposed. Measured: `tools/` is already clean
under PHPStan level 8 and cs-fixer, so 11 and 12 cost no fixes.

## The rename-enumeration surface

| #   | Address                                                                  | Carrier                                         | L/S   | Package    |
| --- | ------------------------------------------------------------------------ | ----------------------------------------------- | ----- | ---------- |
| 17  | `'tests' => ['roots' => ['tests', 'governance'], …]` gains the new roots | `scripts/generate-rename-enumeration.php:56-61` | **S** | each mover |

The 16 files leave this surface and nothing adds them back, so
`composer enumeration:renames:check` reads a pure drop in one column. The code
comment at `:51-55` sets the precedent: the governance move added a **second
root to the same surface** rather than a new column, exactly so a sweep would
not read a meaningless drop. Follow it. Baseline to preserve:
`58 channel, 54 producer, 82 metric-key rows, 113 executed`.

## Literals that go dead and must be removed, not left

| #   | Address                                                                           | Carrier                                                           | L/S                                                                                                                          |
| --- | --------------------------------------------------------------------------------- | ----------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| 18  | `<directory>tests/TestSupport/ArchitectureStaticAnalysis/Unit</directory>`        | `phpunit.xml.dist`                                                | **L on a fresh clone, S locally** — git tracks no empty directory, so the emptied tree is *absent* in CI and PHPUnit exits 2 |
| 19  | `testSuitePrefixTable()` row `tests/TestSupport/ArchitectureStaticAnalysis/Unit/` | inventory `:1040`                                                 | L                                                                                                                            |
| 20  | `classifyOwner()` prefix `tests/TestSupport/ArchitectureStaticAnalysis/`          | inventory `:667`                                                  | L                                                                                                                            |
| 21  | `classifyOwner()` prefix `tests/Unit/RuleVocabulary/`                             | inventory `:960`                                                  | L                                                                                                                            |
| 22  | `classifyOwner()` prefix `tests/Unit/PromiseEffect/`                              | inventory `:966`                                                  | L                                                                                                                            |
| 23  | `systemSupportContents()` literal `tests/TestSupport`                             | inventory `:1505`                                                 | L                                                                                                                            |
| 24  | two rows + lowered `ceiling`                                                      | `governance/TestSuiteHygiene/namespace-path-allow-list.php:77-78` | L; **re-derive, never hand-edit**                                                                                            |
| 25  | fixture ignore path                                                               | `phpstan.neon:34`                                                 | L (`reportUnmatchedIgnoredErrors`)                                                                                           |
| 26  | fixture exclude path                                                              | `phpstan.neon:22`                                                 | L                                                                                                                            |

`tests/Reporting/Formatter/Suppressed/Unit` **survives** — `SuppressedFormatterTest`
stays. `tests/TestSupport/Logging/` (inventory `:664`) exists and is untouched.

## Pinned references the movers rename

| #   | Address                                                                                                                                            | Carrier                                            | L/S                      |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------- | ------------------------ |
| 27  | 119 dot-separated `FQN::method` literals: 16 `DirectiveAuditGateTest`, 38 `DirectiveAuditReportReadingTest`, 65 `ThresholdPopulationAgreementTest` | `scripts/directive-audit-controls/Probes.php`      | L (`stale declaration:`) |
| 28  | `FIXTURE` const → `AuthoredThresholdForms.php`                                                                                                     | same, `:91`                                        | L                        |
| 29  | three of eight test paths                                                                                                                          | `scripts/directive-audit-controls/Suite.php:50-52` | L (PHPUnit exits 2)      |
| 30  | pinned path literal                                                                                                                                | `scripts/generate-rename-enumeration.php:1980`     | S (comment only)         |
| 31  | generated artifacts, refreshed by `composer architecture:generate`                                                                                 | `docs/internal/generated/modular-architecture/*`   | L                        |

**No repo-wide find/replace on `Qualimetrix\Tests\Unit\RuleVocabulary\`.** That
prefix maps to **three** destinations — `scripts/directive-audit/tests/`,
`scripts/directive-audit-controls/tests/`, and a flat-tool directory — so a
single substitution is wrong by construction. Inside `Probes.php` alone the
three pinned classes do share one destination
(`DirectiveAuditControlsSuiteKeyTest` appears there 0 times), so a
`Probes.php`-scoped substitution is safe; the repo-wide one is not.

**Spellings a sweep must walk, not names:** project-relative path; backslashed
FQN; **dot-separated FQN**; `FQN::method`; bare `itXxx` method name; bare class
name; namespace prefix with no class. The dot form is invisible to a backslash
grep and is the one in live use.
