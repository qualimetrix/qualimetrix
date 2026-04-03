# Git — Git Integration for Change Analysis

## Overview

Git integration enables filtering violations to show only those related to changed files via the `--report` option.

## Components

### GitClient

Wrapper around git commands for obtaining the list of changed files.

**Methods:**
- `isRepository(): bool` — check if directory is a git repository
- `getRoot(): string` — get repository root
- `getChangedFiles(string $scope): array` — get list of changed files by scope

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
- `findGitDir(?string $workingDir = null): ?string` — find `.git` directory path

### GitScopeParser

Parses strings in format `git:staged`, `git:main..HEAD` into a `GitScope` object.

**Methods:**
- `parse(string $scope): ?GitScope` — parse scope string
- `isValid(string $scope): bool` — check scope validity

### GitScopeFilter

`ViolationFilterInterface` implementation for `--report=git:...`.

**Behavior:**
- Filters violations, showing only those related to changed files
- By default includes parent namespace violations (if a class is changed, namespace violations are shown too)
- `--report-strict` mode disables parent namespace violation display

## Use Cases

| Scenario      | --report        | Description                                          |
| ------------- | --------------- | ---------------------------------------------------- |
| Full analysis | (not specified) | Analyze everything, show all violations              |
| Pre-commit    | git:staged      | Full analysis, show violations in staged files only  |
| PR review     | git:main..HEAD  | Full analysis, show violations in changed files only |

## CLI Options

| Option             | Description                                   |
| ------------------ | --------------------------------------------- |
| `--report=<scope>` | Which violations to show in the report        |
| `--report-strict`  | Show only violations exactly in changed files |

## Examples

```bash
# Pre-commit: show violations in staged files only
bin/qmx check src/ --report=git:staged

# PR review: show violations in changed files only
bin/qmx check src/ --report=git:main..HEAD

# Strict mode: only violations in changed files (exclude parent namespaces)
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
- `GitScopeFilter` filters violations by scope
- CLI option `--report` works
- `--report-strict` disables parent namespaces
- Pre-commit hook example works
- Unit tests with real git repo
- End-to-end integration test
