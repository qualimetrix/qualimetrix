# Stage 03 — tell "could not measure" from "measured zero"

## The question

An unresolvable ancestor scores like a root class. So does a class that
genuinely has no parent. Stage 02 split the outcomes internally
(`ReachedRoot`, `NoMapForIt`, `BrokeAt`); the report still shows one number and
says nothing about which happened. This stage decides what a user sees.

## The count the stage was waiting for

Measured on `ba5fdc66` through `bin/qmx check`, by a probe inside
`ExternalAncestry::depthOf()` and `ComposerAutoloadMap::pointAt()` — the
product path, not a prototype.

| shape                                                     | calls | distinct parents | ReachedRoot | BrokeAt         | NoMapForIt |
| --------------------------------------------------------- | ----: | ---------------: | ----------: | --------------: | ---------: |
| qmx on its own `src/`                                     | 35    | 5                | 5           | 0               | 0          |
| 14 benchmark packages in a shared vendor                  | 111   | 68               | 58          | 10              | 0          |
| gate corpus, 21 cases                                     | 1     | 1                | 1           | 0               | 0          |
| constructed: no install at all                            | 1     | 1                | 0           | 0               | 1          |
| constructed: install present, name absent                 | 1     | 1                | 0           | 1               | 0          |
| constructed: package present, its parent's package absent | 1     | 1                | 0           | 1 (**depth 1**) | 0          |

By calls the benchmarks are 93 `ReachedRoot` / 18 `BrokeAt`. Distinct parents
understate `BrokeAt`'s reach up to fivefold — `symfony/routing` is two distinct
parents but ten affected child declarations — so **calls, not distinct parents,
is the unit this stage reports in**. Both remain lower bounds on affected
classes: in-project descendants inherit the floor through `deepestOf` and are
not counted.

Four results carry the design.

1. **`NoMapForIt` is a run-level state, not a chain state.** `isConfigured()`
   is `roots !== []`, fixed once in `pointAt()`, and `walk()` tests it before
   touching any chain. Every chain in a run is `NoMapForIt` or none is. The
   overview's "20 root / 1 broke / 1 no map" mix cannot occur under stage 02's
   code.

2. **A `BrokeAt` deeper than zero is real, not hypothetical.** All ten breaks
   in the benchmark corpus have depth 0, because there the missing package was
   missing at the first link. Construct the other shape — a package the install
   carries, whose own parent's package it does not — and the walk returns
   `brokeAt(1)`, published as DIT 2 where a genuine root would publish 1.
   Measured, not argued. This is the ordinary shape of a partially installed
   `vendor/`, and it settles a contradiction the campaign carried: the
   overview's `FileLocator` row was right about depth>0 being reachable in the
   wild, whatever the rest of that prototype's numbers were worth.

3. **`BrokeAt` is an aggregate of at least six causes.** `ParentLookup::notPlaced()`
   returns for four (no entry in the map, unreadable file, unparseable file,
   file declares another name); `walk()` books `BrokeAt` for two more, a cycle
   and the visit cap. A seventh is a defect elsewhere: `PhpBuiltinClassRegistry`
   omits `SessionHandler`, so a genuine builtin books a break. One of the ten
   measured breaks is that. The docblock on `notPlaced()` already says a channel
   explaining *why* a chain stopped would need the distinction widened.

4. **The walk moves numbers, but not on this tree.** 21 of 58 distinct roots
   (36%) have depth > 0 — laravel 15, doctrine/orm 5, monolog 1. On qmx's own
   `src/` it is 0 of 5. Dogfooding cannot observe this feature working.

## The decision

**Keep the value. Do not change the metric's contract. Publish no new key.**

The open question the stage carried was whether DIT should withhold a value for
a chain it could not follow. It should not. What the walk produces is a **floor**
— a lower bound reached by reading real declarations — and a lower bound is
information. Withholding replaces it with nothing, and buys that with an ADR, a
`Breaking` entry, a declared gate delta, a hand-written `declared-field-moves.tsv`
row and a benchmark recalibration. Result 2 forbids the cheaper argument that an
earlier draft of this page used: with a depth>0 break the floor is *not* the same
number a genuine root publishes, so the two are not interchangeable — but that
makes the floor more informative, not less.

What is missing is not a different number. It is the sentence that the number is
a floor, and which classes it applies to. That sentence is about **what this run
could read**, not about the analysed code — the constraint stage 02 handed over
("must not make `NoMapForIt` look like a defect in the analysed project").

