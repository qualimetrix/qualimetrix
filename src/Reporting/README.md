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

**Note:** `--format=json` uses a custom summary structure (health scores, worst offenders, findings) and is NOT PHPMD-compatible.

**Benefits:** seamless PHPMD replacement in CI/CD, use of existing IDE plugins, integration with existing tools.

## Structure

```
Reporting/
├── Report.php                              # Report aggregate (with health scores, worst offenders, tech debt)
├── ReportBuilder.php                       # Builder for creating reports
├── ReportCoverage.php                      # Reporting-safe coverage projection
├── CoverageFailure.php                     # One projected parse/processing failure
├── FormatterContext.php                    # Context passed to formatters (color, grouping, filters, options)
├── GroupBy.php                             # Grouping mode enum (None, File, Rule, Severity)
├── GraphProjection/                        # Dependency graph output projection
│   ├── Contract/
│   │   ├── DependencyGraphProjectionInterface.php # Console-facing projection port
│   │   └── GraphProjectionRequest.php      # Immutable DOT/JSON projection request
│   ├── DependencyGraphProjector.php        # Internal format dispatcher
│   ├── DotExporter.php                     # Internal DOT projection
│   ├── DotExporterOptions.php              # Internal DOT options
│   ├── JsonGraphExporter.php               # Internal JSON projection
│   └── README.md
├── Health/                                 # Health output assembly over capability contracts
│   ├── HealthScoreResolver.php            # Selects project/namespace/class contract values
│   ├── SummaryEnricher.php                # Assembles Report, debt, and impact
│   └── HealthHintProjector.php             # Projects Health metadata for HTML
├── FindingProjection/                      # Ordered user-visible finding projection
│   ├── Contract/                          # Framework-free Git scope port and request/result
│   ├── FindingProjectionOptions.php      # Immutable projection controls
│   ├── FindingProjectionResult.php       # Reported, measured, accepted, and stale facts
│   └── FindingProjector.php              # Authoritative suppression/filtering order
├── Filter/
│   └── FindingFilter.php                # Shared finding/offender filtering by namespace/class context
├── Profile/
│   └── ProfileSummaryRenderer.php         # Profiler summary rendering for console
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
    │   ├── FindingSorter.php            # Sorting/grouping utility for findings
    │   ├── DetailedFindingRenderer.php  # Detailed-output compositor
    │   ├── FindingDetailRenderer.php    # Sorted/grouped finding details
    │   ├── DebtBreakdownRenderer.php      # Per-rule technical-debt details
    │   ├── AcceptedLevelNarrator.php      # "accepted at 25, now 31" fragment for a measured breach
    │   └── CoverageNarrator.php           # Complete/empty/incomplete human coverage summary
    ├── Summary/
    │   ├── SummaryFormatter.php           # Default: health overview + worst offenders + hints
    │   ├── HealthBarRenderer.php          # Renders ANSI health bars for console output
    │   ├── OffenderListRenderer.php       # Renders worst offender lists for console output
    │   ├── FindingSummaryRenderer.php   # Renders finding count summary with severity breakdown and tech debt
    │   ├── HintRenderer.php              # Renders contextual hints at the bottom of summary output
    │   └── TopIssuesRenderer.php          # Renders "Top issues by impact" section
    ├── Json/
    │   ├── JsonFormatter.php              # Summary-oriented JSON (health, worst offenders, findings)
    │   ├── JsonSanitizer.php              # Sanitizes metric values (NaN/INF → null) for JSON output
    │   ├── JsonHealthSection.php          # Formats health scores section for JSON output
    │   ├── JsonOffenderSection.php        # Formats worst offenders sections for JSON output
    │   └── JsonFindingSection.php       # Formats findings section for JSON output
    ├── Sarif/
    │   ├── SarifFormatter.php             # SARIF 2.1.0
    │   └── SarifRuleCollector.php         # Collects rule metadata for SARIF tool component, joined from ChannelPresentationInterface
    ├── Health/
    │   └── HealthTextFormatter.php         # Text-based health report with scores and decomposition
    ├── Html/
    │   ├── HtmlFormatter.php              # Interactive HTML report with D3 treemap
    │   ├── HtmlTreeBuilder.php            # Builds namespace tree from MetricRepository
    │   ├── HtmlTreeNode.php               # Internal VO for tree construction
    │   ├── HtmlDebtCalculator.php         # Computes and aggregates technical debt for HTML reports
    │   ├── HtmlMetricAggregator.php       # Bottom-up metric aggregation for HTML tree
    │   └── HtmlFindingPartitioner.php   # Partitions findings by file/class for HTML tree
    └── GitLabCodeQualityFormatter.php      # GitLab Code Climate JSON
```

