# Stage 01 — the phar

Introduces phar tooling where there is none: no `box.json`, no `humbug/box`, no build step, and no
abandoned attempt in `git log --all`. Everything here is a decision rather than a repair.

**This document records decisions and measurements. It prescribes no instruments.** Each acceptance
item names a property; the executor states how it checked and shows the check refusing a plant.

## It already works

A phar built from this tree runs the tool and publishes what the tree publishes. P0 established it
properly: 132 files with four workers and the parallel path confirmed engaged, the whole JSON report
equal field for field to a `composer install --no-dev` tree's. That is the single most important
fact about this stage, so nothing below is a bet on whether the approach is viable.

The earlier draft's version of this claim — 957 files, identical summaries — was measured against the
**development** tree, and that comparison is now known to be the wrong one: it disagrees with the
phar for a reason that has nothing to do with phars (problem 5). The number also named `src/`, which
DoD 3 disqualifies as evidence for a different reason. Both replaced rather than corrected, because
the claim was right by luck.

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
| tool version, resolved          | `Version::get()` → `InstalledVersions::getPrettyVersion(...)`; `dev-main` here, `1.0.0+no-version-set` in a copy |
| analysed project's label        | `HtmlTreeBuilder` → the `project-name` format option, falling back to `InstalledVersions::getRootPackage()`      |
| `realpath()` under `phar://`    | returns **`false`**; `file_exists()` on the same path returns `true`                                             |
| `humbug/box` today              | refused outright without `-W`; the newest releases constrain `symfony/finder` to `^6.4 \|\| ^7.0`                |
| public promise                  | `website/docs/getting-started/installation.md`, EN and RU, says a phar is "Coming soon"                          |

Re-take these before executing.

## The five real problems

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
copying the whole archive per run. P0 measured the copy rather than citing it — the archive copied to the temp directory
per run, byte for byte. The figure moves with the build — 11,124,518 for P0's uncompacted archive,
around 7.4 MB for the compacted one P1 ships — so what is recorded is the identity, not a constant.
Present without the suffix, absent with it.
Record that reason next to the name, or the next person who renames it for tidiness will reintroduce
it with nothing to say why.

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

One place in the product depended on that: `HookInstallCommand` builds
`scripts/pre-commit-hook.sh`'s path from `__DIR__`, and under a phar that reaches
`AbsolutePath::fromString()` with a `phar://` prefix it rejects. Measured from a built archive: exit
3, and on stderr the invariant's own message naming a path the reader never wrote. Loud, then, but
useless — the earlier reading of this plan predicted a quiet "not found" and was wrong about which.
Note what the rejection is: the value object refuses the scheme before anything canonicalises, so
`realpath()` is a neighbouring fact here rather than the cause.

Shipping the script would not have helped, which is what settled P1's choice: the hook is installed
as a **symlink**, and nothing can symlink into an archive. So P1 refuses the command from a phar with
a message that says what to do instead — and that message deliberately does not say "install with
Composer". `/scripts/` is `export-ignore`d, so an installed package answers `Hook script not found`
as well; measured on an extracted dist after review pointed out the advice was false. The command is
therefore inert outside a checkout of this repository, which is a defect older and wider than this
stage, tracked on its own rather than repaired here. Adding `-export-ignore` for the one file does
not work: `export-ignore` on a directory prunes it and git never descends to read a per-file
attribute — measured, and the same semantic the sibling control's docblock already records.

Other `realpath()` callers in `src/` were swept and are not exposed: they canonicalize the **analysed**
tree or the working directory, both on a real filesystem. Swept with grep over `src/` and `bin/qmx`
for `realpath(`, `__DIR__` and `__FILE__`; blind to a path assembled through a variable and to
`vendor/`.

**5. The shipped dependency graph is incomplete, and the tool trips over it.** Found by P0 and not
caused by the phar. `symfony/console` is a production dependency; its `Event/` classes extend
`Symfony\Contracts\EventDispatcher\Event`, which `symfony/event-dispatcher-contracts` provides — and
`composer.lock` carries that package in `packages-dev`, not `packages`. A normal install never
notices, because the tool never dispatches a console event.

`InheritanceDepthCollector` does notice. It resolves an external class's DIT with
`class_exists($fqcn, true)`, so the tool's **own** autoloader answers for any analysed class whose
name it happens to map. Measured on a `composer install --no-dev` tree over 132 files of
`symfony/console`: five files fail, the run is reported incomplete, and the exit code is 4 — from a
tree with no phar involved. The development tree analyses all 132, which is why the defect has never
been seen here.

