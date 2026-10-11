# 0114. Effective methods and cohesion populations

**Date:** 2026-10-11
**Status:** Accepted

## Context

LCOM4 and TCC/LCC were calculated from each file's class body. Trait-imported methods and adaptations were invisible, calls were matched by source spelling, and `self::`/`static::` instance calls did not form LCOM edges. Public methods that reached state through helpers did not share that state for TCC. An LCOM graph without eligible vertices could publish zero, while `cohesion.lcom` rule eligibility used the unrelated `size.method-count` population. These are distinct semantic errors, not coefficient choices.

[Hitz and Montazeri](https://www.researchgate.net/publication/238729882_Measuring_coupling_and_cohesion_in_object-oriented_systems) define graph connections through shared instance variables or calls between methods. Call edges are part of that definition. PHP call resolution and trait composition interpret those edges; constructor exclusion, stateless merging and absent empty graphs adapt the measured population. [Bieman and Kang](https://www.cs.colostate.edu/~bieman/Pubs/bieman-kang-ssr95.pdf), sections 2–3, include state accessed through directly or transitively called methods on the same object and exclude constructors and destructors. Following helpers is therefore part of TCC/LCC, while the public-only population and stateless abstention remain named adaptations.


## Decision

Cohesion's file collectors publish declaration facts; the Cohesion-owned global collector joins exact class declarations with effective trait methods. Core's neutral trait-composition primitive provides effective names, original bodies, aliases, visibility and `insteadof` precedence. Cohesion owns the graph, property-read and metric policy. The shared primitive costs a Core public surface and exact declared consumer grants in the ownership manifest; that price is justified by its actual Coupling RFC and Cohesion consumers. It must not acquire cohesion-specific arithmetic.

PHP method-call matching is case-insensitive. Instance calls through `$this`, `self` and `static` contribute LCOM edges where they resolve to an instance method. Only uncalled stateless constant methods merge into one virtual vertex. A called stateless method keeps its vertex because call structure is evidence. This is the selected PHP scope adaptation: an uncalled metadata obligation does not by itself indicate a separate responsibility. The website and capability README identify these adaptations explicitly; method-call edges are not an extension to Hitz–Montazeri.

The alternative of dropping uncalled stateless methods would hide a real
independent group. Keeping every such method as a separate vertex instead
measures protocol metadata as separate responsibilities.

**Historical no-merge comparison receipt.** The source was `main` at
[`9a88bd2c9912699fd38973cb5554dd9d14396e36`](https://github.com/qualimetrix/qualimetrix/commit/9a88bd2c9912699fd38973cb5554dd9d14396e36).
Both compared variants had the
called-stateless correction and rule metadata moved to constants. The alternative
changed only the virtual-node choice: every remaining non-static, non-excluded
method kept its own vertex. Static/constructor exclusions and the then-existing
all-trivial clamp remained; this was not an unadapted Hitz–Montazeri run.
Both used the source tree's configuration and formulas, with no baseline.
The JSON metrics timestamps are `2026-09-25T15:25:25+00:00` and
`2026-09-25T15:25:26+00:00`; invocation start dates were not separately recorded.
Both captures were complete: 1,031 files discovered and analyzed, none failed.

| Historical comparison                           | Stateless merging retained | Stateless merging disabled         |
| ----------------------------------------------- | -------------------------- | ---------------------------------- |
| Classes whose LCOM changed between the variants | —                          | 46: 33 increased by one, 13 by two |
| New `cohesion.lcom` findings                    | —                          | 8 errors                           |
| New `health.cohesion` findings                  | —                          | 12 warnings: 7 class, 5 namespace  |
| Existing finding severity changes               | —                          | 3 warnings promoted to errors      |
| Total findings                                  | 165                        | 185                                |
| Project `health.cohesion`                       | 84.54526625856893          | 82.84595750280856                  |
| Metrics / findings command exit codes           | 0 / 0                      | 2 / 2                              |

The exact LCOM transition counts are 2→3: 17, 2→4: 5, 3→4: 14, 3→5: 8 and
4→5: 2. The new LCOM errors were on `AbstractnessCollector`, `ClassRankCollector`,
`CouplingCollector`, `DistanceCollector`, `DitGlobalCollector`, `NocCollector`,
`TypeCoveragePercentCollector` and `MaintainabilityIndexCollector`; all reached
LCOM 5 with an error boundary of 5. No finding was removed. Counts were checked
against both raw JSON comparisons and their per-class table.
Historical command arguments were `php -d memory_limit=2G bin/qmx check src/`
with `--workers=0 --no-cache --no-progress`, once with `--format=metrics` and
once with `--format=json --all`. The outputs identify the tool as `dev-main`.
These are archived fork observations, not current-candidate verdicts, complete
repository validation, or calibration targets.

An empty eligible LCOM graph publishes no `cohesion.lcom`; `cohesion.lcom-method-count` records vertices after exclusions and merging and gates `min_methods`. TCC/LCC follow instance-method invocation trees to collect reachable property access and share one predicate with the pure-method count. `cohesion.tcc-method-count` records their tracked public population. TCC/LCC remain absent when fewer than two tracked methods or no applicable instance state exists. Unread trait uses are reported as `cohesion.unread-trait-count`, never interpreted as a known empty trait. Class cohesion health divides its purity fraction by the tracked TCC method count.

Size and Design share one accessor predicate: a matching `get`/`is`/`has` or
`set` prefix and a simple instance-property read or write body, with the
existing parameter and optional fluent-return constraints. Prefix-named
computed or forwarding methods remain behavior. This refines the classification
in [ADR 0027](0027-weight-of-class-measures-accessors-not-visibility.md) without
changing WOC's public-member denominator. Size counts authored class methods;
LCOM's minimum population instead counts effective graph methods after
exclusions and virtual-node merging. The two populations serve different
questions and must not substitute for each other.

All seven composed outputs declare `MetricReach::Run` through the
Measurement-owned producer contract in [ADR 0113](0113-producer-declared-metric-reach.md).
Exact declaration subjects do not imply declaration-local dependencies: an
unselected trait body can change the class graph. Raw file-local facts retain
Members reach. The uniform producer declaration is deliberately conservative:
a narrow run cannot compare these outputs with a whole-run baseline, even if
one selected class happens to have complete trait evidence. This replaces the
local-only cohesion choice in ADR 0113 when trait composition is enabled; the
producer-owned contract and baseline comparison policy themselves are unchanged.

## Consequences and migration

- Re-evaluate saved LCOM, TCC, LCC, class and aggregate health values. Empty LCOM is now absent; findings can change when trait methods or calls connect the graph.
- Rule `min_methods` refers to the LCOM graph population. Recheck custom limits and accepted baseline entries against that population.
- Update affected metric consumers to distinguish absent from numeric zero and to inspect `cohesion.unread-trait-count` when composition evidence is incomplete.
- Integrated evidence is measured in the complete 17-project capture and [ADR 0116](0116-measured-health-score-recalibration.md). Scoped Cohesion/DependencyModel/Coupling/Size tests passed (958 tests, 3,238 assertions); the later owning quality package, including Cohesion, passed (1,912 tests, 7,439 assertions). The baseline command suite passed (10 tests, 271 assertions), including `itLeavesRunDependentCohesionUncomparedOnOneFile`: a narrow check retains an ordinary finding as `not-compared`/`outside-coverage`, while a complete check can enforce its whole-run ceiling. These scoped receipts do not replace the pending full validation, gate, fresh review and CI.