## Contracts

### Finding projection

`FindingProjector` owns the framework-free projection order: annotation
suppression, configured path exclusion, configured namespace exclusion,
Baseline ceiling, optional annotation rejoin, and Git scope last. Git scope is
queried through `GitScopeQueryInterface`; its Infrastructure adapter never
leaks into Reporting. Git changes only the reported list and cannot alter the
measured, accepted, or stale Baseline facts.

Configuration-owned `OutputFormat` carries the resolved formatter name to the
Console presenter without adding output policy to the transitional runtime
configuration.

### GraphProjection

[`GraphProjection`](GraphProjection/README.md) owns dependency-graph rendering.
Infrastructure injects `DependencyGraphProjectionInterface` and passes a
`GraphProjectionRequest`; the dispatcher and DOT/JSON exporters are internal.
The CLI retains analysis paths, destination handling and coverage refusal.

### FormatterInterface

```php
namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

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
        public string $basePath = '',      // retained for SARIF %SRCROOT% URI builder
        public bool $scopedReporting = false, // scoped reporting (e.g., --report=git:staged)
        public ?string $namespace = null,  // --namespace filter (prefix or glob pattern)
        public ?string $class = null,      // --class filter (exact FQCN match)
        public int $terminalWidth = 0,     // adaptive rendering width (0 = default 80)
        public ?int $detailLimit = null,   // --detail mode: null=off, 0=all, N=limit
        public bool $isGroupByExplicit = false, // whether --group-by was set explicitly
        public int $topIssuesLimit = self::DEFAULT_TOP_ISSUES_LIMIT,
    ) {}

    public function getOption(string $key, string $default = ''): string;
    // Renders a project-relative path as its wire-surface string; '' for null
    // (ADR 0015 — Location::$file is already RelativePath by construction).
    public function relativizePath(?RelativePath $filePath): string;
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
namespace Qualimetrix\Reporting\Formatter;

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
        public array $findings,
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
        public array $topIssues = [],          // list<RankedIssue> — top findings by impact
    ) {}

    public function isEmpty(): bool;
    public function getTotalFindings(): int;
    public function getFindingsBySeverity(Severity $severity): array;
}
```

### SummaryEnricher (Health/)

Enriches a base `Report` with immutable Health summary values, worst offenders,
technical debt, and impact. Health score/decomposition semantics are owned by
[`Analysis\\Evidence\\ComputedMetrics`](../Analysis/Evidence/ComputedMetrics/README.md);
Reporting retains only report assembly.

```php
final readonly class SummaryEnricher
{
    public function enrich(Report $report): Report;
}
```

### HealthHintProjector (Health/)

Projects the immutable metadata returned by
`HealthMetricMetadataProviderInterface` into the existing HTML payload. Labels,
explanations, good values, directions, decompositions, and score-label semantics
remain inside the Health capability.

### SummaryFormatter

**Name:** `summary` (default) | **Default grouping:** `none`

One-screen health overview with worst offenders and contextual hints. Shows health bars for 6 dimensions (complexity, cohesion, coupling, typing, maintainability, overall), top-3 worst namespaces/classes, finding summary, and actionable hints.

Supports `--namespace` and `--class` for drill-down (filtering worst offenders). Handles edge cases: scoped reporting (findings filtered to changed files), missing metrics, single file (no namespace section), zero findings, narrow terminals (no bars).

ASCII fallback with `QMX_ASCII=1` env variable.

### TextFormatter

**Name:** `text` | **Default grouping:** `none`

