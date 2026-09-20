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
on every run when the path it runs from does not end in `.phar`; measured at
11,124,518 bytes per run on this artifact. The reason is recorded in the build
script, because `box.json` cannot hold a comment and a later rename for
tidiness would otherwise reintroduce the copy with nothing to explain it.

**The version the archive reports comes from the install that precedes the
build.** `Version::get()` reads `vendor/composer/installed.php`, which
`composer install` writes and `composer dump-autoload` does **not** refresh —
measured, after the opposite was assumed. The release job therefore exports
`COMPOSER_ROOT_VERSION` for its install step; a shallow checkout leaves
composer unable to guess a version from the tag and it would record
`1.0.0+no-version-set` instead, with nothing reporting that a release answers
`--version` with a non-version.

**`hook:install` refuses from a phar.** The command installs a symlink to a
shell script, and nothing can symlink into an archive; shipping the script
would not have changed that. It previously failed on a path invariant naming a
`phar://` path the reader never wrote, because `realpath()` returns `false` for
every path inside an archive.

## Consequences

The build tool can move independently of the product's dependencies, and
upgrading box is a two-line change to a pinned version and hash rather than a
lockfile negotiation. The cost is that the pin is maintained by hand and a box
release with a security fix does not arrive on its own.

The control judges `box.json`'s meaning rather than a built archive's contents,
which keeps it free of a build step and a network fetch inside `composer check`.
It therefore cannot see box resolving the same configuration differently than
the control resolves it — a box upgrade that changed `directories` semantics
would pass the control and diverge in the artifact. The artifact-level
comparison belongs to the job that builds one, and the CI phar job holds the
archive against the tree it came from across the whole JSON report, the rule
listing and the rendered HTML.

A phar consumer has no `hook:install`. That is a real reduction against the
composer install, stated in the documentation rather than discovered.
