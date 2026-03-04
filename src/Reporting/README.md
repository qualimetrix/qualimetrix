# Reporting — Output Formatting

## Overview

Reporting is responsible for formatting analysis results for user output. It supports different formats through a formatter registry.

## PHPMD Compatibility

**Principle:** output formats should be compatible with PHPMD for a seamless tool replacement.

| Aspect | Compatibility | Comment |
|--------|---------------|---------|
| **Output formats** | Full | text, json, xml, checkstyle — identical to PHPMD |
| **Input parameters** | No | Our options are richer; compatibility would limit them |
| **Configuration** | No | Custom YAML format, different structure |

### PHPMD-Compatible Formats

- **text** — text output (identical format)
- **xml** — PHPMD XML format
- **json** — PHPMD JSON format
- **checkstyle** — Checkstyle XML

**Benefits:** seamless PHPMD replacement in CI/CD, use of existing IDE plugins, integration with existing tools.

## Structure

```
Reporting/
├── Report.php                    # Report aggregate
├── ReportBuilder.php             # Builder for creating reports
└── Formatter/
    ├── FormatterInterface.php    # Formatter contract
    ├── FormatterRegistryInterface.php  # Registry contract
    ├── FormatterRegistry.php     # Registry implementation
    └── TextFormatter.php         # Text output
```

## Contracts

### FormatterInterface

```php
namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Reporting\Report;

interface FormatterInterface
{
    /**
     * Formats the report into a string for output.
     */
    public function format(Report $report): string;

    /**
     * Unique formatter name (used in --format=NAME).
     */
    public function getName(): string;
}
```

### FormatterRegistryInterface

```php
namespace AiMessDetector\Reporting\Formatter;

interface FormatterRegistryInterface
{
    /**
     * Returns a formatter by name.
     *
     * @throws \InvalidArgumentException If the formatter is not found
     */
    public function get(string $name): FormatterInterface;

    /**
     * Checks whether a formatter exists.
     */
    public function has(string $name): bool;

    /**
     * Returns a list of available formatters.
     *
     * @return string[]
     */
    public function getAvailableNames(): array;
}
```

### FormatterRegistry

Registry implementation — stores formatters by name, throws `InvalidArgumentException` when a non-existent formatter is requested.

### Report (Value Object)

```php
final readonly class Report
{
    public function __construct(
        public array $violations,
        public int $filesAnalyzed,
        public int $filesSkipped,
        public float $duration,
        public int $errorCount,
        public int $warningCount,
    ) {}

    public function isEmpty(): bool;
    public function getTotalViolations(): int;
}
```

### TextFormatter

**Name:** `text`

**Output format:**
- Header with title
- Violations (if any) or "No violations found"
- Summary: files analyzed/skipped, errors, warnings, time

## Output Example (TextFormatter)

```
AI Mess Detector Report
==================================================

  [ERROR] src/Service/UserService.php:42
    App\Service\UserService::calculateDiscount
    Rule: cyclomatic-complexity
    Cyclomatic complexity of 25 exceeds threshold

--------------------------------------------------
Files: 42 analyzed, 1 skipped | Errors: 2 | Warnings: 1 | Time: 0.23s
```

## Implemented Formats

| Format | Name | Description | Integration |
|--------|------|-------------|-------------|
| Text | `text` | Human-readable text output | CLI |
| JSON | `json` | PHPMD-compatible JSON for CI/CD | Generic CI/CD |
| Checkstyle | `checkstyle` | Checkstyle XML for CI systems | Jenkins, SonarQube |
| XML (PHPMD) | `xml` | PHPMD-compatible XML | IDE plugins |
| SARIF | `sarif` | SARIF 2.1.0 for static analysis | GitHub, VS Code, JetBrains |
| GitLab | `gitlab` | Code Climate JSON for GitLab MR | GitLab CI |

## JsonFormatter

**Name:** `json`

PHPMD-compatible JSON for CI/CD. Structure: violations + summary. Example:

