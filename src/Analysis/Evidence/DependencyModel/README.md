# DependencyModel

## Subject, promise and ownership

- **Subject:** dependency evidence between logical PHP classes and namespaces.
- **Promise:** preserve collected dependency occurrences and expose a graph with
  deterministic class, namespace, afferent, and efferent coupling views.
- **Semantic owner:** `Analysis.Evidence.DependencyModel`.
- **Owned paths:** `src/Analysis/Evidence/DependencyModel/` and
  `tests/Analysis/Evidence/DependencyModel/`.
- **Non-goals:** AST traversal and collection sequencing remain with
  `Analysis.Run`; cycle detection remains with `Analysis.Evidence.CircularDependency`.

## Structure

```text
DependencyModel/
├── Contract/
│   ├── Dependency.php
│   ├── DependencyGraphBuilderInterface.php
│   ├── DependencyGraphInterface.php
│   ├── DependencyLocationInterface.php
│   └── DependencyType.php
├── DependencyGraph.php
├── DependencyGraphBuilder.php
└── EmptyDependencyGraph.php
```

## Public surface

The five types in `Contract/` are the complete public surface. `DependencyGraph`,
`DependencyGraphBuilder`, and `EmptyDependencyGraph` are internal implementation
details. `DependencyLocationInterface` is implemented by
`Core\Violation\Location`, preserving the same location object in findings and
dependency evidence.

`DependencyGraphBuilderInterface` accepts dependency occurrences together with
the logical class universe. The universe retains degree-zero declarations, while
the builder derives all ancestor namespaces locally and preserves dependency
encounter order and coupling semantics.

## Test ownership

The module owns these Unit test classes under
`tests/Analysis/Evidence/DependencyModel/Unit/`:

- `DependencyTest`
- `EmptyDependencyGraphTest`
- `DependencyGraphTest`
- `DependencyGraphBuilderTest`

Run them with:

```bash
vendor/bin/phpunit --no-coverage tests/Analysis/Evidence/DependencyModel/Unit
```