The reach followed from the lock, not from a chain of inferences: every standalone install shape
resolves the same `packages` section — the Docker image, which runs exactly this
`composer install --no-dev`, a global composer install, and the phar. A consumer installing the tool
as a project dependency was unaffected, because their own graph supplies the missing package.

**Closed on `main` by [#106](https://github.com/qualimetrix/qualimetrix/pull/106)**, which stopped
DIT resolution from recording a class it can reach but not finish loading as a processing failure.
Re-measured after that landed: all 132 files analysed, exit 0. The record stays because it is why
DoD 1's reference is a `--no-dev` tree, and because the shape outlives the instance — a comparison
between two installs of one graph cannot see a defect of that graph, whether or not one is live
today.

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

**P0 — done; what it measured.** Built with box 4.7.0 fetched as its own phar rather than required,
so the project's dependency graph was never touched — problem 1's isolated-graph option exercised
instead of argued. Everything below is a measurement on this tree.

- `phar.readonly` is `1` and `php -d phar.readonly=0` builds. Nothing else obstructed the build.
- 10.61 MB over 2488 files uncompacted; **6.82 MB with box's `Php` and `Json` compactors**, whose
  output produced a byte-identical report and a byte-identical `qmx rules` listing. The compactor
  question is closed: safe, and worth about a third of the size.
- **Equivalence above the threshold.** `vendor/symfony/console`, 132 files, `--workers=4`, with the
  parallel path confirmed by the run's own log rather than by the flag. The phar's whole JSON report
  equals the tree's field for field, except `meta.timestamp`, `meta.version`, and the text of the
  coverage failure messages, which carry `phar://` paths inside their stack traces.
- **The reference must be a `--no-dev` tree, and this is the trap the stage nearly fell into.**
  Against the development tree the phar looked broken: 127 files where the tree analysed 132, six
  findings fewer. It was not the phar — a `composer install --no-dev` tree reproduced the phar's
  numbers exactly, because both carried the graph defect problem 5 records. That instance is closed
  now, but the rule it produced is not about it: a comparison whose reference carries dev
  dependencies measures the dev/prod split and reports it as a phar defect. DoD 1's reference is the
  shipped graph, not the developed one.
- **`--format=html`** inlined `report.css`, `d3.min.js` and `report.min.js` verbatim, and the
  document is identical to the tree's once `generatedAt` and `qmxVersion` are normalised.
- **The filename decision is now a measurement, not a citation.** Run from a copy whose path lacks
  the `.phar` suffix, the process leaves an file in the temp directory that is the archive byte for
  byte — 11,124,518 bytes on P0's uncompacted build, and the same identity on the compacted one P1
  ships. With the suffix, only amphp's process runner appears.

One question P0 leaves to P1 because answering it is design, not measurement:

**Can the finding gate be pointed at a phar?** It is the only thing in the repository that compares
two products across findings, the twelve formats, exit codes, `qmx rules` and `baseline:explain`, and
that breadth is what DoD 1a asks for. It takes a **tree root**, not a binary: it runs `<root>/bin/qmx`,
reads its corpus from `<root>/finding-gate/cases`, and — deliberately, so the answer comes from the
tree under test — boots the candidate's own container in-process through the candidate's autoloader.
A shim root would therefore have to supply three things, not one: a `bin/qmx` that execs the phar, a
`vendor/autoload.php` that delegates into it, and the corpus. P1 chooses that or a narrower
comparison that says which surfaces it dropped.

**P1 — done; the decisions and where they are written.**

- **Box is fetched, not required.** `scripts/build-phar.sh` downloads `box.phar` and verifies it
  against a pinned version and SHA256 before running it. The production graph is untouched, which
  DoD 5 can check as an empty `composer.lock` diff. The alternative — a second `composer.json` under
  a tool directory — buys a lockfile and costs a second install on every CI run plus another root in
  `AGENTS.md`'s address table.
- **`build/qmx.phar`**, because `build/` is already ignored and because the suffix is problem 2's
  decision rather than a label. The reason lives in the build script, where a renamer will read it;
  `box.json` cannot carry a comment.
- **The include list is `box.json` at the repository root**, and `governance/DistributedPackage/PharCarriesWhatTheDistCarriesTest.php`
  holds it against the dist's list in both directions. Shown refusing three plants: a file added to
  `box.json` that `export-ignore` excludes, a declared omission whose reason no longer describes
  anything, and a load-bearing asset dropped from the list.
- **`check-requirements` off, and the guard moved into `bin/qmx`.** Box's checker was on until the
  job's first real run refused to start: `symfony/cache` — transitive, through
  `expression-language`, and never constructed by this tool — declares a conflict with `ext-redis`
  below 6.1, and box's check is `!extension_loaded('redis')` with no version comparison. The
  archive would have refused to run wherever Redis is installed. The interpreter floor is now a
  `PHP_VERSION_ID` guard in the entry point, which covers the Composer install and the image as
  well, with a control holding it against `composer.json`. **`compression` none**, because GZ would
  require `ext-zlib` on the consumer's machine at run time.
- **Compactors on**, `Php` and `Json`: measured safe in P0 and worth a third of the size.
- **`hook:install` refuses from a phar**, per problem 4.

Two addresses closed here because both fail silently and neither is a path a sweep for "phar" would
find: `.gitignore`'s blanket `*.json` swallowed `box.json`, and without an `export-ignore` row the
dist would have carried a build configuration no consumer runs — which the new control would then
have reported as an undeclared omission, blaming the wrong file.

**The version fix belongs to the build, not to `Version.php`** (DoD 3a). `COMPOSER_ROOT_VERSION`
sets what `installed.php` records, but **only on `composer install`** — `composer dump-autoload` does
not refresh it, measured. So the release job must export it for the `install` step that precedes the
build; verified end to end by building with a stand-in tag and reading `0.28.0` back out of the
archive. P3 wires it.

**P2 — done.** Both locales of `website/docs/getting-started/installation.md` now describe the
archive instead of promising it, and name the two ways it differs from a Composer install: no
`hook:install`, and a suffix that is not cosmetic. `CHANGELOG.md` carries both under `Changed`.
[ADR 0066](../../adr/0066-the-phar-is-built-by-a-tool-outside-the-graph-it-builds.md) holds the box
decision, the include list and its control, the filename, and the version mechanism — this plan gets
deleted and those outlive it.

**P3 — done inside the tree; one address outside it is not.** `release.yml` gained a PHP setup, a
`--no-dev` install carrying `COMPOSER_ROOT_VERSION`, the build, an assertion that the archive names
the version being released, and an upload kept separate from the release creation because that
creation is deliberately skippable on a re-run. `qmx.yml` gained a `phar` job that builds on every
run and holds the archive against the tree it came from. Its steps were extracted from the workflow
and executed locally against a `composer install --no-dev` workspace before being committed — the
first version of this job had never been run and was red on every one of its assertions.

**The required-context list is a repository setting, and it is the one item stage 01 cannot land in
a pull request.** `main` requires six contexts today and `enforce_admins` is on, so a context added
before the job has run under its final name blocks every merge, the owner's included. The order is:
merge, let `Phar (built, and equal to the tree it came from)` report once, copy the name from that
run, then add it.

## Definition of Done

Status at the end of P3: every item is met except 7, which is outside a pull request, and 1a, which
is met more narrowly than its wording allows — see the note under it.

1. **(a) The phar publishes what the source tree publishes**, where "the source tree" means a
   `composer install --no-dev` one — P0 measured the development tree disagreeing with the phar for
   a reason that is not the phar — across the surfaces the comparison names — findings at minimum, and every further format, exit code and command the chosen
   comparator covers. *Green for the wrong reason if:* only findings were compared, so a defect in a
   format that was never rendered ships.
   **(b) The phar's parallel path produces the same findings as the source tree**, over a target of
   more than 100 `*.php` files with more than one worker, compared rather than eyeballed. *Green for
   the wrong reason if:* the target is below 100 or the run is `--workers=0`/`1`, so both runs were
   sequential and the worker path was never tested.
   These are two properties and no instrument in the repository delivers both: the gate has the
   breadth and a 12-file corpus, a large target has the threshold and no comparator. Splitting them
   is the point; satisfying both with one run is not required.
   **As landed, 1a is narrower than its wording.** The CI job compares the whole JSON report, the
   `qmx rules` listing, the rendered HTML document, the exit code, and the archive's `src/` against
   the committed listing — but not the twelve output formats or `baseline:explain`. Pointing the
   finding gate at an archive needs a shim root supplying `bin/qmx`, a delegating
   `vendor/autoload.php` and the corpus, and that was not built. The gap is stated rather than
   closed: a format the comparison never renders could diverge and nothing here would say so.
   A second limit, and the sharper one: the job compares two installs of the same production graph,
   so a defect of that graph sits on both sides and reads as agreement. Problem 5 was exactly that
   shape and is now closed, which changes the example and not the limit. This job is evidence that
   the archive equals its tree, not that either is sound.
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
