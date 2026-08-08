# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.24.0] - 2026-08-08

### Changed
- Baselines now use version 10 reported-magnitude ceilings: an accepted live group can fail only when its count or reported magnitude worsens, while a stale or inapplicable entry is reported without disabling the remaining baseline. Create files with `bin/qmx baseline:generate <baseline> <paths...>` and maintain them with `baseline:update` or explicitly selected `baseline:cleanup --remove` entries.
- Every output format now carries an explicit analysis-coverage verdict, including zero-file and generated-only runs. Parse or processing failures make policy results non-authoritative and return exit code 4; JSON/metrics expose a structured `coverage` object, SARIF uses invocation notifications, CI formats emit native failure records, and human/HTML reports show an explicit warning.
- Namespace LOC and structural metrics are attributed to every namespace block in multi-namespace files, while project totals continue to count each physical file exactly once. Git report scoping likewise indexes every namespace declaration in a changed file.

### Breaking
- `AnalysisConfiguration::isRuleEnabled()` and `isViolationCodeEnabled()` were removed because configuration no longer owns selection semantics. Inject `RuleSelector` and pass the producer name plus `ViolationChannel`; this preserves channels whose producer name, channel `ruleName`, and `violationCode` differ.
- Baseline file format v5 was removed. Convert an existing file with `bin/qmx baseline:migrate <baseline> <paths...>`; migration makes a fresh v10 capture because v5 has no recorded magnitude boundary.
- `check --generate-baseline=<file>` was removed. Use `bin/qmx baseline:generate <file> <paths...>` instead.
- `--baseline-ignore-stale` was removed. Stale entries now report without failing a run or disabling other baseline entries; inspect and remove only explicitly selected entries with `bin/qmx baseline:cleanup <baseline> <paths...> --remove=<selector>`.
- `--no-suppression` was renamed to `--no-suppression-annotations` with no alias. It is report-only: annotated findings are restored after baseline measurement, so the flag no longer widens the measured set or promotes an annotated finding to Error.
- The `Cycle data:` JSON trailer of an `architecture.circular-dependency` recommendation now lists fully qualified class names in its `cycle` array, where it used to list bare class names. The trailer exists to be machine-read, and a bare name does not identify a class. The keys and the shape of the object are unchanged; consumers matching on a short name must match on the fully qualified name or its trailing segment. Baseline entries are unaffected — they are not keyed by the recommendation.
- Baseline entries for `architecture.circular-dependency` must be regenerated. Cycles are now keyed by the canonically smallest class of the cycle, so any recorded entry whose key was a different member no longer matches and the cycle is reported as new. For a v5 file run `bin/qmx baseline:migrate <baseline> <paths...>`; for a v10 file, review the capture and replace it with `bin/qmx baseline:generate <baseline> <paths...> --force`. Entries for other rules are unaffected.
- `@qmx-threshold` accepts only a non-negative numeric shorthand or the generic `warning=N` / `error=N` keys (one or both, in either order). Arbitrary YAML / `--rule-opt` option names and trailing prose that were accidentally accepted by substring matching are now rejected; put an optional non-empty reason after `--` or an em dash (`—`). Prefix and wildcard rule patterns remain supported but skip per-rule validator checks, so exact rule names are recommended.
- Incomplete analysis no longer succeeds or returns a warning/error policy code: `check`, baseline lifecycle commands, and `graph:export` return exit code 4. Baseline writers and graph export refuse partial artifacts even with `--force`; existing destinations remain byte-identical.
- Maintainability Index now consumes the Size metric `methodStatementCount`; the Halstead-owned `methodLoc` / `halstead.methodLoc` metric was removed. Rename `minLoc` to `minStatements`, YAML and `--rule-opt` key `min_loc` to `min_statements`, and CLI alias `--mi-min-loc` to `--mi-min-statements`. No compatibility aliases remain. MI values, aggregates, health scores, thresholds, and baselines may shift.
- NPath now retains nested expression contributions through AST wrappers, counts every `match` arm, nullsafe access, and expression-bearing `for`, `foreach`, `switch`, and `echo` slots. Existing NPath values, thresholds, and baselines may shift.

