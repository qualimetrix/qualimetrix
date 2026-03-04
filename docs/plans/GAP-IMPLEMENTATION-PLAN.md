# Gap Implementation Plan — Summary Analysis

> **Date:** 2025-12-11
> **Source:** [FEATURE_GAP_ANALYSIS.md](../FEATURE_GAP_ANALYSIS.md)

---

## Summary

Missing features from the GAP analysis have been reviewed. They are classified by implementation complexity and value.

---

## Infrastructure Analysis

### Current Architecture Supports

| Capability | Status | Comment |
|------------|--------|---------|
| Pattern detection in AST | ✅ | Via Visitor + Collector |
| File-level metrics | ✅ | MetricBag with aggregation |
| Method/Class-level metrics | ✅ | SymbolPath routing |
| Namespace-level aggregation | ✅ | MetricAggregator |
| Rules with thresholds | ✅ | HierarchicalRuleInterface |
| JSON in metrics | ✅ | MetricBag accepts string |
| Rule configuration | ✅ | RuleOptionsInterface |

### Requires Enhancement For

| Capability | Complexity | What is needed |
|------------|-----------|----------------|
| Unused code detection | High | Data-flow analysis, scope tracking |
| Cross-file analysis | High | New pipeline phase |
| Inheritance tree | Medium | DependencyGraph extension |

---

## Feature Classification

### Group A: Quick Wins (to be implemented)

**Complexity: Low | Value: High**

| Feature | RFC | Estimated effort |
|---------|-----|-----------------|
| Code smell rules (goto, eval, etc.) | [RFC-009](RFC-009-CODE-SMELL-RULES.md) | 4-6 hours |
| Package metrics (functionCount) | [RFC-010](RFC-010-PACKAGE-METRICS.md) | 1-2 hours |

### Group B: Medium Complexity (deferred)

**Complexity: Medium | Value: Medium**

| Feature | Blocker |
|---------|---------|
| BooleanArgumentFlag | Many false positives |
| StaticAccess | Questionable value, many exceptions |

### Group C: High Complexity (will not implement)

**Complexity: High | Value: High, but PHPStan does it better**

| Feature | Reason for rejection |
|---------|---------------------|
| UnusedPrivateField | PHPStan does this with data-flow analysis |
| UnusedPrivateMethod | Same |
| UnusedFormalParameter | Same |
| UnusedLocalVariable | Same |

### Group D: Out of Scope (will not implement)

| Feature | Reason |
|---------|--------|
| Naming conventions | PHP-CS-Fixer, Rector |
| CamelCase rules | Code style, not quality |
| HTML reports | Not core functionality |
| Dependency graph visualization | DOT export exists, sufficient |

---

## Implementation Plan

### Phase 1: RFC-010 — Package Metrics (1-2 hours)

1. Extend `ClassCountVisitor` — add `functionCount`
2. Extend `ClassCountCollector` — add metric
3. Tests
4. Update README

**Dependencies:** None

### Phase 2: RFC-009 — Code Smell Rules (4-6 hours)

**Step 2.1: Collector (2 hours)**
1. `CodeSmellVisitor` — all patterns
2. `CodeSmellCollector` — metric collection
3. Unit tests

**Step 2.2: High-priority rules (2 hours)**
1. `GotoRule`, `EvalRule`, `EmptyCatchRule`, `DebugCodeRule`
2. Unit tests
3. Integration tests

**Step 2.3: Medium-priority rules (2 hours)**
1. `ExitRule`, `ErrorSuppressionRule`, `CountInLoopRule`, `SuperglobalsRule`
2. Tests

**Dependencies:** None

---

## Acceptance Criteria

### RFC-010
- [ ] `functionCount` metric works
- [ ] Aggregation by namespace/project
- [ ] `composer check` passes

### RFC-009
- [ ] 8 rules implemented
- [ ] Configuration via aimd.yaml
- [ ] Baseline/suppression work
- [ ] `composer check` passes
- [ ] Documentation updated

---

## Recommendation

Start with **RFC-010** (minimal changes, quick result), then **RFC-009** (more value for users).

---

## Answer to the Question: Is the Infrastructure Sufficient?

**Yes, for Quick Wins — fully sufficient:**

1. Visitor + Collector pattern works
2. MetricBag supports string (for JSON locations)
3. Rule options system is ready
4. Automatic registration via DI

**Insufficient only for Unused Code Detection:**

Data-flow analysis is required, which goes beyond the current architecture. PHPStan solves this task better — integration is recommended rather than duplication.

---

## Change History

| Date | Change |
|------|--------|
| 2025-12-11 | Summary plan created |
