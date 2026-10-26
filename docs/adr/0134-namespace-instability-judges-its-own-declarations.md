# 0134. Namespace Instability Judges Its Own Declarations

**Date:** 2026-10-26
**Status:** Accepted

Supplements [ADR 0080](0080-a-project-fold-reads-a-partition-not-the-leaves.md),
which left the subtree rollup feeding `coupling.instability` as an unchanged
consequence rather than a decision.

## Context

A namespace has two coupling scopes: its own declarations (`*-own`) and its
subtree. Namespace CBO and distance judge the own scope. Namespace
`coupling.instability` still judged the subtree value, with the subtree's Ca
and Ce in its message and a population gate over the subtree's class count.

The subtree value folds in every outward dependency of the sub-namespaces,
while few consumers reach a parent that declares nothing itself. The root of a
bounded context that holds only sub-namespaces therefore reads close to 1 by
construction, and one more dependent decides whether it is reported
(issue #199). Alongside it, `coupling.instability-own` was published as 0 for a
namespace declaring no type, whereas `coupling.cbo-own` is absent there and
distance stays absent without Ca/Ce.

## Decision

1. **The judged value is `coupling.instability-own`.** The finding message
   carries Ca-own and Ce-own, and the `min_afferent` gate reads
   `coupling.ca-own`. There is no `scope:` option: one judged scope, as for CBO
   and distance. The subtree keys stay published as aggregate evidence.
2. **The population is counted over own types, option `min_type_count`
   (default 3).** It counts the classes, traits, interfaces and implementing
   enums declared directly in the namespace, exactly as distance does.
   Instability and distance are the package metrics of Martin's main sequence
   (D = |A + I - 1|); a namespace is either a package for both or for neither,
   and an interface-only contract namespace is a package too. Like distance,
   the population requires at least one counted own type even when
   `min_type_count` is 0; bare enums, which implement no interface, are not
   counted, so a namespace declaring only them is no package for either metric.
   `min_class_count` is removed from namespace instability with no alias, per the backward
   compatibility policy: the old key is refused by name (exit code 3) in YAML
   and in `--rule-opt`. CBO keeps `min_class_count` over own classes.
3. **`coupling.instability-own` is absent on a namespace declaring no analysed
   type**, as `coupling.cbo-own` is: a 0 would read as a measured one, and such
   a namespace is not a package. Publication follows any declared analysed
   type, so a namespace holding only bare enums carries both keys; the judged
   population follows the counted types of decision 2 and excludes it. A namespace declaring a type with no own edge
   (Ca-own = Ce-own = 0) keeps 0 by the existing convention. A user formula
   reading `m["coupling.instability-own"]` without `??` now gets no value on a
   namespace without own types, reported under `computedMetricOutcomes`;
   `?? 0` restores the former result.
4. **The Martin diagram and `health.coupling` stay on the subtree.** They
   summarise how a region is coupled to what lies outside it, which is the
   subtree question; changing them is a separate decision with its own
   calibration.
5. **Ratchet.** Six namespace instability entries no longer match and are
   removed. Two own-scope findings appear: `Infrastructure\Composer` (0.930)
   and `Infrastructure\Parallel` (0.915), and `Infrastructure\Console\CheckReport`
   tightens from 0.967 to 0.923. The two new ones are accepted as warning
   ratchet entries under #175: their Ce-own is 40 and 43, so removing them means
   restructuring two infrastructure namespaces, which is layout work owned by
   the issue that already tracks namespace-coupling debt with a closure
   condition. Shipping a metric correction together with an unmeasured module
   restructuring would mix two changes that must be judged separately.

## Rejected alternatives

- **A `scope:` option choosing own or subtree.** CBO and distance have one
  judged scope; a second one for instability would let two package metrics of
  the same namespace disagree about which code they judge.
- **Keeping `min_class_count` over own classes.** It would make instability
  and distance judge two populations of one package: an interface-only
  namespace would be judged for distance and skipped for instability.
- **Keeping the subtree value judged and only fixing the empty-root case.**
  The distortion is not limited to a root that declares nothing: any parent
  accumulates its sub-namespaces' outward edges.

## Consequences

- **Breaking.** The judged value changes, `namespace.min_class_count` of
  `coupling.instability` becomes `namespace.min_type_count` with a wider count,
  and `coupling.instability-own` disappears from namespaces without own types.
  Findings move accordingly.
- Which namespaces are judged no longer depends on which sub-namespaces the
  run holds; the value still depends on the classes analysed, as every coupling
  measure does.
- Subtree distance and the Martin diagram keep reading a value the rule no
  longer judges, so a namespace can appear unstable on the diagram without an
  instability finding.
