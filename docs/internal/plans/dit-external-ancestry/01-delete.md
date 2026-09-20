# Stage 01 — remove the resolution that reaches nothing

## Premise, measured

After #111 the per-file resolver's return value reaches no published surface.
A sentinel `return 99` in
`InheritanceDepthCollector::resolveExternalClassDit()`, checked across **every**
`design.dit` key — class level, `.avg`, `.max`, `.p95`, `.count` — leaves the
output byte-identical on both fixtures.

Precisely: the value *is* carried into the repository by
`getClassesWithMetrics()` through `FileProcessor::extractClassMetrics()`. What
does not survive is the depth, which `DitGlobalCollector` overwrites. The key
survives and is load-bearing — it is what marks the population. So "publishes
nothing" is about the number, not about the write.

It is dead by result and alive by execution, and by more than the global pass:
both collectors instrumented on `benchmarks/vendor/symfony/http-kernel`

| collector | calls   |
| --------- | ------- |
| per-file  | **195** |
| global    | 40      |

The pass that publishes no depth runs roughly five times more foreign code than
the one that does, and it runs it inside the parallel workers.

> An earlier version of this measurement grepped only the exact key
> `"design.dit"` and concluded the resolver was unobservable; it was observable
> through the aggregates. Any repeat reads every key.

## The second defect, in `DitGlobalCollector::calculateDit()`

It treats "not a key in `$parentMap`" as "outside the project". A parent that
is itself a root has no `parentMap` entry, so in-project classes are sent to
`class_exists()`. This is not a corner:

| tree                                    | external-resolution calls naming an in-project class |
| --------------------------------------- | ---------------------------------------------------- |
| `benchmarks/vendor/symfony/http-kernel` | **25 of 40 (62%)**                                   |
| the whole `finding-gate` corpus         | **3 of 3 (100%)**                                    |

The per-file collector has the same confusion in its own `calculateDit()`,
where a parent absent from the current file is assumed external. Deleting its
resolver settles that half by removing the branch entirely.

## Contracts

```
InheritanceDepthCollector
  resolveExternalClassDit()   deleted
  calculateReflectionDit()    deleted with it
  calculateDit()              a parent it cannot see in this file scores 1

DitGlobalCollector::calculateDit(...)
  parent in the class universe, absent from $parentMap
      -> in-project root: depth 0, no external resolution
  parent absent from the universe
      -> resolveExternalClassDit() still, its body replaced in stage 02
```

**The universe must be chosen by measurement, not by name.** Both candidates
are plausible and they differ exactly where it matters: a class carrying no
dependency edge at all. `DependencyGraphInterface::getAllClasses()` may be
built from edge endpoints, in which case an edgeless class — a bare attribute
class is the common shape — is absent from it, is judged "external", and after
stage 02 scores 0 for a reason no one can see. `$repository->all(Class_)` is
the set the collector already iterates and cannot omit a measured class.

Acceptance before the choice is fixed: a fixture holding an attribute class
with no dependencies must keep its depth under both the old and the new code.
If `getAllClasses()` drops it, that settles the choice.

## Enumeration this stage relies on

"Nothing consumes the per-file resolver's value." Obtained by sentinel over
every `design.dit` key, plus reading the path from `getClassesWithMetrics()`
through `FileProcessor` to the global overwrite.

Blind spots, named: a sentinel proves the *value* unused, not the *call*
unmade; and fixtures are not the corpus. Both are closed before the work lands
by repeating it over `src/` and one benchmark project.

## The gate cannot see this work, and that is the first thing to fix

Measured: across all nineteen `finding-gate` corpus cases, external resolution
is entered **three times, for one FQCN** — `Corpus\Design\Hierarchy\Base`,
which is in-project and only arrives there because of the universe defect.
There is no case in the corpus whose class extends a genuinely external parent.

So after this stage the corpus enters external resolution **zero** times, and
stage 02 could replace the mechanism entirely under a fully green gate. A DoD
resting on that gate would be resting on a check that cannot redden.

**Therefore the corpus case comes first, before either stage's code.** The
`design` case gains a class whose parent lives outside the analysed path, with
the parent supplied inside the case (the corpus is external to the project by
rule — a case must never point at `src/`). That case is what makes the gate
able to answer anything about this campaign at all.

## Test plan

- The per-file pass consults no autoloader: `UnloadableClassProbe`'s
  `queryCount()` flips from 1 to 0, and the assertion message is rewritten
  rather than edited, because its meaning inverts.
- `failedOnTheMissingParent()` becomes false on that path. Stated in the PR as
  the intended reversal of the oracle, not quietly adjusted.
- `UnloadableExternalParentRunTest` covers the global pass, which still loads
  here. Check first whether the case can tell the two collectors apart; if it
  cannot, it must gain that distinction in this stage, or deleting one of them
  leaves it green for the wrong reason.
- An in-project root parent reaches no autoloader while still scoring its
  depth.
- The edgeless-attribute-class fixture above.

## Definition of Done

1. The gate corpus case exists and is shown to enter external resolution.
2. Sentinel repeated over `src/` and one benchmark project, not only fixtures.
3. `composer check` green in full.
4. `composer gate -- --reference=<this stage's base>`: with the new case in
   place the run can see the change. Expected shape is the in-project root
   parents that scored `1 + 0` through a load and now score the same without
   one. Anything beyond that on `metrics`/`html` is a finding, not a row to
   declare.
5. `composer benchmark:check`.
6. Review of the stage.
