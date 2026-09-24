# 0080. A Project Fold Reads a Partition, Not the Leaves

**Date:** 2026-09-23
**Status:** Accepted

## Context

[ADR 0062](0062-health-scores-measure-what-they-cover.md) settled that the
project aggregate of a namespace-collected metric is the unweighted mean over
**leaf** namespaces, and kept that rule because the double counting it prevents
is real: a parent namespace publishes a value for its whole subtree, so folding
parents and children together counts the same classes twice.

What the rule also does was measured on this repository. A parent namespace can
declare types of its own, and 41 such parents hold **447 of 994 classes — 45%**.
Those classes are represented in the project figure by no value at all. The
leaf-only rule does not merely avoid double counting them; it drops them.

The obvious repair — fold every namespace that publishes a value — is the double
counting ADR 0062 named, and measurement said why. `DependencyGraphBuilder`
overwrites a parent's `Ce`/`Ca` with the prefix semantics of its whole subtree,
so a parent publishes one whole-subtree figure and nothing about its own
declarations. There was never a "hybrid" parent value to take.

The second consequence is a reporting one. [ADR 0062](0062-health-scores-measure-what-they-cover.md)
made every dimension publish what it covers, and coupling's denominator was
`NamespaceTree::getLeaves()` — the population of the aggregate itself. A
coverage line whose denominator is the aggregate's own traversal can only ever
print 100%. On the defect above it would have printed **127 of 168 (76%)**,
which is the first time that line would have been a witness rather than a
restatement.

Cross-tool reconnaissance was done and its blind spots stated: JDepend, PDepend,
PhpMetrics and NDepend all treat a package as a **partition**, and none of them
publishes a project-level number for distance at all. No data was found for
SonarQube or Structure101. No tool was executed; only sources and documentation
were read.

## Decision

**The project population of a namespace-collected metric is a partition: every
declaration belongs to exactly one node, and the value folded is that node's
own scope.** A namespace node carries two scopes — its own declarations, and the
rollup of its subtree — and the published subtree value is unchanged. The own
scope is published beside it under its own key and is what the project fold
reads.

The keys follow the values. A node now publishes `coupling.ca-own`,
`coupling.ce-own`, `coupling.instability-own`, `coupling.abstractness-own` and
`coupling.distance-own` beside the subtree-scoped originals, and the project
fold is `coupling.distance-own.avg` / `.count`, because `X.avg` must be the mean
of `X`. The project no longer publishes `coupling.distance.avg`; the
namespace-level `coupling.distance` is untouched.

**A coverage denominator is measured independently of the aggregate's
traversal.** Coupling's unit stops being `LeafNamespaces` and becomes namespaces
declaring a type, counted by `size.symbol-declaring-namespace-count` — a size
measurement, not a walk of the tree the aggregate folds. The denominator is
deliberately **not** equal to the fold's population: using the population would
restore exactly the defect this record repairs. Two namespaces in this
repository declare only a bare enum and enter the size count without entering
the fold, so the line reads `166 of 168` rather than `166 of 166`; that
permanent gap is by construction and is documented where the reader meets it.

**Weighting by class count was rejected**, and not because its argument is weak.
It was measured: 40% of namespaces hold 9% of classes and contribute 40% of the
weight, so today's project number depends on how finely directories are cut.
The rejection is that weighting is a *second* decision in the same change —
it moves the meaning of the number from "mean over packages" to "mean over
code", which is a question about what a project figure should mean, and
ADR 0062's protocol requires answering it with the corpus in hand and
recalibrating thresholds. Directory-granularity dependence stays an open point,
named rather than quietly carried.

## Consequences

- Measured on this repository: `coupling.distance-own.avg` 0.2718897 against
  the old `coupling.distance.avg` 0.2854663, `count` 127 → 166,
  `health.coupling` 51.16 → 51.28, `health.overall` 76.63 → 76.65, and **0 of
  152 ratchet entries move**. Across the fifteen-project benchmark corpus no
  project leaves its band because of this change.
