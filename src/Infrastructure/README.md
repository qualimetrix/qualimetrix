# Infrastructure — CLI, DI, Parser and Caching

## Overview

Infrastructure contains external adapters and entry points:
- **Console**: CLI application on Symfony Console with progress reporting
- **DependencyInjection**: Unified Symfony DI container with lazy services
- **Ast**: PHP parser implementation with factory
- **Cache**: AST caching ([details](Cache/README.md))
- **Composer**: safe readers for the analysed project's install and external DIT ancestry
- **Git**: Git integration for analyzing staged/changed files ([details](Git/README.md))
- **Logging**: PSR-3 logging ([details](Logging/README.md))
- **Parallel**: Parallel processing strategies
- **Profiler**: Span-based performance profiler ([details](Profiler/README.md))

## Internal Dependency Layers

Infrastructure sub-packages are declared as `infrastructure-*` sub-layers in the project's
own `qmx.yaml` to prevent circular dependencies (see
[ADR 0014](../../docs/adr/0014-deptrac-retirement.md)):

- **Leaf** (no Infrastructure siblings): Cache, Logging, Profiler, Rule, Git, Composer
- **Mid** (depends on specific siblings): Ast -> Cache, Parallel -> Ast + Cache
- **Hub** (wide dependencies): Console -> Git, Rule, Cache, Logging, Profiler; DI -> all

## Structure

