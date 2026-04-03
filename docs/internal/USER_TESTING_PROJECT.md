# User Testing Project

Systematic CLI testing from the user's perspective.
Tests cover all commands, options, output formats, and edge cases.

---

## Round 1 (April 2, 2026) — 101 tests, 3 bugs fixed

All bugs fixed in commit `85e4612`.

| #         | Test Group              | Cases   | Result                              |
| --------- | ----------------------- | ------- | ----------------------------------- |
| 1         | Basic CLI & Help        | 15      | **15/15 PASS**                      |
| 2         | Output Formats          | 15      | **15/15 PASS**                      |
| 3         | Filtering & Presets     | 18      | **17/18 PASS, 1 PARTIAL**           |
| 4         | Baseline & Git          | 17      | **14/17 PASS, 1 BUG, 2 MINOR**      |
| 5         | Hooks, Graph, Profiling | 20      | **19/20 PASS, 1 BUG**               |
| 6         | YAML Config & Combos    | 16      | **14/16 PASS, 1 BUG, 1 NOTE**       |
| **Total** |                         | **101** | **94 PASS, 3 BUG, 2 MINOR, 2 NOTE** |

## Round 2 (April 3, 2026) — 98 tests, 3 bugs fixed + 4 features implemented

| #         | Test Group            | Cases  | Result                       |
| --------- | --------------------- | ------ | ---------------------------- |
| 7         | Bug Regression        | 12     | **11 PASS, 1 BUG**           |
| 8         | Computed Metrics      | 7      | **6 PASS, 1 FAIL**           |
| 9         | Inline Tags           | 7      | **7 PASS**                   |
| 10        | Edge Cases            | 10     | **10 PASS**                  |
| 11        | Conflicting Options   | 8      | **8 PASS**                   |
| 12        | Custom Presets        | 5      | **5 PASS**                   |
| 13        | Dogfooding Regression | 6      | **5 PASS, 1 BUG**            |
| 14        | HTML Report           | —      | *Skipped (requires browser)* |
| 15        | Exit Codes            | 10     | **10 PASS**                  |
| 16        | Parallel Determinism  | 9      | **9 PASS**                   |
| 17        | Metric Accuracy       | 4      | **4 PASS**                   |
| 18        | Output Completeness   | 7      | **7 PASS**                   |
| 19        | Config Priorities     | 5      | **4 PASS, 1 FAIL**           |
| 20        | Heavy Rules & Misc    | 8      | **7 PASS, 1 BUG**            |
| **Total** |                       | **98** | **93 PASS, 3 BUG, 2 FAIL**   |

### Actions taken based on Round 2 findings

**Bugs fixed:**
- Warnings in stdout corrupting machine-readable formats → routed to stderr
- `graph:export` crash (`-d` shortcut conflict) → removed shortcut
- Non-existent metric in computed formula silently ignored → now a fatal error
- `exclude_namespaces` not a top-level config key → added global option

**Design changes:**
- `--analyze` option removed (redundant with `--report`, misleading name)
- `analyze` command alias removed (single command name: `check`)
- Exit code 3 for config/input errors (was 1, overlapping with "warnings found")

### Positive findings

- **Parallel determinism**: workers=0 and workers=4 produce bit-identical results
- **Custom presets**: merge semantics work correctly
- **Conflict detection**: `--all --format-opt=violations=10` gives clear error (model UX)
- **Health scores**: consistent at 75.0%, all 5 dimensions present
- **Inline tags**: all tag types work correctly, backtick escaping works
- **Benchmarks**: all 15 projects in expected ranges
- **Heavy rules**: disabling duplication/circular-dependency saves ~2.5x time

---

## Open UX items (backlog)

| #   | Finding                                                          | Type | Status   |
| --- | ---------------------------------------------------------------- | ---- | -------- |
| 1   | Parse error files show "No PHP files found"                      | UX   | **Fixed** |
| 2   | `--preset=strict --preset=legacy` silently drops first           | UX   | Not reproducible |
| 3   | `--show-suppressed` shows only count, not which violations       | UX   | **Fixed** |
| 4   | `@qmx-ignore complexity` doesn't suppress `health.complexity`    | UX   | Won't Fix |
| 5   | `exclude_paths` requires glob syntax, not directory paths        | UX   | **Fixed** |
| 6   | Invalid `--exclude-health` value silently ignored                | UX   | **Fixed** |
| 7   | `--format=metrics` name suggests human-readable but outputs JSON | UX   | Open     |
| 8   | Metric name normalization (`my_ratio` → `myRatio`) undocumented  | Bug  | **Fixed** |
| 9   | No `--exclude-namespace` CLI option (only YAML)                  | UX   | **Fixed** |

---

## Test Infrastructure Notes

- **Environment:** macOS (Claude Code CLI) or Ubuntu (Claude Code on Web)
- **Performance:** Full `src/` analysis takes ~4s with parallel workers, ~6s single-threaded
