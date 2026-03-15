# Sherlock the Skeptic: Data Anomaly Report

**Date:** 2026-03-15
**Tool version:** dev-main (commit 872b239)
**Methodology:** Run AIMD on 6 projects of varying size/quality, cross-validate numbers across formats, check for NaN/null/negative/infinity, question every counterintuitive result.

All runs used: `--no-cache --workers=0 --disable-rule=architecture.circular-dependency --disable-rule=duplication.code-duplication`

---

## 1. Executive Summary

Health scores are mathematically correct and internally consistent across all three output formats (JSON, summary, text). No NaN, null, negative, or infinity values were found in any output. However, four anomalies deserve attention: (1) class-level coupling score has zero discrimination for any CBO above 25 -- every single worst-offender class across all projects has coupling=0; (2) project-level coupling uses averages that make large projects with many small classes (Laravel) appear less coupled than small focused libraries (Monolog), which is counterintuitive; (3) complexity scores cluster suspiciously around 53-55 for three very different projects due to formula saturation; (4) classes with zero methods get maintainability=0 despite mi=100, and the class-level overall formula ignores maintainability entirely.

---

## 2. Data Overview

### Health Scores Across Projects

| Project         | Files | Violations | Complexity      | Cohesion        | Coupling        | Typing    | Maintainability | Overall        |
| --------------- | ----: | ---------: | --------------: | --------------: | --------------: | --------: | --------------: | -------------: |
| Monolog         | 121   | 494        | 55.7 Good       | 78.8 Excellent  | 61.4 Good       | 96.1 Good | 73.4 Good       | 71.1 Excellent |
| Symfony Console | 132   | 578        | 39.3 Needs attn | 75.5 Excellent  | 63.2 Good       | 98.5 Good | 76.3 Good       | 67.6 Good      |
| PHP-Parser      | 269   | 1,453      | 53.9 Good       | 48.8 Needs attn | 49.3 Needs attn | 90.6 Good | 79.3 Good       | 62.5 Good      |
| Doctrine ORM    | 453   | 1,153      | 46.5 Needs attn | 86.8 Excellent  | 73.2 Excellent  | 99.6 Good | 74.4 Good       | 73.4 Excellent |
| AIMD (self)     | 413   | 1,164      | 53.8 Good       | 63.0 Good       | 62.0 Good       | 99.9 Good | 74.8 Good       | 68.4 Good      |
| Laravel         | 1,536 | 7,360      | 54.2 Good       | 73.5 Excellent  | 83.7 Excellent  | 18.5 Poor | 77.9 Good       | 63.3 Good      |

### Violation Density

| Project         | Violations/File | Errors/File | Error Rate | Tech Debt/File |
| --------------- | --------------: | ----------: | ---------: | -------------: |
| Monolog         | 4.1             | 1.32        | 32.4%      | 1.5h           |
| Symfony Console | 4.4             | 1.07        | 24.4%      | 1.7h           |
| PHP-Parser      | 5.4             | 0.86        | 15.8%      | 1.7h           |
| Doctrine ORM    | 2.5             | 0.84        | 33.0%      | 1.1h           |
| AIMD (self)     | 2.8             | 0.92        | 32.6%      | 1.2h           |
| Laravel         | 4.8             | 3.13        | 65.3%      | 1.6h           |

### CBO Statistics

| Project      | Classes | CBO min | CBO max | CBO avg | Distance avg |
| ------------ | ------: | ------: | ------: | ------: | -----------: |
| Monolog      | 121     | 1       | 102     | 8.88    | 0.294        |
| Doctrine ORM | 453     | 1       | 115     | 8.10    | 0.285        |
| Laravel      | 1,441   | 1       | 231     | 7.59    | 0.262        |

---

## 3. Anomalies Found

### A1. Class-Level Coupling Score Has No Discrimination (MEDIUM)

**What:** Every single worst-offender class across all 6 projects has `coupling=0`, regardless of actual CBO values ranging from 28 to 231.

**Formula:** `clamp(100 - max((cbo - 5) * 5, 0, 100)` -- any class with CBO > 25 gets coupling=0.

**Evidence:**
- Symfony `Application`: CBO=61, coupling=0
- PHP-Parser `Php8`: CBO=171, coupling=0
- PHP-Parser `PrettyPrinterAbstract`: CBO=139, coupling=0
- Laravel `Application`: CBO=114, coupling=0
- Laravel `Filesystem`: CBO=54, coupling=0
- All 10 Laravel worst-offender classes: coupling=0
- All 4 PHP-Parser worst-offender classes: coupling=0
- All 3 Doctrine worst-offender classes: coupling=0