```
Infrastructure/
├── Ast/
├── Cache/
│   ├── Contract/
│   └── Root/
├── Composer/
│   └── Contract/
├── Console/
│   ├── Application/
│   ├── BaselineCommand/
│   ├── CacheLifecycle/
│   ├── Check/
│   ├── CheckReport/
│   ├── CheckScope/
│   ├── Command/
│   ├── Directives/
│   ├── GraphExport/
│   ├── Hook/
│   ├── Invocation/
│   ├── Profile/
│   ├── Progress/
│   ├── Refusal/
│   ├── RuleListing/
│   ├── RunSetup/
│   ├── RunTarget/
│   ├── RuntimePolicy/
│   └── Terminal/
├── DependencyInjection/
│   ├── CircularDependency/
│   ├── CodeSmell/
│   ├── Cohesion/
│   ├── CompilerPass/
│   ├── Complexity/
│   ├── ComputedMetrics/
│   ├── Configurator/
│   ├── ContainerFactory.php
│   ├── Coupling/
│   ├── Design/
│   ├── Duplication/
│   ├── Maintainability/
│   ├── MeasurementRepositoryFactory.php
│   ├── ProjectManifest/
│   ├── Registration/
│   ├── RuleCatalog/
│   ├── Security/
│   └── Size/
├── Git/
│   └── ChangeListing/
├── Logging/
│   └── Contract/
├── Parallel/
│   ├── Configuration/
│   ├── Contract/
│   ├── Runtime/
│   └── Strategy/
├── Profiler/
│   ├── Contract/
│   └── Export/
└── Rule/
    └── Contract/
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
- `MeasurementConfigurator` — repository, aggregation, and worker reconstruction
- `ParserConfigurator` — AST parser and caching
- `CollectorConfigurator` — collector compiler-pass and parallel-class composition; it does not scan capability implementations
- `RuleConfigurator` — rule registries, channels, selector, and compiler passes; it does not scan capability implementations
- `CodeSmellConfigurator`, `CohesionConfigurator`, `ComplexityConfigurator`,
  `DesignConfigurator`, `MaintainabilityConfigurator`, `SecurityConfigurator`,
  and `SizeConfigurator` — exact owned collector roots plus lazy, non-autowired
  rule roots
- `CohesionConfigurator` also registers the LCOM configuration resolver and
  store, with the tagged LCOM-configurable collectors
- `CouplingConfigurator` — the same exact collector/rule registration for
  Coupling, plus internal `CouplingAnalysis` state and the public
  `CouplingConfiguratorInterface` alias
- `ArchitectureConfigurator` — declared-layer policy contracts and rule
- `CircularDependencyConfigurator` — SCC evidence preparation and rule
- `DuplicationConfigurator` — internal Duplication detector/provider wiring and capability-owned rule registration; the detector is autoconfigured as a Run-owned FileSet participant
- `AnalysisConfigurator` — Run pipeline, discovery, collection, and strategies
- `OutputConfigurator` — formatters, GraphProjection, and exact composition for Reporting finding projection, Inline annotation suppression, and the Git query adapter

`Registration/EvidenceRegistration` supplies fresh loader and collector/rule
prototypes to Cohesion, Complexity, Coupling, Maintainability and Size. Each
configurator retains its literal exact resource roots and special bindings.

**Method:**
- `create(): ContainerBuilder` — runs all configurators and returns a compiled container

**Runtime configuration:**
Configuration is resolved after container creation to `ConfigurationDocument`.
`RuntimeConfigurator` resets Cache, Parallel, Finding, Cohesion LCOM, profiling,
and — through `ProgressConfigurator` — the progress reporter together with the
error stream's section list, before any resolver runs. Run, Finding, Cache, Parallel,
Reporting, and Console each resolve their own immutable values; stores are
replaced only after all resolution succeeds. This prevents a failed or prior run
from leaking values into the next one. There is no transitional provider and no
generic collector-runtime store: Cohesion owns the only collector-specific
configuration projection.

Rule selector validation runs before any store has accepted a value, so it asks
Infrastructure Rule's snapshot factory for a channel universe built over the
candidate computed-metric definitions rather than reading the committed one.
Console and Finding receive that universe through their own narrow contracts.

**Tags:**
- `qmx.collector` — metric collectors
- `qmx.global_collector` — global context collectors
- analysis rules — composed as Finding's private executable set
- `qmx.formatter` — output formatters
- `qmx.configuration_stage` — configuration pipeline stages
- `qmx.analysis.run.file_set_inspection_participant` — Run-owned whole-file-set participants

### Lazy Services

Executable rules are lazy services and are created on first use in
Finding's `RuleExecution`. Options are immutable values built during preflight
for every producer, not lazy services or raw CLI readers.

### CompilerPass

**CollectorCompilerPass:**
- Collects services with tag `qmx.collector`
- Injects into `CompositeCollector`

**GlobalCollectorCompilerPass:**
- Collects services with tag `qmx.global_collector`
- Injects into `GlobalCollectorRunner`

`FindingConfigurator` registers the private document-forms implementation and
its `RuleOptionDocumentFormsInterface` alias. CLI input adapters and validators
receive that contract through autowiring; inline compiler wiring and worker
bootstrap supply the same interpretation. `OutputConfigurator` composes one
`ChannelFileScope` from declared capability channels and supplies it to Reporting.
It also wires the held `BaselineDocumentReader` separately from semantic loading.

**RuleOptionsCompilerPass:**
- Prepares producer-specific options for Finding's private executable rules
- Keeps configured options independent when implementations share an immutable options value

The service identity contains both producer name and Options class. Rules may
share an immutable Options implementation, but their configured instances must
remain independent because configuration is keyed by producer rule name.

**RuleCompilerPass:**
- Composes Finding's private executable-rule set
- Adapters, including `RulesCommand`, consume `RuleExecutionInterface` metadata views
- `RulesCommand` asks `RuleOptionSurface` what each rule accepts at each depth,
  so the listing and the option refusal answer from one declaration; it
  additionally reads `RuleChannelRegistryInterface` and
  `ChannelDeclarationRegistryInterface` to print, under each producer, the
  catalog metrics its channels declare they judge — the rule ID and the metric
  key are separate names, and the listing is where the pair is readable

**RuleRegistryCompilerPass:**
- Collects `RuleDefinitionInterface` class strings for CLI option discovery
- Maintains the metadata boundary without exposing executable rule instances

**ChannelDeclarationCompilerPass:**
- Builds Finding's channel-declaration registry in one walk over the container's
  definitions, rules and configuration validators interleaved in registration order
- **Channel order is published, so it follows registration order, not producer kind.**
  A "did you mean" answer breaks ties between equidistant names by the order
  `ChannelUniverse::channels()` yields, so collecting one producer kind as a block
  would move the text of a finding. A capability configurator therefore registers its
  validator in the family's published position — Inline before its rule, Architecture
  after — and `ChannelOrderFixtureDriftTest` pins the whole order against a tracked
  fixture
- Preserves producer-to-channel topology and adds configured computed channels at run
  time for channel-aware `--only-rule` / `--disable-rule` selection
- Rejects a channel declared by more than one producer, a validator that names a
  producer no rule answers to or declares no channels at all, and a tagged service
  whose definition names no class
- Delegates single-source reach and judged-metric declaration checks to
  `JudgedMetricDeclarationGuard`, which is the only class on this side that reads the
  metric catalog: a declared judged key must exist in `MetricName` (an aggregate
  spelling counts) and only a `magnitude` producer may name one at all. Run-evidence reach
  and a judged metric cannot be declared together
- **The one place a channel becomes a configuration error.** It applies
  `ChannelDeclaration::asConfigurationError()` to everything a validator declares and
  to nothing else, registering it under the validator's producer rule name so
  selection, exclusion, description, docs page and remediation minutes all resolve as
  before. `ConfigurationErrorClassificationTopologyTest` counts the call sites

**ConfigurationValidatorCompilerPass:**
- Collects services tagged `qmx.configuration_validator` and hands them to
  `RuleExecution`, which runs each in its producer rule's slot
- Refuses a tagged service whose definition names no class, rather than skipping it:
  a dropped validator would neither run nor declare, and no second reader would notice
- Fills `ConfigurationValidatorRegistry` with their class strings — the second witness
  to "which producers are validators", read only by `ChannelUniverseCoverageTest`, so
  that the check does not read the same universe production reads

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

Each compiler pass has a dedicated unit test. Most live in
`tests/Infrastructure/DependencyInjection/Unit/CompilerPass/`, named after the pass; the channel-declaration pass
is tested with its subject in `tests/Analysis/Finding/Unit/`, and threshold
validator wiring by
`tests/Analysis/Policy/Inline/Integration/ThresholdValidatorWiringTest.php`.
The count is deliberately not repeated here — the previous wording said eleven
while `src/Infrastructure/DependencyInjection/CompilerPass/` held thirteen, and
named a directory that had not existed since the tests moved.

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

**Algorithm of `parseContent()`:**
1. Receive caller-owned bytes and the original absolute file identity.
2. Generate the key from those bytes with `generateForContent()`.
3. Cache hit -> return from cache.
4. Cache miss -> parse those bytes via `$inner`, preserving file identity for diagnostics, and save.

Source IO belongs to Run's private `SourceReader`. Parser/cache adapters neither
reopen source nor infer cwd. Generated-header inspection and Duplication retain
separate reads. See [Run](../Analysis/Run/README.md).

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
- A retired suppression flag (`--exclude-path`, `--exclude-namespace`) is
  refused in `CheckCommand::run()`, out of the binding failure Symfony raises
  for it — the flag is undeclared, so a retired token never reaches
  `execute()`, and only a token the parser itself could not bind is read as one
  (a *path* named `--exclude-path` is not). The refusal text is
  `Analysis\Configuration\RetiredSuppressionOptions`, shared with the
  config-file doors, and exits 3 like them. Scoped to this command so
  `graph:export --exclude-namespace`, which was never renamed, keeps working
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


## Atomic typed rule configuration

Infrastructure supplies one immutable channel universe over the candidate
computed-metric definitions, shared by document/selection/option preparation.
Every producer's options are built, even when inactive, before collection. The
completed `FindingConfiguration` carries document, options, enablement, universe
and diagnostics; runtime stores receive it only after all preflight succeeds.
A failed invocation cannot leave part of a candidate configuration committed.
Rules remain lazy executable services. Their Options are already immutable
prepared values, not lazy raw readers. No generic contribution adapter or second
constructor-reflected option catalogue participates in runtime execution.

## Report publication

`CheckReport/ReportAssembler` forwards the normal analysis absence summary to
`ReportBuilder`. For successful check with resolved format exactly `json` and
stdout destination, `Terminal/ReportPublication/ReportPublication` writes the complete raw
document at NORMAL and QUIET verbosity, preserving literal markup. SILENT emits
no bytes. Configuration-selected JSON follows the same route. File destinations
retain judged full publication, and other formats retain their ordinary output
behavior. Formula failures retain the effective-source refusal and exit 3.

Formatters return Reporting's `FormattedReport` (`body`, `escapedStrings`) and
state `PublicationKind`. `CheckReport/ReportFormatter` applies the invocation's prose mode
or preserves a structured body, and reports a positive repair count on the
single ErrorStream. Internal terminal failures use `ConsoleExitCode::InternalError`
(exit 5); typed input/environment refusals remain 3. Generated hooks distinguish
measured findings at 1/2 from refusal/incomplete/internal outcomes at 3/4/5.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.

Measurement repository composition resolves the measured and computed definition
catalogs for every fresh store after invocation configuration. Class key scopes
are assembled here; Measurement itself does not import ComputedMetrics. The
container's direct repository service uses the same factory.
