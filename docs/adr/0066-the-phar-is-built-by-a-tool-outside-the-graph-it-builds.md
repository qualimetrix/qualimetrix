# 0066. The Phar Is Built by a Tool Outside the Graph It Builds

**Date:** 2026-09-20
**Status:** Accepted
**Related:** [0064 — The HTML Viewer Lives Outside the PSR-4 Root](0064-the-html-viewer-lives-outside-the-psr-4-root.md)

## Context

`website/docs/getting-started/installation.md` had been promising a standalone
archive as "Coming soon" in both locales, and the repository carried no phar
tooling at all — no `box.json`, no build step, and no abandoned attempt in
`git log --all`.

The obvious route, `composer require --dev humbug/box`, is not available.
Measured on this lock, composer **refuses** it outright: box's current releases
constrain `symfony/finder` to `^6.4 || ^7.0` while the lock pins `v8.0.8`. It
installs only under `-W`, and that downgrades four production Symfony packages
from 8.0 to 7.4 — legally, because the project declares `^7.4 || ^8.0`, and
therefore silently. The tool would then be developed and tested against one
Symfony generation while claiming both.

A second question arrived with the first. `.gitattributes`'s `export-ignore`
already enumerates what the composer dist carries and `.dockerignore`
enumerates what reaches the image; a phar's include list is a third
enumeration of "what ships". Deriving it from the dist is not available:
`git archive` carries no `vendor/`, and a phar without `vendor/` does not run.

## Decision

**Box is fetched as its own phar, pinned by version and SHA256, and never
enters the project's dependency graph.** `scripts/build-phar.sh` downloads the
release asset, refuses to proceed on a checksum mismatch, and runs it with
`phar.readonly` disabled for that process only. `composer.lock` is therefore
untouched by the ability to build an archive, which is checkable as an empty
diff rather than asserted.

The alternative considered was a second `composer.json` under a tool directory.
It buys a lockfile for the build tool and costs a second `composer install` on
every CI run plus another development root in the address table `AGENTS.md`
maintains. The pin plus the checksum buys the same reproducibility without
either.

**The include list is `box.json` at the repository root, and a governance
control holds it against the dist's list in both directions.**
`governance/DistributedPackage/PharCarriesWhatTheDistCarriesTest.php` fails
when the phar would carry a file `export-ignore` keeps back — a decision
reversed without being restated — and fails when the dist carries a file the
phar omits unless that omission is declared with its reason.

**The artifact is `build/qmx.phar`, and the suffix is load-bearing.**
`amphp/parallel` copies the entire running archive into the temporary directory
on every run when the path it runs from does not end in `.phar`. The copy is the
archive byte for byte — measured as that identity rather than as a constant,
because the archive's size moves with its contents and a pinned figure would
quietly stop being true. The reason is recorded in the build script, because
`box.json` cannot hold a comment and a later rename for tidiness would otherwise
reintroduce the copy with nothing to explain it.

**The version the archive reports comes from the install that precedes the
build.** `Version::get()` reads `vendor/composer/installed.php`, which
`composer install` writes and `composer dump-autoload` does **not** refresh —
measured, after the opposite was assumed. The release job therefore exports
`COMPOSER_ROOT_VERSION` for its install step. Without it composer records
whatever it can work out for the root package, measured as `dev-main` in a
checkout of this repository and `1.0.0+no-version-set` in a copy of one; both
are a release answering `--version` with a non-version, and nothing would say
so. The release job compares the whole line rather than searching it, because
`1.0.0+no-version-set` contains `1.0.0`.

**The interpreter floor is guarded by the product, not by the build tool.**
Box can inject a requirements checker, and the first version of this decision
used it. Measured on the first real CI run: it refuses to start on any machine
with `ext-redis` loaded. `symfony/expression-language` pulls in `symfony/cache`,
which declares a *version-ranged* conflict with `ext-redis` below 6.1 — and box's
checker is `!extension_loaded('redis')`, with no version comparison at all. The
archive would have refused to run for a large share of PHP developers over a
Redis cache adapter this tool never constructs.

So the checker is off and `bin/qmx` guards `PHP_VERSION_ID` before it loads
anything. That is the better home regardless of box: the guard now covers a
Composer install and the Docker image too, where no checker was ever injected,
and it fires before `src/` is parsed rather than after. The floor is a literal,
because the guard has to hold inside the archive and `composer.json` is
deliberately not in it;
`governance/PackageVersion/EntryPointRefusesTheVersionsComposerRefusesTest.php`
holds the literal and the constraint together.

**`hook:install` refuses from a phar.** The command installs a symlink to a
shell script, and nothing can symlink into an archive; shipping the script
would not have changed that. It previously failed with
`AbsolutePath must start with "/"`, naming a `phar://` path the reader never
wrote — the value object rejects the scheme before anything canonicalises it,
so `realpath()` returning `false` inside an archive is a neighbouring fact
rather than this one's cause.

The refusal does not send the reader to Composer, although that is the obvious
advice. `/scripts/` is `export-ignore`d, so an installed package carries no hook
script either and the command answers `Hook script not found` there too —
measured on an extracted dist. That is a separate defect of the command, older
than this decision and recorded outside this record.

## Consequences

The build tool can move independently of the product's dependencies, and
upgrading box is a two-line change to a pinned version and hash rather than a
lockfile negotiation. The cost is that the pin is maintained by hand and a box
release with a security fix does not arrive on its own.

The control judges `box.json`'s meaning rather than a built archive's contents,
which keeps it free of a build step and a network fetch inside `composer check`.
It therefore cannot see box resolving the same configuration differently than
the control resolves it — a box upgrade that changed `directories` semantics
would pass the control and diverge in the artifact. One box rule is reproduced
rather than read, Finder's exclusion of dot-files, and it is marked as such
where it is reproduced.

The artifact-level comparison belongs to the job that builds one. The CI phar
job holds the archive against the `--no-dev` tree it came from across the whole
JSON report, the `qmx rules` listing, the rendered HTML document and the exit
code — but not across the twelve output formats, `baseline:explain`, or the
rest of the surface the finding gate compares, because that gate takes a tree
root rather than a binary.

Two things the comparison is structurally unable to report, both stated so the
job is not mistaken for a proof of soundness. It compares two installs of the
same production graph, so a defect of that graph appears on both sides and
reads as agreement — this is how one was found and then missed: building the
archive surfaced it, and the equivalence job would not have.
And stack-trace line numbers inside `vendor/` shift between the archive and its
source tree, because the compactor strips comments, so the comparison keeps a
failure's reason and drops the frames that carry it.

Reports rendered from the archive name the analysed project
`qualimetrix/qualimetrix` unless `project-name` is passed, because the label
falls back to the root package and inside the archive that is Qualimetrix's own.
**Recorded, not repaired**: the Docker image and a global install have had the
same fallback for as long as they have existed, so it is a property of the
product rather than of this decision. The installation page names it among the
differences a reader should expect.

A phar consumer has no `hook:install`. That is a real reduction against the
composer install, stated in the documentation rather than discovered.
