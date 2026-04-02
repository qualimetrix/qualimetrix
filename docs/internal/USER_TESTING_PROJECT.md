# User Testing Project

Systematic CLI testing from the user's perspective.
Tests cover all commands, options, output formats, and edge cases.

---

## Round 1 Results (April 2, 2026)

### Summary

| #         | Test Group              | Cases   | Result                              |
| --------- | ----------------------- | ------- | ----------------------------------- |
| 1         | Basic CLI & Help        | 15      | **15/15 PASS**                      |
| 2         | Output Formats          | 15      | **15/15 PASS**                      |
| 3         | Filtering & Presets     | 18      | **17/18 PASS, 1 PARTIAL**           |
| 4         | Baseline & Git          | 17      | **14/17 PASS, 1 BUG, 2 MINOR**      |
| 5         | Hooks, Graph, Profiling | 20      | **19/20 PASS, 1 BUG**               |
| 6         | YAML Config & Combos    | 16      | **14/16 PASS, 1 BUG, 1 NOTE**       |
| **Total** |                         | **101** | **94 PASS, 3 BUG, 2 MINOR, 2 NOTE** |

### Bugs Found and Fixed

All bugs fixed in commit `85e4612` on branch `claude/user-testing-prompts-R1mZX`.

| #   | Bug                                   | Root Cause                                                                                  | Fix                                                                    |
| --- | ------------------------------------- | ------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| 1   | `--log-level` ignored for file logger | `LoggerFactory` hardcoded `FileLogger` to `LogLevel::DEBUG`                                 | Pass `$level` parameter to FileLogger constructor                      |
| 2   | `--class` filter not working          | `ViolationFilter` only applied in JsonFormatter and SummaryFormatter (2 of 11)              | Moved filtering to `ResultPresenter` (central, all formatters benefit) |
| 3   | `--cache-dir` silently ignored        | `CacheInterface` resolved during DI build with default config; CLI config not yet available | `CachedFileParser` accepts `CacheFactory` for lazy resolution          |

### UX Improvements Applied

| #   | Issue                                               | Fix                                                                             |
| --- | --------------------------------------------------- | ------------------------------------------------------------------------------- |
| 1   | Unknown rule in YAML config silently ignored        | Extended `warnAboutUnknownRules()` to also check `ruleOptions` keys from config |
| 2   | Unknown rule in `--rule-opt` silently ignored       | Extract rule names from `--rule-opt` input and validate against registry        |
| 3   | Unknown option key in `--rule-opt` silently ignored | Added `warnAboutUnknownKeys()` in `RuleOptionsFactory` (visible with `-v`)      |

### False Positives (Not Bugs)

| #   | Reported Issue                                                   | Actual Behavior                                                             |
| --- | ---------------------------------------------------------------- | --------------------------------------------------------------------------- |
| 1   | `--show-resolved` doesn't display in summary                     | Correct: no resolved violations = no message (nothing changed)              |
| 2   | `--show-suppressed` with `--format=text` doesn't show suppressed | Correct: with baseline, suppression count is 0 (baseline filter runs first) |

---

## Round 2 Test Plan (Not Yet Executed)

### Group 7: Bug Regression Tests (re-verify fixes)

1. `--log-level=info --log-file=/tmp/test.log` — verify NO debug entries in log
2. `--log-level=warning --log-file=/tmp/test.log` — verify only warning+ entries
3. `--class="Qualimetrix\Core\Symbol\SymbolPath" --format=text` — verify only SymbolPath violations
4. `--class="Qualimetrix\Core\Symbol\SymbolPath" --format=json` — same for JSON
5. `--class="Qualimetrix\Core\Symbol\SymbolPath" --format=checkstyle` — same for Checkstyle
6. `--class="Qualimetrix\Core\Symbol\SymbolPath" --format=sarif` — same for SARIF
7. `--namespace="Qualimetrix\Core\Metric" --format=text` — namespace filter works across formatters
8. `--cache-dir=/tmp/custom-cache --workers=0` — verify cache created at custom path
9. `--cache-dir=/tmp/custom-cache --workers=2` — verify parallel workers also use custom path
10. `--rule-opt=nonexistent.rule:x=1` — verify warning shown
11. Config with `nonexistent.rule` in YAML — verify warning shown
12. `--rule-opt=complexity.cyclomatic:fake_option=5 -v` — verify unknown key warning

### Group 8: Computed Metrics

