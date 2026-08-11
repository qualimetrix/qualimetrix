# Infrastructure — CLI, DI, Parser and Caching

## Overview

Infrastructure contains external adapters and entry points:
- **Console**: CLI application on Symfony Console with progress reporting
- **DependencyInjection**: Unified Symfony DI container with lazy services
- **Ast**: PHP parser implementation with factory
- **Cache**: AST caching ([details](Cache/README.md))
- **Git**: Git integration for analyzing staged/changed files ([details](Git/README.md))
- **Logging**: PSR-3 logging ([details](Logging/README.md))
- **Parallel**: Parallel processing strategies
- **Serializer**: Serialization abstraction (igbinary/PHP native)
- **Profiler**: Span-based performance profiler ([details](Profiler/README.md))

## Internal Dependency Layers

Infrastructure sub-packages are declared as `infra-*` sub-layers in the project's
own `qmx.yaml` to prevent circular dependencies (see
[ADR 0014](../../docs/adr/0014-deptrac-retirement.md)):

- **Leaf** (no Infrastructure siblings): Serializer, Logging, Profiler, Rule, Git
- **Mid** (depends on specific siblings): Cache -> Serializer, Ast -> Cache, Parallel -> Ast + Cache + Serializer
- **Hub** (wide dependencies): Console -> Git, Rule, Cache, Logging, Profiler; DI -> all

## Structure

