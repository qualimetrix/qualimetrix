# Persona #4: GPT the Consumer — AI Agent Refactoring Plan Test

**Date:** 2026-03-15
**Codebase analyzed:** Doctrine ORM (`benchmarks/vendor/doctrine/orm/src`, 453 files)
**AIMD version:** dev-main

## 1. Executive Summary

AIMD's JSON output is well-structured for machine consumption and fits comfortably within typical LLM context windows (~8K tokens for summary, ~7K for drill-down). The summary format provides enough information to identify top refactoring priorities and build a concrete plan. Two significant issues reduce effectiveness: (1) the drill-down via `--namespace` does not filter health scores or worst-class lists to the selected namespace, making the drill-down less useful than expected; (2) only 50 violations are shown (top-50 cap), but the violation messages are actionable enough that this limit works in practice. The metrics-json format (~1M tokens) is completely unusable for LLM context windows and serves a different purpose (data export).

## 2. JSON Structure Assessment

### Top-level keys
```json
["meta", "summary", "health", "worstNamespaces", "worstClasses", "violations"]
```

**Verdict: Excellent.** The structure follows a natural analysis flow: metadata -> overview -> health scores -> worst offenders -> specific violations. An LLM can parse this top-to-bottom without backtracking.

### Health scores — well-designed for AI
```json
{
  "complexity": {
    "score": 46.45,
    "label": "Needs attention",
    "threshold": { "warning": 50, "error": 25 },
    "decomposition": [
      { "metric": "ccn.avg", "humanName": "Cyclomatic (avg)", "value": 16.94, "good": "below 4", "direction": "lower_is_better" }
    ]
  }
}
```

The `label`, `good`, and `direction` fields are critical for AI understanding. Without them, an LLM would have to know that a complexity score of 46 is bad. With them, interpretation is immediate.

### Worst offenders — good but limited
```
worstNamespaces: 1 entry
worstClasses: 3 entries
violations: 50 entries (capped)
```

Only 1 worst namespace and 3 worst classes are shown. For a 453-file project with 46 namespaces, this feels sparse. An AI agent might want the top-5 or top-10 to build a multi-phase refactoring roadmap.

### Violation messages — highly actionable
```json
{
  "message": "NPath complexity: 147600 (max 1000) — explosive number of execution paths",
  "metricValue": 147600,
  "threshold": 1000,
  "symbol": "Doctrine\\ORM\\Query\\SqlWalker::walkJoinAssociationDeclaration"
}
```

The message includes: what the metric is, the current value, the threshold, and a human-readable explanation. The `metricValue` and `threshold` fields allow programmatic severity ranking. This is excellent — an AI can immediately say "this is 147x over threshold, highest priority."

### Tech debt estimation — present and useful
```json
{
  "techDebtMinutes": 31235
}
```

Present in summary (31,235 minutes = ~65 developer-days). Useful for executive-level estimates. However, per-violation debt is not shown, making it impossible to estimate effort per refactoring item.

## 3. Information Sufficiency

### What's there (good)
- Health scores with labels and decomposition — immediately interpretable
- Worst offenders with reasons ("high coupling, low cohesion") — guides strategy
- Class-level metrics in worst classes (methodCount, cbo, tcc, wmc, mi.avg) — detailed enough for diagnosis
- File paths for all violations — enables automated follow-up
- Violation severity (error/warning) — enables triage
- Rule codes (`complexity.npath.method`) — machine-parseable categorization

### What's missing (gaps)

1. **Per-violation remediation time.** The summary has `techDebtMinutes: 31235` but individual violations don't show their estimated fix time. An AI agent can't say "fixing this one method saves 2 hours."

2. **Hints/recommendations.** The `humanMessage` field mentioned in the spec doesn't appear in JSON output. An AI agent has to infer "what to do" from the violation type alone. For example, `complexity.cognitive.method` with value 338 — the AI knows it's bad but doesn't get a suggestion like "extract conditional blocks into named methods."

3. **Drill-down health scores are project-wide, not namespace-filtered.** When running with `--namespace="Doctrine\ORM\Query"`, the health scores still show the full project (overall: 73.4). The namespace's own health (45.2 from worstNamespaces) is only available in the worstNamespaces section.

