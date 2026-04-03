# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.13.0] - 2026-04-03

### Changed
- `--show-suppressed` now lists each suppressed violation with file, line, message, and rule name (was count-only)
- `exclude_paths` and `exclude_namespaces` now support both prefix matching (`src/Entity`) and glob patterns (`src/Metrics/*Visitor.php`); simple directory/namespace names work without trailing `/*`
- `--exclude-health` with invalid dimension name now produces an error instead of silently ignoring

### Fixed
- "No PHP files found" message shown when all files had parse errors — now shows "All N file(s) were skipped due to parse errors"

## [0.12.0] - 2026-04-03

### Changed
- LCOM4 rule: `exclude_methods` option to exclude specific methods from the cohesion graph (reduces false positives from interface-mandated methods like `getName`, `getDescription`)
- Partial scope warning when analysis paths don't cover all composer.json autoload entries
- `coupling.instability`: `min_afferent` option replaces `skip_leaf` — configurable minimum afferent coupling (Ca) threshold for skipping symbols (default: 1, skip Ca=0)
- `code-smell.boolean-argument`: parameters with common boolean prefixes (`is*`, `has*`, `can*`, `should*`, `will*`, `did*`, `was*`) are now allowed by default (configurable via `allowed_prefixes: []`)
- `code-smell.error-suppression`: `allowed_functions` option to whitelist functions where `@` usage is acceptable (e.g., `fopen`, `unlink`)
- Per-rule `exclude_paths` option for targeted violation suppression by file path patterns
- `@qmx-ignore` tags now work in regular comments (`//`, `/* */`), not just PHPDoc docblocks
- JSON format (`--format=json`) now outputs all violations by default (was limited to 50); use `--format-opt=violations=50` to restore the old behavior
- Global `exclude_namespaces` config option for suppressing violations by namespace prefix (like `exclude_paths` but for namespaces)
- Computed metric formulas referencing non-existent metrics now produce a clear error instead of silently failing
- Warnings (partial scope, unknown rules, missing composer.json) now go to stderr to avoid corrupting machine-readable output
- Exit codes: config/input errors now return exit code 3 (was 1, overlapping with "warnings found"). Scheme: 0=clean, 1=warnings, 2=errors, 3=config error

### Fixed
- `graph:export` command crash due to `-d` shortcut conflict with global `--working-dir`

### Removed
- `--analyze` option — was misleading (analyzed all files regardless, only filtered violations like `--report`). Use `--report` instead
- `analyze` command alias — use `check` instead
- `baseline.json` — replaced with proper `qmx.yaml` configuration using new features

## [0.11.2] - 2026-04-02

### Changed
- Project `qmx.yaml` for self-analysis with tuned coupling thresholds and `exclude_namespaces` for Core value objects
- `qmx.yaml.example` — comprehensive annotated example with documentation links, default values, and all available options (replaces `qmx.yaml.dist`)
- `parallel` section in config file for setting worker count (was CLI-only via `--workers`)

### Fixed
- `coupling` section in config file was rejected as unknown key

## [0.11.1] - 2026-04-01

### Changed
- `--memory-limit` option and `memory_limit` config key to control PHP memory limit (e.g., `--memory-limit=1G`)
- Removed hidden 512M memory limit override — PHP's `memory_limit` from php.ini is now respected by default

## [0.11.0] - 2026-04-01

### Changed
- Cognitive Complexity violations include breakdown of top contributors: `Top: nested if +5 L12, foreach +4 L15, &&/|| +1 L22`
- NPath Complexity violations include multiplicative chain: `Chain: ×6 if/else L25, ×4 match L31, ×3 switch L20`

## [0.10.0] - 2026-03-29

### Breaking
- Rule IDs `code-smell.god-class` and `code-smell.data-class` renamed to `design.god-class` and `design.data-class`
- `--format=health` now produces a text table (was HTML). Use `--format=html` for the interactive HTML report

