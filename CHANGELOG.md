# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- 5-tier health labels (`Strong` / `Good` / `Acceptable` / `Weak` / `Critical`) replace the previous 4-tier scheme — new `Good` tier fills the 65–80 gap
- `--exclude-health=DIMENSION` option (CLI + YAML) to exclude specific health dimensions from scoring and `health.overall` calculation
- Complexity health now uses per-method CCN average instead of WMC-based average — fixes floor effect where small projects with concentrated complexity scored 0
- Maintainability health formula stretched `(mi_avg - 40) * 1.667` for better score spread
- ClassRank thresholds scale by `sqrt(classCount / 100)` — adapts to project size instead of fixed thresholds
- Tech debt scaled by `base * max(1, ln(ratio))` — large violations no longer dominate total debt
- `--format-opt=limit=N` for JSON violations count (`limit=0` for unlimited); `violationsMeta.shown` field added
- God-class detection: TCC veto — classes with TCC >= 0.5 (good cohesion) can no longer be flagged as god classes
- Data class rule excludes interfaces, abstract classes, zero-property classes, and exceptions (reduces false positives)
- CBO violations show coupling direction (`afferent` / `efferent` / `balanced` with Ca/Ce counts)
- Circular dependency output uses structured JSON with size categories
- NPath display capped at `> 1M` instead of `> 10^9`
- Debug-code rule: `var_export`/`print_r` in return mode no longer flagged; debug API methods and `debug_backtrace` excluded; severity lowered to WARNING
- Unused-private violations now name the specific symbol in the message
- WMC message includes average method CCN
- `computed.health` violations include dimension-specific detail
- TCC uses neutral value (0.5) for small classes (<6 methods) and all-static classes
- Empty-catch rule excludes chain-of-responsibility pattern (catch with only return/continue)
- Hardcoded credentials rule excludes human-readable messages and translation keys
- Threshold phrasing in violation messages changed from `(max X)` / `(min X)` to `(threshold: X)` for clarity at boundary values
- `--namespace` and `--class` drill-down now shows total tech debt line (aggregated from per-rule breakdown)
- Complexity health formula retuned — linear penalty model with balanced CCN/cognitive/NPath weights (was harmonic K=32 with 92% cognitive bias). Cross-project score spread improved from 1.8pt to 6.6pt
- Class-level coupling health uses efferent coupling (CE) instead of bidirectional CBO — popular utility classes (e.g., `Collection`) no longer penalized for being widely used
- `--class` drill-down now shows the class's own health scores in the header (was showing project-wide scores)
- Namespace drill-down header shows `(direct: X%)` marker when recursive and flat scores differ by >5 points
- `worstClasses` always shows top-N classes regardless of health threshold (previously empty when all classes scored above 50%)
- Empty root namespaces (no direct classes) filtered from worst namespace list
- `--format-opt=top=N` now works with `summary` format (was hardcoded to 3, only worked with JSON)
- Namespace drill-down hints now suggest `--class=` for the worst class (completing the progressive disclosure chain)
- `+N more` in worst offenders now suggests `--format=html` or `--format-opt=top=N`
- Per-dimension label footnote explains that dimensions have independent thresholds
- Typing health decomposition shows parameter/return/property type percentages
- `health.typing` violations now include specific recommendation text
- `--generate-baseline` exits 0 when baseline is successfully written (was exit 2)
- Baseline "written" message reports deduplicated violation count (was raw count)
- Boolean argument violations now show the parameter name (e.g., `$overwrite`)
- Debt density display clarified with "to fix" suffix
- `violationsMeta.byRule` added to JSON output — per-rule violation counts for complete visibility without `violations=all`
- SARIF `helpUri` now links to rule-specific documentation pages
- GitLab Code Quality: project-level violations use `[project-level]` path instead of invalid `.`
- `code-smell.long-parameter-list` rule — detects methods with too many parameters (warning: 4, error: 6)
- `code-smell.unreachable-code` rule — detects dead code after return/throw/exit statements
- `design.type-coverage` rule — measures type declaration coverage for parameters, return types, and properties per class
- `--format=metrics-json` output format — exports raw metric values for all symbols (methods, classes, namespaces, files)

