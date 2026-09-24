# 0076. A Floor Is Reported, and Said to Be One

**Date:** 2026-09-21
**Status:** Accepted

## Context

[ADR 0074](0074-dit-reads-the-ancestors-it-measures.md) made DIT follow the
external part of a chain by reading the analysed project's install rather than
loading classes into this process. It also split, internally, the three ways
that walk can end: it reaches a root, it finds no install to read at all, or it
stops partway. Only one number leaves the collector, so all three still look
alike in the report — and a class whose chain could not be followed is
indistinguishable from one that genuinely has no parent.

The question left open was whether DIT should report a value at all for a chain
it could not follow. It was named as a product decision about the metric's
contract, to be settled against a measured distribution rather than before one
existed.

That distribution was then measured through the product path, with a probe
inside `ExternalAncestry::depthOf()` and `ComposerAutoloadMap::pointAt()`:

| shape                                                     | calls | distinct parents | ReachedRoot | BrokeAt     | NoMapForIt |
| --------------------------------------------------------- | ----: | ---------------: | ----------: | ----------: | ---------: |
| qmx on its own `src/`                                     | 35    | 5                | 5           | 0           | 0          |
| 14 benchmark packages in a shared vendor                  | 111   | 68               | 58          | 10          | 0          |
| finding-gate corpus, 21 cases                             | 1     | 1                | 1           | 0           | 0          |
| constructed: no install at all                            | 1     | 1                | 0           | 0           | 1          |
| constructed: install present, name absent                 | 1     | 1                | 0           | 1           | 0          |
| constructed: package present, its parent's package absent | 1     | 1                | 0           | 1 (depth 1) | 0          |

Three facts in that table decided the rest.

**The install question is settled once per run.** `isConfigured()` is fixed in
`pointAt()` and tested before any chain is walked, so a run is entirely
"no install" or not at all. A mixed result is structurally impossible, which
rules out treating "no install" as a per-class state.

**A break deeper than the first link is ordinary.** Every break in the benchmark
corpus has depth 0, but that is a property of those installs — the missing
package was missing at the first link. Construct a partial install, where a
package is present and its own parent's package is not, and the walk returns
`brokeAt(1)`: the class below publishes 2 where a genuine root publishes 1.

**A break is an aggregate of at least six causes.** `ParentLookup::notPlaced()`
answers for four (no map entry, unreadable file, unparseable file, file declares
another name) and `ExternalAncestry::walk()` books two more, a cycle and the
visit cap.

## Decision

**DIT keeps reporting the value, and the run says when that value is a floor.**

Withholding was rejected. What the walk produces is a lower bound reached by
reading real declarations, and a lower bound is information; withholding
replaces it with nothing while costing a metric-contract change — a `Breaking`
entry, a declared gate delta, a hand-written field-move licence and a benchmark
recalibration. Nothing in the measurement shows a reader better served by the
absence of a number than by a number labelled as incomplete.

**The label is a diagnostic, not a published key.** At the end of its
`calculate()` the DIT collector logs one `warning` naming how many chains were
not followed to a root and where the walk stopped. A `Report` field would make this a published
surface — the gate's enumeration, the html-report metric-key catalog,
`default-thresholds` — for a fact that changes no number. Raising it from inside
the capability that knows it is what `Coupling\DistanceRule`,
`Duplication\DuplicationDetector` and `Measurement\MeasurementAggregationService`
already do for their own caveats — the first with `?LoggerInterface $logger = null`,
the other two with `LoggerInterface $logger = new NullLogger()`.

**The sentence claims only what is true of every way a walk can end.** It says
those chains were not followed to a root and names where the walk stopped. Each
of the obvious alternatives is false somewhere:

| wording                       | the case that refutes it                                                                          |
| ----------------------------- | ------------------------------------------------------------------------------------------------- |
| "the depth is a lower bound"  | a cycle publishes the length of a loop, which is not a depth                                      |
| "the depth was cut short"     | a builtin absent from `PhpBuiltinClassRegistry` gets a correct depth and still books this outcome |
| "reading stopped here"        | at the visit cap the class named reads perfectly well                                             |
| "install the missing package" | wrong for a cycle, for an unparseable file, and for a builtin the registry does not list          |

So the seventh cause — a genuine builtin the hand-written registry does not
list — is not merely out of scope: the warning counts it, and the wording is
chosen so that counting it is not a lie. The instance this stage measured,
`SessionHandler`, was fixed separately under ADR 0075, which keeps the list
hand-written and adds a control comparing it against the loaded extensions. That
narrows the cause rather than removing it — a list a machine's extensions can
disagree with can still be short — so the wording here does not assume the case
cannot happen.

## Consequences

The collector's `LoggerInterface` argument is **required**, against the
`= new NullLogger()` default its three siblings use, and bound by name in
`DesignConfigurator`. `LoggerInterface` is not a service id in this container —
only an alias keyed by the holder's class name — so an optional argument on a
glob-autowired collector would silently keep its default and address the
diagnostic to nobody, with every unit test still green. Required, a forgotten
registration is a container that refuses to compile. A container test asserts
the binding, because a unit test that constructs the collector by hand cannot.

Audibility is a property of `RuntimeConfigurator::configure()`, which is where
the single `setLogger()` call in `src/` is reached. `check` and
`baseline:generate` both pass through it, so both speak, and an integration
test runs both rather than trusting that they share a seam. `graph:export` does
not pass through it — and does not reach this collector at all, since it builds
a dependency graph rather than running the measurement pipeline, so there is no
line for it to drop.

The diagnostic also speaks under `--disable-rule=design.dit`, and that is
intended rather than incidental: disabling the rule suppresses findings, not the
metric, and `design.dit` is still computed and published in `--format=metrics`.
A caveat about a number the user can still read belongs with that number.

The channel has gates, and they are the same ones the neighbouring scope warning
already carries: `-q` silences it, so does `--log-level=error` with or without
`-v` (without `-v` a written level can only make the console quieter than
`WARNING`, never louder), a buffered or
`NullOutput` sink drops it, and it is absent from the machine formats' payload —
which is where CI reads this metric. The alternative was a published key for a
diagnostic. This stops being the right trade the first time someone needs the
flag inside `--format=json`.

**The finding-gate cannot carry a case that fires the warning.** A speaking case
produces a `stderr:<surface>` artifact the reference side lacks, and the gate
records a one-sided key as a mismatch before reaching any declaration — which no
declared delta can express, and which empties the whole `--derive-declared-delta`
run. The firing shapes are proved by an integration test over constructed trees
instead, and the gate keeps proving that no published number moved.

One state stays outside the report entirely: a run whose classes never extend
anything beyond the analysed path asks nothing and says nothing, whether or not
an install exists. The flag is therefore "at least one chain hit a missing
install", not "this run found no install".
