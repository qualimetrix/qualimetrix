# Product Research Summary

**Date:** 2026-03-15
**Methodology:** 7 AI agents acting as user personas, testing on 6 benchmark projects (Monolog, Symfony Console, PHP-Parser, Doctrine ORM, Laravel Framework, AIMD self-analysis)

## Research Team

| Agent                | Persona                   | Focus                             | Report                           |
| -------------------- | ------------------------- | --------------------------------- | -------------------------------- |
| Bambi the Bewildered | Junior PHP dev, first run | Output clarity, first impressions | [01](01-bambi-the-bewildered.md) |
| Sherlock the Skeptic | Data analyst              | Metric anomalies, data sanity     | [02](02-sherlock-the-skeptic.md) |
| Doc Holiday          | Newcomer reading docs     | Documentation quality             | [03](03-doc-holiday.md)          |
| Pipeline Pete        | DevOps engineer           | CI/CD formats, exit codes         | [04](04-pipeline-pete.md)        |
| The Drill Sergeant   | Tech Lead                 | Drill-down, sprint planning       | [05](05-the-drill-sergeant.md)   |
| GPT the Consumer     | AI coding assistant       | JSON for LLM consumption          | [06](06-gpt-the-consumer.md)     |
| Inspector Gadget     | QA engineer               | Config UX, HTML, edge cases       | [07](07-inspector-gadget.md)     |

## Projects Analyzed

| Project         | Files | Violations | Overall Health  |
| --------------- | ----: | ---------: | --------------: |
| Monolog         | 121   | 494        | 71.1% Excellent |
| Symfony Console | 132   | 578        | 67.6% Good      |
| PHP-Parser      | 269   | 1,453      | 62.5% Good      |
| Doctrine ORM    | 453   | 1,153      | 73.4% Excellent |
| AIMD (self)     | 413   | 1,164      | 68.4% Good      |
| Laravel         | 1,536 | 7,360      | 63.3% Good      |

---

## Consolidated Findings

### CRITICAL — Trust-Breaking Issues

| #   | Issue                                                  | Found by      | Description                                                                                                                                                                                                   |
| --- | ------------------------------------------------------ | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C1  | **Nonexistent paths produce fake health scores**       | Bambi, Gadget | `bin/aimd check nonexistent/path` outputs "60% Good" with health bars for 0 files instead of an error. Phantom `health.maintainability` violation fires, exit code 2. Deeply misleading for CI and newcomers. |
| C2  | **Class-level coupling score has zero discrimination** | Sherlock      | Formula `100 - (cbo-5)*5` bottoms out at 0 for any CBO > 25. Every worst-offender class across all 6 projects shows coupling=0, whether CBO is 28 or 231. The dimension is useless at class level.            |

### HIGH — Significant UX/Data Issues

| #   | Issue                                                                | Found by          | Description                                                                                                                                  |
| --- | -------------------------------------------------------------------- | ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| H1  | **Health scores don't filter with --namespace/--class**              | Bambi, Drill, GPT | Drill-down shows project-wide health scores, not the filtered entity's scores. Undermines the entire progressive disclosure workflow.        |
| H2  | **--namespace drill-down shows 0 worstClasses in JSON**              | GPT               | `worstClasses: []` when using `--namespace` filter. AI agent loses ability to identify which classes within the namespace need work.         |
| H3  | **Rating labels feel inconsistent**                                  | Bambi             | 71% = "Excellent" but 96% = "Good" (different scales per dimension, but unexplained). Erodes newcomer trust.                                 |
| H4  | **Abbreviations without expansion**                                  | Bambi             | WMC, CBO, TCC, LCOM, NPath used in `--detail` output without explanation. Newcomers must Google them.                                        |
| H5  | **Quick Start is not a Quick Start**                                 | Doc Holiday       | The page jumps to CI/hooks integration without showing basic `aimd check src/`. No "first analysis" tutorial.                                |
| H6  | **Default format contradicted in docs**                              | Doc Holiday       | CLI says default = `summary`, docs say `text`. `summary` and `html` formats are completely undocumented.                                     |
| H7  | **Rules index missing ~9 rules**                                     | Doc Holiday       | ClassRank, Constructor Over-injection, Data Class, God Class, Unused Private, 4 security rules absent from index tables.                     |
| H8  | **`--only-rule` with unknown rule silently succeeds**                | Gadget            | Misspelled rule name gives false "clean" result (exit 0) with no warning. Dangerous in CI.                                                   |
| H9  | **Laravel OOMs at 128MB default**                                    | Sherlock, Drill   | PHP fatal error on large projects. Should set higher default (256-512MB) in `bin/aimd`.                                                      |
| H10 | **Project-level coupling rewards large projects counterintuitively** | Sherlock          | Laravel (CBO max=231) gets coupling=83.7% "Excellent" while Monolog (CBO max=102) gets 61.4% "Good". Average-based formula ignores outliers. |

### MEDIUM — UX Improvements

