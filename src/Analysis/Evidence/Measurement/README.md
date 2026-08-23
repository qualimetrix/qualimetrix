# Measurement

## Subject and boundary

`Analysis\\Evidence\\Measurement` owns metric collection facts, exact
declaration metrics, logical-class and namespace projections, aggregation,
repository implementation, namespace attribution, and the worker-safe
collector contract.

It is the owner of the shared measurement lifecycle, not of pipeline sequencing
or capability-specific evidence. Run passes file facts through its collection
contracts; Measurement returns typed metric/repository data. CodeSmell,
Cohesion, Complexity, Coupling, Design, Maintainability, Security, and Size own
their collectors and rules, while depending on Measurement's public contracts
for collection and repository interchange.

## Structure

```text
Measurement/
├── Aggregation/        # aggregation phases and global collectors
├── Contract/           # named cross-owner metric and repository promises
├── FileMeasurement/    # file collectors and derived metrics
├── Namespace_/         # project namespace attribution
├── Repository/         # in-memory repository and indexes
└── Visitor/             # AST visitor state and metadata
```

## Public contracts

The `Contract/` namespace is the complete external surface. Important promises
include `MetricRepositoryInterface`, `MetricRepositoryFactoryInterface`,
`MetricCollectorInterface`, `FileMeasurementCollectorInterface`,
`MeasurementAggregationInterface`, and `ProjectNamespaceResolverInterface`.
Consumers must not import repository indexes, visitor state, aggregation helpers,
or collector implementations.

`SymbolLevel` is the project's one level vocabulary: the rule layer, the
finding, the channel declaration and the stored metric all name a level with
it. `Core\Symbol\SymbolType` stays a separate question — what kind of
declaration this is, a fact about PHP — and the two meet only in
`SymbolLevelProjection::ofDeclaration()`, which collapses `method` and
`function` into `callable`. Do not re-derive that collapse at a call site.

**Why the vocabulary lives here and not in `Core`.** Nearly every rule now
imports it, and the fan-in is visible in this capability's own numbers — the
enum's point CBO threshold and its point ClassRank threshold exist for that
reason. Many imports are nonetheless not an argument for moving it: ADR 0022
settles this exact case, and settles it against the move — "`Core` holds only
neutral primitives without a natural leaf owner. Many imports do not make a
type neutral." A level is the level of the *aggregation tree*, which is this
capability's own model and not a neutral primitive; `Core\Symbol\SymbolType`
is the neutral one and already lives there. Revisit only if the aggregation
tree stops being owned here.

## Declaration numbering

A declaration's durable identity carries an ordinal — its rank among the
declarations of the same logical identity earlier in the same file — instead of
its byte position, so text edited above a declaration leaves its key alone. The
positions that rank is computed from are collected by one registrar per
traversal, `DeclarationRegistrarInterface`, created through
`DeclarationRegistrarFactory` and added to the traverser **before** every other
visitor. Producers never count: they receive the file's `FileDeclarationIndex`
through `DeclarationIndexAwareInterface` and ask it.

The factory is a required constructor dependency of `CompositeCollector`
precisely because the parallel worker assembles that constructor positionally: a
dependency that could be omitted would give workers that number files with no
registrar and no error. Two traversal owners exist — `CompositeCollector` for
the whole `check` analysis, and `DependencyGraphAnalyzer` for `graph:export` —
and each owns an index per file.

Three collaborators carry that between them, composed by `VisitorMethodContext`:
`VisitorLexicalScope` knows where the traversal stands, `DeclarationNumbering`
turns that position into the key a declaration is grouped under, and
`FileEntrySubjectRegistry` holds the wire subjects minted along the way. Which
subject is *current* follows from the traversal and lives with the scope; what a
subject is made of moves with the wire grammar and lives with the registry.

The position itself does not disappear; it stops being an identity.
`CallableWithMetrics` and `ClassWithMetrics` carry it as `startFilePos`, the
in-run key that joins what several producers said about one declaration, and
`FileProcessor` checks it: two records under one canonical key whose positions
differ are two declarations sharing a number, and that is a `LogicException`.

`MetricRepositoryInterface` retains exact declaration subjects and separately
projects logical classes and namespaces. A duplicate FQN declaration is an
exact fact; a logical-class projection is deliberately deduplicated before
namespace aggregation.

## Collection and worker reconstruction

`FileMeasurementCollectorInterface` supplies collectors and derived collectors.
Only `ParallelSafeCollectorInterface` implementations are reconstructed in a
parallel worker. Capability-specific collection values stay with their owning
capability: Cohesion supplies its typed `LcomCollectionConfiguration` through
its exact contract. `WorkerBootstrap` rebuilds the collector graph from
registered class names and receives that value before file processing,
preventing a worker from receiving a container service or state from a prior
run.

## Aggregation

`MeasurementAggregationService` owns initial aggregation, ordered global
collectors, and re-aggregation of global metric definitions. It consumes the
DependencyModel graph through `DependencyGraphInterface`; the graph itself and
its extraction internals remain DependencyModel-owned.

The service receives the neutral Core profiler port through constructor
injection. The per-container Infrastructure `ProfileSession` provides disabled
no-op behaviour and deliberately does
not consult mutable global profiler state. The exact span order is initial
`aggregation`, `global`, and optional `aggregation.global`, with the completion
log between the first two spans.

High fan-in of the public Measurement surface is governed by point thresholds,
not a namespace-wide exclusion. Current CBO thresholds give one-edge headroom:
`AbstractCollector` 27, `AggregationStrategy` 38, `MetricBag` 68,
`MetricDefinition` 33, `MetricName` 63, `MetricRepositoryInterface` 46,
`ResettableVisitorInterface` 23, and `SymbolLevel` 34. `MetricBag` and
`MetricRepositoryInterface` also carry rounded point ClassRank warning/error
thresholds of 0.035 and 0.020 respectively for their intentional contract-hub
role.

## Test ownership and Definition of Done

Owned tests live under `tests/Analysis/Evidence/Measurement/`. They cover
repository identity, file and derived collection, aggregation, namespace
attribution, and sequential/parallel worker equivalence.

- Collectors reset for each file; capability-owned configuration is resolved
  before each analysis run.
- Worker serialization contains only contracts and values, never services.
- Aggregation preserves exact declaration evidence and deterministic namespace
  and project projections.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
