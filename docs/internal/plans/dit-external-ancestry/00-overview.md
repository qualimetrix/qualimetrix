# DIT stops executing the code it measures

## Why

Resolving a parent outside the analysed path is done by asking **this tool's**
autoloader to load a class named by the **analysed project's** source.
`class_exists($fqcn, true)` is not a test; it includes a file and runs its
top-level code. `bin/qmx` can run as a dependency of the project it analyses,
sharing its `vendor/`, so analysing an arbitrary repository executes that
repository's files inside the qmx process. An `exit` or a fatal there ends the
run with no report, and no `catch` reaches it. The step has no determinism, no
time bound and no memory bound.

## What the measurements say

|                                                      |                                                    |
| ---------------------------------------------------- | -------------------------------------------------- |
| loading calls on one project (`symfony/http-kernel`) | **195 per-file + 40 global**                       |
| across 10 benchmark projects, global pass only       | 326 calls over 90 distinct parents                 |
| of those, resolved                                   | 172 — **170 from the tool's own vendor**           |
| of those, actually deepening DIT                     | 7 calls, **2 distinct classes**                    |
| calls naming a class *inside* the analysed path      | 25 of 40 on http-kernel; 3 of 3 in the gate corpus |

So the mechanism executes analysed-project code to change very few answers, and
those come from the tool's dependency tree rather than the project's. A large
share of the calls are not about external classes at all: they are in-project
parents misrouted by a second defect, which stage 01 settles.

The 326 figure counted only the global pass. The per-file pass, which publishes
no depth and runs inside the parallel workers, makes roughly five times more
calls than that, so the case for stage 01 is stronger than the first draft of
this page claimed.


**One correction the ADR must carry.** When qmx is installed as a dependency of
the analysed project, the tool's vendor *is* the project's vendor and today's
answers are correct. The wrong-tree defect is real for a standalone install
(the Docker image, a phar, `composer global require`) and for a shared harness;
it is not universal. Parsing is correct in both shapes and executes nothing in
either.

## The chosen mechanism, and the measurement that chose it

Resolve the external part of a chain by **locating the ancestor's file from the
analysed project's own autoload map and parsing it** — never loading it. The
map is built from `vendor/composer/installed.json` (`install-path` plus
`autoload.psr-4`) and the root `composer.json`; both are data, unlike
`autoload_static.php`, which is code.

The rival candidate — extending the dependency graph over `vendor/` — is
rejected on cost: it parses an entire vendor tree to answer ninety questions
the on-demand walk answers by parsing one file per ancestor.

A first measurement of this put coverage at 88%, and it was wrong: the probe
read the benchmark harness's **shared** vendor, which is the same "measured
against the wrong tree" error this campaign exists to remove. Corrected, on two
honest shapes and following each chain rather than only locating its first link:

| shape                                         | rule "nearest `composer.json`" | rule "owner of the enclosing `vendor/`" |
| --------------------------------------------- | ------------------------------ | --------------------------------------- |
| qmx on its own `src/` (7 parents)             | 7 reach a root                 | same — it *is* the root                 |
| a library inside a shared vendor (22 parents) | 8 root, **14 no file**         | 20 root, 1 broke, 1 no file             |

**Stage 03 re-measured this on the landed code and two rows above are wrong.**
qmx's own `src/` has **5** external parents, not 7, all reaching a root — the
external non-builtin parents are `AbstractLogger`, `Application`, `Command`,
`InputDefinition` and `NodeVisitorAbstract`, cross-checked by enumerating
`extends` in `src/` without the probe. Why the prototype saw 7 was not
re-measured on the old tree; the likely cause, at medium confidence, is that it
predates stage 01, which stopped routing in-project parents to external
resolution. The shared-vendor row's "1 no map" **cannot occur** under the code
that landed: `isConfigured()` is fixed once per run before any chain is walked,
so a run is entirely no-map or not at all, and a mixed result is structurally
impossible. That row was taken on a private tree this repository does not have
and is not reproducible here.

Its "1 broke" is a different matter and **stands**. The `FileLocator` chain
itself was not re-measured — the tree it was taken on is gone — but the shape it
claims was reproduced on a constructed partial install: a package the install
carries whose own parent's package it does not, giving `brokeAt(1)` and a
published DIT of 2 where a genuine root gives 1. So a break deeper than zero is
an ordinary consequence of a partly installed `vendor/`, not a prototype
artifact. The measured distribution that replaces the rest of this table is in
[03-observability.md](03-observability.md).

Two things follow. The resolution rule is a decision, not a detail: the nearest
`composer.json` of a package inside `vendor/` describes only that package. And
a chain can end in **three** ways, not two — reaching a root, finding no map at
all, or breaking partway, measured on
`Symfony\Component\DependencyInjection\Kernel\FileLocator` (depth 1, then its
parent's file is absent). Today all three are reported as the same `0`.

A first version of that count said three chains broke, and two of the three
were a defect in the measuring prototype, which took the parent name as written
instead of resolving it: `class ResponseHeaderBag extends HeaderBag` came back
as the bare `HeaderBag` and located nothing. Corrected, one chain breaks. The
same mistake would become product behaviour if the implementation repeated it,
so stage 02 carries name resolution as a requirement rather than a footnote.

## Stages

| stage                                     | what it settles                                                                                                 |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| [01 — delete](01-delete.md)               | the per-file resolver is dead after #111; remove it, and stop sending in-project parents to external resolution |
| [02 — resolver](02-resolver.md)           | the autoload map as a subject, and the parsing walk that consumes it                                            |
| [03 — observability](03-observability.md) | keep the value, and say when it is a floor the run could not finish reading                                     |

Stage 01 is measurable without any parser and proves deletion is safe; it is
also the cheapest thing to review. It carries one item that is not about
deletion and blocks everything after it: **the gate corpus does not exercise
external resolution**. Measured across all nineteen cases, that path is entered
three times, for one in-project name. A corpus case must exist before either
stage can claim a gate result — otherwise stage 02 replaces the whole mechanism
under a green gate that never looked at it. Stage 03 is deliberately last: its channel
should be designed against a measured distribution of the three states, not
before one exists.

## What this campaign changes for users

DIT values move where a chain leaves the analysed path, and the direction
depends on the install shape. This is a metric contract change: it needs an
ADR, a `Breaking` entry naming the old and new surface, a `composer gate` run
against the commit each stage starts from, and a `benchmark:check`.
The website's "Implementation notes" section for DIT describes the loading
mechanism and becomes false in stage 02; it is rewritten in the same stage.
