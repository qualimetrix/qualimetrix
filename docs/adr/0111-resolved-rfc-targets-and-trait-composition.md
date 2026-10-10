# ADR 0111: Resolved RFC targets and trait composition

## Status

Accepted

## Context

RFC keyed responses by receiver syntax. Different variables could split one
known target, while one variable name could merge different targets. Ignoring
inherited responses and abstract methods also changed the response set without
expressing a language adaptation. Trait dependency edges contain names, not
method aliases, visibility adaptations or precedence rules.

## Decision

Coupling collects flat scalar facts on each exact class-like declaration. Its
global collector joins those facts to the captured callable owners and
inheritance edges, then publishes all three final RFC metrics on the same
exact declaration. Namespace and project aggregation consume those final
values. Measurement retains responsibility for collection, transport and
aggregation; it does not acquire PHP method-composition policy.

The own set counts distinct exposed methods, including abstract methods and
property hooks. Effective imported trait methods participate; two aliases of
one body are two exposed members. Directly invoked responses outside that set
supply the external part. A resolved declaring body identifies a known method
response; spelling variants share its identity. Class and method identities
follow PHP's ASCII case rules. Property-hook identities retain the
case-sensitive property name. Functions use a separate response identity.

Known single-class receiver evidence includes resolved class names, declared
parameter and property types, and the supported local assignment and positive
`instanceof` forms. Unknown receivers share the method-name fallback. Unread
ancestry and ambiguous declarations do not invent a declaring body. The
owning README and RFC documentation define these bounded static limitations;
this is not general flow analysis or runtime dispatch resolution.

Core's Symbol subject owns a pure trait method-composition primitive. Its raw
facts describe own methods, nested trait uses, aliases, visibility and
precedence. Each call returns imported effective names and original bodies,
plus unread traits. Own methods override imports; qualified aliases can name
excluded sources. Active cycles differ from repeated diamond imports.
Unread facts remain distinct from known empty traits. The primitive does not
validate PHP method signatures or property compatibility.

This is neutral PHP symbol semantics, consumed by Coupling and intended for
Cohesion's effective-method model. It imports neither capability facts nor
parser nodes. If it permanently has only one capability consumer, return it
to that owner rather than retaining a speculative shared abstraction.

Cognitive recursion uses the existing resolved-name contract after the shared
name-resolution pass. DIT already consumes that contract through dependency
extraction; no second name resolver or use map is introduced.

## Consequences and migration

- Re-evaluate saved `coupling.rfc`, `coupling.rfc-own` and
  `coupling.rfc-external` expectations. Inherited calls, effective trait
  bodies, abstract methods and spelling variants now follow the response-set
  model. RFC still has no finding rule or configurable threshold.
- Re-evaluate Cognitive values and their accepted findings for recursion
  written through aliases or relative names. Imported foreign targets are
  not own recursion. Calibration consumes the resulting measurements.
- Resolve the final RFC definitions through the global collector. The file
  collector provides worker-safe raw entries, not final metric values.
- Reuse trait composition with raw immutable facts and an exact lookup
  callback. Keep response arithmetic and metric repository ownership with
  each capability consumer.
