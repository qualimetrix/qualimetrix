# Git — Git Integration for Change Analysis

## Overview

Git integration enables flexible control over analysis and reporting scope:

1. **--analyze** — which files to analyze (parsing, metrics)
2. **--report** — which violations to show in the report

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

### GitFileDiscovery

`FileDiscoveryInterface` implementation for `--analyze=git:...`.

**Behavior:**
- Gets list of changed files via `GitClient`
- Filters only PHP files (*.php)
- Excludes deleted files
- Verifies files are within specified paths

### GitScopeFilter

`ViolationFilterInterface` implementation for `--report=git:...`.

**Behavior:**
- Filters violations, showing only those related to changed files
- By default includes parent namespace violations (if a class is changed, namespace violations are shown too)
- `--report-strict` mode disables parent namespace violation display

## Use Cases

| Scenario         | --analyze        | --report         | Aggregated |
| ---------------- | ---------------- | ---------------- | ---------- |
| Full analysis    | (entire project) | (entire project) | yes        |
| Quick pre-commit | git:staged       | (implicit)       | no         |
| PR review (fast) | git:main..HEAD   | (implicit)       | no         |
| PR review (full) | (entire project) | git:main..HEAD   | yes        |

## CLI Options

| Option              | Description                                         |
| ------------------- | --------------------------------------------------- |
| `--analyze=<scope>` | Which files to analyze (git:staged, git:main..HEAD) |
| `--report=<scope>`  | Which violations to show in the report              |
| `--report-strict`   | Show only violations exactly in changed files       |

## Examples

```bash
# Pre-commit: staged files only
bin/aimd check src/ --analyze=git:staged

# PR review: full analysis, report only for changes
bin/aimd check src/ --report=git:main..HEAD

# Quick PR: analyze only changed files
bin/aimd check src/ --analyze=git:main..HEAD

# Strict mode: only violations in changed files
bin/aimd check src/ --report=git:main..HEAD --report-strict

# Combined with baseline
bin/aimd check src/ --report=git:main..HEAD --baseline=baseline.json
```

## Pre-commit Hook Integration

```bash
#!/bin/bash
# .git/hooks/pre-commit

bin/aimd check --analyze=git:staged --format=text

if [ $? -ne 0 ]; then
    echo "AI Mess Detector found issues in staged files"
    exit 1
fi
```

## Definition of Done

- `GitClient` with support for all scope formats (staged, HEAD, two-dot, three-dot)
- `GitScopeParser` parses git:... syntax
- `GitFileDiscovery` implements `FileDiscoveryInterface`
- `GitScopeFilter` filters violations by scope
- CLI options `--analyze`, `--report` work
- `--report-strict` disables parent namespaces
- Validation: report scope is a subset of analyze scope
- Warning about unavailable aggregated metrics during partial analyze
- Pre-commit hook example works
- Unit tests with real git repo
- End-to-end integration test
