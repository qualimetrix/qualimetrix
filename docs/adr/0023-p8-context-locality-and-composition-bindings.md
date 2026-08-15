# 0023. P8 context locality and composition bindings

**Date:** 2026-08-15

**Status:** Accepted

## Context

Configuration, invocation state, progress, profiling and Symfony container
composition had crossed capability boundaries through transitional holders and
temporary internal grants. Those mechanisms made the active dependency surface
hard to inspect and let a coarse qmx owner edge conceal an unlisted declaration
import.

## Decision

Each capability owns its immutable configuration projection and any necessary
per-container runtime state. Run owns invocation configuration and collection
progress; Finding owns effective rule configuration; Cohesion owns its LCOM
collection projection; Cache and Parallel own their runtime stores; Profiling
uses a neutral Core instrumentation port with an Infrastructure-owned session.

The internal manifest records every direct Symfony composition-root reference
to a private declaration as one permanent exact `composition_binding`. A binding
is limited to a named `Infrastructure.DependencyInjection` source, an internal
target, and a declared behavioural container operation. It is not a public
contract and does not authorize another source. The generated coarse qmx edge
is evidence of the observed dependency only; exact manifest validation remains
the authorization boundary.

Core Path and Symbol value vocabularies remain direct public declarations:
their subject is the value itself, so a role-only `Contract` subdirectory would
not improve encapsulation.

## Consequences

Temporary internal grants are removed. Generated governance reports publish
exact composition bindings, public import fan-in, production-to-test checks,
and test-topology dispositions. Future wiring must add a reviewed exact binding
or a named public contract; an owner-level allow edge alone is insufficient.