### Fixed
- `--only-rule` now selects the full finding channel instead of assuming a rule name prefixes its `violationCode`. `--only-rule=computed.health`, `--only-rule=health.complexity`, and `--only-rule=computed.health#health.complexity` now all run the `computed.health` producer and retain the intended findings; Architecture diagnostic channels such as `architecture.coverage` and baseline lifecycle commands use the same selection contract. Valid channel selectors no longer trigger the false "does not match any registered rule" warning.
- `architecture.circular-dependency` now identifies a cycle by its smallest member instead of by whichever member the graph traversal happened to reach first. The reported symbol, the displayed cycle path and the order of reported cycles used to depend on file discovery order, so adding an unrelated file could re-key an existing cycle: its baseline entry looked resolved and the same cycle reappeared as a new violation.
- `architecture.circular-dependency` no longer renders every member of a cycle by its bare class name. Members of the same cycle that share a class name now carry the shortest trailing namespace suffix that tells them apart, so a cycle between `App\Billing\Service` and `App\Orders\Service` reads `Billing\Service → Orders\Service → Billing\Service` instead of the useless `Service → Service → Service`. Members whose short name is unique in the cycle are unchanged. This also changes the GitLab Code Quality fingerprint of an affected violation, so one such cycle will be reported as resolved and re-raised once.
- `@qmx-threshold` parsing now validates the entire value expression instead of accepting `warning=` or `error=` substrings hidden inside unsupported syntax. The documentation now also reflects the actual scope rule: a class override applies to evaluations inside the class, including its methods, while the smallest matching source span wins.
- Rules that share one Options class now receive producer-specific Options instances; configuring one code-smell or security rule no longer silently configures another rule that reuses the same immutable class.
- Unknown `--only-rule` / `--disable-rule` selectors and unknown rule-option owners now fail closed as input errors (exit 3) before a report payload is written instead of warning and continuing with an unintended rule set.
- `check` diagnostics are routed to stderr, keeping stdout valid for the selected report format even on configuration/input errors, deprecations, logging, and output-file notices.
- `baseline:explain` now rejects a symbol absent from both the current analysis and baseline, while labelling baseline-only symbols explicitly instead of presenting a misspelling as a clean result.


## [0.23.0] - 2026-07-29

### Fixed
- `code-smell.boolean-argument` no longer flags promoted constructor properties (`public bool $x`) by default — a promoted parameter declares a field, not a behavior switch, so the rule's "split into two methods" advice never applied to it. Set `flag_promoted_properties: true` to restore the previous behavior.
- `duplication.code-duplication` no longer flags a duplicate block that lies *entirely* inside a `const` array or a static/instance property's array-literal initializer — repeated key/value shape across the rows of a data table is normal, and "extract a shared method" was never actionable advice for it. A block that extends past the declaration into surrounding code is still reported, so two otherwise identical classes wrapping the same table remain a finding.

## [0.22.0] - 2026-07-28

### Fixed
- Rule options set through the config file or `--rule-opt` were silently ignored when the option name had more than one word (`vo-warning`, `param_threshold`, …), while the dedicated CLI flag for the same option worked but printed a bogus `Unknown option` warning. All three channels now agree, and `--preset=strict` applies its `vo-error` value instead of dropping it. Values were dropped for `code-smell.long-parameter-list` and `design.type-coverage`; `coupling.distance` only ever suffered the false warning.
- The documented `threshold:` shorthand crashed the whole run with `Cannot mix "threshold" with "warning"/"error"` (exit code 3) whenever it was written at the top level of a rule, which is exactly how `website/docs/getting-started/configuration.md` shows it. 15 rules were affected; the nested `method: {threshold: …}` form was never broken.
- The `threshold`, `vo-threshold` and `*_threshold` shorthands no longer produce a false `Unknown option` warning on rules that support them.
- `coupling.cbo` and `coupling.instability` now accept the `threshold` shorthand at the rule's top level, applying it uniformly to the class and namespace dimensions. They were the only two threshold rules of twenty that rejected it, answering a bare `threshold` with a bewildering `Unknown option` warning.
- `code-smell.long-parameter-list` never applied its `vo-warning` / `vo-error` thresholds: value-object constructors were reported against the ordinary thresholds instead. The VO detection flag never reached the rule.
- `architecture.unreachable-layer` no longer fires for layers that only ever match as the *target* of a dependency — such as vendor boundary layers (`ClickHouseDB\**`). Such a layer was reported unreachable in the same run where `architecture.layer-violation` flagged a real edge into it.
- Parameter and return types of closures and arrow functions are now collected into the dependency graph. Previously only their bodies were, so a layer violation that entered exclusively through a closure signature was invisible to `architecture.layer-violation`, coupling metrics and `graph:export`.
- Global `exclude_namespaces` (and `--exclude-namespace`) no longer suppress `architecture.*` violations. Silencing a noisy metric in a namespace used to switch off layer-policy enforcement there as a side effect. Per-rule exclusions still work — see below.

