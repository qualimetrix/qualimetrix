# Pipeline Pete: CI/CD Integration Testing

**Persona:** DevOps engineer setting up AI Mess Detector in CI/CD pipelines.
**Date:** 2026-03-15
**Version tested:** 0.1.0

---

## Executive Summary

AI Mess Detector provides solid CI/CD integration fundamentals. Exit codes work correctly with `--fail-on` controlling the threshold. All five structured output formats (SARIF, GitLab Code Quality, Checkstyle, GitHub Actions, JSON) produce valid output with correct structure. The baseline workflow functions end-to-end: generate, apply, zero violations on replay. Two areas need attention for production CI use: (1) SARIF output lacks several fields that GitHub Advanced Security and VS Code expect (ruleIndex, fullDescription, helpUri, partialFingerprints), and (2) the default `--fail-on` behavior (warning) means most real projects will fail CI immediately without tuning.

---

## 1. Exit Code Behavior

### Matrix

| Scenario                  | Violations       | `--fail-on`       | Exit Code | Correct?                |
| ------------------------- | ---------------- | ----------------- | --------- | ----------------------- |
| Errors + warnings present | 578 (141E, 437W) | default (warning) | 2         | Yes                     |
| Errors + warnings present | 578 (141E, 437W) | `error`           | 2         | Yes                     |
| Errors + warnings present | 578 (141E, 437W) | `warning`         | 2         | Yes                     |
| Warnings only (3W, 0E)    | 3 warnings       | default           | 1         | Yes                     |
| Warnings only (3W, 0E)    | 3 warnings       | `error`           | 0         | Yes                     |
| No violations (baseline)  | 0                | default           | 0         | Yes                     |
| No files (git:staged)     | 0                | default           | 0         | Yes                     |
| Invalid `--fail-on=none`  | -                | `none`            | 1 (error) | Yes, good error message |

### Exit Code Semantics

| Code | Meaning                                              |
| ---- | ---------------------------------------------------- |
| 0    | No violations exceeding `--fail-on` threshold        |
| 1    | Warnings found (when `--fail-on=warning` or default) |
| 2    | Errors found                                         |

### Observations

- **Exit codes are predictable and correct.** `--fail-on=error` correctly ignores warnings (exit 0 when only warnings present).
- Exit code 1 = warnings only, exit code 2 = errors present. This is a good convention (higher code = more severe).
- Default `--fail-on` is `warning`, meaning most real-world projects will fail CI out of the box. This is intentional but should be documented clearly for CI setup guides.
- `--fail-on=none` is not supported (only `warning` and `error` are valid). This means there's no way to run analysis without failing on anything while still getting output. Workaround: `|| true` in CI scripts.

---

## 2. Format Audit

### 2.1 SARIF (`--format=sarif`)

**Structure:** Valid SARIF 2.1.0 with correct schema reference.

| Field                        | Status         | Notes                                                          |
| ---------------------------- | -------------- | -------------------------------------------------------------- |
| `$schema`                    | Present        | Points to OASIS sarif-schema-2.1.0.json                        |
| `version`                    | Present        | "2.1.0"                                                        |
| `runs[].tool.driver.name`    | Present        | "AI Mess Detector"                                             |
| `runs[].tool.driver.version` | Present        | "0.1.0"                                                        |
| `runs[].tool.driver.rules[]` | Present        | 14 rules with id, name, shortDescription, defaultConfiguration |
| `runs[].results[]`           | Present        | 83 results with ruleId, level, message, locations              |
| File URIs                    | Relative paths | `src/Core/...` (no `file://` prefix)                           |
| `ruleIndex`                  | **Missing**    | Not present in any result                                      |
| `fullDescription` on rules   | **Missing**    | Only shortDescription present                                  |
| `helpUri` on rules           | **Missing**    | No link to documentation                                       |
| `help` on rules              | **Missing**    | No inline help text                                            |
| `partialFingerprints`        | **Missing**    | Needed for GitHub GHAS deduplication                           |
| `automationDetails`          | **Missing**    | Optional but recommended                                       |
| `invocations`                | **Missing**    | Optional but useful for debugging                              |
| `columnKind`                 | **Missing**    | Optional                                                       |