**Expected:** A sliding scale that distinguishes CBO=30 from CBO=170. The current formula has a useful range of only CBO 5-25.

**Impact:** The class-level coupling score provides zero useful information for any class that is even moderately coupled. In the worst-offender view, every class simply shows coupling=0, making the dimension useless for differentiation.

### A2. Project-Level Coupling Rewards Large Projects Counterintuitively (MEDIUM)

**What:** Laravel (1,536 files, CBO max=231) has coupling=83.7 "Excellent", while Monolog (121 files, CBO max=102) has coupling=61.4 "Good". The larger, more complex project scores better.

**Formula uses averages:** `clamp(100 * 12 / (12 + distance__avg * 6.5 + max(cbo__avg - 7, 0) * 4), 0, 100)`

**Root cause:** Laravel has many small utility/value-object classes with CBO=1-5, dragging the average CBO down to 7.59 vs Monolog's 8.88. The formula rewards this pattern regardless of extreme outliers.

**Verdict:** Mathematically correct, but the ranking is counterintuitive. A user who sees "Coupling: 83.7% Excellent" for Laravel will be surprised. Consider incorporating percentile-based measures (e.g., P90 CBO) or outlier counts alongside averages.

### A3. Complexity Scores Cluster Around 53-55 for Dissimilar Projects (LOW)

**What:** Three very different projects -- AIMD (static analyzer), PHP-Parser (parser/compiler), and Laravel (web framework) -- have nearly identical complexity scores: 53.83, 53.87, and 54.18 respectively.

**Root cause:** The harmonic-style formula `100 * 32 / (32 + ...)` naturally saturates. As projects grow, the average CCN and cognitive complexity converge toward similar values due to the law of large numbers, and the formula's denominator growth is bounded.

**Verdict:** Not a bug, but reduces the score's discriminatory power for medium-to-large projects. The complexity score effectively has ~20 points of useful range (roughly 40-60 for typical projects).

### A4. Zero-Method Classes Get maintainability=0 Despite mi=100 (LOW)

