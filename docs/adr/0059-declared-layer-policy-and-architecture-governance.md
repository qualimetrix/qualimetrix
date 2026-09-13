# 0059. Declared-Layer Policy and Architecture Governance

**Date:** 2026-09-13
**Status:** Accepted

## Context

The declared-layer feature grew through several records while its
implementation still occupied role-based namespaces. Those decisions were
partly corrected later and their paths no longer described the
capability-oriented tree. The product needs one current statement that
separates layer policy from project architecture governance.

## Decision

`Analysis\Policy\Architecture` owns the declared-layer language, its
configuration, prepared membership state, diagnostics, and layer-policy rules.
Circular-dependency evidence remains a separate capability. Console commands and
container wiring remain Infrastructure adapters.

Layers are an ordered declaration. A class is assigned to the first declared
layer whose positive criteria match and whose `exclude` criteria do not remove
it. Criteria may select namespaces or classes; templates may bind captures and
expand into concrete layers. Same-layer dependencies are implicit. Cross-layer
dependencies are denied unless the source layer's `allow` declaration admits
the target, including any declared relation constraint. Exact declared allows
must form a directed acyclic layer graph.

One prepared evidence walk serves the policy's code findings and declaration
diagnostics. Forbidden code edges, unmatched exclusions, and unassigned classes
are ordinary findings owned by their rules. Invalid or ineffective layer
declarations are produced by the Architecture configuration validator and are
configuration errors: they cannot be accepted through a baseline and fail the
run independently of `fail_on`. A `pending` layer is exempt from the unreachable
layer diagnostic until code matches it; matching a pending layer is itself
reported.

Architecture state is instance-owned and reset for every run. Run invokes only
the capability's declared preparation contract; no mutable layer state enters a
worker payload or a generic analysis context. External consumers use only the
contracts named by the capability README.

Project-wide ownership is governed separately by the versioned internal owner
manifest. It assigns every production declaration one semantic owner, records
named contract consumers and exact composition bindings, and generates the
coarser qmx owner projection. Generated inventories and qmx layers are review and
enforcement projections, not a second source of ownership truth.

This ADR consolidates the current declared-layer policy. ADR 0006 remains the
detailed rationale for declaration-order matching; ADR 0014 remains the record
for retiring deptrac; ADR 0022 and ADR 0023 govern the wider module topology and
context locality.

## Consequences

- A layer declaration is interpreted by one capability with one run lifecycle.
- Adding a criterion, diagnostic, or policy rule changes the Architecture
  capability and its adapters, not a cross-project role bucket.
- Exact ownership and composition mistakes fail through the manifest checker;
  coarse qmx success cannot override them.
- Historical implementation paths are not current API.
