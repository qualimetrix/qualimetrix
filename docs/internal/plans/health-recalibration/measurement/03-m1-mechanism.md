# Why the global namespace leaves no trace in a project score

Established by reading the code, after the arithmetic in
`00-applicability.md` predicted the rule from measurements.

## The path a namespace-collected metric takes

`coupling.distance` is collected on namespace symbols
(`DistanceCollector::getMetricDefinitions()` declares
`collectedAt: Namespace_`, `aggregations: [Project => Average]`).

`NamespaceToProjectAggregator` then aggregates it — but not over every namespace
symbol. It reads `$this->tree->getLeaves()` and the comment says why:

> Only aggregate leaf namespaces (those with class/method/function symbols)
> to avoid double-counting parent namespaces whose I/A/D are derived from
> children.

That restriction is deliberate, documented, and right. It is not the defect.

## The defect is one line above, in a different subject

`NamespaceTree::registerInputNamespaces()`:

```php
foreach ($leafNamespaces as $ns) {
    if ($ns === '') {
        continue;
    }
    $allNodes[$ns] = true;
}
```

The global namespace is the empty string. It never enters the tree, so it is
never a leaf, so the aggregator never reads its bag — even though that bag
exists and carries a computed `coupling.distance`. CodeIgniter's `(global)`
holds 0.944, near the worst possible, and contributes to nothing.

`ProjectNamespaceResolver::isProjectNamespace()` is explicit that the empty
namespace *is* project code ("Empty namespace is considered project namespace
(global scope)"), so the two components disagree about whether global-namespace
code is part of the project.

## Why this matters more than a missing value

The skip is a guard for **tree construction**: an empty string has no parent
chain to walk, so it is excluded from a structure built out of parent chains.
That is a reasonable thing for a tree to do. What it is not is a decision about
**measurement** — nobody wrote "code in the global namespace shall not be
scored structurally". A traversal guard became a measurement filter by
accident, and the consequence is that a wholly procedural project takes no
structural penalty at all and scores a perfect 100 on coupling.

## What the fix has to separate

- Global-namespace code must reach the project aggregate. It is a leaf by
  definition: no parent, no children.
- The leaf-only rule stays. Removing it would reintroduce the double-counting
  its comment describes.
- Whether the aggregate remains an unweighted mean is a second, independent
  question, priced at 0.03 to 2.65 points of coupling drift by C4
  (`07-monotonicity-direction.md`).
