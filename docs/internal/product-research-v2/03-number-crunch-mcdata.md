# 03 — Number Crunch McData: Cross-Project Metric Analysis

**Persona:** Data scientist scrutinizing metric consistency, formula saturation, and cross-project discrimination
**Projects:** Monolog (small), PHP-Parser (medium), Laravel (large), Flysystem (small/clean)
**Focus:** Do the numbers make sense across projects?
**Date:** 2026-03-15

---

## Summary

The overall ranking is correct and intuitive: Flysystem (86.2%) > Monolog (66.3%) > PhpParser (57.7%) > Laravel (51.8%). The coupling formula discriminates well across the full spectrum (25–96%). However, three serious issues undermine confidence in the numbers: (1) `health.complexity` is effectively a binary scale — the three "real" projects all cluster within 1.8 points of each other (53.9–55.7%) despite dramatically different raw metrics, driven by CCN having only 8% weight in the formula; (2) `health.coupling` at the class level delivers devastating penalties to popular utility classes (Collection CBO=231 but CA=218, CE=17), penalizing cohesive design; (3) `techDebtMinutes/kLOC` is inversely correlated with actual health — the cleanest project (Flysystem) shows the highest density (1028 min/kLOC) vs the worst (Laravel at 606 min/kLOC).

---

## Cross-Project Comparison Table

| Metric                   | Monolog  | PHP-Parser | Laravel  | Flysystem |
| ------------------------ | -------: | ---------: | -------: | --------: |
| Files analyzed           | 121      | 269        | 1536     | 55        |
| Classes                  | 121      | 268        | 1441     | 55        |
| Methods                  | 669      | 1288       | 12 933   | 269       |
| Violations total         | 494      | 1447       | 7290     | 116       |
| Violations (errors)      | 154      | 224        | 4748     | 44        |
| Tech debt (hours)        | 187h     | 448h       | 2518h    | 49h       |
| Debt / kLOC (min)        | 747      | 1110       | **606**  | **1028**  |
| **health.overall %**     | **66.3** | **57.7**   | **51.8** | **86.2**  |
| health.complexity %      | 55.7     | 53.9       | 54.2     | 83.9      |
| health.cohesion %        | 78.8     | 48.8       | 73.5     | 77.2      |
| health.coupling %        | 37.7     | 25.2       | 26.1     | 96.4      |
| health.typing %          | 96.1     | 90.6       | **18.5** | 93.6      |
| health.maintainability % | 73.4     | 79.3       | 77.9     | 82.5      |
| CCN.avg (project-level)  | 14.32    | 10.63      | 18.22    | 7.51      |
| Cognitive.avg (project)  | 10.36    | 11.58      | 10.74    | 2.20      |
| CBO median (class)       | 6        | 6          | 4        | 4         |
| CBO mean (class)         | 8.9      | 10.1       | 7.6      | 4.7       |
| CBO max (class)          | 102      | 175        | **231**  | 23        |
| MI.avg (method-level)    | 73.4     | 79.3       | 77.9     | 82.5      |
| Method CCN median        | 1.0      | 1.0        | 1.0      | 1.0       |
| TCC = 0 (% of classes)   | 19%      | **63%**    | 13%      | 22%       |
| NPath.avg (project)      | 98       | **7894**   | 14       | 1.4       |

---

## Findings

### HIGH — health.complexity Formula Severely Under-Discriminates

**Formula:** `clamp(100 * 32 / (32 + max(ccn__avg - 1, 0) * 0.2 + cognitive__avg * 2.2), 0, 100)`

The CCN term carries only `0.2 / (0.2 + 2.2) = 8.3%` of the discriminating power. Cognitive complexity carries 91.7%. The result:

| Project   | CCN.avg | Cognitive.avg | health.complexity |
| --------- | ------: | ------------: | ----------------: |
| Monolog   | 14.32   | 10.36         | 55.7%             |
| Laravel   | 18.22   | 10.74         | 54.2%             |
| PhpParser | 10.63   | 11.58         | 53.9%             |
| Flysystem | 7.51    | 2.20          | 83.9%             |

Monolog, PhpParser, and Laravel are compressed into a **1.8-point band (53.9–55.7%)** regardless of: Laravel having 27% higher CCN than Monolog, PhpParser having 57× higher NPath.avg (7894 vs 98), or PhpParser having higher MI than Monolog yet scoring 1.8 points lower.

**Sensitivity check:** with cognitive.avg = 10, varying CCN from 1 to 50 moves the score from 59.3% to 50.2% — a 9-point range. Varying cognitive from 0 to 15 at the same CCN moves it from 94.7% to 47.9% — a 47-point range.

