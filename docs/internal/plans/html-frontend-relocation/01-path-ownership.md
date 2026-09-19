# Stage 01 — make the distance to the root checkable

Lands and is proved **before** anything moves. After it, every hop count that
crosses the viewer's boundary is guarded by a control that refuses when it is
wrong, and the two JS copies of that hop have become one.

## What review changed about this stage

The first draft introduced a PHP value object that owned the asset paths. Two
measurements killed it, and they are worth keeping because both are invisible
from the file being refactored:

- **The shipping guard derives its expectation by regex over the formatter's
  source.** `HtmlReportShipsOnlyWhatItReadsTest.php:78` runs
  `preg_match_all('#\$templateDir \. \'(/[^\']+)\'#')` over
  `HtmlFormatter.php`, and line 41 refuses on an empty result. A value object
  that joins the asset name removes both the variable and the concatenation, so
  the guard reads zero assets and goes red — which would have made stage 01's
  own "green `composer check`" unreachable, and deadlocked stage 02, whose P2
  owns that guard's file and cannot start until stage 01 is green.
- **Every production declaration is pinned in the manifest by name.**
  `generate-modular-architecture-production-inventory.php:805` compares the AST's
  class list against the manifest's and refuses on any difference. A new class
  under `src/Reporting/` is therefore a manifest change plus an artifact
  regeneration — work stage 01 claimed not to contain.

Both point the same way: **PHP does not have a multiplicity problem.** Measured,
`src/Reporting/` contains exactly one `dirname(__DIR__` — the formatter's. There
is already one owner; what is missing is a check on its depth. JS is the side
with two copies, and only JS gets a new module.

## What changes

**JS — two copies become one.** `metric-key-catalog.mjs:17` and
`collect-metric-keys.mjs` each carry `resolve(__dirname, '..' × 4)`. One module
beside them owns it; neither computes hops afterwards.

```
// src/Reporting/Template/scripts/repo-root.mjs
export const REPO_ROOT;            // resolved once, from this module's own place
export function fromRoot(...parts);
```

Its location is fixed here and not left to the executor: it sits beside its two
consumers, so stage 02 moves it with them and the hop count stays one.

**PHP — nothing is refactored.** The formatter keeps `$templateDir` and its
concatenations, because the shipping guard reads them. The refusal message at
`HtmlFormatter.php:83` still advises `cd src/Reporting/Template && npm run
build`; that string is stage 02's to repoint, listed in its P1.

**Node becomes a declared dependency of the default check.** The control below
executes node. `scripts/init-environment.sh` installs neither node nor npm —
measured, 0 mentions — so in the web environment `composer test:js` already does
not run, and this stage would newly redden `composer test` there too. The
install goes into that script **in this stage**, because this is the stage that
introduces the dependency. Stage 02 explicitly does not touch it.

## The control

One control, in the `Governance` suite, asserting that each side's arithmetic
still lands on the repository root.

**What it asserts.** It establishes the root **without counting directories** —
by walking up until it finds the one directory holding both `composer.json` and
`.gitattributes`, a pair that occurs exactly once in the tree (measured: 27
`composer.json`, 1 `.gitattributes`). Then:

- the directory the formatter computes is that root's `src/Reporting/Template`,
  and every asset it reads exists there;
- the root the JS module resolves is that same directory, obtained by executing
  node against the module and comparing the string.

**Two tautologies it must not commit,** both of which the first draft left open:

- It must not reach the root by the same `\dirname(__DIR__, N)` idiom it is
  checking. That idiom is how every governance control in this repository finds
  the root, so an executor will copy it by reflex; the marker walk above is what
  replaces it, and the DoD proves the difference by planting.
- It must not accept an injected root. Nothing in the control may hand either
  side a root it did not derive, or the check confirms its own input.

**Node absent is a refusal, not a skip.** A skip here would be permanently
invisible in the web environment, which is exactly where the dependency is new.

## Definition of Done

Negative checks are written as refusals. `grep -c` exits 0 when it finds the
forbidden string and 1 when the file is clean, so a gate phrased "returns 0" is
green on a dirty tree — measured on this tree. And `git grep -E` does not honour
`\b` here, returning nothing where `grep` returns matches; use `-P` or `-wE`.

1. Exactly one JS hop chain remains, and it is the new module's:
   `git grep -lP "\.\.'\s*,\s*'\.\." -- '*.mjs' '*.js' ':!src/Reporting/Template/scripts/repo-root.mjs'`
   prints nothing. The pattern tolerates absent whitespace — the current call
   spells it `'..', '..'`, but nothing enforces that spelling.
2. PHP is unchanged: `git diff --stat` names no file under `src/Reporting/`
   other than none at all. The formatter is deliberately untouched.
3. The control **fails on a planted wrong depth** — change the JS module's hop,
   quote the refusal verbatim, restore from a copy taken *before* the plant.
4. The control **fails on a planted tautology** — reimplement its root discovery
   as `\dirname(__DIR__, N)` with the correct `N`, confirm it then passes on a
   tree where the JS hop is wrong, and restore. This is the one that proves the
   marker walk is doing work, and it is the check the first draft could not make.
5. The control **refuses, not skips, when node is unavailable** — run it with
   node off `PATH` and quote the refusal.
6. `composer check` green from a clean clone with copied `vendor`,
   `website/.venv` and `node_modules`, **on a machine with node** — and the same
   clone with `scripts/init-environment.sh` run from scratch reaches a node that
   satisfies item 5.
7. No new production class: `git diff --name-only` names no added file under
   `src/`, so the manifest and the generated artifacts are untouched. If that
   turns out to be false, stage 01 has grown a manifest declaration and an
   artifact regeneration, and it stops and says so rather than absorbing them.

## Test plan

One new governance control, described above, plus the four plants that prove it
bites. No unit test for the JS module: its whole behaviour is the path, and a
unit test recomputing the same join is the tautology this stage exists to
remove. `metric-key-catalog.test.js` already fails loudly when the root is
wrong — that is evidence the module works, not evidence the depth is guarded,
and item 3 is what guards it.
