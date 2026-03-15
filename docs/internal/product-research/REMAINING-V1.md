# Product Research V1 — Remaining Findings

**Extracted:** 2026-03-15
**Source:** [SUMMARY.md](SUMMARY.md)

These findings from the first product research round were not fully resolved. They will be tracked alongside V2 findings.

---

## Partially Fixed

| #   | Issue                            | Status          | Notes                                                                                                      |
| --- | -------------------------------- | --------------- | ---------------------------------------------------------------------------------------------------------- |
| M7  | No hints/recommendations in JSON | Partially fixed | `humanMessage` field added to JSON violations, but structured hints from `MetricHintProvider` not surfaced |
| M10 | Tech debt numbers feel inflated  | Partially fixed | Debt density (min/1K LOC) added for relative context, but absolute "65 days" still shown                   |

## Open

| #   | Issue                                                   | Severity | Notes                                                                                              |
| --- | ------------------------------------------------------- | -------- | -------------------------------------------------------------------------------------------------- |
| M3  | Health threshold phrasing ambiguous                     | Medium   | Likely implicitly resolved by label redesign (Strong/Acceptable/Weak/Critical), needs verification |
| L3  | Checkstyle missing `line` on namespace-level violations | Low      | Most consumers handle this, but some may not                                                       |
| L8  | Zero-method classes get maintainability=0               | Low      | `mi__avg` fallback is 0, should be ~75. Formula: `clamp(mi__avg ?? 0, 0, 100)`                     |
| L9  | Complexity scores cluster around 53-55                  | Low      | Harmonic formula saturates quickly, reduces discrimination for medium-large projects               |
| L10 | `--help` lists ~80 rule-specific options                | Low      | Important flags buried among threshold tweaks. No grouping/hiding mechanism                        |