### Changed
- `@qmx-threshold` annotations for per-class/method threshold overrides in source code
- Framework CBO distinction: `cbo_app` and `ce_framework` metrics separate application from framework coupling
- Full dependency graph in `--analyze=git:*` modes — coupling metrics now correct in partial analysis
- `--group-by=class|namespace` for JSON output
- Worst contributors per health dimension in `--format=health`, configurable via `--format-opt=contributors=N`
- Violation density metric (`violationDensity`: violations per 100 LOC) in worst offenders
- NPath violations include severity categories (low/moderate/high/very high/extreme)
- VO constructor exemption for `long-parameter-list` — relaxed thresholds (`vo-warning`, `vo-error`)
- LCOM4: stateless methods grouped together, reducing false positives on utility classes
- Duplication violations include content preview hint
- Martin Diagram view in HTML report with parent-namespace instability/abstractness/distance
- NamespaceTree: canonical namespace hierarchy replaces flat aggregator
- Warn when `@qmx-threshold` targets rules that don't support overrides
- Decomposed 13 large classes into focused components (SRP)

### Fixed
- Health: complexity contributors always empty; recalibrated formulas for per-method aggregation
- Metrics: namespace `.max`/`.avg`/`.p95` now aggregated from raw method values, not pre-aggregated class values
- Reporting: aggregation suffixes stripped from metric keys in health text; uppercase metric keys fixed
- Git: absolute path mismatch in `GitScopeFilter` for `--analyze=git:*`
- Security: hardcoded credentials no longer flag dot-notation identifiers (e.g., `config.database.host`)
- Duplication: self-duplication for overlapping/adjacent ranges in same file eliminated
- Removed dead weighted average from aggregation, dead `GitFileDiscovery` class

## [0.9.2] - 2026-03-26

### Fixed
- CI: refactored `ConfigDataNormalizer` to eliminate complexity violations (NPath 442K → 4), regenerated baseline

## [0.9.1] - 2026-03-26

### Changed
- "Top issues by impact" redesigned: file path on the first line (clickable in terminal), rule name + message + symbol context on the second line. Shows `recommendation` when available. Handles architectural violations (`[project]`)
- HTML report: violations table now shows `File` column, uses `violationCode` (more specific than `ruleName`), and prefers `recommendation` over technical `message`

## [0.9.0] - 2026-03-26

### Changed
- Analysis presets: `--preset=strict|legacy|ci` for one-flag configuration. Multiple presets can be combined (`--preset=strict,ci`). Custom preset files supported via path (`--preset=./team.yaml`)
- `rules` key now uses deep merge across pipeline stages — partial rule overrides in `qmx.yaml` no longer replace entire preset rule configurations

## [0.8.0] - 2026-03-26

### Changed
- Effort-aware prioritization: "Top issues by impact" section in summary and JSON output. Violations ranked by `classRank × severity × remediation time` — answering "what should I fix first?" New `--top=N` option (default 10, `--top=0` to disable)

## [0.7.1] - 2026-03-25

### Changed
- CBO metric no longer counts PHP built-in classes (`Exception`, `DateTime`, `Iterator`, etc.) — only project and third-party dependencies contribute to coupling scores. Dependency graph exports (`graph:export`) are also affected

## [0.7.0] - 2026-03-25

### Changed
- `--fail-on` now defaults to `error` — warnings are shown in output but don't cause non-zero exit code. Use `--fail-on=warning` or `fail_on: warning` in config for the old behavior
- `threshold` shorthand for rule configuration — sets both warning and error to the same value, making all violations errors at that threshold
- Health score labels renamed to industry-standard terminology: `Excellent` / `Good` / `Fair` / `Poor` / `Critical` (was `Strong` / `Good` / `Acceptable` / `Weak` / `Critical`)
- Line numbers shown only for violations with precise locations (method/class level), not for file-level violations

### Fixed
- Technical debt breakdown now calculated from all violations, not just the truncated display list

### Breaking
- Default `--fail-on` changed from `warning` to `error`. CI pipelines relying on exit code 1 for warnings must add `--fail-on=warning` explicitly

## [0.6.0] - 2026-03-18

