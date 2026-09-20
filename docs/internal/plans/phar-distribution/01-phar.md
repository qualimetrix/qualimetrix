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

That target is `src/`, which is 957 `*.php` files today. Naming it matters, because DoD 3 says
`src/` is the one target that cannot serve as evidence for this stage — see there.

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

## Measured on `89b5aa76`

Re-taken on the tree this stage starts from. Every figure the previous draft measured on `423df74a`
and this one re-measured came back identical — the dist counts, the viewer's four assets, the
release workflow attaching nothing, the promise in both locales. Three rows carry no measurement of
this round and are held over from that draft as they stood — the AST cache under `getcwd()`, the
container compiled fresh, and Finder-backed discovery over `phar://`. The remaining rows are new,
and each exists because it changes an acceptance item below.

| Fact                            | Value                                                                                                            |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| composer dist package           | 1014 files (1196 tar entries), 5.8 MB uncompressed                                                               |
| what the tool writes today      | the AST cache under `getcwd()`; other writes exist and P1 enumerates them                                        |
| viewer assets                   | four files, resolved by `dirname(__DIR__, 4) . '/html-report'` from `HtmlFormatter`                              |
| viewer assets are **tracked**   | `html-report/dist/{d3,report}.min.js` are committed, so no JS build precedes a phar build                        |
| `.gitattributes` already trims  | `git archive --worktree-attributes` carries exactly those four under `html-report/`                              |
| DI container                    | compiled fresh every run, never dumped to disk                                                                   |
| collector and rule discovery    | Symfony `registerClasses()`, Finder-backed, works over `phar://`                                                 |
| release workflow                | creates a GitHub Release from the changelog, runs no `composer install`, and attaches nothing                    |
| parallel threshold              | `AmphpParallelStrategy::DEFAULT_MIN_FILES_FOR_PARALLEL` = **100**, applied inside the strategy, not the selector |
| `docker-image` job's target     | `src/Core` with `--workers=0` — below the threshold and sequential by flag as well                               |
| the repository's own comparator | `finding-gate`: largest corpus case is 12 `*.php` files; locates the product as `<root>/bin/qmx`                 |
| tool version, resolved          | `Version::get()` → `InstalledVersions::getPrettyVersion('qualimetrix/qualimetrix')`; prints `dev-main` here      |
| analysed project's label        | `HtmlTreeBuilder` → the `project-name` format option, falling back to `InstalledVersions::getRootPackage()`      |
| `realpath()` under `phar://`    | returns **`false`**; `file_exists()` on the same path returns `true`                                             |
| `humbug/box` today              | refused outright without `-W`; the newest releases constrain `symfony/finder` to `^6.4 \|\| ^7.0`                |
| public promise                  | `website/docs/getting-started/installation.md`, EN and RU, says a phar is "Coming soon"                          |

Re-take these before executing.

## The four real problems

**1. `humbug/box` cannot simply be added.** Measured on this lock: `composer require --dev humbug/box`
is **refused**, not merely lossy — the newest box releases constrain `symfony/finder` to
`^6.4 || ^7.0` while the lock pins `v8.0.8`, and composer declines the partial update. It installs
only under `-W`, which is where the previously measured downgrade of production Symfony packages
from 8.0 to 7.4 happens. The project declares `^7.4 || ^8.0`, so that downgrade is legal and silent
— and it would mean the tool is developed and tested against one Symfony generation while declaring
both.

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

**3. The parallel path is not observable in a small run, and nothing in the repository observes it.**
`AmphpParallelStrategy` falls back to sequential when `count($files)` is below
`DEFAULT_MIN_FILES_FOR_PARALLEL`, which is 100. The branch lives in the strategy; `StrategySelector`
decides on worker count alone and never sees a file count, so reading the selector suggests a
guarantee the strategy then withdraws.

Every existing target is below that number: the `docker-image` job runs `src/Core` with
`--workers=0`, and the finding gate's largest corpus case is 12 files. **Any acceptance item here
that says "with workers" must name a target above 100 and a worker count above 1, or it is green
without having run the thing it claims to check.**

**4. `realpath()` returns `false` for every path inside a phar.** Measured, not reasoned: built a
phar and printed both — `file_exists()` on a `phar://` path is `true` while `realpath()` on the same
path is `false`.

