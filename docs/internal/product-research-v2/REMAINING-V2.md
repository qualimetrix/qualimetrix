# Product Research V2 — Remaining Findings

**Extracted:** 2026-03-15
**Source:** [SUMMARY.md](SUMMARY.md)

These findings from the second product research round were not resolved in the initial fix batch. They require deeper investigation or broader changes.

---

## Not Fixed

### H9 — Duplicate violations across all output formats

**Severity:** HIGH
**Found by:** Pipeline Patty
**Affects:** All output formats (JSON, SARIF, GitLab, Checkstyle, Baseline)

**Symptom:** Same violation appears 2–4 times for certain files. Confirmed cases:
- `Doctrine\DBAL\Driver\Mysqli\Result.php` — 4 identical `code-smell.unused-private` violations for the same property (line 51, same message, same symbol)
- `Doctrine\DBAL\Portability\Converter.php` — 2 identical `code-smell.boolean-argument` violations per bool-parameter method (lines 41 and 175)

**Impact:**
- Inflated violation counts (880 instead of ~873 for Doctrine DBAL)
- GitLab: duplicate fingerprints → violations silently overwrite each other in MR widget
- SARIF: duplicate result groups → potential GHAS display issues
- Baseline: duplicate entries inflate count and waste baseline slots
- JSON `violationsMeta.byRule` counts are inflated

**Root cause (partially traced):** Duplication happens upstream in metric collection or rule execution, not in formatters. For `unused-private`, the class has 1 unused promoted property (`$statementReference`), yet 4 entries appear in the DataBag. For `boolean-argument`, 2 bool parameters on the same line generate identical violations (same line, identical message template, no per-parameter disambiguation).

**Investigation plan:**
1. Reproduce with `bin/aimd check benchmarks/vendor/doctrine/dbal/src --only-rule=code-smell.unused-private --format=json` and inspect raw violations
2. Check `CodeSmellCollector` / `CodeSmellVisitor` for how `unusedPrivate` entries are recorded — suspected: promoted properties counted once per visitor pass, with multiple passes (inheritance chain?)
3. Check `AbstractCodeSmellRule::analyze()` for whether it deduplicates entries before generating violations
4. For `boolean-argument`: check if multi-param signatures generate one entry per param but with identical line numbers, causing same-hash duplicates
5. Fix: deduplicate in rule execution (preferred) or add a dedup pass in `RuleExecutor` before returning violations

---

### L2 — No total debt line at class/namespace level in summary

**Severity:** LOW
**Found by:** Drill Down Diana, Captain Obvious

**Symptom:** When using `--class` or `--namespace`, per-rule debt breakdown is shown but no aggregated total. Users must mentally sum `~2h god-class + ~45min cbo + ~45min type-coverage = ?`.

**Impact:** Minor inconvenience for sprint planning. Per-rule breakdown is already shown via `DetailedViolationRenderer`.

**Implementation plan:**
1. Inject `RemediationTimeRegistry` into `SummaryFormatter` (constructor change)
2. In `renderViolationSummary()`, when `$context->namespace !== null || $context->class !== null`, compute scoped debt total by summing `$registry->getMinutes($v->ruleName)` for each filtered violation
3. Display as `Tech debt: ~7h 40min` in the summary line
4. Update `SummaryFormatterTest` with new constructor argument

**Why deferred:** Changes `SummaryFormatter` constructor (DI wiring impact), needs test updates for the new dependency. Low priority — the data is already visible in per-rule breakdown.

---

### M17 — Threshold boundary phrasing ambiguous ("10 (max 10)")

**Severity:** MEDIUM
**Found by:** Captain Obvious

**Symptom:** `Cyclomatic complexity: 10 (max 10) — too many code paths` reads as "value equals the limit, so why is it a violation?" The `(max 10)` phrasing implies the value is within bounds.

**Affected rules:** All rules using the `value (max/min threshold)` message pattern:
- `complexity.cyclomatic` — "Cyclomatic complexity: 10 (max 10)"
- `complexity.cognitive` — "Cognitive complexity: 15 (max 15)"
- `complexity.npath` — "NPath complexity: 1000 (max 1000)"
- `coupling.cbo` — "CBO: 20 (max 20)"
- `design.lcom` — "LCOM4: 5 (max 5)"
- And others with warning/error thresholds

**Options:**
- **A)** Change phrasing to `value (limit: threshold)` — "Cyclomatic complexity: 10 (limit: 10)"
- **B)** Change phrasing to `value (threshold ≥ threshold)` — "Cyclomatic complexity: 10 (≥ 10 triggers warning)"
- **C)** Use different phrasing for at-threshold vs above-threshold: "Cyclomatic complexity: 10 (at warning threshold)" vs "15 (exceeds warning threshold of 10)"

**Why deferred:** Requires decision on phrasing convention, then mass-update across ~15 rule message templates. The current messages are functional — the trailing `— too many code paths` explanation mitigates the confusion.

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
