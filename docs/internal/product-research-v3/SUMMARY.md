# Product Research V3 — Architecture Analysis Summary

**Date:** 2026-03-16
**Focus:** Practical usefulness of architectural metrics — do the numbers help make real decisions?
**Methodology:** 7 AI agents (5 human personas, 1 AI persona, 1 QA persona), testing on 8 benchmark projects

## Research Team

| Agent                 | Persona              | Focus                                          | Report                            |
| --------------------- | -------------------- | ---------------------------------------------- | --------------------------------- |
| Refactor Rick         | Senior PHP dev       | Worst offenders accuracy, drill-down workflow  | [01](01-refactor-rick.md)         |
| Metric Mirage         | Data analyst skeptic | Cross-project anomalies, metric discrimination | [02](02-metric-mirage.md)         |
| Coupling Cartographer | Software architect   | CBO, instability, distance, circular deps      | [03](03-coupling-cartographer.md) |
| Cohesion Detective    | Tech lead            | TCC/LCOM/god-class/data-class precision        | [04](04-cohesion-detective.md)    |
| Complexity Compass    | Code reviewer        | CCN/cognitive/NPath/MI usefulness              | [05](05-complexity-compass.md)    |
| HAL 9000              | AI coding assistant  | JSON for programmatic architecture analysis    | [06](06-hal-9000.md)              |
| Code Smell Sommelier  | QA lead              | Code smell + security FP rates                 | [07](07-code-smell-sommelier.md)  |

## Projects Analyzed

| Project         | Files | Classes | Health            | Key Insight                                   |
| --------------- | ----: | ------: | ----------------: | --------------------------------------------- |
| Flysystem       | 55    | 55      | 88.5 (Strong)     | Cleanest codebase, good discrimination target |
| Monolog         | 121   | 121     | 68.3 (Acceptable) | Focused library, reasonable coupling          |
| Symfony Console | 132   | 132     | 57.9 (Acceptable) | Well-architected, complexity concentrated     |
| PHP-Parser      | 269   | 268     | 59.3 (Acceptable) | AST nodes inflate violation counts            |
| Doctrine ORM    | 453   | 453     | 64.6 (Acceptable) | Known god classes perfectly detected          |
| Composer        | 286   | 285     | 45.4 (Weak)       | Worst overall — genuinely problematic         |
| Guzzle          | 41    | 39      | 61.9 (Acceptable) | Small project, complexity floor effect        |
| Laravel         | 1536  | 1441    | 52.5 (Acceptable) | Type coverage dominates rankings              |

---

## Consolidated Findings

### HIGH — Metric Accuracy / Trust Issues

| #   | Issue                                                     | Found by     | Description                                                                                                                                                         |
| --- | --------------------------------------------------------- | ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| H1  | **Class-level `mi` is always 100.0**                      | Mirage       | Dead metric: 2,793/2,793 classes across 8 projects report `mi=100`. Useful values are `mi.avg` and `mi.min`.                                                        |
| H2  | **Complexity health floors at 0.0 for small projects**    | Mirage       | Guzzle (avg CCN=3.3) and Composer get 0.0 complexity. WMC-based namespace aggregation penalizes small projects with concentrated complexity.                        |
| H3  | **Distance rule silently broken for vendor code**         | Cartographer | `ProjectNamespaceResolver` doesn't detect vendor namespaces. Zero violations, no warning. With explicit `include_namespaces`, finds 18 real violations in Doctrine. |
| H4  | **Data class rule flags interfaces and abstract classes** | Detective    | `NodeVisitor`, `Driver\Connection`, etc. flagged as data classes. Interfaces have 100% WOC by definition — not data classes. ~25% of all data class FPs.            |
| H5  | **TCC=1.00 contradicts LCOM=116 on same class**           | Detective    | `PrettyPrinter\Standard`: all methods connected via TCC, but LCOM says 116 components. Paradox undermines trust in cohesion metrics.                                |
| H6  | **Type coverage dominates Laravel rankings**              | Rick         | 4,804 of 7,443 violations are type coverage. Structural problems (god classes, coupling) buried. No way to exclude typing from health.                              |
| H7  | **Violation cap at 50 in JSON blocks AI analysis**        | HAL 9000     | 50/1177 violations shown for Doctrine (4%). AI agent cannot perform comprehensive analysis. Workaround: per-namespace drill-down.                                   |
| H8  | **`message === recommendation` in 100% of violations**    | HAL 9000     | No actionable fix guidance in JSON. `recommendation` field duplicates `message` in every case. Wastes tokens and provides no value.                                 |
| H9  | **No dependency graph data in JSON/metrics-json**         | HAL 9000     | Ca/Ce/CBO counts exist but no adjacency list. Cannot determine who depends on whom, safe refactoring order, or impact radius.                                       |
| H10 | **identical-subexpression: 99% FP on generated code**     | Sommelier    | 782 violations in PHP-Parser, all from YACC-generated parser files. Single rule accounts for 90% of project violations.                                             |
| H11 | **debug-code: ~75% FP in frameworks**                     | Sommelier    | Flags intentional API methods (`Dumpable::dd()`, `Builder::dump()`, `var_export($x, true)`). Severity is ERROR — too aggressive.                                    |

