# Product Research V1 — Remaining Findings

**Extracted:** 2026-03-15
**Updated:** 2026-03-16 (after V2 remaining fixes)
**Source:** [SUMMARY.md](SUMMARY.md)

---

## Resolved by V2 Fixes

| #   | Issue                                                   | Resolution                                                                                         |
| --- | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| M7  | No hints/recommendations in JSON                        | `humanMessage` renamed to `recommendation`. `computed.health` has real guidance. Others still TBD. |
| L9  | Complexity scores cluster around 53-55                  | Linear formula with CCN×2.0 + cognitive×2.5 + NPath×0.5. Spread improved from 1.8pt to 6.6pt.      |
| M3  | Health threshold phrasing ambiguous                     | Resolved by label redesign (Strong/Acceptable/Weak/Critical) + per-dimension footnote (H8).        |
| L3  | Checkstyle missing `line` on namespace-level violations | Fixed in previous commit (namespace-level violations now emit line numbers).                       |

## Still Open

| #   | Issue                                     | Severity | Notes                                                                            |
| --- | ----------------------------------------- | -------- | -------------------------------------------------------------------------------- |
| L8  | Zero-method classes get maintainability=0 | Low      | `mi__avg` fallback is 0, should be ~75. By design — MI undefined without methods |
| L10 | `--help` lists ~80 rule-specific options  | Low      | Important flags buried among threshold tweaks. No grouping/hiding mechanism      |

## Won't Fix (by design)

| #   | Issue                           | Reason                                                        |
| --- | ------------------------------- | ------------------------------------------------------------- |
| L8  | Zero-method MI = 0              | MI is undefined without methods; 0 is correct fallback        |
| M10 | Tech debt numbers feel inflated | Debt density added for context; absolute numbers are accurate |
