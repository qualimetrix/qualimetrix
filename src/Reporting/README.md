# Reporting — Output Formatting

## Overview

Reporting is responsible for formatting analysis results for user output. It supports different formats through a formatter registry.

## PHPMD Compatibility

**Principle:** output formats should be compatible with PHPMD for a seamless tool replacement.

| Aspect               | Compatibility | Comment                                                |
| -------------------- | ------------- | ------------------------------------------------------ |
| **Output formats**   | Partial       | text, checkstyle — compatible with PHPMD               |
| **Input parameters** | No            | Our options are richer; compatibility would limit them |
| **Configuration**    | No            | Custom YAML format, different structure                |

### PHPMD-Compatible Formats

- **text** — text output (identical format)
- **checkstyle** — Checkstyle XML

**Note:** `--format=json` uses a custom summary structure (health scores, worst offenders, violations) and is NOT PHPMD-compatible.

**Benefits:** seamless PHPMD replacement in CI/CD, use of existing IDE plugins, integration with existing tools.

## Structure

```
Reporting/
├── Report.php                              # Report aggregate (with health scores, worst offenders, tech debt)
├── ReportBuilder.php                       # Builder for creating reports
├── FormatterContext.php                    # Context passed to formatters (color, grouping, filters, options)
├── GroupBy.php                             # Grouping mode enum (None, File, Rule, Severity)
├── Health/                                 # Health scoring module
│   ├── HealthScoreResolver.php            # Shared health score resolution (project/namespace/class level)
│   ├── SummaryEnricher.php                # Enriches Report with health scores, worst offenders, tech debt
│   ├── MetricHintProvider.php             # Single source of truth for metric display metadata
│   ├── NamespaceDrillDown.php             # Shared logic for namespace-level drill-down
│   ├── HealthScore.php                    # VO: one health dimension (complexity, cohesion, etc.)
│   ├── WorstOffender.php                  # VO: a namespace or class ranked by health
│   └── DecompositionItem.php              # VO: one contributing metric in a health score breakdown
├── Filter/
│   └── ViolationFilter.php                # Shared violation/offender filtering by namespace/class context
├── Profile/
│   └── ProfileSummaryRenderer.php         # Profiler summary rendering for console
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
    ├── CheckstyleFormatter.php             # Checkstyle XML
    ├── GithubActionsFormatter.php          # GitHub Actions annotation output
    ├── MetricsJsonFormatter.php            # Raw metrics JSON export
    ├── Support/                            # Shared formatter utilities
    │   ├── AnsiColor.php                  # Lightweight ANSI color wrapper
    │   ├── ViolationSorter.php            # Sorting/grouping utility for violations
    │   └── DetailedViolationRenderer.php  # Detailed violation output (--detail mode)
    ├── Summary/
    │   ├── SummaryFormatter.php           # Default: health overview + worst offenders + hints
    │   ├── HealthBarRenderer.php          # Renders ANSI health bars for console output
    │   ├── OffenderListRenderer.php       # Renders worst offender lists for console output
    │   ├── ViolationSummaryRenderer.php   # Renders violation count summary with severity breakdown and tech debt
    │   └── HintRenderer.php              # Renders contextual hints at the bottom of summary output
    ├── Json/
    │   ├── JsonFormatter.php              # Summary-oriented JSON (health, worst offenders, violations)
    │   ├── JsonSanitizer.php              # Sanitizes metric values (NaN/INF → null) for JSON output
    │   ├── JsonHealthSection.php          # Formats health scores section for JSON output
    │   ├── JsonOffenderSection.php        # Formats worst offenders sections for JSON output
    │   └── JsonViolationSection.php       # Formats violations section for JSON output
    ├── Sarif/
    │   ├── SarifFormatter.php             # SARIF 2.1.0
    │   └── SarifRuleCollector.php         # Collects rule metadata for SARIF tool component
    ├── Html/
    │   ├── HtmlFormatter.php              # Interactive HTML report with D3 treemap
    │   ├── HtmlTreeBuilder.php            # Builds namespace tree from MetricRepository
    │   └── HtmlTreeNode.php               # Internal VO for tree construction
    └── GitLabCodeQualityFormatter.php      # GitLab Code Climate JSON
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
        public ?string $namespace = null,  // --namespace filter (boundary-aware prefix)
        public ?string $class = null,      // --class filter (exact FQCN match)
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
        public ?MetricRepositoryInterface $metrics = null,
        public array $healthScores = [],       // array<string, HealthScore>
        public array $worstNamespaces = [],    // list<WorstOffender>
        public array $worstClasses = [],       // list<WorstOffender>
        public int $techDebtMinutes = 0,
        public ?float $debtPer1kLoc = null,    // debt density (min/kLOC), null if no LOC data
    ) {}

    public function isEmpty(): bool;
    public function getTotalViolations(): int;
    public function getViolationsBySeverity(Severity $severity): array;
    public function getExitCode(): int;
}
```

