# Product Research V3 — Remaining Findings

**Extracted:** 2026-03-16
**Source:** [SUMMARY.md](SUMMARY.md)

All findings from V3 research. To be resolved in subsequent work sessions.

---

## HIGH Severity

| #   | Issue                                                        | Agent        | Category        |
| --- | ------------------------------------------------------------ | ------------ | --------------- |
| H1  | Class-level `mi` always 100.0 (dead metric)                  | Mirage       | anomalous-value |
| H2  | Complexity health floors at 0.0 for small projects           | Mirage       | formula-issue   |
| H3  | Distance rule silently produces zero results for vendor code | Cartographer | metric-gap      |
| H4  | Data class rule flags interfaces and abstract classes        | Detective    | false-positive  |
| H5  | TCC=1.00 contradicts LCOM=116 on same class (paradox)        | Detective    | metric-gap      |
| H6  | Type coverage dominates Laravel rankings, no way to exclude  | Rick         | ux-issue        |
| H7  | Violation cap at 50 in JSON blocks AI analysis               | HAL 9000     | missing-data    |
| H8  | `message === recommendation` in 100% of violations           | HAL 9000     | schema-issue    |
| H9  | No dependency graph data in JSON/metrics-json                | HAL 9000     | missing-data    |
| H10 | identical-subexpression: 99% FP on generated code            | Sommelier    | false-positive  |
| H11 | debug-code: ~75% FP in frameworks (flags API methods)        | Sommelier    | false-positive  |

## MEDIUM Severity

| #   | Issue                                                                   | Agent        | Category            |
| --- | ----------------------------------------------------------------------- | ------------ | ------------------- |
| M1  | MessageSelector CCN=334 lookup table ranked #2 worst                    | Rick         | misleading-metric   |
| M2  | 61 identical long-parameter-list warnings in one trait                  | Rick         | false-positive      |
| M3  | Namespace "direct" vs "roll-up" score confusing                         | Rick         | ux-issue            |
| M4  | Maintainability dimension: 10.8pt range, near-zero discrimination       | Mirage       | zero-discrimination |
| M5  | 782 identical-subexpr violations inflate PHP-Parser by 54%              | Mirage       | anomalous-value     |
| M6  | 6/8 projects labeled "Acceptable" — label clustering                    | Mirage       | zero-discrimination |
| M7  | Debt/1kLOC inversely correlates with health                             | Mirage       | counterintuitive    |
| M8  | Class-level instability: 76% FP (leaf classes Ca=0)                     | Cartographer | false-positive      |
| M9  | ClassRank thresholds don't scale with project size                      | Cartographer | formula-issue       |
| M10 | CBO message misleading for interfaces (Ca vs Ce direction)              | Cartographer | ux-issue            |
| M11 | BuilderFactory false positive god class (factory pattern)               | Detective    | false-positive      |
| M12 | Data class: small service classes flagged (NodeFinder, ParserFactory)   | Detective    | false-positive      |
| M13 | 63% PHP-Parser classes TCC=0.00 drags cohesion health                   | Detective    | threshold-issue     |
| M14 | Data class rule fires on zero-property classes                          | Detective    | false-positive      |
| M15 | ~20% CCN violations are mechanical branching (switch/match)             | Compass      | false-positive      |
| M16 | NPath >10^9 display is not actionable                                   | Compass      | ux-issue            |
| M17 | Metric divergence (CCN vs cognitive) not surfaced explicitly            | Compass      | metric-gap          |
| M18 | Health decomposition always empty in JSON                               | HAL 9000     | missing-data        |
| M19 | Tech debt: only 2 distinct values (30/45 min)                           | HAL 9000     | schema-issue        |
| M20 | computed.health violations are generic (no dimension detail)            | HAL 9000     | ux-issue            |
| M21 | Circular dependency cycle data not structured in JSON                   | HAL 9000     | schema-issue        |
| M22 | Data class over-flags exception classes                                 | Sommelier    | false-positive      |
| M23 | boolean-argument: ~50% FP in framework code                             | Sommelier    | threshold-issue     |
| M24 | empty-catch: chain-of-responsibility false positives                    | Sommelier    | false-positive      |
| M25 | hardcoded-credentials: 100% FP (translation file keys)                  | Sommelier    | false-positive      |
| M26 | unused-private misses trait method calls                                | Sommelier    | false-positive      |
| M27 | Three security rules never fire (sql-injection, xss, command-injection) | Sommelier    | unknown             |

## LOW Severity

| #   | Issue                                                            | Agent        | Category             |
| --- | ---------------------------------------------------------------- | ------------ | -------------------- |
| L1  | CBO direction unclear on utility classes (Str CBO=191)           | Rick         | ux-issue             |
| L2  | TCC=1.00 for static utility class (101 static methods)           | Rick         | misleading-metric    |
| L3  | 177-class circular dependency not actionable                     | Rick         | ux-issue             |
| L4  | Coupling health uses only CBO average                            | Cartographer | metric-gap           |
| L5  | No dependency list for high-CBO classes                          | Cartographer | missing-insight      |
| L6  | WMC message lacks context (many-simple vs few-complex)           | Detective    | ux-issue             |
| L7  | MI violations 80% overlap with complexity                        | Compass      | discrimination-issue |
| L8  | No JSON schema definition / OpenAPI spec                         | HAL 9000     | missing-data         |
| L9  | Two formats required (json + metrics-json) for complete analysis | HAL 9000     | schema-issue         |
| L10 | `metricValue: null` / `threshold: null` on computed violations   | HAL 9000     | schema-issue         |
| L11 | unused-private doesn't name the unused symbol in message         | Sommelier    | ux-issue             |