### Fixed
- Baseline now correctly matches file-level violations (duplication, code smell, security rules) — previously ~150 violations passed through a freshly generated baseline
- Duplicate code block locations are now sorted deterministically, making baseline entries stable across runs
- File paths are normalized to relative (vs CWD) to prevent mismatches with absolute or `./`-prefixed paths

### Breaking
- Baseline version bumped to 5 — existing v4 baselines must be regenerated with `--generate-baseline`

## [0.5.0] - 2026-03-18

### Changed
- `exclude_namespaces` is now a universal per-rule option available for any rule, not just coupling rules

### Breaking
- `exclude_namespaces` for `coupling.cbo` and `coupling.instability` moves from nested `namespace:` to top-level rule config
- `exclude_namespaces` now filters violations at all levels (class + namespace), not just namespace level

## [0.4.0] - 2026-03-18

### Changed
- **Health scores redesigned**: 5-tier labels (`Excellent` / `Good` / `Fair` / `Poor` / `Critical`), recalibrated formulas for complexity (avg + P95 + sqrt(max) penalties), coupling (efferent-based, P95 + sqrt-scaled max), cohesion (TCC neutral value for small classes), maintainability (MI anchor shifted to 30). `--exclude-health=DIMENSION` to exclude dimensions from scoring
- **Computed metrics**: 6 built-in `health.*` scores plus user-definable `computed.*` metrics via Symfony Expression Language formulas, per-level formulas, threshold-based violations
- **Summary-first CLI**: `--format=summary` is now the default output — health bars, worst offenders, violation summary, and contextual hints in one screen
- **Drill-down navigation**: `--namespace=App\Service` and `--class=App\Service\UserService` for progressive filtering with auto-enabled `--detail`. Namespace/class health scores shown in drill-down headers
- **Interactive HTML report**: `--format=health` — self-contained D3.js treemap, health coloring, search, metric selector, dark mode. Use `--output` / `-o` to write any format to a file
- **JSON output redesigned**: summary-oriented with `meta`, `summary`, `health` decomposition, `worstNamespaces`, `worstClasses`, `violations` (top 50 by default). `--format-opt=violations=all|0|N`, `--format-opt=top=N`
- **New rules**: `code-smell.long-parameter-list`, `code-smell.unreachable-code`, `code-smell.identical-subexpression`, `design.god-class` (Lanza & Marinescu), `design.data-class`, `code-smell.constructor-overinjection`, `code-smell.unused-private`, `design.type-coverage`, `duplication.code-duplication` (Rabin-Karp token hashing), `coupling.class-rank` (PageRank), `security.sql-injection`, `security.xss`, `security.command-injection`, `security.sensitive-parameter`, `security.hardcoded-credentials`
- **New output formats**: `--format=metrics` (raw metric values), `--format=github` (PR annotations)
- **Technical debt**: remediation time estimates per violation, aggregated debt in reports, `--detail` shows per-rule breakdown
- `--fail-on=error` option to allow warnings without failing the build
- `--include-generated` to override automatic `@generated` file skipping
- `--disable-rule=duplication` now skips the memory-intensive detection phase entirely (not just violations). Same for circular dependency detection
- Violation messages improved: actionable recommendations, parameter names in boolean-argument, coupling direction in CBO, CCN divergence hints, top-5 dependencies in coupling violations
- `bin/qmx graph:export --format=json` — dependency graph as aggregated JSON adjacency list
- `composer benchmark:check` regression suite — validates health scores against 15 open-source projects
- `llms.txt` and `llms-full.txt` — machine-readable documentation for AI coding agents

### Fixed
- Metric algorithm corrections: cognitive complexity nesting in closures, cyclomatic complexity for `match` arms, NPath formulas aligned with Nejmeh/PMD standards, Maintainability Index class-level aggregation, WOC formula, RFC for traits/enums, abstractness formula for interfaces
- Anonymous class isolation: methods inside anonymous classes no longer attributed to enclosing class (CCN, NPath, Halstead, ParameterCount, UnreachableCode visitors)
- Suppression system (`@qmx-ignore`): fully wired into pipeline, `@qmx-ignore-next-line` scoped to single line, file-level regex fixed, symbol-level no longer leaks to file-level
- Output formatters: SARIF schema compliance (paths, locations, helpUri), Checkstyle/Text relative paths, GitLab project-level path, JSON NaN/Infinity handling
- Configuration: `--config` now functional, `exclude_paths` accepted, YAML key normalization preserves rule IDs, deep merge for CLI overrides, `fromArray([])` applies defaults
- Security rules: XSS and command injection detect superglobals in interpolated strings
- Infrastructure: cache hit skips AST traversal, runtime state reset between runs, baseline v3 migration errors, parallel worker validation

