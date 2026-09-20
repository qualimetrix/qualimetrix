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

|                                    |                                                   |
| ---------------------------------- | ------------------------------------------------- |
| calls across 10 benchmark projects | 326, over 90 distinct parents                     |
| of those, resolved                 | 172 — **170 from the tool's own vendor**          |
| of those, actually deepening DIT   | 7 calls, **2 distinct classes**                   |
| qmx analysing its own `src/`       | 7 distinct parents, **2 of them its own classes** |

So the mechanism executes analysed-project code 326 times to change 7 answers,
and its answers come from the tool's dependency tree rather than the project's.

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
| a library inside a shared vendor (22 parents) | 8 root, **14 no file**         | 18 root, 3 broke, 1 no file             |

Two things follow. The resolution rule is a decision, not a detail: the nearest
`composer.json` of a package inside `vendor/` describes only that package. And
a chain can end in **three** ways, not two — reaching a root, finding no map at
all, or breaking partway, measured on
`Symfony\Component\HttpFoundation\ResponseHeaderBag` (depth 1, then its
parent's file is absent). Today all three are reported as the same `0`.

## Stages

| stage                                     | what it settles                                                                                                 |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| [01 — delete](01-delete.md)               | the per-file resolver is dead after #111; remove it, and stop sending in-project parents to external resolution |
| [02 — resolver](02-resolver.md)           | the autoload map as a subject, and the parsing walk that consumes it                                            |
| [03 — observability](03-observability.md) | make the three chain outcomes distinguishable                                                                   |

Stage 01 is measurable without any parser and proves deletion is safe; it is
also the cheapest thing to review. Stage 03 is deliberately last: its channel
should be designed against a measured distribution of the three states, not
before one exists.

## What this campaign changes for users

DIT values move where a chain leaves the analysed path, and the direction
depends on the install shape. This is a metric contract change: it needs an
ADR, a `Breaking` entry naming the old and new surface, a `composer gate` run
against the commit each stage starts from, and a `benchmark:check`.
The website's "Implementation notes" section for DIT describes the loading
mechanism and becomes false in stage 02; it is rewritten in the same stage.
