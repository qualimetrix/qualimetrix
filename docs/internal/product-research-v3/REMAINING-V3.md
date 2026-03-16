# Product Research V3 — Findings Resolution

**Extracted:** 2026-03-16
**Source:** [SUMMARY.md](SUMMARY.md)
**Updated:** 2026-03-16 (all findings resolved)

---

## Resolved

All HIGH findings (H1-H11) and most MEDIUM/LOW findings were resolved across 3 batches:

- **Batch 1** (da666d8): H1, H3, H4, H8, H11, M10, M14, M22, M25, L1
- **Batch 2** (e056cd7): H2, H5, H6, H7, M6, M8, M16, L11
- **Batch 3** (e651695): M3, M4, M9, M13, M18, M19, M20, M21, M24, L2, L3, L6, L10

Remaining "Future Work" items were implemented in subsequent sessions:

- **H9** — `graph:export --format=json` adjacency list export (8e6ab9e)
- **H10** — `@generated` annotation detection + `--include-generated` flag (4c6f85a)
- **M17** — CCN/cognitive divergence hint in recommendations (21df0f5)
- **M26** — Same-file trait method call resolution for unused-private (3cdc550)
- **M27** — XSS and command injection detection for interpolated strings (bd1b89f)
- **L4** — Coupling health formula uses CE + distance (not just CBO average)
- **L5** — CBO violations include top-5 efferent dependencies (9c6b734)

## By Design (no action needed)

| #   | Issue                                        | Reason                                                                                                   |
| --- | -------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| M1  | CCN=334 on switch/lookup table               | Inherent CCN behavior. Cognitive complexity correctly ignores it. Documented in Guide Notes.             |
| M2  | 61 identical long-parameter-list warnings    | Per-occurrence model — each method is a separate violation. Collapsing would break baseline/suppression. |
| M5  | 782 identical-subexpr in PHP-Parser          | Generated YACC code. Workaround: `--exclude` for generated files. Related to H10.                        |
| M7  | Debt/kLOC inversely correlates with health   | Mathematically correct — small projects with concentrated issues have higher density per line.           |
| M11 | BuilderFactory false positive god class      | Factory pattern — low cohesion is by design. Use `@aimd-ignore code-smell.god-class`.                    |
| M12 | Data class: small service classes            | Partially fixed (zero-property excluded). Remaining cases are legitimate edge cases.                     |
| M15 | ~20% CCN violations are mechanical branching | Fundamental CCN property. Cognitive complexity is the answer. Documented.                                |
| M23 | boolean-argument ~50% FP in frameworks       | Detection is correct per Martin Fowler's definition. Framework noise is documented.                      |
| L7  | MI violations 80% overlap with complexity    | By design — MI formula includes complexity. MI catches "long but simple" methods only.                   |
| L8  | No JSON schema / OpenAPI spec                | Documentation work, not a code change.                                                                   |
| L9  | Two formats required (json + metrics-json)   | Intentional design — violation JSON stays small, metrics-json is comprehensive.                          |
