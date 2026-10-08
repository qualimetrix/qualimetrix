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
├── ReportProjectScope.php                  # Covered / narrowed / unknown / unmeasured scope, unjudged values and typed source reasons
├── Configuration/                          # OutputFormatSection, OutputFormatVocabulary and OutputFormatResolver
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
│   ├── FindingProjector.php              # Authoritative suppression/filtering order
│   ├── BaselineFindingProjection.php     # One held-document ceiling judgement and late baseline audit
│   ├── ConfiguredExclusionProjection.php # Ordered path and namespace exclusion operations
│   ├── GitScopeFindingFilter.php          # Private Git publication predicate; the projector retains query and order
│   ├── SuppressionMechanism.php           # Closed 7-value vocabulary: 5 FindingFilterStage cases + the 2 per-rule ledger halves
│   ├── SuppressedFinding.php              # One finding x mechanism x suppressor pairing (multiset unit, not a finding-level fact)
│   ├── InertSuppressor.php                # A configured suppressor (pattern/rule) that excluded nothing this run
│   ├── SuppressionComposition.php         # `$all` (multiset) + `$neverMatched`, published by `--format=suppressed`
│   ├── SuppressionCompositionBuilder.php  # Builds SuppressionComposition for the five global stages; delegates the ledger halves
│   └── RuleExclusionLedgerAttributor.php  # Publishes each ledger-excluded finding from the RuleExclusionAttribution the ledger recorded; finds inert patterns, including suppress_namespace_channels
├── DrillDown/
│   ├── DrillDownBinding.php             # How many analyzed namespaces/classes a `--namespace` / `--class` value selects; zero is refused instead of emptying the report
│   ├── FindingFilter.php                # What `--namespace` / `--class` selects from findings and offenders — the one copy of that rule
│   ├── OutOfScopeIdentity.php           # Exact occurrence-preserving outside-scope identity
│   └── OutOfScopeFindings.php           # Severity counts of the run's findings a selection left out, and the formats with no place to publish them
└── Formatter/
    ├── FormatterInterface.php              # Formatter contract
    ├── FormattedReport.php                 # Published body and repaired-string count
    ├── PublicationKind.php                 # Prose or native structured document kind
    ├── FindingRecord.php                   # Nineteen fields shared by JSON and HTML
    ├── FindingPlace.php                    # Fileless namespace or project presentation
    ├── Ordering/FindingSorter.php          # Severity/impact selection before grouping
    ├── FormatOptionKeysInterface.php       # Opt-in: the --format-opt keys a formatter reads
    ├── FormatOptionValue.php               # The value grammar of every --format-opt key, and which keys set one value; the CLI refuses by it, formatters read by it
    ├── PublishedFinding.php                # Which composition of a finding's texts each surface publishes, and under which key
    ├── PublishedUtf8.php                   # Repairs invalid UTF-8 from analysed identifiers and paths in structured formats and counts the repair
    ├── FormatterRegistryInterface.php      # Registry contract
    ├── FormatterRegistry.php               # Registry implementation
    ├── TextFormatter.php                   # Compact text output (with colors)
    ├── CheckstyleFormatter.php             # Checkstyle XML
    ├── GithubActionsFormatter.php          # GitHub Actions annotation output
    ├── MetricsJsonFormatter.php            # Raw metrics JSON export
    ├── AcceptedLevelNarrator.php            # "accepted at 25, now 31" fragment for a breach or not-compared group
    ├── CoverageNarrator.php                 # Complete/empty/incomplete human coverage summary
    ├── Prose/                              # UTF-8 repair and publication-time glyph selection
    │   ├── ProseText.php                   # Publishes one prose body and its repaired-string count
    │   ├── GlyphMode.php                   # Unicode or closed-table ASCII publication
    │   └── AsciiGlyphs.php                 # Product glyph replacements; other Unicode stays intact
    ├── Ansi/                                # ANSI escape sequences
    │   └── AnsiColor.php                   # Lightweight ANSI color wrapper
    ├── Ordering/                            # The order and grouping findings appear in
    │   └── FindingSorter.php               # Sorts and groups findings per GroupBy
    ├── Detail/                              # The `--detail` block
    │   ├── DetailedFindingRenderer.php     # Detailed-output compositor
    │   ├── FindingDetailRenderer.php       # Sorted/grouped finding details
    │   └── DebtBreakdownRenderer.php       # Per-rule technical-debt details
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
    │   ├── HealthTextFormatter.php         # Text-based health report with scores and decomposition
    │   └── HealthCoverageNarrator.php      # What share of its subject a health score was computed over: the decomposition line, the one-line form beside a score, the bare share for a table cell, and the coverage record both `--format=json` and the HTML payload publish
    ├── Html/
    │   ├── HtmlFormatter.php              # Interactive HTML report with D3 treemap
    │   ├── HtmlTreeBuilder.php            # Builds namespace tree from MetricRepository
    │   ├── HtmlTreeNode.php               # Internal VO for tree construction
    │   ├── HtmlDebtCalculator.php         # Completes own debt and bottom-up totals for HTML trees
    │   ├── HtmlProjectMetadata.php        # The report's `project` object: analysed project's name, version, docs addresses
    │   └── HtmlFindingPartitioner.php   # Puts every finding on exactly one tree node (root at the latest)
    ├── GitLabCodeQualityFormatter.php      # GitLab Code Climate JSON
    └── Suppressed/
        └── SuppressedFormatter.php          # Machine-readable suppression composition (`--format=suppressed`)
