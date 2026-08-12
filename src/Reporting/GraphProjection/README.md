# Graph Projection

`GraphProjection` renders the DependencyModel graph for delivery adapters.

Its public surface is deliberately limited to
`Contract\DependencyGraphProjectionInterface` and
`Contract\GraphProjectionRequest`. The Console adapter supplies a request and
receives bytes; DOT and JSON exporters, their options, and the dispatcher stay
internal to this module.

## Structure

```text
GraphProjection/
├── Contract/
│   ├── DependencyGraphProjectionInterface.php
│   └── GraphProjectionRequest.php
├── DependencyGraphProjector.php
├── DotExporter.php
├── DotExporterOptions.php
└── JsonGraphExporter.php
```

## Definition of Done

- Only the two `Contract` types are imported by delivery adapters.
- DOT and JSON output preserve the graph projection behaviour used by
  `graph:export`.