**Root cause:** once a project's average cognitive complexity exceeds ~8, all scores are compressed into 48–60%. The upper half of the scale (60–80%) is practically inaccessible for non-trivial projects. This is de facto a two-class label: "Flysystem-like" (clean) or "everything else" (not clean).

**Aggravating factor:** NPath is completely absent from the formula. PhpParser's NPath.avg = 7894 vs Laravel's 14.3 — a 554× difference — has zero effect on `health.complexity`. NPath violations appear in violation counts but are invisible in the health score.

---

### HIGH — Bidirectional CBO Penalizes Cohesive Utility Classes

The coupling formula `clamp(100 * 15 / (15 + max(cbo - 5, 0)), 0, 100)` uses bidirectional CBO (CA + CE deduplicated). This severely penalizes stable, widely-used utility classes:

| Class                                 | CBO | CA  | CE  | CE % | coupling_health |
| ------------------------------------- | --: | --: | --: | ---: | --------------: |
| `Illuminate\Support\Collection`       | 231 | 218 | 17  | 7%   | **6.2%**        |
| `Illuminate\Support\Str`              | 191 | 171 | 22  | 11%  | **7.5%**        |
| `Illuminate\Support\Arr`              | 186 | 172 | 16  | 9%   | **7.7%**        |
| `Illuminate\Support\Traits\Macroable` | 79  | 75  | 4   | 5%   | **16.9%**       |
| `Monolog\LogRecord`                   | 102 | 97  | 5   | 5%   | **13.4%**       |

In all these cases, 89–95% of the coupling is afferent (other classes depending on them, which is a sign of good design — high reuse, stable interface). The actual CE (efferent — what they depend on) is 4–22. If CE-only were used, `Collection` would score 55.6% instead of 6.2%.

The CBO=231 for `Collection` results in it ranking as the worst-coupled class in the entire Laravel codebase, ahead of `Foundation\Application` (CBO=114, but CE=108 — legitimately coupled outward). The metric correctly identifies a problem for `Application` but mischaracterizes `Collection`.

Note: the namespace-level formula already accounts for `cbo__max` as an outlier penalty. The class-level formula does not distinguish direction at all.

---

### MEDIUM — Debt / kLOC Is Inversely Correlated with Project Health

| Project    | health.overall    | debt/kLOC          |
| ---------- | ----------------: | -----------------: |
| Flysystem  | **86.2%** (best)  | **1028** (highest) |
| PHP-Parser | 57.7%             | 1110               |
| Monolog    | 66.3%             | 747                |
| Laravel    | **51.8%** (worst) | **606** (lowest)   |

Flysystem, the cleanest project, has the highest debt density. Laravel, the least healthy, has the lowest. This is not a bug in the calculation but a fundamental limitation of the metric: debt/kLOC measures "cost to fix per line of code." Small, clean codebases have a small LOC denominator; the few violations they do have (often structural, at 25 min/violation) push density up. Large codebases with many cheap warnings (21 min/violation on average) dilute the denominator.

**Practical impact:** if a user sees "Flysystem: 1028 min/kLOC, Laravel: 606 min/kLOC," they will conclude Flysystem needs more remediation effort per unit of code — the opposite of reality. This metric should not be displayed as a quality signal without prominent context.

---

### MEDIUM — health.complexity Score Is Mis-Aggregated at Project Level

The project-level `ccn.avg = 18.22` for Laravel sounds high (and indeed feeds into a sub-55% score). But this value is not the average method CCN — it is the average of *namespace-level* CCN averages, which are themselves method-sum / method-count per namespace.

Actual method-level CCN statistics for Laravel:
- median: **1.0** (50% of methods have CCN=1)
- mean: 1.88
- the project-level "18.22" is pulled up by 3–4 high-complexity namespaces (`Validation\Concerns` at 153.5, `Database\Query` at 125.5)

This means a project with 1400 simple methods and 10 pathological namespaces will score worse than one with uniformly-moderate complexity distributed everywhere. The outlier namespaces disproportionately inflate the project-level average, making the formula sensitive to extreme outliers rather than overall complexity distribution.

---

### MEDIUM — PHP-Parser Worst Classes Are Machine-Generated Code

The two worst classes flagged for PHP-Parser are `PhpParser\Parser\Php8` and `PhpParser\Parser\Php7` (CBO=171/170, coupling_health=8.3%). These are LR parser tables generated automatically by `php-yacc`. They have legitimate architectural CBO (depending on all ~170 node types) but are not representative of code quality issues — they cannot be refactored by a developer.

