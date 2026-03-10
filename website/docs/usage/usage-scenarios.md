# Usage Scenarios

AI Mess Detector fits into several development workflows. This page describes the most common scenarios and recommended configurations for each.

---

## CI/CD Pipeline

The most common scenario. AIMD runs on every push or pull request and blocks merges if quality standards are not met.

**Recommended configuration:**

- Use `only_rules` in your config file to pin the set of active rules. This prevents new rules from breaking your pipeline when you upgrade AIMD.
- Use `--fail-on=error` to allow warnings without failing the build. This gives you a gradual adoption path.
- Use a [baseline](baseline.md) for legacy projects to focus on new violations only.
- Use `--format=github` for inline annotations in GitHub PRs, or `--format=sarif` for the GitHub Security tab.

**Example config (aimd.yaml):**

```yaml
fail_on: error

only_rules:
  - complexity
  - coupling.cbo
  - size

rules:
  complexity.cyclomatic:
    method:
      warning: 10
      error: 20
```

**Example GitHub Actions workflow:**

```yaml
- name: Run AI Mess Detector
  run: vendor/bin/aimd check src/ --format=github --fail-on=error --no-progress
```

### Upgrading AIMD in CI

When upgrading AIMD to a new version, new rules may be added. If you use `only_rules`, new rules won't activate automatically. If you don't use `only_rules`, review the CHANGELOG for new rules and either:

- Add them to `disabled_rules` if you don't want them
- Regenerate your baseline: `vendor/bin/aimd check src/ --generate-baseline=baseline.json`

!!! tip
    Combining `only_rules` with a baseline gives you the most stable CI experience. You control exactly which rules run and which existing violations are ignored.

---

## Pre-commit Hook

Fast feedback loop during local development. AIMD checks only staged files before each commit.

**Setup:**

```bash
bin/aimd hook:install
```

**How it works:**

- Analyzes only staged PHP files (fast)
- Blocks the commit if error-severity violations are found
- Respects your project's `aimd.yaml` and baseline

**Tips:**

- If a commit is blocked, you can fix the issues or skip the hook with `git commit --no-verify`
- The hook uses `--analyze=git:staged` automatically
- Consider using `--fail-on=error` in the hook config to allow warnings through

!!! note
    The hook analyzes only the staged version of each file. If you stage partial changes (`git add -p`), the hook checks exactly what will be committed.

---

## AI-Assisted Development

A developer (with or without an AI coding assistant) writes code, then runs AIMD to check quality before submitting for review.

**Workflow:**

1. Write or generate code
2. Run `bin/aimd check src/` to get a list of violations
3. Review the results and decide what to fix, ignore, or suppress
4. For issues worth fixing, either fix manually or delegate to your AI assistant with a specific instruction like "reduce cyclomatic complexity of method X by extracting helper methods"
5. Re-run AIMD to verify fixes

**Tips:**

- Use `--format=json` if your AI tool works better with structured data
- Use `--report=git:main..HEAD` to focus on violations in your changed files only
- The text format is already optimized for terminal and IDE consumption -- no special "AI format" is needed

---

## Code Review

AIMD annotates pull requests with quality findings, giving reviewers objective data alongside the code diff.

**GitHub -- inline annotations (recommended):**

```yaml
- name: Run AI Mess Detector
  run: vendor/bin/aimd check src/ --format=github --fail-on=error --no-progress
```

Violations appear as warning/error annotations directly on the changed lines in the PR diff. No extra setup required.

**GitHub -- Security tab:**

```yaml
- name: Run AI Mess Detector
  run: vendor/bin/aimd check src/ --format=sarif --no-progress > results.sarif

- name: Upload SARIF
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

**GitLab -- Code Quality widget:**

```yaml
script:
  - vendor/bin/aimd check src/ --format=gitlab --no-progress > gl-code-quality-report.json
artifacts:
  reports:
    codequality: gl-code-quality-report.json
```

**Scoped reporting:**

To show violations only for changed files (not the entire project):

```bash
vendor/bin/aimd check src/ --report=git:main..HEAD --format=github --no-progress
```

!!! tip
    Scoped reporting with `--report=git:main..HEAD` is especially useful for large projects where a full report would overwhelm reviewers with pre-existing issues.

---

## Comparison

| Scenario    | Recommended format             | Key options                               |
| ----------- | ------------------------------ | ----------------------------------------- |
| CI/CD       | `github` or `sarif`            | `--fail-on=error`, `only_rules` in config |
| Pre-commit  | `text` (default)               | Automatic via `hook:install`              |
| AI-assisted | `text` or `json`               | `--report=git:main..HEAD`                 |
| Code review | `github`, `sarif`, or `gitlab` | `--report=git:main..HEAD`                 |