| #   | Issue                                                    | Found by      | Description                                                                                                        |
| --- | -------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------ |
| M1  | **SARIF missing fields for GitHub Advanced Security**    | Pipeline Pete | No `ruleIndex`, `partialFingerprints`, `fullDescription`, `helpUri`. GHAS works but with degraded experience.      |
| M2  | **`--class` filter should auto-enable `--detail`**       | Drill         | Drilling to a single class and getting "20 violations" with a hint to add `--detail` is an unnecessary extra step. |
| M3  | **Health threshold phrasing ambiguous**                  | Bambi         | "error threshold: below 25.0" — does it mean "error IF below 25" or "error threshold IS below 25"?                 |
| M4  | **No config file logging**                               | Gadget        | Even with `-v`, no indication which config file was loaded. Users can't verify auto-discovery.                     |
| M5  | **`--disable-rule` + `--only-rule` conflict not warned** | Gadget        | Silently results in 0 violations. User probably made a mistake.                                                    |
| M6  | **`--workers=abc` silently accepted**                    | Gadget        | Non-numeric value treated as default without error.                                                                |
| M7  | **No hints/recommendations in JSON**                     | GPT           | MetricHintProvider exists but not surfaced in JSON output. AI agent gets "what's wrong" but not "what to do."      |
| M8  | **`--namespace`/`--class` filtering undocumented**       | Doc Holiday   | Powerful drill-down features not documented outside bare CLI help.                                                 |
| M9  | **Configuration docs incomplete**                        | Doc Holiday   | Missing `fail_on`, `only_rules`, `disabled_rules` YAML keys. Missing CLI shortcut flags (~7 flags).                |
| M10 | **Tech debt numbers feel inflated**                      | Bambi, Drill  | "65 days" for Doctrine, "313 days" for Laravel — hard to translate to sprint capacity.                             |
| M11 | **`--detail` overwhelming on full project**              | Bambi, Drill  | 3800+ lines for Doctrine. No truncation warning or pagination.                                                     |
| M12 | **Typing dimension appears/disappears**                  | Bambi         | Some runs show Typing, others don't (depends on whether type_coverage collector finds anything). No explanation.   |

### LOW — Polish Items

| #   | Issue                                                         | Found by      | Description                                                                                             |
| --- | ------------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------- |
| L1  | **JSON caps violations at 50 without metadata**               | Sherlock      | No `violationsShown`/`violationsTotal` fields. Consumer doesn't know they're seeing a subset.           |
| L2  | **Baseline `count` counts symbols not violations**            | Pipeline Pete | `"count": 67` vs 83 violations reported. Could confuse users inspecting baseline file.                  |
| L3  | **Checkstyle missing `line` on 5 namespace-level violations** | Pipeline Pete | Most consumers handle this, but some may not.                                                           |
| L4  | **No `--fail-on=none` option**                                | Pipeline Pete | Users must use `\|\| true` for informational CI runs.                                                   |
| L5  | **Only top 3 worst offenders shown**                          | Drill, GPT    | Sprint planning needs top 5-10. Need `--top=N` or `--format-opt=top=10`.                                |
| L6  | **`text-verbose` shown in available formatters**              | Bambi         | Deprecated formatter still listed.                                                                      |
| L7  | **Exit codes undocumented in `--help`**                       | Bambi         | No indication what 0/1/2 mean.                                                                          |
| L8  | **Zero-method classes get maintainability=0**                 | Sherlock      | `mi__avg` not set -> fallback to 0. Should fallback to `mi` or 75.                                      |
| L9  | **Complexity scores cluster around 53-55**                    | Sherlock      | Harmonic formula saturates quickly. Reduces discrimination for medium-large projects.                   |
| L10 | **`--help` lists too many rule-specific options**             | Bambi         | ~80 options, most are threshold tweaks. Important flags buried.                                         |
| L11 | **No per-violation remediation time in JSON**                 | GPT           | `techDebtMinutes` exists at summary level only. Per-violation would enable effort-based prioritization. |
| L12 | **Docs: security rules scope not explicit**                   | Doc Holiday   | SQL Injection/XSS/Command Injection only detect superglobal usage. Limitation not stated.               |

---

## What Works Well

These are the product's strengths, confirmed across multiple agents:

1. **Health bar visualization** (Bambi, Drill, Gadget) — Unicode bars with percentages and labels are immediately readable. The tool's strongest UX feature.

2. **Contextual hints** (Bambi, Drill, GPT) — The hints line telling users exactly what to type next (`--namespace='...'`) is the killer feature. Progressive disclosure done right.

3. **Worst offenders match intuition** (Sherlock, Drill) — For both Doctrine and Laravel, the identified targets are exactly what experienced developers would point to. Ranking is credible.

4. **Cross-format consistency** (Sherlock) — Zero discrepancies between JSON, summary, and text formats. All numbers match perfectly.

5. **No data corruption** (Sherlock) — Zero NaN, null, negative, or infinity values across all 6 projects, all formats. Every health score is in [0, 100].

6. **CI integration production-ready** (Pipeline Pete) — All 5 structured formats valid. Exit codes correct. Baseline workflow end-to-end. GitLab format fully compliant.

7. **Config validation error messages** (Gadget) — Clear, specific, and actionable. The unknown formatter message listing alternatives is exemplary.

