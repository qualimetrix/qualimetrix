# 05 — Pipeline Patty: CI/CD Integration Audit

**Persona:** DevOps engineer integrating AIMD into CI pipelines
**Projects:** Doctrine DBAL (432 files), Composer (286 files)
**Focus:** Is AIMD production-ready for CI automation?

## Summary

AIMD is mostly ready for CI. Exit codes work correctly, parallel workers produce deterministic results, SARIF and GitLab formats pass structural validation, and the baseline workflow suppresses all known violations. However, there are three issues that will break automation at scale: (1) duplicate violations appear across all output formats for certain files, causing inflated violation counts, duplicate SARIF entries, and fingerprint collisions in GitLab; (2) `--generate-baseline` exits 2 instead of 0, preventing its use in CI pipelines without `|| true`; and (3) the baseline "written" message reports the raw violation count, not the deduplicated count stored in the file, confusing operators.

## Projects Overview

| Project       | Files | Violations | Errors | Warnings | Tech Debt |
| ------------- | ----- | ---------- | ------ | -------- | --------- |
| Doctrine DBAL | 432   | 880        | 281    | 599      | 51d 4h    |
| Composer      | 286   | 2694       | 964    | 1730     | 138d 2h   |

*Note: duplication.code-duplication disabled for both runs to avoid OOM (512 MB default limit).*

## Format Validation

### SARIF

**Valid SARIF 2.1.0.** Schema declaration present, all required fields populated.

| Field                            | Status                                                                                                                                                                                                                                             |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `$schema`                        | `https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json`                                                                                                                                                   |
| `version`                        | `2.1.0`                                                                                                                                                                                                                                            |
| `tool.driver.rules`              | 36 rules defined                                                                                                                                                                                                                                   |
| `rules[*].id`                    | Present                                                                                                                                                                                                                                            |
| `rules[*].shortDescription`      | Present                                                                                                                                                                                                                                            |
| `rules[*].fullDescription`       | Present                                                                                                                                                                                                                                            |
| `rules[*].helpUri`               | Present — but **all 36 rules point to the same generic URL** (repository root, not rule-specific docs)                                                                                                                                             |
| `results[*].ruleIndex`           | Present, all valid (0–35 range, none out of bounds)                                                                                                                                                                                                |
| `results[*].partialFingerprints` | Present — `primaryLocationLineHash` with violation fingerprint                                                                                                                                                                                     |
| `results[*].locations`           | **74 results have no `locations` array** — all are `architecture.circular-dependency` violations (class-level, no file location). This is technically valid per SARIF 2.1.0 spec. GitHub Advanced Security may not surface these in the diff view. |
| Duplicate results                | **3 duplicate result groups** (7 total extra entries) — same ruleId, same URI, same line, same message. Root cause is a duplicate violation generation bug (see Findings).                                                                         |

### GitLab Code Quality

**Valid GitLab Code Quality format.** Array of objects, all required fields present.

| Field                  | Status                                                                                                                                                                                                                                                             |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Structure              | JSON array of objects                                                                                                                                                                                                                                              |
| `description`          | Present                                                                                                                                                                                                                                                            |
| `check_name`           | Present                                                                                                                                                                                                                                                            |
| `fingerprint`          | Present on all 880 entries                                                                                                                                                                                                                                         |
| `severity`             | `critical` (errors) / `major` (warnings) — both are valid GitLab severity values                                                                                                                                                                                   |
| `location.path`        | **74 entries have `path: "."` ** — all `architecture.circular-dependency` violations. GitLab spec does not accept `"."` as a valid path; these violations will be silently ignored or cause display errors in the MR widget.                                       |
| `location.lines.begin` | Present on all entries                                                                                                                                                                                                                                             |
| Fingerprint uniqueness | **5 duplicate fingerprints** — 3 groups, 5 extra entries (same check_name, path, line, message → identical md5). Root cause is the duplicate violation bug. In GitLab, duplicate fingerprints cause one entry to silently overwrite another in the MR diff widget. |
| Severity values        | Only `critical` and `major` used. Missing: `blocker`, `minor`, `info`. Severity mapping: Error → `critical`, Warning → `major`. No `blocker` used even for the most severe violations.                                                                             |

