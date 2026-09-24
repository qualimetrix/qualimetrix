# 0082. The Project Aggregate Is Typed, Not Spelled

**Date:** 2026-09-24
**Status:** Accepted

## Context

`SymbolPath` marked the project aggregate by storing `__PROJECT__` in its
namespace field and typed any path with that namespace, no class and no member
as `SymbolType::Project`. `__PROJECT__` is a legal PHP namespace name. Analysing
`namespace __PROJECT__;` merged that namespace's aggregate into the project's,
dropped the namespace from the `symbol` and `subject` of every declaration in
it, and reported its namespace-level findings as project findings under the
`project:` identity. The published `namespace` field and the
`--group-by=namespace` key of project findings also collided with the real
namespace's.

## Decision

The project is told apart by a private flag that only `forProject()` sets. No
namespace string, from source code, a selector, a cache entry or a baseline, can
produce `SymbolType::Project`.

The namespace field of the project path shows `(project)`, which cannot occur
in a PHP namespace name, so no analysed namespace publishes the same value. It
is a display value and is never compared to decide the type.

Alternatives rejected:

- **Keep the type spelled by the string, change it to `(project)`, refuse the
  sentinel in every factory.** The type would still hang on a string that any
  caller of `forNamespace()` can supply, and each factory would need a guard
  whose only job is to keep that string out. With the type held by a flag
  there is nothing to guard: `forNamespace('(project)')` is an ordinary
  namespace that matches no analysed code, and `--namespace=exact:(project)`
  is already refused as selecting no namespace (exit 3) before any report
  code builds a path from it.
- **Give the project a `null` namespace.** Nineteen source files across
  Reporting, Finding and the Evidence capabilities read the namespace field as
  `namespace ?? ''`; project findings would silently join the global namespace
  in grouping, in namespace exclusions and in offender attribution.

## Consequences

- Project findings publish `"namespace": "(project)"` and group under the
  `(project)` key; the identity `project:` and the symbol `(project)` do not
  change, so baseline keys are unaffected.
- A namespace named `__PROJECT__` is measured, reported and keyed like any other
  namespace.
- Readers that group by the namespace string keep treating the project as one
  more group key; the value merely changed. In text output grouped by
  namespace, `(project)` sorts before every namespace name.
