# Stage 03 — the population, by two witnesses

## The plan's count was stale, and by how much

`03-tooling-tests.md` says "9 files (plus tooling methods inside 4 `mixed`
files)". Re-derived on `main` at `c49fc0b4`, the population is **16 whole PHP
files** plus 2 Python files. The growth has two separate causes, and only the
first was foreseen:

1. **Stage 02 materialized the partials.** Splitting a `mixed` file left the
   tooling remainder as a whole file with a new name, so what the plan counted
   as "a method inside a product test" is now a file of its own:
   `BenchmarkCoverageRefusalTest.php` (from `BenchmarkConsumersCoverageTest`)
   and `ModularArchitectureGeneratorRefusalTest.php` (from
   `ModularArchitectureGovernanceIntegrationTest`).
2. **Two files were never in the census at all** — neither in
   `controls-verdict.tsv`'s 81 nor among the 17 `mixed`:
   `BenchmarkRegressionClassificationTest.php` (subprocess on
   `scripts/benchmark-regression.php`) and `HealthCalibrationBenchTest.php`
   (`require_once` on `scripts/health-calibration.php`). Both were found by a
   mechanical sweep, not by reading; the census that missed them examined 81 of
   688 files.

## Two witnesses, agreeing

| Witness | Given                                                                                                                         | Produced                  |
| ------- | ----------------------------------------------------------------------------------------------------------------------------- | ------------------------- |
| A       | the tree, the plan, stage 02's artifacts                                                                                      | the 16 paths below        |
| B       | the criterion and the tree only; the plan, the verdict TSV, the slice reports and every stage-02 artifact named as unreadable | `witness-b-population.md` |

**The two derived the same 16 paths.** No path is in one list and not the other.
That is the check: witness A had stage 02's answer available and witness B did
not, so agreement is not shared provenance.

They disagreed on **classification**, not membership, in three places. All three
resolved in witness B's favour, each checked against the code by the
orchestrator rather than argued:

| File                                | A said           | B said  | Checked                                                                                                                                                                                 |
| ----------------------------------- | ---------------- | ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ChannelRenameTsvGateAgreementTest` | agreement        | tooling | **B.** The file has one `#[Test]` method and it calls only `RenameMaps::load()`. No `src/` reader runs in it; the agreement is between two files sharing a corpus, not inside this one. |
| `SuppressionSnapshotKeyTest`        | agreement-ish    | tooling | **B.** It iterates `SuppressionMechanism::cases()` to drive the tool over every value; no assertion compares two readers' results.                                                      |
| `HealthCalibrationBenchTest`        | (not classified) | tooling | **B.** It imports five `src/` classes, but only to build the config fixtures the bench consumes.                                                                                        |

So there is exactly **one** agreement test, not three.

## The 16

| #   | path                                                                                             | SUT                                                                                             | channel that reaches it                         |
| --- | ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- | ----------------------------------------------- |
| 1   | `tests/Unit/PromiseEffect/ClassifierTest.php`                                                    | `scripts/promise-effect/Classifier.php`                                                         | `require_once` via `$scripts`                   |
| 2   | `tests/Unit/PromiseEffect/FloorTest.php`                                                         | `scripts/promise-effect/Floor.php`                                                              | `require_once` via `$scripts`                   |
| 3   | `tests/Unit/PromiseEffect/LedgerVocabularyTest.php`                                              | `scripts/promise-effect/Ledger.php`                                                             | `require_once`                                  |
| 4   | `tests/Unit/RuleVocabulary/DirectiveAuditGateTest.php`                                           | `scripts/directive-audit/Gate.php`                                                              | `require_once` loop                             |
| 5   | `tests/Unit/RuleVocabulary/DirectiveAuditReportReadingTest.php`                                  | `scripts/directive-audit/*` (8 parts)                                                           | `require_once` loop                             |
| 6   | `tests/Unit/RuleVocabulary/DirectiveAuditControlsSuiteKeyTest.php`                               | `scripts/directive-audit-controls/Suite.php`                                                    | `require_once`                                  |
| 7   | `tests/Analysis/Policy/Baseline/Unit/ChannelRenameTsvGateAgreementTest.php`                      | `scripts/finding-gate/RenameMaps.php` + 4                                                       | `require_once` loop                             |
| 8   | `tests/TestSupport/ArchitectureStaticAnalysis/Unit/BannedStringPathPropertyRuleTest.php`         | `tools/phpstan/Rules/BannedStringPathPropertyRule.php`                                          | `use` (already autoloaded)                      |
| 9   | `tests/TestSupport/ArchitectureStaticAnalysis/Unit/BannedStringPathPromotedPropertyRuleTest.php` | `tools/phpstan/Rules/BannedStringPathPromotedPropertyRule.php`                                  | `use` (already autoloaded)                      |
| 10  | `tests/Unit/RuleVocabulary/ThresholdPopulationAgreementTest.php`                                 | `scripts/directive-audit/ThresholdDirectiveScan.php` **and** `src/…/ThresholdOverrideExtractor` | `require_once` + `use` — the one agreement test |
| 11  | `tests/Reporting/Formatter/Suppressed/Unit/SuppressionSnapshotKeyTest.php`                       | `scripts/generate-suppression-snapshot.php`                                                     | `require_once`; script is global-namespace      |
| 12  | `tests/Unit/RuleVocabulary/RenameEnumerationRetirementTest.php`                                  | `scripts/generate-rename-enumeration.php`                                                       | `require_once`; script is global-namespace      |
| 13  | `tests/Analysis/Evidence/ComputedMetrics/Unit/HealthCalibrationBenchTest.php`                    | `scripts/health-calibration.php`                                                                | `require_once`                                  |
| 14  | `tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkCoverageRefusalTest.php`           | `scripts/benchmark-regression.php`, `scripts/collect-benchmark-data.php`                        | subprocess + copy-then-run                      |
| 15  | `tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkRegressionClassificationTest.php`  | `scripts/benchmark-regression.php`                                                              | subprocess + copy-then-run                      |
| 16  | `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php`     | `scripts/generate-modular-architecture-{production,test}-inventory.php`                         | subprocess                                      |

