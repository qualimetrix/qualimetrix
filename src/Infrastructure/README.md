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
│   ├── ReportingGitScopeQuery.php   # Git adapter for Reporting finding projection
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
│   ├── FileProcessingTaskFactory.php # Supplies each task with compile-time metadata and current runtime configuration
│   ├── WorkerBootstrap.php          # Reconstructs Measurement and DependencyModel worker-safe participants
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
│   │   ├── DependencyModelConfigurator.php
│   │   ├── ComputedMetricsConfigurator.php
│   │   ├── MeasurementConfigurator.php
│   │   ├── ParserConfigurator.php
│   │   ├── CollectorConfigurator.php      # Collector compiler/parallel composition
│   │   ├── RuleConfigurator.php           # Rule registries and selection composition
│   │   ├── CodeSmellConfigurator.php      # Exact CodeSmell collector/rule roots
│   │   ├── CohesionConfigurator.php       # Exact Cohesion collector/rule roots
│   │   ├── ComplexityConfigurator.php     # Exact Complexity collector/rule roots
│   │   ├── CouplingConfigurator.php       # Exact Coupling roots, config contract alias, state
│   │   ├── DesignConfigurator.php         # Exact Design collector/rule roots
│   │   ├── MaintainabilityConfigurator.php # Exact Maintainability collector/rule roots
│   │   ├── SecurityConfigurator.php       # Exact Security collector/rule roots
│   │   ├── SizeConfigurator.php           # Exact Size collector/rule roots
│   │   ├── ArchitectureConfigurator.php
│   │   ├── CircularDependencyConfigurator.php
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
│       ├── ParallelCollectorClassesCompilerPass.php
│       ├── FileSetInspectionParticipantCompilerPass.php
│       └── ThresholdValidatorMapCompilerPass.php
├── Rule/
│   ├── RuleRegistryInterface.php
│   ├── RuleRegistry.php
│   ├── ChannelDeclarationRegistry.php     # Implements Finding's channel-declaration registry contract
│   ├── RuleChannelRegistry.php             # Implements Finding's rule-channel registry contract
│   └── Exception/
│       └── ConflictingCliAliasException.php
└── Console/                          # -> See Console/README.md
    ├── Application.php
    ├── CliOptionsParser.php
    ├── OutputHelper.php               # Helper for large text output (line-by-line flush)
    ├── MeasuredViolationSet.php       # The one definition of the set a baseline measures: paths + resolved config in, findings at the baseline stage's input out (no InputInterface)
    ├── ViolationFilterOrchestrator.php # Adapts check options to the Reporting-owned FindingProjector and reports its stage results
    ├── RuntimeConfigurator.php        # Runtime DI configuration; applies the ConfigurationDocument to Coupling every run
    ├── RuntimeLoggerConfigurator.php  # Creates and publishes the logger for one console run
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
    │   └── SwitchableProgressReporter.php
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
   - owner-local runtime stores and sessions
   - Finding RuleConfigurationInterface read by rule construction
   - CacheFactory for lazy cache creation
        |
   CheckCommand receives all dependencies via constructor
        |
   In execute():
   1. Configuration resolution -> ConfigurationDocument
   2. RuntimeConfigurator resets owner-local state
   3. named owner resolvers compute and replace their values
   4. AnalysisPipeline analyzes RunConfiguration; lazy rules read Finding state
