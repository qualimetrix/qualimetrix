# 0001. Computed Metrics (Health Scores)

**Date:** 2026-03-14
**Status:** Accepted

## Context

Qualimetrix collects 30+ raw metrics but provides no aggregate "how healthy is this code?" answer. When baselines suppress all violations, the violation-based view becomes empty — yet metrics still reveal code quality. No PHP competitor offers user-definable composite health metrics.

## Decision

**Formula-based computed metrics** using Symfony Expression Language:

1. **Per-level formulas** — separate formulas for class, namespace, and project levels (metrics available differ by level). Formulas reference aggregated metrics via Expression Language syntax.

2. **6 default health scores** (0–100, higher is better): `health.complexity`, `health.cohesion`, `health.coupling`, `health.typing`, `health.maintainability`, `health.overall`. Ship as `ComputedMetricDefaults` — work out of the box with no configuration.

3. **User-definable computed metrics** — `computed.*` prefix in YAML config. Any computed metric can reference other computed metrics. Circular dependencies detected via topological sort at config load time.

4. **Calibration approach** — formulas calibrated against 21 open-source PHP projects (1660 namespaces). Harmonic decay for complexity (punishes worst methods), balanced TCC/LCOM weights for cohesion, distance+CBO model for coupling. `health.overall` uses weighted arithmetic mean of dimensions.

5. **Implementation as standalone evaluator** — `ComputedMetricEvaluator` runs after aggregation as a separate pipeline step (not part of the collector framework), receives full `MetricRepositoryInterface`. Called directly from `AnalysisPipeline`.

**Alternatives considered:**
- SonarQube-style hardcoded A-E ratings — rejected (not configurable, no formula transparency)
- NDepend CQLinq — rejected (separate query language, too complex for CLI tool)
- Simple weighted averages — rejected (don't handle zero values well; harmonic mean is more appropriate)

## Consequences

- Health scores are always available (defaults are always active) — all formatters can rely on them
- Formula calibration is an ongoing process as more benchmark projects are added
- Expression Language adds ~50KB to dependencies but provides safe sandboxed evaluation
- Per-level formulas add complexity but are necessary (class has `tcc`, namespace has `tcc.avg`)
- Topological sort prevents circular references but adds startup cost (negligible for <100 metrics)