```

## Contracts

### Published finding records

`Formatter\FindingRecord` builds the same nineteen fields for JSON violations,
ranked topIssues and HTML: file, line, subject, symbol, channel, occurrence,
edge, namespace, rule, code, severity, message, recommendation, metricValue,
threshold, techDebtMinutes, acceptedLevel, baselineVerdict and baselineReason.
Display file/symbol differ from exact canonical identity. HTML uses published
repository metric bags and never recomputes subtree health.

### Finding projection

`FindingProjector` owns the framework-free projection order: annotation
suppression, configured path exclusion, configured namespace exclusion,
Baseline ceiling, optional annotation rejoin, and Git scope last. Git scope is
queried through `GitScopeQueryInterface`; its Infrastructure adapter never
leaks into Reporting. Infrastructure composes the declared `ChannelFileScope`
once and injects it into `FindingProjector`; configured exclusions and Git
projection use that same value. Reporting carries no capability-registration
factory. Git changes only the reported list and cannot alter the measured,
accepted, or stale Baseline facts. `BaselineDocumentReader` acquires and judges
the held document before analysis; `BaselineLoader` interprets those bytes.

Reporting-owned `OutputFormat` carries the resolved formatter name to the
Console presenter without adding output policy to the transitional runtime
configuration. `OutputFormatVocabulary` asks `FormatterRegistryInterface`
which names exist and owns the canonical `format` key. `OutputFormatSection` returns an atomic
`SectionDeclaration` through `DocumentSectionSchemaInterface`: the
`format` string scalar uses the vocabulary in every writing layer before
merge, so a typo remains refused even under a valid command-line override.
`OutputFormatResolver` uses that same vocabulary for the winner before a
single file is read, including documents composed without the real format
section. Refusals retain their source. Infrastructure registers the section
with autoconfiguration and the vocabulary and resolver as separate services.
See [ADR 0088](../../docs/adr/0088-atomic-section-declarations-and-format-vocabulary.md).

### Suppression composition

`FindingProjection\SuppressionCompositionBuilder` assembles
`SuppressionComposition` — the multiset `SuppressedFormatter` publishes — from
facts the pipeline already computed: `FindingProjectionResult` for the five
global `FindingFilterStage` cases, and `RuleExecutionResult`'s exclusion
ledger (via `RuleExclusionLedgerAttributor`) for the two per-rule halves. It
reads Inline's first actually applied `DirectiveSite` from
`FindingProjectionResult`'s `AnnotationSuppressionResult` for annotations.
It does not repeat declaration placement or matching;
`DirectiveSuppressorResolver` has been removed. Configured path and namespace
patterns retain their own attribution. The public annotation suppressor is
still `file:line`: two physical sites on one line have the same label. See
`docs/adr/0037-suppressed-format-and-produced-findings.md` for why this is a
separate format rather than a `json` section.

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
     * Formats the report body and its structured repair count.
     */
    public function format(Report $report, FormatterContext $context): FormattedReport;

    public function publicationKind(): PublicationKind;

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
        public ?NamespacePattern $namespace = null, // bound exact/subtree/regex selector
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

    /**
     * Every `--format-opt` key any registered formatter reads, sorted and deduplicated.
     *
     * @return list<string>
     */
    public function declaredFormatOptionKeys(): array;
}
```

### FormatterRegistry