### Breaking
- `--format=html` renamed to `--format=health`; `--format=metrics-json` renamed to `--format=metrics`
- `--format=summary` is now the default (was `text`). Use `--format=text` for the previous behavior
- `--format=json` redesigned — no longer PHPMD-compatible. See documentation for new schema
- JSON field `humanMessage` renamed to `recommendation` in violation objects
- Health scores: 5-tier labels (was 4-tier), recalibrated formulas — baselines may need regeneration
- NPath values changed due to formula corrections — baselines may need regeneration
- Baseline version 3 no longer supported — regenerate with `--generate-baseline`

## [0.3.0] - 2026-03-08

### Changed
- CLI command renamed from `analyze` to `check`, with aliases for backward compatibility
- Canonical config file name is now `qmx.yaml`
- `exclude_paths` option for violation suppression by file path patterns
- MkDocs Material documentation website (EN/RU)
- Version derived from Composer/git tag instead of hardcoded constant

### Fixed
- LCOM4 calculation aligned with original Hitz & Montazeri specification
- Maintainability Index accuracy: use ELOC instead of physical LOC
- `--workers=0` semantics corrected

## [0.2.2] - 2026-03-05

### Changed
- Rule NAME constants follow `group.rule-name` format (kebab-case)
- `SizeRule` split into `MethodCountRule` and `ClassCountRule`
- `CouplingRule` split into `InstabilityRule` and `CboRule`
- `RuleMatcher` utility for prefix-based rule matching
- ANSI colors, grouping, and `FormatterContext` for formatters
- Baseline v3 format with duplicate NAME validation
- Suppression system updated for dotted rule names and prefix matching

## [0.2.1] - 2026-03-05

### Fixed
- TTY output written line by line to prevent macOS terminal truncation

## [0.2.0] - 2026-03-05

### Changed
- Category filtering for rules
- Default thresholds calibrated

## [0.1.1] - 2026-03-04

### Changed
- `violationCode` field in `Violation` for stable baseline hashing
- Improved violation messages with thresholds and actionable advice

### Fixed
- Namespace-level violation display and `minClassCount` filter

## [0.1.0] - 2026-03-04

Initial release.

- PHP static analysis CLI tool
- Metrics: Cyclomatic Complexity, Cognitive Complexity, NPATH, Halstead, Maintainability Index
- Metrics: RFC, Instability, Abstractness, Distance from Main Sequence
- Metrics: TCC/LCC, LCOM4, WMC, LOC, DIT, NOC
- Rules with configurable thresholds
- Circular dependency detection with DOT graph export
- Output formats: Text, JSON, Checkstyle, SARIF, GitLab Code Quality
- Parallel file processing via amphp/parallel
- Git integration: `--staged`, `--diff`
- Baseline support with `@qmx-ignore` suppression tags
- AST caching, progress bar, PSR-3 logging
- Git hook installation (`hook:install`, `hook:status`)
- Symfony DI with autowiring and autoconfiguration
- GitHub Actions workflow

[0.11.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.11.0...v0.11.1
[0.11.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.9.2...v0.10.0
[0.9.2]: https://github.com/qualimetrix/qualimetrix/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.7.0...v0.7.1
[Unreleased]: https://github.com/qualimetrix/qualimetrix/compare/v0.12.0...HEAD
[0.13.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.11.2...v0.12.0
[0.11.2]: https://github.com/qualimetrix/qualimetrix/compare/v0.11.1...v0.11.2
[0.7.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/qualimetrix/qualimetrix/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/qualimetrix/qualimetrix/releases/tag/v0.1.0