- No namespace-level metric moves. The parent's subtree rollup is still built
  and still feeds `coupling.instability`, `coupling.ca` and `coupling.ce` on
  parents; the own scope is added beside it rather than replacing it, so the
  graph carries both sets of `Ce`/`Ca` at once.
- The project surface changes: `coupling.distance.avg` and
  `coupling.distance.count` are gone and `coupling.distance-own.*` takes their
  place, `size.symbol-declaring-namespace-count` is a new published key, and the
  coverage line for coupling reads a different unit. This is a structural change
  to what is published, not a rename of one name into another: the
  namespace-level `coupling.distance` keeps its spelling and its value, so no
  map row can express it. The finding gate carries it as a declared delta.
- The 0-class case is closed by construction rather than by a guard: a namespace
  with no declarations of its own is not in the partition, so the bare-enum
  namespaces that would otherwise enter the fold with `D = 1.0` do not.
- `DistanceRule` still declines to judge a node below `min_class_count`, so the
  rule and the aggregate continue to disagree about which nodes are worth an
  opinion. That is unchanged by this record and remains a separate question.

## Amendment (2026-09-24): `coupling.cbo` judges the own scope

Namespace-level `coupling.cbo` came to be taken over the subtree, the region the
published Ca and Ce are counted over, and the rule then judged leaf namespaces
only, because the thresholds do not describe a number that grows with the
subtree. Which namespace is a leaf is a fact about the run, not the code: a
namespace whose only sub-namespace holds one exception class was never judged
on a whole run, and was judged on a run that left the sub-namespace out. Which
namespaces were judged depended on the paths given.

**The rule judges every namespace on the coupling of its own declarations** —
the population this record folds, and for the same reason: each declaration
belongs to exactly one namespace. `coupling.cbo-own` is published beside
`coupling.cbo`, counting the namespaces coupled to the classes a namespace
declares itself, a sub-namespace included like any other; the subtree value is
published unchanged and not judged. A namespace declaring no type the run
analysed gets no `coupling.cbo-own`, as it gets no `coupling.abstractness-own`.
`min_class_count` counts the namespace's own classes and the finding's direction
reads `coupling.ca-own` / `coupling.ce-own`, so a finding never pairs an own CBO
with a subtree Ca or Ce.

What this makes independent of the run's paths is the population: which
namespaces are judged, and over which declarations. It does not make the value
independent of them, and nothing about a coupling count can. A dependency is
read from the class it starts at, so a class the run did not analyse
contributes no edge — to `coupling.cbo-own` as to Ca, instability and class
rank. A namespace whose classes are extended or used by classes left out of
the run, a sub-namespace's among them, is judged on fewer coupled namespaces
than a whole run finds, and can drop below its threshold. Measured on a
namespace of three classes coupled to thirteen namespaces, plus one class in a
sub-namespace extending one of them: 14 on the whole run, a finding at the
default threshold; 13 with the sub-namespace left out, none. The run says so:
a run whose paths leave production autoload targets out publishes
`projectScope.state: narrowed` (ADR 0084).

The rejected alternatives were to stop treating as a parent a namespace whose
children are all below `min_class_count`, which still left the population a
function of the run's paths, and to judge the subtree value at the leaves and
name the cost, which kept it one. Two alternatives for the value were rejected
as well. Declaring `coupling.cbo` unjudged on a narrowed run would silence the
class level too, and every coupling measure has the same dependence, so
singling this one out is disproportionate. Counting outbound dependencies only
would make the value independent of the run, but it is a different metric —
efferent coupling, not CBO — and would have to be renamed under the Metrics
Policy.

Measured on this repository with default thresholds: the namespace-level
findings go from 16 to 30. The 14 added are all parents with classes of their
own that the leaf rule never judged; every leaf is judged on the value it was
judged on before, because for a namespace without sub-namespaces the two scopes
are one (all 127 leaves were compared).