### Changed
- Severities of `architecture.unreachable-layer`, `architecture.potential-shadow` and `architecture.empty-template` are configurable via `unreachable_layer_severity`, `potential_shadow_severity` and `empty_template_severity` on the `architecture.layer-violation` rule. Defaults are unchanged, so a typo in `patterns:` can now fail the build instead of only whispering at info level.
- Violations dropped by per-rule `exclude_namespaces` / `exclude_paths` are now reported: `-v` prints how many were suppressed and by which rules, and `--show-suppressed` lists them in a block of their own, separate from `@qmx-ignore`. They used to disappear without a trace — on this repository's own configuration that hid 387 violations.

### Breaking
- `architecture.coverage` with `coverage: warn` now reports `Warning` severity instead of `Info`, matching the mode's name. If you relied on it staying silent under `fail_on: warning`, switch to `coverage: ignore` or raise `fail_on` to `error`.
- Dependency graph gained edges: parameter and return types of closures and arrow functions, plus attributes on their parameters. Coupling metrics that read the graph — CBO, ClassRank, instability, distance and the derived health scores — shift accordingly, and `architecture.circular-dependency` may report cycles that were previously invisible. Thresholds tuned against the old graph may need revisiting; a baseline generated before this release stays valid only for violations whose identity did not change.
- `ThresholdParser::parse()` replaced the `legacyWarningKeys` / `legacyErrorKeys` parameters with a single `legacyKeys` array keyed by `warning` / `error` / `threshold`. Named-argument calls fail with `Unknown named parameter`; the old positional form silently loses its legacy keys. Only affects third-party rule packages calling the parser directly.
- `RuleExecutorInterface` gained `getRuleExclusionStats()`. Third-party implementations of the interface must add it.
- `ViolationFilterOrchestrator::__construct()` takes an additional required `RuleExecutorInterface` argument. Only affects code constructing it directly; the container wires it automatically.

## [0.21.0] - 2026-07-28

### Fixed
- `qmx rules` crashed with a fatal `ArgumentCountError` instead of listing the rules. The command built rule objects itself, which breaks for rules that take constructor dependencies besides their options (`architecture.layer-violation`). Rule instances now always come from the DI container; `qmx check` was never affected.

### Breaking
- `RuleRegistryInterface::getAll()` removed — it could not build rules that declare constructor dependencies beyond their options. Embedding consumers that need rule instances should take them from the container (tag `qmx.rule`); `getClasses()` and `getAllCliAliases()` still cover metadata.
- `RulesCommand::__construct()` now takes `iterable<RuleInterface> $rules` instead of a `RuleRegistryInterface`. Only affects code that constructs the command directly; the container wires it automatically.

## [0.20.1] - 2026-07-28

### Fixed
- Qualimetrix reported the *consuming project's* version as its own. `qmx --version` printed things like `1.0.0+no-version-set`, and the same wrong value was stamped into every analysis artifact — `version` in JSON and SARIF, `toolVersion` in the metrics format, and the HTML report footer. The version is now resolved by package name instead of through Composer's root package, which is the host project whenever Qualimetrix is installed as a dependency.

## [0.20.0] - 2026-07-28

> **Earlier releases can no longer be installed.** The repository history was
> rewritten to remove content that should never have been published. Every tag
> before v0.20.0 now points at a commit that no longer exists, so
> `composer require qualimetrix/qualimetrix:<older version>` fails with a 404
> from GitHub. The published archives cannot be restored — upgrade to v0.20.0.

