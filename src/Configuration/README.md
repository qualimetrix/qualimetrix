# Configuration — Configuration System

## Overview

Configuration is responsible for managing analysis settings. It supports:
- **Configuration Pipeline** — extensible pipeline with priority-based stages
- **Analysis Presets** — built-in named configurations (`--preset=strict,ci`)
- Zero-config experience — auto-detection of paths from `composer.json`
- Typed options for each rule
- Config merging: defaults -> composer.json -> presets -> config file -> CLI options
- Extensible loading (YAML, PHP)

## Structure

```
Configuration/
├── AnalysisConfiguration.php      # General analysis config
├── PathsConfiguration.php         # VO for paths and excludes
├── ConfigurationHolder.php        # Runtime configuration holder
├── RuleOptionsFactory.php         # Factory for creating rule options
├── RuleOptionsRegistry.php        # Mutable storage for rule options (config file, CLI)
├── RuleNamespaceExclusionProvider.php  # Per-rule namespace exclusion storage
├── RuleOptionsParser.php          # CLI options parser for rules
├── RuleOptionsParserFactory.php   # Factory for creating RuleOptionsParser with CLI aliases
├── ConfigurationProviderInterface.php  # Interface for runtime config access
├── ComputedMetricsConfigResolver.php  # Merges defaults with YAML overrides, validates formulas
├── ComputedMetricFormulaValidator.php # Validates formula syntax, coverage, circular deps, references
├── HealthFormulaExcluder.php      # Filters excluded health dimensions, rebuilds health.overall formula
│
├── Pipeline/                      # Configuration Pipeline (RFC-002)
│   ├── ConfigurationPipelineInterface.php  # Pipeline contract
│   ├── ConfigurationPipeline.php           # Implementation
│   ├── ConfigurationContext.php            # Context (input + workDir)
│   ├── ConfigurationLayer.php              # Configuration layer
│   ├── ConfigDataNormalizer.php            # YAML → dot-notation normalizer
│   ├── ConfigurationMerger.php            # Layered config merge logic
│   ├── RuleNameValidator.php              # Unknown rule name warnings
│   ├── ResolvedConfiguration.php           # Final configuration
│   └── Stage/
│       ├── ConfigurationStageInterface.php # Stage contract
│       ├── DefaultsStage.php               # Priority 0: defaults
│       ├── ComposerDiscoveryStage.php      # Priority 10: composer.json
│       ├── PresetStage.php                 # Priority 15: --preset
│       ├── ConfigFileStage.php             # Priority 20: qmx.yaml
│       └── CliStage.php                    # Priority 30: CLI options
│
├── Preset/                       # Analysis Presets
│   ├── PresetResolver.php        # Resolves preset name → file path
│   ├── strict.yaml               # Greenfield: tight thresholds
│   ├── legacy.yaml               # Legacy: relaxed thresholds
│   └── ci.yaml                   # CI: error-only exit codes
│
├── Discovery/
│   └── ComposerReader.php         # PSR-4 path extraction
│
├── Loader/
│   ├── ConfigLoaderInterface.php  # Loader contract
│   └── YamlConfigLoader.php       # YAML loader
│
└── Exception/
    └── ConfigLoadException.php    # Loading exception
```

---

## Configuration Pipeline (RFC-002)

The pipeline provides a **zero-config experience** — `bin/qmx check` works without arguments,
automatically detecting paths from `composer.json`.

### Architecture

```
+--------------------------------------------------------------------------+
|                        ConfigurationPipeline                             |
+--------------------------------------------------------------------------+
|  +---------+ +---------+ +--------+ +----------+ +-----+                 |
|  |Defaults |>|Composer |>| Preset |>|ConfigFile|>| CLI |                 |
|  |(pri: 0) | |(pri: 10)| |(pri:15)| |(pri: 20) | |(30) |                 |
|  +---------+ +---------+ +--------+ +----------+ +-----+                 |
|                                                                          |
|  ConfigurationContext -> [Layers] -> ResolvedConfiguration               |
+--------------------------------------------------------------------------+
```

### Stages

