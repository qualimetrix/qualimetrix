# DependencyModel

## Subject, promise and ownership

- **Subject:** dependency evidence between logical PHP classes and namespaces.
- **Promise:** preserve collected dependency occurrences and expose a graph with
  deterministic class, namespace, afferent, and efferent coupling views — for a
  namespace, in both of the two scopes it can have.
- **Semantic owner:** `Analysis.Evidence.DependencyModel`.
- **Owned paths:** `src/Analysis/Evidence/DependencyModel/` and
  `tests/Analysis/Evidence/DependencyModel/`.
- **Non-goals:** collection sequencing remains with `Analysis.Run`; cycle
  detection belongs to its own leaf and is not DependencyModel state.

## Structure

```text
DependencyModel/
├── Contract/
│   ├── Dependency.php
│   ├── DependencyGraphBuilderInterface.php
│   ├── DependencyGraphInterface.php
│   ├── DependencyLocationInterface.php
│   ├── DependencyTraversalParticipantInterface.php
│   └── DependencyType.php
├── Extraction/
│   ├── DependencyResolver.php
│   ├── DependencyVisitor.php
│   └── Handler/                  # one internal extraction family
├── DependencyGraph.php
├── DependencyGraphBuilder.php
├── NamespaceCouplings.php        # both coupling scopes of every namespace
└── StringSet.php                 # unique-dependency counting for coupling
```

## Public surface

The model's graph/value contracts and
`DependencyTraversalParticipantInterface` are the declared public surface.
`DependencyGraph`, `DependencyGraphBuilder`, `NamespaceCouplings`,
`StringSet`, and every type under `Extraction/` are internal implementation
details.
`DependencyLocationInterface` exposes a structured relative file and line so
Finding consumers can project DependencyModel-owned extraction locations
without parsing their wire representation. `Analysis\Finding\Contract\Location` also
implements the contract; when dependency evidence already carries that type,
findings preserve the same object identity.

`DependencyGraphBuilderInterface` accepts dependency occurrences together with
the logical class universe. The universe retains degree-zero declarations, while
the builder derives all ancestor namespaces locally and preserves dependency
encounter order and coupling semantics.

### The coupling view and the declaration view

The builder leaves every edge whose target is a class PHP itself declares out
of the coupling view — `getAllDependencies()`, the per-class dependency lists,
`getAllClasses()`, Ce/Ca and both namespace scopes — because coupling to the
standard library is not architectural risk. An `extends` edge is kept in
`getAllDependencies()` (and its target in `getAllClasses()`), because DIT and
NOC read inheritance from it, but it is in no per-class dependency list and
counts toward no Ce, Ca or namespace scope: `extends \RuntimeException` is no
more coupling than `implements \Countable`, and a consumer computing CBO from
the per-class lists agrees with Ce and Ca without deciding again what a PHP
class is.

`getDeclarationDependencies()` answers a different question — what a
declaration states about itself — and keeps every `extends`, `implements`,
`trait_use` and attribute edge, PHP target or not, in encounter order. Layer
membership reads it (`Policy\Architecture\Layer\ClassContextFactory`): read from
the coupling view, a class declaring `implements \JsonSerializable` is
indistinguishable from one that does not. Adding an edge to this view moves no
coupling metric; adding one to the coupling view does.

`ClassLikeHandler` also records the interfaces PHP gives a declaration without
their being written: `UnitEnum` on every enum, `BackedEnum` on a backed one,
and `Stringable` on a class or interface declaring `__toString()` in its own
body. They are `implements` edges to PHP's own interfaces, so they reach the
declaration view and never the coupling view. A `__toString()` a class takes
from a trait is not recorded: the trait's body is another declaration.

### The two namespace coupling scopes

A namespace that both declares classes and contains sub-namespaces is two things
at once, and the graph answers for both:

- `getNamespaceCe()` / `getNamespaceCa()` — the **subtree rollup**, over
  prefix-based boundary semantics: a dependency is a crossing when one side is
  inside the namespace or below it and the other is outside. An edge between two
  of its sub-namespaces is internal.
- `getNamespaceOwnCe()` / `getNamespaceOwnCa()` — the **own scope**, over exact
  namespace equality: only the classes declared in that namespace itself, with a
  sub-namespace outside like anything else.

