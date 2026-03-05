# Configuration — Configuration System

## Overview

Configuration is responsible for managing analysis settings. It supports:
- **Configuration Pipeline** — extensible pipeline with priority-based stages
- Zero-config experience — auto-detection of paths from `composer.json`
- Typed options for each rule
- Config merging: defaults -> composer.json -> config file -> CLI options
- Extensible loading (YAML, PHP)

## Structure

```
Configuration/
├── AnalysisConfiguration.php      # General analysis config
├── PathsConfiguration.php         # VO for paths and excludes
├── ConfigurationHolder.php        # Runtime configuration holder
├── RuleOptionsFactory.php         # Factory for creating rule options
├── RuleOptionsParser.php          # CLI options parser for rules
│
├── Pipeline/                      # Configuration Pipeline (RFC-002)
│   ├── ConfigurationPipelineInterface.php  # Pipeline contract
│   ├── ConfigurationPipeline.php           # Implementation
│   ├── ConfigurationContext.php            # Context (input + workDir)
│   ├── ConfigurationLayer.php              # Configuration layer
│   ├── ResolvedConfiguration.php           # Final configuration
│   └── Stage/
│       ├── ConfigurationStageInterface.php # Stage contract
│       ├── DefaultsStage.php               # Priority 0: defaults
│       ├── ComposerDiscoveryStage.php      # Priority 10: composer.json
│       ├── ConfigFileStage.php             # Priority 20: aimd.yaml
│       └── CliStage.php                    # Priority 30: CLI options
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

The pipeline provides a **zero-config experience** — `bin/aimd analyze` works without arguments,
automatically detecting paths from `composer.json`.

### Architecture

```
+-------------------------------------------------------------+
|                 ConfigurationPipeline                        |
+-------------------------------------------------------------+
|  +-----------+  +-------------+  +------------+  +-------+  |
|  | Defaults  |->|  Composer   |->| ConfigFile |->|  CLI  |  |
|  | (pri: 0)  |  |  (pri: 10)  |  | (pri: 20)  |  |(pri:30)| |
|  +-----------+  +-------------+  +------------+  +-------+  |
|                                                              |
|  ConfigurationContext -> [Layers] -> ResolvedConfiguration   |
+-------------------------------------------------------------+
```

### Stages

| Stage | Priority | Source | Description |
|-------|----------|--------|-------------|
| `DefaultsStage` | 0 | hardcoded | Defaults: `paths=['.']`, `excludes=['vendor','node_modules','.git']` |
| `ComposerDiscoveryStage` | 10 | composer.json | Extracts PSR-4 autoload paths |
| `ConfigFileStage` | 20 | aimd.yaml | Loads config file |
| `CliStage` | 30 | CLI | Parses `--exclude`, `--format`, `--cache-*`, paths argument |

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
// In AnalyzeCommand
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
        $paths = getenv('AIMD_PATHS');
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
Config file            # aimd.yaml
     |
Defaults               # Default values in *Options classes
```

---

## Contracts

### AnalysisConfiguration

General analysis settings (not related to rules).

**Fields:**
- `cacheDir: string` — cache directory (default: `.aimd-cache`)
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
- Normalizes snake_case -> camelCase

### RuleOptionsFactory

Creates rule options with priority handling.

**Methods:**
- `create(string $ruleName, string $optionsClass): RuleOptionsInterface`
- `setConfigFileOptions(array $options): void`
- `addCliOption(string $ruleName, string $option, mixed $value): void`

**Algorithm of create():**
1. Getting defaults from constructor via Reflection
2. Merge with config file options
3. Merge with CLI options
4. Creating instance via named arguments

---

## Config File Format

### aimd.yaml

```yaml
# Rule settings
rules:
  complexity:
    enabled: true
    method:
      warning: 10
      error: 20
    class:
      max_warning: 50
      max_error: 100

  size:
    enabled: true
    namespace:
      warning: 10
      error: 15
      count_interfaces: true
      count_traits: true
      count_enums: true
    class:
      warning: 200
      error: 400

  maintainability:
    warning: 50
    error: 25

  lcom:
    warning: 2
    error: 3

# Caching
cache:
  enabled: true
  dir: .aimd-cache

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
```

### Minimal Config

```yaml
rules:
  complexity:
    method:
      warning: 15
```

### Multiple Config Files

All files from the `config/` directory are merged:
```
config/
├── aimd.yaml           # Base
├── aimd.local.yaml     # Local overrides (in .gitignore)
└── aimd.ci.yaml        # CI-specific
```