Compact, parseable text output (one line per finding). GCC/Clang-compatible format.
Supports ANSI colors for severity and summary (auto-detected, disabled with `--no-ansi`).

**Output format:** `file:line: severity[code]: message (symbol)`

### TextVerboseFormatter

**Name:** `text-verbose` | **Default grouping:** `file`

Human-readable verbose output with:
- Findings grouped by file (default), rule, severity, or flat
- File headers with finding count
- ANSI colors for severity tags and summary
- Compact finding format (2 lines per finding)
- Metric values highlighted when present

## CLI Options

```bash
# Drill-down (mutually exclusive, works with summary/text/json)
bin/qmx check src/ --namespace=App\\Service   # filter by namespace pattern (prefix or glob)
bin/qmx check src/ --class=App\\Service\\UserService  # filter by exact FQCN

# Grouping (overrides formatter default)
bin/qmx check src/ --group-by=file      # group by file
bin/qmx check src/ --group-by=rule      # group by rule name
bin/qmx check src/ --group-by=severity  # group by severity
bin/qmx check src/ --group-by=none      # flat list

# Formatter-specific options
bin/qmx check src/ --format-opt key=value

# Disable colors
bin/qmx check src/ --no-ansi
```

## Output Examples

### SummaryFormatter (default)

```
Qualimetrix — 412 files analyzed, 3.2s

Health █████████████████████░░░░░░░░░ 68% Fair

  Complexity      ████████████████░░░░░░░░░░░░░░ 54% Fair
  Cohesion        ███████████████████░░░░░░░░░░░ 63% Fair
  Coupling        ███████████████████░░░░░░░░░░░ 62% Fair
  Typing          ██████████████████████████████ 99% Fair
  Maintainability ██████████████████████░░░░░░░░ 74% Fair

Worst namespaces
  46 App\Metrics\Halstead (3 classes, 29 findings) — high coupling, high complexity
  49 App\Metrics\Complexity (6 classes, 51 findings) — high coupling

1251 findings (384 errors, 867 warnings) | Tech debt: 63d 5h 35min

Hints: --format=text to see all findings | --namespace="App\Metrics\Halstead" to drill down | --format=html -o report.html for full report
```

### TextFormatter (`--format=text`)

```
src/Service/UserService.php:42: error[cyclomatic-complexity]: Cyclomatic complexity of 25 exceeds threshold (UserService::calculateDiscount)
src/Service/UserService.php:120: warning[cyclomatic-complexity]: Cyclomatic complexity of 12 exceeds threshold (UserService::processOrder)

1 error(s), 1 warning(s) in 1 file(s)
```

### TextVerboseFormatter (`--format=text-verbose`)