```
Infrastructure/
├── Ast/
│   ├── PhpFileParser.php            # Base implementation
│   ├── CachedFileParser.php         # Decorator with caching
│   └── FileParserFactory.php        # Factory with config awareness
├── Cache/                            # -> See Cache/README.md
│   ├── CacheInterface.php
│   ├── FileCache.php
│   ├── CacheFactory.php
│   ├── CacheKeyGenerator.php
│   └── CacheWriteException.php      # Cache write failure exception
├── Git/                              # -> See Git/README.md
│   ├── GitClient.php
│   ├── GitScopeParser.php
│   ├── GitScope.php
│   ├── ChangedFile.php
│   ├── ChangeStatus.php
│   ├── GitScopeFilter.php
│   ├── GitScopeResolver.php          # Resolves git scope from CLI options
│   ├── GitScopeResolution.php        # Resolution result VO
│   └── Exception/UnresolvedGitReferenceException.php # Invalid git revision input
├── Logging/                          # -> See Logging/README.md
│   ├── LoggerFactory.php
│   ├── LoggerHolder.php
│   ├── LoggerHelperTrait.php        # Shared PSR-3 interpolation and level filtering
│   ├── DelegatingLogger.php
│   ├── ConsoleLogger.php
│   └── FileLogger.php
├── Parallel/
│   ├── FileProcessingTask.php       # Task executed in parallel workers
│   ├── WorkerBootstrap.php          # Worker bootstrap (filters by ParallelSafeCollectorInterface)
│   └── Strategy/
│       ├── SequentialStrategy.php      # Single-process execution
│       ├── AmphpParallelStrategy.php   # Multi-worker via amphp
│       ├── StrategySelector.php        # Strategy selection logic
│       └── WorkerCountDetector.php     # Detects optimal worker count
├── Serializer/
│   ├── SerializerInterface.php      # Serializer contract
│   ├── IgbinarySerializer.php       # igbinary-based serializer
│   ├── PhpSerializer.php            # PHP native serializer
│   └── SerializerSelector.php       # Auto-selects best serializer
├── Profiler/                         # -> See Profiler/README.md
│   ├── Profiler.php
│   └── Export/
├── DependencyInjection/
│   ├── ContainerFactory.php           # Thin orchestrator (delegates to configurators)
│   ├── Configurator/                  # Decomposed container configuration
│   │   ├── ContainerConfiguratorInterface.php
│   │   ├── CoreServicesConfigurator.php
│   │   ├── ConfigurationConfigurator.php
│   │   ├── ParserConfigurator.php
│   │   ├── CollectorConfigurator.php
│   │   ├── RuleConfigurator.php
│   │   ├── ArchitectureConfigurator.php
│   │   ├── DuplicationConfigurator.php
│   │   ├── AnalysisConfigurator.php
│   │   └── OutputConfigurator.php
│   └── CompilerPass/
│       ├── CollectorCompilerPass.php
│       ├── GlobalCollectorCompilerPass.php
│       ├── RuleCompilerPass.php
│       ├── RuleRegistryCompilerPass.php
│       ├── ChannelDeclarationCompilerPass.php
│       ├── RuleOptionsCompilerPass.php
│       ├── FormatterCompilerPass.php
│       ├── ConfigurationStageCompilerPass.php
│       └── ParallelCollectorClassesCompilerPass.php
├── Rule/
│   ├── RuleRegistryInterface.php
│   ├── RuleRegistry.php
│   ├── ChannelDeclarationRegistry.php     # Implements Core\Violation\ChannelDeclarationRegistryInterface: (ruleName, violationCode) -> ChannelDeclaration
│   ├── RuleChannelRegistry.php             # Implements Core\Rule\RuleChannelRegistryInterface: producer rule -> emitted channels
│   └── Exception/
│       └── ConflictingCliAliasException.php
└── Console/                          # -> See Console/README.md
    ├── Application.php
    ├── CliOptionsParser.php
    ├── OutputHelper.php               # Helper for large text output (line-by-line flush)
    ├── MeasuredViolationSet.php       # The one definition of the set a baseline measures: paths + resolved config in, findings at the baseline stage's input out (no InputInterface)
    ├── ViolationFilterPipeline.php    # Runs the ordered stages: suppression -> path exclusion -> namespace exclusion -> baseline -> git scope; also where --no-suppression-annotations puts annotated findings back into the report, past the baseline
    ├── ViolationFilterOrchestrator.php # Turns check's options into a pipeline run and reports what its stages did — also reports per-rule namespace/channel/path suppression (via injected RuleExecutorInterface, Analysis\RuleExecution\RuleExclusionStats) for -v / --show-suppressed
    ├── ViolationFilterOptions.php     # Filter options VO
    ├── CliOnlyNarrowing.php           # VO: --exclude-path / --exclude-namespace / --no-suppression-annotations — narrowing that is check's alone and not part of the measured set; a flag may shrink what the ceiling measures, never grow it
    ├── ViolationFilterResult.php      # Filter result VO: reported findings, the measured set, per-stage removals, stale entries
    ├── GitScopeFilterConfig.php       # Git scope filter config VO
    ├── RuntimeConfigurator.php        # Runtime DI configuration; also sets Core\Violation\RuleExclusionCaptureHolder from --show-suppressed
    ├── DiagnosticOutput.php          # Routes human diagnostics to stderr without polluting report payloads
    ├── RuleInputValidator.php        # Fails closed on unknown selectors and option owners
    ├── ResultPresenter.php            # Output presentation
    ├── ExitCodeResolver.php           # Determines policy codes and incomplete-analysis exit 4
    ├── ScopeWarningChecker.php        # Warns when analysis paths don't cover all composer.json autoload entries
    ├── ProfilePresenter.php           # Handles profiling output: summary to stderr or export to file
    ├── FormatterContextFactory.php    # Creates FormatterContext from CLI input options
    ├── CheckCommandDefinition.php     # Command option definitions
    ├── FilteredInputDefinition.php    # InputDefinition that hides rule-specific options from --help
    ├── Progress/
    │   ├── ConsoleProgressBar.php
    │   ├── ProgressReporterHolder.php
    │   └── DelegatingProgressReporter.php
    └── Command/
        ├── CheckCommand.php           # Thin orchestrator (delegates to extracted classes)
        ├── BaselineCommand.php              # Base class for the four lifecycle commands: shared error-to-exit-code mapping and scope validation
        ├── BaselineCommandDefinition.php    # Shared input definition: paths + the configuration options that decide what is measured (--config, --preset, --rule-opt, --only-rule, --disable-rule), deliberately without check's exclusion/suppression flags (ADR 0017)
        ├── BaselineRunInterface.php         # The one way a baseline command obtains the set it measures
        ├── BaselineRun.php                  # Implements BaselineRunInterface: resolves configuration, configures the runtime and runs the analysis exactly as `check` does
        ├── BaselineRunContext.php           # VO: one run's measured violations, its RunScope and project root
        ├── BaselineCaptureReporter.php      # Reports non-baselineable findings omitted by baseline:generate
        ├── BaselineConfiguredThresholds.php # Resolves each channel's qmx.yaml-configured warning boundary, for baseline:explain
        ├── BaselineGenerateCommand.php # `baseline:generate` — captures the current findings as a new baseline file
        ├── BaselineUpdateCommand.php   # `baseline:update` — direction-aware monotonic tightening of an existing baseline in place
        ├── BaselineCleanupCommand.php  # `baseline:cleanup` — lists removal candidates (stale/undeclared/inert entries) and removes only the selectors named via --remove
        ├── BaselineExplainCommand.php  # `baseline:explain` — prints the effective boundary for one symbol and its three sources (baseline, qmx.yaml, @qmx-threshold)
        ├── GraphExportCommand.php           # Export dependency graph (DOT, JSON)
        ├── RulesCommand.php           # Lists all rules with options and CLI aliases
        ├── HookInstallCommand.php
        ├── HookStatusCommand.php
        └── HookUninstallCommand.php
```

