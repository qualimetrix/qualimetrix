# Infrastructure — CLI, DI, Parser and Caching

## Overview

Infrastructure contains external adapters and entry points:
- **Console**: CLI application on Symfony Console with progress reporting
- **DependencyInjection**: Unified Symfony DI container with lazy services
- **Ast**: PHP parser implementation with factory
- **Cache**: AST caching ([details](Cache/README.md))
- **Storage**: SQLite metric storage for large projects ([details](Storage/README.md))
- **Git**: Git integration for analyzing staged/changed files ([details](Git/README.md))
- **Logging**: PSR-3 logging ([details](Logging/README.md))
- **Parallel**: Parallel processing strategies
- **Serializer**: Serialization abstraction (igbinary/PHP native)
- **Profiler**: Span-based performance profiler ([details](Profiler/README.md))

## Internal Dependency Layers

Infrastructure sub-packages follow internal deptrac rules to prevent circular dependencies:

- **Leaf** (no Infrastructure siblings): Serializer, Storage, Logging, Profiler, Rule, Git
- **Mid** (depends on specific siblings): Cache -> Serializer, Ast -> Cache, Collector -> Storage, Parallel -> Ast + Cache + Serializer
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
├── Storage/                          # -> See Storage/README.md
│   ├── StorageInterface.php
│   ├── SqliteStorage.php
│   ├── InMemoryStorage.php
│   ├── StorageFactory.php
│   ├── ChangeDetector.php
│   └── FileRecord.php
├── Collector/
│   └── CachedCollector.php          # Decorator with metric caching
├── Git/                              # -> See Git/README.md
│   ├── GitClient.php
│   ├── GitScopeParser.php
│   ├── GitScope.php
│   ├── ChangedFile.php
│   ├── ChangeStatus.php
│   ├── GitFileDiscovery.php
│   ├── GitScopeFilter.php
│   ├── GitScopeResolver.php          # Resolves git scope from CLI options
│   └── GitScopeResolution.php        # Resolution result VO
├── Logging/                          # -> See Logging/README.md
│   ├── LoggerFactory.php
│   ├── LoggerHolder.php
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
│   │   ├── AnalysisConfigurator.php
│   │   └── OutputConfigurator.php
│   └── CompilerPass/
│       ├── CollectorCompilerPass.php
│       ├── GlobalCollectorCompilerPass.php
│       ├── RuleCompilerPass.php
│       ├── RuleRegistryCompilerPass.php
│       ├── RuleOptionsCompilerPass.php
│       ├── FormatterCompilerPass.php
│       ├── ConfigurationStageCompilerPass.php
│       └── ParallelCollectorClassesCompilerPass.php
├── Rule/
│   ├── RuleRegistryInterface.php
│   ├── RuleRegistry.php
│   └── Exception/
│       └── ConflictingCliAliasException.php
└── Console/                          # -> See Console/README.md
    ├── Application.php
    ├── CliOptionsParser.php
    ├── OutputHelper.php               # Helper for large text output (line-by-line flush)
    ├── ViolationFilterPipeline.php    # Violation filtering orchestration
    ├── ViolationFilterOrchestrator.php # Orchestrates violation filtering, baseline checks, and CLI output
    ├── ViolationFilterOptions.php     # Filter options VO
    ├── ViolationFilterResult.php      # Filter result VO
    ├── GitScopeFilterConfig.php       # Git scope filter config VO
    ├── RuntimeConfigurator.php        # Runtime DI configuration
    ├── ResultPresenter.php            # Output presentation
    ├── BaselinePresenter.php          # Baseline file generation presentation
    ├── ExitCodeResolver.php           # Determines CLI exit code from violations
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
        ├── BaselineCleanupCommand.php
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
- `RuleConfigurator` — rules and rule options
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
- Registers Options for each rule via `RuleOptionsFactory::create()`
- Injects Options into the rule constructor

**RuleCompilerPass:**
- Collects services with tag `qmx.rule`
- Injects into `RuleExecutor`

**RuleRegistryCompilerPass:**
- Collects rule classes (not instances)
- Injects into `RuleRegistry` for CLI option discovery

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
1. Key generation
2. Cache hit -> return from cache
3. Cache miss -> parse via `$inner`, save

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
3. Getting `CheckCommand` from container (all dependencies injected)
4. Running Application

**Runtime configuration:**
- CLI options are parsed in `CheckCommand::execute()`
- ConfigurationProvider and RuleOptionsFactory are configured before analysis
- Lazy rules are created with correct options

---

## Detailed Documentation

- [Cache/README.md](Cache/README.md) — AST Caching
- [Storage/README.md](Storage/README.md) — SQLite Metric Storage
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