Registry implementation — stores formatters by name, throws `InvalidArgumentException` when a non-existent formatter is requested.

### FormatOptionKeysInterface

Opt-in contract: a formatter that reads `--format-opt` keys declares them, and
`FormatterRegistry::declaredFormatOptionKeys()` unites the declarations across
every registered formatter (hidden ones included). `FormatterContextFactory`
refuses a key outside that union with exit code 3 instead of dropping it into an
options array nobody reads. A key belonging to *another* formatter is accepted:
one option set is routinely run through several formats.

The declaration sits on the formatter even when the key is read by one of its
renderers (`rank-by`, `top`, `project-name`), so
`governance/FormatOptionKeys/FormatOptionKeyDeclarationTest.php` enumerates
the reading sites from the source and holds declaration and reader in agreement
in both directions.

Today's union: `contributors` (health), `limit`, `rank-by`, `top`, `violations`
(json), `rank-by`, `top` (summary), `project-name` (html).

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
        public ?NamespaceTree $namespaceTree = null,
        public int $infoCount = 0,
        public ?ReportCoverage $coverage = null,
        public ?SuppressionComposition $suppressionComposition = null,
        public ?OutOfScopeFindings $outOfScope = null, // what a --namespace/--class selection left out; null without one
        public ?ReportProjectScope $projectScope = null, // how the run's paths stood against composer.json autoload; set on every check run
        public array $configurationDiagnostics = [], // list<{message, source}> — warnings about the accepted configuration, already published
    ) {}

    public function isEmpty(): bool;
    public function getTotalFindings(): int;
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

Under `--namespace`/`--class` the closing summary line counts the selection
("… in this scope"), names the findings outside it that decide the exit code,
and takes its colour from the whole run; `summary` does the same in its
finding-count line. `--detail=N` selects the worst N by severity, then impact
ranking, before the requested presentation grouping. Unranked findings fall
back to place. `--detail=all` retains every finding.

Detailed text uses `--format=text --detail=all`. `text-verbose` is removed
and refused; it is no longer an alias. Diagnostic, recommendation and accepted
baseline level retain separate meanings in the detailed rendering.

## CLI Options

```bash
# Drill-down (mutually exclusive, works with summary/text/json)
bin/qmx check src/ --namespace='subtree:App\Service' # namespace plus descendants
bin/qmx check src/ --namespace='regex:App\\(?:Service|Controller)(?:\\[^\\]+)*'
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

Hints: --format=text to see all findings | --namespace='subtree:App\Metrics\Halstead' to drill down | --format=html -o report.html for full report
Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt
```

### TextFormatter (`--format=text`)

This output comes from `finding-gate/cases/drill-down`, with
`--only-rule=code-smell.eval --workers=0 --no-cache --no-progress --no-ansi`:

```text
src/Cart.php:14: error[code-smell.eval]: eval() usage detected - security risk (Cart::run)
src/Outside.php:11: error[code-smell.eval]: eval() usage detected - security risk (Outside::run)

Qualimetrix dev-main: 2 error(s), 0 warning(s) in 6 file(s)
Analysis complete: 6 analyzed, 0 generated file(s) excluded.
Technical debt: 30min
Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt
```

## Implemented Formats

| Format     | Name         | Description                                                   | Integration                |
| ---------- | ------------ | ------------------------------------------------------------- | -------------------------- |
| Summary    | `summary`    | **Default.** Health overview + worst offenders                | CLI                        |
| Text       | `text`       | Compact human-readable text output                            | CLI                        |
| JSON       | `json`       | Summary-oriented JSON (health + findings)                     | AI agents, CI/CD           |
| Checkstyle | `checkstyle` | Checkstyle XML for CI systems                                 | Jenkins, SonarQube         |
| SARIF      | `sarif`      | SARIF 2.1.0 for static analysis                               | GitHub, VS Code, JetBrains |
| GitLab     | `gitlab`     | Code Climate JSON for GitLab MR                               | GitLab CI                  |
| Metrics    | `metrics`    | Raw metric values for all symbols                             | Dashboards, cross-tool     |
| GitHub     | `github`     | GitHub Actions workflow-command annotations                   | GitHub Actions             |
| Health     | `health`     | Text table of health dimensions with scores and decomposition | CLI                        |
| Html       | `html`       | Interactive treemap report with D3.js                         | Browser, CI artifacts      |
| Suppressed | `suppressed` | Machine-readable composition of what was suppressed and why   | Auditing, CI               |

