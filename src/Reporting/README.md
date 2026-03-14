# Reporting — Output Formatting

## Overview

Reporting is responsible for formatting analysis results for user output. It supports different formats through a formatter registry.

## PHPMD Compatibility

**Principle:** output formats should be compatible with PHPMD for a seamless tool replacement.

| Aspect               | Compatibility | Comment                                                |
| -------------------- | ------------- | ------------------------------------------------------ |
| **Output formats**   | Partial       | text, json, checkstyle — compatible with PHPMD         |
| **Input parameters** | No            | Our options are richer; compatibility would limit them |
| **Configuration**    | No            | Custom YAML format, different structure                |

### PHPMD-Compatible Formats

- **text** — text output (identical format)
- **json** — PHPMD JSON format
- **checkstyle** — Checkstyle XML

**Benefits:** seamless PHPMD replacement in CI/CD, use of existing IDE plugins, integration with existing tools.

## Structure

```
Reporting/
├── Report.php                              # Report aggregate
├── ReportBuilder.php                       # Builder for creating reports
├── FormatterContext.php                    # Context passed to formatters (color, grouping, options)
├── GroupBy.php                             # Grouping mode enum (None, File, Rule, Severity)
├── AnsiColor.php                           # Lightweight ANSI color wrapper
├── ViolationSorter.php                     # Sorting/grouping utility for violations
├── Debt/
│   ├── RemediationTimeRegistry.php        # Rule name -> estimated remediation minutes
│   ├── DebtSummary.php                    # Value Object: total, per-file, per-rule debt
│   └── DebtCalculator.php                 # Calculates DebtSummary from violations
└── Formatter/
    ├── FormatterInterface.php              # Formatter contract
    ├── FormatterRegistryInterface.php      # Registry contract
    ├── FormatterRegistry.php               # Registry implementation
    ├── TextFormatter.php                   # Compact text output (with colors)
    ├── TextVerboseFormatter.php            # Verbose text output (grouped, colored)
    ├── JsonFormatter.php                   # PHPMD-compatible JSON
    ├── CheckstyleFormatter.php             # Checkstyle XML
    ├── SarifFormatter.php                  # SARIF 2.1.0
    ├── GitLabCodeQualityFormatter.php      # GitLab Code Climate JSON
    ├── MetricsJsonFormatter.php            # Raw metrics JSON export
    ├── HtmlFormatter.php                   # Interactive HTML report with D3 treemap
    └── Html/
        ├── HtmlTreeBuilder.php            # Builds namespace tree from MetricRepository
        └── HtmlTreeNode.php               # Internal VO for tree construction
```

## Contracts

### FormatterInterface

```php
namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;

interface FormatterInterface
{
    /**
     * Formats the report into a string for output.
     */
    public function format(Report $report, FormatterContext $context): string;

    /**
     * Unique formatter name (used in --format=NAME).
     */
    public function getName(): string;

    /**
     * Returns the default grouping mode for this formatter.
     */
    public function getDefaultGroupBy(): GroupBy;
}
```

### FormatterContext

```php
final readonly class FormatterContext
{
    public function __construct(
        public bool $useColor = true,      // from OutputInterface::isDecorated()
        public GroupBy $groupBy = GroupBy::None,
        public array $options = [],        // from --format-opt key=value
        public string $basePath = '',      // for relativizing file paths
        public bool $partialAnalysis = false, // git:staged or similar partial mode
    ) {}

    public function getOption(string $key, string $default = ''): string;
    public function relativizePath(string $filePath): string;
}
```

### GroupBy

```php
enum GroupBy: string
{
    case None = 'none';
    case File = 'file';
    case Rule = 'rule';
    case Severity = 'severity';
}
```

### FormatterRegistryInterface

```php
namespace AiMessDetector\Reporting\Formatter;

interface FormatterRegistryInterface
{
    /**
     * Returns formatter by name.
     *
     * @throws InvalidArgumentException If formatter not found
     */
    public function get(string $name): FormatterInterface;

    /**
     * Checks if formatter exists.
     */
    public function has(string $name): bool;

    /**
     * Returns list of available formatter names.
     *
     * @return list<string>
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
    public function getViolationsBySeverity(Severity $severity): array;
    public function getExitCode(): int;
}
```

### TextFormatter

**Name:** `text` | **Default grouping:** `none`

Compact, parseable text output (one line per violation). GCC/Clang-compatible format.
Supports ANSI colors for severity and summary (auto-detected, disabled with `--no-ansi`).

**Output format:** `file:line: severity[code]: message (symbol)`

### TextVerboseFormatter

**Name:** `text-verbose` | **Default grouping:** `file`