**Verdict:** Valid SARIF that most tools will accept. However, GitHub Advanced Security specifically benefits from `partialFingerprints` (for alert deduplication across runs) and `helpUri` (for linking to documentation). VS Code SARIF Viewer works with minimal SARIF but `ruleIndex` improves performance. The missing `fullDescription` means rule details won't show in GHAS.

**Recommendation:** Add `ruleIndex` to results, `fullDescription` and `helpUri` to rules, and `partialFingerprints` to results for GitHub GHAS compatibility. These are the most impactful improvements.

### 2.2 GitLab Code Quality (`--format=gitlab`)

**Structure:** Valid GitLab Code Quality JSON array.

| Field                  | Status   | Notes                                         |
| ---------------------- | -------- | --------------------------------------------- |
| Array of objects       | Correct  | 83 objects                                    |
| `description`          | Present  | Human-readable violation message              |
| `check_name`           | Present  | Rule ID (e.g., `code-smell.boolean-argument`) |
| `fingerprint`          | Present  | MD5 hash, **all unique** (83/83)              |
| `severity`             | Present  | `major` and `critical` only                   |
| `location.path`        | Relative | Correct (e.g., `src/Core/...`)                |
| `location.lines.begin` | Present  | Line number                                   |

**Verdict:** Production-ready for GitLab CI. Fingerprints are unique, paths are relative, severity mapping is correct. The format exactly matches what GitLab expects.

**Observation:** Only `major` and `critical` severities are used. GitLab also supports `info`, `minor`, and `blocker`. Mapping warnings to `major` and errors to `critical` is a reasonable choice.

### 2.3 Checkstyle (`--format=checkstyle`)

**Structure:** Valid XML with checkstyle version 3.0.

| Field                  | Status         | Notes                                                   |
| ---------------------- | -------------- | ------------------------------------------------------- |
| XML declaration        | Present        | UTF-8                                                   |
| `<checkstyle version>` | Present        | "3.0"                                                   |
| `<file name>`          | Present        | Relative paths                                          |
| `<error line>`         | Mostly present | 78/83 have line, 5 missing (namespace-level violations) |
| `<error severity>`     | Present        | `warning` or `error`                                    |
| `<error message>`      | Present        | Human-readable                                          |
| `<error source>`       | Present        | `aimd.` prefixed rule ID                                |

**Verdict:** Valid XML, parseable by standard Checkstyle consumers (Jenkins, SonarQube, etc.). The 5 errors without `line` attribute are namespace-level violations where no specific line applies -- this is acceptable and handled correctly by most consumers.

**Observation:** File paths are relative. The `source` attribute uses `aimd.` prefix convention which is good for filtering in multi-tool setups.

### 2.4 GitHub Actions (`--format=github`)

**Structure:** Standard GitHub Actions workflow commands.

| Field                   | Status  | Notes                |
| ----------------------- | ------- | -------------------- |
| `::warning` annotations | Present | 38 warnings          |
| `::error` annotations   | Present | 45 errors            |
| `file=` parameter       | Present | Relative paths       |
| `line=` parameter       | Present | Line numbers         |
| `title=` parameter      | Present | Rule ID              |
| Message                 | Present | After `::` separator |

**Verdict:** Production-ready. GitHub Actions will display these as inline annotations on PRs. The format is correct per GitHub's workflow command specification. Severity mapping (warning/error) correctly matches violation severity.

**Observation:** Percent signs are correctly URL-encoded (`%25`) in messages, which is required by the GitHub Actions format.

### 2.5 JSON (`--format=json`)

**Structure:** Summary-oriented JSON with top-level sections.

| Section           | Contents                                                                                  |
| ----------------- | ----------------------------------------------------------------------------------------- |
| `meta`            | version, package, timestamp                                                               |
| `summary`         | filesAnalyzed, filesSkipped, duration, violationCount, errorCount, warningCount, techDebt |
| `health`          | Per-category health scores                                                                |
| `worstNamespaces` | Top worst namespaces                                                                      |
| `worstClasses`    | Top worst classes                                                                         |
| `violations`      | Top 50 violations (capped for readability)                                                |