## JsonFormatter

**Name:** `json`

Summary-oriented JSON for AI agents, CI/CD, and programmatic consumption. Includes health scores, worst offenders, and every finding unless `violations=N` caps the list. Example:

```json
{
  "meta": { "version": "<qmx version>", "package": "qmx", "timestamp": "...", "docs": "https://qualimetrix.dev", "llmsTxt": "https://qualimetrix.dev/llms.txt" },
  "summary": { "filesAnalyzed": 342, "violationCount": 47, "errorCount": 12, "warningCount": 35, "techDebtMinutes": 270, "debtPer1kLoc": 5.4 },
  "outOfScope": null,
  "health": { "complexity": { "score": 65, "label": "Fair", "threshold": { "warning": 50, "error": 25 }, "coverage": { "state": "measured", "measured": 2263, "eligible": 2263, "ratio": 1.0, "unit": "callables", "basis": "complexity.ccn.count", "reason": null }, "decomposition": [...] } },
  "worstNamespaces": [{ "symbolPath": "App\\Payment", "healthOverall": 31, "reason": "low cohesion, high complexity" }],
  "worstClasses": [{ "symbolPath": "App\\Payment\\PaymentService", "file": "src/...", "healthOverall": 28, "metrics": {...} }],
  "violations": [{ "file": "src/...", "line": 42, "symbol": "...", "namespace": "App\\Service", "rule": "complexity.ccn", "code": "complexity.ccn", "severity": "error", "message": "...", "metricValue": 15, "threshold": 10, "acceptedLevel": null }]
}
```

**`meta`:** `docs` and `llmsTxt` come from `Core\ProductIdentity`; no formatter
spells a documentation address itself. `suppressed` publishes the same block,
and three commands outside `check` (`directives`,
`baseline:rename-channels`, `debug:layer-assignment`) open their JSON with it.

**Options:** `--format-opt=violations=all|0|N` (default: all), `--format-opt=top=N` (default: 10 offenders). An unparsable value is refused with exit code 3 before the analysis runs; the grammar of every key is `Formatter\FormatOptionValue`. `limit` sets the same value as `violations` (with `0` meaning no cap), and `FormatOptionValue::spellingsOf()` says so: the command line refuses both together, or `limit` beside `--all`, and `JsonFormatter` treats receiving both as a wiring defect. A capped list selects severity, then impact ranking, before presentation grouping; unranked findings fall back to place. `--detail` shows findings (default limit: 200, `--detail=all` for unlimited). `--namespace`/`--class` filters findings and worst offenders; the `summary` section keeps its keys under a selection — a selection being a report whose `outOfScope` is set, the one fact the `outOfScope` key publishes too — and counts only the selection, with `debtPer1kLoc: null` (the selection's debt over the whole project's LOC would mix two scopes). `coverage` always states whether the result is complete; policy and health results from an incomplete run are not authoritative.

**`violationGroups`:** dictionary keys use total SourceBytes percent encoding,
including a literal `%` as `%25`, keeping byte-distinct groups separate.
Decode a key with `rawurldecode`; `file` remains display text.

**`message` / `recommendation`:** the finding's message and its optional recommendation, under the same two keys in `violations` and `topIssues` (see `Formatter\PublishedFinding`).

**`outOfScope`:** always present. `null` without `--namespace`/`--class`; under a selection, `{violationCount, errorCount, warningCount, infoCount, identities}` of the run's findings the selection left out, zeroes when it left none. The exit code is resolved over `summary` and `outOfScope` together. `metrics` publishes the same count names and identities; `sarif`, `github` and `html` add one diagnostic entry under `drill-down.out-of-scope` only when something lies outside (see `DrillDown\OutOfScopeFindings`). `gitlab` and `checkstyle` have no entry that is not a finding to their consumer, so `OutOfScopeFindings::FORMATS_WITHOUT_A_PLACE` names them and the command line refuses a selection under them. `suppressed` describes the whole run and refuses either selector before analysis.

Namespace drill-down selects a file finding when any namespace declared in its
physical file matches the selector. Files without declarations use the global
namespace; a missing repository has the same reporting fallback. A declaration
finding keeps its declared namespace even in a multi-namespace file. Class
selection never selects a file aggregate. Binding counts retain their existing
namespace and ranked-offender universe.

