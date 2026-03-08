# Git Integration

AI Mess Detector integrates with Git to analyze only the code you have changed. This saves time and helps you catch issues before they reach the main branch.

## Why analyze only changed code?

Running a full analysis on a large codebase can take time. More importantly, a legacy project may have hundreds of existing violations that are not your responsibility right now. Git integration solves both problems:

- **Speed** -- analyze only the files you touched
- **Focus** -- see only the violations you introduced
- **Gradual adoption** -- start using AIMD without fixing every old issue first

---

## Quick start

The two most common scenarios:

```bash
# Before committing: check staged files
bin/aimd check src/ --analyze=git:staged

# Before merging: check what changed vs main
bin/aimd check src/ --report=git:main..HEAD
```

---

## Pre-commit workflow with --analyze=git:staged

The `--analyze=git:staged` option limits analysis to files added to Git's staging area (`git add`):

```bash
bin/aimd check src/ --analyze=git:staged
```

Only PHP files that are currently staged for commit are analyzed. This is fast and gives you immediate feedback before committing.

### Automatic pre-commit hook

Instead of running `--analyze=git:staged` manually, install a Git hook:

```bash
bin/aimd hook:install
```

This creates a `.git/hooks/pre-commit` script that automatically runs AIMD on staged files before each commit. If violations with severity `error` are found, the commit is blocked.

Check the hook status:

```bash
bin/aimd hook:status
```

Remove the hook:

```bash
bin/aimd hook:uninstall
```

If you already had a pre-commit hook, AIMD backs it up. To restore it:

```bash
bin/aimd hook:uninstall --restore-backup
```

!!! warning
    `hook:install` will not overwrite an existing hook unless you pass `--force`.

---

## PR workflow with --report

The `--report` option shows only violations in files that changed compared to a Git reference:

```bash
# Compare against main branch
bin/aimd check src/ --report=git:main..HEAD

# Compare against a specific branch
bin/aimd check src/ --report=git:origin/develop..HEAD

# Compare against a specific commit
bin/aimd check src/ --report=git:abc1234..HEAD
```

!!! note
    With `--report`, AIMD still analyzes the full codebase (it needs complete metrics for namespace-level rules). It only *filters the output* to show violations from changed files.

---

## --analyze vs --report

AIMD has two separate scoping mechanisms:

| Option      | Controls   | What it does                                  |
| ----------- | ---------- | --------------------------------------------- |
| `--analyze` | **Input**  | Which files to parse and collect metrics from |
| `--report`  | **Output** | Which violations to show in the report        |

### --analyze

Limits the set of files that AIMD processes:

```bash
# Only parse and analyze staged files
bin/aimd check src/ --analyze=git:staged
```

This is faster because fewer files are parsed, but namespace-level and coupling metrics may be incomplete since AIMD does not see the full picture.

### --report

Analyzes all files but filters the report to show only violations from changed files:

```bash
# Analyze everything, report only changed files
bin/aimd check src/ --report=git:main..HEAD
```

This gives accurate metrics (the full codebase is analyzed) while only showing relevant violations.

### Combining both

You can combine them for fine-grained control:

```bash
# Parse only changed files, report only changed files
bin/aimd check src/ --analyze=git:main..HEAD --report=git:main..HEAD
```

### Which one to use?

| Scenario                        | Recommendation                              |
| ------------------------------- | ------------------------------------------- |
| Pre-commit hook (speed matters) | `--analyze=git:staged` (uses `--analyze`)   |
| PR review (accuracy matters)    | `--report=git:main`..HEAD (uses `--report`) |
| CI pipeline with full analysis  | `--report=git:main..HEAD`                   |

---

## --report-strict

By default, when using `--diff` or `--report`, AIMD also shows violations from parent namespaces of the changed files. This is useful because adding a class to a namespace can push it over size limits.

If you want to see only violations from the changed files themselves:

```bash
bin/aimd check src/ --report=git:main..HEAD --report-strict
```

---

## Scope syntax

Both `--analyze` and `--report` accept scope expressions:

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
# 1. Make changes
# 2. Stage them
git add src/Service/UserService.php

# 3. Check before committing
bin/aimd check src/ --analyze=git:staged

# 4. If clean, commit
git commit -m "refactor: simplify UserService"
```

Or automate it with the hook:

```bash
# One-time setup
bin/aimd hook:install

# Now every commit is checked automatically
git commit -m "refactor: simplify UserService"
# AIMD runs automatically, blocks commit if errors found
```

### Pull request review

```bash
# On your feature branch, check against main
bin/aimd check src/ --report=git:main..HEAD

# Strict mode: only violations in your changed files
bin/aimd check src/ --report=git:main..HEAD --report-strict

# With JSON output for CI
bin/aimd check src/ --report=git:main..HEAD --format=json --no-progress
```

### CI pipeline (GitHub Actions)

```yaml
- name: Run AIMD
  run: bin/aimd check src/ --report=git:origin/main..HEAD --format=sarif --no-progress > results.sarif

- name: Upload SARIF
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

### CI pipeline (GitLab CI)

```yaml
code_quality:
  script:
    - bin/aimd check src/ --report=git:origin/main..HEAD --format=gitlab --no-progress > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```