### MEDIUM — Formula / Detection Issues

| #   | Issue                                                          | Found by     | Description                                                                                                                   |
| --- | -------------------------------------------------------------- | ------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| M1  | **MessageSelector CCN=334 is a lookup table, ranked #2 worst** | Rick         | Giant switch mapping ~300 locale codes. Not genuinely complex. Wastes investigation time.                                     |
| M2  | **61 identical long-parameter-list warnings in one trait**     | Rick         | `ReplacesAttributes`: every method has same 4-param signature (framework convention). 61 duplicated violations.               |
| M3  | **Namespace "direct" vs "roll-up" score is confusing**         | Rick         | Namespace at 39.5% in summary, but 79.7% Strong in drill-down. The distinction isn't explained.                               |
| M4  | **Maintainability dimension barely discriminates**             | Mirage       | 10.8-point range across 8 projects (71.7-82.5). 7/8 labeled "Acceptable". Decomposition always empty.                         |
| M5  | **782 identical-subexpr violations inflate PHP-Parser by 54%** | Mirage       | Domain-specific false positives dominate violation count and tech debt.                                                       |
| M6  | **6/8 projects labeled "Acceptable" — label clustering**       | Mirage       | 23-point spread compressed into one label. Granularity too coarse.                                                            |
| M7  | **Debt/1kLOC inversely correlates with health**                | Mirage       | Flysystem (health=88.5) has higher debt density than Laravel (health=52.5). Contradictory signals.                            |
| M8  | **Class-level instability: excessive false positives**         | Cartographer | 13/17 Symfony class-level instability violations are leaf classes (Ca=0). I=1.00 for leaf classes is architecturally correct. |
| M9  | **ClassRank thresholds don't scale with project size**         | Cartographer | Laravel (1536 classes) gets 4 violations, Symfony (132 classes) gets 11. PageRank dilutes across more nodes.                  |
| M10 | **CBO message misleading for interfaces**                      | Cartographer | "Depends on too many classes" when 44/45 couplings are afferent. Should distinguish Ca vs Ce direction.                       |
| M11 | **BuilderFactory false positive god class**                    | Detective    | Pure factory with 28 independent creator methods. Low cohesion is by design.                                                  |
| M12 | **Data class: small service classes flagged**                  | Detective    | `NodeFinder` (4 methods, 0 properties) flagged as data class. No properties = not a data class.                               |
| M13 | **63% of PHP-Parser classes have TCC=0.00**                    | Detective    | Tiny AST node classes drag down cohesion health to 48.8% "Weak" for a well-designed codebase.                                 |
| M14 | **Data class rule fires on zero-property classes**             | Detective    | Classes with only methods and no fields flagged as "data classes". By definition, data classes hold data.                     |
| M15 | **~20% CCN violations are mechanical branching**               | Compass      | Switch/match/lookup tables inflate CCN without real complexity. Cognitive complexity handles this correctly.                  |
| M16 | **NPath >10^9 display is not actionable**                      | Compass      | Any NPath >10000 is clearly problematic. Showing "> 10^9" is presentation noise.                                              |
| M17 | **High CCN + low cognitive = metric divergence not surfaced**  | Compass      | Reviewer must mentally cross-reference rules to identify mechanical branching. Tool doesn't highlight divergence.             |
| M18 | **Health decomposition always empty in JSON**                  | HAL 9000     | Can't explain WHY a health score is low. No formula inputs exposed.                                                           |
| M19 | **Tech debt: only 2 distinct values (30/45 min)**              | HAL 9000     | CCN=21 and NPath>10^9 have the same 30-min debt. Useless for prioritization.                                                  |
| M20 | **computed.health violations are generic**                     | HAL 9000     | 231 violations in Doctrine with same "reduce complexity" message. No dimension-specific detail.                               |
| M21 | **Circular dependency cycle data not structured in JSON**      | HAL 9000     | "SQLFilter -> EntityManagerInterface -> FilterCollection -> SQLFilter" is a string, not structured data.                      |
| M22 | **Data class over-flags exception classes**                    | Sommelier    | 16/18 Flysystem data-class findings are on exception classes. Exceptions are DTOs by design.                                  |
| M23 | **boolean-argument: ~50% FP in framework code**                | Sommelier    | Framework API booleans ($force, $strict) are pragmatic design. Detection is correct but noisy.                                |
| M24 | **empty-catch: chain-of-responsibility false positives**       | Sommelier    | `foreach + try { return ... } catch { }` is intentional chain pattern, not negligent error handling.                          |
| M25 | **hardcoded-credentials: 100% FP**                             | Sommelier    | Both findings are translation file keys containing "password". Not credentials.                                               |
| M26 | **unused-private misses trait method calls**                   | Sommelier    | `XmlDriver::doLoadMappingFile()` flagged as unused but called via trait composition.                                          |
| M27 | **Three security rules never fire**                            | Sommelier    | sql-injection, xss, command-injection: zero findings across 2313 files. Unknown if working or too conservative.               |