So: a run-level diagnostic on the error stream, in the channel `CheckCommand`
already owns for `ScopeWarningChecker`'s coupling caveat. **Not** a `Report`
field: that is a published, machine-readable surface (gate enumeration,
html-report metric-key catalog, `default-thresholds`) and a diagnostic that
changes no number does not belong there.

**The price of that channel, named rather than discovered later.** `ErrorStream`
drops diagnostics where the output has no error stream of its own — a buffer, a
`NullOutput`, an embedder. The line also does not survive `-q` and is absent from
the machine formats, which is where CI reads this metric. `ProjectScopeCoverage`
already carries this cost in a comment for the neighbouring warning. It is
accepted here for the same reason: the alternative is a published key for a
diagnostic. It stops being acceptable the first time someone needs the floor
flag in `--format=json`; that is a new decision with a demand behind it, and it
should be recorded as such rather than rediscovered.

## What is deliberately *not* done

The existing "No composer.json found" warning is **left exactly as it is**. An
earlier draft folded it into this stage's predicate, on the ground that
`roots === []` implies no manifest at `projectRoot`. That implication is true and
its converse is false: `InstallLocator::rootsFor()` walks the analysed paths
before `projectRoot`, so a map is routinely configured while `projectRoot` has no
manifest — `cd /elsewhere && bin/qmx check /repo/src` is that shape. The
warning's real consumers, `ProjectNamespaceResolver` and `ProjectScopeCoverage`,
read `projectRoot/composer.json` specifically; re-pointing the warning at a
DIT-shaped predicate would silence it exactly where it is right. Two questions,
two predicates, two owners.

## Where the sentence is produced

The fact is stated where it is known, by the logger the repository already uses
for exactly this — a metric caveat raised from inside an Evidence capability.
`Coupling\DistanceRule:69,108` takes `LoggerInterface $logger = new NullLogger()`
and calls `->warning()`; `Duplication\DuplicationDetector:54` and
`Measurement\MeasurementAggregationService:37` carry the same parameter in the
same shape. Nothing new is invented.

```
DitGlobalCollector::__construct(
    ExternalAncestry $externalAncestry,
    LoggerInterface $logger,        // second parameter, not ninth, and REQUIRED
)
```

The parameter is required deliberately, against the `= new NullLogger()` default
its three siblings use. See the wiring note below: with a default, a missing
registration line disables the feature in silence; without one, the container
refuses to compile. Reject beats silence.

At the end of `calculate()` the collector logs at most one `warning` naming how
many chains this run could not read to their end and the distinct classes where
reading stopped.

**"Could not read to the end" is the phrase, not "is a floor."** The floor claim
is true for the four `notPlaced()` causes and false for the other two: for a
cycle the steps walked are the length of a loop, which `walk()`'s own comment
says is not a depth, and for the visit cap 64 is a fact about the tool. One
sentence has to be true for all six outcomes, so it states what the run could
not do and names where; the lower-bound reading is left where it is provable. Outcomes are accumulated in
a local of that one call, so there is **no object state and therefore no reset
seam**: `calculate()` builds a fresh `InheritanceDepthResolver` every time, and
it runs even when `design.dit` is disabled by `--disable-rule` (measured).

`InheritanceDepthResolver` does change: it is the only place that sees an
outcome on the way to a depth, so it reports each one to an accumulator the
collector passes in. `ExternalDepth`, `ExternalChainOutcome`, `depthOf()` and
`ExternalAncestry` keep their signatures.

The missing-install case is read from the outcomes — did any chain return
`NoMapForIt` — not by asking a predicate a second time. That keeps the invariant
observable rather than tautological, and avoids the fact that `isConfigured()`
is not on `AnalysedInstallAnchorInterface` at all (it carries only `pointAt()`).

**The dichotomy has a third state, and the flag is named for what it observes.**
`walk()` is reached only from `InheritanceDepthResolver:166`, the branch for a
parent known to be outside the analysed path; four earlier branches return
first. A tree whose classes all have no parent, a builtin parent or an
in-project parent never calls it, so a run with no install observes zero
`NoMapForIt`. That is harmless — there is nothing to warn about — but it means
the flag is "at least one chain hit a missing install", not "this run found no
install", and it must be named that way. The consequence for tests is concrete:
a fixture proving the no-install sentence must contain a class with an external
parent, or the test passes for the wrong reason and no mutation reddens it.