### SummaryEnricher (Health/)

Enriches a base `Report` with health scores, worst offenders, and tech debt. Called in the pipeline between `ReportBuilder::build()` and `Formatter::format()`.

```php
final readonly class SummaryEnricher
{
    public function __construct(DebtCalculator $debtCalculator, MetricHintProvider $hintProvider);
    public function enrich(Report $report): Report;
}
```

### MetricHintProvider (Health/)

Single source of truth for metric display metadata (27 metrics, 6 health decompositions, 5 dimension labels). Used by `SummaryEnricher` and future formatters.

```php
final class MetricHintProvider
{
    public function getLabel(string $metricKey): ?string;
    public function getExplanation(string $metricKey, float $value): string;
    public function getGoodValue(string $metricKey): ?string;
    public function getDirection(string $metricKey): ?string;
    public function getDecomposition(string $healthDimension): array;
    public function getScoreLabel(float $score, float $warnThreshold, float $errThreshold): string;
    public function getHealthDimensionLabel(string $dimension, bool $bad): string;
}
```

### SummaryFormatter

**Name:** `summary` (default) | **Default grouping:** `none`

One-screen health overview with worst offenders and contextual hints. Shows health bars for 6 dimensions (complexity, cohesion, coupling, typing, maintainability, overall), top-3 worst namespaces/classes, violation summary, and actionable hints.

Supports `--namespace` and `--class` for drill-down (filtering worst offenders). Handles edge cases: partial analysis (no health scores), missing metrics, single file (no namespace section), zero violations, narrow terminals (no bars).

ASCII fallback with `AIMD_ASCII=1` env variable.

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
# Drill-down (mutually exclusive, works with summary/text/json)
bin/aimd check src/ --namespace=App\\Service   # filter by namespace prefix (boundary-aware)
bin/aimd check src/ --class=App\\Service\\UserService  # filter by exact FQCN

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

### SummaryFormatter (default)

```
AI Mess Detector — 412 files analyzed, 3.2s

Health █████████████████████░░░░░░░░░ 68% Acceptable

  Complexity      ████████████████░░░░░░░░░░░░░░ 54% Acceptable
  Cohesion        ███████████████████░░░░░░░░░░░ 63% Acceptable
  Coupling        ███████████████████░░░░░░░░░░░ 62% Acceptable
  Typing          ██████████████████████████████ 99% Acceptable
  Maintainability ██████████████████████░░░░░░░░ 74% Acceptable

Worst namespaces
  46 App\Metrics\Halstead (3 classes, 29 violations) — high coupling, high complexity
  49 App\Metrics\Complexity (6 classes, 51 violations) — high coupling

1251 violations (384 errors, 867 warnings) | Tech debt: 63d 5h 35min

Hints: --format=text to see all violations | --namespace="App\Metrics\Halstead" to drill down | --format=health -o report.html for full report
```

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

| Format       | Name           | Description                                    | Integration                |
| ------------ | -------------- | ---------------------------------------------- | -------------------------- |
| Summary      | `summary`      | **Default.** Health overview + worst offenders | CLI                        |
| Text         | `text`         | Compact human-readable text output             | CLI                        |
| Text Verbose | `text-verbose` | Detailed text output with sorting by severity  | CLI                        |
| JSON         | `json`         | Summary-oriented JSON (health + violations)    | AI agents, CI/CD           |
| Checkstyle   | `checkstyle`   | Checkstyle XML for CI systems                  | Jenkins, SonarQube         |
| SARIF        | `sarif`        | SARIF 2.1.0 for static analysis                | GitHub, VS Code, JetBrains |
| GitLab       | `gitlab`       | Code Climate JSON for GitLab MR                | GitLab CI                  |
| Metrics      | `metrics`      | Raw metric values for all symbols              | Dashboards, cross-tool     |
| Health       | `health`       | Interactive treemap report with D3.js          | Browser, CI artifacts      |

