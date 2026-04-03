# Git Integration

Qualimetrix integrates with Git to analyze only the code you have changed. This saves time and helps you catch issues before they reach the main branch.

## Why analyze only changed code?

Running a full analysis on a large codebase can take time. More importantly, a legacy project may have hundreds of existing violations that are not your responsibility right now. Git integration solves both problems:

- **Speed** -- analyze only the files you touched
- **Focus** -- see only the violations you introduced
- **Gradual adoption** -- start using Qualimetrix without fixing every old issue first

---

## Quick start

The two most common scenarios:

```bash
# Before committing: install a pre-commit hook
bin/qmx hook:install

# Before merging: check what changed vs main
bin/qmx check src/ --report=git:main..HEAD
```

---

## Pre-commit workflow

Install a Git hook to automatically check staged files before each commit:

```bash
bin/qmx hook:install
```

This creates a `.git/hooks/pre-commit` script that automatically runs Qualimetrix on staged files before each commit. Only PHP files that are currently staged are analyzed, making it fast. If violations with severity `error` are found, the commit is blocked.

Check the hook status:

```bash
bin/qmx hook:status
```

Remove the hook:

```bash
bin/qmx hook:uninstall
```

If you already had a pre-commit hook, Qualimetrix backs it up. To restore it:

```bash
bin/qmx hook:uninstall --restore-backup
```

!!! warning
    `hook:install` will not overwrite an existing hook unless you pass `--force`.

---

## PR workflow with --report

The `--report` option shows only violations in files that changed compared to a Git reference:

```bash
# Compare against main branch
bin/qmx check src/ --report=git:main..HEAD

# Compare against a specific branch
bin/qmx check src/ --report=git:origin/develop..HEAD

# Compare against a specific commit
bin/qmx check src/ --report=git:abc1234..HEAD
```

!!! note
    With `--report`, Qualimetrix still analyzes the full codebase (it needs complete metrics for namespace-level rules). It only *filters the output* to show violations from changed files.

---

## How --report works

The `--report` option controls which violations are shown in the output. Qualimetrix still analyzes the full codebase (it needs complete metrics for namespace-level rules), but only reports violations from the changed files:

```bash
# Analyze everything, report only changed files
bin/qmx check src/ --report=git:main..HEAD
```

This gives accurate metrics while only showing relevant violations.

| Scenario                        | Recommendation            |
| ------------------------------- | ------------------------- |
| Pre-commit hook (speed matters) | `bin/qmx hook:install`    |
| PR review (accuracy matters)    | `--report=git:main..HEAD` |
| CI pipeline with full analysis  | `--report=git:main..HEAD` |

---

## --report-strict

By default, when using `--diff` or `--report`, Qualimetrix also shows violations from parent namespaces of the changed files. This is useful because adding a class to a namespace can push it over size limits.

If you want to see only violations from the changed files themselves:

```bash
bin/qmx check src/ --report=git:main..HEAD --report-strict
```

---

## Scope syntax

The `--report` option accepts scope expressions:

| Expression                 | Meaning                                      |
| -------------------------- | -------------------------------------------- |
| `git:staged`               | Files staged for commit                      |
| `git:main..HEAD`           | Files changed between main and HEAD          |
| `git:origin/develop..HEAD` | Files changed between remote branch and HEAD |
| `git:abc1234..HEAD`        | Files changed since a specific commit        |

---

## Example workflows

### Local development

```bash
# One-time setup
bin/qmx hook:install

# Now every commit is checked automatically
git add src/Service/UserService.php
git commit -m "refactor: simplify UserService"
# Qualimetrix runs automatically on staged files, blocks commit if errors found
```

### Pull request review

```bash
# On your feature branch, check against main
bin/qmx check src/ --report=git:main..HEAD

# Strict mode: only violations in your changed files
bin/qmx check src/ --report=git:main..HEAD --report-strict

# With JSON output for CI
bin/qmx check src/ --report=git:main..HEAD --format=json --no-progress
```

### CI pipeline (GitHub Actions)

```yaml
- name: Run Qualimetrix
  run: bin/qmx check src/ --report=git:origin/main..HEAD --format=sarif --no-progress > results.sarif

- name: Upload SARIF
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

### CI pipeline (GitLab CI)

```yaml
code_quality:
  script:
    - bin/qmx check src/ --report=git:origin/main..HEAD --format=gitlab --no-progress > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```
