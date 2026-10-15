# 0124. Finding Publication and Rule Execution Contracts

**Date:** 2026-10-15
**Status:** Accepted

## Context

Rule execution, late finding selection, channel coverage and level activity
were exposed through one `RuleExecutionInterface`. Baseline, Run and Finding
readers that only ask what a run publishes consequently depended on the
executor's entire contract. `FindingPublication` already owned the exclusion
ledger and selection projection, while `RuleMaterialization` already owned
deferred rules, validators and their snapshot-scoped caches.

Moving publication to a new facade would duplicate the selection path or
transfer the same coupling to another class. Exposing the publication ledger's
`begin()`, population session, per-rule `published()` and `complete()` methods
to other capabilities would make execution mechanics part of the public API.

## Decision

`RuleExecutionInterface` exposes execution and producer metadata only.
`FindingPublication` implements `RulePublicationInterface`, which exposes
`publishable()`, `publication()` and `levelActivity()` to named readers.
Finding's publisher owns the one live rule configuration and shares one
`RuleMaterialization` instance with the executor. Execution asks that publisher
for its current enablement and snapshot preparation, then uses its internal
ledger and population operations; late findings use its public selection
operation without changing the completed ledger.
The immutable `RuleEnablement` in the resolved invocation remains the sole
source of channel and level decisions. Both services reset materialized
instances when the resolved options object changes identity.

The producer list remains on the execution contract because the current
executor's measured coupling does not require a third public contract. The
internal publication lifecycle remains on the concrete Finding-owned class;
it is not an external extension point.

## Consequences

Run, Baseline and Finding audits can depend on publication without depending
on execution. Composition must inject the same materialization and publisher
into the executor, and must register rules, validators and classless producers
on that materialization. Tests that build an executor directly must compose
those same objects. A future consumer that needs both operations takes both
contracts rather than widening either one.

The publisher's configuration and snapshot accessors are internal concrete
operations for the executor; they are absent from `RulePublicationInterface`.

`AnalysisPipeline` needs both contracts: it captures publication before
discovery, checks publication after execution for unmatched-type warnings, and
selects late findings after execution. This raises its explicit constructor
arity from 12 to 13. The dependencies represent separate phase duties; an
aggregate introduced solely to retain the old arity would conceal that cost
without reducing coupling.