---

## Dependency Injection

### Architecture

```
ContainerFactory.create()
        |
   Unified container with:
   - Lazy Rules (created on first use)
   - Mutable providers (ConfigurationProvider, RuleOptionsFactory)
   - CacheFactory for lazy cache creation
        |
   CheckCommand receives all dependencies via constructor
        |
   In execute():
   1. CLI parsing -> config + ruleOptions
   2. ConfigurationProvider.setConfiguration(config)
   3. RuleOptionsFactory.setCliOptions(...)
   4. Analyzer.analyze() -> Rules are created with correct options
```

### ContainerFactory (Decomposed)

Creates a unified Symfony DI ContainerBuilder without parameters. Delegates configuration to specialized configurators implementing `ContainerConfiguratorInterface`:

- `CoreServicesConfigurator` — core services (logger, profiler, etc.)
- `ConfigurationConfigurator` — configuration pipeline and providers
- `ParserConfigurator` — AST parser and caching
- `CollectorConfigurator` — metric collectors registration
- `RuleConfigurator` — remaining layered rules under `src/Rules/`
- `ArchitectureConfigurator` — Architecture capability services and rules
- `DuplicationConfigurator` — Duplication detector/provider wiring, contract alias, and capability-owned rule registration
- `AnalysisConfigurator` — analysis pipeline, repository, strategies
- `OutputConfigurator` — formatters and output

**Method:**
- `create(): ContainerBuilder` — runs all configurators and returns a compiled container

**Runtime configuration:**
Configuration is set via mutable services AFTER container creation:
- `ConfigurationProviderInterface::setConfiguration()` — main configuration
- `RuleOptionsFactory::setCliOptions()` — rule options from CLI

**Tags:**
- `qmx.collector` — metric collectors
- `qmx.global_collector` — global context collectors
- `qmx.rule` — analysis rules (lazy)
- `qmx.formatter` — output formatters
- `qmx.configuration_stage` — configuration pipeline stages

### Lazy Services

Rules and their Options are made lazy via `->setLazy(true)`:
- Rules are not created during container compilation
- Rules are created on first use in RuleExecutor
- By that time RuleOptionsFactory is already configured with CLI options

### CompilerPass

**CollectorCompilerPass:**
- Collects services with tag `qmx.collector`
- Injects into `CompositeCollector`

**GlobalCollectorCompilerPass:**
- Collects services with tag `qmx.global_collector`
- Injects into `GlobalCollectorRunner`

**RuleOptionsCompilerPass:**
- Registers producer-specific Options for each rule via `RuleOptionsFactory::create()`
- Injects Options into the rule constructor

The service identity contains both producer name and Options class. Rules may
share an immutable Options implementation, but their configured instances must
remain independent because configuration is keyed by producer rule name.