Rows 1–10 have a **tool directory** to move next to. Rows 11–16 name a **flat
`scripts/*.php` file with no directory of its own** — the placement the plan's
three-directory scheme does not cover.

Python, by the same criterion, not in the `*Test.php` census:

| path                                                                      | SUT                                |
| ------------------------------------------------------------------------- | ---------------------------------- |
| `tests/Analysis/Evidence/Measurement/Tests/test_cross_tool_comparison.py` | `scripts/cross-tool-comparison.py` |
| `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py`    | `scripts/phpunit-aggregate.py`     |

`tests/System/TestRunnerConfiguration/Tests/Fixtures/fake_phpunit.py` is fixture
support for the second, not a test.

## PSR-4 compliance of the tool directories — measured, because the blanket claim was wrong

The plan says autoloading "differs per tool and the earlier blanket claim was
wrong", and it does. Checked by parsing every file for `namespace` and for
declared types, and comparing each against what PSR-4 requires of its path:

| tool directory                     | files | PSR-4 violations | can be an `autoload-dev` root as-is?                    |
| ---------------------------------- | ----- | ---------------- | ------------------------------------------------------- |
| `tools/phpstan`                    | 3     | 0                | it already is                                           |
| `scripts/directive-audit`          | 11    | 0                | yes                                                     |
| `scripts/directive-audit-controls` | 6     | 0                | yes                                                     |
| `scripts/finding-gate`             | 42    | 2                | yes — both are classless helper files nothing autoloads |
| `scripts/promise-effect`           | 15    | **13**           | **no**                                                  |
| `scripts/input-doors`              | 5     | 3                | no (no test targets it)                                 |

`scripts/promise-effect` is the decisive row: `Classifier.php` declares
`CompositionComparison`, `Verdict`, `Judgement` and `Classifier`;
`Composition.php` declares five types; `Ledger.php` five. PSR-4 would look for
`Qualimetrix\PromiseEffect\Verdict` in `Verdict.php`, which does not exist —
which is exactly why these tests `require_once` instead of importing.

**Consequence for the design, forced rather than chosen:** the `autoload-dev`
PSR-4 root must be the *test* directory, not the tool directory. One convention
for all tools, `require_once` for the SUT left as the tool's own loading
mechanism. Nested roots are safe:
`NamespacePathAllowList::expectedNamespace()` picks the longest matching root,
the same rule Composer uses, and `TestTree::testFiles()` dedupes by path so a
doubly-covered file is judged once.

## How obtained / what this cannot see

- Witness A: `git grep` over `tests/**Test.php` for `require_once`, tooling
  namespaces, `PHP_BINARY`, script names; then read every hit. Then a second
  sweep for any reference to `benchmarks/`, `website/`, `governance/`,
  `.githooks/`, `finding-gate/`, `tools/`.
- Witness B: six named channels swept separately — `use`, `require`/`include`
  (literal and concatenated forms), subprocess, tool-file-as-text, copy-then-run,
  Python module loading — with the per-channel commands and blind spots in
  `witness-b-population.md`. Its own recorded lesson: a first pathspec
  `scripts/**/*.php` missed the top-level `scripts/*.php` files and undercounted
  the namespace roster by 3, because those files sit in no subdirectory.
- **A third channel, run afterwards, in the opposite direction.** Both
  witnesses searched *tests for tool strings*. This one enumerated the 131 tool
  files under `scripts/ tools/ benchmarks/ website/hooks/ .githooks/` and
  `git grep -F`-ed each **exact basename** inside `tests/`. It returned the same
  16 and nothing else. It surfaced one file neither witness had examined —
  `tests/Functional/Console/Command/HookStatusCommandTest.php`, via
  `pre-commit-hook.sh` — and that file is a product test: it carries
  `#[CoversClass(HookStatusCommand::class)]` and the path it names is the
  fabricated `/fake/target/pre-commit-hook.sh`, never the real tool. Both plan
  reviewers also failed to find a 17th.
- **The narrowest true form of the claim, which is not "the population is
  complete".** No witness read all 632 files. Two searched tests for tool
  strings; the third searched tools for their own basenames. So the claim
  established is: *no test file under `tests/` references a tool by its
  namespace, by a literal `scripts`/`tools` path, or by the tool's own
  basename, other than these 16.* What survives outside it is a test reaching
  its SUT with none of those three spellings anywhere in the file — for
  instance through a constant defined in a shared base class, or a helper that
  assembles the path from segments. Nothing here rules that out; the stage
  narrows the project's standing "files nobody read" population by 16 and does
  not close it.
- Not covered by either: a test whose SUT is a repository tool written in a
  language neither witness swept (there is none in `.githooks/` with a test),
  and CI workflow YAML, which nobody read.