| Stage                    | Priority | Source        | Description                                                                   |
| ------------------------ | -------- | ------------- | ----------------------------------------------------------------------------- |
| `DefaultsStage`          | 0        | hardcoded     | Defaults: `paths=['.']`, `excludes=['vendor','node_modules','.git']`          |
| `ComposerDiscoveryStage` | 10       | composer.json | Extracts PSR-4 autoload paths                                                 |
| `PresetStage`            | 15       | `--preset`    | Named presets: `strict`, `legacy`, `ci` (or custom YAML files)                |
| `ConfigFileStage`        | 20       | qmx.yaml      | Loads config file                                                             |
| `CliStage`               | 30       | CLI           | Parses `--exclude`, `--exclude-path`, `--format`, `--cache-*`, paths argument |

### Layer Merging

Stages return a `ConfigurationLayer` with sparse values. The pipeline merges layers
from lowest to highest priority — higher priority overrides values.

```php
// Example: CLI override config file
// ConfigFileStage (20): ['format' => 'json']
// CliStage (30):        ['format' => 'sarif']
// Result:               ['format' => 'sarif'] (CLI wins)
```

### Usage

```php
// In CheckCommand
$context = new ConfigurationContext($input, getcwd());
$resolved = $this->pipeline->resolve($context);

// ResolvedConfiguration contains:
$resolved->paths;       // PathsConfiguration
$resolved->analysis;    // AnalysisConfiguration
$resolved->ruleOptions; // array<string, mixed>
```

### Extending the Pipeline

Adding a new stage:

1. Create a class in `src/Configuration/Pipeline/Stage/`
2. Implement `ConfigurationStageInterface`
3. Specify a unique `priority()` and `name()`
4. The stage will be automatically registered via DI autoconfiguration

```php
final readonly class EnvironmentStage implements ConfigurationStageInterface
{
    public function priority(): int { return 5; } // between Defaults and Composer

    public function name(): string { return 'environment'; }

    public function apply(ConfigurationContext $context): ?ConfigurationLayer
    {
        $paths = getenv('QMX_PATHS');
        if (!$paths) {
            return null; // skip this stage
        }
        return new ConfigurationLayer('env', ['paths' => explode(',', $paths)]);
    }
}
```

---

## Settings Priority

```
CLI options            # Highest priority
     |
Config file            # qmx.yaml
     |
Presets                # --preset=strict,ci
     |
Defaults               # Default values in *Options classes
```

---

## Contracts

### AnalysisConfiguration

General analysis settings (not related to rules).

**Fields:**
- `cacheDir: string` — cache directory (default: `.qmx-cache`)
- `cacheEnabled: bool` — whether caching is enabled (default: true)
- `format: string` — output format (default: `text`)
- `namespaceStrategy: string` — namespace detection strategy (`psr4`, `tokenizer`, `chain`)
- `composerJsonPath: ?string` — path to composer.json for PSR-4

### RuleOptionsInterface

Rule options contract.

**Methods:**
- `isEnabled(): bool`
- `getSeverity(int|float $value): ?Severity` — null if the value is within normal range

**Static:**
- `fromArray(array $config): self` — creates an instance from a configuration array

### ConfigLoaderInterface

**Methods:**
- `load(string $path): array` — loads configuration
- `supports(string $path): bool` — whether the format is supported

### YamlConfigLoader

Implementation for `.yaml`/`.yml` files.

**Behavior:**
- Parses via Symfony Yaml
- Normalizes snake_case -> camelCase for option names within rule configuration

**Note:** Rule identifiers (keys under the `rules:` section) are preserved as-is and not normalized. Only option names within rule configuration are normalized.

### RuleOptionsFactory

Creates rule options with priority handling.

**Methods:**
- `create(string $ruleName, string $optionsClass): RuleOptionsInterface`
- `setConfigFileOptions(array $options): void`
- `addCliOption(string $ruleName, string $option, mixed $value): void`
- `getExclusionProvider(): RuleNamespaceExclusionProvider`