```

### ContainerFactory (Decomposed)

Creates a unified Symfony DI ContainerBuilder without parameters. Delegates configuration to specialized configurators implementing `ContainerConfiguratorInterface`:

- `CoreServicesConfigurator` — core services (logger, profiler, etc.)
- `ConfigurationConfigurator` — Analysis.Configuration pipeline and ordered document source seam
- `DependencyModelConfigurator` — graph/traversal contracts and extraction registration
- `ComputedMetricsConfigurator` — private root/Health implementation tree, capability-owned rule, and four public contract aliases
- `MeasurementConfigurator` — repository, aggregation, Cohesion LCOM configuration, and worker reconstruction
- `ParserConfigurator` — AST parser and caching
- `CollectorConfigurator` — collector compiler-pass and parallel-class composition; it does not scan capability implementations
- `RuleConfigurator` — rule registries, channels, selector, and compiler passes; it does not scan capability implementations
- `CodeSmellConfigurator`, `CohesionConfigurator`, `ComplexityConfigurator`,
  `DesignConfigurator`, `MaintainabilityConfigurator`, `SecurityConfigurator`,
  and `SizeConfigurator` — exact owned collector roots plus lazy, non-autowired
  rule roots
- `CouplingConfigurator` — the same exact collector/rule registration for
  Coupling, plus internal `CouplingAnalysis` state and the public
  `CouplingConfiguratorInterface` alias
- `ArchitectureConfigurator` — declared-layer policy contracts and rule
- `CircularDependencyConfigurator` — SCC evidence preparation and rule
- `DuplicationConfigurator` — internal Duplication detector/provider wiring and capability-owned rule registration; the detector is autoconfigured as a Run-owned FileSet participant
- `AnalysisConfigurator` — Run pipeline, discovery, collection, and strategies
- `OutputConfigurator` — formatters, GraphProjection, and exact composition for Reporting finding projection, Inline annotation suppression, and the Git query adapter

**Method:**
- `create(): ContainerBuilder` — runs all configurators and returns a compiled container

**Runtime configuration:**
Configuration is resolved after container creation to `ConfigurationDocument`.
`RuntimeConfigurator` resets Cache, Parallel, Finding, Cohesion LCOM, profiling,
and progress state before any resolver runs. Run, Finding, Cache, Parallel,
Reporting, and Console each resolve their own immutable values; stores are
replaced only after all resolution succeeds. This prevents a failed or prior run
from leaking values into the next one. There is no transitional provider and no
generic collector-runtime store: Cohesion owns the only collector-specific
configuration projection.

Rule selector validation uses an immutable `RuleChannelRegistryInterface`
snapshot assembled from the resolved computed-metric definitions. Infrastructure
Rule owns the snapshot factory; Console and Finding receive only the resulting
run snapshot through their named contracts.

**Tags:**
- `qmx.collector` — metric collectors
- `qmx.global_collector` — global context collectors
- analysis rules — composed as Finding's private executable set
- `qmx.formatter` — output formatters
- `qmx.configuration_stage` — configuration pipeline stages
- `qmx.analysis.run.file_set_inspection_participant` — Run-owned whole-file-set participants

### Lazy Services

Rules and their Options are made lazy via `->setLazy(true)`:
- Rules are not created during container compilation
- Rules are created on first use in Finding's `RuleExecution`
- By that time RuleOptionsFactory is already configured with CLI options

### CompilerPass

**CollectorCompilerPass:**
- Collects services with tag `qmx.collector`
- Injects into `CompositeCollector`

**GlobalCollectorCompilerPass:**
- Collects services with tag `qmx.global_collector`
- Injects into `GlobalCollectorRunner`

**RuleOptionsCompilerPass:**
- Prepares producer-specific options for Finding's private executable rules
- Keeps configured options independent when implementations share an immutable options value

The service identity contains both producer name and Options class. Rules may
share an immutable Options implementation, but their configured instances must
remain independent because configuration is keyed by producer rule name.

**RuleCompilerPass:**
- Composes Finding's private executable-rule set
- Adapters, including `RulesCommand`, consume `RuleExecutionInterface` metadata views

**RuleRegistryCompilerPass:**
- Collects `RuleDefinitionInterface` class strings for CLI option discovery
- Maintains the metadata boundary without exposing executable rule instances

**ChannelDeclarationCompilerPass:**
- Builds Finding's channel-declaration registry from the registered rule set
- Preserves producer-to-channel topology and adds configured computed channels at run
  time for channel-aware `--only-rule` / `--disable-rule` selection
- Rejects a channel declared by more than one rule class

**FormatterCompilerPass:**
- Collects services with tag `qmx.formatter`
- Registers in `FormatterRegistry`

**ConfigurationStageCompilerPass:**
- Collects services with tag `qmx.configuration_stage`
- Injects into `ConfigurationPipeline` in priority order

**ParallelCollectorClassesCompilerPass:**
- Collects base-collector, derived-collector, and rule class names
- Supplies the exact class lists used to reconstruct parallel workers

**FileSetInspectionParticipantCompilerPass:**
- Collects `FileSetInspectionParticipantInterface` implementations
- Fails the container build on duplicate participant ids
- Injects a deterministic participant list into Run's FileSet composite

**ThresholdValidatorMapCompilerPass:**
- Builds the rule-name-to-threshold-validator map from registered rule classes
- Injects it into `ThresholdOverrideExtractor` without instantiating rules

The 11 compiler passes are covered by dedicated unit tests under
`tests/Unit/Infrastructure/DependencyInjection/CompilerPass/` or, for threshold
validator wiring, by
`tests/Analysis/Policy/Inline/Integration/ThresholdValidatorWiringTest.php`.

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
- `CacheFactory $cacheFactory`
- `CacheKeyGenerator $keyGenerator`
- `CacheConfigurationStoreInterface $configurationStore`

**Method:**
- `create(): FileParserInterface` — returns `CachedFileParser` or `PhpFileParser` depending on the current Cache-owned configuration

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
- `RuntimeConfigurator` resolves the ordered document through exact owner
  resolvers, resets/replaces their local state, then passes RunConfiguration to
  the analysis pipeline
- `LayerAssignmentCommand` delegates collected-state reconstruction to the
  internal Console `LayerAssignmentResolver`
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


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
