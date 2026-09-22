# Git — Git Integration for Change Analysis

## Overview

Git integration enables filtering findings to show only those related to changed files via the `--report` option.

## Components

### GitClient

Wrapper around git commands for obtaining the list of changed files.

**Methods:**
- `isRepository(): bool` — check if directory is a git repository
- `getRoot(): AbsolutePath` — get git top-level (`git rev-parse --show-toplevel`); may differ from the project root passed to the constructor when the project sits in a git subdirectory
- `getChangedFiles(string $scope): array` — get list of changed files by scope

`GitScopeResolution` owns the project root for analysis. Reporting passes its
explicit project root to `ReportingGitScopeQuery` instead of asking the Git
client to infer it again.

**Supported scopes:**
- `staged` — files in staging area
- `HEAD` — uncommitted changes (working tree vs HEAD)
- `main..HEAD` — two-dot syntax (changes in current branch)
- `main...HEAD` — three-dot syntax (changes since merge-base)
- `HEAD~3` — last N commits

**Status letters.** `ChangeStatus` names `A`, `M`, `D`, `R`, `C` and `T`. `T`
is a change of the entry's type — a regular file replaced by a symlink or the
reverse — and it is an ordinary change here: the path exists, its bytes
changed, and the run reads it. What the entry became is discovery's question,
not the boundary's, and the boundary answering it by dropping the row is how a
PHP file that had replaced a symlink became invisible to `--report=git:*`.

`U` is refused: an unmerged index entry holds several versions of the file at
once and names no single change. It reaches the tool through `git:staged`
only — measured, the same conflicted repository reports `M` under `git:HEAD`.
`X` and any letter a later git adds are refused by the same branch, quoted
back by letter. Every refusal is a warning naming the path; there is no silent
way out of `parseNameStatus()`.

### NameStatusRecord

One row of `git diff --name-status -z`, holding the bytes git wrote.

The NUL-separated form is asked for rather than the textual one because the
textual one is not a format. `core.quotePath` defaults to on, so git wraps any
path holding a byte above 0x7F, a `"`, a `\` or a control character in C quotes
and escapes the bytes octally. A reader taking the row literally gets a name no
file has — and since the escaped form still looks like a path under the project
root, nothing refuses it: `--report=git:staged` used to report nothing and exit
0 for a project with a Cyrillic filename. `-z` removes the transformation
instead of inverting it; inverting it would mean carrying a second
implementation of git's quoting rules and keeping it in step with git's.

The price is that the stream is flat: a record's width is decided by its status
letter (`R` and `C` own two path fields, everything else one), so a miscount
shifts every later record. A field that is not where the format puts it stops
the walk with a `RuntimeException` rather than resynchronising — git's machine
format is a contract with the tool, so a stream this cannot walk is a broken
environment, not bad user input.

**One name this build still cannot carry.** POSIX allows every byte but `/` and
NUL in a name, and `-z` hands them all over; `Core\Path\RelativePath` does not
carry `\`, which it rewrites as a directory separator, so the one file
`back\slash.php` is stored as the two segments `back/slash.php`.

Discovery rewrites it the same way, so the two sides agree and the findings are
published — measured, with the guard removed: two violations, filed under
`back/slash.php`. That is the reason to refuse, not a reason not to. The stored
value is a key that baselines, suppression maps and every reader of the report
index by, and `back/slash.php` is a name that file does not have and that a
real `back/slash.php` already owns, so the two would silently share one
identity. `ChangedFile::isRepresentableGitPath()` therefore refuses such a row,
and the row leaves a warning naming both the name and the reason.

This is containment, not a repair, and it is deliberately asymmetric: a plain
`bin/qmx check` still publishes such a file under the rewritten name, and only
the git boundary declines to take part. The repair belongs in the path model —
`RelativePath::normalize()` applies a Windows-separator rewrite to values whose
own docblocks declare a POSIX model.

### GitRepositoryLocator

Locates the `.git` directory for the current repository. Used by hook commands
(`hook:install`, `hook:status`, `hook:uninstall`) to find the hooks directory.

**Strategy:**
1. Primary: `git rev-parse --git-dir` (handles regular repos, worktrees, bare repos)
2. Fallback: manual directory traversal (when git is not in PATH)

**Methods:**
- `findGitDir(?AbsolutePath $workingDir = null): ?AbsolutePath` — find `.git` directory path

### GitScopeParser

Parses strings in format `git:staged`, `git:main..HEAD` into a `GitScope` object.

**Methods:**
- `parse(string $scope): ?GitScope` — parse scope string
- `isValid(string $scope): bool` — check scope validity

### ReportingGitScopeQuery

The Infrastructure adapter for Reporting's
`GitScopeQueryInterface` and `--report=git:...` finding projection.

**Behavior:**
- Resolves changed PHP paths and their declared namespaces for Reporting
- By default includes parent namespaces when a changed file declares one
- Indexes every namespace declaration in a changed PHP file, including multiple bracketed blocks
- `--report-strict` requests no parent-namespace expansion

## Use Cases

| Scenario      | --report        | Description                                        |
| ------------- | --------------- | -------------------------------------------------- |
| Full analysis | (not specified) | Analyze everything, show all findings              |
| Pre-commit    | git:staged      | Full analysis, show findings in staged files only  |
| PR review     | git:main..HEAD  | Full analysis, show findings in changed files only |

## CLI Options

| Option             | Description                                 |
| ------------------ | ------------------------------------------- |
| `--report=<scope>` | Which findings to show in the report        |
| `--report-strict`  | Show only findings exactly in changed files |

## Examples

```bash
# Pre-commit: show findings in staged files only
bin/qmx check src/ --report=git:staged

