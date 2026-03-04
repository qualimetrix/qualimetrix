# CLAUDE.md — Guide for AI Agents

**AI Mess Detector** — a CLI tool for static analysis of PHP code

---

## Language Policy

The repository language is **English**. All commits, documentation, code comments, docblocks, CLI output strings, and any other text must be written in English. Do not use any other language.

---

## Development Environment

The project is developed with the help of an AI agent in two environments:
- **Locally** — Claude Code CLI on macOS
- **Remotely** — [Claude Code on the Web](https://claude.ai/code) (Ubuntu)

When starting a session in the web environment, `scripts/init-environment.sh` is automatically executed (via the SessionStart hook), which installs the required dependencies and tools.

---

## Required Reading

**Before starting work:**
1. This file (CLAUDE.md) — working rules
2. [ARCHITECTURE.md](docs/ARCHITECTURE.md) — understanding the architecture
3. README.md in the corresponding `src/` directory for the current task

**Before implementing a component:**
- Read README.md in the corresponding `src/` directory
- Check the Definition of Done at the end of the document
- Study the related interfaces in `src/Core/README.md`

---

## Project Structure

```
src/
├── Core/                   # Contracts and primitives (README.md)
│   ├── Metric/             # MetricBag, MetricCollectorInterface, MetricDefinition
│   ├── Rule/               # RuleInterface, RuleCategory, AnalysisContext
│   ├── Symbol/             # SymbolType, MethodInfo, ClassInfo
│   ├── Violation/          # Violation, Severity, SymbolPath
│   │   └── Filter/         # BaselineFilter, ViolationFilterInterface
│   ├── Dependency/         # DependencyGraphInterface, Dependency, CycleInterface
│   ├── Progress/           # ProgressReporter, NullProgressReporter
│   ├── Util/               # StringSet, utilities
│   ├── Ast/                # FileParserInterface
│   ├── Namespace_/         # NamespaceDetectorInterface
│   └── Exception/
│
├── Metrics/                # Metric collectors (README.md)
│   ├── Complexity/         # CyclomaticComplexity, CognitiveComplexity, NpathComplexity
│   ├── Size/               # LocCollector, ClassCountCollector
│   ├── Coupling/           # CouplingCollector, AbstractnessCollector, DistanceCollector
│   ├── Structure/          # TccLcc, Rfc, Lcom, Noc, InheritanceDepth, MethodCount
│   ├── Halstead/           # HalsteadCollector
│   └── Maintainability/    # MaintainabilityIndexCollector
│
├── Rules/                  # Analysis rules (README.md)
│   ├── Complexity/         # ComplexityRule, CognitiveComplexityRule, NpathComplexityRule
│   ├── Size/               # SizeRule, PropertyCountRule
│   ├── Architecture/       # CircularDependencyRule
│   ├── Coupling/           # CouplingRule, DistanceRule
│   ├── Structure/          # LcomRule, NocRule, WmcRule, InheritanceRule
│   ├── Maintainability/    # MaintainabilityRule
│   └── Module/             # [PLANNED]
│
├── Baseline/               # Baseline Support
│   ├── Baseline.php        # Value object for baseline
│   ├── BaselineEntry.php   # Entry in baseline
│   ├── BaselineLoader.php  # Loading from JSON
│   ├── BaselineWriter.php  # Writing to JSON (atomic)
│   ├── BaselineGenerator.php  # Generation from violations
│   ├── ViolationHasher.php    # Stable hashes
│   └── Suppression/           # @aimd-ignore tags
│       ├── Suppression.php
│       ├── SuppressionExtractor.php
│       └── SuppressionFilter.php
│
├── Analysis/               # Orchestration (README.md)
│   ├── Pipeline/           # AnalysisPipeline, AnalysisResult
│   ├── Discovery/          # FileDiscoveryInterface
│   ├── Collection/         # Data collection phase
│   │   ├── FileProcessor, CollectionOrchestrator
│   │   ├── Metric/         # CompositeCollector, GlobalCollectorSorter
│   │   ├── Dependency/     # DependencyGraph, DependencyCollector, CircularDependencyDetector
│   │   │   └── Export/     # DotExporter for graph visualization
│   │   └── Strategy/       # ExecutionStrategy (Sequential, AmphpParallel), Serializer
│   ├── Aggregation/        # MetricAggregator, GlobalCollectorRunner
│   ├── RuleExecution/      # RuleExecutor
│   ├── Repository/         # InMemoryMetricRepository
│   └── Namespace_/         # PSR-4, Tokenizer detectors
│
├── Reporting/              # Output (README.md)
│   └── Formatter/          # Text, JSON, Checkstyle, SARIF, GitLabCodeQuality
│
├── Configuration/          # Configuration (README.md)
│   └── Loader/             # YamlConfigLoader
│
└── Infrastructure/         # CLI, DI, cache (README.md)
    ├── Ast/                # PhpFileParser, CachedFileParser, FileParserFactory
    ├── Cache/              # FileCache, CacheKeyGenerator
    ├── Collector/          # CachedCollector
    ├── Storage/            # SqliteStorage, InMemoryStorage, StorageFactory
    ├── Git/                # GitClient, GitScopeParser, GitFileDiscovery
    ├── Logging/            # ConsoleLogger, FileLogger, LoggerFactory
    ├── Rule/               # RuleRegistry
    ├── Profiler/           # ProfilerInterface, Profiler, NullProfiler, Span, Export
    ├── DependencyInjection/
    └── Console/            # AnalyzeCommand, BaselineCleanupCommand, Hook commands
        ├── Command/        # CLI commands
        └── Progress/       # ConsoleProgressBar, ProgressReporterHolder
```

---

## Key Features

### Metrics and Rules
- **Complexity**: Cyclomatic (CCN), Cognitive Complexity, NPATH Complexity
- **Maintainability**: Halstead, Maintainability Index
- **Coupling**: RFC (Response for Class), Distance from Main Sequence, Instability, Abstractness
- **Cohesion**: TCC/LCC (Tight/Loose Class Cohesion), LCOM4, WMC (Weighted Methods per Class)
- **Size**: LOC, Class Count, Namespace Size, Property Count, Method Count
- **Structure**: DIT (Depth of Inheritance Tree), NOC (Number of Children)
- **Architecture**: Circular Dependency Detection, Dependency Graph Export (DOT)

### Infrastructure
- **Parallel Processing**: Multi-worker file processing via amphp/parallel
- **Profiler**: Internal span-based profiler for performance diagnostics
- **Serialization**: Automatic selection of the best serializer (igbinary/PHP serialize)
- **Git Integration**: Analysis of changed files only, staged files
- **Baseline Support**: Ignoring known issues, @aimd-ignore tags
- **Multiple Formats**: Text, JSON, Checkstyle, SARIF, GitLab Code Quality
- **Caching**: AST caching for faster repeated runs
- **Progress Reporting**: Progress bar, PSR-3 logging
- **Git Hooks**: Automatic pre-commit checks

---

## Critical Rules

### 1. Dependency Graph (DO NOT VIOLATE!)

```
Infrastructure -> Analysis -> Metrics/Rules/Reporting/Configuration -> Core
```

- **Core** has no dependencies (only PHP + php-parser types)
- **Infrastructure** depends on all domains
- Dependencies flow DOWNWARD only

### 2. Stateless Rules, Stateful-per-file Collectors

```php
// Correct: Rule reads pre-computed metrics
public function analyze(AnalysisContext $context): array {
    foreach ($context->metrics->all(SymbolType::Method) as $method) {
        $ccn = $context->metrics->get($method->symbolPath);
    }
}

// Wrong: Rule performs AST traversal
public function analyze(AnalysisContext $context): array {
    $traverser = new NodeTraverser(); // WRONG!
}
```

### 3. Pipeline Phase Separation

```
Collection (parallel) -> Aggregation -> Analysis -> Reporting
     |                      |            |           |
  MetricBag[]        AggregatedMetrics  Violation[]  Output
```

- **Collection** — the only parallelizable phase (85-95% of total time)
- **Aggregation/Analysis/Reporting** — sequential, fast

### 4. SymbolPath for Identification

```php
// Use SymbolPath for violations and metrics
SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
SymbolPath::forClass('App\Service', 'UserService');
SymbolPath::forNamespace('App\Service');
SymbolPath::forFile('src/Service/UserService.php');

// Do not use string FQNs directly
$repository->forMethod('App\Service\UserService::calculate'); // OLD API
```

### 5. Atomic Cache Writes

```php
// Correct: atomic rename
$tmp = $path . '.tmp.' . getmypid();
file_put_contents($tmp, serialize($data));
rename($tmp, $path);

// Wrong: direct write (race condition)
file_put_contents($path, serialize($data));
```

### 6. Anonymous Classes — Ignore

```php
// In ClassCountCollector:
if ($node instanceof Class_ && $node->name !== null) {
    // named class — count it
}
// anonymous — skip
```

### 7. Symfony DI: Automatic Service Registration

Collectors, formatters, and configuration stages are registered **automatically** via `PhpFileLoader::registerClasses()`.
Standard Symfony practices are used: **autowiring** and **autoconfiguration**.

**How it works:**
1. `registerForAutoconfiguration()` defines tags for interfaces
2. `registerClasses()` scans directories and registers discovered classes
3. Prototype with `setAutoconfigured(true)->setAutowired(true)`:
   - **Autoconfigured** — automatic tagging of interface implementations
   - **Autowired** — automatic dependency resolution via type hints
4. CompilerPasses collect services by tags

**Adding a new collector:**
1. Create a class in `src/Metrics/{Category}/` (e.g., `src/Metrics/Complexity/`)
2. Implement `MetricCollectorInterface` (or `DerivedCollectorInterface`, `GlobalContextCollectorInterface`)
3. The class will be registered **automatically** — NO need to modify `ContainerFactory`

**Adding a new formatter:**
1. Create a `*Formatter.php` class in `src/Reporting/Formatter/`
2. Implement `FormatterInterface`
3. The class will be registered **automatically**

**Adding a new configuration stage:**
1. Create a class in `src/Configuration/Pipeline/Stage/`
2. Implement `ConfigurationStageInterface`
3. The class will be registered **automatically** and added to `ConfigurationPipeline`

**Adding a new rule:**
1. Create a `*Rule.php` class in `src/Rules/{Category}/` (e.g., `src/Rules/Complexity/`)
2. Implement `RuleInterface` (or extend `AbstractRule`)
3. Add a `NAME` constant with the rule slug (e.g., `'complexity'`)
4. Add a static `getOptionsClass()` method returning the Options class
5. Create an Options class in the same directory, implementing `RuleOptionsInterface`
6. The class will be registered **automatically** — NO need to modify `ContainerFactory`

**How rule registration works:**
1. `registerClasses()` scans `src/Rules/**/*Rule.php`
2. `registerForAutoconfiguration(RuleInterface::class)` adds the `aimd.rule` tag
3. `RuleOptionsCompilerPass` automatically registers Options via `RuleOptionsFactory::create()`
4. `RuleCompilerPass` collects all rules into `RuleExecutor`

**Important:** Rules do NOT use autowiring for the constructor (due to `RuleOptionsInterface`). The `$options` argument is injected via `RuleOptionsCompilerPass`.

**Important:** Collectors must be placed in subdirectories `src/Metrics/{Category}/`; files in the root of `src/Metrics/` (except base classes) are ignored.

**Exclude patterns (not registered as services):**
- `Abstract*.php` — abstract classes
- `*Interface.php` — interfaces
- `*Visitor.php` — AST visitors
- `*ClassData.php`, `*Metrics.php`, `*Calculator.php` — auxiliary VOs

**CompilerPasses collect services by tags:**
- `CollectorCompilerPass` -> `CompositeCollector`
- `GlobalCollectorCompilerPass` -> `GlobalCollectorRunner`
- `RuleOptionsCompilerPass` -> registers Options for rules
- `RuleCompilerPass` -> `RuleExecutor::$rules`
- `RuleRegistryCompilerPass` -> `RuleRegistry::$ruleClasses`
- `FormatterCompilerPass` -> `FormatterRegistry`
- `ConfigurationStageCompilerPass` -> `ConfigurationPipeline`

---

## Technology Stack

| Tool | Version | Purpose |
|------|---------|---------|
| PHP | ^8.4 | Runtime |
| nikic/php-parser | ^5.0 | AST parsing |
| amphp/parallel | ^2.0 | Parallel file processing |
| symfony/console | ^8.0 | CLI |
| symfony/dependency-injection | ^8.0 | DI container |
| symfony/yaml | ^8.0 | YAML configuration |
| symfony/finder | ^8.0 | File discovery |
| psr/log | ^3.0 | PSR-3 logging |
| PHPUnit | ^12.0 | Tests |
| PHPStan | ^2.0, level 8 | Static analysis |
| PHP-CS-Fixer | ^3.0 | Code style (PER-CS 2.0) |
| Deptrac | ^2.0 | Architecture layers |

## Essential Commands

```bash
# Project validation
composer check          # tests + phpstan + deptrac
composer test           # PHPUnit
composer phpstan        # PHPStan level 8

# Basic analysis
bin/aimd analyze src/
bin/aimd analyze src/ --format=json --workers=0

# Git integration
bin/aimd analyze src/ --staged
bin/aimd analyze src/ --diff=main

# Baseline
bin/aimd analyze src/ --baseline=baseline.json
bin/aimd analyze src/ --generate-baseline=baseline.json

# Hooks
bin/aimd hook:install
bin/aimd hook:status

# Full list of options
bin/aimd analyze --help
```

---

## Workflow

**Before implementation:** read README.md in the corresponding `src/` directory

**Work order:**
1. Implement the contract (interface)
2. Write unit tests
3. `composer check` — validation
4. Commit

**Commit format:** `<type>: short description` (`feat`, `fix`, `refactor`, `test`, `docs`, `chore`)

---

## Related Documents

### Component Documentation (in src/)
- [src/Core/README.md](src/Core/README.md) — contracts and primitives
- [src/Metrics/README.md](src/Metrics/README.md) — metric collectors
- [src/Rules/README.md](src/Rules/README.md) — analysis rules
- [src/Analysis/README.md](src/Analysis/README.md) — orchestration
- [src/Reporting/README.md](src/Reporting/README.md) — formatting
- [src/Configuration/README.md](src/Configuration/README.md) — configuration
- [src/Infrastructure/README.md](src/Infrastructure/README.md) — CLI, DI, caching

### General Documentation (in docs/)
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — overall architecture
- [docs/QUICK_START.md](docs/QUICK_START.md) — quick start
- [docs/GITHUB_ACTION.md](docs/GITHUB_ACTION.md) — GitHub Action integration
