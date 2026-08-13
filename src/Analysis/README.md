# Analysis

`Analysis` is a navigation taxonomy. It owns no shared production type, state,
or dependency allow-list target. Its leaf capabilities define the actual
boundaries.

## Current leaves

| Leaf                                                             | Subject                                                                  | Read first                                            |
| ---------------------------------------------------------------- | ------------------------------------------------------------------------ | ----------------------------------------------------- |
| [`Configuration`](Configuration/README.md)                       | configuration document resolution and transitional runtime configuration | configuration schema, stages, and exit packages P4-P7 |
| [`Evidence/DependencyModel`](Evidence/DependencyModel/README.md) | dependency evidence, graph construction, and extraction                  | public graph/traversal contracts                      |
| [`Evidence/Duplication`](Evidence/Duplication/README.md)         | file-set duplication evidence and one-run result                         | Run-port implementation and result lifecycle          |
| [`Evidence/Measurement`](Evidence/Measurement/README.md)         | metric facts, repository, namespace attribution, and aggregation         | metric/repository contracts and worker reconstruction |
| [`Run`](Run/README.md)                                           | discovery, collection, phase ordering, coverage, and run results         | Pipeline and FileSet inspection contracts             |

`RuleExecution` remains a P6 migration input. `Architecture` and its policy
state are not Analysis-owned; P4 isolates that capability. The `Metrics/`,
`Rules/`, and remaining policy trees stay physical migration inputs until their
named packages land.

## Current execution flow

```text
Configuration resolution
  -> Run discovery
  -> Run collection (the only parallel phase)
  -> DependencyModel graph build
  -> Architecture preparation
  -> transitional enrichment
  -> rule execution
  -> reporting adapters
```

Run sequencing never creates a generic capability registry or transports
capability result payloads. Its only P3 participant port is file-set inspection:
a capability receives the eligible file set and keeps its result privately.
Dependency traversal is separately a DependencyModel promise, not a Run port.

## Reading and test conventions

Read the leaf README before changing a type beneath it. Tests follow the same
subject-first layout under `tests/Analysis/`; a moved production capability moves
its owned tests, fixtures, and support with it. External tests remain with their
semantic owner even when P3 rewrites their imports.

## Definition of Done

- No type is added directly to `Analysis`, `Analysis\\Evidence`, or
  `Analysis\\Policy`.
- Cross-leaf imports use a declared contract; a taxonomy namespace is never an
  allow-list target.
- A phase change documents its input, output, state owner, and direct tests in
  the owning leaf README.