Human-readable verbose output with:
- Violations grouped by file (default), rule, severity, or flat
- File headers with violation count
- ANSI colors for severity tags and summary
- Compact violation format (2 lines per violation)
- Metric values highlighted when present

## CLI Options

```bash
# Grouping (overrides formatter default)
bin/aimd check src/ --group-by=file      # group by file
bin/aimd check src/ --group-by=rule      # group by rule name
bin/aimd check src/ --group-by=severity  # group by severity
bin/aimd check src/ --group-by=none      # flat list

# Formatter-specific options
bin/aimd check src/ --format-opt key=value

# Disable colors
bin/aimd check src/ --no-ansi
```

## Output Examples

### TextFormatter (`--format=text`)

```
src/Service/UserService.php:42: error[cyclomatic-complexity]: Cyclomatic complexity of 25 exceeds threshold (UserService::calculateDiscount)
src/Service/UserService.php:120: warning[cyclomatic-complexity]: Cyclomatic complexity of 12 exceeds threshold (UserService::processOrder)

1 error(s), 1 warning(s) in 1 file(s)
```

### TextVerboseFormatter (`--format=text-verbose`)

```
AI Mess Detector Report
──────────────────────────────────────────────────

src/Service/UserService.php (2)

  ERROR :42  App\Service\UserService::calculateDiscount
    Cyclomatic complexity of 25 exceeds threshold (25) [cyclomatic-complexity]

  WARN :120  App\Service\UserService::processOrder
    Cyclomatic complexity of 12 exceeds threshold (12) [cyclomatic-complexity]

──────────────────────────────────────────────────
Files: 1 analyzed, 0 skipped | Errors: 1 | Warnings: 1 | Time: 0.23s
```

## Implemented Formats

| Format       | Name           | Description                                   | Integration                |
| ------------ | -------------- | --------------------------------------------- | -------------------------- |
| Text         | `text`         | Compact human-readable text output            | CLI                        |
| Text Verbose | `text-verbose` | Detailed text output with sorting by severity | CLI                        |
| JSON         | `json`         | PHPMD-compatible JSON for CI/CD               | Generic CI/CD              |
| Checkstyle   | `checkstyle`   | Checkstyle XML for CI systems                 | Jenkins, SonarQube         |
| SARIF        | `sarif`        | SARIF 2.1.0 for static analysis               | GitHub, VS Code, JetBrains |
| GitLab       | `gitlab`       | Code Climate JSON for GitLab MR               | GitLab CI                  |
| Metrics JSON | `metrics-json` | Raw metric values for all symbols             | Dashboards, cross-tool     |
| HTML         | `html`         | Interactive treemap report with D3.js         | Browser, CI artifacts      |

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
    "rule": "cyclomatic-complexity",
    "code": "complexity.method"
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

---

## SarifFormatter

**Name:** `sarif`

SARIF 2.1.0 for GitHub Security, VS Code, Azure DevOps, JetBrains IDEs.

### Level Mapping

| AIMD Severity | SARIF Level |
| ------------- | ----------- |
| Error         | `error`     |
| Warning       | `warning`   |
| Info          | `note`      |

### Related Locations

Violations with `relatedLocations` (e.g., code duplication violations pointing to other occurrences) are rendered as SARIF `relatedLocations` entries. This provides clickable cross-references in GitHub Code Scanning, VS Code, and JetBrains IDEs.

### GitHub Actions Integration

```yaml
- name: Run AI Mess Detector
  run: bin/aimd check src/ --format=sarif > results.sarif

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
| ------------- | --------------- |
| Error         | `critical`      |
| Warning       | `major`         |
| Info          | `minor`         |

### GitLab CI Integration

```yaml
code_quality:
  stage: test
  script:
    - bin/aimd check src/ --format=gitlab > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Results will appear in the **Code Quality** tab with inline comments in the MR.

---

## MetricsJsonFormatter

**Name:** `metrics-json`

Exports raw metric values for all symbols (methods, classes, namespaces, files) as JSON. Unlike `json` which outputs violations, this formatter outputs the actual metric data collected during analysis — useful for cross-tool comparison, metrics analysis, and custom dashboards.

### Output Structure

```json
{
  "version": "1.0.0",
  "package": "aimd",
  "timestamp": "2025-01-15T10:30:00+00:00",
  "symbols": [
    {
      "type": "method",
      "name": "App\\Service\\UserService::calculateDiscount",
      "file": "src/Service/UserService.php",
      "line": 42,
      "metrics": {
        "ccn": 25,
        "cognitive_complexity": 18,
        "npath": 128,
        "loc": 45
      }
    }
  ],
  "summary": {
    "filesAnalyzed": 42,
    "filesSkipped": 0,
    "duration": 1.234,
    "violations": 3,
    "errors": 2,
    "warnings": 1
  }
}
```