4. **Drill-down shows 0 worstClasses.** The namespace filter doesn't populate the worstClasses array, so the AI loses the ability to identify which classes within the namespace need the most work. This is the biggest gap — a drill-down should show the worst classes within the filtered scope.

5. **Cross-references between violations.** Multiple violations for the same class (e.g., SqlWalker has WMC + NPath + cognitive violations) aren't grouped. An AI has to mentally join them by symbol path.

6. **No dependency information.** CBO is shown as a number (37) but not which classes are coupled. An AI can't suggest "decouple from X and Y" without reading the source.

## 4. Refactoring Plan Attempt

Based solely on the JSON summary + drill-down outputs:

### Priority 1: `Doctrine\ORM\Query\SqlWalker` — Break up the god class
- **What's wrong:** WMC=397 (5x threshold), NPath up to 147,600 on a single method, cognitive complexity dominating the namespace. The class is a massive tree-walker with many intertwined responsibilities.
- **What to do:** Extract walker methods into strategy classes per SQL clause type (SELECT, JOIN, WHERE, etc.). Each `walk*` method with NPath >1000 should become its own class with a single `walk()` method. The parent SqlWalker becomes a dispatcher.
- **Estimated effort:** 3-5 developer-days (high complexity, high risk of behavioral regression).
- **Data quality:** SUFFICIENT. The violation list clearly identifies the worst methods by name and metric value. I can prioritize `walkJoinAssociationDeclaration` (NPath=147,600) > `walkSelectClause` (NPath=39,174).

### Priority 2: `Doctrine\ORM\Query\Parser` — Decompose the parser
- **What's wrong:** WMC=481 (6x threshold), individual methods like `SimpleConditionalExpression` have NPath=21,888. The parser tries to handle all DQL grammar in one class.
- **What to do:** Extract grammar rule groups into sub-parsers (ExpressionParser, ClauseParser, FunctionParser). Use composition with a shared token stream.
- **Estimated effort:** 3-4 developer-days.
- **Data quality:** SUFFICIENT. Same reasoning as above — methods are identified with exact complexity values.

### Priority 3: `Doctrine\ORM\Mapping\ClassMetadataFactory` — Reduce coupling and improve cohesion
- **What's wrong:** CBO=37 (extremely high), TCC=0 (zero cohesion — methods share no instance variables), WMC=133. This class depends on 37 other classes and its methods are completely unrelated.
- **What to do:** Extract concerns into focused factories (AssociationMetadataFactory, FieldMetadataFactory, LifecycleCallbackFactory). Introduce interfaces for decoupling.
- **Estimated effort:** 2-3 developer-days.
- **Data quality:** SUFFICIENT. The class-level metrics (cbo=37, tcc=0, wmc=133) tell a clear story. Missing: which specific dependencies to decouple from.

### Plan Assessment
The JSON gave me enough to build a credible 3-priority refactoring plan with specific classes, methods, and strategies. The main gap was dependency details for the coupling issues — I could name the problem class but had to guess at the coupling targets. A second drill-down call to get class-level dependency lists would close this gap.

## 5. Context Window Impact

| Format          | Size   | Est. Tokens | Fits in 8K? | Fits in 32K? | Fits in 128K? |
| --------------- | ------ | ----------- | ----------- | ------------ | ------------- |
| Summary JSON    | 33 KB  | ~8,300      | Barely      | Yes          | Yes           |
| Drill-down JSON | 29 KB  | ~7,200      | Barely      | Yes          | Yes           |
| Both combined   | 62 KB  | ~15,500     | No          | Yes          | Yes           |
| Metrics JSON    | 4.1 MB | ~1,030,000  | No          | No           | No            |

The summary JSON is impressively compact for 453 files and 1,153 violations. The top-50 violation cap is the key design choice that makes this work — without it, 1,153 violations at ~250 chars each would add ~75K tokens.

For a typical "summary + one drill-down" workflow, ~15K tokens is excellent. It leaves plenty of room for the LLM to reason and generate a plan within a 32K context window.

