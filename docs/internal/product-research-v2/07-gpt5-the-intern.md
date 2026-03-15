# 07 — GPT-5 the Intern: AI Agent Consumption Audit

**Persona:** AI coding assistant consuming JSON output to build refactoring plans
**Projects:** PHP-Parser (`benchmarks/vendor/nikic/php-parser/lib`), Doctrine ORM (`benchmarks/vendor/doctrine/orm/src`)
**Focus:** Can an AI programmatically consume AIMD JSON output to produce actionable refactoring plans?

---

## Summary

AIMD's default `--format=json` output is compact and fits in 32K context, with a clean, consistent violation schema. However, three issues block AI agents from building complete refactoring plans: (1) the default 50-violation cap exposes as little as 4% of total violations with no per-rule breakdown, (2) `worstClasses` is silently empty when no class falls below the 50% health threshold — even when the project has 1143 violations and measurably problematic classes, and (3) `--class` drill-down returns full project health scores instead of the target class's health, making it actively misleading. The `--format=metrics-json` format is too large for any LLM context window (>500K tokens) and must only be used with targeted filtering or post-processing.

---

## Token Budget

| Report                                                 | Bytes     | ~Tokens   | Fits 8K | Fits 32K | Fits 128K |
| ------------------------------------------------------ | --------: | --------: | :-----: | :------: | :-------: |
| `--format=json` (PHP-Parser, default)                  | 38,960    | 9,740     | NO      | YES      | YES       |
| `--format=json` (Doctrine, default)                    | 37,901    | 9,475     | NO      | YES      | YES       |
| `--format=json --namespace=X` (either)                 | ~138,000  | ~34,600   | NO      | NO       | YES       |
| `--format=json --format-opt=violations=all` (Doctrine) | 756,494   | 189,123   | NO      | NO       | NO        |
| `--format=metrics-json` (PHP-Parser)                   | 2,254,194 | 563,548   | NO      | NO       | NO        |
| `--format=metrics-json` (Doctrine)                     | 4,123,572 | 1,030,893 | NO      | NO       | NO        |

**Key takeaway:** Default `--format=json` fits in a 32K context and is suitable for an overview pass. Namespace drill-downs (~35K tokens) require a 128K context. `--format=metrics-json` is too large for any direct LLM consumption and requires offline processing.

---

## JSON Schema Analysis

### Top-level structure
```json
{
  "meta": { "version", "package", "timestamp" },
  "summary": { "filesAnalyzed", "filesSkipped", "duration", "violationCount",
                "errorCount", "warningCount", "techDebtMinutes", "debtPer1kLoc" },
  "health": { "<dimension>": { "score", "label", "threshold", "decomposition" } },
  "worstNamespaces": [ { "symbolPath", "healthOverall", "label", "reason",
                          "violationCount", "classCount", "healthScores" } ],
  "worstClasses": [ { "symbolPath", "healthOverall", "label", "reason",
                       "violationCount", "file", "metrics", "healthScores" } ],
  "violations": [ { "file", "line", "symbol", "namespace", "rule", "code",
                     "severity", "message", "humanMessage", "metricValue",
                     "threshold", "techDebtMinutes" } ],
  "violationsMeta": { "total", "limit", "truncated" }
}
```

### Strengths
- **Violation schema is complete and consistent.** All 12 fields are always present (no nulls in 100-violation sample). `metricValue` and `threshold` are always populated, making programmatic threshold-distance calculations straightforward.
- **`rule` vs `code` distinction is meaningful.** `rule=complexity.cognitive` / `code=complexity.cognitive.method` vs `complexity.cognitive.class` allows grouping by rule while distinguishing sub-violation types.
- **`techDebtMinutes` per violation** enables effort-based prioritization without external lookups.
- **`health.decomposition`** provides the specific metrics driving a health score (e.g., `ccn.avg=16.9, good: below 4`), giving an AI enough context to explain the finding.
- **`violationsMeta.truncated`** is an honest signal that the list is incomplete — an AI can detect this and request more.