### Checkstyle

**Valid XML.** All violations have line numbers.

| Field                    | Status                                                                                                                                                                    |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Root tag                 | `<checkstyle version="3.0">`                                                                                                                                              |
| `<file>` elements        | 282                                                                                                                                                                       |
| Total `<error>` elements | 880                                                                                                                                                                       |
| `line` attribute         | Present on all 880 errors                                                                                                                                                 |
| `column` attribute       | **Missing on all 880 errors** — `column` is optional in Checkstyle spec but many consumers (Jenkins, SonarQube) use it for precise highlighting                           |
| `severity` values        | `error` / `warning` — valid Checkstyle values                                                                                                                             |
| `source` format          | `aimd.<rule-name>` — consistent prefix, recognized by most Checkstyle consumers                                                                                           |
| Duplicate errors         | **3 duplicate error groups** (5 extra entries) — same file, line, source, message. Checkstyle consumers may deduplicate or count them double; behavior is tool-dependent. |

## Baseline Workflow

### Generate

```
bin/aimd check benchmarks/vendor/composer/composer/src --generate-baseline=baseline.json
```

- **2694 violations** at runtime, **2368 entries** stored in baseline (deduplication by `canonical:hash` removes ~326 identical hashes)
- **Exit code: 2** — baseline generation fails the CI step even though its purpose is to capture the current state. Requires `|| true` workaround in CI.
- **Output message bug**: `"Baseline with 2694 violations written"` — reports `count($violations)` (raw list) but the file stores `2368` (`violationCount` key). Operators seeing "2694 written" but inspecting JSON and finding `"violationCount":2368` will lose trust.

### Baseline Structure (v4)

| Key              | Value                                                                     |
| ---------------- | ------------------------------------------------------------------------- |
| `version`        | `4`                                                                       |
| `violationCount` | 2368                                                                      |
| `count`          | 2368 (alias)                                                              |
| `generated`      | ISO-8601 timestamp                                                        |
| `violations`     | Dict keyed by canonical symbol path (`class:`, `method:`, `file:`, `ns:`) |

The baseline correctly includes namespace-level (`ns:`) violations, not just file/class/method.

### Apply

```
bin/aimd check benchmarks/vendor/composer/composer/src --baseline=baseline.json
```

- **Result: "No violations found"** — all 2694 violations suppressed correctly
- **Exit code: 0** — correct
- Works with `--fail-on=error` and `--fail-on=none` as expected

## Exit Codes

| Invocation                                        | Expected                 | Actual | Status              |
| ------------------------------------------------- | ------------------------ | ------ | ------------------- |
| Default (violations exist)                        | 2                        | 2      | PASS                |
| `--fail-on=none`                                  | 0                        | 0      | PASS                |
| `--fail-on=error` (only warnings, no errors)      | N/A (has errors too)     | 2      | PASS                |
| `--fail-on=warning` (warnings exist)              | 2                        | 2      | PASS                |
| `--generate-baseline` (violations exist)          | 0 (or user-configurable) | 2      | FAIL — see findings |
| `--baseline` (all suppressed)                     | 0                        | 0      | PASS                |
| `--baseline` + `--fail-on=error` (all suppressed) | 0                        | 0      | PASS                |

## Worker Consistency

Workers=0 (sequential) and workers=4 (parallel) produce **identical results**:

| Metric                | workers=0 | workers=4 |
| --------------------- | --------- | --------- |
| Violations            | 880       | 880       |
| complexity score      | 62.4011   | 62.4011   |
| cohesion score        | 71.4697   | 71.4697   |
| coupling score        | 47.2682   | 47.2682   |
| typing score          | 99.6960   | 99.6960   |
| maintainability score | 76.9130   | 76.9130   |
| overall score         | 69.6848   | 69.6848   |

