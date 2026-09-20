# 0069. A Metric Is Declared by the Collector That Writes It

**Date:** 2026-09-20
**Status:** Accepted

## Context

Aggregation runs twice. `MeasurementAggregationService::aggregate()` first rolls
up every collector's definitions, then lets the global collectors overwrite
class-level values from the dependency graph, then re-aggregates — but only over
the definitions the *global* collectors declare.

`DitGlobalCollector` declared none. A comment said so in as many words: "DIT
definitions are already declared by InheritanceDepthCollector." They were, and
that was the defect. The per-file collector cannot see a parent declared in
another file, so it scores such a child as a root's child; the global collector
exists precisely to repair that from the graph. Because the repair was published
under a declaration owned by the collector it corrected, the second pass skipped
the metric and the rollups kept summarising the values that had just been
replaced.

Measured on a three-file chain (`Base`, `Middle extends Base`,
`Leaf extends Middle`): class-level DIT 0, 1, 2 — correct — while
`design.dit.max` reported 1 and `design.dit.avg` 0.667. The published per-class
numbers and the published aggregate of those same numbers disagreed, and nothing
in the suite could tell: class-level values are right, both collectors' unit
tests pass, and a fixture whose classes share one file never reaches the repair
at all.

A second disagreement sat underneath. `InheritanceDepthVisitor` records named
class declarations only, while the global pass walked every class-level symbol.
Interfaces, traits and enums therefore received a published class-level
`design.dit` of 0 and were nonetheless absent from the aggregate's population.
On `symfony/http-kernel`, `design.dit.count` was 154 — equal to
`size.class-count.sum` — while 177 class-level symbols existed.

The two are independent, and the obvious repair of the first silently performs
the second: moving the declaration alone re-denominates every DIT average onto
class-like symbols (154 → 177 on http-kernel, 805 → 959 on this repository),
pushing the project average *down* while purporting to fix depth.

## Decision

**The collector that writes a metric's published value declares that metric,
and it declares it alone.**

`DitGlobalCollector` now carries DIT's `MetricDefinition`;
`InheritanceDepthCollector` carries none, inheriting the empty default.

**A metric's population is the set the measuring pass identified, not every
symbol the repairing pass can reach.** `DitGlobalCollector` corrects the depth
of symbols that already carry a per-file `design.dit` and leaves the others
untouched. The per-file pass answers *which things are classes*; the global pass
answers *how deep each one is*. This is the `MetricSubject`/`SymbolPath` seam of
Critical Rule 4 showing up in aggregation: the two passes stood on opposite
sides of it.

The invariant is checked rather than remembered, by
`governance/MeasurementIdentity/GlobalCollectorDeclaresWhatItWritesTest`:
every global collector declares each metric its `provides()` names, and no
metric is declared by two collectors. Both halves are read by calling the
methods — neither returns literals in every collector, and a sweep over source
text silently reports nothing for the ones that compute.

The control does not check that a declaration is *adequate*: a definition with
the right name but the wrong `collectedAt`, or missing a level's strategies,
passes it while leaving that level unaggregated. There is no declared spec of
required levels to check against, so the control names the gap instead of
implying coverage.

## Consequences

Aggregate DIT rises wherever inheritance crosses files, which is the ordinary
case. Per-class DIT is unchanged except that interfaces, traits and enums no
longer receive one. `design.dit.count` now equals `size.class-count.sum` by
construction.

Nothing downstream re-calibrates: `design.dit` appears in no health formula
(`ComputedMetricDefaults` has no DIT term), in no benchmark range, and in no
entry of `qmx-baseline.json`. The `design.dit` rule judges class-level values,
so findings do not move; only the `metrics` and `html` formats carry the
aggregate.

Declaring a metric in two collectors is now refused rather than merely
discouraged. That is not pedantry: the first aggregation pass iterates
definitions rather than metric names, so a duplicated declaration computes every
strategy over a doubled population.

The rationale is correctness, not cost. External resolution was measured at
0.0002s over 45 calls on `symfony/http-kernel`; no performance claim supports
this change and none is made.

This ADR governs *which* collector declares a metric. It says nothing about how
`DitGlobalCollector` resolves a parent outside the analysed path — that
resolution still loads the analysed project's classes through this tool's own
autoloader, and its replacement is separate work.
