# 0073. A Depth Belongs to a Declaration, a Child Count to a Name

**Date:** 2026-09-20
**Status:** Accepted

## Context

`DitGlobalCollector` resolved inheritance from the dependency graph through a
map keyed by the child's fully qualified name. The edge it reads carries a
`DeclarationPath` on the source side and a `LogicalClassPath` on the target
side, so the name-keyed map discarded information the edge already had: when
one name is declared in two files, the second edge overwrote the first, and
every declaration of that name was published with the parent of whichever file
the walk reached last. `InheritanceRule` read the metric back through the same
collapsed key, so the report never contradicted itself — the rule and the map
agreed while both disagreed with the source.

Two declarations of one name are ordinary PHP, not broken code: the
`class_exists()`-guarded polyfill and its native counterpart are exactly this
shape, and so is a conditional compatibility shim.

Measured on fixtures before the change, with `--no-cache --format=json`:

| input                                                                           | published `design.dit`                      |
| ------------------------------------------------------------------------------- | ------------------------------------------- |
| `Shim extends Base1` in one file, `Shim extends Base2 extends Base1` in another | both declarations reported 2                |
| the same two bodies, file names exchanged                                       | both reported 1                             |
| `--workers=0` / `2` / `4`                                                       | identical — the dependence is on file order |
| a child of that name                                                            | inherited the wrong depth, plus one         |

So the published depth was not merely wrong for one of the two declarations: it
was a function of the order the file system was walked in, and renaming a file
moved a metric.

`NocCollector` reads the same `Extends` edges and carried a different defect of
the same family. Its parent side is logical by construction — `extends P` names
a name — but its child side counted edges, so a subclass declared twice was
counted twice: measured `NOC(P) = 2` for a hierarchy containing one subclass
named `Shim`.

## Decision

Follow the asymmetry the edge already records, rather than making both sides
exact or leaving both collapsed.

- **A depth is a fact about a declaration.** The child map is keyed by the
  child's `DeclarationPath`, the walk visits declarations, and each declaration
  is written back onto its own subject. `InheritanceRule` reads the declaration
  subject it already iterates instead of the logical projection.
- **A parent is a name.** `extends Foo` does not say which file declared `Foo`,
  and no later pass can recover it. When a parent name has declarations that
  disagree about depth, the **deepest** is taken.
- **A child count is a fact about a name.** `NocCollector` counts distinct
  child names, not edges. NOC is not given a per-declaration value, because
  there is no fact to attach one to. Its *findings* are still emitted per
  declaration, so a duplicated name reports the same count twice, with two
  subjects and twice the remediation time. Emission is left alone deliberately:
  moving a NOC finding onto a logical subject would change the identity of
  every NOC finding in every tree, and that is a separate contract change.
- **The logical class keeps one depth**, the maximum over that name's
  declarations, written after the per-declaration pass. Aggregation, the
  `metrics` export, the HTML tree and a user's computed-metric formula all
  address classes by name, and they must not read whichever declaration was
  stored last.

Max was chosen for two reasons, and deliberately not for a third. It does not
depend on traversal order, and it does not hide a depth that some declaration
in the tree really has. It is **not** justified by the channel judging higher as
worse: that is the direction of a judgement, not a policy for collapsing a
measurement, and reading it as a precedent would license the same move for
metrics where it means nothing.

Rejected: refusing to answer for an ambiguous name, the policy
`MetricSubjectIndex::logicalCallableMetrics()` already applies to callables. It
would withdraw a published metric from classes whose own declaration is
unambiguous, and the rule would then skip them in silence.

## Consequences

- Published `design.dit` and `design.noc` values move for any analysed tree
  that declares one class name in more than one file. Trees that do not —
  including this repository, the benchmark projects and every corpus case that
  existed before this change — are unaffected, which was checked by scanning
  for repeated class FQNs rather than assumed.
- A finding now reports the depth of the declaration it points at, so two
  declarations of one name can carry two different depths and two different
  severities. Consumers keying findings by class name see what looks like a
  disagreement; it is the source disagreeing with itself.
- The corpus case `finding-gate/cases/duplicate-declaration` exists so the gate
  can see this shape at all. A gate run whose reference carries the same
  product code proves that case is complete and stable — it cannot prove the
  change is safe, because both sides walk the same file system in the same
  order. The witness for the order dependence is an integration test that
  exchanges two file names and asserts the findings do not move.
- **The cost of max, stated.** In the polyfill shape the deeper declaration is
  often the one a live autoloader never reaches, so the name-level value can
  describe a branch that does not execute. The per-declaration values, which
  are what a finding now carries, remain exact for both branches.
- **Cycles remain order-dependent, and `max` carries that out of the cycle.**
  For `A extends B` and `B extends A` the walk still scores whichever node it
  enters first as 2 and the other as 1 — the cycle marker makes a branch's
  value depend on the entry point, and it did so before this change too. What
  `max` adds is reach: a name with one declaration inside a cycle and another
  outside it now contributes the deeper of the two, so an **acyclic** class
  extending that name inherits the cycle's entry order. Measured on a tree
  where one name is declared twice, once in a cycle and once above a
  three-deep chain: a single-declaration descendant reports 6 or 4 depending
  on which file name sorts first. So the determinism this decision buys covers
  hierarchies in which no name reaches a cycle, not every acyclic class.
- **Two declarations of one name inside one file are still measured as one**,
  and the walk deliberately follows the metric rather than the graph. The two
  producers disagree about how many declarations exist: the dependency graph
  numbers both by ordinal and records both `extends` edges, while every metric
  producer keys by name within a file, so the second body overwrites the first
  and one subject is published — recorded by `ClassProducerOrdinalTest` and
  unchanged here. The resolver therefore reasons only about declarations the
  run measured. An earlier version of this change let the unmeasured body
  contribute its depth, and the result was a report nobody could reconcile: a
  child published deeper than the only declaration of its parent the report
  shows. Propagating half of a fact is worse than not propagating it; making
  the metric producer number declarations the way the graph does is the repair
  this leaves open.
- `design.dit` is now the one key in a logical class bag with a stated
  collapsing policy; its neighbours still merge last-writer-wins. Making that
  uniform is a question about `InMemoryMetricRepository`, and it is open.
- **`--format=metrics` still addresses a class by name**, so it publishes one
  depth for a duplicated one and no consumer of that document can recover the
  per-declaration values a finding now carries. `ChannelJudgedMetricDriftTest`
  is the first place this bit: it compares a finding's magnitude against that
  export, and now stops short of calling the difference a disagreement for a
  name declared twice — narrowly, with the declared key still required to
  exist. Giving the export a declaration-addressable class level would close
  it, and is a separate change to what that document promises.
- **One repository contract was added**, and the product chose it.
  `MetricRepositoryInterface::addSubjectScalar()` is the declaration-addressed
  counterpart of `addScalar()`. The first version of this change did without
  it, writing a one-key `MetricBag` through `addSubject()`; that makes every
  global collector enriching declarations a dependent of `MetricBag`, which
  sits on its CBO threshold, and this repository's own analysis of itself went
  red on the first one. The same run then flagged `DitGlobalCollector` as a god
  class on all three criteria, which is why the walk now lives in
  `InheritanceDepthResolver` and the collector keeps the protocol and the
  repository pass. Both were refactors rather than threshold edits, and the
  gate is GREEN across them: the split moved no published value.
