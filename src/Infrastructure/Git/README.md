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

```bash
#!/bin/bash
# .git/hooks/pre-commit

bin/qmx check --report=git:staged --format=text

if [ $? -ne 0 ]; then
    echo "Qualimetrix found issues in staged files"
    exit 1
fi
```

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
