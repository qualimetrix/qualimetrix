# Output Formats

AI Mess Detector supports 10 output formats. Choose the one that fits your workflow.

```bash
bin/aimd check src/ --format=<format>
```

---

## summary (default)

Health-oriented overview showing project health scores, worst offenders, and violation summary. This is the default CLI output designed for quick project assessment.

**When to use:** Local development, quick project health overview.

**Key features:**

- 6 health dimensions with progress bars (complexity, cohesion, coupling, typing, maintainability, overall)
- Top-3 worst namespaces and classes with health scores
- Violation count with tech debt estimate (including debt density per 1K LOC)
- Contextual hints for next steps

**Example output:**

```
AI Mess Detector — 45 files analyzed, 1.23s

  Complexity     ████████████████░░░░  78 Strong
  Cohesion       ██████████████░░░░░░  68 Acceptable
  Coupling       ████████████░░░░░░░░  59 Acceptable
  Typing         ██████████████████░░  88 Strong
  Maintainability████████████████░░░░  80 Good
  Overall        ██████████████░░░░░░  72 Acceptable

Worst namespaces:
  App\Service           52 Weak      | App\Repository        61 Acceptable
  App\Controller        55 Acceptable

Worst classes:
  App\Service\OrderService          38 Critical  | App\Service\UserService   45 Weak
  App\Repository\OrderRepository    51 Weak

Violations: 12 errors, 8 warnings | Tech debt: 4h 30m (2.1/1K LOC)

Hint: Run with --namespace=App\\Service to drill down into the worst namespace
```

**Drill-down with `--namespace` and `--class`:**

```bash
# Show violations for a specific namespace subtree
bin/aimd check src/ --namespace=App\\Service

# Show violations for a specific class
bin/aimd check src/ --class=App\\Service\\UserService
```

**Detail mode with `--detail`:**

```bash
# Append grouped violation list (default limit: 200)
bin/aimd check src/ --detail

# Show all violations (no limit)
bin/aimd check src/ --detail=all

# Custom limit
bin/aimd check src/ --detail=50
```

!!! note
    `--detail` is auto-enabled when using `--namespace` or `--class`. It also works with `--format=text` to append a grouped violation list after the one-line-per-violation output.

---

## text

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

!!! warning "Deprecated"
    `text-verbose` is deprecated. Use `--format=text --detail` instead, which provides the same grouped, multi-line violation output alongside the compact one-line format.

    ```bash
    # Replaces: bin/aimd check src/ --format=text-verbose
    bin/aimd check src/ --format=text --detail
    ```

---

## json

Machine-readable JSON output. Summary-oriented format with health scores, worst offenders, and a capped list of violations.

**When to use:** Custom scripts, dashboards, programmatic processing.

**Example output:**

```json
{
    "meta": {
        "version": "1.0.0",
        "package": "aimd",
        "timestamp": "2025-01-15T10:30:00+00:00"
    },
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "duration": 1.234,
        "violationCount": 3,
        "errorCount": 2,
        "warningCount": 1,
        "techDebtMinutes": 270,
        "debtPer1kLoc": 2.1
    },
    "health": {
        "complexity": {
            "score": 78.0,
            "label": "Strong",
            "threshold": {"warning": 50, "error": 25},
            "decomposition": []
        },
        "overall": {
            "score": 72.0,
            "label": "Acceptable",
            "threshold": {"warning": 50, "error": 25},
            "decomposition": []
        }
    },
    "worstNamespaces": [
        {
            "symbolPath": "App\\Service",
            "healthOverall": 52.0,
            "label": "Weak",
            "reason": "high coupling",
            "violationCount": 15,
            "classCount": 8,
            "healthScores": {}
        }
    ],
    "worstClasses": [
        {
            "symbolPath": "App\\Service\\UserService",
            "healthOverall": 45.0,
            "label": "Weak",
            "reason": "low cohesion",
            "violationCount": 8,
            "file": "src/Service/UserService.php",
            "metrics": {},
            "healthScores": {}
        }
    ],
    "violations": [
        {
            "file": "src/Service/UserService.php",
            "line": 42,
            "symbol": "App\\Service\\UserService::calculate",
            "namespace": "App\\Service",
            "rule": "complexity.cyclomatic",
            "code": "complexity.cyclomatic.method",
            "severity": "error",
            "message": "Cyclomatic complexity: 15 (threshold: 10) — too many code paths",
            "recommendation": null,
            "metricValue": 15,
            "threshold": 10,
            "techDebtMinutes": 30
        }
    ],
    "violationsMeta": {
        "total": 3,
        "limit": 50,
        "truncated": false,
        "byRule": {
            "complexity.cyclomatic": 2,
            "coupling.cbo": 1
        }
    }
}
```

**Options:**

```bash
# Control violation limit (default: 50)
bin/aimd check src/ --format=json --format-opt=limit=100

# Show all violations (no limit)
bin/aimd check src/ --format=json --format-opt=violations=all

# Control number of worst offenders (default: 10)
bin/aimd check src/ --format=json --format-opt=top=20
```

**CI usage:**

```bash
bin/aimd check src/ --format=json --no-progress > report.json
```

---

## metrics-json

