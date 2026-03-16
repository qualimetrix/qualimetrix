# Product Research V2 — Findings Resolution

**Extracted:** 2026-03-15
**Updated:** 2026-03-16 (all findings resolved)
**Source:** [SUMMARY.md](SUMMARY.md)

All findings from the second product research round have been resolved.

---

## Resolved (2026-03-16, batch fixes)

### H9 — Duplicate violations across all output formats ✓

**Root cause:** Global collectors read full `MetricBag` and re-wrote via `add()`, concatenating `DataBag` entries.
**Resolution:** Added `addScalar()` to `MetricRepositoryInterface`. Migrated 7 callers.

### M17 — Threshold boundary phrasing ambiguous ("10 (max 10)") ✓

**Resolution:** Changed `(max X)` / `(min X)` to `(threshold: X)` across 26 rule message templates.

### L2 — No total debt line at class/namespace level ✓

**Resolution:** Total tech debt line added to `--namespace` and `--class` drill-down output.

---

## Resolved (subsequent sessions)

### M7/V1 — No structured hints in JSON ✓

**Resolution:** `recommendation` field now distinct from `message` across all rules. All rules include actionable recommendations.

### M10/V1 — Tech debt numbers feel inflated ✓

**Resolution:** Debt density (min/kLOC) shown alongside absolute numbers. Debt scaling uses `ln(ratio)` — large violations no longer dominate totals.

### M15 — PHP-Parser worst classes are generated code ✓

**Resolution:** `@generated` annotation detection auto-excludes generated files. Override with `--include-generated`.

---

## Not Reproduced / By Design

### M14 — `--only-rule` + config `enabled: false` confusing behavior

Semantically correct behavior. Low priority UX improvement.