### Breaking
- JSON field `humanMessage` renamed to `recommendation` in violation objects. Update any scripts parsing `humanMessage`
- Health score values changed due to formula retuning — baselines and stored thresholds may need regeneration
- `health.maintainability` thresholds changed from 65/50 to 50/25 due to stretched formula — existing baselines may need regeneration
- Health label scheme changed from 4-tier to 5-tier — scripts checking label strings need updating
- `--format=json` redesigned as summary-oriented output — includes `meta`, `summary`, `health` scores with decomposition, `worstNamespaces`, `worstClasses`, and `violations` (top 50 by default). Supports `--format-opt=violations=all|0|N`, `--format-opt=top=N`, `--detail`, `--namespace`/`--class` drill-down. No longer PHPMD-compatible
- `--format=summary` is now the **default CLI output** — shows health overview with bars, worst offenders, violation summary, and contextual hints in one screen. Previous default `text` format is still available via `--format=text`
- `--namespace` and `--class` CLI options for drill-down filtering — boundary-aware namespace prefix matching and exact FQCN class matching (mutually exclusive)
- Composite code-smell rules: God Class (`code-smell.god-class`, Lanza & Marinescu 4-criteria detection), Data Class (`code-smell.data-class`, high WOC + low WMC), Constructor Over-injection (`code-smell.constructor-overinjection`, configurable thresholds 8/12)
- Class-level LOC metric (`classLoc`) for accurate God Class size detection
- `--disable-rule=duplication` now skips the memory-intensive duplication detection phase entirely (previously only suppressed violations). Same for `--disable-rule=architecture.circular-dependency`. Resolves out-of-memory issues on large codebases (500+ files)
- `--format=html` interactive HTML report — self-contained file with D3.js treemap visualization, health score coloring, drill-down navigation, search, metric selector, dark mode support. Metric hints and health decomposition data are now embedded from PHP (single source of truth) with descriptive labels
- `--output` / `-o` generic option to write any format to a file with atomic writes (works with all formats, not just HTML)
- `computed_metrics` config section with 6 default `health.*` scores (complexity, cohesion, coupling, typing, maintainability, overall), user-definable `computed.*` metrics via Symfony Expression Language formulas, per-level formulas, threshold-based violations. Formulas calibrated against 9 open-source projects (391 namespaces): harmonic decay for complexity, balanced TCC/LCOM weights for cohesion, distance+CBO model for coupling
- `typeCoverage.pct` derived metric — overall type coverage percentage at class level
- `--fail-on` option to control which severity level triggers a non-zero exit code (`--fail-on=error` allows warnings)
- `--format=github` output format for GitHub Actions inline PR annotations
- Identical sub-expression detection — catches copy-paste errors and logic bugs: identical operands (`$a === $a`), duplicate if/elseif conditions, identical ternary branches, duplicate match arms (`code-smell.identical-subexpression` rule)
- Code duplication detection — token-stream hashing (Rabin-Karp) detects copy-paste across files (`duplication.code-duplication` rule, configurable `min_lines`/`min_tokens`)
- Unused private members detection — flags private methods, properties, and constants never referenced within the class (`code-smell.unused-private` rule)
- SARIF `relatedLocations` support — duplication violations include clickable cross-references to all copies in IDE SARIF viewers
- ClassRank metric — PageRank-based class importance ranking via dependency graph (`coupling.class-rank` rule)
- Security pattern rules: `security.sql-injection`, `security.xss`, `security.command-injection` — AST-based detection of direct superglobal flows
- `security.sensitive-parameter` rule — detects parameters with sensitive names missing `#[\SensitiveParameter]` attribute
- Technical debt reporting — remediation time estimates per violation, aggregated debt in text and JSON output
- NPath complexity formula changes: `for` loop follows Nejmeh 1988 standard, `try-catch-finally` follows PMD/Checkstyle convention — existing NPath values may change
- Baseline version 3 is no longer supported — regenerate with `--generate-baseline` (v3 hashes were silently incompatible with v4)
- `DependencyGraphInterface` and `Dependency` VO now use `SymbolPath` instead of raw string FQNs
- `SymbolPath::forProject()` factory for project-level metrics, separated from global namespace
- `AnalysisContext::$cycles` typed property replaces untyped `additionalData` side-channel
- `CachedCollector` now caches file dependencies alongside metrics — cache hit skips AST traversal entirely
- SARIF and GitLab Code Quality formatters now output repo-relative paths instead of absolute paths
- `--config` CLI option is now functional (was defined but silently ignored)
- `RuntimeConfigurator` uses deep merge for rule options — CLI overrides individual keys instead of replacing entire rule config
- `ComposerReader` handles multi-path PSR-4 arrays and `autoload-dev` paths
- Hook commands use shared `GitRepositoryLocator` with git-worktree support
- `security.hardcoded-credentials` rule for detecting hardcoded passwords, API keys, secrets, and other credentials in PHP code
- Code smell rules now report violations per occurrence with precise line numbers instead of a single violation per file
- Global collector metrics (CBO, Instability, NOC, Distance) are now properly aggregated to namespace and project levels
- Circular dependency detection is now active — `architecture.circular-dependency` rule produces violations
- `AnalysisPipeline` now accepts `MetricRepositoryFactoryInterface` instead of hardcoding `InMemoryMetricRepository`
- `MetricAggregator` and all aggregators now depend on `MetricRepositoryInterface` instead of concrete implementation
- `WorkerBootstrap` validates collector instantiability before creating instances