Raw metric values for every symbol (file, class, method, namespace). Unlike `json` which outputs violations, `metrics-json` exports the underlying metric data that rules evaluate.

**When to use:** Custom dashboards, trend analysis, data science pipelines, or building your own quality gates on raw metrics.

**Example output (abbreviated):**

```json
{
    "version": "1.0.0",
    "package": "aimd",
    "timestamp": "2025-01-15T10:30:00+00:00",
    "symbols": [
        {
            "type": "file",
            "name": "src/Service/UserService.php",
            "file": "src/Service/UserService.php",
            "line": 1,
            "metrics": {
                "loc": 150,
                "lloc": 120,
                "classCount": 1
            }
        },
        {
            "type": "class",
            "name": "App\\Service\\UserService",
            "file": "src/Service/UserService.php",
            "line": 10,
            "metrics": {
                "methodCount": 8,
                "propertyCount": 3,
                "lcom4": 2,
                "wmc": 35,
                "ca": 5,
                "ce": 12,
                "cbo": 17,
                "instability": 0.71
            }
        },
        {
            "type": "method",
            "name": "App\\Service\\UserService::calculate",
            "file": "src/Service/UserService.php",
            "line": 42,
            "metrics": {
                "ccn": 15,
                "cognitive": 22,
                "halstead.volume": 384.5,
                "loc": 35
            }
        }
    ],
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "duration": 1.234
    }
}
```

**Usage:**

```bash
bin/aimd check src/ --format=metrics-json --no-progress > metrics.json
```

!!! note
    The `metrics-json` format exports **all collected metrics**, not just those that triggered violations. This makes it useful for tracking metric trends over time, even for code that passes all rules.

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
bin/aimd check src/ --format=checkstyle --no-progress > checkstyle.xml
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
  run: bin/aimd check src/ --format=sarif --no-progress > results.sarif

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
    - bin/aimd check src/ --format=gitlab --no-progress > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Violations appear inline in the **Changes** tab of your Merge Request.

---

## github

GitHub Actions workflow command format. Produces inline annotations that appear directly in PR diffs when running in GitHub Actions.

**When to use:** GitHub Actions CI. Simpler setup than SARIF — no upload step needed.

**Example output:**

```
::warning file=src/Service/UserService.php,line=87,title=size.method-count.class::Class has 22 methods, max recommended is 20
::error file=src/Service/UserService.php,line=42,title=complexity.cyclomatic.method::Cyclomatic complexity is 15, max allowed is 10
```

**Severity mapping:**

| AIMD Severity | GitHub Command |
| ------------- | -------------- |
| warning       | `::warning`    |
| error         | `::error`      |

**CI usage (GitHub Actions):**

```yaml
- name: Run AIMD
  run: vendor/bin/aimd check src/ --format=github --fail-on=error --no-progress
```

Annotations appear directly on the changed lines in your pull request — no SARIF upload needed.

!!! tip
    Use `--format=github` for quick inline annotations. Use `--format=sarif` if you also want results in the GitHub Security tab.

---

## html

Interactive treemap report with D3.js visualization. Generates a self-contained single HTML file with namespace/class hierarchy.

**When to use:** Project-wide visualization, stakeholder reports, team reviews.

**Key features:**

- Namespace/class hierarchy with LOC-proportional sizing
- Color-coded health scores per node
- Click to drill down into namespaces
- Detail panel with metrics, violations, and decomposition
- Self-contained single HTML file (no external dependencies)

**Usage:**

```bash
bin/aimd check src/ --format=html -o report.html
```

**Example workflow:**

```bash
# Generate and open the report
bin/aimd check src/ --format=html -o report.html
open report.html  # macOS
xdg-open report.html  # Linux
```

!!! note
    The `-o` (output) flag is recommended with `html` format. Without it, HTML content is written to stdout.

---

## Comparison table

| Format         | Readable    | Machine   | Grouping                     | CI Integration             |
| -------------- | ----------- | --------- | ---------------------------- | -------------------------- |
| `summary`      | Best        | No        | Health scores, drill-down    | Any (exit code)            |
| `text`         | Good        | Parseable | `--group-by`                 | Any (exit code)            |
| `text-verbose` | Good        | No        | `--group-by` (default: file) | Any (exit code)            |
| `json`         | No          | Yes       | Built-in (by file)           | Custom scripts             |
| `metrics-json` | No          | Yes       | Built-in (by symbol)         | Custom scripts, dashboards |
| `checkstyle`   | No          | Yes       | Built-in (by file)           | Jenkins, SonarQube         |
| `sarif`        | No          | Yes       | Built-in                     | GitHub, VS Code, JetBrains |
| `gitlab`       | No          | Yes       | Flat list                    | GitLab MR widget           |
| `github`       | No          | No        | Flat list                    | GitHub Actions annotations |
| `html`         | Interactive | No        | Treemap hierarchy            | Reports, reviews           |

### Exit codes

All formats use the same exit codes:

| Exit code | Meaning                               |
| --------- | ------------------------------------- |
| 0         | No violations                         |
| 1         | At least one warning (but no errors)  |
| 2         | At least one error-severity violation |

With `--fail-on=error`, warnings no longer cause exit code 1 — only errors trigger a non-zero exit.