**The wiring is the part that fails silently, so it is the part with a test.**
`LoggerInterface` is registered only with `registerAliasForArgument(DelegatingLogger::class, ...)`
(`CoreServicesConfigurator:59`), which binds to an argument *named* after that
service, not to any `$logger`. `DitGlobalCollector` is picked up by the
`**/*Collector.php` glob with plain autowiring, so an unbound `$logger` would
quietly keep its `NullLogger` default and the warning would never be seen by
anyone. Rules avoid this through `RuleOptionsCompilerPass:155`; collectors do
not have that path. `DesignConfigurator` therefore binds the argument
explicitly, exactly as `DuplicationConfigurator:48` does
(`'$logger' => new Reference(DelegatingLogger::class)`), and a container test
asserts the collector receives a real logger rather than the default.

### The alternative that was rejected, and why

An earlier draft routed the fact through a Design-owned ledger contract and a VO
read by `CheckCommand` and `BaselineRun`. Every seam it needed is already at its
own declared ceiling: `RuntimeConfigurator` carries eight arguments under a
docblock saying "the ceiling is one slot: a ninth parameter reports again",
`CheckCommand` carries nine, and `AnalysisResult` carries eight under
`warning=9 error=9`. Taking one of those slots would have moved the ratchet for
a diagnostic. This repository reads a threshold at its ceiling as a signal to
refactor, not as a slot to spend, and the logger removes the need entirely.

**The price of this channel, named rather than discovered later.** The console
logger is built only when the diagnostic writer is not quiet
(`LoggerFactory:44`), so `-q` silences the line; the default console level is
`WARNING`, so it does appear without `-v`. It goes to the run's diagnostic
writer, not to stdout, so machine formats stay parseable — and it is absent from
their payload, which is where CI reads this metric.
A second gate is the logger's own level: `-v --log-level=error` raises the floor
above `WARNING` and silences the line, which plain `-v` does not.
`ProjectScopeCoverage:110-115` already records this same class of cost for the
neighbouring warning. It is accepted for the same reason: the alternative is a
published key for a diagnostic. It stops being acceptable the first time someone
needs the flag inside `--format=json`, which is a new decision with a demand
behind it.

**Audibility is a property of the seam, not of the command.** `LoggerHolder`
starts holding a `NullLogger`, and the single `setLogger()` call in `src/` is
reached only through `RuntimeConfigurator::configure()`. `check` and
`baseline:generate` both pass through it, so both hear the line — but that is
why they hear it, not because they publish DIT. `graph:export` runs the pipeline
without `RuntimeConfigurator` and would drop the line entirely; it publishes no
DIT, so this stage accepts that and names it rather than discovering it later.

## Work packages

| #   | subject                        | files                                                                                                                                                                                                          |
| --- | ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P1  | the sentence and its wiring    | `DitGlobalCollector.php`, `InheritanceDepthResolver.php`, `DesignConfigurator.php`, their tests plus a container test                                                                                          |
| P2  | the shapes that prove it fires | an integration test covering three constructed trees: no install with an external parent, install present with the name absent, and a package present whose own parent's package is not                        |
| P3  | records                        | `00-overview.md` (including its stages-table row), `02-resolver.md:189-191`, `plans/README.md`, `src/Analysis/Evidence/Design/README.md`, `website/docs/rules/design.md` + `.ru.md`, `CHANGELOG.md`, a new ADR |

No console file is touched: `CheckCommand`, `BaselineRun`, `RuntimeConfigurator`
and `OutputConfigurator` are all out of scope, and `baseline:generate` is covered
because it passes through the same `RuntimeConfigurator::configure()`.

No new production declaration is introduced, so the hand-written
`docs/internal/modular-architecture-manifest.json` needs no entry: it is keyed by
declaration, and `Coupling\DistanceRule`, which imports the same
`Psr\Log\LoggerInterface`, carries no special record for it. Run
`composer architecture:check` anyway — registration addresses redden one per run.

An ADR is required by the repository's own rule: the decision is non-obvious and
rejects a named alternative (withholding the value).

## Test plan

Each check must be observed red under a planted mutation before it is believed.

- The collector logs **once** per `calculate()`, and nothing at all when every
  chain reaches a root.
- The count is of **child declarations**, not distinct parents: two children of
  one unresolvable parent count two. `depthOf` is memoized by the child
  declaration's canonical form, which is what makes that the unit.
