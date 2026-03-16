# 02 — Metric Mirage

**Persona:** Skeptical data analyst hunting metric anomalies
**Projects:** All 8 benchmark projects
**Date:** 2026-03-16

## Executive Summary

AIMD health scores **do discriminate** between projects, but with important caveats. The overall health range spans 43.1 points (45.4 to 88.5), which is meaningful. However, several anomalies deserve attention:

1. **Class-level `mi` is always 100** across all 2793 classes in all 8 projects — a dead metric at the class level.
2. **Complexity score uses WMC-based averages**, not per-method CCN averages. This means small projects with a few god classes (Guzzle: 39 classes) can score worse than massive projects (Laravel: 1441 classes).
3. **Maintainability dimension barely discriminates** — only 10.8-point range across 8 very different projects.
4. **php-parser has 782 identical-subexpression violations** (exclusive to this project) — likely a domain-specific false positive from AST comparison code.
5. **No NaN/null/Infinity values** found in any metrics-json output — data quality is clean.

The tool is NOT a mirage. It produces genuinely different numbers for different codebases. But some dimensions are better discriminators than others, and the aggregation strategy can produce counterintuitive results for small-namespace projects.

## Cross-Project Comparison Table

### Summary Statistics

| Project         | Files | Classes | Methods | Violations | Errors | Warnings | V/File | Debt (min) | Debt/1kLOC |
| --------------- | ----: | ------: | ------: | ---------: | -----: | -------: | -----: | ---------: | ---------: |
| monolog         | 121   | 121     | 669     | 488        | 153    | 335      | 4.03   | 11,145     | 741.1      |
| symfony-console | 132   | 132     | 1,041   | 560        | 144    | 416      | 4.24   | 13,070     | 691.7      |
| php-parser      | 269   | 268     | 1,288   | 1,438      | 221    | 1,217    | 5.35   | 26,610     | 1,099.8    |
| doctrine        | 453   | 453     | 2,480   | 1,116      | 358    | 758      | 2.46   | 30,680     | 551.8      |
| guzzle          | 41    | 39      | 245     | 192        | 63     | 129      | 4.68   | 4,580      | 684.0      |
| laravel         | 1,536 | 1,441   | 12,933  | 7,227      | 4,761  | 2,466    | 4.71   | 148,325    | 594.7      |
| flysystem       | 55    | 55      | 269     | 116        | 44     | 72       | 2.11   | 2,925      | 1,028.1    |
| composer        | 286   | 285     | 2,464   | 2,639      | 972    | 1,667    | 9.23   | 65,040     | 928.4      |

### Health Scores

| Project         | Overall           | Complexity         | Cohesion      | Coupling            | Typing              | Maintainability       |
| --------------- | ----------------: | -----------------: | ------------: | ------------------: | ------------------: | --------------------: |
| monolog         | 68.3 (Acceptable) | 63.5 (Acceptable)  | 78.8 (Strong) | 37.7 (Weak)         | 96.1 (Strong)       | 73.4 (Acceptable)     |
| symfony-console | 57.9 (Acceptable) | 12.8 (Critical)    | 75.5 (Strong) | 47.7 (Weak)         | 98.5 (Strong)       | 76.3 (Acceptable)     |
| php-parser      | 59.3 (Acceptable) | 60.3 (Acceptable)  | 48.8 (Weak)   | 25.2 (Weak)         | 90.6 (Strong)       | 79.3 (Strong)         |
| doctrine        | 64.6 (Acceptable) | 38.3 (Weak)        | 86.8 (Strong) | 39.2 (Weak)         | 99.6 (Strong)       | 74.4 (Acceptable)     |
| guzzle          | 61.9 (Acceptable) | **0.0 (Critical)** | 84.1 (Strong) | 93.6 (Strong)       | 78.8 (Weak)         | 72.6 (Acceptable)     |
| laravel         | 52.5 (Acceptable) | 56.9 (Acceptable)  | 73.5 (Strong) | 26.1 (Weak)         | **18.5 (Critical)** | 77.9 (Acceptable)     |
| flysystem       | **88.5 (Strong)** | **92.9 (Strong)**  | 77.2 (Strong) | **96.4 (Strong)**   | 93.6 (Strong)       | **82.5 (Strong)**     |
| composer        | **45.4 (Weak)**   | **0.0 (Critical)** | 73.3 (Strong) | **24.1 (Critical)** | 77.2 (Weak)         | **71.7 (Acceptable)** |