One place in the product depends on that and fails quietly: `HookInstallCommand` locates
`scripts/pre-commit-hook.sh` by `__DIR__`, then canonicalizes, and canonicalization throws on
`false` and is caught by a `continue`. So `hook:install` from a phar returns "not found" **even if
the script is in the archive**. It is already broken for composer consumers for a different reason —
`/scripts/` is `export-ignore`d, so the dist package does not carry the file at all — which makes
this a pre-existing defect the phar inherits rather than one it introduces. P1 decides whether
stage 01 ships the script and repairs the lookup, or refuses `hook:install` from a phar loudly; DoD 3
carries the decision either way.

Other `realpath()` callers in `src/` were swept and are not exposed: they canonicalize the **analysed**
tree or the working directory, both on a real filesystem. Swept with grep over `src/` and `bin/qmx`
for `realpath(`, `__DIR__` and `__FILE__`; blind to a path assembled through a variable and to
`vendor/`.

## The include list, and how many lists there are

`.gitattributes`'s `export-ignore` shapes the composer dist. A `box.json` include list would be a
second enumeration of "what ships". **`.dockerignore` is a third**, shaping what reaches the image,
and the first draft of this plan did not acknowledge it.

Deriving the phar from the composer dist is not available as stated: `git archive` carries no
`vendor/`, and a phar without `vendor/` does not run. So the options are a box list maintained
against the dist's list with a control asserting they agree, or a build that takes the dist and adds
an installed `vendor/`. P1 chooses.

Two constraints the list has to satisfy, both established by reading rather than by taste:

- **`src/` and `html-report/` must stay siblings at the archive root.** `HtmlFormatter` resolves its
  templates as `dirname(__DIR__, 4) . '/html-report'`, so any layout that nests or flattens them
  breaks the viewer. The dist already trims `html-report/` to the four files the formatter reads.
- **`scripts/pre-commit-hook.sh` is the one file the dist drops that the product still reaches for.**
  Whichever way problem 4 is decided, the list states it.

A control of exactly this shape already exists for the dist:
`governance/DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest.php`, which asserts the
distributed package carries only what the formatter reads. Whatever P1 chooses, its control belongs
beside that one, in `governance/DistributedPackage/`, and needs the registration a new governance
member needs; `AGENTS.md`'s Project Structure section lists those addresses and marks which fail
silently. That group already exists, so a new **file** in it needs no new `phpunit.xml.dist` entry —
but a fixture directory under it would need the `.gitignore` negation and the `phpstan.neon`
`excludePaths` line, and both of those fail silently.

## Packages

**P0 — the questions that remain.** Small, because most of what it was going to ask is answered
above. Build a phar with box in whatever isolated form P1 is considering, and establish: that
`phar.readonly` does not block the build on the machine that will run it, that an analysis over a
target above the parallel threshold produces the same findings as the source tree, and that
`--format=html` from the phar yields a report with its assets inlined.

Two further questions P0 answers because they decide P1's shape, not merely its detail:

- **Can the finding gate be pointed at a phar?** It is the only thing in the repository that compares
  two products across findings, the twelve formats, exit codes, `qmx rules` and `baseline:explain`,
  and that breadth is what DoD 1a asks for. But it takes a **tree root**, not a binary: it runs
  `<root>/bin/qmx` and reads its corpus from `<root>/finding-gate/cases`. Either a shim root satisfies
  it, or DoD 1a's comparison is narrower and says which surfaces it dropped.
- **Does box's comment-stripping compactor have to stay off?** Every `ReflectionClass` use in `src/`
  reads names, constants, methods and attributes; the only two `getDocComment()` calls read
  php-parser nodes of the analysed tree, not the tool's own source. So the compactor looks safe and
  P0 confirms it by running with it on. Swept with grep over `src/`; blind to `vendor/`, where a
  dependency reading its own docblocks would not show.

**P1 — tooling, the dependency decision, and the include list.** The box decision and the include
list decision, both recorded. The artifact's name and the reason for its suffix. The `hook:install`
decision from problem 4.

**P2 — the records and the promise.** `website/docs/getting-started/installation.md` in EN **and**
RU, `CHANGELOG.md`, and an ADR if the box decision or the include-list decision is worth outliving
this plan. The site already promises this, so the records package is not optional.

