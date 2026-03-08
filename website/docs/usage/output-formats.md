# Output Formats

AI Mess Detector supports 6 output formats. Choose the one that fits your workflow.

```bash
bin/aimd analyze src/ --format=<format>
```

---

## text (default)

Compact, one-line-per-violation output. Compatible with GCC/Clang error format, so violations are clickable in most terminals and IDEs.

**When to use:** Local development, quick checks, piping to `grep` or `wc`.

**Example output:**

```
src/Service/UserService.php:42: error[complexity.cyclomatic.method]: Cyclomatic complexity is 15, max allowed is 10 (calculate)
src/Service/UserService.php:87: warning[size.method-count.class]: Class has 22 methods, max recommended is 20 (UserService)
src/Repository/OrderRepository.php:15: error[coupling.cbo.class]: CBO is 18, max allowed is 15 (OrderRepository)

3 error(s), 0 warning(s) in 45 file(s)
```

**Format:** `file:line: severity[violationCode]: message (symbol)`

---

## text-verbose

Human-readable, multi-line output with grouping. Shows more context than `text`, including file counts and timing.

**When to use:** Detailed local review, when you want violations grouped by file, rule, or severity.

**Example output:**

```
AI Mess Detector Report
──────────────────────────────────────────────────

src/Service/UserService.php (2)

  ERROR src/Service/UserService.php:42  App\Service\UserService::calculate
    Cyclomatic complexity is 15, max allowed is 10 (15) [complexity.cyclomatic.method]

  WARN src/Service/UserService.php:87  App\Service\UserService
    Class has 22 methods, max recommended is 20 (22) [size.method-count.class]

src/Repository/OrderRepository.php (1)

  ERROR src/Repository/OrderRepository.php:15  App\Repository\OrderRepository
    CBO is 18, max allowed is 15 (18) [coupling.cbo.class]

──────────────────────────────────────────────────
Files: 45 analyzed, 0 skipped | Errors: 2 | Warnings: 1 | Time: 1.23s
```

**Grouping:** Default is `--group-by=file`. You can change it:

```bash
bin/aimd analyze src/ --format=text-verbose --group-by=rule
bin/aimd analyze src/ --format=text-verbose --group-by=severity
```

---

## json

Machine-readable JSON output. Compatible with PHPMD JSON format for tool integration.

**When to use:** Custom scripts, dashboards, programmatic processing.

**Example output:**

```json
{
    "version": "1.0.0",
    "package": "aimd",
    "timestamp": "2025-01-15T10:30:00+00:00",
    "files": [
        {
            "file": "src/Service/UserService.php",
            "violations": [
                {
                    "beginLine": 42,
                    "endLine": 42,
                    "rule": "CyclomaticComplexityRule",
                    "code": "complexity.cyclomatic.method",
                    "symbol": "App\\Service\\UserService::calculate",
                    "priority": 1,
                    "severity": "error",
                    "description": "Cyclomatic complexity is 15, max allowed is 10",
                    "metricValue": 15
                }
            ]
        }
    ],
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "violations": 3,
        "errors": 2,
        "warnings": 1,
        "duration": 1.234
    }
}
```

**CI usage:**

```bash
bin/aimd analyze src/ --format=json --no-progress > report.json
```

---

## checkstyle

Checkstyle XML format. Widely supported by CI tools.

**When to use:** Jenkins, SonarQube, or any tool that accepts Checkstyle XML.

**Example output:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<checkstyle version="3.0">
  <file name="src/Service/UserService.php">
    <error line="42"
           severity="error"
           message="Cyclomatic complexity is 15, max allowed is 10"
           source="aimd.complexity.cyclomatic.method"/>
    <error line="87"
           severity="warning"
           message="Class has 22 methods, max recommended is 20"
           source="aimd.size.method-count.class"/>
  </file>
</checkstyle>
```

**CI usage (Jenkins):**

```bash
bin/aimd analyze src/ --format=checkstyle --no-progress > checkstyle.xml
```

---

## sarif

SARIF (Static Analysis Results Interchange Format) 2.1.0. A standard for static analysis tools adopted by GitHub, Microsoft, and many IDE vendors.

**When to use:** GitHub Security tab, VS Code (with SARIF Viewer extension), JetBrains IDEs, Azure DevOps.

**Example output (abbreviated):**

```json
{
    "$schema": "https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json",
    "version": "2.1.0",
    "runs": [
        {
            "tool": {
                "driver": {
                    "name": "AI Mess Detector",
                    "version": "0.1.0",
                    "rules": [...]
                }
            },
            "results": [
                {
                    "ruleId": "complexity.cyclomatic.method",
                    "level": "error",
                    "message": {
                        "text": "Cyclomatic complexity is 15, max allowed is 10"
                    },
                    "locations": [
                        {
                            "physicalLocation": {
                                "artifactLocation": {
                                    "uri": "src/Service/UserService.php"
                                },
                                "region": {
                                    "startLine": 42
                                }
                            }
                        }
                    ]
                }
            ]
        }
    ]
}
```

**CI usage (GitHub Actions):**

```yaml
- name: Run AIMD
  run: bin/aimd analyze src/ --format=sarif --no-progress > results.sarif

- name: Upload SARIF to GitHub Security
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

Results appear in the **Security** tab of your repository and as inline annotations on pull requests.

---

## gitlab

GitLab Code Quality JSON format. Shows violations directly in Merge Request diffs.

**When to use:** GitLab CI/CD with Code Quality reports.

**Example output (abbreviated):**

```json
[
    {
        "description": "Cyclomatic complexity is 15, max allowed is 10",
        "check_name": "complexity.cyclomatic.method",
        "fingerprint": "a1b2c3d4e5f6...",
        "severity": "critical",
        "location": {
            "path": "src/Service/UserService.php",
            "lines": {
                "begin": 42
            }
        }
    }
]
```

**Severity mapping:**

| AIMD Severity | GitLab Severity |
| ------------- | --------------- |
| error         | critical        |
| warning       | major           |

**CI usage (GitLab CI):**

```yaml
code_quality:
  stage: test
  script:
    - bin/aimd analyze src/ --format=gitlab --no-progress > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Violations appear inline in the **Changes** tab of your Merge Request.

---

## Comparison table

| Format         | Readable | Machine   | Grouping                     | CI Integration             |
| -------------- | -------- | --------- | ---------------------------- | -------------------------- |
| `text`         | Good     | Parseable | `--group-by`                 | Any (exit code)            |
| `text-verbose` | Best     | No        | `--group-by` (default: file) | Any (exit code)            |
| `json`         | No       | Yes       | Built-in (by file)           | Custom scripts             |
| `checkstyle`   | No       | Yes       | Built-in (by file)           | Jenkins, SonarQube         |
| `sarif`        | No       | Yes       | Built-in                     | GitHub, VS Code, JetBrains |
| `gitlab`       | No       | Yes       | Flat list                    | GitLab MR widget           |

### Exit codes

All formats use the same exit codes:

| Exit code | Meaning                                              |
| --------- | ---------------------------------------------------- |
| 0         | No errors (warnings are allowed)                     |
| 1         | At least one error-severity violation                |
| 2         | Runtime error (invalid config, file not found, etc.) |
