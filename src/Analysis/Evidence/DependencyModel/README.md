# DependencyModel

## Subject, promise and ownership

- **Subject:** dependency evidence between logical PHP classes and namespaces.
- **Promise:** preserve collected dependency occurrences and expose a graph with
  deterministic class, namespace, afferent, and efferent coupling views.
- **Semantic owner:** `Analysis.Evidence.DependencyModel`.
- **Owned paths:** `src/Analysis/Evidence/DependencyModel/` and
  `tests/Analysis/Evidence/DependencyModel/`.
- **Non-goals:** collection sequencing remains with `Analysis.Run`; cycle
  detection is prepared by its own P4 leaf and is not DependencyModel state.

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
└── EmptyDependencyGraph.php
```

## Public surface

The model's graph/value contracts and
`DependencyTraversalParticipantInterface` are the declared public surface.
`DependencyGraph`, `DependencyGraphBuilder`, `EmptyDependencyGraph`, and every
type under `Extraction/` are internal implementation details.
`DependencyLocationInterface` exposes a structured relative file and line so
Finding consumers can project DependencyModel-owned extraction locations
without parsing their wire representation. `Analysis\Finding\Contract\Location` also
implements the contract; when dependency evidence already carries that type,
findings preserve the same object identity.

`DependencyGraphBuilderInterface` accepts dependency occurrences together with
the logical class universe. The universe retains degree-zero declarations, while
the builder derives all ancestor namespaces locally and preserves dependency
encounter order and coupling semantics.

`DependencyGraphInterface` has raw CBO 27 and the inclusive point threshold 28,
so one additional edge fails rather than being absorbed. Its five net consumers
are `DependencyGraphBuilderInterface`, `DependencyGraphBuilder`,
`AnalysisPipeline`, `DependencyGraphProjector`, and
`MeasurementAggregationInterface`. The threshold documents this stable query
boundary; it is not a namespace exclusion or permission to import extraction
internals.

## Extraction and worker reconstruction

`DependencyTraversalParticipantInterface` is a DependencyModel-owned promise
to its named consumers and extends php-parser's `NodeVisitor`. The caller invokes
`beginFile(RelativePath)` before traversal, feeds AST events through the visitor
lifecycle, and reads the exact `list<Dependency>` from `dependencies()` after
traversal. `DependencyResolver`,
`DependencyVisitor`, and their handlers remain private to the extraction
family. Parallel worker bootstrapping reconstructs the participant from the
same internal configuration used sequentially; it does not serialize a visitor
or allow other modules to import extraction internals.

## Test ownership

The module owns these Unit test classes under
`tests/Analysis/Evidence/DependencyModel/Unit/`:

- `DependencyTest`
- `EmptyDependencyGraphTest`
- `DependencyGraphTest`
- `DependencyGraphBuilderTest`
- `DependencyResolverTest`
- `DependencyVisitorTest`
- `TypeDependencyHelperTest`

Run them with:

```bash
vendor/bin/phpunit --no-coverage tests/Analysis/Evidence/DependencyModel/Unit
```