## 6. Comparison: Summary JSON vs Metrics JSON

| Aspect          | Summary JSON (`--format=json`)     | Metrics JSON (`--format=metrics-json`) |
| --------------- | ---------------------------------- | -------------------------------------- |
| Size            | 33 KB (~8K tokens)                 | 4.1 MB (~1M tokens)                    |
| Purpose         | Decision-making                    | Data export                            |
| Health scores   | Yes, with labels                   | No                                     |
| Worst offenders | Yes (curated)                      | No (flat list)                         |
| Violations      | Top 50, actionable                 | None                                   |
| Raw metrics     | Selected metrics for worst classes | All metrics for all 3,433 symbols      |
| AI-friendliness | Excellent                          | Unusable (too large)                   |
| Use case for AI | Refactoring planning               | None practical                         |

**Verdict:** Summary JSON is clearly the right format for AI agents. Metrics JSON serves a different purpose (integration with dashboards, CI pipelines, or other tools that process raw data programmatically).

Metrics JSON could theoretically be useful if it supported the same `--namespace` filtering to produce a manageable subset, but even a single namespace would likely produce 50-100KB of raw metric data.

## 7. Issues Found

### Issue 1: Drill-down health scores are not namespace-scoped (MEDIUM)
When using `--namespace="Doctrine\ORM\Query"`, the `health` section shows project-wide scores (overall: 73.4) instead of namespace-scoped scores. The namespace's actual health (45.2) is only in `worstNamespaces`. An AI agent drilling into a problem namespace gets misleading context.

### Issue 2: Drill-down shows 0 worstClasses (HIGH)
The `--namespace` filter produces `"worstClasses": []`. This defeats the purpose of drill-down — the AI agent can't identify which classes within the namespace need the most work. It has to infer this from the violation list, which is less efficient and loses the health score context.

### Issue 3: No per-violation remediation time (LOW)
The `techDebtMinutes` field exists at the summary level but not per-violation. An AI agent can't estimate effort for individual refactoring items. This would be useful for prioritization ("fix this one method to save 4 hours of debt").

### Issue 4: No hints or recommendations in JSON (MEDIUM)
The reporting infrastructure includes `MetricHintProvider` with actionable hints, but these don't appear in the JSON output. An AI agent gets "Cognitive complexity: 338 (max 30)" but not "consider extracting nested conditions into named methods." Adding a `hint` field per violation would make the output more actionable.

### Issue 5: Limited worst offenders count (LOW)
Only 1 worst namespace and 3 worst classes are shown. For a multi-phase refactoring plan, top-5 or top-10 would be more useful. This could be a configurable parameter.

### Issue 6: No violation grouping by class (LOW)
A single class can have 10+ violations (SqlWalker has WMC + multiple NPath + cognitive). These aren't grouped, requiring the AI to join by `symbol` field. A `violationsByClass` section would reduce AI processing overhead.

## 8. Recommendations

### For AI agent consumption (high priority)

1. **Fix drill-down to scope health scores and populate worstClasses** (Issues 1 & 2). When `--namespace` is used, the JSON should show health scores for that namespace specifically, and list its worst classes. This is the single most impactful improvement for the "summary + drill-down" workflow.

2. **Add `hint` field to violations.** The MetricHintProvider infrastructure already exists. Including its output in JSON would transform violations from "what's wrong" into "what to do about it."

3. **Add `techDebtMinutes` per violation.** Even if approximate, this enables effort-based prioritization.

### For better AI ergonomics (medium priority)

4. **Increase worst offender limits.** Show top-5 namespaces and top-10 classes by default, or make it configurable via `--top=N`.

5. **Add a `classSummary` section** that groups violations by class with aggregate metrics. This is what an AI agent mentally constructs anyway — doing it in the output saves tokens and reduces error.

6. **Add a `recommendations` section** with 3-5 high-level actionable items derived from the health scores and worst offenders. This would make the "one JSON call = one refactoring plan" workflow fully automated.

### Not recommended

7. **Don't try to make metrics-json AI-friendly.** It serves a different purpose. Keep the two formats separate and optimize summary JSON for the AI use case.