Parallel execution is deterministic. No race conditions observed.

## Findings

### HIGH

#### H1: Duplicate violations generated for same rule/file/line

**What happened:** Running AIMD on `Doctrine\DBAL\Driver\Mysqli\Result.php` produces **4 identical** `code-smell.unused-private` violations for the same property (line 51, same message, same symbol). Running on `Doctrine\DBAL\Portability\Converter.php` produces **2 identical** `code-smell.boolean-argument` violations for each bool-parameter method (lines 41 and 175).

**Confirmed in:** All output formats (JSON, SARIF, GitLab, Checkstyle).

**Root cause (partially traced):** The violation list contains true duplicates — identical `rule`, `file`, `line`, `symbol`, `message`. The duplication happens upstream in metric collection or rule execution, not in the formatters. For `unused-private`, the class has 1 unused promoted property (`$statementReference`), yet 4 entries appear in the DataBag for `unusedPrivate.property`. For `boolean-argument`, 2 bool parameters are defined on the same line (multi-param signature), and each generates an identical violation (same line, identical message template, no per-parameter information).

**Impact on CI:**
- Inflated violation counts (880 instead of ~873)
- **GitLab**: 5 duplicate fingerprints → 5 violations silently overwrite each other in the MR widget (lost signal)
- **SARIF**: 3 result groups with 2–4 identical entries → GHAS deduplicates by `partialFingerprints`, so these may or may not appear correctly depending on GHAS version
- **Checkstyle**: duplicate errors may cause double-counting in CI dashboards (Jenkins warnings plugin counts all entries)
- **Baseline**: duplicates inflate the baseline entry count and cause 4 baseline entries to be burned for what is logically 1 violation

**Expected:** Each unique violation (rule + symbol + line + code) should appear exactly once.

#### H2: `--generate-baseline` exits 2 when violations found

**What happened:** `bin/aimd check ... --generate-baseline=baseline.json` exits with code **2** (violations found), even when the baseline was successfully written.

**Expected:** Baseline generation should exit 0 (or at most 1). The purpose of the command is to *capture* the current state, not to assert the codebase is clean. Exiting 2 means the CI step that generates the baseline will always fail.

**Workaround:** `bin/aimd check ... --generate-baseline=baseline.json --fail-on=none`

**Impact on CI:** Teams that add baseline generation to CI without `--fail-on=none` will see a failing step. This is non-obvious because the output says "Baseline written" right before a non-zero exit.

**Affected file:** `src/Infrastructure/Console/ResultPresenter.php` — `generateBaseline()` does not affect exit code logic, which is determined separately by violation count and `--fail-on`.

### MEDIUM

#### M1: Baseline "written" message reports raw count, not stored count

**What happened:** Output says `"Baseline with 2694 violations written"`. The baseline file contains `"violationCount": 2368`. Discrepancy: 326 violations.

**Root cause:** `ResultPresenter::generateBaseline()` uses `\count($violations)` (the raw violation list, including duplicates and variants) while `BaselineGenerator::generate()` deduplicates by `canonical:hash`. The counts diverge whenever multiple violations hash to the same key (e.g., same rule fired on the same symbol at the same line multiple times due to H1, or same violation recorded by multiple levels).

**Impact on CI:** Operators auditing baseline generation see a mismatch between the log and the file. When scripting baseline validation (e.g., `jq .violationCount baseline.json`), the count does not match what was reported. Confuses "how many violations are known" tracking.

**Fix:** Change `\count($violations)` to `$baseline->count()` in the message.

#### M2: GitLab path `"."` for 74 project-level violations

**What happened:** `architecture.circular-dependency` violations have `"location": {"path": ".", "lines": {"begin": 1}}` in GitLab Code Quality output.

**Root cause:** `GitLabCodeQualityFormatter` uses `'.'` as fallback when `$violation->location->isNone()`. The GitLab Code Quality spec requires `path` to be a relative file path from the repository root. `"."` is the current directory, not a file, and is not a valid path for this field.

