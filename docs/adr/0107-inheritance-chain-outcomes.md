# 0107. Inheritance Chain Outcomes

**Date:** 2026-10-09
**Status:** Accepted

## Context

A chain ending at an unread ancestor and a chain entering a loop used to leave
an integer that looked like a complete inheritance depth. Registered builtin
parents stopped the walk before their own ancestors were counted. Separately,
Size classified exceptions by the immediate parent's short name, so a project
class named `Exception` could be mistaken for PHP's exception, while a real
exception grandchild could be judged as a Data Class.

A local DIT seed and a later graph correction also made the measuring population
implicit in a metric key. Removing a cyclic answer requires withholding a
numeric metric, which is impossible if an earlier pass has already written it
and the repository deliberately offers no metric-removal operation.

## Decision

**Design resolves one complete ancestry answer per exact class declaration.**
The answer carries nullable depth, an exact/floor/loop outcome, and nullable
knowledge of whether the chain reaches PHP's `Throwable`. Only completed
answers are memoized; active canonical names detect cycles separately. An
integer sentinel cannot stand for an unfinished answer or participate in depth
arithmetic.

A depth counts parent links to a root. A finite unread chain still supplies a
lower bound, so floors retain their known number. A cycle has no finite depth:
a loop and any declaration whose parent alternatives reach it publish no
numeric DIT. Every named class declaration publishes `design.dit-unresolved`,
0 for exact and 1 for floor or loop. The global collector alone owns DIT and
these ancestry flags, starting from positive graph declaration facts, including
roots and abstract classes. No local seed or repository mutation API is needed.

**A child is exact; its parent is still a name.** The identity decision in
[ADR 0073](0073-a-depth-belongs-to-a-declaration-a-child-count-to-a-name.md)
stands. All declarations of the parent name participate, including degree-zero
roots. Finite depths merge by maximum; any floor yields a floor and any loop
withholds the number. Exception knowledge is true only when all alternatives
prove true, false only when all prove false, and unknown otherwise. Depth
completeness and classifier knowledge are independent: an exact maximum does
not settle contradictory exception evidence.

**Builtin ancestry is evidence, not runtime reflection.** Registered PHP names
are followed transitively through the static hierarchy before asking whether
Composer is configured. Other ancestry is read through Design's existing
external-source port, never autoloaded. Unplaced or unread source, no source map
and the existing 64-step cap yield floors; canonical external cycles yield
loops. Unregistered extension classes remain unknown unless readable source
resolves them, so the analysing machine's loaded extensions cannot decide a
metric. Already-proven `Throwable` evidence survives an unread later tail.

**A self-parent is a declaration fact.** DependencyModel preserves named class
self-`extends`, including case-folded identity, in its existing declaration
view with the original exact source identity and location. Its ordinary graph
views remain free of canonical self edges. Ordinary self references, interface
self-`extends` and nested-anonymous policy are not widened. This records a loop
without changing coupling, ClassRank, graph export or circular-dependency
semantics.

**An unknown exception status is not a non-exception.** Design publishes
`design.is-exception` as 1 or 0 only when known; unknown status omits the key.
Interfaces, traits and enums receive 0. Size retains method, accessor, property
and WOC measurement without exception classification. DataClass with
`excludeExceptions=true` cannot judge unknown status; with `false`, the
remaining criteria decide independently of ancestry.

**The enabled inheritance rule owns its caveat.** It warns once per execution,
distinguishing floors, loops, or both; disabling the rule silences that warning
while metric collection continues. Numeric floor findings and recommendations
say that DIT is at least the published value. This replaces the diagnostic and
published-state decision in
[ADR 0076](0076-a-floor-is-reported-and-said-to-be-one.md).

## Consequences

Builtin descendants can report greater depths. Cyclic declarations lose their
invented numeric depths; floor consumers can now distinguish incompleteness
from an exact measurement. Numeric DIT aggregates include published exact and
floor depths, including zero roots, and omit loops. NOC retains its distinct
logical-child policy and its class roster, so its numeric sample can differ
from DIT's when loops occur.

DataClass can withdraw a finding when resolved ancestry proves an exception or
cannot settle exception exclusion. Unknown status can also occur with an exact
DIT when duplicate parent alternatives disagree. Consumers must not default a
missing exception key or a missing DIT to 0.

The trade-off is explicit uncertainty rather than a plausible invented answer.
The static builtin hierarchy needs maintenance, and a capped or unread external
chain cannot prove a complete depth. No new public graph query, source-loading
mechanism, or metric-removal contract is introduced. Thresholds and rule
enablement defaults remain unchanged. Consumer migration is recorded in the
[Changelog](../../CHANGELOG.md).