## Findings

### Finding M1: Class-level `mi` is always 100.0 — a dead metric

**Severity:** HIGH
**Category:** anomalous-value

In the metrics-json output, the `mi` field at the class level is exactly 100.0 for **all 2,793 classes** across all 8 projects. This includes Composer classes with `mi.min` of 0.0 (worst method in that class) and `mi.avg` of 32.1 (average method MI).

The meaningful MI values are `mi.avg` and `mi.min` at the class level, and `mi` at the method level. The class-level `mi` field appears to be a capped/placeholder value that provides zero information.

**Evidence:**
- monolog: 121/121 classes with `mi=100`
- composer: 285/285 classes with `mi=100`, despite `mi.avg` ranging from 32.1 to 100.0
- All projects: 2,793/2,793 classes with `mi=100`

**Impact:** Any consumer reading class-level `mi` (as opposed to `mi.avg` or `mi.min`) will see perfect scores everywhere. This is misleading. The `health.maintainability` score correctly uses `mi.avg`, so the health score itself is not affected.

### Finding M2: Complexity score bottoms out at 0.0 due to WMC-based aggregation

**Severity:** HIGH
**Category:** counterintuitive-ranking

Guzzle (39 classes, avg method CCN=3.3) and Composer (285 classes, avg method CCN=4.4) both score **0.0** on complexity. Meanwhile, Laravel (1,441 classes, avg method CCN=1.9) scores **56.9** and php-parser (268 classes, avg method CCN=2.1) scores **60.3**.

The reason: the complexity health score uses **namespace-level `ccn.avg`**, which is the average per-class WMC (sum of all method CCN) within that namespace. For Guzzle:
- `GuzzleHttp\Cookie` namespace: 5 classes, `ccn.avg`=36.2
- `GuzzleHttp\Handler` namespace: 9 classes, `ccn.avg`=37.2

These are WMC values (total complexity per class), not per-method CCN. A class with 20 methods of CCN=2 each gets WMC=40, same as a class with one method of CCN=40.

The project-level score then averages these namespace-level averages. Guzzle has only 4 namespaces, so one bad namespace dominates. Composer has many namespaces but most are concentrated complexity.

This creates a **size-sensitivity inversion**: small, focused libraries with a few complex classes appear worse than large frameworks where complexity is diluted across hundreds of simple facade/service classes.

**Evidence:**
- Guzzle method-level CCN avg: 3.3, project health complexity: 0.0
- Laravel method-level CCN avg: 1.9, project health complexity: 56.9
- The per-method CCN says Laravel methods are simpler. The health score says Guzzle is worse. Both are correct from different angles, but the 0.0 score for Guzzle is extreme.

### Finding M3: Maintainability dimension has minimal discrimination (10.8-point range)

**Severity:** MEDIUM
**Category:** zero-discrimination

The maintainability health scores across all 8 projects span only 10.8 points (71.7 to 82.5). For comparison, complexity spans 92.9, coupling spans 72.3, and typing spans 81.1 points.

All 8 projects are labeled either "Acceptable" (7) or "Strong" (1). The decomposition field is always empty for maintainability, providing no insight into what drives the score.

**Evidence:**
- Range: 71.7 (composer) to 82.5 (flysystem) = 10.8 spread
- Labels: 7x "Acceptable", 1x "Strong"
- Decomposition: always `[]`

This dimension effectively says "all PHP code has roughly the same maintainability" which is not a useful signal. The underlying `mi.avg` values have better discrimination (e.g., method-level MI avg ranges from 70.6 to 84.1), but the health score formula compresses the range.

### Finding M4: 782 identical-subexpression violations exclusive to php-parser

**Severity:** MEDIUM
**Category:** anomalous-value

php-parser has 782 `code-smell.identical-subexpression` violations — more than ALL other violations in the project combined (656). No other project has a single one of these violations.

This is almost certainly a domain-specific false positive. php-parser contains AST node classes with methods like:
```php
// Comparing AST node properties
$left->name === $right->name && $left->type === $right->type
```

These are legitimate equality checks on different objects that happen to have the same property names. The rule flags `$a->name === $b->name` as "identical subexpressions" when `$a` and `$b` are different variables.

