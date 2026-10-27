# 0137. Constructor-Only Helpers Leave the LCOM4 Graph

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
   callable reference created in a lifecycle body or a candidate runs after
   construction, so the method it names is behavior (a registered listener),
   not initialization. A private `registerListeners()` called only by the
   constructor leaves the graph; the `handle` it registers through a closure
   stays. Every closure counts as deferred, even one invoked at once
   (`array_map`): telling registration from immediate use is not worth a
   heuristic, and the approximation errs toward keeping callees in the graph.
   The visitor records deferred calls apart from direct ones, for every method,
   because trait aliases decide lifecycle only after composition.
5. **A reference protects; it is not an edge.** LCOM4 and TCC/LCC edges stay
   exactly the forms that existed before this change: `$this->m()`,
   `self::m()`, `static::m()`, their first-class callables, also inside
   closures. Any other reference to an own method only protects its target from
   removal as an initialization helper: calls through the own fully qualified
   name or `$this::`, `$this->{'m'}()`, `self::{'m'}()`, a keyless two-element
   array or `new ReflectionMethod(X, 'm')` with `X` one of `$this`,
   `self::class`, `static::class`, the own `::class`, `$this::class`,
   `__CLASS__`, `get_class($this)`, `get_class()`, `get_called_class()` or the
   strings `'self'`, `'static'`, the own name, and the callable strings
   `'self::m'`, `'static::m'`, `'App\Own::m'`. Matching is deliberately broad:
   a false reference only keeps a helper, while a missed one removes a method
   that is behavior.
6. **Hand-off keeps a helper.** A candidate that hands state on registers
   behavior and stays: it touches a property inside a closure, arrow function
   or nested function (by name or dynamically); binds a property, or its
   element or member, by reference (either side of `=&`, an array element, a
   `foreach` value, destructuring); or is a generator (`yield`, `yield from`).
7. **Removal before the stateless split.** Helpers leave before uncalled
   stateless constant methods are merged into the virtual node, so a removed
   constant helper never holds the virtual vertex open.
8. **LCOM may rise or the graph may empty.** A helper can be the only bridge
   between two property groups; removing it raises LCOM. A class whose graph
   becomes empty publishes no value. Both follow directly from excluding the
   constructor and are accepted, not compensated.
9. **Fail closed.** No method leaves the graph when the call map may be
   incomplete: `cohesion.unread-trait-count` is above zero; a non-literal name
   appears in an own call or in any reference of decision 5; a string or
   interpolated string starts with an own prefix plus `::` without being a plain
   callable string; or a concatenation starts with the own class name.
10. **Limitations.** Not recorded, so a helper used only that way counts as
    initialization: `$o->m()` on another instance (including `$self = $this`),
    `$this?->m()`, `parent::`, the short class name, callable strings built by
    `sprintf()` or from a variable class name, `getMethod('m')`, a property
    passed by reference as an argument, and an object taken from a property and
    captured by value in a static closure. `call_user_func($this)` reaches only
    the public `__invoke`, an ordinary root.

## Measurement

Classes with an LCOM value before the change (aggregates only); TCC and LCC
changed on no class in any corpus:

| Corpus                                | Classes | Fell        | Rose       | Disappeared  | Unchanged |
| ------------------------------------- | ------- | ----------- | ---------- | ------------ | --------- |
| Benchmark projects (17)               | 4747    | 25 (0.53 %) | 6 (0.13 %) | 5 (0.11 %)   | 4711      |
| Private application corpus (62 roots) | 10110   | 18 (0.18 %) | 0          | 159 (1.57 %) | 9933      |
| Qualimetrix `src/`                    | 900     | 3           | 4          | 1            | -         |

In the benchmark corpus LCOM fell by 1 in 18 classes, by 2 in 6 and by 6 in
one; it rose by 1 in 4 and by 2 in 2. All 159 vanished classes of the private
corpus had LCOM 1 with a single graph method that was an initialization
helper. `composer benchmark:check` stays green; `health.cohesion` moved by
+0.5, +0.2, +0.1, -0.1 and -0.1 on five benchmark projects and by 0 elsewhere,
`health.overall` within 0.1.

## Alternatives rejected

- **Treat constructor calls as edges.** The constructor would call every
  helper and touch most properties, re-merging every component through it.
  That is why the constructor was excluded in the first place.
- **Remove every method the constructor calls.** Public and protected methods
  have callers outside the class; dropping them would hide real
  responsibilities.

- **Make every own-method reference an LCOM4 edge.** Tried and rejected: it
  moved values beyond the issue. On the benchmark corpus 91 classes fell
  (instead of 25), mostly from callable-array route callbacks in one
  WordPress-style project's REST controllers, and TCC/LCC changed too. A
  reference therefore protects its target and adds no edge.

## Consequences

- LCOM4 of classes with constructor-only helpers falls or the value is
  withdrawn; a few classes rise. Thresholds are not recalibrated.
- The scope adaptation is documented on the cohesion rules page and in the Cohesion README.