### LOW — UX / Polish

| #   | Issue                                                              | Found by     | Description                                                                                                             |
| --- | ------------------------------------------------------------------ | ------------ | ----------------------------------------------------------------------------------------------------------------------- |
| L1  | **CBO=191 on utility class: direction unclear**                    | Rick         | `Str` CBO=191 from afferent coupling. Message says "depends on too many" but it's "too many depend on it".              |
| L2  | **TCC=1.00 for static utility class**                              | Rick         | `Illuminate\Support\Str`: 101 static methods, zero shared state, TCC=1.00. Technically correct, practically misleading. |
| L3  | **177-class circular dependency not actionable**                   | Rick         | Too large to act on. Small 2-3 class cycles are more useful.                                                            |
| L4  | **Coupling health uses only CBO average**                          | Cartographer | Instability, distance, ClassRank don't feed into health score.                                                          |
| L5  | **No dependency list for high-CBO classes**                        | Cartographer | CBO=66 but can't see which 66 classes. Essential for reducing coupling.                                                 |
| L6  | **WMC message lacks context**                                      | Detective    | "Total method complexity is high" — doesn't say if it's many-simple or few-complex methods.                             |
| L7  | **MI violations 80% overlap with complexity**                      | Compass      | Marginal value for review prioritization. Catches "long but simple" methods only.                                       |
| L8  | **No JSON schema definition**                                      | HAL 9000     | AI agents discover structure empirically.                                                                               |
| L9  | **Metrics-json has no violations, JSON has no raw metrics**        | HAL 9000     | Two invocations required for complete analysis.                                                                         |
| L10 | **`metricValue: null` / `threshold: null` on computed violations** | HAL 9000     | Breaks programmatic `metricValue > threshold` filtering.                                                                |
| L11 | **unused-private doesn't name the unused symbol**                  | Sommelier    | "Unused private method" without saying which one.                                                                       |

---

## What Works Well

Confirmed strengths across multiple agents:

1. **Drill-down workflow is excellent** (Rick, HAL) — Summary → namespace → class → detail is intuitive and effective. The contextual hints ("Try: --namespace=...") are the killer UX feature.

2. **Worst offenders match reality** (Rick, Compass) — Doctrine: 5/5 accuracy. God class detection: ~75% true positive rate across all projects. Extreme cognitive complexity (>100): 100% accuracy.

3. **Cognitive complexity is the best metric** (Compass) — Near-zero false positives for "hard to understand" code. Correctly ignores mechanical branching. The clear winner for code review prioritization.

4. **Circular dependency detection is accurate** (Cartographer) — Correctly finds Doctrine EM/UnitOfWork/Filter triangle, Laravel View/Factory cycle, Symfony HelperSet bidependency. Real architectural insights.

5. **Namespace-level instability is architecturally insightful** (Cartographer) — Exception=0.13 (stable), Tester=1.00 (unstable), Input=0.19 (stable) — all match expected architecture.

6. **Typing dimension discriminates strongly** (Mirage) — 81.1-point range. Correctly identifies Doctrine as type-safety leader and Laravel as laggard.