## JsonFormatter

**Name:** `json`

Summary-oriented JSON for AI agents, CI/CD, and programmatic consumption. Includes health scores, worst offenders, and violations (top 50 by default). Example:

```json
{
  "meta": { "version": "1.0.0", "package": "aimd", "timestamp": "..." },
  "summary": { "filesAnalyzed": 342, "violationCount": 47, "errorCount": 12, "warningCount": 35, "techDebtMinutes": 270, "debtPer1kLoc": 5.4 },
  "health": { "complexity": { "score": 65, "label": "Acceptable", "threshold": { "warning": 50, "error": 25 }, "decomposition": [...] } },
  "worstNamespaces": [{ "symbolPath": "App\\Payment", "healthOverall": 31, "reason": "low cohesion, high complexity" }],
  "worstClasses": [{ "symbolPath": "App\\Payment\\PaymentService", "file": "src/...", "healthOverall": 28, "metrics": {...} }],
  "violations": [{ "file": "src/...", "line": 42, "symbol": "...", "namespace": "App\\Service", "rule": "complexity.cyclomatic", "code": "complexity.cyclomatic.method", "severity": "error", "message": "...", "metricValue": 15, "threshold": 10 }]
}
```

**Options:** `--format-opt=violations=all|0|N` (default: 50), `--format-opt=top=N` (default: 10 offenders). `--detail` shows violations (default limit: 200, `--detail=all` for unlimited). `--namespace`/`--class` filters violations and worst offenders. Partial analysis: `health` is `null`.

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

**Name:** `metrics`

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
bin/aimd check src/ --format=metrics > metrics.json
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
$violation->message       // Violation description (technical, for text/checkstyle/sarif)
$violation->recommendation  // ?string — human-readable message (for summary/detail/json)
$violation->threshold     // int|float|null — threshold that was exceeded
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
$report->healthScores     // array<string, HealthScore> — per-dimension health scores
$report->worstNamespaces  // list<WorstOffender> — worst namespaces by health
$report->worstClasses     // list<WorstOffender> — worst classes by health
$report->techDebtMinutes  // int — total remediation time
$report->debtPer1kLoc     // ?float — debt density (minutes per 1K LOC)
```

## Formatter Comparison

| Characteristic          | Summary | Text   | Text Verbose | JSON    | Checkstyle        | SARIF        | GitLab | Metrics | Health          |
| ----------------------- | ------- | ------ | ------------ | ------- | ----------------- | ------------ | ------ | ------- | --------------- |
| **ANSI Colors**         | Yes     | Yes    | Yes          | No      | No                | No           | No     | No      | No              |
| **Health overview**     | Yes     | No     | No           | No      | No                | No           | No     | No      | Yes             |
| **Grouping**            | No      | No     | Yes (file)   | No      | No                | No           | No     | No      | No              |
| **Readability**         | High    | High   | High         | No      | No                | No           | No     | No      | Visual          |
| **CI/CD integration**   | No      | No     | No           | Generic | Jenkins/SonarQube | GitHub/Azure | GitLab | Custom  | CI artifacts    |
| **IDE support**         | No      | No     | No           | No      | Limited           | VS Code/JB   | No     | No      | No              |
| **PHPMD compatibility** | No      | Full   | No           | No      | Full              | No           | No     | No      | No              |
| **Fingerprinting**      | No      | No     | No           | No      | No                | No           | Yes    | No      | No              |
| **Output**              | STDOUT  | STDOUT | STDOUT       | STDOUT  | STDOUT            | STDOUT       | STDOUT | STDOUT  | File (--output) |

### Choosing the Right Format

- **CLI usage (overview)** -> `summary` (default)
- **CLI usage (compact violations)** -> `text`
- **CLI usage (detailed)** -> `text-verbose`
- **Generic CI/CD** (GitLab CI, CircleCI, Travis) -> `json`
- **Jenkins / SonarQube** -> `checkstyle`
- **GitHub** -> `sarif`
- **GitLab** -> `gitlab`
- **VS Code** -> `sarif`
- **JetBrains IDE** -> `sarif`
- **Custom dashboards / metrics analysis** -> `metrics`
- **Visual exploration / stakeholder reports** -> `health`

## HtmlFormatter

**Name:** `health`

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
bin/aimd check src/ --format=health --output=report.html

# Also works with stdout (but warns on TTY)
bin/aimd check src/ --format=health > report.html
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
