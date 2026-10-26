# 0136. Constructor-Only Helpers Leave the LCOM4 Graph

**Date:** 2026-10-27
**Status:** Accepted

Extends the constructor and destructor exclusion that `cohesion.lcom` already
applied as a scope adaptation of LCOM4.

## Context

Issue #198: a class whose constructor delegates its setup to private helpers
(`initCache()`, `buildDefaults()`) got one extra LCOM4 component per helper.
The constructor is excluded from the graph, so the edges that tied the helpers
to the class went with it, and each helper became an isolated vertex. The
reported LCOM counted initialization steps as responsibilities.

## Decision

A private non-static method leaves the LCOM4 graph when constructor or
destructor bodies reach it through direct `$this->`, `self::` or `static::`
calls, transitively, and no other method reaches it.

1. **Candidates.** Only private instance methods reachable from `__construct`
   or `__destruct` over direct calls. Reachability goes through helpers of
   helpers. Names are effective names after trait composition:
   `__construct as init` is an ordinary method, `boot as __construct` is
   lifecycle.
2. **Public and protected methods are never candidates.** They are part of the
   class's contract and have callers this analysis cannot see, so a constructor
   call does not make them initialization. The same holds for static methods
   and for `exclude_methods` entries.
3. **Roots of ordinary use.** Every method that is not lifecycle and not a
   candidate keeps what it calls in the graph, over direct and deferred calls.
   Candidacy is independent of `exclude_methods`: an excluded public method is
   a root, and an excluded private method that only the constructor calls is
   itself a candidate.
4. **Deferred calls are a pseudo-root.** A closure, arrow function or
   first-class callable (`$this->h(...)`) created in a lifecycle body or a
   candidate runs after construction, so the method it names is behavior
   (a registered listener), not initialization. The visitor records such calls
   apart from direct ones, for every method, because trait aliases decide
   lifecycle only after composition. A private `registerListeners()` called
   only by the constructor leaves the graph; the `handle` it registers through
   a closure stays. Every closure counts as deferred, even one invoked at
   once (`array_map`): telling registration from immediate use is not worth a
   heuristic, and the approximation errs toward keeping callees in the graph.
   A candidate that hands state on also stays: it touches `$this->p` inside a
   closure, arrow function or nested function, or binds a property (or its
   element or member) by reference, which registers behavior.
5. **Removal before the stateless split.** Helpers leave before uncalled
   stateless constant methods are merged into the virtual node, so a removed
   constant helper never holds the virtual vertex open.
6. **LCOM may rise or the graph may empty.** A helper can be the only bridge
   between two property groups; removing it raises LCOM. A class whose graph
   becomes empty publishes no value. Both follow directly from excluding the
   constructor and are accepted, not compensated.
7. **Fail closed.** No method leaves the graph when the call map may be
   incomplete: `cohesion.unread-trait-count` is above zero, or the class has a
   dynamically named own call (`$this->$name()`, `$this->{expr}()`,
   `self::$name()`, `static::$name()` and their `{expr}` forms, a two-element
   array whose first element is `$this`, `self::class` or `static::class`, or
   `call_user_func` / `call_user_func_array` with `$this` first).
8. **Limitation.** Calls on another instance (`$o->m()`) and `parent::` calls
   are not recorded, so a helper used only that way counts as initialization.
   TCC/LCC are unchanged.

## Measurement

Classes with an LCOM value before the change (aggregates only):

| Corpus                                | Classes | Fell        | Rose       | Disappeared  | Unchanged |
| ------------------------------------- | ------- | ----------- | ---------- | ------------ | --------- |
| Benchmark projects (17)               | 4747    | 24 (0.51 %) | 6 (0.13 %) | 5 (0.11 %)   | 4712      |
| Private application corpus (62 roots) | 10110   | 18 (0.18 %) | 0          | 159 (1.57 %) | 9933      |
| Qualimetrix `src/`                    | 900     | 3           | 4          | 1            | -         |

In the benchmark corpus LCOM fell by 1 in 17 classes, by 2 in 6 and by 6 in
one; it rose by 1 in 4 and by 2 in 2. All 159 vanished classes of the private
corpus had LCOM 1 with a single graph method that was an initialization
helper. `composer benchmark:check` stays green, every project in range;
`health.cohesion` moved by +0.5, +0.2, +0.1, -0.1 and -0.1 on five benchmark
projects and by 0 elsewhere, `health.overall` within 0.1.

## Alternatives rejected

- **Treat constructor calls as edges.** The constructor would call every
  helper and touch most properties, re-merging every component through it.
  That is why the constructor was excluded in the first place.
- **Remove every method the constructor calls.** Public and protected methods
  have callers outside the class; dropping them would hide real
  responsibilities.

## Consequences

- LCOM4 of classes with constructor-only helpers falls or the value is
  withdrawn; a few classes rise. Thresholds are not recalibrated.
- The deviation from the original definition is documented on the cohesion
  rules page and in the Cohesion README.
