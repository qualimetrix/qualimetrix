# 0073. DIT Reads the Ancestors It Measures

**Date:** 2026-09-20
**Status:** Accepted

## Context

Resolving a parent outside the analysed path was done with
`class_exists($fqcn, true)`. That is not a test. It includes the file and runs
its top-level code, and `bin/qmx` can run as a dependency of the project it
analyses, sharing its `vendor/`. So analysing an arbitrary repository executed
that repository's files inside the qmx process: an `exit` or a fatal there ended
the run with no report, and no `catch` reached it. The step had no determinism,
no time bound and no memory bound.

It also answered from the wrong tree. The autoloader consulted belonged to this
tool, not to the project being measured. Across ten benchmark projects, of 172
resolved lookups **170 came from qmx's own vendor**.

What that bought was small. The same measurement found exactly **two** distinct
classes whose depth the mechanism actually changed — `PHPUnit\Framework\TestCase`
and `Symfony\Component\Config\Loader\FileLoader` — both answered from the tool's
install rather than the analysed project's.

One correction this record keeps rather than hides: when qmx *is* installed as a
dependency of the analysed project, the tool's vendor is the project's vendor
and those answers were correct. The wrong-tree defect is real for a standalone
install — the Docker image, the phar, `composer global require` — and for a
shared harness. It was never universal.

## Decision

**The external part of an inheritance chain is followed by reading the analysed
project's own sources, never by loading them.**

A class is placed from the analysed project's composer install and its `extends`
is read by parsing. The map is built from `composer.json`, the `psr-4` sections
of `vendor/composer/installed.json`, and composer's generated
`autoload_classmap.php` — the last one parsed rather than included, because
including it would execute a file from the tree under measurement.

psr-4 alone would have been a silent half-measure: of 130 packages in this
repository's benchmark install, **25 declare a classmap and no psr-4**, and
`phpunit/phpunit` is one of them. A psr-4-only map would have dropped
`PHPUnit\Framework\TestCase` — one of the only two classes the old mechanism
usefully placed.

**Names are resolved before they are located.** `class Child extends Base` names
its parent relatively, and reading that as the bare `Base` places nothing. This
is stated as a decision because the prototype that measured this design got it
wrong first, and reported two chains as broken that were not.

**A chain ends three ways, not two.** It reaches a root, it finds no install to
read, or it breaks partway. The metric still publishes one number — the depth
actually walked — but the three are distinguishable internally, which is what a
later change can surface. Before this, all three were reported as `0` and a
reader could not tell "has no parent" from "could not be followed".

### Ownership

The port belongs to Design, which is what needs it. Placing a class and parsing
its declaration are delivery, so the implementation is an Infrastructure
adapter, and the capability imports neither composer nor a parser. This is the
shape `Cohesion`, `Coupling` and `ComputedMetrics` already use.

The first draft of this design gave the map to `Analysis\Configuration`, on the
argument that `DependencyModel` might one day want it. ADR 0022 refuses exactly
that: a hypothetical future consumer does not make a contract shared, and a port
introduced for dependency inversion belongs to its consumer. The manifest agreed
— Design has no edge to Configuration, and none of the Evidence capabilities do.

### Which install is read

From the analysed path, the nearest `composer.json` walking up; and when that
path sits inside an install, that install's owner too. The second half is not a
refinement but the difference between answering and not: a library analysed from
inside somebody else's `vendor/` carries a manifest describing only itself.
Measured on one such package, the nearest-manifest rule placed 8 of 22 parents
and the enclosing-owner rule placed 20.

The walk up is bounded by a level cap and by the filesystem root. That bounds
how far it climbs; it does not confine it to the analysed project. A path with
no `composer.json` at or under it resolves to the first manifest above it, which
may belong to a parent repository. The bound is a limit, not a boundary, and
saying otherwise would promise a containment this does not implement.

## Consequences

Every command that runs the pipeline aims the reader, because the aiming lives
in the one call they all make rather than at one call site. That is not
tidiness: while it was wired only into `check`, `baseline:generate` measured the
same tree with no install to read and recorded a DIT of 1 where `check`
reported 2 — a baseline holding a magnitude the check never produces. Review
found it; a test now holds it.

DIT deepens wherever a chain leaves the analysed path and the install can be
read. Per-class values move; so do the aggregates that summarise them, and so
does the magnitude a `design.dit` finding reports. The gate carries this as
eleven declared deltas and ten licensed field moves, all inside the one corpus
case that exercises an external parent — no other case moved.

`bin/qmx` no longer executes analysed-project code to measure inheritance
depth. That is the point of the change, and it is asserted rather than claimed:
`UnloadableClassProbe` counts autoloader queries, and the resolution path is
required to leave that count at zero.

Nothing recalibrates by default: `design.dit` appears in no built-in health
formula, no benchmark range, and — before this change — no entry of
`qmx-baseline.json`. A user-defined computed metric may read any published key,
so where someone reads `design.dit.*` their score moves with these values.

The ratchet gains two entries, both instability, for the namespaces this work
added classes to. A leaf capability that consumes contracts and is reached only
through the container is unstable by construction rather than by defect; the
entries record that as accepted residual debt rather than hiding it behind a
threshold that would have been inert.

Performance is not a rationale. External resolution was measured at 0.0002s
across 45 calls; no performance claim supports this change and none is made.
