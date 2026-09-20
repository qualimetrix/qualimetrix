# Stage 02 — locate and parse, never load

## Two subjects, and where each belongs

**The part of an inheritance chain that leaves the analysed path.** DIT-specific,
and it stays in `Analysis\Evidence\Design\Inheritance` as `ExternalAncestry` —
the subject is the ancestors outside the path, not a "resolver" role.

**Where a class's file is, according to the analysed project's install.** This
is the contested one, and the plan's first answer was wrong.

The first draft gave it to `Analysis\Configuration\Discovery`, where
`ComposerReader` already reads the root package's psr-4, arguing that
`DependencyModel` would one day want the same map. ADR 0022 refuses exactly
that argument: a hypothetical future consumer does not make a contract shared,
and a port introduced for dependency inversion belongs to **its consumer**.
Today the only consumer is Design.

Measured against the manifest rather than argued: Design's approved edges today
reach `DependencyModel`, `Measurement`, `Finding` and `Core.*` — there is no
edge to `Configuration`, and `ComposerAutoloadPathReaderInterface` is consumed
only by `Analysis.Run` and by Infrastructure. Introducing Design →
Configuration would be a new cross-owner edge of a kind no Evidence capability
has.

The layout the repository already uses for this shape: the capability owns the
port, Infrastructure supplies the adapter. That is not novel here —
`Cohesion`, `Coupling` and `ComputedMetrics` each own contracts that
Infrastructure consumes.

```
Analysis\Evidence\Design\Inheritance\Contract
    interface AnalysedAutoloadMapInterface
        fileFor(string $fqcn): ?string

Infrastructure\...        the adapter that builds the map from the analysed
                          project's install and implements that port

Analysis\Evidence\Design\Inheritance\ExternalAncestry
    depthOf(string $fqcn): ExternalDepth
```

`DesignConfigurator` scans `**/*Collector.php`, so neither class is picked up
by that mask; both need explicit registration. The earlier belief that a new
class would be autowired by the directory scan is false and was checked.

## The map must cover more than psr-4

Measured on `benchmarks/vendor/composer/installed.json`, 130 packages:

| declares                  | packages |
| ------------------------- | -------- |
| psr-4                     | 102      |
| classmap                  | 37       |
| **classmap and no psr-4** | **25**   |
| psr-0                     | 0        |

The decisive case is not incidental. `phpunit/phpunit` declares only `files`
and `classmap: ['src/']`, and `PHPUnit\Framework\TestCase` lives at
`src/Framework/TestCase.php`. That FQCN is one of the **two** classes on which
today's loading mechanism actually deepens DIT. A psr-4-only `fileFor()` would
silently drop half of the only measured benefit this campaign promises to keep.

Composer generates `vendor/composer/autoload_classmap.php`, a literal
`return array(...)`. It is **parsed**, never included: php-parser over it
yields 1334 entries with `PHPUnit\Framework\TestCase` among them and nothing
executed. Its values are `$vendorDir . '/…'` concatenations, so the adapter
resolves `$vendorDir`/`$baseDir` from the file's own location — deterministic,
but a resolution step rather than a literal read.

`codeigniter/framework` and `johnpbloch/wordpress-core` declare no autoload at
all. For them every external chain has no map, which is correct and is why
stage 03 exists.

## Names must be resolved before they are located

The prototype that produced this plan's first chain numbers read
`$class->extends->toString()` without php-parser's `NameResolver`, so
`class ResponseHeaderBag extends HeaderBag` yielded the bare `HeaderBag`, which
located nothing and was counted as a broken chain. Corrected, shape 2 goes from
18/3/1 to **20 root, 1 broke, 1 no-map**.

This is an implementation requirement, not only a correction: the adapter
resolves `use` statements and the enclosing namespace before locating, or it
reproduces that bug as product behaviour on every relatively-written parent —
which is most of them.

## The resolution rule, and its boundary

| rule                               | library inside a shared vendor, 22 parents |
| ---------------------------------- | ------------------------------------------ |
| nearest `composer.json` walking up | 8 root, 14 no file                         |
| owner of the enclosing `vendor/`   | 20 root, 1 broke, 1 no file                |

A package inside `vendor/` has a `composer.json` describing only itself, so the
nearest-file rule finds the package and loses its dependencies.