7. **Coupling dimension discriminates well** (Mirage) — 72.3-point range. Rankings match intuition: Flysystem (96.4) > Guzzle (93.6) > ... > Composer (24.1).

8. **Cross-format consistency is perfect** (Mirage) — Zero NaN/null/infinity across 8 projects. Health scores always in [0, 100]. Data quality is clean.

9. **sensitive-parameter is excellent** (Sommelier) — 27/27 Laravel findings correctly identify credential-handling methods. ~5% FP rate. High-value security improvement.

10. **WMC is the strongest class-level metric** (Detective) — ~88% true positive rate. Most accurate single indicator of class complexity.

11. **Namespace drill-down in JSON is useful** (HAL) — Removes truncation, provides focused class-level detail. `directScore` vs `score` distinction is smart.

12. **Smell profiles reflect project character** (Sommelier) — Framework (Laravel): diverse smells. ORM (Doctrine): god-classes. Parser (PHP-Parser): generated code noise. Coherent story per project.

---

## Cross-Cutting Themes

### 1. Formula Tuning at Extremes

The health score formulas work well in the 30th-70th percentile but produce counterintuitive results at extremes:
- **Complexity floors at 0.0** for small projects with concentrated complexity (Guzzle, Composer)
- **Maintainability compresses to 10.8-point range** — nearly useless for cross-project comparison
- **Labels cluster**: 6/8 projects "Acceptable" despite 23-point spread
- **Class-level `mi` = 100 always** — a dead metric

### 2. False Positives Have Patterns

The highest FP rates correlate with specific code patterns:
- **Generated code** → identical-subexpression (99% FP)
- **Framework API methods** → debug-code (75% FP), boolean-argument (50% FP)
- **Interface contracts** → data-class (100% FP on interfaces)
- **Leaf/concrete classes** → class-level instability (76% FP)
- **Factory/visitor patterns** → god-class (25% FP)

These are predictable and could be reduced with contextual awareness (detect `@generated`, exclude interfaces, skip leaf Ca=0 classes).

### 3. Metric Divergence Is the Strongest Signal

When metrics disagree, it reveals the nature of the problem:
- **High CCN + no cognitive** → mechanical branching (switch/match) → not a review priority
- **High cognitive + no CCN** → nesting-driven complexity → review carefully
- **High NPath + neither CCN nor cognitive** → independent conditions → testing concern
- **High CBO + low Ce** → coupling magnet (many depend on it) → probably a healthy abstraction

AIMD computes all these but doesn't surface the divergence pattern. Users must cross-reference manually.

### 4. The AI Consumer Story Is 80% There

JSON output has good structure, useful `worstNamespaces`/`worstClasses`, and effective namespace drill-down. But three gaps prevent full programmatic analysis:
- 50-violation cap without override
- No `recommendation` distinct from `message`
- No dependency graph data

### 5. The Tool Is Remarkably Accurate

Across 7 agents and 8 projects: Doctrine worst offenders 5/5 correct, cognitive complexity >100 is 100% correct, WMC 88% true positive, god-class 75% true positive. The tool finds real problems. The main issues are noise (false positives in specific patterns) and formula edge cases (small projects, extreme values), not fundamental accuracy.

---

## Prioritized Action Plan

### P0 — Trust / Accuracy

1. **Fix class-level `mi` always being 100** (H1) — Either compute real class-level MI or remove the field. Currently misleading.

2. **Fix complexity health floor effect** (H2) — Small projects shouldn't get 0.0 for moderate per-method complexity. Consider using per-method CCN averages alongside WMC.

3. **Warn when distance rule finds zero namespaces** (H3) — Silent failure is trust-breaking. Emit diagnostic message.

4. **Exclude interfaces from data-class detection** (H4, M14) — Add `excludeInterfaces: true` default. Exclude zero-property classes.

### P1 — Noise Reduction

5. **Differentiate CBO message by direction** (M10, L1) — "Ca=44 classes depend on this" vs "Ce=20 outward dependencies". Especially important for interfaces.

6. **Fix class-level instability noise** (M8) — Only flag when Ca > 0, or remove class-level instability violations entirely.

7. **Scale ClassRank thresholds by project size** (M9) — `threshold = base / sqrt(classCount)` or percentile-based.

8. **Improve debug-code contextual awareness** (H11) — Don't flag method definitions named `dump`/`dd`. Don't flag `var_export($x, true)`. Lower severity to WARNING.

