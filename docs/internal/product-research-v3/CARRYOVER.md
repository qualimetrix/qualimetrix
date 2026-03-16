# Carryover from V1/V2 Research

Items carried forward from previous research rounds. All resolved as of 2026-03-16.

## Resolved

| #      | Source       | Issue                                    | Resolution                                                                                   |
| ------ | ------------ | ---------------------------------------- | -------------------------------------------------------------------------------------------- |
| L10/V1 | V1 REMAINING | `--help` lists ~80 rule-specific options | Rule options hidden from `--help`; `bin/aimd rules` subcommand added                         |
| M7/V1  | V1→V2        | No structured hints in JSON              | `recommendation` field now distinct from `message` across all rules                          |
| M10/V1 | V1→V2        | Tech debt numbers feel inflated          | Debt density (min/kLOC) shown alongside absolute numbers; debt scaled by `ln(ratio)`         |
| M15/V2 | V2           | PHP-Parser worst classes are generated   | `@generated` annotation detection + `--include-generated` flag auto-excludes generated files |

## Won't Fix (by design)

| #      | Source | Issue                                      | Reason                                                 |
| ------ | ------ | ------------------------------------------ | ------------------------------------------------------ |
| L8/V1  | V1     | Zero-method MI = 0                         | MI is undefined without methods; 0 is correct fallback |
| M14/V2 | V2     | `--only-rule` + `enabled: false` confusing | Semantically correct, low priority UX improvement      |