**`projectScope`:** always has
`{state, uncoveredAutoloadTargets, unjudgedChannels, unjudgedValues, reasons}`.
`state` remains `covered`, `narrowed`, `unknown` or `unmeasured`; the pipeline's
final measured judgement, rather than this enum alone, answers declaration
absence and selector/path completeness separately. Missing observed PHP, authored
PHP removal, generated removal and uncertain denominator are named causes.
All nine project-scoped channels use these measured questions; see
[ADR 0093](../../docs/adr/0093-measured-run-scope-and-project-tree-queries.md).

Each published skipped value has `{channel, option, pattern}`.
`unjudgedChannels` lists channels with no judged value; a partially judged channel
can be absent while its skipped values remain named. Reasons retain
flat named cause fields even on a `covered` run. Namespace location still uses
accepted PSR-4 facts independently of state. Human wording names unjudged
channels even when state is `covered`: path coverage alone does not judge every
channel. `metrics` and `suppressed` share
this object. SARIF (`QMX-RUN-PROJECT-SCOPE`), GitHub (`run.project-scope`), HTML
and human formats render the scope explanation; `gitlab` and `checkstyle` have
no diagnostic entry because their consumers treat every entry as a finding.
Auxiliary install issues explain ancestry limits without changing main-project
coverage. An `omitted-composer-root` reason retains `cause` and `visitedLevels`.
Its `candidate`, `startDirectory` and `lastDirectory` are published only when
inside the project, relative to its root. An absent main manifest contributes no
omitted-root reason. `coverage.excluded` counts named authored entries separately from
`discovered`: analyzed PHP plus generated-excluded PHP plus selected failed
terminal entries. Named exclusions are outside that sum; failures may name
directories, links or special entries rather than PHP files. A complete
intentionally empty run and an incomplete run remain distinct; failure has
priority and its policy/health result is not authoritative.

**`configurationDiagnostics`:** always present, `[]` when the configuration drew no warning. Each entry is `{message, source}`: the warning as `check` also prints it on stderr, and `source` every layer it is about, lowest precedence first, each as the refusal envelope's `source` entries are — `{kind, name, imported_by}`. The entries arrive already published (`Infrastructure\Console\ConfigurationInputAdapter::publishedDiagnostics()`), so `Reporting` does not read the configuration document.

**`invalidUtf8Replaced`:** appears only when source strings required repair
and counts repaired strings. Invalid bytes display as `%XX`. Structured repairs
also escape literal percent signs in a malformed string; valid strings stay
unchanged. Prose preserves literal percentages and counts the whole repaired
body once. Every format reports a positive count on stderr. JSON, metrics,
suppressed, HTML and debug JSON retain their native marker; SARIF uses a tool
notification. GitLab and Checkstyle emit no synthetic repair finding.
SARIF `uri` encodes the original path bytes, so `%FF` remains an invalid byte
address and literal percent becomes `%25`. Display `file` is not exact identity;
use canonical `subject`, whose components also escape reserved separators.