**P3 — release and CI.** The phar built and attached by `release.yml`, and built on every CI run so a
broken build is caught before a tag. `release.yml` today checks out and calls `gh release create`
with no PHP setup and no `composer install`, so "attaches the phar" is a build job, not an upload
line. **A new required check is an address outside the tree:** branch protection on `main` carries
the required-context list, and a job absent from it does not block a merge.

## Definition of Done

1. **(a) The phar publishes what the source tree publishes**, across the surfaces the comparison
   names — findings at minimum, and every further format, exit code and command the chosen
   comparator covers. *Green for the wrong reason if:* only findings were compared, so a defect in a
   format that was never rendered ships.
   **(b) The phar's parallel path produces the same findings as the source tree**, over a target of
   more than 100 `*.php` files with more than one worker, compared rather than eyeballed. *Green for
   the wrong reason if:* the target is below 100 or the run is `--workers=0`/`1`, so both runs were
   sequential and the worker path was never tested.
   These are two properties and no instrument in the repository delivers both: the gate has the
   breadth and a 12-file corpus, a large target has the threshold and no comparator. Splitting them
   is the point; satisfying both with one run is not required.
2. **`--format=html` from the phar inlines its four assets**, checked by content. The build failing
   to include them is loud, not silent — the formatter refuses — so this item's real target is an
   asset that is present but wrong, not one that is missing.
3. **The three values that resolve differently inside a phar are established**, each by its own
   mechanism, and each either right or a recorded decision:
   - **The tool's own version.** `Version::get()` reads `vendor/composer/installed.php`, which is
     inside the archive, so it resolves to whatever the **build checkout** said. It prints `dev-main`
     here; a release phar printing `dev-main` is the failure, and it is silent.
   - **The analysed project's label.** Absent the `project-name` format option, `HtmlTreeBuilder`
     falls back to `InstalledVersions::getRootPackage()['name']` — which inside a phar is
     `qualimetrix/qualimetrix`, whatever is being analysed. This is **pre-existing**: the Docker image
     and a `composer global require` install have the same root package today. Stage 01 states
     whether it repairs the product (a user-facing change, so `CHANGELOG.md`) or records the defect
     and leaves it.
   - **`hook:install`'s script lookup**, per problem 4: repaired, or refused loudly, but not left
     returning "not found" from a working archive.
   **Evidence for 1b must not be `src/`.** Analysing this repository makes both the phar run and the
   tree run report `qualimetrix/qualimetrix`, which masks exactly the defect this item is about. An
   external target pinned by the lock — `vendor/symfony/console` is 132 `*.php` files — is both above
   the threshold and outside the mask.
4. **The include list's chosen mechanism has a control**, added to
   `governance/DistributedPackage/` beside `HtmlReportShipsOnlyWhatItReadsTest.php`, and shown
   refusing a divergence.
5. **The dependency decision is recorded and the production graph is unchanged**, or its change is
   the recorded decision. Checkable as a `composer.lock` diff: no production Symfony package moves.
6. **The release attaches the phar**, and the build runs on every CI run.
7. **The new check is in branch protection's required list**, or its absence is a recorded decision.
   The list on `main` today is six contexts — `Commit messages (private terms)`,
   `Action smoke test (8.4)`, `Action smoke test (8.5)`,
   `Full validation (composer check) (8.4)`, `Full validation (composer check) (8.5)`,
   `Docker image (the documented build)` — and `enforce_admins` is **on**. So the order is
   load-bearing: add the context only after the job has run once and its name is copied from a real
   run, because a misspelled required context with `enforce_admins` on blocks every merge to `main`,
   the owner's included. This is a repository-settings change, outside the tree and outside a pull
   request.
8. **The docs promise is resolved**, EN and RU saying the same thing.
9. `composer check` green from a clean clone with copied `vendor`, `website/.venv` and
   `html-report/node_modules`.

## Hazards

`phar.readonly` is `On` by default — measured `1` on this machine — and a build needs it off for the
building process only.

`ThreadContext` — the `ext-parallel` path — carries no phar workaround of the kind `ProcessContext`
has, and this machine cannot exercise it. Unresolved, and named so it is not mistaken for covered.

The tool's writes under `getcwd()` are unaffected by a read-only archive, but that is a fact about
today's code. A future write resolved from `__DIR__` would break only inside the phar, and only for a
consumer. `realpath()` returning `false` there is the same hazard one step earlier: a future
canonicalization of a path the tool ships would fail in the archive and nowhere else.