- The message names every class where reading stopped and claims only that the
  run could not read those chains to their end — no instruction to install
  anything, and no "floor" for a cycle or a capped walk.
- A cycle and a visit-cap exhaustion are counted, and the sentence stays true of
  them. These two are the witnesses that the wording is right, because they are
  the outcomes a looser wording would lie about.
- **The collector receives a real logger from the built container.** This is the
  wiring that fails silently, so it is asserted against the container, not
  against a hand-made object. With the parameter required, the negative case is
  a compile failure rather than a quiet default, and that is asserted too.
- `baseline:generate` emits the sentence on a no-install tree **whose fixture
  contains a class with an external parent** — without that the run walks no
  chain, observes nothing, and the test would pass for the wrong reason.

## What the gate can and cannot prove

**A corpus case that fires the warning would make the gate unfixable, so this
stage does not add one.** `TreeRun::capture()` writes a `stderr:<surface>`
artifact only when stderr is non-empty, so a case that speaks on the candidate
and is silent on the reference produces a key one side does not have. `Gate`
compares over the union of keys, and for a one-sided key it records
`SURFACE_MISMATCH` and `continue`s — **before** the derive branch and before
`declaredDelta->claim()`. A declared delta stores the diff of two artifacts that
both exist; it cannot express "a surface appeared". Worse,
`deriveDeclaredDelta()` returns an empty list whenever the report is not green,
so one undeclarable case writes nothing at all and kills the whole derive run.
An earlier draft's DoD ("its delta derived and every reason filled in") was
therefore unreachable in principle.

So the proof of firing moves to an integration test, beside the one this stage
already needs for the no-install and depth-1 shapes, and the gate keeps the job
it is good at: proving **no published number moved**. That expectation is GREEN
with no declared delta — and this time for a reason that holds: measured across
all 21 cases, the corpus produces `ReachedRoot` once and the other two outcomes
never, so no case speaks and no new surface appears.

This also removes a second problem before it starts. `ConsoleLogger` prefixes
every line with `[H:i:s]`, and the gate runs the candidate twice and refuses on
`NONDETERMINISM_UNDECLARED` when two runs differ after normalization. A timestamp
differs between runs, so a speaking corpus case would have required a
`--derive-normalization` pass *before* the delta pass, with a locator anchored on
the exact message text. With no speaking case, no stderr line reaches the gate
and none of that is needed.

**`NoMapForIt` cannot be covered by the corpus either.** The binding constraint
is the run's `projectRoot`, not the walk depth: `rootsFor()` consults
`projectRoot` as well as the analysed paths, and a gate case's `projectRoot` is
its own directory, which carries a `composer.json` — all 21 do. It is covered by
the same integration test.

The baseline is clean. On `ba5fdc66` the gate refused 55 times on an unchanged
tree, all of it stale one-shot declarations from two landed campaigns; they were
removed in this branch's first commit and the gate is GREEN against itself.
Stage 03's own number is read directly, with nothing to subtract.

## Out of scope

- **`PhpBuiltinClassRegistry` omits `SessionHandler`**, a real ext-session
  builtin, so `NativeFileSessionHandler` books a `BrokeAt` where the answer is
  "builtin, depth 1". One of the ten measured breaks is this. The new warning
  over-reports by it until the registry is fixed; that is a `Core` defect with
  its own oracle and its own change.
- **Widening `ParentLookup` to say why a chain stopped.** Its docblock asks for
  it and result 3 shows the need, but a warning that names classes is honest
  without it.
- Three benchmark packages abort at the default memory limit in
  `DuplicateBlockFinder` / `CircularDependencyDetector`. Reproduced without the
  probe; unrelated.

## Definition of Done

- The sentence lands with tests observed red first, including the container test
  that proves the logger is bound.
- Both commands that produce DIT emit it, because both pass through
  `RuntimeConfigurator::configure()`, which is the seam that makes it audible;
  it blames no analysed code and prescribes no action result 3 shows to be
  wrong.
- The existing composer.json warning is untouched, and
  `ProjectScopeCoverage:110-115` remains true.
- `composer check` green, whole.
- `composer gate -- --reference=<this branch's first commit>` GREEN with no
  declared delta. A red gate here means a published number moved, which is the
  one thing this design exists to avoid.
- `benchmark:check` green — no published number should move, so a move is a
  finding.
- `composer architecture:check` green.
- The logger parameter is required and bound in `DesignConfigurator`; removing
  the binding fails container compilation rather than disabling the feature.
- Review of the implementation.