Order: base < local < ci (alphabetical or explicit priority).

---

## CLI Options

### Short Aliases

| Option | Rule | Field |
|--------|------|-------|
| `--cc-warning=N` | complexity | method.warning |
| `--cc-error=N` | complexity | method.error |
| `--cc-class-warning=N` | complexity | class.max_warning |
| `--cc-class-error=N` | complexity | class.max_error |
| `--cognitive-warning=N` | cognitive | method.warning |
| `--cognitive-error=N` | cognitive | method.error |
| `--cognitive-class-warning=N` | cognitive | class.max_warning |
| `--cognitive-class-error=N` | cognitive | class.max_error |
| `--npath-warning=N` | complexity.npath | method.warning |
| `--npath-error=N` | complexity.npath | method.error |
| `--npath-class-warning=N` | complexity.npath | class.max_warning |
| `--npath-class-error=N` | complexity.npath | class.max_error |
| `--size-class-warning=N` | size | class.warning |
| `--size-class-error=N` | size | class.error |
| `--ns-warning=N` | size | namespace.warning |
| `--ns-error=N` | size | namespace.error |
| `--mi-warning=N` | maintainability | warning |
| `--mi-error=N` | maintainability | error |
| `--lcom-warning=N` | lcom | warning |
| `--lcom-error=N` | lcom | error |
| `--wmc-warning=N` | wmc | warning |
| `--wmc-error=N` | wmc | error |
| `--dit-warning=N` | inheritance | warning |
| `--dit-error=N` | inheritance | error |
| `--noc-warning=N` | noc | warning |
| `--noc-error=N` | noc | error |
| `--distance-warning=N` | distance | max_distance_warning |
| `--distance-error=N` | distance | max_distance_error |
| `--coupling-class-warning=N` | coupling | class.max_instability_warning |
| `--coupling-class-error=N` | coupling | class.max_instability_error |
| `--coupling-ns-warning=N` | coupling | namespace.max_instability_warning |
| `--coupling-ns-error=N` | coupling | namespace.max_instability_error |
| `--cbo-class-warning=N` | coupling | class.cbo_warning_threshold |
| `--cbo-class-error=N` | coupling | class.cbo_error_threshold |
| `--cbo-ns-warning=N` | coupling | namespace.cbo_warning_threshold |
| `--cbo-ns-error=N` | coupling | namespace.cbo_error_threshold |
| `--no-circular-deps` | circular-dependency | enabled |
| `--max-cycle-size=N` | circular-dependency | maxCycleSize |

### Unified Format

```bash
--rule-opt=RULE:OPTION=VALUE
```

Examples:
```bash
--rule-opt=complexity:method.warning=15
--rule-opt=size:namespace.count_interfaces=false
--rule-opt=lcom:minMethods=3
```

### Rule Management

| Option | Description |
|--------|-------------|
| `--disable-rule=RULE` | Disable a rule or category |
| `--only-rule=RULE` | Run only the specified rule or category |
| `--config=PATH` | Path to config file |

#### Category Filtering

Use `category:<slug>` to target entire rule categories in `--disable-rule`, `--only-rule`,
`disabled_rules`, and `only_rules`:

```bash
bin/aimd analyze src/ --disable-rule=category:code-smell
bin/aimd analyze src/ --only-rule=category:complexity
```

```yaml
disabled_rules:
  - category:code-smell    # Disable all code smell rules at once
  - complexity.class       # Still works: disable a specific rule level

only_rules:
  - category:complexity    # Run only complexity rules
```

Available categories: `complexity`, `size`, `design`, `naming`, `maintainability`,
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

Loading from `aimd.php` with IDE autocompletion:

```php
return [
    'rules' => [
        'complexity' => [
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
8. [x] AnalyzeCommand integration
9. [x] Unit tests
10. [x] Configuration Pipeline (RFC-002)
    - [x] PathsConfiguration VO
    - [x] ConfigurationContext, ConfigurationLayer, ResolvedConfiguration
    - [x] ConfigurationStageInterface, ConfigurationPipelineInterface
    - [x] DefaultsStage (priority: 0)
    - [x] ComposerDiscoveryStage (priority: 10)
    - [x] ConfigFileStage (priority: 20)
    - [x] CliStage (priority: 30)
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
- [x] **Zero-config**: `bin/aimd analyze` works without arguments
- [x] **Auto-discovery**: paths from composer.json PSR-4 autoload
- [x] **Extensible**: new stages are automatically registered via DI
