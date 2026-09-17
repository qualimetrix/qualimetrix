# Witness B — population of tooling tests under `tests/`

Derived from the code alone, without reading the other witness's list, the
verdict TSV/taxonomy, the slice reports, `03-tooling-tests.md`, or
`00-overview.md`.

## Tooling namespaces established from the code

```
git grep -h "^namespace" -- 'scripts/' 'tools/'
```

```
namespace QmxDirectiveAudit;
namespace QmxDirectiveAuditControls;
namespace QmxDirectiveNarrowControl;
namespace QmxFindingGate;
namespace QmxFindingGateControls;
namespace Qualimetrix\HealthCalibration;
namespace Qualimetrix\InputDoorControls;
namespace Qualimetrix\InputDoors;
namespace Qualimetrix\PhpStan\Rules;
namespace Qualimetrix\PromiseEffect;
namespace Qualimetrix\PromiseEffectControls;
namespace Qualimetrix\PromiseEffectCorpus;
```

`Qualimetrix\PhpStan\Rules` is the namespace sharing production's `Qualimetrix\`
prefix (`composer.json` maps `Qualimetrix\` to `src/`; `tools/phpstan/Rules/`
is a second, non-autoloaded root under the same prefix).

A first pass with the pathspec `'scripts/**/*.php'` silently dropped every
top-level file directly in `scripts/` (glue scripts have no subdirectory), so
it missed `Qualimetrix\HealthCalibration`, `QmxDirectiveNarrowControl` and
`Qualimetrix\PromiseEffectCorpus`. Re-running with the bare directory
pathspecs (`'scripts/' 'tools/'`) is what surfaced them — recorded here
because it is exactly the kind of narrowing the task warns undercounts.

Several files under `scripts/` declare **no** namespace at all (plain
functions in the global namespace): `generate-suppression-snapshot.php`,
`generate-rename-enumeration.php`, `generate-modular-architecture*.php`,
`enumerate-*.php`, `collect-benchmark-data.php`, `benchmark-regression.php`,
`directive-audit-gate.php`, `promise-effect-p1-set.php`,
`x8-overlap-sites.php`, `finding-gate/probe-channels.php`,
`finding-gate/classes.php`. These are reachable only through `require`/`include`
or subprocess — never through a `use` import — which is exactly why channel 2
is first-class here.

## Table 1 — Tooling tests

| path                                                                                             | SUT (exact tool file or namespace)                                                                                           | channel(s)                                                                 | evidence                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `tests/TestSupport/ArchitectureStaticAnalysis/Unit/BannedStringPathPropertyRuleTest.php`         | `tools/phpstan/Rules/BannedStringPathPropertyRule.php` (`Qualimetrix\PhpStan\Rules`)                                         | 1 (`use`)                                                                  | L10: `use Qualimetrix\PhpStan\Rules\BannedStringPathPropertyRule;`                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `tests/TestSupport/ArchitectureStaticAnalysis/Unit/BannedStringPathPromotedPropertyRuleTest.php` | `tools/phpstan/Rules/BannedStringPathPromotedPropertyRule.php` (`Qualimetrix\PhpStan\Rules`)                                 | 1 (`use`)                                                                  | `use Qualimetrix\PhpStan\Rules\BannedStringPathPromotedPropertyRule;` (mirrors the sibling file)                                                                                                                                                                                                                                                                                                                                                                                                             |
| `tests/Unit/PromiseEffect/ClassifierTest.php`                                                    | `scripts/promise-effect/Classifier.php` (`Qualimetrix\PromiseEffect\Classifier`)                                             | 1 (`use`) + 2 (`require_once`)                                             | L27-28: `require_once $scripts . '/promise-effect/InProcess.php'; require_once $scripts . '/promise-effect/Classifier.php';`                                                                                                                                                                                                                                                                                                                                                                                 |
| `tests/Unit/PromiseEffect/FloorTest.php`                                                         | `scripts/promise-effect/Floor.php` (`Qualimetrix\PromiseEffect\Floor`)                                                       | 1 (`use`) + 2 (`require_once`)                                             | L34-37: four `require_once $scripts . '/promise-effect/{Ledger,Classifier,Stand,Floor}.php'`                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `tests/Unit/PromiseEffect/LedgerVocabularyTest.php`                                              | `scripts/promise-effect/Ledger.php` (`Qualimetrix\PromiseEffect\Ledger`)                                                     | 1 (`use`) + 2 (`require_once`)                                             | L28: `require_once \dirname(__DIR__, 3) . '/scripts/promise-effect/Ledger.php';`                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `tests/Unit/RuleVocabulary/DirectiveAuditGateTest.php`                                           | `scripts/directive-audit/Gate.php` (`QmxDirectiveAudit\Gate`) + `scripts/finding-gate/Process.php`                           | 1 (`use`) + 2 (`require_once`)                                             | L27,41: `require_once $scripts . '/finding-gate/Process.php';` then a loop `require_once $scripts . '/directive-audit/' . $part . '.php';`                                                                                                                                                                                                                                                                                                                                                                   |
| `tests/Unit/RuleVocabulary/DirectiveAuditControlsSuiteKeyTest.php`                               | `scripts/directive-audit-controls/Suite.php` (`QmxDirectiveAuditControls\Suite`)                                             | 1 (`use`) + 2 (`require_once`)                                             | L25-26: `require_once $scripts . '/finding-gate-controls/Shell.php'; require_once $scripts . '/directive-audit-controls/Suite.php';`                                                                                                                                                                                                                                                                                                                                                                         |
| `tests/Unit/RuleVocabulary/DirectiveAuditReportReadingTest.php`                                  | `scripts/directive-audit/{VerdictReport,Population,SiteEnumeration,...}.php` (`QmxDirectiveAudit`)                           | 1 (`use`) + 2 (`require_once`)                                             | L47: loop `require_once \dirname(__DIR__, 3) . '/scripts/directive-audit/' . $part . '.php';` over 8 parts                                                                                                                                                                                                                                                                                                                                                                                                   |
| `tests/Unit/RuleVocabulary/RenameEnumerationRetirementTest.php`                                  | `scripts/generate-rename-enumeration.php` (global-namespace `retireExecutedRows()`)                                          | 2 (`require_once`) only — no `use`, since the script declares no namespace | L22: `require_once \dirname(__DIR__, 3) . '/scripts/generate-rename-enumeration.php';`                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `tests/Reporting/Formatter/Suppressed/Unit/SuppressionSnapshotKeyTest.php`                       | `scripts/generate-suppression-snapshot.php` (global-namespace `normalizeSuppressor()`)                                       | 2 (`require_once`) only                                                    | L25: `require_once \dirname(__DIR__, 5) . '/scripts/generate-suppression-snapshot.php';`                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `tests/Analysis/Evidence/ComputedMetrics/Unit/HealthCalibrationBenchTest.php`                    | `scripts/health-calibration.php` (`Qualimetrix\HealthCalibration\*`)                                                         | 1 (`use`) + 2 (`require_once`)                                             | L39: `require_once \dirname(__DIR__, 5) . '/scripts/health-calibration.php';`. Also `use`s five `src/` classes (`ComputedMetricsConfigResolver`, `ComputedMetricFormulaValidator`, `HealthFormulaExcluder`, `ComputedMetricDefinition`, `SymbolLevel`) but only to build realistic config fixtures the bench consumes (L601-603) — no assertion compares a `src/` result against the tool's; the docblock (L30-32) states the corpus-scale agreement lives in the bench's own `--self-test`, outside PHPUnit |
| `tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkRegressionClassificationTest.php`  | `scripts/benchmark-regression.php`                                                                                           | 3 (subprocess) + 5 (copy-then-run)                                         | L83 etc.: `new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', '--update-baselines'], $fixtureRoot)`; L436-437: `copy($source, $fixtureRoot . '/scripts/benchmark-regression.php')` before running it in the scratch fixture root                                                                                                                                                                                                                                                                  |
| `tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkCoverageRefusalTest.php`           | `scripts/benchmark-regression.php`, `scripts/collect-benchmark-data.php`                                                     | 3 (subprocess) + 5 (copy-then-run)                                         | L51: `new Process([\PHP_BINARY, 'scripts/benchmark-regression.php', ...])`; L71: same for `scripts/collect-benchmark-data.php`; L125-126: `copy($source, $fixtureRoot . '/scripts/' . $script)`                                                                                                                                                                                                                                                                                                              |
| `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGeneratorRefusalTest.php`     | `scripts/generate-modular-architecture-production-inventory.php`, `scripts/generate-modular-architecture-test-inventory.php` | 3 (subprocess)                                                             | L47-50: `runProcess([\PHP_BINARY, $this->root() . '/scripts/generate-modular-architecture-production-inventory.php', ...])`; L99-101, L137-139: same pattern for the test-inventory generator, asserting non-zero exit and a specific refusal message                                                                                                                                                                                                                                                        |
| `tests/Analysis/Policy/Baseline/Unit/ChannelRenameTsvGateAgreementTest.php`                      | `scripts/finding-gate/{RenameMaps,MetricVocabulary,GateError,Fs,Tsv}.php` (`QmxFindingGate`)                                 | 1 (`use`) + 2 (`require_once`)                                             | L36-40: `foreach ([...] as $file) { require_once $gate . '/' . $file; }`; the single test method (L73-90) calls only `RenameMaps::load(...)` — no `src/` reader is invoked in this file, so despite the class docblock calling itself "the other half" of a shared-corpus pair, it does not itself assert agreement (see Table 2 note)                                                                                                                                                                       |

**Count: 15 tooling test files.**

## Table 2 — Agreement tests

| path                                                             | SUT (tool side)                                                                                                    | src/ side                                                                | channel(s)                                                                                                             | evidence                                                                                                                                                                                                                                                                          |
| ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Unit/RuleVocabulary/ThresholdPopulationAgreementTest.php` | `QmxDirectiveAudit\ThresholdDirectiveScan` (`scripts/directive-audit/{EnumeratedSite,ThresholdDirectiveScan}.php`) | `Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor` | 1 (`use` of both the tool and the `src/` contract) + 2 (`require_once` for the tool half, since it has no PSR-4 entry) | L235-239, method `itMeasuresTheSamePopulationOverTheWholeFixture`: `self::assertSame(self::productSites(), self::scanSites());` — `productSites()` drives the real `ThresholdOverrideExtractor`, `scanSites()` drives `ThresholdDirectiveScan::overFile()`, over the same fixture |

**Count: 1 agreement test.**

Note on the borderline: `ChannelRenameTsvGateAgreementTest.php` (Table 1) is named and doc-commented as "the other half" of a shared-corpus pair with `ChannelRenameMapTest.php` (which drives the `src/` `ChannelRenameMap` reader over the identical `ChannelRenameTsvCorpus` fixture cases). But the two live in **separate files**, and neither file's own test method invokes both readers and compares their verdicts inside one assertion. Per the criterion's wording ("a test... that runs a `src/` reader and a tool's reader over the same input and requires them to agree"), a single test file's assertion is the unit of judgement here, so `ChannelRenameTsvGateAgreementTest.php` is filed as a tooling test, not an agreement test — the corpus-level agreement between the two files is a design property the criterion does not ask this census to certify.

## Table 3 — Considered and rejected

| path                                                                             | reason rejected                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| -------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Analysis/Evidence/Duplication/Unit/DuplicationMemoryLimitProcessTest.php` | Hit by channel 3 (`PHP_BINARY`, subprocess) and channel 2-shaped pattern (`require $argv[1]` inside a generated probe script). The probe script requires `vendor/autoload.php` and exercises `Qualimetrix\Analysis\Evidence\Duplication\HashIndexBuilder` — a `src/` class — plus the second test method shells out to `bin/qmx` directly. Product test.                                                                                                                                                                                                   |
| `tests/Analysis/Policy/Baseline/Unit/BaselineChannelRenamerTest.php`             | Hit by channel 3 (subprocess) via `writeChildScript()`. The written child script requires `vendor/autoload.php` and calls `Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer` — a `src/` class under test for compare-and-swap concurrency behaviour. Product test; the subprocess is a mechanism for observing the product's file-locking, not a repository tool.                                                                                                                                                                               |
| `tests/Functional/Console/Command/HookInstallCommandTest.php`                    | Hit by the literal-string sweep for `scripts` (builds a `scripts/pre-commit-hook.sh` fixture on disk). `#[CoversClass(HookInstallCommand::class)]` and every assertion targets `Qualimetrix\Infrastructure\Console\Command\HookInstallCommand`, a `src/` class. The "tool" content written to disk is a fake stub the test authors itself (`"#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'Running Qualimetrix'\n"`), never the real `.githooks`/`scripts/pre-commit-hook.sh` content — the test never reads or runs the real tool file. Product test. |
| `tests/Analysis/Policy/Baseline/Unit/BaselineWriterTest.php`                     | Hit by the literal-string sweep for `scripts`. L597 is a docblock cross-reference to `scripts/check-private-leaks.sh` explaining *why* a behaviour matters (CLAUDE.md §10); the script is never required, invoked, or read. Not a channel hit at all — mention only.                                                                                                                                                                                                                                                                                       |
| `tests/Analysis/Policy/Baseline/Unit/EntrySelectorTest.php`                      | Hit by the bare-word sweep for `scripts`. L44 is prose ("printed to users and ends up in their scripts") about end-user shell scripts in general, unrelated to this repository's `scripts/`. Not a channel hit.                                                                                                                                                                                                                                                                                                                                            |

## How obtained / what each channel cannot see

1. **`use` imports of a tooling namespace.**
   ```
   git grep -h "^namespace" -- 'scripts/' 'tools/'   # namespace roster (corrected after the '**/*.php' undercount)
   grep -rlE "^use (QmxDirectiveAudit|QmxDirectiveAuditControls|QmxDirectiveNarrowControl|QmxFindingGate|QmxFindingGateControls|Qualimetrix\\HealthCalibration|Qualimetrix\\InputDoorControls|Qualimetrix\\InputDoors|Qualimetrix\\PhpStan\\Rules|Qualimetrix\\PromiseEffect|Qualimetrix\\PromiseEffectControls|Qualimetrix\\PromiseEffectCorpus)\\" tests --include="*.php"
   ```
   Blind spot: sees nothing for a script with no namespace (11+ top-level `scripts/*.php` files are bare global-namespace functions) and nothing for a fully-qualified reference used without a `use` statement (checked separately below, one non-test fixture hit only).

2. **`require`/`require_once`/`include` of a tool file.**
   ```
   grep -rnE "require(_once)?\s" tests --include="*.php" | grep -E "scripts/|tools/"     # literal-path form
   grep -rnE "\.\s*['\"]\/(scripts|tools)\/" tests --include="*.php"                      # concatenated-variable form ($scripts . '/...', dirname(...) . '/...')
   ```
   This is the channel the task warned undercounts an import-only sweep, and it did here: `RenameEnumerationRetirementTest.php`, `SuppressionSnapshotKeyTest.php`, and the four `DirectiveAudit*`/`PromiseEffect` files that `require_once` through a `$scripts = dirname(...) . '/scripts'` variable would be invisible to a `use`-only sweep (some, like the two global-namespace scripts, have literally no `use` to find). Blind spot: a require built through a named constant or a helper method return value with no literal `scripts`/`tools` substring on the same or an adjacent grep-matched line — checked for (`SCRIPTS_DIR`, `TOOLS_DIR`, `scriptsRoot`, etc.), none found.

3. **Sub-process invocation.**
   ```
   grep -rlE "new Process\(|proc_open\(|shell_exec\(|passthru\(|\bexec\(|PHP_BINARY|escapeshellarg\(|\`[^\`]*\`" tests --include="*.php"   # 311 files, dominated by bin/qmx product tests
   grep -rnE "['\"](\.\./)*(scripts|tools)/[a-zA-Z0-9_.\/-]+['\"]" tests --include="*.php"                                                # narrowed to literal scripts/tools targets
   grep -rnE "\.\s*['\"]\/(scripts|tools)\/" tests --include="*.php"                                                                       # narrowed to concatenated scripts/tools targets
   ```
   Manually distinguished `bin/qmx` invocations (product; the large majority of the 311) from `scripts/*.php` invocations (tool). Blind spot: the 311-file superset was narrowed by grepping for the literal substrings `scripts/`/`tools/` on the invocation call or a `copy()`/`file_get_contents()` line nearby; a subprocess target built from a path with no such substring anywhere in the file would not surface. None of the 311 files showed evidence of this pattern on inspection of the narrowed set, but the full 311 were not read file-by-file.

4. **A tool file read as text/data.**
   ```
   grep -rnE "(file_get_contents|md5_file|sha1_file|hash_file|file\()\s*\(" tests --include="*.php" | grep -E "scripts/|tools/"
   ```
   Zero hits inside `tests/`. `ModularArchitectureGeneratorRefusalTest.php` reads files as text with `file_get_contents($sourcePath)`, but `$sourcePath` points at `src/` files being mutated into fixtures for the tool to run over — not the tool's own source. No test in this tree hashes or parses a tool file's own bytes. Blind spot: same as above — a read via a non-literal path would not surface; not found on the narrowed candidate set.

5. **Copying a tool into a scratch directory and running it there.**
   Found by inspecting the `.\s*['"]\/(scripts|tools)\/` matches for `copy(...)` calls specifically: `BenchmarkRegressionClassificationTest.php` (L436-437) and `BenchmarkCoverageRefusalTest.php` (L125-126) both `copy()` a `scripts/*.php` file into a fixture root before invoking it there via `Process`. No `tools/` file is ever copied. Blind spot: none identified beyond channel 2/3's own blind spots, since this channel was found as a specialization of those two searches rather than an independent one.

6. **Anything else found — Python module loading.** Two non-PHP files (see below) load a `scripts/*.py` file as a Python module via `importlib.util.spec_from_file_location` / `exec_module`, a channel with no PHP analogue. `test_phpunit_aggregate.py` additionally invokes its subject via `subprocess.run([sys.executable, str(RUNNER), ...])` (channel 3's Python shape).

## Non-PHP test files under `tests/`

```
find tests -name "*.py" -not -path "*/__pycache__/*"
```

| path                                                                      | judgement            | reason                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------- | -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Analysis/Evidence/Measurement/Tests/test_cross_tool_comparison.py` | Tooling test         | SUT is `scripts/cross-tool-comparison.py`, loaded as a module (`SCRIPT = Path(__file__).parents[5] / "scripts" / "cross-tool-comparison.py"`, L13; `importlib.util.spec_from_file_location(...)`, L15-18). Own docstring: "Deterministic tests for cross-tool methodology; no live tools are invoked" (L2) — it tests the comparison script's parsing/spec logic against fixture data it builds itself, not `src/`.                                                                                       |
| `tests/System/TestRunnerConfiguration/Tests/test_phpunit_aggregate.py`    | Tooling test         | SUT is `scripts/phpunit-aggregate.py` (`RUNNER = PROJECT_ROOT / "scripts/phpunit-aggregate.py"`, L20), reached both by module load (L26) and by `subprocess.run([sys.executable, str(RUNNER), ...])` (L59-62, L227, L294, L316-317). Asserts on the runner's suite-partition logic, exit behaviour and command construction — a repository tool that keeps the PHPUnit suite tuple in sync (this same file is itself named in `AGENTS.md`'s registration table as a place a new test root must be added). |
| `tests/System/TestRunnerConfiguration/Tests/Fixtures/fake_phpunit.py`     | Not a test (fixture) | No `test_*` naming, not collected as a test case by either `unittest` or the project's Python runner; it is the fake PHPUnit binary `test_phpunit_aggregate.py` points `--phpunit=` at. Listed here for completeness per the task's instruction not to silently include or exclude it, but it is not one of the "non-PHP test files" being judged — it is fixture support for one.                                                                                                                        |

**Count: 2 Python tooling tests, 1 non-test Python fixture file.**

## Validation

First derivation: union of the per-channel searches above, arrived at
incrementally as each channel was swept (namespace `use`, then `require`
literal, then `require` concatenated, then subprocess/copy literal,
then subprocess/copy concatenated) → **15 tooling + 1 agreement + 2
rejected-on-inspection = 18 PHP files touched by some channel**.

Second derivation, a single combined regex run independently after the fact:

```
grep -rlE "QmxDirectiveAudit|QmxDirectiveAuditControls|QmxDirectiveNarrowControl|QmxFindingGate|QmxFindingGateControls|Qualimetrix\\HealthCalibration|Qualimetrix\\InputDoorControls|Qualimetrix\\InputDoors|Qualimetrix\\PhpStan\\Rules|Qualimetrix\\PromiseEffect|scripts/|tools/phpstan|'/scripts'|\"/scripts\"" tests --include="*.php" | grep -v Fixtures | sort -u
```

Result: the same 18 files, byte-for-byte the same list as the incremental
union. **The two derivations agree.**

Of those 18: 15 are tooling tests (Table 1), 1 is an agreement test (Table 2),
2 were rejected as product tests or mention-only (`HookInstallCommandTest.php`,
`BaselineWriterTest.php`; `EntrySelectorTest.php` was caught only by a
broader bare-word variant of the same search and is listed in Table 3 too).
`DuplicationMemoryLimitProcessTest.php` and `BaselineChannelRenamerTest.php`
were caught by the separate channel-3 subprocess sweep (they contain no
`scripts/`/`tools/` substring, only `PHP_BINARY`/`Process`) and are recorded
in Table 3 for that reason — the combined regex above does not re-surface
them, which is expected since it targets channels 1/2/5 substrings, not bare
subprocess primitives.

## Counts

- Total `*Test.php` files under `tests/` (starting population): **632**
- Table 1 (tooling tests): **15**
- Table 2 (agreement tests): **1**
- Table 3 (considered and rejected): **5** (`DuplicationMemoryLimitProcessTest.php`, `BaselineChannelRenamerTest.php`, `HookInstallCommandTest.php`, `BaselineWriterTest.php`, `EntrySelectorTest.php`)
- Non-PHP tests under `tests/`: **2** Python tooling tests, plus **1** non-test Python fixture file (not counted as a test)