**Algorithm of create():**
1. Getting defaults from constructor via Reflection
2. Merge with config file options
3. Merge with CLI options
4. Extract `exclude_namespaces` → `RuleNamespaceExclusionProvider` (framework-level filtering)
5. Creating instance via named arguments

### RuleNamespaceExclusionProvider

Stores per-rule namespace exclusions extracted by `RuleOptionsFactory::create()`.
Consumed by `RuleExecutor` to filter violations at framework level.

**Methods:**
- `setExclusions(string $ruleName, list<string> $prefixes): void`
- `isExcluded(string $ruleName, string $namespace): bool` — prefix matching
- `getExclusions(string $ruleName): list<string>`
- `reset(): void`

---

## Config File Format

### qmx.yaml

```yaml
# Exclude paths from violations (glob patterns, fnmatch syntax)
# Note: files are still analyzed and metrics are collected,
# but violations for matching files are suppressed.
# Namespace-level/aggregated violations are not affected (they have no specific file).
exclude_paths:
  - src/Entity/*
  - src/DTO/*

# Rule settings
rules:
  complexity.cyclomatic:
    enabled: true
    method:
      warning: 10
      error: 20
    class:
      max_warning: 50
      max_error: 100

  size.method-count:
    enabled: true
    warning: 15
    error: 25

  size.class-count:
    enabled: true
    warning: 10
    error: 15

  maintainability.index:
    warning: 50
    error: 25

  design.lcom:
    warning: 2
    error: 3

# Coupling settings
coupling:
  # Framework namespace prefixes for CBO_APP/CE_FRAMEWORK metrics
  # Boundary-aware: 'Psr' matches 'Psr\Log' but NOT 'PsrExtended\Custom'
  framework-namespaces:
    - Symfony
    - PhpParser
    - Psr

# Caching
cache:
  enabled: true
  dir: .qmx-cache

# Output format
format: text

# Namespace detection
namespace:
  strategy: psr4
  composer_json: composer.json

# Aggregation
aggregation:
  prefixes:
    - App\Domain
    - App\Infrastructure
  auto_depth: 2

# Computed metrics (health scores)
computed_metrics:
  # Override default health score thresholds (graduated)
  health.complexity:
    warning: 60
    error: 30
  # Or use threshold shorthand (sets both warning=error=threshold)
  health.cohesion:
    threshold: 40
  # Disable a health score
  health.typing:
    enabled: false
  # Define a custom metric (computed.* prefix)
  computed.risk_score:
    formula: "ccn__avg * (1 - (tcc__avg ?? 0))"
    levels: [namespace]
    warning: 30
    error: 60
    description: "Risk score combining complexity and cohesion"
```

### Minimal Config

```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 15
```

### Multiple Config Files

All files from the `config/` directory are merged:
```
config/
├── qmx.yaml           # Base
├── qmx.local.yaml     # Local overrides (in .gitignore)
└── qmx.ci.yaml        # CI-specific
```

Order: base < local < ci (alphabetical or explicit priority).

### ComputedMetricsConfigResolver

Merges default computed metric definitions with user overrides from the `computed_metrics` YAML section. Validates the result: formula syntax (via ExpressionLanguage), formula coverage (each level has a formula), circular dependencies between computed metrics, and inter-metric references.

**Methods:**
- `resolve(array $rawConfig): list<ComputedMetricDefinition>` — merges defaults with user config, validates, returns resolved definitions

**User config options per metric:**
- `formula: string` — shorthand formula applied to all levels
- `formulas: array<string, string>` — per-level formulas (`class`, `namespace`, `project`)
- `levels: list<string>` — levels to evaluate at (default: `[namespace, project]` for new metrics)
- `threshold: float|null` — sets both warning and error to the same value (cannot mix with `warning`/`error`)
- `warning: float|null` — warning threshold (graduated mode)
- `error: float|null` — error threshold (graduated mode)
- `inverted: bool` — whether higher values are better (default: false for new metrics)
- `enabled: false` — removes the metric entirely
- `description: string` — human-readable description

**Formula variable mapping:** Metric names use `__` as separator in formulas because ExpressionLanguage does not support `.` in identifiers. For example, `ccn.avg` becomes `ccn__avg`, `health.complexity` becomes `health__complexity`.