# PR review: show findings in changed files only
bin/qmx check src/ --report=git:main..HEAD

# Strict mode: only findings in changed files (exclude parent namespaces)
bin/qmx check src/ --report=git:main..HEAD --report-strict

# Combined with baseline
bin/qmx check src/ --report=git:main..HEAD --baseline=baseline.json
```

## Pre-commit Hook Integration

`GitRepositoryLocator` is all this module lends the hook commands: the hook
itself is generated by `Qualimetrix\Infrastructure\Console\Hook\PreCommitHook`,
which belongs to Console because its text is a CLI invocation and changes for
CLI reasons. Install it with `qmx hook:install`; a hook written by hand looks
like this:

```bash
#!/bin/bash
# .git/hooks/pre-commit

bin/qmx check --report=git:staged --format=text

if [ $? -ne 0 ]; then
    echo "Qualimetrix found issues in staged files"
    exit 1
fi
```

## Two ways of running git, on purpose

`GitClient` uses Symfony Process; `GitRepositoryLocator` uses `proc_open`. The
split is deliberate, and one support carries it: `GitClient` reports git's own
error text. A command carrying a user-supplied scope runs through `diff()`,
which relays git's stderr in a `GitScopeRefusedException` — an
`InvalidArgumentException`, so the CLI's ladder reads it as exit 3. `askGit()`
asks one short question, discards stderr and reads a single pipe safely; it has
no such message to build.

`exec()` is the other half of that split and keeps its `RuntimeException`: it
runs only commands with no user input in them (`rev-parse --show-toplevel`), so
a failure there really is the tool's problem and really is an internal error.
Routing a `git diff` through it was how `--report=git:HEAD` in a repository
with no commits reached the user as a Symfony Process dump titled "Internal
error" with exit 1.

One support this section used to claim does not hold: `assertInsideWorkTree()`
reads `isSuccessful()` and stdout, and needs no stderr at all.
`assertCommitReference()` does read stderr, but only to relay it — never to
decide anything. It once ran `rev-parse` with `--quiet` and classified on what
survived the flag, and that axis turned out not to exist. Measured over
eighteen forms of refusal in this repository, `LC_ALL=C`: every failure of
`git rev-parse --verify` exits 128, so the code says nothing; and under
`--quiet` the stderr left behind is empty for a plain typo, for a reflog
position git cannot walk (`HEAD@{999}`) and for a *damaged* reflog alike,
while a revision dereferencing to a tree (`HEAD^{tree}`) keeps its `error:`
line. Neither signal separates the user's mistake from a broken repository —
and which cases fall silent is a property of the flag, not of git: `--quiet`
was itself buggy for `@{u}` before git 2.41.

Dropping `--quiet` dissolves the question rather than answering it. All
eighteen forms then carry git's own sentence — `log for 'HEAD' only has 1
entries` for the walked-off position, `log for HEAD is empty` for the damage,
`loose object … is corrupt` for the unreadable one — and every refusal is
raised as `UnresolvedGitReferenceException` quoting it verbatim, so a bad
scope is an input refusal whatever went wrong behind it. Nothing parses that
text, so no locale and no git version can move a verdict; the quote itself is
left in whatever language the user's environment gives git, because relaying
it is all this does. The only refusal without a quote is the empty revision,
caught before git is asked — and the one branch that then has to suppress the
`git:` tail is what keeps the colon from dangling, so it carries its own test
rather than waiting for an input that never arrives.

## What `validateScope()` has to cover

The set of scope shapes validated before analysis has to equal the set that
reaches git, or the difference surfaces as a failed command instead of a
refusal — and a failed command is classified as a bug in this tool. Two shapes
used to fall in that gap: `HEAD`, which returned from validation unchecked and
then failed in a repository before its first commit, and a range of three or
more endpoints (`t1..t2..HEAD`), whose parts each resolve while the range as a
whole does not. `staged` is the one scope with nothing to resolve — `git diff
--cached` compares against the empty tree when there is no HEAD, which is a
correct answer rather than a failure.

Together with the `diff()` refusal above, this is fail-closed in both
directions: validation is meant to leave nothing for the backstop to catch, and
the backstop still refuses as input rather than as a bug when something reaches
it — a repository damaged between the two commands is the only measured way in.

## Diagnostics need a logger the container supplies

Rows dropped at the git boundary are the only record that the analysed set is
smaller than the changed set, so they are PSR-3 `warning`s: one per reason, each
naming the measured fact rather than a diagnosis of it. A row whose path does
not resolve inside the project root, a row whose name this build cannot carry,
an unmerged entry and a status letter this build does not know are four
different facts and say so separately.

One warning is not about a drop. A rename whose **source** name holds a
backslash is still carried — its new name is the key, and that one is
carriable — so the row is reported as kept, with the source name that was
lost. Nothing downstream reads `oldPath`, which is the reason this costs the
run nothing rather than a reason to say nothing: the same check on the new
name drops the row, and saying nothing here would make the two outcomes of one
check look like one.

The constructor default is a `NullLogger`, which is the right default for a
class instantiated directly in a test and the wrong one for a service: a
service registered without that argument is silently mute. `GitScopeResolver`
is wired with `DelegatingLogger`; `ReportingGitScopeQuery`, which is where
`getChangedFiles()` actually runs, must be too.

The timeout is the second support that does hold, and it cuts both ways.
Process applies 60 s to every call by default, so a git that hangs is bounded.
The same default is also a ceiling: `git diff --name-status` over a large range
in a consumer's repository is aborted at 60 s, and what surfaces is a
`ProcessTimedOutException` carrying no git stderr rather than the wrapped
failure above. `askGit()`, bounded by nothing, has no such failure mode — it
trades this one for hanging instead.

Neither client builds a shell command. `exec()` takes an argument vector, so a
ref or range reaches git as one literal argument; where Symfony falls back to a
shell of its own — a `--enable-sigchild` build, or an array `proc_open` that
failed — it quotes each element itself. That matters because a range is user
input from `--report=git:...` and git accepts refnames holding `;`, `|`, `&`,
`$(` and `>`; quoting them was previously `escapeshellarg`'s job at each call
site, and is now nobody's.

Symfony Process is therefore a production dependency. It once was not, and
every git scope died with a class-not-found on the phar and on `--no-dev`
installs while the dev-graph suite stayed green.
`governance/DeclaredDependencies/` refuses that statically;
`governance/DistributedPackage/GitScopeWorksFromTheDistPackageTest` and the
phar job's git-scope step refuse it by running the thing.

## Definition of Done

- `GitClient` with support for all scope formats (staged, HEAD, two-dot, three-dot)
- `GitScopeParser` parses git:... syntax
- `ReportingGitScopeQuery` resolves git scope for Reporting finding projection
- CLI option `--report` works
- `--report-strict` disables parent namespaces
- Pre-commit hook example works
- Unit tests with real git repo
- End-to-end integration test


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
