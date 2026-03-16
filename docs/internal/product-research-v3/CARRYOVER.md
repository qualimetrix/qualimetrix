# Carryover from V1/V2 Research

Items not resolved in previous research rounds, carried forward for tracking.

## Still Open

| #      | Source       | Issue                                    | Severity | Notes                                                                                                                 |
| ------ | ------------ | ---------------------------------------- | -------- | --------------------------------------------------------------------------------------------------------------------- |
| L10/V1 | V1 REMAINING | `--help` lists ~80 rule-specific options | Low      | Important flags buried among threshold tweaks. No grouping/hiding mechanism. `bin/aimd rules` exists but not obvious. |

## Partially Addressed

| #      | Source | Issue                           | Status  | Notes                                                                                                                 |
| ------ | ------ | ------------------------------- | ------- | --------------------------------------------------------------------------------------------------------------------- |
| M7/V1  | V1→V2  | No structured hints in JSON     | Partial | `recommendation` field exists but duplicates `message` for most rules. Only `computed.health` has distinct guidance.  |
| M10/V1 | V1→V2  | Tech debt numbers feel inflated | Partial | Added debt/kLOC context. Absolute numbers still shown. Inversely correlated with health — misleading without context. |

## Won't Fix (by design)

| #      | Source | Issue                                       | Reason                                                 |
| ------ | ------ | ------------------------------------------- | ------------------------------------------------------ |
| L8/V1  | V1     | Zero-method MI = 0                          | MI is undefined without methods; 0 is correct fallback |
| M14/V2 | V2     | `--only-rule` + `enabled: false` confusing  | Semantically correct, low priority UX improvement      |
| M15/V2 | V2     | PHP-Parser worst classes are generated code | Would need `@generated` detection — new feature        |
