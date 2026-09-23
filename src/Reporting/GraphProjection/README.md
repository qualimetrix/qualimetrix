# Graph Projection

`GraphProjection` renders the DependencyModel graph for delivery adapters.

Its public surface is `Contract\DependencyGraphProjectionInterface`,
`Contract\GraphProjectionRequest`, and the two vocabularies that request
types by: `Contract\GraphDirection` (DOT `rankdir`) and
`Contract\GraphExportFormat` (`dot`/`json`). Each is a single-owner backed
enum — before they existed the same four-word and two-word vocabularies were
written out separately in the command's option help, `DotExporterOptions`'s
docblock, and the DOT `rankdir` attribute, with no shared source
of truth.
The Console adapter validates a raw `--direction`/`--format` against these
enums *before* running the analysis, then supplies a typed request and
receives bytes; DOT and JSON exporters, their options, and the dispatcher
stay internal to this module.

## Structure

```text
GraphProjection/
├── Contract/
│   ├── DependencyGraphProjectionInterface.php
│   ├── GraphDirection.php
│   ├── GraphExportFormat.php
│   └── GraphProjectionRequest.php
├── DependencyGraphProjector.php
├── DotExporter.php
├── DotExporterOptions.php
├── JsonGraphExporter.php
└── NamespaceFilter.php
```

`NamespaceFilter` is the module's only namespace comparison. Both exporters
used to carry a private copy of it, and the binding answer the projector now
owes the Console adapter would have made a third: `unboundIncludeNamespaces()`
reports the `--namespace` values that match no class of the graph, so
`graph:export` refuses them (exit 3) instead of printing a graph the caller
cannot tell apart from a clean one. A value binds if it matches a class
*before* exclusion; `--exclude-namespace` misses stay silent by design,
because they leave the graph exactly as it would have been.

## Definition of Done

- Only the four `Contract` types are imported by delivery adapters.
- Exactly one namespace comparison exists in this module: exporters and the
  binding report never disagree about what a `--namespace` value matches.
- DOT and JSON output preserve the graph projection behaviour used by
  `graph:export`.
- `GraphDirection` and `GraphExportFormat` remain the sole owners of their
  vocabularies: no second literal list of direction or format values exists
  outside this module's `Contract/`.

## Locality

GraphProjection owns delivery-facing graph rendering. Console imports its
four declared contracts; exporters and options remain internal, while graph
semantics stay with DependencyModel.

A symbol name can carry a byte the parser accepts inside an identifier but
that is not valid UTF-8. `JsonGraphExporter` and `DotExporter` both repair it
via `Reporting\Formatter\PublishedUtf8` — the same repair-and-mark treatment
`check`'s own formatters give published strings — rather than let the export
fail (JSON) or silently write the invalid byte into the document (DOT). This
is the one production import this module takes from outside its own tree.