**What:** `Monolog\DateTimeImmutable` has 0 methods (it extends PHP's built-in class). The class gets `mi: 100` (file-level) but `health.maintainability: 0` because the formula `clamp(mi__avg ?? 0, 0, 100)` falls back to 0 when `mi__avg` is not set (no methods to average).

**Additional issue:** The class-level `health.overall` formula does NOT include maintainability: `(complexity ?? 75) * 0.30 + (cohesion ?? 75) * 0.25 + (coupling ?? 75) * 0.25 + (typing ?? 75) * 0.20`. So `health.overall=92.5` coexists with `health.maintainability=0`. Misleading to users.

**Also:** `health.complexity` is NOT SET for zero-method classes (no CCN/cognitive data), so it falls back to 75 in the overall formula -- an arbitrary default.

### A5. Laravel OOMs at Default Memory Limit (LOW)

**What:** Running AIMD on Laravel (1,536 files) with `--workers=0` exhausts the default 128MB memory limit. The crash occurs in `MetricBag.php:62`. Requires `php -d memory_limit=512M` to complete.

**Verdict:** This is a scalability issue for large projects. The tool should either detect and report this gracefully, or document the memory requirement. With parallel workers the issue may be different (per-worker memory).

### A6. JSON Format Caps Violations at 50 Without Warning (LOW)

**What:** The JSON output includes at most 50 violations (by design -- "top 50"). However, the summary section reports the full count (e.g., 7,360 for Laravel). A consumer of the JSON API might not realize they're seeing only 50 of 7,360 violations.

**Evidence:** All 6 projects had exactly 50 violations in the JSON array, regardless of having 494 to 7,360 total violations.

**Verdict:** The truncation should be explicitly documented in the JSON output (e.g., `"violationsShown": 50, "violationsTotal": 7360`).

---

## 4. Cross-Project Comparison

### Does the ranking make sense?

**Overall health ranking:** Doctrine (73.4) > Monolog (71.1) > AIMD (68.4) > Symfony Console (67.6) > Laravel (63.3) > PHP-Parser (62.5)

**Analysis:**
- **Doctrine > Monolog**: Somewhat surprising. Doctrine ORM is a complex project, but it has excellent typing (99.6%) and good cohesion (86.8%). Monolog loses on typing (96.1%) and cohesion (78.8%). The ranking is driven by Doctrine's better type discipline and lower violation density (2.5/file vs 4.1/file). Plausible.
- **AIMD beats Symfony Console**: The tool finds more complexity issues in Symfony (39.3 vs 53.8) which makes sense -- Console has complex parsing/rendering logic. AIMD has better typing (99.9% vs 98.5%). Reasonable.
- **Laravel near bottom**: Driven entirely by typing score (18.5% -- 2,772 type-coverage violations). Excluding typing, Laravel would score well. This is fair -- Laravel historically has weak type declarations.
- **PHP-Parser at bottom**: Low cohesion (48.8) and coupling (49.3). The parser classes `Php7`/`Php8` have CBO of 170-171 (generated code?). `BuilderFactory` has TCC=0. These are characteristics of parser/compiler code. Fair assessment.

**Counterintuitive result:** Laravel has the best coupling score (83.7) and the best maintainability score (77.9) of all projects. The coupling score is explained by A2 above (average dilution). The maintainability score (MI formula) may also benefit from many small, simple methods.

### Self-analysis passes the smell test

AIMD finds 1,164 violations in itself with 380 errors. Top issues: CBO (23), NPath (15), WMC (6). The tool identifies its own complexity hotspots (Halstead, Complexity collectors) as worst offenders. This is honest and expected -- metric computation code IS complex.

---

## 5. Cross-Format Consistency

Tested on Symfony Console and Monolog with three formats: JSON, summary, text --detail.

| Metric              | JSON       | Summary      | Text --detail   |
| ------------------- | ---------- | ------------ | --------------- |
| Files analyzed      | 132        | 132          | 132             |
| Errors              | 141        | 141          | 141             |
| Warnings            | 437        | 437          | 437             |
| Total violations    | 578        | 578          | 578             |
| Overall health      | 67.6%      | 67.6%        | N/A (not shown) |
| Complexity          | 39.3%      | 39.3%        | N/A             |
| Tech debt (Symfony) | 13,340 min | 27d 6h 20min | N/A             |

**Tech debt conversion:** 13,340 min / 60 = 222.3h / 8h workday = 27.8 days = 27d 6h 20min. Consistent.

**Verdict:** All three formats are perfectly consistent. Zero discrepancies found.

---

## 6. What Looks Solid

1. **No data corruption:** Zero NaN, null, negative, or infinity values across all 6 projects, all formats. Every health score is in [0, 100].

2. **Cross-format consistency:** JSON, summary, and text formats produce identical numbers for files, violations, errors, warnings, and health scores.

3. **Health score math is correct:** Manually verified the overall formula (`complexity * 0.25 + cohesion * 0.20 + coupling * 0.20 + typing * 0.15 + maintainability * 0.20`) against JSON values. Match to 2 decimal places.

4. **Label logic is consistent:** Labels follow the documented thresholds: `> warn+20` = Excellent, `> warn` = Good, `> err` = Needs attention, `<= err` = Poor. All labels across all projects are correctly assigned.

5. **Violation detection is credible:** Laravel's top violations (2,772 type-coverage, 548 long-parameter-list, 486 instability) align with known characteristics of the framework. PHP-Parser's low cohesion matches the nature of parser/compiler code. Doctrine's complexity issues match ORM query-building complexity.

6. **Self-analysis is honest:** The tool finds real issues in its own code (1,164 violations, 380 errors) and correctly identifies its complexity hotspots.

7. **Tech debt calculation uses 8-hour workdays:** Consistent and reasonable assumption.

8. **Violation density is reasonable:** 2.5-5.4 violations per file across all projects. No project has zero violations (which would be suspicious), and no project has absurdly high counts that suggest false positives.

---

## 7. Recommendations

### Must Fix

1. **(A1) Redesign class-level coupling formula.** The current `100 - (cbo - 5) * 5` has a useful range of only CBO 5-25. Consider a logarithmic or harmonic formula: `100 * k / (k + max(cbo - 5, 0))` with appropriate k to provide discrimination across CBO 5-200.

### Should Fix

2. **(A2) Incorporate outlier awareness into project-level coupling.** Supplement `cbo__avg` with `cbo__p90` or `cbo__max` in the formula. A project with CBO max=231 should not score "Excellent" on coupling.

3. **(A4) Fix maintainability=0 for zero-method classes.** When `mi__avg` is not set, fall back to `mi` (file-level MI) or to the default 75 rather than 0. Also consider whether the class-level overall formula should include maintainability.

4. **(A6) Add truncation metadata to JSON violations.** Include something like `"violationsLimit": 50, "violationsTotal": 7360` in the JSON output so API consumers know they're seeing a subset.

### Nice to Have

5. **(A3) Consider adjusting the complexity formula for better discrimination.** The harmonic formula saturates too quickly. Alternatively, accept this as a design choice and document it.

6. **(A5) Detect memory limits and warn users.** Before starting analysis of large projects, estimate memory needs based on file count and warn if the current limit may be insufficient.
