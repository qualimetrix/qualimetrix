# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- `code-smell.long-parameter-list` rule — detects methods with too many parameters (warning: 4, error: 6)
- `code-smell.unreachable-code` rule — detects dead code after return/throw/exit statements
- `design.type-coverage` rule — measures type declaration coverage for parameters, return types, and properties per class
- `--format=metrics-json` output format — exports raw metric values for all symbols (methods, classes, namespaces, files)

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

[Unreleased]: https://github.com/fractalizer/ai-mess-detector/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/fractalizer/ai-mess-detector/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/fractalizer/ai-mess-detector/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/fractalizer/ai-mess-detector/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/fractalizer/ai-mess-detector/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/fractalizer/ai-mess-detector/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/fractalizer/ai-mess-detector/releases/tag/v0.1.0