### Weaknesses
- **No schema version** in `meta`. No `$schema` URL or documentation pointer. An AI encountering this format for the first time has no canonical reference.
- **`worstClasses[n].healthScores` is missing the `overall` key** that appears in the top-level `health` object. The top-level has 6 dimensions; `healthScores` in offenders has only 5. Inconsistent.
- **`worstNamespaces`/`worstClasses` use `symbolPath` as the key** but violations use `namespace` and `symbol` (FQN string). An AI must know that `symbolPath` in worst-offenders equals the `namespace` field in violations — this mapping is implicit, not documented.
- **No `class` field** in violations. Extracting the class from `symbol` requires string splitting on `::`, which works for methods but not for class-level violations where `symbol` IS the FQCN.
- **Violations are sorted by severity+metricValue, not by file or symbol.** The same class may appear non-contiguously across the 50-violation list, requiring an AI to group by symbol manually.
- **`design.*` rules appear in violations** (e.g., `design.lcom`, `design.type-coverage`) but there is no `design` dimension in the top-level `health` object. The health dimension is called `typing`. An AI reading violation rules cannot map them to health dimensions without prior knowledge.

---

## Findings

### HIGH: `worstClasses` is silently empty when all classes score above 50% health

**Observed:** Doctrine ORM has 1143 violations, 453 classes, and measurably problematic classes (ClassMetadata: health=55.4%, CBO=96, 30 violations). Yet `worstClasses` is `[]` in the JSON output.

**Root cause:** `SummaryEnricher::buildWorstOffenders()` only includes classes with `health.overall <= 50%`. Doctrine's worst class scores 53.8% — just above the threshold. The threshold is hardcoded.

**Impact on AI agent:** Without `worstClasses`, an AI cannot identify which classes to prioritize without issuing a namespace drill-down first. Step 3 of a refactoring plan ("Priority Classes: fix these first") produces `[NO DATA]`. This is the most impactful gap for programmatic consumption.

**Suggested fix:** Either lower the threshold to 60% or include top-N classes by violation count regardless of health score as a fallback when no class falls below the threshold.

---

### HIGH: `--class` drill-down returns full-project health instead of class health

**Observed:**
```bash
bin/aimd check doctrine/orm/src --class='Doctrine\ORM\Mapping\ClassMetadata' --format=json
```
Returns:
- `health.overall: 66.6%` (entire Doctrine project)
- `violations: 30` (correctly filtered to the class)

But from `--format=metrics-json`, `Doctrine\ORM\Mapping\ClassMetadata` has:
- `health.overall: 55.4%`
- `health.coupling: 14.2%` (CBO=96)

**Impact:** An AI agent doing a class-level drill-down receives health scores that describe the project, not the class. This is actively misleading — the agent would conclude the class's complexity is 46.5% (project-wide Weak) when the class itself may have different scores. The violation list is correct; the health context is wrong.

**Suggested fix:** When `--class=X` is specified, scope the health scores to that class's metrics from the repository (already available in `--format=metrics-json`).

---

### HIGH: Default 50-violation cap exposes 4% of violations with no per-rule breakdown

**Observed:** Doctrine has 1143 total violations. Default JSON returns 50. `violationsMeta.total=1143, limit=50, truncated=true`. The 50 violations are exclusively `complexity.npath` (34), `complexity.wmc` (7), `complexity.cognitive` (6), `complexity.cyclomatic` (2), `coupling.cbo` (1).

**Impact:** An AI agent has no visibility into coupling violations, code smell violations, or design violations. There is no per-rule count in `violationsMeta` — only a single total. An AI cannot know whether there are 50 or 500 `code-smell.boolean-argument` violations without requesting `violations=all`.

The option to get all violations (`--format-opt=violations=all`) exists but is **not documented in `--help` output**. An AI agent consuming the CLI documentation would not discover it.