Both scopes travel in one `NamespaceCouplings`, as one row of four counts per
namespace rather than as four separate maps: nothing reads a Ce without meaning
a scope, and a caller holding the four apart can pair a subtree Ce with an own
Ca — a ratio of two different regions, and one nothing would report. The rollup
pass names parent namespaces the own-scope pass never saw, so a namespace
present in one scope only answers zero in the other instead of falling out of
the index.

For a namespace without sub-namespaces the two coincide. The builder computes
the own scope for every namespace first and then derives the rollup from it, so
the rollup pass returns a changed copy rather than overwriting what it read:
the parent's own value has to survive the pass that replaces its published one.
Only the own scope partitions the declarations, which is what a project-level
fold of a namespace-collected metric is taken over.

`Dependency::$describesNestedAnonymousClass` marks an edge whose type is a
declaration fact (`extends`, `implements`, an attribute, or `trait_use`) of an
anonymous class nested in `$source`, rather than of `$source` itself — an
anonymous class has no declaration identity of its own, so
`DependencyVisitor` has nowhere else to attach the edge. The field changes
nothing about the edge's `DependencyType`, coupling, ClassRank, or `graph:export`
representation: dependency readers keep reading it as-is. Declaration readers
outside this module (`Design\Inheritance\DitGlobalCollector`, `NocCollector`,
`Policy\Architecture\Layer\ClassContextFactory`) skip a flagged edge instead.
See ADR 0071.

`Dependency::$interfaceExtends` marks an `extends` edge an interface declares
(`interface I extends J`). The same `DependencyType::Extends` names a parent
class when a class declares it, and the graph carries no declaration kind
otherwise, so a reader asking which interfaces a declaration has needs the
flag to count `J` for `I` without counting a parent class for its subclass.
Only `ClassContextFactory` (layer `implements:` membership) reads it; DIT, NOC,
coupling and `graph:export` treat both edges alike.

`DependencyGraphInterface` has raw CBO 27 and the inclusive point threshold 28,
so one additional edge fails rather than being absorbed. Its five net consumers
are `DependencyGraphBuilderInterface`, `DependencyGraphBuilder`,
`AnalysisPipeline`, `DependencyGraphProjector`, and
`MeasurementAggregationInterface`. The threshold documents this stable query
boundary; it is not a namespace exclusion or permission to import extraction
internals.

## StringSet

An immutable set of unique strings, used by the builder to accumulate each
namespace's efferent and afferent dependencies without counting a class twice.
It lived in `Core` until its subject was named: `Core` holds primitives with no
natural leaf owner, and this one has exactly one.

It is immutable: `add`, `addAll`, `filter`, `union`, `intersect`, `diff` and
the static `fromArray` never modify the receiver. They do not always allocate,
though — `add` returns the receiver when the value is already present, and
`addAll` returns it when every value is, which is what
`itReturnsTheSameInstanceWhenAddingADuplicate` pins. Identity is therefore not
a safe proxy for "nothing changed". It implements `Countable` and
`IteratorAggregate`, and answers `contains`, `isEmpty` and `toArray`.

A second consumer from another owner is not free: the manifest entry would have
to go back to `contract` and gain that consumer, both halves in the same edit.

## Extraction and worker reconstruction

`DependencyTraversalParticipantInterface` is a DependencyModel-owned promise
to its named consumers and extends php-parser's `NodeVisitor`. The caller invokes
`beginFile(RelativePath, FileDeclarationIndex)` before traversal, feeds AST
events through the visitor lifecycle, and reads the exact `list<Dependency>`
from `dependencies()` after traversal. The index is handed over per file
because the same participant instance serves both traversal paths, and the
number it puts in an edge's source declaration must belong to the path it is
currently taking part in. `DependencyResolver`,
`DependencyVisitor`, and their handlers remain private to the extraction
family. Parallel worker bootstrapping reconstructs the participant from the
same internal configuration used sequentially; it does not serialize a visitor
or allow other modules to import extraction internals.

## Test ownership

The module owns these Unit test classes under
`tests/Analysis/Evidence/DependencyModel/Unit/`:

- `DependencyTest`
- `DependencyGraphTest`
- `DependencyGraphBuilderTest`
- `DependencyResolverTest`
- `DependencyVisitorTest`
- `TypeDependencyHelperTest`

Run them with:

```bash
vendor/bin/phpunit --no-coverage tests/Analysis/Evidence/DependencyModel/Unit
```


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