### Security
- `symfony/yaml` updated to v8.0.14, clearing three advisories: a ReDoS via catastrophic backtracking in the parser cleanup regex (CVE-2026-45305), stack exhaustion via unbounded recursion in nested blocks (CVE-2026-45133), and CVE-2026-45304. Qualimetrix parses YAML configuration on every run, so this affects all users.
- `symfony/cache` updated to v8.0.14, clearing CVE-2026-45073. It is pulled in transitively by `symfony/expression-language`, which backs computed metric formulas.

### Breaking
- `AnalysisConfiguration::{projectRoot, cacheDir, composerJsonPath}` are now typed as `AbsolutePath` / `?AbsolutePath` instead of `string` / `?string`. Embedding consumers that construct `AnalysisConfiguration` directly must wrap path arguments in `AbsolutePath::fromString(...)`. The no-arg constructor still works as before — defaults resolve lazily to `getcwd()` and `${projectRoot}/.qmx-cache`. `fromArray()` and `merge()` continue to accept string values from YAML / CLI input and resolve them via `PathFactory::fromCliArgument()`. ADR 0015 Phase 5.
- `BaselineWriter::write()` now requires `AbsolutePath` for the `$projectRoot` parameter (was optional `string = '.'`). Embedded callers must wrap their project root and pass it explicitly.
- `GitClient::getProjectRoot()` accessor removed. The project root is now owned by `GitScopeResolution` (returned from `GitScopeResolver::resolve()`); pass it explicitly to consumers that previously read it from `GitClient`.
- `FileProcessorInterface` gains `setProjectRoot(AbsolutePath): void`. `CollectionOrchestratorInterface::collect()` gains a required `AbsolutePath $projectRoot` parameter. Custom orchestrators / processors must add the method or update the call. ADR 0015 Phase 6.

### Changed
- Configuration, cache, parallel pipeline, namespace detection, and dependency analysis now consume `AbsolutePath` / `RelativePath` VOs at every internal boundary instead of untyped strings. The migration closes the path-type ambiguity that motivated the T10 git-subdirectory bug class. ADR 0015 Phase 5.
- Git infrastructure now uses typed `AbsolutePath` and `RelativePath` VOs instead of `string` throughout `GitClient`, `GitRepositoryLocator`, and `GitScopeFilter`. ADR 0015 Phase 1b.
- `GitScopeFilter` now performs eager git-to-project path translation at the `GitClient` boundary. Project roots that sit in a strict subdirectory of the git tree (T10) are now handled correctly: changed files outside the project are filtered out early, and namespace extraction for violations is resolved against the project root instead of the git top-level.
- The project's own dogfooding `qmx.yaml` now declares the full 27-layer architecture topology (Core + Configuration + Architecture slice + per-category `metrics-{Category}` template + 10 `analysis-*` sub-layers + 10 `infra-*` sub-layers) that previously lived in `deptrac.yaml`. Sub-layer enforcement (e.g. `analysis-discovery → analysis-pipeline` is now caught) gained, on top of features deptrac never had: per-category metric isolation via template expansion, and a `relations:` filter that permits `infra-di → metrics-*` references but forbids inheritance. ADR 0014.
- `Violation::$location->$file` is now typed as `?RelativePath` (was `string`). Architecture violations not tied to a single file use `Location::none()` (file is `null`). Wire/comparator surface preserved via `Location::pathString()` and `Location::isNone()` — formatters and JSON output emit the same shape as before, but file paths going *into* `Location` must be project-relative. `WorstOffender::$file` and `DuplicateLocation::$file` migrated similarly; `ParseException::$filePath` now carries `AbsolutePath` (lives at the parser boundary, where absolute paths are the natural representation). ADR 0015 Phase 1a.

### Removed
- `deptrac/deptrac` dev-dependency. `composer check` is now `cs-check + test + phpstan + selfcheck`; architecture enforcement runs entirely through Qualimetrix's own `architecture.layer-violation` rule.
- Internal `Qualimetrix\Core\Util\PathNormalizer` helper (was `@internal` since v0.18). Superseded by `Core\Path\PathFactory`. ADR 0015 Phase 6 also wires a PHPStan rule (`qmx.bannedStringPathProperty`) as a regression guard against re-introducing `string`-typed `$file` / `$filePath` / `$oldPath` properties in scoped namespaces.

