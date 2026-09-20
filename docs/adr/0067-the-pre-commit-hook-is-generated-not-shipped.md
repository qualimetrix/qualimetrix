# 0067. The Pre-commit Hook Is Generated, Not Shipped

**Date:** 2026-09-20
**Status:** Accepted

## Context

`hook:install` could not work for anyone who installed Qualimetrix as a
composer package.

`.gitattributes` carries `/scripts/ export-ignore`, so the distributed package
has no `scripts/` at all. The command looked for `scripts/pre-commit-hook.sh`
in two places — relative to the working directory, and relative to the package
root — and in an installed package neither exists. Measured on an extracted
`git archive` of the tree that closed the HTML-viewer relocation:

```
Hook script not found: scripts/pre-commit-hook.sh
```

exit 1. The command therefore worked only inside a git checkout of this
repository, which is not where its users are.

Two things hid this for as long as they did.

The **second** lookup is the one that mattered: `__DIR__ . '/../../../../scripts/pre-commit-hook.sh'`
resolves inside any checkout regardless of the working directory, so nothing
running in the source tree could observe the miss. The functional tests also
fabricated `scripts/pre-commit-hook.sh` in their own fixture, but that fixture
was redundant — removing it would not have reddened anything.

The documentation had the same defect twice more. `quick-start` offered three
installation methods, two of them `ln -s ../../scripts/pre-commit-hook.sh` and
`cp scripts/pre-commit-hook.sh`, on a page that seventy lines earlier tells the
reader to run `composer require --dev qualimetrix/qualimetrix`. None of the
three worked for that reader.

The obvious one-line repair does not work: adding
`/scripts/pre-commit-hook.sh -export-ignore` beside the directory row has no
effect, because `export-ignore` on a directory prunes the directory and git
never descends into it to read a per-file attribute. Verified with
`git archive --worktree-attributes`; it is the same semantic that
`governance/DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest.php`
documents.

## Decision

`hook:install` generates the hook's contents. `PreCommitHook` owns the text,
the marker that makes a hook ours, and the recognition of one.

The shipped file is not moved, re-exported, or relocated: it is removed from
the problem. The only input the hook needs is the path of the binary that is
installing it, and the command already holds that — everything else was a
delivery step for a file whose contents never varied.

Three consequences follow, and each was a decision of its own:

**The phar's refusal goes with it.**
[ADR 0066](0066-the-phar-is-built-by-a-tool-outside-the-graph-it-builds.md)
refused `hook:install` from a phar for two reasons, and this decision removes
both: nothing symlinks any more, and no code builds a script path, so
`AbsolutePath` never sees a `phar://` prefix. Measured on a built archive
rather than argued — `hook:install` exits 0, the hook it writes carries the
`.phar` in `QMX_BIN`, and a `git commit` in that repository runs the archive
and blocks on the finding it reports. The installation page no longer lists
the reduction.

**The hook names the binary that installed it.** `QMX_BIN` is baked, with the
old `vendor/bin/qmx` → `bin/qmx` search kept as a fallback for the binary
moving afterwards, which `composer update` does routinely. This is also what
makes the command work outside a composer layout at all — from a global
install, or from an archive.

**The hook is a regular file, not a symlink.** A symlink cannot point inside an
archive, and a symlink into `vendor/` dies the first time the package is
replaced. This is the breaking half: every hook installed by an earlier release
is now a symlink whose target this package no longer carries.

**A dangling symlink is a hook.** `file_exists` follows a link and answers
false for a broken one, so all three hook commands ask `is_link` first. Without
that, `hook:install` would write *through* the dangling link and recreate the
deleted script in the user's repository; `hook:uninstall` would report nothing
to remove; `hook:status` would call a hook git still executes "NOT INSTALLED".
`hook:uninstall` refuses a dangling link rather than guessing: with no contents
there is no marker to read, and the alternative is a rule about where a past
release pointed it — a compatibility shim for a path that no longer exists.

**The hook's inputs are quoted, and its own path comes from git.** Both are
consequences of generating rather than shipping, because both are questions a
static file never had to answer. The binary path is a value this code
substitutes, so it is single-quoted — measured, not assumed: a `"` in the path
ended the string and the file stopped being valid shell while the command
reported success, and `$(…)` in a directory name executed on every commit.
And since all three commands now ask one method where the hook lives, that
method asks git (`rev-parse --git-path hooks`) instead of composing
`<git-dir>/hooks`, which is wrong under `core.hooksPath` and inside a linked
worktree — the two setups this repository itself uses.

## Consequences

A consumer runs `hook:install` and gets a working hook. Someone who installed
the hook from a checkout has a dangling symlink until they run
`hook:install --force`; every hook command now says so rather than misreporting
the state.

`governance/DistributedPackage/HookInstallWorksFromTheDistPackageTest.php`
judges the artifact instead of the checkout: it extracts `git archive`, copies
`vendor/` rather than symlinking it, rebuilds the autoload map against the
extracted tree, and installs into a repository of its own. The copy is
load-bearing and was measured both ways — with a symlinked `vendor/` the PSR-4
map still addresses the checkout, and the defective command exits 0 there while
exiting 1 with a copy.

The control archives `HEAD`, so a regression living only in the working tree
passes it and reddens on the first run after the commit. That is the same
trade the neighbouring control makes, for the same reason: a file nobody
committed is a file no consumer receives.

`scripts/pre-commit-hook.sh` is deleted. The baseline-lifecycle entrypoint
control that guarded its two `BASELINE_ADVICE` lines now reads what
`PreCommitHook::script()` produces rather than the source that produces it —
asserting on the source would pass for a literal spelling those lines and fail
for a generator assembling them, which is a fact about the template's form and
not about the CLI surface being guarded.