8. **HTML report self-contained** (Gadget) — Zero external dependencies. Valid embedded JSON. Clean HTML5. Scales reasonably (500KB-1.6MB).

9. **Summary-first default** (Drill) — 20 lines, 4 seconds, immediately actionable. The right default for busy developers.

10. **JSON for AI agents** (GPT) — ~8K tokens for a 453-file project. Health scores with `label`/`direction` fields immediately interpretable by LLM. Refactoring plan successfully built from single JSON call.

11. **Rule documentation** (Doc Holiday) — Best-in-class. Every rule has plain-language explanation, PHP examples, actionable fix guidance, implementation notes.

12. **Self-analysis is honest** (Sherlock) — AIMD finds 1,164 violations in itself and correctly identifies its own complexity hotspots.

---

## Prioritized Action Plan

### P0 — Must Fix (trust / correctness)

1. **Validate paths before analysis** (C1, H8) — Error on nonexistent paths. Warn on unknown `--only-rule`/`--disable-rule` names. These are trust-breaking for newcomers and dangerous in CI.

2. **Redesign class-level coupling formula** (C2) — Current formula has useful range of only CBO 5-25. Consider logarithmic: `100 * k / (k + max(cbo - 5, 0))`.

3. **Set default memory_limit to 256-512MB** (H9) — Add `ini_set('memory_limit', '512M')` to `bin/aimd`. Standard practice for static analysis tools.

### P1 — Should Fix (UX impact)

4. **Scope health scores to filter** (H1, H2) — When `--namespace`/`--class` is used, show filtered entity's health and populate worstClasses. The most impactful UX improvement.

5. **Fix documentation drift** (H5, H6, H7, M8, M9) — Update Quick Start with "first analysis" tutorial. Fix default format docs. Document `summary`/`html` formats. Complete rules index. Document `--namespace`/`--class`/`--detail`.

6. **Expand abbreviations on first occurrence** (H4) — First time CBO appears in output: "CBO (Coupling Between Objects): 49 (max 20)".

7. **Fix rating label thresholds** (H3) — Either recalibrate so 71% is not "Excellent", or add context explaining per-dimension scales.

### P2 — Should Fix (quality of life)

8. **Auto-enable `--detail` for `--class` filter** (M2) — Drilling to a single class inherently means "show me everything."

9. **Add SARIF fields for GHAS** (M1) — `ruleIndex`, `partialFingerprints`, `helpUri`, `fullDescription`.

10. **Add hints to JSON output** (M7) — Surface MetricHintProvider in JSON violations for AI agent actionability.

11. **Log config file source in verbose mode** (M4) — "Configuration loaded from aimd.yaml" or "No configuration file found."

12. **Validate `--workers` and warn on conflicts** (M5, M6) — Reject `--workers=abc`. Warn on `--disable-rule` + `--only-rule` overlap.

### P3 — Nice to Have

13. **Incorporate outlier awareness in coupling formula** (H10) — Supplement `cbo__avg` with `cbo__p90` or `cbo__max`.

14. **Add JSON truncation metadata** (L1) — `"violationsLimit": 50, "violationsTotal": 7360`.

15. **Add `--top=N` for worst offenders** (L5) — Default 3, allow override.

16. **Truncation warning for large `--detail`** (M11) — Suggest `--namespace` or `--format=html` when >200 violations.

17. **Fix zero-method class maintainability** (L8) — Fallback to `mi` or 75 instead of 0.

18. **Remove `text-verbose` from formatter list** (L6) — Deprecated but still shown.

19. **Document exit codes in `--help`** (L7) — "Exit codes: 0 = clean, 1 = tool/config error, 2 = violations found."

20. **Add per-violation `techDebtMinutes` in JSON** (L11) — Enables effort-based prioritization by AI agents.

---

## Cross-Cutting Themes

### 1. The Drill-Down Story is 80% There
The summary → namespace → class workflow is the product's core UX strength. But health scores not filtering (H1) and empty worstClasses in drill-down (H2) break the last 20%. Fixing these two issues would complete the progressive disclosure story.

### 2. Documentation Lags Behind Implementation
The UX redesign (Phases 1-5) shipped new features (`summary` format, `--detail` flag, `--namespace`/`--class` filtering) but documentation wasn't updated to match. The `text-verbose` → `--detail` migration left ghost references. This is the biggest documentation debt.

### 3. Formulas Need Tuning at Extremes
The coupling formula bottoms out at CBO=25. The complexity formula saturates around 53-55. Average-based project scores reward "many small classes" regardless of extreme outliers. The formulas work well in the 20th-80th percentile range but lose discrimination at the tails.

### 4. Edge Case Validation is Weak
Zero files, nonexistent paths, misspelled rule names, non-numeric workers — all handled silently rather than failing fast. Each individually is minor, but together they create a tool that doesn't push back when something is wrong.

### 5. The Tool is Remarkably Solid
No data corruption across 6 projects and 3 formats. Cross-format consistency is perfect. Worst offenders match domain expert intuition. CI integration works end-to-end. The config validation errors are exemplary. The summary-first default with contextual hints is genuinely excellent UX. The issues found are mostly about polish and edge cases, not fundamental correctness.