**Available formula functions:** `min`, `max`, `abs`, `sqrt`, `log`, `log10`, `clamp(value, min, max)`.

---

## Analysis Presets

Presets are named configurations that provide sensible defaults for common scenarios.
Multiple presets can be combined: `--preset=strict,ci`.

### Built-in Presets

| Preset   | Axis        | Description                                                        |
| -------- | ----------- | ------------------------------------------------------------------ |
| `strict` | Severity    | Greenfield projects: ~30-50% tighter thresholds, `failOn: warning` |
| `legacy` | Severity    | Legacy projects: ~2x relaxed thresholds, noisy rules disabled      |
| `ci`     | Environment | CI pipelines: `failOn: error`                                      |

### Usage

```bash
# Single preset
bin/qmx check src/ --preset=strict

# Multiple presets (merged left to right)
bin/qmx check src/ --preset=strict,ci

# Custom preset file
bin/qmx check src/ --preset=./team-preset.yaml

# Mix built-in and custom
bin/qmx check src/ --preset=legacy,./overrides.yaml
```

### Priority

Presets sit at priority 15 in the pipeline — they override defaults but are overridden
by `qmx.yaml` and CLI options. This means presets are "starting points" that users can customize.

### Custom Presets

Custom preset files use the same YAML format as `qmx.yaml`. Specify a file path
(relative to working directory or absolute) via `--preset=./path/to/preset.yaml`.

---

## CLI Options

### Short Aliases