**Suggested fix options:**
1. Add a `violationsByRule` summary map to `violationsMeta` (e.g., `{"complexity.npath": 340, "coupling.cbo": 87, ...}`). This adds ~500 bytes and gives complete rule-level visibility without requiring all violations.
2. Document `--format-opt=violations=N` in `--help` output.

---

### MEDIUM: Namespace drill-down health scores use hierarchical aggregation; metrics-json uses flat namespace scopes — results diverge significantly

**Observed:**
- `--namespace=Doctrine\ORM\Query` drill-down health: `overall=78.8%`, `complexity=83.2%`
- `--format=metrics-json` namespace `Doctrine\ORM\Query`: `overall=44.7%`, `complexity=20.8%`

**Root cause:** The drill-down includes sub-namespaces (`Query\AST`, `Query\Exec`, etc. with higher health scores), averaging them in. The metrics-json `Doctrine\ORM\Query` entry covers only the 16 classes directly in that namespace, not sub-namespaces. The sub-namespace `Query\AST` alone has 62 classes at 83% health, which overwhelms the 16 problematic `Query` classes.

**Impact:** An AI using `--format=json` drill-down to investigate the worst namespace will see a "healthier" picture than a metrics-json analysis of the same namespace. The `worstNamespaces` entry (from top-level JSON) correctly identifies `Doctrine\ORM\Query` at 44.7%, but the drill-down contradicts it at 78.8%. An AI building a multi-step analysis plan cannot trust that these two numbers refer to the same thing.

**Suggested fix:** Document the distinction explicitly in the JSON output (`"scope": "namespace-only"` vs `"scope": "namespace-recursive"`) so an AI can interpret the numbers correctly.

---

### MEDIUM: `health.decomposition` is populated inconsistently

**Observed:**
- PHP-Parser `health.complexity.decomposition`: `[]` (empty)
- Doctrine `health.complexity.decomposition`: `[{ccn.avg: 16.9, good: "below 4"}, {cognitive.avg: 15.3, good: "below 5"}]`

Both projects have complexity violations. PHP-Parser has 53.9% complexity health (Acceptable), Doctrine has 46.5% (Weak). The decomposition only appears when a dimension is in the warning/error range.

**Impact:** An AI agent writing a refactoring explanation cannot provide a specific metric rationale for "Acceptable" health scores even when they are borderline. The decomposition data is most useful for healthy-but-borderline dimensions — that's exactly when it's absent.

**Suggested fix:** Always include decomposition for the 2-3 most significant contributing metrics, regardless of whether the dimension score is above or below threshold.

---

### MEDIUM: `humanMessage` is identical to `message` — the field adds no value

**Observed across all 100 sampled violations:** `humanMessage` and `message` contain exactly the same string. Example:
```
message:      "NPath complexity: > 10^9 (max 1000) — explosive number of execution paths"
humanMessage: "NPath complexity: > 10^9 (max 1000) — explosive number of execution paths"
```

**Impact:** An AI parsing violations wastes tokens reading the same string twice (every violation has both fields). More importantly, if the intent was for `humanMessage` to provide richer contextual guidance ("This method has X execution paths, 9000x above threshold; consider extracting the switch statement into a strategy pattern"), that intent is not realized.

