# Stage 02 — locate and parse, never load

## Two subjects, not one

**The analysed project's autoload layout.** What its composer install declares:
which package serves which namespace prefix, and from which directory. Owned by
`Analysis\Configuration\Discovery`, where `ComposerReader` already reads the
root package's `autoload`/`autoload-dev` psr-4.

Applying the three tests rather than asserting the answer:

- *Name* — "what the analysed project's composer install declares" completes
  the sentence without naming a class or a role.
- *Co-change* — it changes when composer's layout changes (`installed.json`
  shape, a `vendor-dir` override), not when DIT changes.
- *Counterfactual ownership* — the day `DependencyModel` wants vendor reach it
  needs this same map. Under "at equal odds the code stays with its subject",
  a map that a second subject would have to copy is not Design's.

So Configuration's reader is extended, and Design consumes a declared contract.
The manifest row is a contract with one named consumer, not an internal class.

**The part of an inheritance chain that leaves the analysed path.** DIT-specific
and stays in `Analysis\Evidence\Design\Inheritance`, as `ExternalAncestry` —
the subject is the ancestors outside the path, not a "resolver" role.

`DesignConfigurator` scans `**/*Collector.php`, so this class is **not** picked
up by that mask and needs explicit registration. The earlier belief that a new
class would be autowired by the directory scan is false and was checked.

## Contracts

```
Analysis\Configuration\Contract\Discovery — extended
    interface AnalysedAutoloadMapInterface
        fileFor(string $fqcn): ?string     // by PSR-4 prefix, no loading

Analysis\Evidence\Design\Inheritance\ExternalAncestry
    depthOf(string $fqcn): ExternalDepth   // locate -> parse -> extends -> recurse
                                           // memoized per FQCN for the run

    ExternalDepth: value + one of
        ReachedRoot | NoMapForIt | BrokeAt(string $fqcn)
```

The walk parses with the php-parser the product already depends on, reads the
one `extends` of the located file, and recurses. It stops at a builtin
(`PhpBuiltinClassRegistry`), at a class with no parent, at an absent file, and
at a visit cap for a cycle.

`DitGlobalCollector::resolveExternalClassDit()` loses `class_exists()`,
`ReflectionClass` and the wide `catch (Throwable)` that existed only because
loading ran someone else's code. Nothing left in it can execute foreign code,
so the narrow-catch discipline #108 established applies to the whole method.

## The resolution rule is a decision

Measured, and it changes the answer (22 parents, library inside a shared vendor):

| rule                               | result                               |
| ---------------------------------- | ------------------------------------ |
| nearest `composer.json` walking up | 8 reach a root, **14 no file found** |
| owner of the enclosing `vendor/`   | 18 root, 3 broke partway, 1 no file  |

A package sitting inside `vendor/` has its own `composer.json` describing only
itself, so the nearest-file rule finds the package and loses its dependencies.
The rule this stage implements: walk up to the nearest `composer.json`; if the
analysed path lies inside a `vendor/` directory, also take that vendor's owning
project. When neither is found, every external chain is `NoMapForIt` and the
run says so rather than scoring zero silently — which is stage 03's subject and
the reason stage 03 follows this one.

Read `config.vendor-dir` from the root `composer.json` rather than assuming
`vendor/`.

## Enumeration this stage relies on

"Every external parent the corpus produces." Obtained by instrumenting
`resolveExternalClassDit()` and running 10 benchmark projects plus `src/`:
326 calls, 90 distinct parents, written to disk, not carried in prose.

Blind spot named: this enumerates parents the *current* mechanism reaches,
which is the set `calculateDit()` sends it. Stage 01 shrinks that set by
removing in-project classes from it, so the enumeration is re-taken after
stage 01 lands rather than reused. A list taken for one question is not
evidence for the next.

## Test plan

- `queryCount() === 0` and `failedOnTheMissingParent() === false` for both
  collectors: with loading gone, the probe's autoloader is never asked. This is
  the oracle the probe was built for; it is not adjusted to stay green.
- A fixture whose ancestor chain is reachable only by file, never by autoload,
  proves parsing is what resolves it.
- The three outcomes each get a case, including a chain that breaks partway —
  `ResponseHeaderBag` is a measured real example to model it on.
- A parent whose file exists but whose `extends` names an absent class must be
  `BrokeAt`, not `ReachedRoot`: the difference is the whole point of the state.
- Determinism: two runs over the same tree agree, and `--workers=0` agrees with
  `--workers=4`.

## Definition of Done

1. `bin/qmx` executes no analysed-project code: the probe's `queryCount()` is 0
   in every case that touches external resolution.
2. `composer check` green; `composer gate` against the stage's base with the
   delta declared and the derived diff read line by line.
3. `benchmark:check`; ADR; `Breaking` entry naming old and new surface.
4. The website's DIT "Implementation notes" rewritten in this stage, EN and RU
   together — it currently describes loading and becomes false here.
