# Stage 01 — the phar

Introduces phar tooling where there is none: no `box.json`, no `humbug/box`, no build step, and no
abandoned attempt in `git log --all`. Everything here is a decision rather than a repair.

**This document records decisions and measurements. It prescribes no instruments.** Each acceptance
item names a property; the executor states how it checked and shows the check refusing a plant.

## It already works

A phar built from this tree runs the tool and produces the same findings as the source tree —
measured over 957 files with four workers, identical summaries. That is the single most important
fact about this stage and it was established by reading and building rather than by planning, so
nothing below is a bet on whether the approach is viable.

Two questions the first draft of this plan called open are answered, both by reading the vendored
tree rather than by experiment:

- **Symfony's Finder walks a `phar://` prefix.** `GlobResource` branches on exactly that prefix and
  takes its Finder path when it matches, so `registerClasses()` discovers collectors and rules inside
  a phar. The first draft made this the question that decided whether the stage existed. It was
  knowable in `vendor/` the whole time.
- **`bin/qmx` finds its autoloader inside a phar already.** Its first candidate is
  `__DIR__ . '/../vendor/autoload.php'`, which resolves under `phar://` provided `vendor/` is in the
  archive. No third branch is needed.

Recorded because both were ranked as risks on reasoning, and both dissolved on measurement.

## Measured on `423df74a`

| Fact                         | Value                                                                                                  |
| ---------------------------- | ------------------------------------------------------------------------------------------------------ |
| composer dist package        | 1014 files (1196 tar entries), 5.8 MB uncompressed, 1.83 MB as the served zip                          |
| what the tool writes today   | the AST cache under `getcwd()`; other writes exist and P1 enumerates them                              |
| viewer assets                | four files under `html-report/`, resolved by `dirname(__DIR__, 4)` from `HtmlFormatter`                |
| DI container                 | compiled fresh every run, never dumped to disk                                                         |
| collector and rule discovery | Symfony `registerClasses()`, Finder-backed, works over `phar://`                                       |
| release workflow             | creates a GitHub Release from the changelog and attaches nothing                                       |
| parallel strategy            | falls back to sequential below a file-count threshold; the `docker-image` job's target is far below it |
| public promise               | `website/docs/getting-started/installation.md`, EN and RU, says a phar is "Coming soon"                |

Re-take these before executing.

## The three real problems

**1. `humbug/box` cannot simply be added.** Measured: requiring it as a dev dependency downgrades
four production Symfony packages from 8.0 to 7.4. The project declares `^7.4 || ^8.0`, so the
downgrade is legal and silent — and it would mean the tool is developed and tested against one
Symfony generation while declaring both.

This is the stage's first decision and it is not "pick a box version". The options are to build the
phar in an isolated dependency graph that never touches the project's own (a separate
`composer.json` under a tool directory, or box's own phar fetched at build time), or to accept the
downgrade deliberately and say so. **P1 chooses and records; it does not add box to the root
`composer.json` without stating which it took.**

**2. Running from a phar introduces writes the tool does not otherwise make.** `amphp/parallel`
extracts its process-runner script to the system temp directory on every run, and — **only when the
running archive's path does not end in `.phar`** — copies the entire archive there as well. Both are
removed by shutdown handlers, which do not run when the process is killed.

So the artifact's **filename is a design decision, not a label**: ending it in `.phar` avoids
copying the whole archive per run. Record that reason next to the name, or the next person who
renames it for tidiness will reintroduce a per-run multi-megabyte copy with nothing to say why.

**3. The parallel path is not observable in a small run.** The strategy falls back to sequential
below a file-count threshold, so a smoke test over a handful of files exercises no worker at all —
which is exactly what the `docker-image` job does today with `--workers=0` over 30 files. Any
acceptance item here that says "with workers" must name a target above the threshold, or it is green
without having run the thing it claims to check.

## The include list, and how many lists there are

`.gitattributes`'s `export-ignore` shapes the composer dist. A `box.json` include list would be a
second enumeration of "what ships". **`.dockerignore` is a third**, shaping what reaches the image,
and the first draft of this plan did not acknowledge it.

Deriving the phar from the composer dist is not available as stated: `git archive` carries no
`vendor/`, and a phar without `vendor/` does not run. So the options are a box list maintained
against the dist's list with a control asserting they agree, or a build that takes the dist and adds
an installed `vendor/`. P1 chooses.

A control of exactly this shape already exists for the dist — the governance group that asserts the
distributed package carries only what the formatter reads. Whatever P1 chooses, its control belongs
beside that one and needs the registration a new governance member needs; `AGENTS.md`'s Project
Structure section lists those addresses and marks which fail silently.

## Packages

**P0 — the questions that remain.** Small, because most of what it was going to ask is answered
above. Build a phar with box in whatever isolated form P1 is considering, and establish: that
`phar.readonly` does not block the build on the machine that will run it, that an analysis over a
target above the parallel threshold produces the same findings as the source tree, and that
`--format=html` from the phar yields a report with its assets inlined.

**P1 — tooling, the dependency decision, and the include list.** The box decision and the include
list decision, both recorded. The artifact's name and the reason for its suffix.

**P2 — the records and the promise.** `website/docs/getting-started/installation.md` in EN **and**
RU, `CHANGELOG.md`, and an ADR if the box decision or the include-list decision is worth outliving
this plan. The site already promises this, so the records package is not optional.

**P3 — release and CI.** The phar built and attached by `release.yml`, and built on every CI run so a
broken build is caught before a tag. **A new required check is an address outside the tree:** branch
protection on `main` carries the required-context list, and a job absent from it does not block a
merge.

## Definition of Done

1. **The phar produces the same findings as the source tree**, over a target large enough that the
   parallel strategy actually engages, compared rather than eyeballed. *Green for the wrong reason
   if:* the target is below the threshold, so both runs were sequential and the worker path was never
   tested.
2. **`--format=html` from the phar inlines its four assets**, checked by content. The build failing
   to include them is loud, not silent — the formatter refuses — so this item's real target is an
   asset that is present but wrong, not one that is missing.
3. **The project's own identity in a report is right when run from a phar.** Two values are resolved
   differently there and fail quietly rather than loudly: the analysed project's label and the tool's
   own version. Establish both, because neither shows up as an error.
4. **The include list's chosen mechanism has a control**, registered where a governance member is
   registered, and shown refusing a divergence.
5. **The dependency decision is recorded and the production graph is unchanged**, or its change is
   the recorded decision.
6. **The release attaches the phar**, and the build runs on every CI run.
7. **The new check is in branch protection's required list**, or its absence is a recorded decision.
8. **The docs promise is resolved**, EN and RU saying the same thing.
9. `composer check` green from a clean clone with copied `vendor`, `website/.venv` and
   `html-report/node_modules`.

## Hazards

`phar.readonly` is `On` by default; a build needs it off for the building process only.

`ThreadContext` — the `ext-parallel` path — carries no phar workaround of the kind `ProcessContext`
has, and this machine cannot exercise it. Unresolved, and named so it is not mistaken for covered.

The tool's writes under `getcwd()` are unaffected by a read-only archive, but that is a fact about
today's code. A future write resolved from `__DIR__` would break only inside the phar, and only for a
consumer.
