# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- **New rules**: `code-smell.long-parameter-list`, `code-smell.unreachable-code`, `code-smell.identical-subexpression`, `code-smell.god-class` (Lanza & Marinescu), `code-smell.data-class`, `code-smell.constructor-overinjection`, `code-smell.unused-private`, `design.type-coverage`, `duplication.code-duplication` (Rabin-Karp token hashing), `coupling.class-rank` (PageRank), `security.sql-injection`, `security.xss`, `security.command-injection`, `security.sensitive-parameter`, `security.hardcoded-credentials`
- **New output formats**: `--format=metrics` (raw metric values), `--format=github` (PR annotations)
- **Technical debt**: remediation time estimates per violation, aggregated debt in reports, `--detail` shows per-rule breakdown
- `--fail-on=error` option to allow warnings without failing the build
- `--include-generated` to override automatic `@generated` file skipping
- `--disable-rule=duplication` now skips the memory-intensive detection phase entirely (not just violations). Same for circular dependency detection
- Violation messages improved: actionable recommendations, parameter names in boolean-argument, coupling direction in CBO, CCN divergence hints, top-5 dependencies in coupling violations
- `bin/aimd graph:export --format=json` — dependency graph as aggregated JSON adjacency list
- `composer benchmark:check` regression suite — validates health scores against 15 open-source projects
- `llms.txt` and `llms-full.txt` — machine-readable documentation for AI coding agents

### Fixed
- Metric algorithm corrections: cognitive complexity nesting in closures, cyclomatic complexity for `match` arms, NPath formulas aligned with Nejmeh/PMD standards, Maintainability Index class-level aggregation, WOC formula, RFC for traits/enums, abstractness formula for interfaces
- Anonymous class isolation: methods inside anonymous classes no longer attributed to enclosing class (CCN, NPath, Halstead, ParameterCount, UnreachableCode visitors)
- Suppression system (`@aimd-ignore`): fully wired into pipeline, `@aimd-ignore-next-line` scoped to single line, file-level regex fixed, symbol-level no longer leaks to file-level
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
- Canonical config file name is now `aimd.yaml`
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
- Baseline support with `@aimd-ignore` suppression tags
- AST caching, progress bar, PSR-3 logging
- Git hook installation (`hook:install`, `hook:status`)
- Symfony DI with autowiring and autoconfiguration
- GitHub Actions workflow

[Unreleased]: https://github.com/fractalizer/ai-mess-detector/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/fractalizer/ai-mess-detector/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/fractalizer/ai-mess-detector/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/fractalizer/ai-mess-detector/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/fractalizer/ai-mess-detector/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/fractalizer/ai-mess-detector/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/fractalizer/ai-mess-detector/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/fractalizer/ai-mess-detector/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/fractalizer/ai-mess-detector/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/fractalizer/ai-mess-detector/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/fractalizer/ai-mess-detector/releases/tag/v0.1.0
