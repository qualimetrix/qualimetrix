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
- **Profiler**: Span-based performance profiler ([details](Profiler/README.md))

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
│   └── CacheKeyGenerator.php
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
│   └── GitScopeFilter.php
├── Logging/                          # -> See Logging/README.md
│   ├── LoggerFactory.php
│   ├── LoggerHolder.php
│   ├── DelegatingLogger.php
│   ├── ConsoleLogger.php
│   └── FileLogger.php
├── Profiler/                         # -> See Profiler/README.md
│   ├── ProfilerInterface.php
│   ├── Profiler.php
│   ├── NullProfiler.php
│   ├── Span.php
│   └── Export/
├── DependencyInjection/
│   ├── ContainerFactory.php
│   └── CompilerPass/
│       ├── CollectorCompilerPass.php
│       ├── GlobalCollectorCompilerPass.php
│       ├── RuleCompilerPass.php
│       ├── RuleRegistryCompilerPass.php
│       ├── RuleOptionsCompilerPass.php
│       ├── FormatterCompilerPass.php
│       └── ConfigurationStageCompilerPass.php
├── Rule/
│   ├── RuleRegistryInterface.php
│   ├── RuleRegistry.php
│   └── Exception/
│       └── ConflictingCliAliasException.php
└── Console/                          # -> See Console/README.md
    ├── Application.php
    ├── CliOptionsParser.php
    ├── Progress/
    │   ├── ConsoleProgressBar.php
    │   ├── ProgressReporterHolder.php
    │   └── DelegatingProgressReporter.php
    └── Command/
        ├── AnalyzeCommand.php
        ├── BaselineCleanupCommand.php
        ├── GraphExportCommand.php
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
   AnalyzeCommand receives all dependencies via constructor
        |
   In execute():
   1. CLI parsing -> config + ruleOptions
   2. ConfigurationProvider.setConfiguration(config)
   3. RuleOptionsFactory.setCliOptions(...)
   4. Analyzer.analyze() -> Rules are created with correct options
```

### ContainerFactory

Creates a unified Symfony DI ContainerBuilder without parameters.

**Method:**
- `create(): ContainerBuilder` — returns a compiled container

**Runtime configuration:**
Configuration is set via mutable services AFTER container creation:
- `ConfigurationProviderInterface::setConfiguration()` — main configuration
- `RuleOptionsFactory::setCliOptions()` — rule options from CLI

**Tags:**
- `aimd.collector` — metric collectors
- `aimd.global_collector` — global context collectors
- `aimd.rule` — analysis rules (lazy)
- `aimd.formatter` — output formatters
- `aimd.configuration_stage` — configuration pipeline stages

### Lazy Services

Rules and their Options are made lazy via `->setLazy(true)`:
- Rules are not created during container compilation
- Rules are created on first use in RuleExecutor
- By that time RuleOptionsFactory is already configured with CLI options

### CompilerPass

**CollectorCompilerPass:**
- Collects services with tag `aimd.collector`
- Injects into `CompositeCollector`

**GlobalCollectorCompilerPass:**
- Collects services with tag `aimd.global_collector`
- Injects into `GlobalCollectorRunner`

**RuleOptionsCompilerPass:**
- Registers Options for each rule via `RuleOptionsFactory::create()`
- Injects Options into the rule constructor

**RuleCompilerPass:**
- Collects services with tag `aimd.rule`
- Injects into `RuleExecutor`

**RuleRegistryCompilerPass:**
- Collects rule classes (not instances)
- Injects into `RuleRegistry` for CLI option discovery

**FormatterCompilerPass:**
- Collects services with tag `aimd.formatter`
- Registers in `FormatterRegistry`

**ConfigurationStageCompilerPass:**
- Collects services with tag `aimd.configuration_stage`
- Injects into `ConfigurationPipeline` in priority order

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

### bin/aimd

**Algorithm:**
1. Finding autoloader
2. Creating unified DI container via `ContainerFactory::create()`
3. Getting `AnalyzeCommand` from container (all dependencies injected)
4. Running Application

**Runtime configuration:**
- CLI options are parsed in `AnalyzeCommand::execute()`
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
- `bin/aimd analyze src/` works
- Unified DI container assembles all dependencies
- Lazy Rules are created with correct runtime options
- FileParserFactory returns the correct implementation
- All CLI options work (including aliases --cc-warning, --cc-error)
- Exit codes are correct
- No ServiceLocator (all dependencies via constructor)