| Option                                  | Rule                                 | Field                 |
| --------------------------------------- | ------------------------------------ | --------------------- |
| `--circular-deps`                       | architecture.circular-dependency     | enabled               |
| `--max-cycle-size=N`                    | architecture.circular-dependency     | maxCycleSize          |
| `--constructor-overinjection-warning=N` | code-smell.constructor-overinjection | warning               |
| `--constructor-overinjection-error=N`   | code-smell.constructor-overinjection | error                 |
| `--long-parameter-list-warning=N`       | code-smell.long-parameter-list       | warning               |
| `--long-parameter-list-error=N`         | code-smell.long-parameter-list       | error                 |
| `--long-parameter-list-vo-warning=N`    | code-smell.long-parameter-list       | vo-warning            |
| `--long-parameter-list-vo-error=N`      | code-smell.long-parameter-list       | vo-error              |
| `--unreachable-code-warning=N`          | code-smell.unreachable-code          | warning               |
| `--unreachable-code-error=N`            | code-smell.unreachable-code          | error                 |
| `--cognitive-warning=N`                 | complexity.cognitive                 | method.warning        |
| `--cognitive-error=N`                   | complexity.cognitive                 | method.error          |
| `--cognitive-class-warning=N`           | complexity.cognitive                 | class.max_warning     |
| `--cognitive-class-error=N`             | complexity.cognitive                 | class.max_error       |
| `--cyclomatic-warning=N`                | complexity.cyclomatic                | method.warning        |
| `--cyclomatic-error=N`                  | complexity.cyclomatic                | method.error          |
| `--cyclomatic-class-warning=N`          | complexity.cyclomatic                | class.max_warning     |
| `--cyclomatic-class-error=N`            | complexity.cyclomatic                | class.max_error       |
| `--npath-warning=N`                     | complexity.npath                     | method.warning        |
| `--npath-error=N`                       | complexity.npath                     | method.error          |
| `--npath-class-warning=N`               | complexity.npath                     | class.max_warning     |
| `--npath-class-error=N`                 | complexity.npath                     | class.max_error       |
| `--wmc-warning=N`                       | complexity.wmc                       | warning               |
| `--wmc-error=N`                         | complexity.wmc                       | error                 |
| `--wmc-exclude-data-classes=N`          | complexity.wmc                       | excludeDataClasses    |
| `--cbo-warning=N`                       | coupling.cbo                         | class.warning         |
| `--cbo-error=N`                         | coupling.cbo                         | class.error           |
| `--cbo-ns-warning=N`                    | coupling.cbo                         | namespace.warning     |
| `--cbo-ns-error=N`                      | coupling.cbo                         | namespace.error       |
| `--class-rank-warning=N`                | coupling.class-rank                  | warning               |
| `--class-rank-error=N`                  | coupling.class-rank                  | error                 |
| `--distance-warning=N`                  | coupling.distance                    | max_distance_warning  |
| `--distance-error=N`                    | coupling.distance                    | max_distance_error    |
| `--instability-class-warning=N`         | coupling.instability                 | class.max_warning     |
| `--instability-class-error=N`           | coupling.instability                 | class.max_error       |
| `--instability-ns-warning=N`            | coupling.instability                 | namespace.max_warning |
| `--instability-ns-error=N`              | coupling.instability                 | namespace.max_error   |
| `--data-class-woc-threshold=N`          | design.data-class                    | wocThreshold          |
| `--data-class-wmc-threshold=N`          | design.data-class                    | wmcThreshold          |
| `--data-class-min-methods=N`            | design.data-class                    | minMethods            |
| `--data-class-exclude-readonly=N`       | design.data-class                    | excludeReadonly       |
| `--data-class-exclude-promoted-only=N`  | design.data-class                    | excludePromotedOnly   |
| `--data-class-exclude-exceptions=N`     | design.data-class                    | excludeExceptions     |
| `--god-class-wmc-threshold=N`           | design.god-class                     | wmcThreshold          |
| `--god-class-lcom-threshold=N`          | design.god-class                     | lcomThreshold         |
| `--god-class-tcc-threshold=N`           | design.god-class                     | tccThreshold          |
| `--god-class-class-loc-threshold=N`     | design.god-class                     | classLocThreshold     |
| `--god-class-min-criteria=N`            | design.god-class                     | minCriteria           |
| `--god-class-min-methods=N`             | design.god-class                     | minMethods            |
| `--god-class-exclude-readonly=N`        | design.god-class                     | excludeReadonly       |
| `--dit-warning=N`                       | design.inheritance                   | warning               |
| `--dit-error=N`                         | design.inheritance                   | error                 |
| `--lcom-warning=N`                      | design.lcom                          | warning               |
| `--lcom-error=N`                        | design.lcom                          | error                 |
| `--lcom-exclude-readonly=N`             | design.lcom                          | excludeReadonly       |
| `--lcom-min-methods=N`                  | design.lcom                          | minMethods            |
| `--noc-warning=N`                       | design.noc                           | warning               |
| `--noc-error=N`                         | design.noc                           | error                 |
| `--type-coverage-param-warning=N`       | design.type-coverage                 | param_warning         |
| `--type-coverage-param-error=N`         | design.type-coverage                 | param_error           |
| `--type-coverage-return-warning=N`      | design.type-coverage                 | return_warning        |
| `--type-coverage-return-error=N`        | design.type-coverage                 | return_error          |
| `--type-coverage-property-warning=N`    | design.type-coverage                 | property_warning      |
| `--type-coverage-property-error=N`      | design.type-coverage                 | property_error        |
| `--mi-warning=N`                        | maintainability.index                | warning               |
| `--mi-error=N`                          | maintainability.index                | error                 |
| `--mi-exclude-tests=N`                  | maintainability.index                | excludeTests          |
| `--mi-min-loc=N`                        | maintainability.index                | minLoc                |
| `--class-count-warning=N`               | size.class-count                     | warning               |
| `--class-count-error=N`                 | size.class-count                     | error                 |
| `--method-count-warning=N`              | size.method-count                    | warning               |
| `--method-count-error=N`                | size.method-count                    | error                 |
| `--property-count-warning=N`            | size.property-count                  | warning               |
| `--property-count-error=N`              | size.property-count                  | error                 |
| `--property-exclude-readonly=N`         | size.property-count                  | excludeReadonly       |
| `--property-exclude-promoted-only=N`    | size.property-count                  | excludePromotedOnly   |

### Unified Format

```bash
--rule-opt=RULE:OPTION=VALUE
```

Examples:
```bash
--rule-opt=complexity.cyclomatic:method.warning=15
--rule-opt=size.class-count:count_interfaces=false
--rule-opt=design.lcom:minMethods=3
```