```
Qualimetrix Report
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

| Format       | Name           | Description                                                   | Integration                |
| ------------ | -------------- | ------------------------------------------------------------- | -------------------------- |
| Summary      | `summary`      | **Default.** Health overview + worst offenders                | CLI                        |
| Text         | `text`         | Compact human-readable text output                            | CLI                        |
| Text Verbose | `text-verbose` | Detailed text output with sorting by severity                 | CLI                        |
| JSON         | `json`         | Summary-oriented JSON (health + findings)                     | AI agents, CI/CD           |
| Checkstyle   | `checkstyle`   | Checkstyle XML for CI systems                                 | Jenkins, SonarQube         |
| SARIF        | `sarif`        | SARIF 2.1.0 for static analysis                               | GitHub, VS Code, JetBrains |
| GitLab       | `gitlab`       | Code Climate JSON for GitLab MR                               | GitLab CI                  |
| Metrics      | `metrics`      | Raw metric values for all symbols                             | Dashboards, cross-tool     |
| GitHub       | `github`       | GitHub Actions workflow-command annotations                   | GitHub Actions             |
| Health       | `health`       | Text table of health dimensions with scores and decomposition | CLI                        |
| Html         | `html`         | Interactive treemap report with D3.js                         | Browser, CI artifacts      |

## JsonFormatter

**Name:** `json`

Summary-oriented JSON for AI agents, CI/CD, and programmatic consumption. Includes health scores, worst offenders, and findings (top 50 by default). Example:

```json
{
  "meta": { "version": "1.0.0", "package": "qmx", "timestamp": "..." },
  "summary": { "filesAnalyzed": 342, "violationCount": 47, "errorCount": 12, "warningCount": 35, "techDebtMinutes": 270, "debtPer1kLoc": 5.4 },
  "health": { "complexity": { "score": 65, "label": "Fair", "threshold": { "warning": 50, "error": 25 }, "decomposition": [...] } },
  "worstNamespaces": [{ "symbolPath": "App\\Payment", "healthOverall": 31, "reason": "low cohesion, high complexity" }],
  "worstClasses": [{ "symbolPath": "App\\Payment\\PaymentService", "file": "src/...", "healthOverall": 28, "metrics": {...} }],
  "violations": [{ "file": "src/...", "line": 42, "symbol": "...", "namespace": "App\\Service", "rule": "complexity.cyclomatic", "code": "complexity.cyclomatic", "severity": "error", "message": "...", "metricValue": 15, "threshold": 10, "acceptedLevel": null }]
}
```

**Options:** `--format-opt=violations=all|0|N` (default: 50), `--format-opt=top=N` (default: 10 offenders). `--detail` shows findings (default limit: 200, `--detail=all` for unlimited). `--namespace`/`--class` filters findings and worst offenders. `coverage` always states whether the result is complete; policy and health results from an incomplete run are not authoritative.

**`acceptedLevel`:** `null` unless the finding is a measured baseline breach (see [Accepted level](#accepted-level-baseline-breach) below), in which case it is `{ "shape": "magnitude" | "occurrence", "describe": "25", "count": 1 }`. For a `magnitude` channel, the current value is the sibling `metricValue` field — not duplicated here.

**Identity fields:** `symbol` remains the logical/display projection. Stable
machine identity is `channel + subject + optional occurrence + optional edge`:
`subject` is the canonical typed declaration or aggregate subject,
`occurrence` distinguishes semantic evidence within a channel, and `edge`
contains a required logical dependency target plus an optional reference
`type`. A target-only edge is emitted as `{ "target": "..." }`; a typed edge
is `{ "type": "...", "target": "..." }`. JSON ordering and formatter
fingerprints use that tuple, not source line or display text. Target-only
fingerprints therefore differ by target and from a typed edge to the same
target. Established no-edge and fully typed fingerprints remain unchanged.

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

| Qualimetrix Severity | SARIF Level |
| -------------------- | ----------- |
| Error                | `error`     |
| Warning              | `warning`   |
| Info                 | `note`      |

### Related Locations

Findings with `relatedLocations` (e.g., code duplication findings pointing to other occurrences) are rendered as SARIF `relatedLocations` entries. This provides clickable cross-references in GitHub Code Scanning, VS Code, and JetBrains IDEs.

### Rule Descriptors

`SarifRuleCollector` carries no description or documentation-URL table of its
own: both are derived per finding code from
`Analysis\Finding\Contract\ChannelPresentationInterface`, which joins the
channel to its producing rule's own description
(`RuleInterface::getDescription()`) and declared documentation page
(`RuleDocsPageReader`). A code no channel carries — including a configured
computed metric whose own description is blank — falls back to a humanised
rendering of the code and the repository URL rather than throwing. See
`docs/internal/plans/sarif-channel-descriptions.md` for why this replaced the
previous hand-kept `match`/category-prefix tables (they had drifted from the
rules they duplicated).

### GitHub Actions Integration

```yaml
- name: Run Qualimetrix
  run: bin/qmx check src/ --format=sarif > results.sarif

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

| Qualimetrix Severity | GitLab Severity |
| -------------------- | --------------- |
| Error                | `critical`      |
| Warning              | `major`         |
| Info                 | `minor`         |

### GitLab CI Integration