**Impact:** This inflates php-parser's violation count by 54% and its tech debt by a proportional amount. It also inflates the V/File metric from ~2.4 to 5.35.

### Finding M5: Laravel typing score (18.5) reflects real PHP 8.x adoption gap

**Severity:** LOW
**Category:** good-discrimination

Laravel scores 18.5 on typing (Critical), while Doctrine scores 99.6 (Strong). This is a genuine and valid finding:
- Laravel: 4,831/16,178 parameters typed (29.9%), 755/12,343 returns typed (6.1%), 210/2,780 properties typed (7.6%)
- Only 180/1,441 classes (12.5%) have 100% type coverage
- Only 206/1,441 classes (14.3%) have >= 80% type coverage

This reflects Laravel's historically permissive approach to type declarations, its heavy use of magic methods (`__get`, `__call`), and its PHP 5.x heritage. The metric correctly identifies this as a meaningful difference from Doctrine (a library that has aggressively adopted PHP 8.x features).

### Finding M6: Coupling dimension discriminates well but is heavily CBO-driven

**Severity:** LOW
**Category:** good-discrimination

Coupling has a 72.3-point range (24.1 to 96.4) and produces an intuitive ranking:
1. flysystem (96.4) — minimal dependencies, clean interfaces
2. guzzle (93.6) — small, focused HTTP client
3. symfony-console (47.7) — moderate framework coupling
4. doctrine (39.2) — complex ORM with many cross-references
5. monolog (37.7) — surprisingly high CBO (avg 8.9) due to handler chain
6. laravel (26.1) — full framework, heavy cross-package coupling
7. php-parser (25.2) — many node types reference each other
8. composer (24.1) — CBO avg 14.1, highest in the set

The decomposition always shows `cbo.avg` as the explanatory factor. This is informative but means the score is essentially a single-metric proxy.

### Finding M7: 6/8 projects labeled "Acceptable" on overall health — label clustering

**Severity:** MEDIUM
**Category:** zero-discrimination

Despite overall scores ranging from 45.4 to 68.3 (excluding flysystem at 88.5), six out of eight projects receive the "Acceptable" label. The thresholds are warning=50, error=30, meaning:
- >50 = "Acceptable"
- 25-50 = "Weak"
- <25 = "Critical"

Only composer (45.4) falls into "Weak" and flysystem (88.5) into "Strong". The rest cluster in "Acceptable" despite a 23-point spread between them.

The label vocabulary is too coarse for the actual score distribution.

### Finding M8: Debt/1kLOC produces counterintuitive rankings

**Severity:** MEDIUM
**Category:** counterintuitive-ranking

The tech debt density (minutes per 1,000 lines of code) ranking:
1. php-parser: 1,099.8
2. flysystem: 1,028.1
3. composer: 928.4
4. monolog: 741.1
5. symfony-console: 691.7
6. guzzle: 684.0
7. laravel: 594.7
8. doctrine: 551.8

Flysystem — the project with the highest overall health (88.5) — has the **second-highest** tech debt density. Doctrine (moderate health) has the lowest debt density. Laravel (low health) has the second-lowest.

This inversion occurs because debt/1kLOC is driven by violation count regardless of severity, and small projects with concentrated issues get disproportionately penalized per line.

## Discrimination Analysis

### Complexity (Range: 92.9, STRONG discrimination)

**Ranking:** flysystem (92.9) > monolog (63.5) > php-parser (60.3) > laravel (56.9) > doctrine (38.3) > symfony-console (12.8) > guzzle (0.0) = composer (0.0)

Discriminates well at the top but suffers from floor effects: two projects hit 0.0. The 0.0 scores for Guzzle (a relatively clean HTTP library) are questionable — driven by namespace-level WMC aggregation, not by per-method complexity.

### Cohesion (Range: 38.0, MODERATE discrimination)

**Ranking:** doctrine (86.8) > guzzle (84.1) > monolog (78.8) > flysystem (77.2) > symfony-console (75.5) > laravel (73.5) > composer (73.3) > php-parser (48.8)

Most projects cluster in the 73-87 range, with php-parser as a clear outlier (many value-object-style AST nodes with low TCC). The bottom 5 projects span only 5.5 points — nearly indistinguishable.

