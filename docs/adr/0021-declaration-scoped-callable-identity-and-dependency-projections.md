# 0021. Declaration-Scoped Callable Identity and Dependency Projections

**Date:** 2026-08-09

**Status:** Accepted

**Plan:** [Scanner Validation Round 2](../internal/SCANNER_VALIDATION_ROUND_2_PLAN.md)

## Context

The scanner currently uses a logical `SymbolPath` as both aggregate identity
and callable/declaration identity. It cannot distinguish valid declarations
with the same logical name, and it drops closures and arrows because they have
no stable method path. Property hooks have no callable contract. Dependency,
coupling, ClassRank, baseline, and formatter consumers consequently apply
different, implicit projections of the same source fact.

Round 1 findings F-006 through F-014 established concrete consequences:
callable loss, position-dependent initializer attribution, capture counted as
execution, missing degree-zero coupling values, and unstable or incomplete
projection semantics. A name-only identity cannot fix those independently.
Conditional duplicate-FQN semantics are separately recorded as deferred D-001;
they are not part of F-006 through F-014 and this ADR does not decide their
mutual-exclusivity semantics.

## Decision

### Declaration identity is separate from logical identity

Introduce `MetricSubject` as a tagged union:

```text
Declaration(DeclarationPath)
LogicalClass(LogicalClassPath)
Aggregate(SymbolPath[file|namespace|project])
```

`DeclarationPath` is an intrinsic, durable declaration key: logical
`SymbolPath`, project-relative file, `startFilePos`, and a deterministic ordinal
only inside a same-position collision group. It is used for declaration metric
storage, violations, controls, baseline identities, and output fingerprints.
Adding a sibling declaration cannot alter an existing declaration key.

`LogicalClassPath` is exclusively the graph vertex identity for ClassRank. It
does not replace a declaration identity and does not receive source location.
The existing file/namespace/project `SymbolPath` aggregates remain logical.

These are cross-cutting identity primitives and therefore belong in the
existing `Core/Symbol` subject. No new technical-role directory is created.

### Callable replaces method as the leaf vocabulary

The public leaf level is renamed from `Method` to `Callable` in metrics, rules,
and configuration. `CallableKind` is exactly `Method`, `Function`,
`PropertyHook`, or `AnonymousCallable`; closure and arrow are syntax metadata
inside `AnonymousCallable`, not separate kinds. There are no legacy aliases or
compatibility shims.

Exact lexical class context is distinct from nullable class aggregation owner.
Methods and property hooks roll up to their named class. Functions and anonymous
callables roll up only to namespace/project. An anonymous callable nested in a class
may attribute its RFC calls to that exact enclosing class, without becoming a
class method. Method count and WMC remain methods-only; RFC contains own
methods plus hooks. Hook controls resolve in this order:

`hook > property > class > config`.

First-class callable capture is distinct from invocation: it can preserve a
resolvable dependency but adds neither an executed RFC target nor the ordinary
Halstead call operator. An initializer is never synthesized as a callable:
property and class-constant initializers have no callable owner. Halstead's
method declaration subtree does include promoted-parameter defaults, so a
first-class capture there belongs to constructor Halstead; it remains
non-executing for RFC.

### Dependency consumers use explicit projections

Dependencies preserve an exact declaration source and a logical target.
Architecture resolution expands that logical target to zero, one, or many owned
declarations. CBO uses unique logical sources/targets and retains external
targets. The graph universe is seeded by repository logical classes, including
degree-zero vertices.

ClassRank computes, aggregates, and scales one score per `LogicalClassPath`.
That score is then projected to each declaration; each finding keeps its own
controls, baseline identity, and fingerprint.

### Breaking migration is explicit

The implementation updates all formatter, baseline, rule, and configuration
projections together. Existing baselines are not silently migrated or
regenerated. The release contains a `Breaking` changelog entry and
consumer-oriented migration instructions. The implementation first performs
one atomic mechanical `Method`-to-`Callable` rename across every tracked
consumer, test, fixture, configuration channel, and reference example. Only a
green, alias-free tree may proceed to visitor semantics and then pipeline
identity/storage migration; no package relies on an intentionally uncompilable
intermediate state. The P1 internal callable payload deliberately retains the
old shape only until P2 atomically installs the final declaration metadata; it
is not an outward compatibility surface and does not alter this ADR's final
state.

## Consequences

- Duplicate or anonymous callable declarations can be stored, reported, and
  controlled without a name collision or loss of leaf metrics.
- Consumers must use their declared projection and cannot reconstruct identity
  from display names.
- Existing callable-level configuration and baseline keys intentionally break;
  consumers update them in a reviewed migration.
- More identities and graph vertices increase test surface and serialization
  obligations, but preserve the one-analysis-model guarantee across output
  formats.
- F-007, F-008, F-009, F-010, F-011, F-013, and F-014 are implemented against
  the contract in the linked plan; their Round 1 record remains historical
  until implementation and validation prove a disposition.
