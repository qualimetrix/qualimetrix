# Product Research V2 — Remaining Findings

**Extracted:** 2026-03-15
**Updated:** 2026-03-16
**Source:** [SUMMARY.md](SUMMARY.md)

These findings from the second product research round were not resolved in the initial fix batch. The remaining items were resolved on 2026-03-16.

---

## Resolved (2026-03-16)

### H9 — Duplicate violations across all output formats ✓

**Severity:** HIGH
**Found by:** Pipeline Patty
**Affects:** All output formats (JSON, SARIF, GitLab, Checkstyle, Baseline)

**Symptom:** Same violation appears 2–4 times for certain files. Confirmed cases:
- `Doctrine\DBAL\Driver\Mysqli\Result.php` — 4 identical `code-smell.unused-private` violations for the same property (line 51, same message, same symbol)
- `Doctrine\DBAL\Portability\Converter.php` — 2 identical `code-smell.boolean-argument` violations per bool-parameter method (lines 41 and 175)

**Root cause:** Global collectors (`DitGlobalCollector`, `NocCollector`, etc.) were reading the full `MetricBag` from the repository (including `DataBag` entries), adding a scalar metric, and writing it back via `add()`. The merge concatenated `DataBag` entries each time, causing 4x duplication of unused-private violations.

**Resolution:** Added `addScalar()` method to `MetricRepositoryInterface` that only touches scalar metrics, never `DataBag` entries. Migrated 7 callers to use `addScalar()`.

---

### M17 — Threshold boundary phrasing ambiguous ("10 (max 10)") ✓

**Severity:** MEDIUM
**Found by:** Captain Obvious

**Symptom:** `Cyclomatic complexity: 10 (max 10) — too many code paths` reads as "value equals the limit, so why is it a violation?" The `(max 10)` phrasing implies the value is within bounds.

**Resolution:** Changed `(max X)` to `(threshold: X)` and `(min X)` to `(threshold: X)` across 26 rule message templates and 10 test assertions. The neutral "threshold:" phrasing avoids implying that the value is within bounds.

---

### L2 — No total debt line at class/namespace level in summary ✓

**Severity:** LOW
**Found by:** Drill Down Diana, Captain Obvious

**Symptom:** When using `--class` or `--namespace`, per-rule debt breakdown is shown but no aggregated total. Users must mentally sum `~2h god-class + ~45min cbo + ~45min type-coverage = ?`.

**Resolution:** Injected `RemediationTimeRegistry` into `SummaryFormatter`. When using `--namespace` or `--class` drill-down, a total tech debt line is now shown.

---

## Partially Fixed (from V1 remainders)

### M7/V1 — No structured hints in JSON

**Status:** Partially addressed by `recommendation` field rename (V2 M8). The `recommendation` field exists but currently duplicates the `message` field for most rules. Only `computed.health` violations have distinct recommendation text. Future work: add actionable guidance distinct from the diagnostic message in all rules.

### M10/V1 — Tech debt numbers feel inflated

**Status:** Partially addressed by adding `(X min/kLOC to fix)` context suffix (V2 M1). Absolute "65 days" numbers are still shown. V2 research (McData) additionally found that debt/kLOC is inversely correlated with project health — small clean projects show higher density than large messy ones. The metric is correct but misleading without context.

---

## Not Reproduced / By Design

### H9-related: Violation deduplication strategy

The baseline system (`ViolationHasher`) already deduplicates by `rule + namespace + type + member + violationCode`. The issue is that identical violations are generated *before* baseline hashing, so the raw violation list has duplicates even when baseline correctly deduplicates them. This is a metric collection issue, not a baseline issue.

### M14 — `--only-rule` + config `enabled: false` confusing behavior

This is arguably correct behavior: config `enabled: false` disables the rule at the configuration layer, and `--only-rule` is a filter on the already-configured rule set. The confusing warning message ("--disable-rule is active") could be improved but the semantics are defensible. Low priority.

### M15 — PHP-Parser worst classes are machine-generated code

No mechanism exists to auto-detect generated code. An `--exclude-generated` flag or `@generated` comment detection would be a new feature, not a bug fix. Tracked separately.