9. **Reduce hardcoded-credentials FP** (M25) — Exclude translation/lang directory keys. Require value (not key) to match credential patterns.

### P2 — Formula Improvements

10. **Widen maintainability discrimination** (M4) — Current 10.8-point range is useless. Adjust formula or expose decomposition.

11. **Reduce label clustering** (M6) — Consider 5 tiers or adjust thresholds so scores 45-68 don't all map to "Acceptable".

12. **Add TCC veto to god-class detection** (H5) — If TCC >= 0.5, skip LCOM criterion. A cohesive class (by TCC) shouldn't be called a god class.

13. **Exclude exception classes from data-class** (M22) — Classes extending `\Exception` are DTOs by design.

### P3 — JSON / AI Consumer

14. **Add `--limit=N` for violation count in JSON** (H7) — Default 50, allow `--limit=0` for unlimited.

15. **Populate `recommendation` with distinct actionable advice** (H8) — Or remove the field. Current duplication wastes tokens.

16. **Expose dependency graph in metrics-json** (H9) — Per-class adjacency list: `{from, to, type}`. Data already computed internally.

17. **Populate health decomposition** (M18) — Show formula inputs per dimension.

18. **Scale tech debt by severity overshoot** (M19) — `debt = base * log(value / threshold)`.

19. **Structure circular dependency data in JSON** (M21) — `{cycle: [...], length: N}` instead of human-readable string.

### P4 — Polish

20. **Add minimum method count for TCC evaluation** (M13) — Skip classes with <4 non-constructor methods.

21. **Surface metric divergence patterns** (M17) — Flag when CCN is high but cognitive is low (likely mechanical branching).

22. **Name the unused symbol in unused-private message** (L11) — "Unused private method `doLoadMappingFile`".

23. **Cap NPath display at >1M** (M16) — Any value above 10^6 conveys the same message.

24. **Fix unused-private for trait method calls** (M26) — Resolve trait composition when checking references.

---

## Metric Quality Assessment

| Metric                  | Discrimination         | Accuracy          | Actionability         | Verdict                      |
| ----------------------- | ---------------------- | ----------------- | --------------------- | ---------------------------- |
| Cognitive Complexity    | Excellent              | ~100% TP for >30  | High                  | **Best metric**              |
| WMC                     | Good                   | ~88% TP           | High                  | **Strongest class metric**   |
| God Class (composite)   | Good                   | ~75% TP           | Very high             | **Most actionable rule**     |
| CBO                     | Good                   | High              | Medium (no dep list)  | Good but needs direction     |
| Typing Coverage         | Excellent (81pt range) | Accurate          | Medium                | Strong discriminator         |
| Coupling Health         | Good (72pt range)      | Accurate          | Medium                | CBO-only proxy               |
| Cyclomatic Complexity   | Good                   | ~80% TP           | Medium                | Over-flags switch/match      |
| NPath                   | Good                   | High              | Low (extreme values)  | Unique testing signal        |
| Namespace Instability   | Good                   | Accurate          | High                  | Best at namespace level      |
| sensitive-parameter     | N/A                    | ~95% TP           | Very high             | Excellent security rule      |
| Circular Dependencies   | N/A                    | Accurate          | Medium (large cycles) | Good for small cycles        |
| LCOM4                   | Moderate               | ~67% TP           | Medium                | Best for 2-10 range          |
| TCC/LCC                 | Moderate               | Mixed             | Low (small classes)   | Needs min-method filter      |
| Data Class              | Poor                   | ~40% TP           | Low                   | Too many FPs                 |
| MI Health               | Poor (11pt range)      | Compressed        | Very low              | Nearly useless cross-project |
| Class-level `mi`        | Dead                   | Always 100        | None                  | Remove or fix                |
| Class-level Instability | Poor                   | Correct but noisy | Very low              | Remove or restrict           |
| boolean-argument        | Moderate               | Correct           | Low (frameworks)      | Context-dependent            |
| identical-subexpr       | Good (non-generated)   | Correct           | Low                   | Needs generated-code filter  |
| debug-code              | Poor                   | ~25% TP           | Low                   | Needs context awareness      |
| hardcoded-credentials   | Poor                   | ~0% TP            | None                  | Needs value-based detection  |

---

## Previous Research Status

See [CARRYOVER.md](CARRYOVER.md) for items carried from V1/V2.