**Verdict:** Well-structured for CI dashboards and custom integrations. The top-50 violation cap is reasonable for summary use; full output available via `--detail` or other formats.

### 2.6 Metrics JSON (`--format=metrics-json`)

**Structure:** Detailed per-symbol metrics data.

| Section     | Contents                 |
| ----------- | ------------------------ |
| `version`   | Format version           |
| `package`   | Package name             |
| `timestamp` | Analysis timestamp       |
| `symbols`   | Per-symbol metric values |
| `summary`   | Aggregated statistics    |

**Verdict:** Suitable for custom metric tracking dashboards, trend analysis, and data pipelines.

---

## 3. Baseline Workflow

### End-to-End Test

```
Step 1: Generate baseline
$ bin/aimd check src/Core/ --generate-baseline=/tmp/baseline.json
Result: "Baseline with 83 violations written to /tmp/baseline.json"
Exit code: 2 (violations exist)

Step 2: Run with baseline
$ bin/aimd check src/Core/ --baseline=/tmp/baseline.json
Result: "No violations found."
Exit code: 0
```

**Baseline file structure:**
```json
{
  "version": 4,
  "generated": "2026-03-15T14:17:20+00:00",
  "count": 67,
  "violations": {
    "class:AiMessDetector\\Core\\Ast\\FileParserInterface": [
      {"rule": "coupling.instability", "hash": "4e3d6d344f02c9b5"}
    ]
  }
}
```

### Observations

- Baseline version is 4 (documented as only supported version).
- Violations are keyed by symbol path (class/namespace), not file path. This is more robust against file moves.
- Each violation is identified by rule + hash, which should survive minor code changes.
- The `count` field (67) differs from the violation count reported by the tool (83). This is because count represents unique symbols, not individual violations. Not a bug, but slightly confusing.
- **The baseline workflow works correctly end-to-end.** All 83 violations suppressed, exit code 0.
- The `--baseline-ignore-stale` flag is available for handling stale entries gracefully.

---

## 4. Output Options

### `-o` Flag (Output to File)

```
$ bin/aimd check src/Core/ --format=sarif -o /tmp/output.sarif
Result: "Report written to /tmp/output.sarif"
File size: 83,468 bytes
Valid JSON: Yes
```

- Works correctly. File is written and contains valid output.
- Console shows confirmation message ("Report written to ...").
- Exit code still reflects violation severity (exit 2).

### Git Scope Options

| Option                      | Test            | Result                                                              |
| --------------------------- | --------------- | ------------------------------------------------------------------- |
| `--analyze=git:staged`      | No staged files | "0 files analyzed (partial)", exit 0                                |
| `--report=git:HEAD~1..HEAD` | Recent commits  | Full analysis, violations filtered to changed files only            |
| `--report=git:HEAD~2..HEAD` | 2 commits       | Full analysis, "No violations found" (only reporting changed files) |