### Fixed
- Fixed Maintainability Index always reporting 0 at class level — collector now properly skips non-method symbols where Halstead data is absent
- Fixed class-level instability rule flagging leaf classes with no afferent coupling (Ca=0)
- Fixed Distance rule silently producing zero results when no project namespaces detected (now warns)
- Fixed duplicate violations in `code-smell.unused-private` rule — global collectors were duplicating `DataBag` entries via `add()` merge; introduced `addScalar()` to `MetricRepositoryInterface`
- Fixed Maintainability Index (MI) not aggregated to namespace/project level — non-additive method metrics now use Average fallback when Sum is unavailable
- Fixed cognitive complexity nesting level not restored after closures/arrow functions inside nested scopes
- Fixed cyclomatic complexity not counting `match` expression arms
- Fixed NPath `for`-loop formula deviation from Nejmeh 1988 standard (extra +1 for init removed)
- Fixed NPath `try-catch-finally` formula to standard `(try + catches + 1) * finally`
- Fixed WOC (Weight of Class) formula — numerator now includes public getters/setters for consistency with denominator
- Fixed RFC visitor not collecting own methods for traits and enums
- Fixed `@aimd-ignore` symbol-level suppression incorrectly suppressing file/namespace-level violations (line=null)
- Fixed `SuppressionFilter::addSuppressions()` silently overwriting previous suppressions (renamed to `setSuppressions()`)
- Fixed `BaselineLoader` leaking `DateMalformedStringException` instead of documented `RuntimeException`
- Fixed SARIF `pathToFileUri()` breaking UNC paths due to `ltrim('/')`
- Fixed SARIF emitting empty `physicalLocation` for `Location::none()` (violates SARIF 2.1.0 schema)
- Fixed Checkstyle and Text formatters using absolute paths instead of relative (now use `relativizePath()`)
- Fixed `ComposerReader` discarding root PSR-4 mapping (`"App\\": ""`) — now normalizes to `'.'`
- Fixed `AnalysisConfiguration::merge()` unable to reset `onlyRules`/`aggregationPrefixes` to empty array
- Fixed runtime state leakage: suppressions, CLI rule options, and parallel strategy now reset between runs
- Fixed `ResultPresenter` profile export silently succeeding on I/O errors
- Fixed `StrategySelector` using unresolved path for cache directory
- Fixed `ParallelCollectorClassesCompilerPass` using service ID instead of actual class name
- Fixed boolean argument code smell not detecting `?bool` and union types containing `bool`
- Fixed `MaintainabilityRule` docblock referencing wrong threshold scale
- Fixed NPath complexity undercounting loop conditions — `while ($a && $b)` now correctly analyzes boolean operators
- Fixed getter/setter false positives — `isolate()`, `setup()`, `getaway()`, `hasty()` no longer classified as accessors
- Fixed global PHP namespace collision with project-level metrics — classes without a namespace are now properly included in namespace-level analysis
- Fixed anonymous class leakage in CCN, NPath, Halstead, ParameterCount, UnreachableCode visitors — methods inside anonymous classes were incorrectly attributed to the enclosing named class
- Fixed abstractness formula to include interfaces in denominator (namespace with only interfaces now correctly returns 1.0)
- Fixed cognitive complexity undercounting when consecutive statements use the same logical operator
- Fixed `@aimd-ignore` suppression system — now fully wired into the analysis pipeline (was previously dead code)
- Fixed `@aimd-ignore-next-line` to only suppress violations on the specific next line (was suppressing entire file)
- Fixed `@aimd-ignore-file` regex to work without explicit rule argument (defaults to wildcard)
- Fixed `exclude_paths` YAML configuration — was rejected as unknown key
- Fixed YAML key normalization mangling rule identifiers (`size.method-count` was incorrectly converted to `size.methodCount`)
- Fixed inconsistent threshold comparison operators — all rules now use `>=` (4 rules previously used strict `>`)
- Fixed `fromArray([])` silently disabling rules instead of applying defaults
- Fixed `GitFileDiscovery::isInPaths()` prefix matching (`src` no longer incorrectly matches `src2/`)
- Fixed `RfcVisitor` losing method context when closures or anonymous classes appear inside methods
- Fixed `ViolationHasher` collision risk by increasing hash from 32-bit to 64-bit (baseline version bumped to 4)
- Fixed `ProfilerHolder` creating new `NullProfiler` instance on every `get()` call
- Fixed AST visitors losing class context after anonymous classes, causing incorrect FQN for subsequent methods
- Fixed derived metric collectors not seeing each other's outputs (e.g., Maintainability Index depending on Halstead)
- Fixed dependency graph not being passed to analysis rules

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