**Impact on CI:** 74 circular dependency violations are either silently dropped or cause a parse error in the GitLab MR widget. Teams relying on circular dependency detection via GitLab CI will not see these violations in their MR view.

**Fix:** Consider using a synthetic path like `"circular-dependency-report.txt"` or the first file in the dependency chain, similar to how the SARIF formatter handles project-level violations (omitting `locations` entirely, which is valid).

#### M3: SARIF `helpUri` points to repository root for all 36 rules

**What happened:** Every rule's `helpUri` is `https://github.com/FractalizeR/php_ai_mess_detector`. No rule-specific documentation links.

**Impact on CI:** GHAS "Learn more" links in the Security tab all point to the repository root. Engineers clicking to understand a specific rule get no useful guidance. This is a significant UX gap for teams adopting AIMD in GitHub-integrated workflows. SARIF spec recommends rule-specific URIs.

**Improvement:** Link to specific rule documentation pages on the project website (e.g., `https://aimd.example.com/docs/rules/complexity-cyclomatic`).

#### M4: Checkstyle missing `column` attribute

**What happened:** All 880 `<error>` elements have `line` but no `column` attribute.

**Impact on CI:** Minor. `column` is optional per spec, but SonarQube, Jenkins (Warnings Next Generation plugin), and some IDE integrations use it for cursor placement when navigating to violations. Missing column defaults to column 1, which is acceptable but not ideal for method/class-level violations.

### LOW

#### L1: OOM crash without `--disable-rule=duplication.code-duplication`

**What happened:** Running `bin/aimd check benchmarks/vendor/doctrine/dbal/src` without disabling duplication detection crashes with:
```
Fatal error: Allowed memory size of 536870912 bytes exhausted (tried to allocate 33554432 bytes)
  in src/Analysis/Duplication/DuplicationDetector.php on line 246
```
Exit code: 1 (fatal, not 2).

**Impact on CI:** Teams running AIMD in CI containers with default PHP memory limits (often 128–256 MB) will encounter OOM crashes on medium/large projects. The error is not caught and returns exit code 1 (unexpected error), not 2 (violations found). Scripts checking for exit code 2 will incorrectly treat this as a success.

**Mitigation documented:** The CLAUDE.md mentions this is expected and auto-skipped when `duplication.code-duplication` is disabled. But the default behavior on projects >~300 files crashes silently. Recommend: auto-detect available memory and skip duplication analysis with a warning instead of crashing.

#### L2: No OOM warning in default text output

Directly related to L1: when duplication analysis is enabled and runs near the memory limit, there is no early warning. The crash happens mid-analysis with a PHP fatal error on stderr, leaving no structured output on stdout. CI systems capturing stdout for format parsing receive empty output and no structured error.

#### L3: JSON `health` field is a dict, not an array

**What happened:** The methodology script assumed `health` is a list (array), but it is a dict keyed by health dimension name. Not a bug per se — the structure is well-defined — but it may trip up consumers who expect a JSON array for iteration.

**Observation:** The `violationsMeta.truncated: true` flag correctly indicates when the top-50 violation limit is hit, which is good for CI scripts.

## What Works Well

- **Exit codes**: Correctly return 0 / 2 based on violations and `--fail-on` level. The `--fail-on=none` override is the recommended CI pattern.
- **Worker consistency**: Results are identical with workers=0 and workers=4. Parallelism is safe.
- **Baseline apply**: Correctly suppresses all baseline violations, exits 0. The `canonical:hash` matching is robust.
- **SARIF structure**: Valid 2.1.0 with `ruleIndex`, `partialFingerprints`, and proper schema URI. GHAS-compatible.
- **GitLab severities**: Only valid values (`critical`, `major`) used.
- **Checkstyle**: Valid XML, `line` attribute present on all errors, `source` prefixed with `aimd.`.
- **Checkstyle `source` format**: `aimd.code-smell.boolean-argument` — consistent, parseable, works with SonarQube rule mapping.
- **Determinism**: Multiple runs on the same input produce identical output (tested across formats and worker counts).