**Observations:**
- `--analyze=git:staged` correctly limits which files are parsed (saves time).
- `--report=git:range` runs full analysis but only reports violations in changed files. This is the right approach for PR checks.
- The "(partial)" indicator on `--analyze` output is helpful for distinguishing partial from full runs.
- Health scores are marked "unavailable in partial analysis mode" for `--analyze` scopes, which is correct (can't compute project health from a subset).

---

## 5. Issues Found

### Issue 1: SARIF Missing ruleIndex (MEDIUM)

**Impact:** Some SARIF consumers (VS Code SARIF Viewer) use `ruleIndex` for efficient rule lookup. Without it, the consumer must do string matching on `ruleId`.

**Recommendation:** Add `ruleIndex` to each result, referencing the index in `tool.driver.rules[]`.

### Issue 2: SARIF Missing Fields for GitHub Advanced Security (MEDIUM)

**Missing fields:**
- `partialFingerprints` -- needed for GHAS alert deduplication across runs
- `fullDescription` on rules -- only `shortDescription` is present
- `helpUri` on rules -- no link to documentation pages

**Impact:** GHAS will work but with degraded experience: no dedup between runs, no documentation links, no detailed rule descriptions in the Security tab.

**Recommendation:** Add these fields. `helpUri` can point to the website documentation pages. `partialFingerprints` can use the same hash as the baseline.

### Issue 3: No `--fail-on=none` Option (LOW)

**Impact:** No clean way to run analysis without any failure threshold. Users must use `|| true` in CI scripts.

**Recommendation:** Consider adding `--fail-on=none` or `--no-fail` flag for informational runs.

### Issue 4: Baseline `count` vs Violation Count Mismatch (LOW)

**Impact:** The baseline file shows `"count": 67` but the tool reports 83 violations. The count field represents unique symbols, not violations. Could confuse users inspecting the baseline file.

**Recommendation:** Rename the field to `symbolCount` or add a `violationCount` field, or document the distinction.

### Issue 5: Checkstyle Missing Line Numbers (LOW)

**Impact:** 5 out of 83 violations in checkstyle output have no `line` attribute. These are namespace/project-level violations. Most Checkstyle consumers handle this gracefully, but some may not.

**Recommendation:** Consider using line 0 or line 1 as fallback for namespace-level violations.

---

## 6. CI Integration Readiness

### Overall Assessment: **READY FOR PRODUCTION USE**

The tool provides all the essential building blocks for CI/CD integration:

| Capability                  | Status            | Notes                                  |
| --------------------------- | ----------------- | -------------------------------------- |
| Predictable exit codes      | **Ready**         | 0/1/2 with `--fail-on` control         |
| SARIF output                | **Ready** (basic) | Valid but missing GHAS-specific fields |
| GitLab Code Quality         | **Ready**         | Fully compliant                        |
| Checkstyle                  | **Ready**         | Valid XML, minor line number gaps      |
| GitHub Actions              | **Ready**         | Correct annotation format              |
| JSON for custom tools       | **Ready**         | Well-structured                        |
| Baseline workflow           | **Ready**         | Full end-to-end support                |
| File output (-o)            | **Ready**         | Works correctly                        |
| Git scope filtering         | **Ready**         | Both --analyze and --report work       |
| Parallel processing control | **Ready**         | --workers=0 for deterministic CI       |

### Sample CI Configurations

**GitHub Actions (basic):**
```yaml
- name: AIMD Check
  run: bin/aimd check src/ --format=github --fail-on=error --no-cache --workers=0
```

**GitHub Actions (SARIF upload):**
```yaml
- name: AIMD Check
  run: bin/aimd check src/ --format=sarif -o results.sarif --no-cache --workers=0 || true
- name: Upload SARIF
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

**GitLab CI:**
```yaml
code_quality:
  script:
    - bin/aimd check src/ --format=gitlab --no-cache --workers=0 > gl-code-quality-report.json || true
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

**Baseline adoption (gradual):**
```yaml
# First run: generate baseline
- bin/aimd check src/ --generate-baseline=baseline.json --no-cache --workers=0
# Subsequent runs: only fail on new violations
- bin/aimd check src/ --baseline=baseline.json --fail-on=error --no-cache --workers=0
```

---

## 7. Recommendations

### Priority 1 (For GHAS users)
1. Add `ruleIndex` to SARIF results
2. Add `partialFingerprints` to SARIF results for cross-run deduplication
3. Add `fullDescription` and `helpUri` to SARIF rule definitions

### Priority 2 (UX improvements)
4. Add `--fail-on=none` or `--no-fail` flag for informational CI runs
5. Clarify baseline `count` field (rename to `symbolCount` or add `violationCount`)

### Priority 3 (Nice to have)
6. Add `columnKind` and `invocations` to SARIF for richer debugging
7. Consider fallback line numbers (0 or 1) for namespace-level Checkstyle violations
8. Document exit code semantics in `--help` output or man page

### Not Needed
- No changes needed for GitLab Code Quality (fully compliant)
- No changes needed for GitHub Actions format (correct)
- No changes needed for JSON format (well-designed)
- No changes needed for exit code logic (correct and predictable)