The rule: from the analysed path, walk up to the nearest `composer.json`; if
the analysed path lies inside a directory named by some ancestor's
`config.vendor-dir` (default `vendor`), take that ancestor too. Read
`vendor-dir` from each candidate rather than assuming the name.

Two limits this rule needs and the first draft did not state:

- **A stop boundary.** Walking up without one reads the `composer.json` of a
  project that merely happens to contain the analysed path — a parent
  repository, or `$HOME`. The walk stops at the analysed root the run was given,
  at a filesystem boundary, and after a fixed number of levels.
- **A monorepo has several.** More than one `composer.json` above the analysed
  path is normal; the rule must say which wins rather than merge silently, and
  must not claim both "nearest" and "enclosing vendor" as one thing when they
  disagree.

## Reading paths that the analysed project chose

The map's values come from a file inside the analysed tree, so they name paths
that tree controls. The adapter reads only files the map resolves to, refuses
paths escaping the project root after normalisation, and never follows a value
outside it. The old mechanism had the same exposure and worse — it *executed*
what it found — but the boundary was never stated, so it is stated here.

## What the chain walk does

Locate, parse, read the one `extends`, recurse, memoised per FQCN for the run.

It stops at four conditions, and `ExternalDepth` carries three states, so the
mapping is written out rather than assumed:

| stop                                  | state                                                  |
| ------------------------------------- | ------------------------------------------------------ |
| a builtin (`PhpBuiltinClassRegistry`) | ReachedRoot                                            |
| a class with no parent                | ReachedRoot                                            |
| no file for the parent                | BrokeAt, or NoMapForIt when nothing was located at all |
| visit cap reached (a cycle)           | BrokeAt                                                |

`NoMapForIt` is "this run has no autoload map", which is different from "the
map has no entry for this name"; the first is a property of the run, the second
of one lookup.

`DitGlobalCollector::resolveExternalClassDit()` loses `class_exists()`,
`ReflectionClass` and the wide `catch (Throwable)` that existed only because
loading ran someone else's code. Nothing left can execute foreign code, so
#108's narrow-catch discipline applies to the whole method.

## The oracle has to be stronger than the probe

`UnloadableClassProbe::queryCount() === 0` proves the tool did not ask *that*
autoloader. It does not prove no analysed code ran: a direct `require`, or a
subprocess, bypasses the probe entirely.

So the test asserts the absence of the mechanism, not only the silence of the
probe: the resolution path contains no `class_exists`, `interface_exists`,
`ReflectionClass`, `require`/`include` reachable from `ExternalAncestry`, and
the probe's counter stays zero besides. A guard over the resolution path's
source is the cheap half; the probe is the behavioural half; neither alone is
the claim.

## Enumeration this stage relies on

"Every external parent the corpus produces", instrumented from both collectors
and written to disk. Re-taken **after stage 01 lands**, because stage 01 removes
the in-project names from that set — on http-kernel 25 of 40, on the gate
corpus 3 of 3. A list taken for one question is not evidence for the next.

## Test plan

- The corpus case added in stage 01 exercises this path; without it the gate
  says nothing about this stage.
- A fixture whose ancestor is reachable by file and not by autoload proves
  parsing is what resolved it.
- A classmap-only parent resolves — `PHPUnit\Framework\TestCase` is the
  measured example.
- A relatively-written parent resolves, which is what `NameResolver` buys.
- Each of the three states has a case, including a chain that breaks partway;
  `Symfony\Component\DependencyInjection\Kernel\FileLocator` is the measured
  real example.
- Determinism: two runs agree; `--workers=0` agrees with `--workers=4`.

## Definition of Done

1. No analysed-project code executes: the source guard and the probe both hold.
2. `composer check` green; `composer gate` against this stage's base **with the
   corpus case present**, the delta declared, the derived diff read line by line.
3. `benchmark:check`; ADR; `Breaking` entry naming old and new surface.
4. The DIT "Implementation notes" on the website rewritten, EN and RU together.
5. Re-check `@qmx-ignore health.cohesion` on `DitGlobalCollector`: injecting a
   collaborator may lift cohesion above the threshold and make the suppression
   inert, which `bin/qmx directives` fails with exit 2 inside `composer check`.
