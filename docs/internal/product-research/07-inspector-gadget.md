# Inspector Gadget — Config UX, HTML Report & Edge Cases

**Date**: 2026-03-15
**Persona**: Meticulous QA engineer testing configuration handling, HTML report integrity, and boundary conditions
**Tool version**: dev-main

## Executive Summary

Configuration UX is solid: auto-discovery works, validation errors are clear and actionable, and config overrides behave correctly. The HTML report is fully self-contained (zero external dependencies), embeds valid JSON data (~445KB for 121 files), and scales reasonably (1.6MB for 413 files). However, several edge cases expose issues: nonexistent/empty paths silently produce phantom health violations instead of clear errors, `--only-rule` with an unknown rule name silently succeeds with zero violations, and single-file analysis outside the project root says "0 files analyzed" while still emitting computed violations. None are data-corrupting, but all degrade the user experience.

---

## Configuration UX

### Auto-discovery

- `aimd.yaml` in project root is picked up automatically — verified by comparing violation counts with and without `--config` flag
- **Issue**: Neither normal nor verbose (`-v`) output mentions which config file was loaded. Users cannot confirm auto-discovery is working without comparing violation counts manually. Verbose mode logs `Starting analysis` and `Collection completed` but never logs the config source.

### Config Validation — Error Messages

| Scenario                           | Error Message                                                                                                                                  | Quality                         |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| Empty config                       | No error, runs with defaults                                                                                                                   | Good (reasonable behavior)      |
| Invalid YAML syntax                | `Configuration error: Failed to parse configuration file ...: Malformed inline YAML string at line 2.`                                         | Good — identifies file and line |
| Unknown key                        | `Configuration error: Invalid configuration in ...: Unknown configuration keys: unknown_key`                                                   | Good — names the offending key  |
| Wrong type (`rules: "string"`)     | `Configuration error: Invalid configuration in ...: "rules" must be an associative array`                                                      | Good — explains expected type   |
| Unknown format (`--format=banana`) | `Formatter "banana" not found. Available formatters: checkstyle, gitlab, github, html, json, metrics-json, sarif, summary, text, text-verbose` | Excellent — lists valid options |
| Unknown CLI option                 | Shows usage help with all available options                                                                                                    | Good                            |

All configuration errors exit with code 1 (not 2), which correctly distinguishes config errors from analysis failures.

### Config Overrides — Behavioral Verification

| Config                                            | Violations                     | Compared to baseline (494)   |
| ------------------------------------------------- | ------------------------------ | ---------------------------- |
| No config override (baseline)                     | 494 (160 errors, 334 warnings) | —                            |
| Strict thresholds (CCN warn=5, err=10)            | 557 (176 errors, 381 warnings) | +63 violations, as expected  |
| Disabled rules (boolean-argument + cognitive off) | 321 (143 errors, 178 warnings) | -173 violations, as expected |

Config overrides work correctly. Strict thresholds produce more violations; disabled rules produce fewer.

---

## HTML Report

### Structure & Self-containment

- **File size**: 500KB (121 files / monolog), 1.6MB (413 files / AIMD self-analysis). Reasonable scaling.
- **Self-contained**: Yes — zero `src="http"` or `href="http"` references. All CSS is inlined in a `<style>` tag. All JS is inlined in `<script>` tags (3 script blocks total).
- **No external stylesheets**: 0 `<link rel="stylesheet">` tags found.
- **Data embedding**: JSON data is embedded in a `<script type="application/json" id="report-data">` tag — clean, standards-compliant approach.

### Embedded Data Integrity

- JSON is valid and parseable (verified with Python `json.loads`)
- Top-level keys: `project`, `tree`, `summary`, `computedMetricDefinitions`, `hints`
- `project` contains: name, generatedAt, aimdVersion, partialAnalysis flag
- `tree` is a hierarchical namespace tree with metrics at each level
- `summary` and `hints` provide the data needed for the dashboard view
- No `violations` key at top level — violations appear to be embedded in the tree structure or summary

### HTML Markup

- Proper `<!DOCTYPE html>`, `<html lang="en">`, charset meta tag
- Viewport meta set to `width=1280` (not responsive — acceptable for a report)
- Uses CSS custom properties (`:root` vars) for theming
- Professional font stack with SF Mono for code

---

## Edge Cases

### Single File Analysis

| Path                                                                | Files Analyzed | Violations  | Notes                   |
| ------------------------------------------------------------------- | -------------- | ----------- | ----------------------- |
| `src/Core/Symbol/SymbolPath.php` (exists)                           | 1              | 8           | Works correctly         |
| `benchmarks/vendor/monolog/monolog/src/Monolog/Logger.php` (exists) | 1              | 26          | Works correctly         |
| `benchmarks/vendor/monolog/monolog/src/Logger.php` (wrong path)     | 0              | 1 (phantom) | **Bug**: silent failure |

### Nonexistent / Empty Paths

| Path                                         | Behavior                                       | Exit Code |
| -------------------------------------------- | ---------------------------------------------- | --------- |
| `/tmp/nonexistent` (does not exist)          | "0 files analyzed", 1 phantom health violation | 2         |
| `/tmp/aimd-gadget-empty-dir` (empty dir)     | "0 files analyzed", 1 phantom health violation | 2         |
| `/tmp/does-not-exist.php` (nonexistent file) | Same as above                                  | 2         |

