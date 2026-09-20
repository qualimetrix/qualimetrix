# Stage 01 — remove the resolution that reaches nothing

## Premise, measured

After #111 the per-file resolver's return value is published nowhere. A
sentinel `return 99` in `InheritanceDepthCollector::resolveExternalClassDit()`,
checked across **every** `design.dit` key — class level, `.avg`, `.max`,
`.p95`, `.count` — leaves the output byte-identical on both fixtures.

It is dead by result and alive by execution: `calculateDit()` still calls it,
so the tool still loads analysed-project classes inside the parallel workers.
Deleting it removes real foreign-code execution, not dead arithmetic.

> The first version of this measurement grepped only the exact key
> `"design.dit"` and concluded the resolver was unobservable. It was observable
> through the aggregates. Any repeat of this check reads every key.

## Second defect in the same method

`DitGlobalCollector::calculateDit()` treats "not a key in `$parentMap`" as
"outside the project". A parent that is itself a root has no `parentMap` entry,
so in-project classes are sent to `class_exists()`. Measured on qmx's own
`src/`: of 7 distinct parents reaching external resolution, 2 are Qualimetrix's
own classes (`AbstractCollector`, `AbstractRule`).

The project's class universe — not the parent map — answers "is this ours".

## Contracts

```
InheritanceDepthCollector
  - resolveExternalClassDit()   deleted
  - calculateReflectionDit()    deleted with it
  calculateDit() returns 1 for a parent it cannot see in the file

DitGlobalCollector::calculateDit(...)
  a parent present in the class universe but absent from $parentMap
    -> it is an in-project root: depth 0, no external resolution
  a parent absent from the universe
    -> still resolveExternalClassDit() in this stage; stage 02 replaces its body
```

The universe comes from what the collector already iterates
(`$repository->all(SymbolLevel::Class_)`), collected once per run rather than
per lookup. Verify against `DependencyGraphInterface::getAllClasses()` and use
whichever actually contains a class carrying no dependency edges — an attribute
class with no edges is the shape that breaks the wrong choice.

## Enumeration this stage relies on

"Nothing consumes the per-file resolver." Obtained by sentinel over every
`design.dit` key on two fixtures, plus the reading that `getClassesWithMetrics()`
writes the class-level value the global pass overwrites.

Blind spots, named because they are what a sentinel cannot see: it proves the
*value* unused, not the *call* unmade; and two fixtures are not the corpus. A
run over `src/` and one benchmark project closes the second before the work
lands.

## Test plan

- The per-file collector no longer consults any autoloader. `UnloadableClassProbe`
  is the oracle and the assertion flips from `queryCount() === 1` to `0`; the
  message flips meaning with it, so it is rewritten rather than edited.
- `failedOnTheMissingParent()` becomes false for the per-file path. That is the
  intended reversal, stated in the PR, not quietly adjusted.
- `UnloadableExternalParentRunTest` still covers the **global** pass, which
  still loads in this stage. Check first whether the case distinguishes the two
  collectors today; if it does not, it is the run test that must gain the
  distinction, because otherwise deleting one of them leaves it green.
- New: an in-project parent that is a root must not reach external resolution —
  the probe's `queryCount()` stays 0 while the class still scores its depth.

## Definition of Done

1. Sentinel measurement repeated over `src/` and one benchmark project before
   removal, not only on fixtures.
2. `composer check` green in full.
3. `composer gate -- --reference=<the commit this stage starts from>`. The gate
   base is clean since #112, so a delta here is this stage's alone and can be
   declared. Expected shape: only in-project root parents that scored `1 + 0`
   through a load and now score the same without one — that is, **GREEN or
   near-GREEN**. Anything on `metrics`/`html` beyond that means the universe
   fix is doing more than intended, and is a finding rather than a row to
   declare.
4. `composer benchmark:check`.
5. Review of the stage, not only of the campaign.
