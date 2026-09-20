# Stage 01 — the phar

Introduces phar tooling where there is none: no `box.json`, no `humbug/box`, no build step, no
mention anywhere, and no abandoned attempt in `git log --all`. Everything below is therefore a
decision rather than a repair.

**This document records decisions and measurements. It prescribes no instruments.** Each acceptance
item names a property; the executor states how it checked and shows the check refusing a plant.

## Measured on `423df74a`

| Fact                               | Value                                                                                             |
| ---------------------------------- | ------------------------------------------------------------------------------------------------- |
| composer dist package              | 1196 entries, ~5.8 MB                                                                             |
| what it carries                    | `src/`, `bin/qmx`, the viewer's four assets under `html-report/`, `action.yml`, root docs         |
| `bin/qmx` autoload discovery       | two hardcoded relative paths, both assuming a `vendor/` layout, no third branch                   |
| what the tool writes               | the AST cache, at `getcwd()/.qmx-cache` — CWD-relative, so a read-only archive does not affect it |
| what the tool reads outside `src/` | the viewer's four assets, resolved by `dirname(__DIR__, 4)` from `HtmlFormatter`                  |
| DI container                       | compiled fresh every run, never dumped to disk                                                    |
| collector and rule discovery       | Symfony `registerClasses()`, a Finder-backed `**` glob rooted at `src/`                           |
| release workflow                   | creates a GitHub Release from the changelog and attaches nothing                                  |
| public promise                     | `website/docs/getting-started/installation.md` says a phar is "Coming soon"                       |

Re-take these before executing.

## The risk that decides whether there is a stage at all

`registerClasses()` walks `src/` with a Finder glob on **every** container build. Inside a phar that
root is a `phar://` stream. **Whether Finder walks it is not a fact anyone in this repository has**,
and it decides whether the tool starts.

So **P0 comes before any design**: build a minimal phar, run one analysis through it, and find out.
The fork it settles:

- **Finder walks the stream** — registration is unchanged and the stage is the ordinary one.
- **Finder refuses** — registration must stop depending on a filesystem walk. Two ways: dump the
  compiled container (the tool does not do this today, so it is a real change with its own
  consequences for how a run starts), or drive registration from a classmap. Choosing between them is
  stage work, not P0's; P0's job is to say which world we are in.

Nothing else in the stage may be designed until P0 answers, because the answer changes what the phar
contains.

## Three more risks, ranked, none of them the one the brief expected

1. **`bin/qmx` cannot find its autoloader inside a phar.** Two hardcoded relative paths, both assuming
   `vendor/`. Loud — it fails at the first run.
2. **The viewer's four assets.** `dirname(__DIR__, 4)` resolves inside the phar, which is correct
   exactly when the build includes them. **Silent if wrong:** `--format=html` breaks at a consumer's
   run, not at build time, and nothing in this repository's own use would notice.
3. **`amphp/parallel` in a phar.** The library carries its own documented workaround — it extracts its
   bootstrap to a temp file and rewrites `__DIR__` when running from inside a phar. That is not a gap
   in the code; it is a gap in the evidence, because nothing here has ever run it from a phar. **P0's
   smoke run uses more than one worker**, unlike the `docker-image` job, whose `--workers=0` is why
   this is unexercised today.

The brief that started this work expected the parallel worker to be the blocker. It is not; the
research refuted that and put the Finder glob in its place. Recorded because the ranking is the part
most likely to be assumed rather than checked.

## One fork to take deliberately, not to discover

A phar build needs a list of what goes in. `.gitattributes` already carries such a list, as the
`export-ignore` rules that shape the composer dist. **Two lists of "what ships" drift independently,
and the one that drifts is the one nobody regenerates.**

Decide, in this stage: either the phar's contents are derived from the composer dist — `git archive`
as the single source, so there is one list — or the two are maintained separately and a control
asserts they still agree. A third option, two hand-maintained lists and a comment saying they match,
is the shape this repository has already paid for twice.

## Packages

**P0 — does it run at all.** Before anything else. A phar, however crude, and one analysis through
it with more than one worker. Reports which side of the Finder fork we are on, whether `bin/qmx`
finds its autoloader, and whether `--format=html` produces a report with its assets inlined. No
design decisions, no tooling committed.

**P1 — the tooling and the entry point.** `humbug/box` as a dev dependency, `box.json`, a composer
script, and whatever `bin/qmx` needs to find its autoloader in all three worlds — a composer install,
a global install, and inside the phar. Carries the include-list fork's decision.

**P2 — the records and the promise.** `website/docs/getting-started/installation.md` in EN **and**
RU, `CHANGELOG.md`, and an ADR if the include-list fork or the Finder fork produced a decision worth
outliving this plan. This stage is user-facing because the site already promises the phar, so the
records package is not optional.

**P3 — release and CI.** The phar built and attached by `release.yml`, and a CI job that builds it on
every run so a broken build is caught before a tag. **A new required check is an address outside the
tree:** branch protection on `main` lists its required contexts, and a job that is not in that list
does not block a merge.

## Definition of Done

1. **The phar runs the tool.** One analysis end to end, with more than one worker, producing the same
   findings as the same command run from the source tree. Compared, not eyeballed.
2. **`--format=html` from the phar produces a report with its four assets inlined**, checked by
   content rather than by exit code — this is the silent failure of the three.
3. **The phar contains what it should and nothing else**, under whichever answer the include-list
   fork produced. If the two lists are separate, the control that asserts they agree exists and has
   been shown refusing a divergence.
4. **`bin/qmx` finds its autoloader in all three worlds**, each exercised.
5. **The release attaches the phar**, and the build runs on every CI run rather than only on a tag.
6. **The new check is in branch protection's required list**, or its absence is a recorded decision.
7. **The docs promise is kept or restated** — "Coming soon" says something to a reader, and whichever
   way it resolves, EN and RU say the same thing.
8. `composer check` green from a clean clone with copied `vendor`, `website/.venv` and
   `html-report/node_modules`.

## Hazards

The `docker-image` job runs with `--workers=0`. Any evidence gathered the same way inherits the same
hole, which is why DoD 1 names a worker count.

A phar is read-only. The tool's writes are CWD-relative and so unaffected, but that is a fact about
today's code, not a property of the design — a future write resolved from `__DIR__` would break only
in the phar, and only for a consumer.