### Rule Management

| Option                   | Description                                                      |
| ------------------------ | ---------------------------------------------------------------- |
| `--disable-rule=RULE`    | Disable a rule or category                                       |
| `--only-rule=RULE`       | Run only the specified rule or category                          |
| `--exclude-path=PATTERN` | Suppress violations for files matching glob pattern (repeatable) |
| `--config=PATH`          | Path to config file                                              |

**`--exclude-path`** uses `fnmatch()` glob syntax (e.g., `src/Entity/*`, `*/DTO/*`).
CLI patterns are **merged** with `exclude_paths` from the config file, not overridden.
Note: excluded files are still analyzed and their metrics are collected — only violations are suppressed.
Namespace-level and aggregated violations are not affected, as they have no specific file path.

#### Prefix Matching

Rule names use `group.rule-name` format (kebab-case). The `--disable-rule` and `--only-rule`
options support prefix matching — specifying a group prefix targets all rules in that group:

```bash
bin/qmx check src/ --disable-rule=code-smell         # Disable all code-smell.* rules
bin/qmx check src/ --only-rule=complexity             # Run only complexity.* rules
bin/qmx check src/ --disable-rule=coupling.instability  # Disable a specific rule
```

```yaml
disabled_rules:
  - code-smell               # Disable all code-smell.* rules (prefix match)
  - complexity.cyclomatic    # Disable a specific rule

only_rules:
  - complexity               # Run only complexity.* rules
```

Available groups: `complexity`, `size`, `design`, `maintainability`,
`coupling`, `architecture`, `code-smell`.

---

## CliOptionsParser

CLI options parser for rules.

**Methods:**
- `parseRuleOptions(array $ruleOpts): array` — parses `--rule-opt`
- `parseShortOptions(?int $ccWarning, ...): array` — parses short aliases

**Normalization:**
- kebab-case -> camelCase for option names
- `true`/`false` -> bool
- Numbers -> int

---

## Extensions

### PhpConfigLoader

Loading from `qmx.php` with IDE autocompletion:

```php
return [
    'rules' => [
        'complexity.cyclomatic' => [
            'method' => ['warning' => 10],
        ],
    ],
];
```

### ConfigResolver

Automatic config file search in the current and parent directories.

### Hierarchical Settings

Setting structure:
- Full name: `category.subcategory.name` (e.g., `caching.enabled`)
- CLI key: auto-generated `--caching-enabled` or explicit alias

---

## Implementation Stages

### Steps

1. [x] RuleOptionsInterface
2. [x] AnalysisConfiguration
3. [x] ConfigLoaderInterface
4. [x] YamlConfigLoader
5. [x] RuleOptionsFactory
6. [x] CliOptionsParser
7. [x] DI container integration
8. [x] CheckCommand integration
9. [x] Unit tests
10. [x] Configuration Pipeline (RFC-002)
    - [x] PathsConfiguration VO
    - [x] ConfigurationContext, ConfigurationLayer, ResolvedConfiguration
    - [x] ConfigurationStageInterface, ConfigurationPipelineInterface
    - [x] DefaultsStage (priority: 0)
    - [x] ComposerDiscoveryStage (priority: 10)
    - [x] ConfigFileStage (priority: 20)
    - [x] CliStage (priority: 30)
    - [x] PresetStage (priority: 15)
    - [x] ConfigDataNormalizer (shared YAML normalization)
    - [x] ConfigurationPipeline
    - [x] ConfigurationStageCompilerPass
    - [x] Integration tests

### Definition of Done

- [x] RuleOptionsFactory correctly merges defaults + config + CLI
- [x] YamlConfigLoader loads and normalizes config
- [x] Short aliases work
- [x] `--rule-opt` works
- [x] `--disable-rule` disables a rule
- [x] `--config` loads the specified file
- [x] **Zero-config**: `bin/qmx check` works without arguments
- [x] **Auto-discovery**: paths from composer.json PSR-4 autoload
- [x] **Extensible**: new stages are automatically registered via DI
