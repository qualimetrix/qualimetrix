# Git Integration

Git reporting keeps the selected analysis paths and limits published findings relative to a Git reference. It focuses attention on changed code while preserving metric context.

## Why limit the report to changes?

`--report` filters publication after analyzing the selected paths. It does not
promise faster collection or prove that a finding was introduced by this commit.
Changed-file findings remain alongside relevant namespace/project results and
declared project-scoped diagnostics, including architecture cycle findings.
Layer violations belong to their source file.

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

The backup is a single slot, `pre-commit.backup`, because `--restore-backup` restores from
that one name. If the slot already holds a *different* hook, `hook:install --force` refuses
rather than overwrite it — move the old backup away first. Forcing the same hook again, or
replacing a Qualimetrix hook, leaves the backup as it is.

When a hook command cannot do what it was asked — not a git repository, a hook that is not
Qualimetrix's, an occupied backup slot — it exits with code `3` and prints the reason on
stderr, like every other refused input. `--working-dir` works with all three commands, also
when the binary was started by a relative path (`php vendor/bin/qmx hook:install -d ../app`).

Backups keep the original hook's mode. `--restore-backup` moves the backup
inode to the hook name and consumes the backup slot. An unreadable hook or a
failed filesystem operation refuses with environment exit 3; it is never
reported as a healthy hook. Targets and backups use the same judged link
policy as file output, and writable exposure is reported once per target.

---

## PR workflow with --report

The `--report` option limits publication relative to a Git reference, retaining the namespace/project results and configuration diagnostics described below:

```bash
# Compare against main branch
bin/qmx check src/ --report=git:main..HEAD

# Compare against a specific branch
bin/qmx check src/ --report=git:origin/develop..HEAD

# Compare against a specific commit
bin/qmx check src/ --report=git:abc1234..HEAD
```

!!! note
    `--report` preserves the selected analysis paths and filters publication only. Scope is established by actual analysis, not by the Git filter.

---

## How --report works

A code finding with a file location is kept by that file, including duplication.
Non-strict mode also keeps namespace findings in changed PHP namespaces and their
ancestors, and location-free project findings when changed PHP files are nonempty.
Channels declared project-scoped are kept independently of changed files in
both modes. This currently includes `architecture.circular-dependency` cycle
findings and project-scoped diagnostics, even in strict mode.
`architecture.layer-violation` remains only when its source file changed, in
both modes. Changing only the target does not retain an outgoing violation.
See the channel list and measured questions under
[Project scope](output-formats.md#project-scope-in-every-format).
Namespace queries do not read source through file links.

## --report-strict

Strict mode limits file-scoped findings to changed files and removes
namespace/project widening. Declared project-scoped findings, including
architecture cycles, remain. Layer violations follow their source file:

```bash
bin/qmx check src/ --report=git:main..HEAD --report-strict
```

---

## Scope syntax

The `--report` option accepts scope expressions:

An empty range endpoint becomes `HEAD` before ref validation: `git:..main` means
`HEAD..main`, and `git:main..` means `main..HEAD`. Diff uses repository-root paths
through `--no-relative`, so a subdirectory invocation does not change their meaning.
Git 2.28 with this flag is required; flag refusal reports that requirement without
a second version probe.


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

# Strict mode: changed-file findings plus declared project-scoped findings
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