**Suggested fix:** Either remove `humanMessage` as a JSON field (it's identical to `message`) or fulfill the intent: provide actionable fix guidance beyond the metric reading.

---

### LOW: `worstClasses[n].healthScores` omits `overall` key present in top-level `health`

**Observed:** Top-level `health` has keys: `complexity, cohesion, coupling, typing, maintainability, overall`. `worstClasses[n].healthScores` has: `complexity, cohesion, coupling, typing, maintainability` — missing `overall`.

**Impact:** An AI iterating over the same keys across both structures hits a KeyError or must special-case the offenders list. The `healthOverall` field separately contains the overall score, so the data is present — just not in `healthScores` where an AI would look for it.

---

### LOW: `--format=metrics-json` is unusable from the CLI pipe (65536-byte console buffer truncation)

**Observed:** `bin/aimd check ... --format=metrics-json 2>&1 | python3 ...` produces a truncated, invalid JSON at exactly 65536 bytes. The output must be redirected to a file (`> /tmp/out.json`) to get valid JSON.

**Impact:** AI agents that process output via pipes (a common pattern for CLI tool integration) will receive broken JSON and fail silently. The error is not a warning from AIMD — the truncation happens at the OS console buffer level. An integration guide should explicitly warn against piping `metrics-json` output.

---

### LOW: `--format=metrics-json` has no violation data — requires a second `--format=json` call

**Observed:** `metrics-json` symbols have keys `[type, name, file, line, metrics]`. No `violations` field. To get both raw metrics and violations for a programmatic analysis, an AI agent must run AIMD twice.

**Impact:** 2x analysis time for the AI-agent use case. The two outputs use different namespace identifiers (`name: "PhpParser\Parser"` in metrics-json vs `symbolPath: "PhpParser\Parser"` in JSON worst-offenders) that happen to be the same string — but this is not documented.

---

## Refactoring Plan Demo

The following was constructed programmatically from `--format=json` output for Doctrine ORM:

```
=== REFACTORING PLAN: Doctrine ORM ===

1. Project Health:
   [ACTION NEEDED] complexity: 46.5% (Weak)
         Cyclomatic (avg): 16.9 (good: below 4)
         Cognitive (avg): 15.3 (good: below 5)
   [OK] cohesion: 86.8% (Strong)
   [ACTION NEEDED] coupling: 39.2% (Weak)
         CBO (avg): 8.1 (good: below 7)
   [OK] typing: 99.6% (Strong)
   [OK] maintainability: 74.4% (Acceptable)
   [OK] overall: 66.6% (Acceptable)

2. Priority Namespaces:
   1. Doctrine\ORM\Query (health: 44.7%, violations: 196)
      reason: high coupling, high complexity
      worst dimension: coupling (13.5%)

3. Priority Classes (fix these first):
   [NO DATA] worstClasses is empty — cannot prioritize without drill-down
   Need: drill-down by namespace to get class data

4. Most Common Issues (from top-50 of 1143 total violations):
   complexity.npath: 34 in sample
      Example: NPath complexity: > 10^9 (max 1000) — explosive number of execution paths
   complexity.wmc: 7 in sample
      Example: WMC: 481 (max 80) — total method complexity is high
   complexity.cognitive: 6 in sample
   [WARNING: 1093 violations not visible — no per-rule totals available]

5. Total Estimated Effort:
   31,505 minutes = 525 hours = 65 working days
   Density: 566.6 min/kLOC
```

**Assessment:** The plan identifies the correct problem area (complexity/coupling), pinpoints the worst namespace, and provides an effort estimate. However, it cannot produce a class-level action list from the top-level JSON alone — requiring at least one additional namespace drill-down call. The 4% violation visibility means the AI cannot rank rule types by total occurrence.

---

## What Works Well

- **Compact default JSON** (37-39KB, ~9500 tokens) fits in a 32K context window. A project overview pass is feasible in a single LLM call.
- **`violationsMeta.truncated: true`** is a reliable signal for AI agents to detect incomplete data and request more.
- **Health `threshold` values** are included in the response, allowing an AI to compute distance-to-threshold without hardcoding thresholds.
- **`techDebtMinutes` per violation** enables effort-weighted prioritization.
- **`worstNamespaces[n].reason`** (e.g., `"high coupling, high complexity"`) is immediately usable in a narrative refactoring plan without further processing.
- **`rule` and `code` both present** allows grouping at the rule level while preserving sub-type granularity (e.g., method vs. class level cognitive complexity).
- **Violations always have `file` + `line` + `symbol`** — an AI can generate a precise `TODO` comment or file-line reference without any lookup.
- **`--format-opt=violations=all`** works correctly and produces valid JSON when redirected to a file, giving complete violation data for offline analysis.