### Coupling (Range: 72.3, STRONG discrimination)

**Ranking:** flysystem (96.4) > guzzle (93.6) > symfony-console (47.7) > doctrine (39.2) > monolog (37.7) > laravel (26.1) > php-parser (25.2) > composer (24.1)

Two clear clusters: low-coupling small libraries (90+) and coupled larger codebases (24-48). This matches intuition well. Monolog's middle-bottom position is slightly surprising but explained by its high handler fan-out.

### Typing (Range: 81.1, STRONG discrimination)

**Ranking:** doctrine (99.6) > symfony-console (98.5) > monolog (96.1) > flysystem (93.6) > php-parser (90.6) > guzzle (78.8) > composer (77.2) > laravel (18.5)

Excellent discrimination. The ranking correctly identifies Doctrine and Symfony as type-safety leaders and Laravel as a laggard. The top 5 cluster somewhat (90-100), but the bottom 3 have meaningful separation.

### Maintainability (Range: 10.8, POOR discrimination)

**Ranking:** flysystem (82.5) > php-parser (79.3) > laravel (77.9) > symfony-console (76.3) > doctrine (74.4) > monolog (73.4) > guzzle (72.6) > composer (71.7)

Barely discriminates. A 10.8-point range across projects from 55 files to 1,536 files suggests the formula compresses too aggressively. The ranking itself is plausible (flysystem at top, composer at bottom) but the scores are too close to be actionable.

### Overall (Range: 43.1, GOOD discrimination)

**Ranking:** flysystem (88.5) > monolog (68.3) > doctrine (64.6) > guzzle (61.9) > php-parser (59.3) > symfony-console (57.9) > laravel (52.5) > composer (45.4)

Reasonable spread. Flysystem clearly stands out as the cleanest codebase. Composer clearly stands out as the most problematic. The middle 6 have enough separation (~2-6 points between adjacent) to be somewhat meaningful, though ordering within the 57-65 range is noisy.

## UX Notes

1. **Health labels need finer granularity.** When 6/8 projects are "Acceptable," the label system fails to communicate meaningful differences. Consider 5 levels or adjusting thresholds.

2. **Complexity score of 0.0 needs explanation.** Guzzle getting 0.0 complexity is alarming and will confuse users who know Guzzle is a well-maintained library. The decomposition helps but users may not read it.

3. **Maintainability decomposition is always empty.** This is the only dimension that never explains itself. Users cannot learn what is driving their score.

4. **Debt/1kLOC contradicts health scores.** Flysystem (health=88.5) has debt/1kLOC=1028, while Laravel (health=52.5) has 594.7. Users will see contradictory signals.

5. **The class-level `mi=100` creates a confusing user experience** if anyone reads the metrics-json directly. It looks like a bug or a "not computed" sentinel.

## Guide Notes

### What to trust

- **Typing scores** are the most reliable discriminator. They are based on straightforward counting (typed declarations / total declarations) with no complex aggregation. The ranking matches known project characteristics.
- **Coupling scores** discriminate well and match intuition. Small focused libraries score high; large interconnected frameworks score low.
- **Overall health** is a reasonable composite signal. The ranking (flysystem > monolog > doctrine > guzzle > php-parser > symfony-console > laravel > composer) is defensible.

### What to be skeptical of

- **Complexity scores for small projects** (< 100 classes) may be dominated by a single namespace with god classes. A 0.0 does not mean "critically complex codebase" — it may mean "3 large classes in a 4-namespace project."
- **Maintainability scores** are nearly useless for cross-project comparison due to minimal range. They may still be useful for within-project tracking over time.
- **Debt/1kLOC** can produce inversions where healthier projects appear more debt-laden. Use absolute violation counts or health scores for cross-project comparison instead.
- **Violation counts** can be inflated by domain-specific false positives (e.g., php-parser's 782 identical-subexpression violations). Always check `violationsMeta.byRule` to identify outlier rules.

### Cross-project comparison checklist

1. Compare **health scores** (not labels) — labels cluster too much
2. For complexity, also check **per-method CCN average** alongside the health score
3. Ignore `mi` at class level; use `mi.avg` or `mi.min` instead
4. If a project has < 100 classes, treat complexity and coupling scores with extra caution
5. Check whether a single violation rule dominates the count (like identical-subexpression in php-parser)