### Usage

```bash
bin/aimd check src/ --format=metrics-json > metrics.json
```

---

## Adding a New Formatter

### Steps

1. Create a `*Formatter.php` class in `src/Reporting/Formatter/`
2. Implement `FormatterInterface` (methods: `format(Report, FormatterContext)`, `getName()`, `getDefaultGroupBy()`)
3. Use it: `bin/aimd check src/ --format=myformat`

**Automatic registration:** the class will be registered via `FormatterCompilerPass` — no need to modify `ContainerFactory`.

### Available Data in Report

```php
$violation->severity      // Severity enum (Error, Warning, Info)
$violation->message       // Violation description
$violation->ruleName      // Rule name
$violation->violationCode // Stable violation code for identification
$violation->symbolPath    // SymbolPath object
$violation->location      // Location object (file, line); check isNone() for architectural violations
$violation->metricValue   // int|float|null

$report->violations       // list<Violation>
$report->filesAnalyzed    // int
$report->errorCount       // int
$report->warningCount     // int
$report->duration         // float (seconds)
```

## Formatter Comparison

| Characteristic          | Text   | Text Verbose | JSON    | Checkstyle        | SARIF        | GitLab | Metrics JSON | HTML            |
| ----------------------- | ------ | ------------ | ------- | ----------------- | ------------ | ------ | ------------ | --------------- |
| **ANSI Colors**         | Yes    | Yes          | No      | No                | No           | No     | No           | No              |
| **Grouping**            | No     | Yes (file)   | No      | No                | No           | No     | No           | No              |
| **Readability**         | High   | High         | No      | No                | No           | No     | No           | Visual          |
| **CI/CD integration**   | No     | No           | Generic | Jenkins/SonarQube | GitHub/Azure | GitLab | Custom       | CI artifacts    |
| **IDE support**         | No     | No           | No      | Limited           | VS Code/JB   | No     | No           | No              |
| **PHPMD compatibility** | Full   | No           | Full    | Full              | No           | No     | No           | No              |
| **Fingerprinting**      | No     | No           | No      | No                | No           | Yes    | No           | No              |
| **Output**              | STDOUT | STDOUT       | STDOUT  | STDOUT            | STDOUT       | STDOUT | STDOUT       | File (--output) |

### Choosing the Right Format

- **CLI usage (compact)** -> `text`
- **CLI usage (detailed)** -> `text-verbose`
- **Generic CI/CD** (GitLab CI, CircleCI, Travis) -> `json`
- **Jenkins / SonarQube** -> `checkstyle`
- **GitHub** -> `sarif`
- **GitLab** -> `gitlab`
- **VS Code** -> `sarif`
- **JetBrains IDE** -> `sarif`
- **Custom dashboards / metrics analysis** -> `metrics-json`
- **Visual exploration / stakeholder reports** -> `html`

## HtmlFormatter

**Name:** `html`

Self-contained interactive HTML report with D3.js treemap visualization. All CSS, JS, and data are embedded in a single file — works offline, easy to share.

### Features

- **Treemap** — namespace hierarchy colored by health score (blue = healthy, red = unhealthy)
- **Drill-down** — click namespaces to explore deeper
- **Detail panel** — health bars, worst offenders, metrics table, violations
- **Metric selector** — switch coloring between health scores (complexity, cohesion, coupling, etc.)
- **Search** — find namespaces and classes by name
- **URL hash navigation** — deep linking via `#ns:App/Payment`, `#cl:App/Service`
- **Dark mode** — adapts to system preference
- **Partial analysis warning** — banner when using `--analyze=git:staged`

### Usage

```bash
# Generate HTML report (recommended: save to file)
bin/aimd check src/ --format=html --output=report.html

# Also works with stdout (but warns on TTY)
bin/aimd check src/ --format=html > report.html
```

### Architecture

- `HtmlFormatter` — implements `FormatterInterface`, orchestrates assembly
- `Html/HtmlTreeBuilder` — builds namespace hierarchy from `MetricRepositoryInterface`
- `Html/HtmlTreeNode` — mutable VO for tree construction
- `Template/` — HTML skeleton, CSS, JS source and build pipeline
- `Template/dist/` — built JS artifacts (committed to git, no Node.js at runtime)

### JS Build Pipeline

```bash
cd src/Reporting/Template
npm install        # first time only
npm test           # vitest unit tests
npm run build      # produces dist/report.min.js + dist/d3.min.js
npm run dev        # vite dev server with HMR (uses dev.html)
```

---

## Planned Formats

Possible extensions:

- **Markdown** — for documentation and PR comments
- **JUnit XML** — for integration with test frameworks