### Fixed
- The HTML report build manifest (`src/Reporting/Template/package.json` and its lockfile) is now tracked. A blanket `*.json` ignore rule had been excluding it, so a fresh clone could not run `composer test:js` or `composer build:js`, and the committed `dist/` bundle could not be regenerated or audited.

## [0.19.0] - 2026-05-17

### Breaking
- `ThresholdAwareOptionsInterface` gains a static `getOverrideValidator()` accessor that returns the per-rule `OverrideValidatorInterface` strategy used to validate `@qmx-threshold` annotations. Custom Options classes in extension code must implement the new method or `use StandardOverrideValidatorTrait;` for the default `warning ≤ error + non-negative` semantics. See ADR 0013.

### Changed
- Invalid `@qmx-threshold` annotations now surface a rule-specific code (e.g. `warning_exceeds_error`, `error_exceeds_warning`, `error_not_supported`) as `violationCode: annotation.invalid-threshold.<code>` in JSON / SARIF / Checkstyle output; the human message is unchanged. Validators that provide a remediation hint (e.g. WarningOnly's "omit `error=...`") now flow through to `recommendation` so users see actionable follow-up.

### Fixed
- `@qmx-threshold maintainability.index warning=N error=M` annotations with `N > M` were silently rejected by the parser, even though the rule's defaults are `warning=40 error=20` (inverted thresholds are the natural orientation). The bug was latent across releases — Maintainability annotations work for the first time in v0.19.
- `@qmx-threshold design.type-coverage warning=N error=M` with `N > M` was rejected on the same parser invariant; type coverage is an inverted-threshold rule and now accepts the natural form.
- `@qmx-threshold design.data-class warning=N error=M` was rejected when `N > M`, but the rule maps warning to `wocThreshold` (high) and error to `wmcThreshold` (low) — independent metrics on independent axes. The annotation now validates accordingly.
- `@qmx-threshold design.god-class warning=W error=E` previously accepted the `error` value silently and then discarded it inside `withOverride()`. Explicit `error=N` is now rejected at parse time with a clear diagnostic; the shorthand form `@qmx-threshold design.god-class N` still works.

## [0.18.0] - 2026-05-16

### Breaking
- `architecture.layers` YAML schema is now an **ordered list** (long form only), not a map. The first layer whose patterns match a class FQN owns the class — declaration order is meaningful. Migration: replace `layers: { name: pattern }` with `layers: [{ name: x, patterns: [pattern] }]`. See ADR 0006.
- `RuleInterface::getCliAliases()` removed. CLI aliases are now declared via the repeatable class-level attribute `#[CliAlias('alias', 'optionName')]`. Custom rules in extension code must drop the method and add attributes on the class.
- Architecture-feature classes moved to a vertical slice under `Qualimetrix\Architecture\{Domain,Configuration,Processing,Rules}` per ADR 0010. Extension authors importing from the old `Qualimetrix\Core\Architecture`, `Qualimetrix\Configuration\Architecture`, `Qualimetrix\Analysis\Architecture`, or `Qualimetrix\Rules\Architecture` namespaces must update imports.

### Changed
- New rule `architecture.layer-violation`: declare layers in YAML and enforce allowed inter-layer dependencies. Membership supports `patterns`, `suffix`, `attributes`, `implements`, `extends` (combined via `match: any | all`); parameterised template layers expand against the observed class set (`{var}` capture); `exclude:` blocks hard-filter assignment; allow-list `relations:` whitelists restrict permitted `DependencyType` kinds; capture-binding (`'app-{m}': ['domain-{m}']`) constrains allows to same-instance edges for DDD bounded contexts. Incremental adoption via `architecture.coverage`; expansion capped by `architecture.max_expanded_layers` (default 500). See ADRs 0006–0008.
- New diagnostics: `architecture.empty-template` (warning — template expanded to zero layers), `architecture.unreachable-layer` (info — layer pattern matched zero classes), `architecture.potential-shadow` (info — evidence-based detection of layers silently stealing classes from later, narrower layers).
- New CLI command `debug:layer-assignment <fqn>`: per-class introspection of layer assignment — reports the assigned layer and which other layers' patterns would also have matched. Runs full Discovery + Collection so output matches `qmx check` byte-for-byte.
- `qmx.yaml.example` includes a commented-out `architecture:` stanza demonstrating multi-criterion membership, `exclude:`, templates, vendor layers, `allow:` (plain, captured same-instance, long-form with `relations:`), `coverage`, and `max_expanded_layers`.

### Fixed
- `@qmx-threshold` annotations on `design.type-coverage`, `design.god-class`, and `design.data-class` previously had no effect — the Options classes did not implement `ThresholdAwareOptionsInterface`. The three Options now implement it and apply overrides per class.
- `architecture.layer-violation` now respects `@qmx-ignore` suppressions placed on the offending class — the dependency visitor used absolute paths while the suppression map was keyed by relative paths.
- `architecture.layer-violation` no longer false-positives mutual-allow when the two directions use disjoint `relations:` filters or `allow_cross_instance: true`.
- `architecture.max_expanded_layers` now actually takes effect when set in YAML (previously silently camelCased and ignored). See ADR 0009.
- `architecture.allow` source and target selectors now reject `[` brackets at config-load time with an actionable hint suggesting `{var}` capture-variable syntax.
- `debug:layer-assignment` now honours `memory_limit` from `qmx.yaml`.
- Architecture configuration warnings (e.g. mutual-allow detection) now actually reach the user logger.
- SARIF formatter `$schema` URL updated to the OASIS canonical location after the upstream repo reorganized.

## [0.17.0] - 2026-05-12

### Fixed
- `health.typing` no longer reports 0% for namespaces with no typeable declarations (e.g. marker interfaces used for Symfony Messenger routing). Empty type surface now yields 100% (vacuous truth) at namespace and project levels, matching the existing class-level semantic.
- Disabling a health dimension via `computed_metrics.health.X.enabled: false` no longer breaks `health.overall`. Both `enabled: false` and `exclude_health: [X]` now follow the same pipeline — the dimension is removed and `health.overall` weights are renormalized across the remaining dimensions.

### Changed
- Excluding a health dimension when `health.overall` has been overridden with a non-canonical formula (one that does not match `(health__dim ?? fallback) * weight`) now throws an explicit error instead of silently dropping the formula. Custom formulas should handle disabled dimensions via `??` fallbacks.

## [0.16.0] - 2026-05-01

### Changed
- `health.coupling` namespace formula rewritten to use efferent-only signals (`ce.avg`, `ce_packages.avg`, `ce.max`, `ce`, distance). Stable contracts namespaces (high incoming, low outgoing dependencies) are no longer unfairly penalized by bidirectional CBO. Class- and project-level formulas are unchanged.
- New aggregations for the `ce` metric at namespace and project levels: `ce.avg`, `ce.max`, `ce.p95`.

## [0.15.0] - 2026-04-04

### Changed
- Strict configuration validation: unknown section sub-keys (`cache.typo`), invalid value types (`cache.enabled: "yes"`), and unknown rule names (`rules.complexty`) now produce clear errors with "Did you mean?" suggestions
- Warnings (e.g., unknown rule option keys) are now visible at default verbosity via stderr, without requiring `-v`

### Fixed
- Configuration warnings were invisible without `-v` flag due to `NullLogger` at default verbosity

## [0.14.0] - 2026-04-03

### Changed
- `--exclude-namespace` CLI option for violation suppression by namespace (prefix or glob), merged with `exclude_namespaces` from `qmx.yaml`

### Fixed
- Computed metric names with underscores (e.g., `computed.my_score`) were incorrectly normalized to camelCase in YAML config

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
[Unreleased]: https://github.com/qualimetrix/qualimetrix/compare/v0.24.0...HEAD
[0.24.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.23.0...v0.24.0
[0.23.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.20.1...v0.21.0
[0.20.1]: https://github.com/qualimetrix/qualimetrix/compare/v0.20.0...v0.20.1
[0.20.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/qualimetrix/qualimetrix/compare/v0.13.0...v0.14.0
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