1. YAML with `computed_metrics` section — custom formula using Expression Language
2. Per-level formulas (method/class/namespace/project) — each level computes correctly
3. Invalid formula syntax — graceful error message
4. Formula referencing non-existent metric — graceful error
5. `--exclude-health=complexity` with computed metrics — excluded dimension not in formula
6. Health score computation with all dimensions
7. Health score with `--exclude-health` — verify excluded dimensions removed from overall

### Group 9: @qmx-ignore and @qmx-threshold Inline Tags

1. `@qmx-ignore complexity` on a class — suppresses complexity violations for that class
2. `@qmx-ignore` (no rule) — suppresses all violations for that symbol
3. `@qmx-threshold complexity.cyclomatic method.warning=50` — overrides threshold
4. `@qmx-ignore-file` — suppresses all violations for the file
5. `--no-suppression` flag — ignores all inline tags
6. `--show-suppressed` — displays count of suppressed violations
7. Backtick-escaped tags in docblocks — should NOT be parsed as real tags

### Group 10: Edge Cases and Error Handling

1. File with PHP syntax errors — should not crash, report parse error
2. Empty PHP file (`<?php`) — should handle gracefully
3. PHP file with only comments — no violations expected
4. Very large file (>5000 lines) — should not OOM with default memory limit
5. Files with `@generated` annotation — skipped by default, included with `--include-generated`
6. Binary file in source directory — should be skipped
7. Symlinks in source paths — should follow or skip gracefully
8. Non-UTF-8 file encoding — should not crash
9. Read-only output file path (`-o /root/test.json`) — graceful error
10. Concurrent runs — two `bin/qmx check` on same codebase (cache race conditions)

### Group 11: Conflicting Options

1. `--only-rule=complexity --disable-rule=complexity` — conflicting, should warn
2. `--analyze=git:staged --report=git:main..HEAD` — scoped analysis + scoped reporting
3. `--preset=strict --preset=legacy` — last wins? merged?
4. `--format=json --all --format-opt=violations=10` — conflicting limits
5. `--quiet --verbose` — conflicting verbosity
6. `--no-cache --clear-cache` — redundant combination
7. `--generate-baseline=x.json --baseline=y.json` — generate and use simultaneously

### Group 12: Custom Preset Files

1. `--preset=./custom.yaml` — load preset from file path
2. `--preset=./nonexistent.yaml` — graceful error for missing file
3. `--preset=strict,./custom.yaml` — combine built-in and custom preset
4. Custom preset with invalid rule name — warning shown
5. Custom preset overriding thresholds — verify thresholds applied

### Group 13: Dogfooding Regression

1. `bin/qmx check src/` with project `qmx.yaml` — should complete, 0 errors
2. Compare violation count with expected range (currently ~235 warnings)
3. Health score regression check (currently ~75%)
4. `bin/qmx check src/ --format=health` — all dimensions scored
5. `bin/qmx check src/ --preset=strict` — stricter analysis (expect more violations)
6. `composer benchmark:check` — benchmark health scores within expected ranges

### Group 14: HTML Report

1. `--format=html -o /tmp/report.html` — generates valid HTML5
2. Open in browser — interactive features work (sorting, filtering, collapsing)
3. Health scores displayed correctly
4. Violation details with links to source
5. Large report (full `src/`) — performance acceptable

---

## Agent Prompts

Prompts for each test group follow a standard template:

```
You are testing the Qualimetrix CLI tool (`bin/qmx`) from a user's perspective.
This is a PHP static analysis tool located at /home/user/qualimetrix.

**Your test group: [GROUP NAME]**

Use `--no-progress` for all commands. Run each test case, capture output and exit
code, verify behavior matches expected. Report PASS/FAIL with details.

Test cases:
[numbered list]

For each: run the command, check exit code, verify output. Report a detailed
summary table at the end.

IMPORTANT: Only run commands and report results. Do NOT modify any files.
```

For Round 2, use the same template with group-specific test cases from the plan above. Launch groups 7-14 as parallel agents.

---

## Test Infrastructure Notes

- **Environment:** Ubuntu (Claude Code on Web)
- **Git signing errors:** `GitScopeFilterTest` (13 tests) fails due to environment signing issue, not code bug
- **Performance:** Full `src/` analysis takes ~18s with parallel workers, ~10s for `src/Core/` alone
- **False positive rate:** `--rule-opt` unknown key detection may warn on valid camelCase variants of snake_case options (fixed with dual-form matching)