Similarly, `PhpParser\PrettyPrinter\Standard` (CBO=175) is a comprehensive node formatter. Flagging these as "worst classes" is technically accurate but provides no actionable signal.

There is no mechanism to distinguish generated from authored code, which could mislead users of automated analysis in CI pipelines.

---

### LOW — PHP-Parser TCC=0 for 63% of Classes Is Architecturally Expected

PHP-Parser has 163 of 260 classes with TCC=0 (no methods sharing property access), yielding `health.cohesion = 48.8% (Weak)`. However, an AST library is naturally composed of:
- Pure value-object node classes with no methods beyond accessors
- Stateless visitor/traverser classes
- Single-responsibility utility classes

For these patterns, TCC=0 and LCOM=1 are correct and expected. The cohesion score correctly identifies absence of field-sharing but incorrectly implies a quality problem in a library designed around this pattern. Laravel, which has more "fat" service classes, scores 73.5% cohesion — better than a deliberately modular AST library.

---

### LOW — Laravel Typing = 18.5% Is Correct but Overwhelms the Overall Score

Laravel's `health.typing = 18.5% (Critical)` is architecturally accurate: `Collection`, `Str`, `Arr`, Facades, and Eloquent models deliberately avoid return type declarations and use dynamic dispatch. This is a known trade-off in Laravel's design.

However, with typing weighted at 15% in the overall formula, it pulls Laravel's overall score down by `(75 - 18.5) × 0.15 = 8.5 points` compared to a project with 75% typing. This is a significant penalty for a deliberate architectural choice that users of Laravel are fully aware of. The score correctly measures the characteristic but may surprise users who consider Laravel "production-grade" code.

---

## What Works Well

**Overall ranking order is correct.** Flysystem (86.2%) > Monolog (66.3%) > PhpParser (57.7%) > Laravel (51.8%) matches expert intuition about these projects' quality and complexity.

**Coupling discrimination is excellent across the full range.** 25.2–96.4% spread (71 points). Namespace-level coupling correctly identifies `Illuminate\Validation\Concerns` (40.6), `Database\Query\Grammars` (51.2), `Console\View` (64.9) as the worst namespaces in Laravel — these are genuinely high-coupling areas. The outlier penalty (`cbo__max`) at namespace level adds meaningful signal beyond the average.

**Worst namespace rankings match intuition.** PhpParser's `PhpParser\Parser` (health=38.3%) and `PhpParser\PrettyPrinter` (42.3%) are correctly identified as the most problematic. Laravel's worst namespaces are all legitimate problem areas: Blade compiler, Query builder, Eloquent concerns.

**No NaN, no null, no out-of-range scores.** All health scores are valid floats in [0, 100]. MI values are bounded (min 11.2 in PHP-Parser for deeply complex parser methods, not 0 or negative). No metric serialization artifacts.

**Laravel typing detection is accurate.** 18.5% typing coverage for Laravel is the right number given its architectural style and correctly places it in Critical territory.

**Flysystem complete separation.** Flysystem's uniformly "Strong" profile across all five dimensions correctly identifies it as the clean-slate baseline. No worst namespaces or worst classes are shown — consistent with the data (all class health scores above 50%).

**Debt total scales correctly with project size.** Absolute hours: Flysystem (49h) → Monolog (187h) → PhpParser (448h) → Laravel (2518h). This linear scaling with project complexity is meaningful for planning.

**CCN discrimination works at the class level** for extreme outliers. `Illuminate\Translation\MessageSelector` (CCN.avg=70.4) and `PhpParser\ParserAbstract` are correctly identified as high-complexity outliers. The issue is at the project-level aggregation, not individual class detection.

---

## Recommended Investigations

1. **Complexity formula:** Increase CCN weight from 0.2 to at least 0.5–0.8, and consider adding a NPath component (`log(npath__avg + 1) / log(100)` normalized). The goal is to spread Monolog/PhpParser/Laravel into a 10–15 point range rather than 1.8 points.

2. **Coupling formula (class-level):** Consider basing the class-level formula on CE (efferent) rather than CBO, or use `min(ca, ce)` to cap the penalty at the smaller direction. Alternatively, add an "instability penalty" for high CE/CBO ratio (true coupling) vs a "popularity bonus" for high CA/CBO ratio (reuse).

3. **Debt/kLOC display:** Either remove this from the default summary view or label it clearly as "cost to fix existing violations per 1kLOC" rather than presenting it as a code quality indicator. An alternative density metric: violations per 100 classes or violations per 1k methods.

4. **Generated code exclusion:** Consider an `--exclude-generated` flag or auto-detection heuristics (file naming, comment patterns) to suppress violations in machine-generated files.
