# 0022. Capability-Oriented Modular Monolith

**Date:** 2026-08-11
**Status:** Accepted; supersedes [ADR 0012](0012-hybrid-architectural-direction.md)
**Related:** [ADR 0010](0010-architecture-vertical-slice.md) (historical pilot), [ADR 0016](0016-subject-cohesion.md) (governing cohesion rule)

## Context

ADR 0012 kept thin metrics and rules in horizontal role buckets and reserved
vertical slices for sufficiently large features. That size/shape fast path did
not answer the ownership question. One capability still changes across
`Metrics`, `Rules`, `Configuration`, tests and documentation, while generic
holders and universal contexts carry feature state across those seams.

The P0 inventory also showed that contract status cannot be inferred from
namespace depth or fan-in. A public boundary exists only when another named
module consumes a promise. Likewise, orchestration needs typed phase-specific
ports, not a generic plugin bag, and mutable feature state needs an explicit
lifecycle rather than process-wide storage.

## Decision

### Leaf capabilities are the architectural boundaries

Qualimetrix moves toward a capability-oriented modular monolith. A leaf module
is a cohesive subject with one owner, one lifecycle, owned tests and
documentation, and an explicit dependency boundary. Its internal layout follows
the subject; it does not receive an empty `Domain/Configuration/Processing/Rules`
skeleton by convention.

`Analysis`, `Analysis\Evidence`, and `Analysis\Policy` are navigation
taxonomies only. They contain no production types, state, shared contracts or
qmx layers, and they are never dependency allow targets. P0 proved that empty
taxonomy directories remain non-architectural under PSR-4, Symfony discovery
and qmx coverage. Composer's single `Qualimetrix\` prefix and declaration-based
qmx classification make the design feasible, and the completed P0 review
verified DI discovery and generated topology together. The architectural
boundary is always a leaf such as
`Analysis\Evidence\Duplication` or `Analysis\Policy\Architecture`.

This decision establishes the target layout; it is not a claim that packages
P1-P8 have completed. P0 governance is live: the versioned internal manifest is
authoritative for the current declarations and semantic owners, and its
generated qmx projection enforces their coarse topology. The physical P1-P8
migration remains future work.

### Contracts require named consumers

A leaf exposes `Contract` only when at least one named external owner consumes
its promise. The module README lists those consumers and the exact types they
use. External modules import only that contract namespace. Internal entities,
holders, raw configuration arrays and framework types do not cross the seam. A
private leaf has no `Contract` directory.

A port introduced to invert a dependency belongs to the consumer that needs the
capability. The target hypothesis is that `Analysis\Run` will own typed,
phase-specific participant ports while capability modules retain their prepared
results. These ports are non-binding design hypotheses until the P3 contract
gate proves their inputs, outputs and actual participant dependencies. They are
not descriptions of the current implementation. The target must not introduce
a universal participant interface, service locator or heterogeneous result bag.

### State and lifecycle are owned together

Mutable feature state is instance-owned and scoped to one run. Its capability
owns creation, reset and typed read access. Run orchestration invokes lifecycle
ports but does not acquire feature-specific fields. Static feature holders are
removed during the owning migration package. Process-wide logging and profiling
proxies are separate infrastructure concerns and must be reviewed explicitly;
this decision does not legitimise them by analogy.

### Supporting boundaries

- `Core` contains only neutral primitives with no natural capability owner.
  Import count alone is not evidence of neutrality.
- `Infrastructure` contains delivery, framework and composition adapters. It
  may wire capabilities but does not own application policy or feature state.
- Cross-capability sequencing belongs to `Analysis\Run`; output-only
  projections belong to `Reporting`.
- Tests are organised subject-first. Unit, integration, functional, fixtures
  and support are subdivisions within the owning subject. A production move
  includes its owned tests and discovery wiring.
- The current internal owner manifest is the authoritative governance input for
  owner/visibility, named contract consumers and temporary grants. It generates
  the qmx owner block and production inventories; it does not duplicate DI
  registration.
- Generated ownership/import inventories are deterministic review projections,
  not the manifest and not an independent source of ownership truth.

Every current production declaration has one explicit semantic owner in the
internal manifest. The reviewed snapshot contains 695 declarations in 693 files
and 37 owners. The generator projects that intent into a coarse qmx owner/seam
block and review inventories. Open-ended owner templates such as a category
wildcard are prohibited because a new sibling would be silently enrolled.
Temporary grants name the exact edge, accountable owner and package/condition
that removes it; the manifest checker, not qmx, enforces that exactness.

### Fail-closed project topology

The generated qmx projection has 37 semantic-owner layers, 14 singleton
enforcement seams and final `external`: 52 layers and 296 allow edges in the
reviewed snapshot. `external` excludes `Qualimetrix\**`, and `coverage: error`
includes every analysed logical class outside all declared layers even when it
has no dependency edges, as well as unclassified dependency endpoints. The qmx
allow graph is deliberately coarse; `composer architecture:check` validates
exact manifest visibility, consumers and temporary grants before selfcheck.

The declared exact allow graph must be a DAG, independently of actual code
cycles. This validation is already implemented: at configuration load, every
exact source-to-exact-target edge is projected and a self-edge or directed cycle
raises `ConfigLoadException`.
Relation filters do not weaken the boundary: opposing exact permissions still
form a cyclic module topology even if they allow different relation kinds.
Runtime circular-dependency analysis remains responsible for cycles in actual
class dependencies.

Glob and captured selectors are deliberately not projected into the static
declared graph. Their concrete matches can be observation-driven and do not
exist until template expansion after collection; treating selector text as
nodes would invent edges. Wildcard self-shaped permissions retain their
configuration warning and capture-binding guidance, but do not receive a false
static DAG verdict.

### Migration of exact allow lists

This is a breaking configuration change:

- an exact self-reference such as `domain: [domain]` used to be silently
  stripped because same-layer code dependencies are already allowed; it now
  fails with `ConfigLoadException`;
- a two-way exact permission used to emit a deferred mutual-allow warning; it
  now fails, as does every longer exact directed cycle.

Consumers must remove redundant self-edges and break or reorient cyclic module
permissions. A cycle is not fixed by changing relation filters; at least one
architectural allow edge must be removed or pointed in the dependency direction.

## Consequences

- ADR 0012's “substantial vertical / thin layered” direction is superseded.
  ADR 0010 remains the historical Architecture pilot, while ADR 0016 remains
  the governing test for subject cohesion.
- Capabilities may initially coexist with legacy role buckets, but every grant
  has a closure package; no new code is auto-owned by a wildcard template.
- Consumers depend on smaller, named contracts and orchestration no longer
  needs feature payloads in universal contexts.
- Configuration that previously relied on exact cyclic permissions must be
  migrated before analysis can start.
- Fail-closed ownership detects both edge-connected and isolated unowned code.
- The internal owner manifest remains the source for generated qmx ownership
  and inventories while P1-P8 close its temporary grants and seams. Generated
  inventories remain auditable projections and can be deleted and regenerated
  without affecting runtime behaviour.

## References

- [ADR 0010 — Architecture as Vertical Slice](0010-architecture-vertical-slice.md)
- [ADR 0016 — Subject Cohesion](0016-subject-cohesion.md)
- [Modular architecture migration plan](../internal/plans/modular-architecture.md)
- [Module README template](../internal/MODULE_README_TEMPLATE.md)
