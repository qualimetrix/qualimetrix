# Followup for FOLLOWUPS.md — package D of X10-freeze-and-carry

## X10 (2026-09-06) — D1 landed; D2 closes the AUDIT record as not reproduced

### D1 — contract unit added, mutation-proven

`03-namespace-sum.md` required a unit on `ClassCountRule` where the base
count and `MetricName::agg(SIZE_CLASS_COUNT, AggregationStrategy::Sum)`
deliberately diverge, asserting the finding follows `.sum` in both
directions. Added to the existing scaffold,
`tests/Analysis/Evidence/Size/Unit/ClassCountRuleTest.php`:

- `itFollowsSumWhenSumCrossesThresholdButBaseDoesNot` — base `3`
  (below warning 15), `.sum` `18` (above warning) → one finding,
  `metricValue: 18`.
- `itStaysSilentWhenBaseCrossesThresholdButSumDoesNot` — base `30`
  (above error 25), `.sum` `5` (below warning) → no finding.

Mutation proof (not just a green run): changed
`ClassCountRule::analyze()` line 99 from
`$metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum))`
to `$metrics->get(MetricName::SIZE_CLASS_COUNT)` (reads the base key).
Re-running the file redenned both new tests plus six pre-existing ones (8
failures total) — the two new tests are not vacuously true. Reverted the
mutation; the suite is green again (19/19).

### D2 — the attribution defect the AUDIT record names does not exist for any current metric

`AUDIT.md`'s "A file with several `namespace` blocks inflates the namespace
`.sum`" entry and Х9's E5 (`X9-gate-holes/followups/e5.md`) both name the
defect by reading code, not by measurement: E5 says explicitly "not
exploited here." X10's plan review and the orchestrator each independently
measured a two-`namespace`-block file against a two-separate-files control
and found **zero discrepancies** across the full metric set of both
namespaces (`Probe\Alpha` 3/3, `Probe\Beta` 1/1). This package's job was to
turn that from "we tried it and it didn't reproduce" into "we enumerated
every definition the predicted path could touch, and none is exposed."

**Enumeration:** `enumeration-file-level-namespace-metrics.tsv` (same
directory). Method, in the file's own header: five independent passes —
(1) grep every `new MetricDefinition(...)` call site across `src/Analysis`
for a literal `collectedAt: SymbolLevel::File`, since only a File-collected
definition can reach `NamespaceMetricContributions::collectFromFiles()`,
the function the AUDIT entry names; (2) grep every
`NamespaceMetricProviderInterface` implementor and read its
`getNamespacesWithMetrics()` body for the exact metric names it emits with
its own per-block attribution; (3) cross-check every
`GlobalContextCollectorInterface`/`DerivedCollectorInterface` implementor
against pass 1's table to confirm none of them registers a File-collected
definition; (4) grep `ComputedMetrics` for `collectedAt` to confirm
computed/formula metrics never enter this path at all; (5) grep every
`NamespaceMetricContributions::collectValues(` call site to confirm all
three (`ClassToNamespaceAggregator`, `NamespaceToProjectAggregator`,
`TreeAwareNamespaceAggregator`) pass the unfiltered definition list, so
nothing bypasses pass 1's inventory.

**Result:** exactly ten File-collected `MetricDefinition`s exist in the
whole codebase — seven in `ClassCountCollector`
(`size.class-count`, `size.abstract-class-count`, `size.interface-count`,
`size.trait-count`, `size.enum-count`, `size.implementing-enum-count`,
`size.function-count`) and three in `LocCollector` (`size.loc`,
`size.lloc`, `size.cloc`). All ten have an exact-name match in that same
collector's `getNamespacesWithMetrics()` — i.e. all ten already have an own
per-namespace-block source (`$namespaceProvided`), so
`collectFromFiles()`'s whole-file attribution branch, the path the AUDIT
entry describes, is never reached for any of them. There is no metric
definition left exposed to the predicted defect.

**Mechanism, restated for this closure (same one E5 named):**
`collectExplicitNamespaceValues()` marks a definition `namespaceProvided`
from the `SymbolType::Namespace_` `SymbolInfo` entries that
`FileProcessor::extractNamespaceMetrics()` builds from
`NamespaceMetricProviderInterface::getNamespacesWithMetrics()` — populated
per `namespace {}` block during AST traversal, not from the whole-file
total. `collectFromFiles()` skips exactly the definitions already in that
set. `mapNamespacesToFileSymbols()`'s whole-file attribution — the step
that would double-count a multi-block file — only matters for a definition
absent from `$namespaceProvided`, and the enumeration shows there is
currently no such definition reaching namespace level from a File
collector.

**Disposition:** the AUDIT.md entry "A file with several `namespace`
blocks inflates the namespace `.sum`" is closed as **not reproduced** for
the current metric set, on the enumeration above plus the two independent
empirical measurements (plan review's four input shapes including a 5/1
asymmetry and a `size.cloc` block breakdown; the orchestrator's
`Probe\Alpha`/`Probe\Beta` run). No source under
`src/Analysis/Evidence/Measurement/Aggregation/` was changed. No
regression fixture was added — per `03-namespace-sum.md`, a fixture built
on an unreproduced defect would redden for the wrong reason the day
someone "fixes" code that isn't broken. If a future collector implements a
File-collected metric without also implementing
`NamespaceMetricProviderInterface` for it, this table goes stale and the
defect becomes live again for that metric — the header says as much and
this is not re-derived automatically.

### Files touched

- `tests/Analysis/Evidence/Size/Unit/ClassCountRuleTest.php` — D1 unit
  (two new test methods).
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/enumeration-file-level-namespace-metrics.tsv`
  — new, the enumeration.
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/d.md`
  — this file.

`src/Analysis/Evidence/Measurement/Aggregation/**` was not touched: D2
found no reproduced case, so the plan's condition for editing that
directory was not met.

### Status vs Definition of Done (per `03-namespace-sum.md`)

- Enumeration table on disk with a "how obtained / what it doesn't see"
  header — done.
- D1 unit red under a rule mutation reading base instead of `.sum`,
  proven by mutation, not just a green run — done.
- AUDIT record closed with measurement (numbers, commands) and mechanism —
  done, no reproduced case found, so no fix/fixture.
- Three stability witnesses (gate GREEN, selfcheck green, benchmark:check
  unmoved) — **not applicable**: the DoD conditions them on code having
  been changed under `src/`, and none was.