**RuleCompilerPass:**
- Collects services with tag `qmx.rule`
- Injects into `RuleExecutor` and `RulesCommand` — the only supported source of
  rule *instances* (a rule may take constructor dependencies besides its
  Options object, so nothing outside the container may build rules)

**RuleRegistryCompilerPass:**
- Collects rule classes (not instances)
- Injects into `RuleRegistry` for CLI option discovery
- Fails the container build when a rule class omits its `NAME` constant

**ChannelDeclarationCompilerPass:**
- Walks the same `qmx.rule`-tagged services as `RuleRegistryCompilerPass`
- Reads each rule's optional static `channelDeclarations(): array<string, ChannelDeclaration>`
  method via `Core\Rule\ChannelDeclarationReader` (reflection, no instantiation — a rule
  with no such method is untouched)
- Each rule already returns full channel keys (`ruleName#violationCode`), so this pass
  does no pairing of its own — it assembles the declaration map and injects it into
  `ChannelDeclarationRegistry`, along with `ComputedMetricRule::NAME` as the
  run-time family discriminator
- Preserves which registered rule class produced each static channel and injects that
  topology into `RuleChannelRegistry`; the registry adds configured computed channels
  at run time for channel-aware `--only-rule` / `--disable-rule` selection
- Fails the container build on a channel declared by more than one rule class

**FormatterCompilerPass:**
- Collects services with tag `qmx.formatter`
- Registers in `FormatterRegistry`

**ConfigurationStageCompilerPass:**
- Collects services with tag `qmx.configuration_stage`
- Injects into `ConfigurationPipeline` in priority order

**Test coverage:** All 8 CompilerPasses have dedicated unit tests (`tests/Unit/Infrastructure/DependencyInjection/CompilerPass/`) covering service registration, tag handling, and edge cases.

---

## PHP Parser

### PhpFileParser

Implementation of `FileParserInterface` via nikic/php-parser.

**Behavior:**
- Creates Parser via `ParserFactory::createForNewestSupportedVersion()`
- Throws `ParseException` on errors

### CachedFileParser (Decorator)

Decorator for `FileParserInterface`.

**Dependencies:**
- `FileParserInterface $inner`
- `CacheInterface $cache`
- `CacheKeyGenerator $keyGenerator`

**Algorithm of parse():**
1. Read source bytes once from the original file.
2. Generate the cache key from those bytes.
3. Cache hit -> return from cache.
4. Cache miss -> parse those same bytes via `$inner` while retaining the original file for diagnostics, save.

### FileParserFactory

Factory with runtime configuration awareness.

**Dependencies:**
- `PhpFileParser $parser`
- `CacheInterface $cache`
- `CacheKeyGenerator $keyGenerator`
- `ConfigurationProviderInterface $configurationProvider`

**Method:**
- `create(): FileParserInterface` — returns `CachedFileParser` or `PhpFileParser` depending on `config.cacheEnabled`

---

## Entry Point

### bin/qmx

**Algorithm:**
1. Finding autoloader
2. Creating unified DI container via `ContainerFactory::create()`
3. Registering commands via `ContainerCommandLoader` (lazy — commands are only instantiated when executed)
4. Running Application

**Runtime configuration:**
- CLI options are parsed in `CheckCommand::execute()`
- ConfigurationProvider and RuleOptionsFactory are configured before analysis
- Lazy rules are created with correct options

---

## Detailed Documentation

- [Cache/README.md](Cache/README.md) — AST Caching
- [Git/README.md](Git/README.md) — Git Integration
- [Logging/README.md](Logging/README.md) — PSR-3 Logging
- [Console/README.md](Console/README.md) — CLI Commands and Options
- [Profiler/README.md](Profiler/README.md) — Span-based Profiler

---

## Definition of Done

### Core Infrastructure
- `bin/qmx check src/` works
- Unified DI container assembles all dependencies
- Lazy Rules are created with correct runtime options
- FileParserFactory returns the correct implementation
- All CLI options work (including aliases --cyclomatic-warning, --cyclomatic-error)
- Exit codes are correct
- No ServiceLocator (all dependencies via constructor)