**`acceptedLevel`:** `null` unless a baseline entry accompanies a measured breach or a present incomparable group (see [Accepted level and baseline verdict](#accepted-level-and-baseline-verdict) below), in which case it is `{ "shape": "magnitude" | "occurrence", "describe": "25", "count": 1 }`. The sibling `baselineVerdict` distinguishes `breached` from `not-compared`. For a `magnitude` channel, the current value is the sibling `metricValue` field — not duplicated here.

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
<checkstyle version="3.0">
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
rendering of the code and the repository URL rather than throwing. This
replaces hand-kept `match` and category-prefix tables, which had drifted from
the rules they duplicated. A presented channel's `helpUri` is
`ProductIdentity::docsPageUrl()` of its declared page;
`SarifRuleCollector::FALLBACK_HELP_URI` is the repository URL used for the
fallback, and is deliberately not the tool's `informationUri`.

### Tool Descriptor

`tool.driver.informationUri` is the documentation site
(`ProductIdentity::docsUrl()`), and `tool.driver.properties.llmsTxt` is the
agent-facing index. The index address sits in the `properties` bag because the
SARIF schema closes `toolComponent` to keys it does not define;
`SarifSchemaValidationTest` holds both halves of that.

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
  "toolVersion": "0.26.0",
  "package": "qmx",
  "timestamp": "2025-01-15T10:30:00+00:00",
  "docs": "https://qualimetrix.dev",
  "llmsTxt": "https://qualimetrix.dev/llms.txt",
  "symbols": [
    {
      "type": "method",
      "name": "App\\Service\\UserService::calculateDiscount",
      "file": "src/Service/UserService.php",
      "line": 42,
      "metrics": {
        "complexity.ccn": 25,
        "complexity.cognitive": 18,
        "complexity.npath": 128,
        "size.loc": 45
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

The root plays the role `json`'s `meta` plays, but `version` here is the export
format's own version and `toolVersion` the tool's. So only `docs` and `llmsTxt`
are added from `ProductIdentity`; merging its identity block wholesale would
overwrite `version` with the tool version.

### Usage

```bash
bin/qmx check src/ --format=metrics > metrics.json
```

---

## SuppressedFormatter

**Name:** `suppressed`

Machine-readable composition of what a run held back from its report and why
— a format of its own rather than a section of `json`, so an ordinary `check`
payload never changes shape for a feature it did not ask for (see
`docs/adr/0037-suppressed-format-and-produced-findings.md`). Reads
`Report::$suppressionComposition` (built by
`FindingProjection\SuppressionCompositionBuilder`), never the finding list
every other formatter reads.

The composition is a multiset over mechanism x finding across eight
mechanisms — the five global stages, two per-rule exclusion-ledger halves
and final produced-finding selection — not a set of findings: a finding removed
by more than one mechanism appears once per mechanism, so `byMechanism`
counts do not sum to a distinct-finding total. A separate `neverMatched` list
publishes configured suppressors (a path/namespace pattern, a per-rule
exclusion) that matched nothing this run.

Path and namespace suppressors reach this projection as bound `PathPattern` and
`NamespacePattern` values. Their output is the stable authored `kind:value`
definition, never the implementation's rendered PCRE string.

Each `suppressed` entry carries the identity the `json` report publishes
(`channel` — spelled here as the finding code —, `subject`, `occurrence`,
`edge`), so a suppressed record can be joined to a published one by machine,
and both texts under the keys `json` uses (`message`, `recommendation`). It
deliberately omits the measurement fields (`metricValue`, `threshold`,
`techDebtMinutes`, `acceptedLevel`): the format audits what held a finding back,
and the identity is what reaches the finding's own record. The document
carries `coverage` like every other format, so a suppression audit of an
incomplete run says it is incomplete. A report without a composition is a
wiring defect and fails, rather than publishing "nothing was suppressed".

### Capture

Selecting this format arms the same per-rule ledger capture `--show-suppressed`
arms on the text surface (`RuntimeConfigurator`): the two routes never
disagree, and `format: suppressed` in `qmx.yaml` triggers capture exactly as
`--show-suppressed` does.

### Usage

```bash
bin/qmx check src/ --format=suppressed > suppressed.json
```

---

## Adding a New Formatter

### Steps

1. Create a `*Formatter.php` class in `src/Reporting/Formatter/`
2. Implement `FormatterInterface` (methods: `format(Report, FormatterContext)`, `getName()`, `getDefaultGroupBy()`)
3. Reading a `--format-opt` key? Also implement `FormatOptionKeysInterface` and
   declare it — an undeclared key is refused before your formatter ever runs
4. Use it: `bin/qmx check src/ --format=myformat`

**Automatic registration:** the class will be registered via `FormatterCompilerPass` — no need to modify `ContainerFactory`.

### Available Data in Report

```php
$finding->severity      // Severity enum (Error, Warning, Info)
$finding->message       // Finding description; publish it through Formatter\PublishedFinding, not by hand
$finding->recommendation  // ?string — human-readable advice; see PublishedFinding for which surface shows which
$finding->threshold     // int|float|null — threshold that was exceeded
$finding->ruleName      // Rule name
$finding->code // Stable finding code for identification
$finding->symbolPath    // SymbolPath object
$finding->location      // Location object (file, line); check isNone() for architectural findings
$finding->metricValue   // int|float|null
$finding->acceptedLevel // ?AcceptedLevel — stored cap for breached or not-compared groups
$finding->baselineVerdict // ?string — breached / not-compared
$finding->baselineReason  // ?string — scalar comparison reason

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
$report->projectScope     // ?ReportProjectScope — covered/narrowed/unknown/unmeasured with source reasons
$report->configurationDiagnostics // list<{message, source}> — warnings about the accepted configuration
```

ADR 0062 publishes a health score's coverage alongside the score, and every
surface that shows a score shows it: `json` and the HTML payload
(`summary.healthCoverage`) carry the full record, `--format=health` a `Coverage`
column on every row including `overall` and at every terminal width, and the
default `summary` one dimmed line per dimension. The HTML viewer's own
rendering of that payload lives in `html-report/`.

`ReportCoverage` is the Reporting-layer projection of the pipeline's canonical
coverage state. Every formatter must preserve a useful payload for zero files and
must make incomplete analysis machine-detectable; see
[ADR 0018](../../docs/adr/0018-analysis-coverage-verdict-and-output-projection.md).

CoverageNarrator is the Reporting contract for the human coverage sentence.
BaselineRun, GraphExportCommand and DirectiveAuditPresenter construct
ReportCoverage and reuse that sentence for complete intentionally empty results.
The manifest names these exact adapter consumers.

## Accepted level and baseline verdict

`Finding::$acceptedLevel` can accompany a measured breach or a present
incomparable group. `baselineVerdict` and nullable scalar `baselineReason`
provide the judgement: `breached` promotes to Error, `not-compared` keeps normal
severity, null establishes neither. AcceptedLevel alone is never a breach flag.

`AcceptedLevelNarrator` renders accepted/current breach values or the explicit
not-compared reason. Text/detail, Summary/detail, Checkstyle, GitLab, GitHub and
SARIF carry that narration. JSON and HTML carry structured acceptedLevel,
baselineVerdict and baselineReason; the HTML viewer renders both states.
Metrics, Health and Suppressed do not publish this baseline audit.

`EntryBinding\UnusedEntryAudit` emits `baseline.unused-entry` project-level
Warnings for stale and inert entries after the full ceiling and before Git
projection. The rule's remediation estimate is 5 minutes. Audit findings never
enter the measured set, capture or accept-new; authored path/namespace
suppression and Git projection cannot hide them. When unselected, stderr reports
counts only. Uncompared entries likewise produce count diagnostics, not path dumps.
Nine finding formats publish the audit; Metrics, Health and Suppressed retain
their own subjects. All twelve preserve the ordinary failure policy: an isolated
audit warning exits 0 by default, with `--fail-on=error` or `none`, and 1 with
`--fail-on=warning`. Incomplete analysis has priority and exits 4.

## Formatter Comparison

| Characteristic          | Summary | Text   | Text Verbose | JSON    | Checkstyle        | SARIF        | GitLab | GitHub         | Metrics | Health | Html            | Suppressed |
| ----------------------- | ------- | ------ | ------------ | ------- | ----------------- | ------------ | ------ | -------------- | ------- | ------ | --------------- | ---------- |
| **ANSI Colors**         | Yes     | Yes    | Yes          | No      | No                | No           | No     | No             | No      | Yes    | No              | No         |
| **Health overview**     | Yes     | No     | No           | No      | No                | No           | No     | No             | No      | Yes    | Yes             | No         |
| **Grouping**            | No      | No     | Yes (file)   | No      | No                | No           | No     | No             | No      | No     | No              | No         |
| **Readability**         | High    | High   | High         | No      | No                | No           | No     | No             | No      | High   | Visual          | No         |
| **CI/CD integration**   | No      | No     | No           | Generic | Jenkins/SonarQube | GitHub/Azure | GitLab | GitHub Actions | Custom  | No     | CI artifacts    | Auditing   |
| **IDE support**         | No      | No     | No           | No      | Limited           | VS Code/JB   | No     | No             | No      | No     | No              | No         |
| **PHPMD compatibility** | No      | Full   | No           | No      | Full              | No           | No     | No             | No      | No     | No              | No         |
| **Fingerprinting**      | No      | No     | No           | No      | No                | Yes          | Yes    | No             | No      | No     | No              | No         |
| **Output**              | STDOUT  | STDOUT | STDOUT       | STDOUT  | STDOUT            | STDOUT       | STDOUT | STDOUT         | STDOUT  | STDOUT | File (--output) | STDOUT     |

### Choosing the Right Format

- **CLI usage (overview)** -> `summary` (default)
- **CLI usage (compact findings)** -> `text`
- **CLI usage (detailed)** -> `text --detail`
- **Generic CI/CD** (GitLab CI, CircleCI, Travis) -> `json`
- **Jenkins / SonarQube** -> `checkstyle`
- **GitHub** -> `sarif`
- **GitLab** -> `gitlab`
- **VS Code** -> `sarif`
- **JetBrains IDE** -> `sarif`
- **Custom dashboards / metrics analysis** -> `metrics`
- **Health scores (terminal)** -> `health`
- **Visual exploration / stakeholder reports** -> `html`
- **Auditing what a run suppressed** -> `suppressed`

## HealthTextFormatter

**Name:** `health` | **Default grouping:** `none`

Text-based health report for terminal output. Renders a table of health dimensions with scores, status labels, and threshold info, followed by decomposition details showing each contributing metric and the share of the subject the score was computed over. Supports ANSI colors and adapts to narrow terminals.

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
- `Html/HtmlFindingPartitioner` — puts every finding on exactly one node: a
  callable or class finding on its class, a global function or namespace
  finding on its namespace, a file finding on the one class its file declares,
  and anything without such a node (a project finding, a file with no class or
  several) on the nearest enclosing node, the project root at the latest. So
  `summary.totalViolations` and the root's `violationCountTotal` count the same
  findings, and the root's `debtMinutes` is the report's debt bottom-up. A
  finding the partitioner cannot place fails the run instead of disappearing.

The template placeholders (`__CSS__`, `__DATA__`, `__D3_JS__`, `__APP_JS__`)
are substituted in a single pass, so a symbol spelled like a placeholder stays
a value in the data.

The browser program that renders the report is **not** under `src/Reporting/`.
It lives at `html-report/` in the repository root — an npm project with its own
build and test toolchain, outside the PSR-4 autoload root by
[ADR 0064](../../docs/adr/0064-the-html-viewer-lives-outside-the-psr-4-root.md).
`HtmlFormatter` reads four files from there at run time: `report.html`,
`report.css`, `dist/report.min.js` and `dist/d3.min.js`. See
[html-report/README.md](../../html-report/README.md) for its structure.

**Project metadata and footer:** `HtmlProjectMetadata` assembles the `project` key
of the report data (`name`, `generatedAt`, `qmxVersion`, `scopedReporting`,
plus `docs` and `llmsTxt` from `Core\ProductIdentity`). `name` is
`--format-opt=project-name`, else the `name` of the analysed project's
`composer.json`, else its root directory's name — never the Composer runtime's
root package, which under a phar, a global install or a qmx checkout is qmx
itself. It reads metadata through the same invocation
`ComposerManifestReaderInterface` as configuration and scope, including absent
or invalid snapshots; it does not decode the file independently.
`HtmlTreeBuilder` receives an instance of `HtmlProjectMetadata`.
The browser program's
footer reads this object and renders `docs` and `llmsTxt` as links beside the
existing generated-date and version line, so the same values that reach every
other output channel also reach the HTML report — JavaScript cannot read a PHP
constant, so this is the only path.

### JS Build Pipeline

```bash
composer install:js   # npm ci, first time only
composer test:js       # vitest unit tests
composer build:js      # produces dist/report.min.js + dist/d3.min.js
cd html-report && npm run dev   # vite dev server with HMR (uses dev.html)
```

---

## Planned Formats

Possible extensions:

- **Markdown** — for documentation and PR comments
- **JUnit XML** — for integration with test frameworks

## Selection audit

The suppression composition has eight mechanisms, adding `selection` to the
seven existing mechanisms. Only actually produced findings removed by final
publication selection enter that multiset and its byMechanism counts.
`SuppressionComposition::notRun` separately records producers that never ran:
producer, reason (`disabled` or `filtered`), decisive statement and layer. It is
metadata, not fabricated findings, and contributes no suppression count.
Drill-down and formatter truncation remain presentation operations; neither is
selection. Capture and output use the same committed options/enablement snapshot.
DoD preserves produced-removal identity and empty/not-run separation.

## Locality

Reporting owns output projection and formatter composition, not feature state.
It consumes named capability contracts and resolves its immutable output and
finding-projection values from declared resolved document sections: the output
format and configured suppressions. `configurationDiagnostics` already arrives
from Console as a published value. Delivery adapters remain in Infrastructure.
Keep formatter tests, templates, and documentation with their Reporting subject,
and keep runtime values with their named owners.

Prose formatters produce Unicode. At publication, `ProseText` escapes invalid
UTF-8 bytes with Core's SourceBytes and counts the body as one repaired string.
ASCII mode replaces only the closed product glyph table. It also replaces the
same glyph sequence inside a source identifier or path: the completed body no
longer retains that provenance. Unicode mode preserves such valid characters.
Other Unicode letters, such as Café, remain intact in either mode. Structured
formatters keep their own encoders and canonical identities.