```yaml
code_quality:
  stage: test
  script:
    - bin/qmx check src/ --format=gitlab > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Results will appear in the **Code Quality** tab with inline comments in the MR.

---

## MetricsJsonFormatter

**Name:** `metrics`

Exports raw metric values for all symbols (methods, classes, namespaces, files) as JSON. Unlike `json` which outputs findings, this formatter outputs the actual metric data collected during analysis — useful for cross-tool comparison, metrics analysis, and custom dashboards.

### Output Structure

```json
{
  "version": "1.0.0",
  "package": "qmx",
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
bin/qmx check src/ --format=metrics > metrics.json
```

---

## Adding a New Formatter

### Steps

1. Create a `*Formatter.php` class in `src/Reporting/Formatter/`
2. Implement `FormatterInterface` (methods: `format(Report, FormatterContext)`, `getName()`, `getDefaultGroupBy()`)
3. Use it: `bin/qmx check src/ --format=myformat`

**Automatic registration:** the class will be registered via `FormatterCompilerPass` — no need to modify `ContainerFactory`.

### Available Data in Report

```php
$finding->severity      // Severity enum (Error, Warning, Info)
$finding->message       // Finding description (technical, for text/checkstyle/sarif)
$finding->recommendation  // ?string — human-readable message (for summary/detail/json)
$finding->threshold     // int|float|null — threshold that was exceeded
$finding->ruleName      // Rule name
$finding->code // Stable finding code for identification
$finding->symbolPath    // SymbolPath object
$finding->location      // Location object (file, line); check isNone() for architectural findings
$finding->metricValue   // int|float|null
$finding->acceptedLevel // ?AcceptedLevel — set only on a measured baseline breach (ADR 0017); null on every other finding, including one
                          // no baseline ever judged. See "Accepted level" below.

$report->findings       // list<Finding>
$report->filesAnalyzed    // int
$report->errorCount       // int
$report->warningCount     // int
$report->duration         // float (seconds)
$report->healthScores     // array<string, HealthScore> — per-dimension health scores
$report->worstNamespaces  // list<WorstOffender> — worst namespaces by health
$report->worstClasses     // list<WorstOffender> — worst classes by health
$report->techDebtMinutes  // int — total remediation time
$report->debtPer1kLoc     // ?float — debt density (minutes per 1K LOC)
$report->topIssues        // list<RankedIssue> — top findings by impact score
$report->coverage         // ?ReportCoverage — discovered/analyzed/generated/failed verdict
```

`ReportCoverage` is the Reporting-layer projection of the pipeline's canonical
coverage state. Every formatter must preserve a useful payload for zero files and
must make incomplete analysis machine-detectable; see
[ADR 0018](../../docs/adr/0018-analysis-coverage-verdict-and-output-projection.md).

## Accepted level (baseline breach)

`Finding::$acceptedLevel` is set only when a finding is a **measured breach**
of a baseline entry (ADR 0017): the group
was checked against an applicable entry and exceeded it, and severity was
already promoted to `Error` via `Finding::reportedAsBreach()`. It is `null`
on every other finding, including one no baseline ever judged.

`Formatter\Support\AcceptedLevelNarrator::describe(Finding $v): ?string`
renders the human fragment — `"accepted at 25, now 31"` for a `magnitude`
channel, `"accepted at 3 occurrences"` for an `occurrence` channel (no
fabricated "now": the mechanism compares a group size no single `Finding`
carries). Returns `null` when `$acceptedLevel` is absent.

Per-format decision — whether the accepted level is carried, and how:

| Format                  | Carries it? | Mechanism                                                                                                                                                          |
| ----------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `text` / `text-verbose` | Yes         | Appended to the message via `AcceptedLevelNarrator`                                                                                                                |
| `summary --detail`      | Yes         | Shares `DetailedFindingRenderer` with `text --detail`                                                                                                              |
| `checkstyle`            | Yes         | Appended to the `message` attribute (schema has no dedicated field)                                                                                                |
| `gitlab`                | Yes         | Appended to `description` (fingerprint still hashes the unmodified message)                                                                                        |
| `github`                | Yes         | Appended to the annotation message, before escaping                                                                                                                |
| `sarif`                 | Yes         | Appended to `message.text`; `result.level` and the rule's run-level default already derive from `Finding::severity`, so promotion propagates without extra mapping |
| `json`                  | Yes         | Structured `acceptedLevel: {shape, describe, count} \| null` field per finding; `now` is the existing sibling `metricValue` field, not duplicated                  |
| `metrics`               | No          | Carries no findings at all — only raw collected metric values                                                                                                      |
| `health`                | No          | Renders health-dimension scores, never individual findings                                                                                                         |
| `html`                  | No          | Would need `Template/` (JS) changes to render; left for a dedicated follow-up rather than shipping an inert data field                                             |

## Formatter Comparison

| Characteristic          | Summary | Text   | Text Verbose | JSON    | Checkstyle        | SARIF        | GitLab | Metrics | Health | Html            |
| ----------------------- | ------- | ------ | ------------ | ------- | ----------------- | ------------ | ------ | ------- | ------ | --------------- |
| **ANSI Colors**         | Yes     | Yes    | Yes          | No      | No                | No           | No     | No      | Yes    | No              |
| **Health overview**     | Yes     | No     | No           | No      | No                | No           | No     | No      | Yes    | Yes             |
| **Grouping**            | No      | No     | Yes (file)   | No      | No                | No           | No     | No      | No     | No              |
| **Readability**         | High    | High   | High         | No      | No                | No           | No     | No      | High   | Visual          |
| **CI/CD integration**   | No      | No     | No           | Generic | Jenkins/SonarQube | GitHub/Azure | GitLab | Custom  | No     | CI artifacts    |
| **IDE support**         | No      | No     | No           | No      | Limited           | VS Code/JB   | No     | No      | No     | No              |
| **PHPMD compatibility** | No      | Full   | No           | No      | Full              | No           | No     | No      | No     | No              |
| **Fingerprinting**      | No      | No     | No           | No      | No                | No           | Yes    | No      | No     | No              |
| **Output**              | STDOUT  | STDOUT | STDOUT       | STDOUT  | STDOUT            | STDOUT       | STDOUT | STDOUT  | STDOUT | File (--output) |

### Choosing the Right Format

- **CLI usage (overview)** -> `summary` (default)
- **CLI usage (compact findings)** -> `text`
- **CLI usage (detailed)** -> `text-verbose`
- **Generic CI/CD** (GitLab CI, CircleCI, Travis) -> `json`
- **Jenkins / SonarQube** -> `checkstyle`
- **GitHub** -> `sarif`
- **GitLab** -> `gitlab`
- **VS Code** -> `sarif`
- **JetBrains IDE** -> `sarif`
- **Custom dashboards / metrics analysis** -> `metrics`
- **Health scores (terminal)** -> `health`
- **Visual exploration / stakeholder reports** -> `html`

## HealthTextFormatter

**Name:** `health` | **Default grouping:** `none`

Text-based health report for terminal output. Renders a table of health dimensions with scores, status labels, and threshold info, followed by decomposition details showing each contributing metric. Supports ANSI colors and adapts to narrow terminals.

Supports `--namespace` and `--class` for drill-down (filtering to specific scope).

---

## HtmlFormatter

**Name:** `html`

Self-contained interactive HTML report with D3.js treemap visualization. All CSS, JS, and data are embedded in a single file — works offline, easy to share.

### Features

- **Treemap** — namespace hierarchy colored by health score (blue = healthy, red = unhealthy)
- **Drill-down** — click namespaces to explore deeper
- **Detail panel** — health bars, worst offenders, metrics table, findings
- **Metric selector** — switch coloring between health scores (complexity, cohesion, coupling, etc.)
- **Search** — find namespaces and classes by name
- **URL hash navigation** — deep linking via `#ns:App/Payment`, `#cl:App/Service`
- **Dark mode** — adapts to system preference
- **Partial analysis warning** — banner when using scoped reporting (e.g., `--report=git:staged`)

### Usage

```bash
# Generate HTML report (recommended: save to file)
bin/qmx check src/ --format=html --output=report.html

# Also works with stdout (but warns on TTY)
bin/qmx check src/ --format=html > report.html
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

## Locality

Reporting owns output projection and formatter composition, not feature state.
It consumes named capability contracts and resolves its immutable output and
finding-projection values from `ConfigurationDocument`; delivery adapters remain
in Infrastructure. Keep formatter tests, templates, and documentation with
their Reporting subject, and keep runtime values with their named owners.