The phantom violation is `health.maintainability = 0.0 (error threshold: below 50.0)`. Computed health metrics fire even when no files were analyzed, producing a false positive. The health scores for zero-file analysis are: Cohesion 50%, Coupling 100%, Maintainability 0% — these are meaningless default values.

### Rule Filtering Edge Cases

| Scenario                                           | Behavior                         | Issue?                                                                         |
| -------------------------------------------------- | -------------------------------- | ------------------------------------------------------------------------------ |
| `--disable-rule=complexity --only-rule=complexity` | 0 violations, exit 0             | Arguably correct (disable wins), but user probably made a mistake — no warning |
| `--only-rule=nonexistent.rule`                     | 0 violations, exit 0             | **Bug**: silently succeeds. Should warn about unknown rule name                |
| `--format=""` (empty string)                       | Uses default formatter (summary) | Acceptable                                                                     |
| `--workers=abc`                                    | Silently treated as default      | Questionable — could warn                                                      |

---

## Error Message Quality

**Strong points:**
- Config validation errors are specific: they name the file, the offending key, and the expected type
- Unknown formatter error lists all available formatters — a model error message
- Invalid CLI options show full usage help

**Weak points:**
- No "config file loaded" message even in verbose mode
- Nonexistent paths produce no error — just silently analyze zero files
- Unknown rule names in `--only-rule` / `--disable-rule` produce no warning
- `--workers=abc` is silently accepted

---

## Issues Found

### HIGH

1. **Nonexistent/empty paths produce phantom violations instead of errors** — When a path does not exist or contains no PHP files, the tool reports "0 files analyzed" but still generates a `health.maintainability` violation (exit code 2). This is a false positive. Expected: either a clear error message ("Path does not exist") or a clean exit with "No PHP files found" (exit code 0).

2. **`--only-rule` with unknown rule name silently succeeds** — `--only-rule=nonexistent.rule` results in zero violations and exit code 0, with no indication that the rule name was not recognized. If a user misspells a rule name (e.g., `--only-rule=complxity.cyclomatic`), they get a false "clean" result. Expected: a warning like "Rule 'nonexistent.rule' not found. Available rules: ..."

### MEDIUM

3. **No config file logging** — Neither normal nor verbose (`-v`) output indicates which configuration file was loaded. Users cannot verify auto-discovery is working. Expected: verbose mode should log "Configuration loaded from aimd.yaml" or "No configuration file found, using defaults."

4. **`--disable-rule` + `--only-rule` conflict not warned** — Using both `--disable-rule=complexity` and `--only-rule=complexity` silently results in zero violations. While "disable wins" is a reasonable resolution, the user almost certainly made a mistake. Expected: a warning like "Rule 'complexity' is both in --only-rule and --disable-rule; it will be disabled."

5. **`--workers=abc` silently accepted** — Non-numeric value for `--workers` is silently treated as default instead of producing an error.

### LOW

6. **Computed health violations on empty analysis** — The health score computation should be skipped entirely when zero files are analyzed. Currently it computes health scores from empty metrics, producing meaningless values (Maintainability: 0%, Coupling: 100%).

7. **Health display inconsistency** — When no files are analyzed, the dashboard shows only 3 health dimensions (Cohesion, Coupling, Maintainability) instead of the usual 5 (missing Complexity and Typing). This is inconsistent but not actively misleading.

---

## What Works Well

1. **Config validation is excellent** — Clear, specific error messages for every type of misconfiguration. The unknown formatter message is particularly good, listing all available options.

2. **HTML report is production-quality** — Fully self-contained, valid embedded JSON, clean HTML5 structure, no external dependencies. Opening it in a browser requires zero network access.

3. **Config overrides behave correctly** — Strict thresholds produce more violations, disabled rules produce fewer. The configuration pipeline is reliable.

4. **Single file analysis works** — Analyzing a single PHP file produces meaningful, detailed output including class-level metrics, method-level violations, and tech debt estimates.

5. **Exit codes are well-designed** — Exit 0 for clean, exit 1 for tool errors (config, CLI), exit 2 for violations found. This is CI-friendly.

6. **Summary output is informative** — Health bars, worst namespaces with hints, and actionable drill-down suggestions (`--namespace=...`, `--detail`) are all present.

7. **Tech debt estimation** — Per-rule debt breakdown in `--detail` mode gives actionable prioritization data.

---

## Recommendations

1. **Add path validation before analysis** — Check that all provided paths exist and contain at least one PHP file. Fail fast with a clear error message instead of silently analyzing zero files.

2. **Validate rule names in `--only-rule` and `--disable-rule`** — Warn (or error) when a rule name does not match any registered rule. This prevents silent misconfiguration.

3. **Log config file source in verbose mode** — Add an INFO log line like "Configuration loaded from /path/to/aimd.yaml" or "No configuration file found."

4. **Skip computed health metrics when no files are analyzed** — If the file count is zero, do not compute or report health scores. Report "No PHP files found" and exit 0.

5. **Validate `--workers` value** — Reject non-numeric values with a clear error message.

6. **Consider warning on conflicting `--disable-rule` + `--only-rule`** — At minimum, log a warning when the same rule prefix appears in both options.