```json
{
  "violations": [{
    "file": "src/Service/UserService.php",
    "line": 42,
    "severity": "error",
    "message": "...",
    "rule": "cyclomatic-complexity"
  }],
  "summary": { "filesAnalyzed": 42, "errors": 2, "warnings": 1 }
}
```

---

## CheckstyleFormatter

**Name:** `checkstyle`

Checkstyle XML for Jenkins/SonarQube. Example:

```xml
<checkstyle version="10.0">
  <file name="src/Service/UserService.php">
    <error line="42" severity="error" message="..." source="cyclomatic-complexity"/>
  </file>
</checkstyle>
```

---

## XmlFormatter

**Name:** `xml`

PHPMD-compatible XML. Grouped by files, priority mapping:

| AIMD Severity | PMD Priority |
|---------------|--------------|
| Error | 1 |
| Warning | 2 |
| Info | 3 |

---

## SarifFormatter

**Name:** `sarif`

SARIF 2.1.0 for GitHub Security, VS Code, Azure DevOps, JetBrains IDEs.

### Level Mapping

| AIMD Severity | SARIF Level |
|---------------|-------------|
| Error | `error` |
| Warning | `warning` |
| Info | `note` |

### GitHub Actions Integration

```yaml
- name: Run AI Mess Detector
  run: bin/aimd analyze src/ --format=sarif > results.sarif

- name: Upload SARIF results
  uses: github/codeql-action/upload-sarif@v2
  with:
    sarif_file: results.sarif
```

Results will appear in **Security** -> **Code scanning alerts**.

---

## GitLabCodeQualityFormatter

**Name:** `gitlab`

Code Climate JSON for GitLab MR. Uses fingerprinting for tracking fixes.

### Severity Mapping

| AIMD Severity | GitLab Severity |
|---------------|-----------------|
| Error | `critical` |
| Warning | `major` |
| Info | `minor` |

### GitLab CI Integration

```yaml
code_quality:
  stage: test
  script:
    - bin/aimd analyze src/ --format=gitlab > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Results will appear in the **Code Quality** tab with inline comments in the MR.

---

## Adding a New Formatter

### Steps

1. Create a `*Formatter.php` class in `src/Reporting/Formatter/`
2. Implement `FormatterInterface` (methods: `format()`, `getName()`)
3. Use it: `bin/aimd analyze src/ --format=myformat`

**Automatic registration:** the class will be registered via `FormatterCompilerPass` — no need to modify `ContainerFactory`.

### Available Data in Report

```php
$violation->severity      // Severity enum (Error, Warning, Info)
$violation->message       // Violation description
$violation->ruleName      // Rule name
$violation->symbolPath    // SymbolPath object
$violation->location      // Location object (file, line)
$violation->metricValue   // int|float|null

$report->violations       // list<Violation>
$report->filesAnalyzed    // int
$report->errorCount       // int
$report->warningCount     // int
$report->duration         // float (seconds)
```

## Formatter Comparison

| Characteristic | Text | JSON | Checkstyle | XML | SARIF | GitLab |
|---|---|---|---|---|---|---|
| **Readability** | High | No | No | Moderate | No | No |
| **CI/CD integration** | No | Generic | Jenkins/SonarQube | IDE | GitHub/Azure | GitLab |
| **IDE support** | No | No | Limited | PHPMD plugins | VS Code/JB | No |
| **PHPMD compatibility** | Full | Full | Full | Full | No | No |
| **Fingerprinting** | No | No | No | No | No | Yes |
| **Output** | STDOUT | STDOUT | STDOUT | STDOUT | STDOUT | STDOUT |

### Choosing the Right Format

- **CLI usage** -> `text`
- **Generic CI/CD** (GitLab CI, CircleCI, Travis) -> `json`
- **Jenkins / SonarQube** -> `checkstyle`
- **Legacy IDE** -> `xml`
- **GitHub** -> `sarif`
- **GitLab** -> `gitlab`
- **VS Code** -> `sarif`
- **JetBrains IDE** -> `sarif`

## Planned Formats

Possible extensions:

- **HTML** — interactive web report
- **Markdown** — for documentation and PR comments
- **JUnit XML** — for integration with test frameworks
