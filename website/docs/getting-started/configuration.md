# Configuration

Qualimetrix works out of the box with sensible defaults. A configuration file lets you customize thresholds, disable rules, and exclude paths to fit your project.

---

## Configuration File

Create a file named `qmx.yaml` in your project root. Qualimetrix automatically looks for this file.

You can also specify a file explicitly:

```bash
vendor/bin/qmx check src/ --config=my-config.yaml
```

---

## Configuration Sections

### Paths

Directories to analyze:

```yaml
paths:
  - src/
```

!!! note
    If you pass paths as CLI arguments (e.g., `vendor/bin/qmx check src/ lib/`), they take precedence over the config file.

### Exclude

Directories to skip entirely. Files in these directories are not analyzed at all:

```yaml
exclude:
  - vendor/
  - tests/Fixtures/
```

### Include Generated

By default, files with a `@generated` annotation in the first 2 KB are automatically skipped from analysis. To include them:

```yaml
include_generated: true
```

Equivalent CLI: `--include-generated`

### Exclude Paths

Path patterns for suppressing violations. Unlike `exclude`, these files **are still analyzed** (their metrics are collected), but violations are not reported. Supports both directory prefixes and glob patterns:

```yaml
exclude_paths:
  - src/Entity                # prefix: matches all files under src/Entity/
  - src/Metrics/*Visitor.php  # glob: matches visitor files only
```

### Exclude Namespaces

Suppress violations for classes in specific namespaces (prefix matching). Like `exclude_paths`, files are still analyzed and metrics are collected, but violations are not reported. This applies to all rules globally:

```yaml
exclude_namespaces:
  - App\Tests
  - App\Generated
```

This is useful when entire namespace subtrees should never produce violations. For per-rule exclusions, use `exclude_namespaces` inside a rule configuration instead (see below).

Also available as a CLI option: `--exclude-namespace` (merged with YAML config).

### Rules

Control which rules are active and set custom thresholds.

**Disable a rule entirely:**

```yaml
rules:
  code-smell.boolean-argument:
    enabled: false
```

**Override thresholds:**

Each rule defines severity levels. When a metric exceeds a threshold, a violation is reported at that severity. For example, the cyclomatic complexity rule has thresholds for methods:

```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 15
      error: 25
```

This means: report a **warning** when a method's cyclomatic complexity reaches 15, and an **error** when it reaches 25.

**Threshold shorthand:**

If you want a single pass/fail threshold (all violations become errors), use the `threshold` key:

```yaml
rules:
  complexity.cyclomatic:
    method:
      threshold: 15    # warning=15, error=15 → all violations are errors

  size.method-count:
    threshold: 25      # same as warning: 25, error: 25
```

This is useful in CI where you want a simple pass/fail cutoff without graduated warnings. You cannot mix `threshold` with explicit `warning`/`error` keys in the same rule level.

For type coverage, dedicated shorthand keys are available:

```yaml
rules:
  design.type-coverage:
    param_threshold: 90
    return_threshold: 90
    property_threshold: 80
```

**Exclude namespaces from a rule:**

Any rule can exclude specific namespaces using prefix matching. Violations from matching namespaces are suppressed:

```yaml
rules:
  complexity.cyclomatic:
    exclude_namespaces:
      - App\Tests
      - App\Legacy
    method:
      warning: 15
      error: 25

  coupling.cbo:
    exclude_namespaces:
      - App\Tests
    exclude_paths:
      - src/Infrastructure/DependencyInjection
```

This is useful when certain namespaces (e.g., tests, generated code, legacy modules) should not trigger violations for a specific rule, while still being analyzed for metrics.

**Exclude paths from a rule:**

Any rule can exclude specific file paths using prefix or glob matching. Violations from matching files are suppressed:

```yaml
rules:
  coupling.cbo:
    exclude_paths:
      - src/Metrics                # prefix: all files in src/Metrics/
      - src/Metrics/*Visitor.php   # glob: only visitor files
```

This works alongside `exclude_namespaces` -- both filters are applied. Unlike the global `exclude_paths`, per-rule `exclude_paths` only affects the specific rule, not all rules.

**Per-symbol threshold overrides with `@qmx-threshold`:**

In addition to project-wide thresholds in YAML, you can override thresholds for individual classes or methods using `@qmx-threshold` annotations directly in source code:

```php
/**
 * @qmx-threshold complexity.cyclomatic method.warning=20 method.error=40
 */
class ComplexStateMachine
{
    // Methods in this class use higher complexity thresholds
}
```

See [Baseline > @qmx-threshold](../usage/baseline.md#per-symbol-threshold-overrides-with-qmx-threshold) for full syntax and examples.

### Disabled Rules

Disable specific rules or entire groups:

```yaml
disabled_rules:
  - code-smell.boolean-argument
  - duplication
```

Equivalent CLI: `--disable-rule=code-smell.boolean-argument --disable-rule=duplication`

### Only Rules

Run only specified rules (everything else is disabled):

```yaml
only_rules:
  - complexity.cyclomatic
  - complexity.cognitive
```

Equivalent CLI: `--only-rule=complexity.cyclomatic --only-rule=complexity.cognitive`

### Fail On

Control which severity levels cause a non-zero exit code:

```yaml
fail_on: error    # Only fail on errors (default)
# fail_on: warning  # Fail on warnings too
# fail_on: none     # Never fail on violations
```

The default is `error`: warnings are shown in the output but do not cause a non-zero exit code. Use `fail_on: warning` if you want warnings to also fail the build.

### Exclude Health

Exclude specific health dimensions from scoring. The excluded dimensions are not shown in the health summary and do not contribute to the overall score:

```yaml
exclude_health:
  - typing
  - maintainability
```

Equivalent CLI: `--exclude-health=typing --exclude-health=maintainability`

### Memory limit

Set the PHP memory limit for analysis. By default, PHP's `memory_limit` from `php.ini` is used.

```yaml
memory_limit: 1G    # 1 gigabyte
# memory_limit: -1  # Unlimited
```

Equivalent CLI: `--memory-limit=1G`

### Format

Set the default output format:

```yaml
format: summary   # Default
# format: json
# format: html
```

### Cache

Control AST caching for faster repeated runs:

```yaml
cache:
  enabled: true         # Default: true
  dir: .qmx-cache       # Default: .qmx-cache
```

Equivalent CLI: `--no-cache` to disable, `--cache-dir=DIR` to change directory.

### Parallel Processing

Control the number of parallel workers for file analysis:

```yaml
parallel:
  workers: 4     # Fixed number of workers
  # workers: 0   # Disable parallelism (single-threaded)
```

By default, Qualimetrix auto-detects the optimal worker count based on CPU cores. Equivalent CLI: `--workers=4`

!!! tip
    Use `workers: 0` for debugging or when running in environments without `ext-parallel`.

### Namespace Detection

Control how Qualimetrix resolves namespace-to-directory mapping:

```yaml
namespace:
  strategy: chain          # Default: chain (try psr4, then tokenizer)
  # strategy: psr4         # PSR-4 only (requires composer.json)
  # strategy: tokenizer    # Parse namespace from PHP tokens
  composer_json: composer.json   # Path to composer.json for PSR-4 detection
```

### Coupling

Configure framework namespace prefixes for the CBO (Coupling Between Objects) metric. Dependencies on framework namespaces are tracked separately as `cbo_app` and `ce_framework`:

```yaml
coupling:
  framework-namespaces:
    - Symfony
    - Doctrine
    - Psr
    - Illuminate
```

When no `framework-namespaces` are configured, `cbo_app` equals `cbo` (no effect).

### Aggregation

Control how namespaces are grouped for aggregated metrics:

```yaml
aggregation:
  prefixes:
    - App\Domain
    - App\Infrastructure
  auto_depth: 2    # Auto-detect depth for namespace grouping
```

---

## Presets

<!-- llms:skip-begin -->
Presets are named configuration bundles that apply predefined settings — thresholds, disabled rules, fail behavior — in a single flag. Instead of manually tuning dozens of options, pick a preset that matches your project's maturity.
<!-- llms:skip-end -->

| Preset   | Description                                                       |
| -------- | ----------------------------------------------------------------- |
| `strict` | Tight thresholds for greenfield projects. Sets `fail_on: warning` |
| `legacy` | Relaxed thresholds for legacy codebases. Disables noisy rules     |
| `ci`     | Explicit CI mode. Sets `fail_on: error`                           |

```bash
# Use a single preset
vendor/bin/qmx check src/ --preset=strict

# Combine multiple presets
vendor/bin/qmx check src/ --preset=strict,ci

# Use a custom preset file
vendor/bin/qmx check src/ --preset=./my-preset.yaml
```

<!-- llms:skip-begin -->
**Priority order:** Presets are applied after `composer.json` discovery but before `qmx.yaml`. Your config file always overrides preset values.

**Multiple presets:** When combining presets, they are merged left-to-right — later presets override earlier ones, except list keys like `disabled_rules` which accumulate. For example, `--preset=legacy,ci` gives you legacy thresholds with CI fail behavior.

!!! warning
    `only_rules` is **not** accumulated across presets — the last preset's `only_rules` completely replaces any earlier one. This is intentional: `only_rules` is a restrictive filter, and union would widen the scope.

**Custom presets:** Any YAML file with the same structure as `qmx.yaml` can be used as a preset. Pass the file path instead of a built-in name.
<!-- llms:skip-end -->

---

## Full Example

```yaml
# Or start with a preset and customize:
# vendor/bin/qmx check src/ --preset=strict

paths:
  - src/

exclude:
  - vendor/
  - tests/Fixtures/

exclude_paths:
  - src/Entity
  - src/DTO

exclude_namespaces:
  - App\Tests

include_generated: false

format: summary
fail_on: error        # default — warnings shown but don't fail the build

cache:
  enabled: true
  dir: .qmx-cache

parallel:
  workers: 4

coupling:
  framework-namespaces:
    - Symfony
    - Doctrine

exclude_health:
  - typing

disabled_rules:
  - code-smell.boolean-argument
  - duplication

rules:
  complexity.cyclomatic:
    exclude_namespaces:
      - App\Tests
    exclude_paths:
      - src/Generated
    method:
      warning: 15
      error: 25

  size.method-count:
    warning: 25
    error: 40
```

---

## CLI Options Override Config

Command-line options always take precedence over values in the configuration file. For example:

```bash
# Config says paths: [src/], but CLI overrides it
vendor/bin/qmx check lib/

# Add extra exclude paths on top of config
vendor/bin/qmx check src/ --exclude-path='src/Generated/*'
```

This makes it easy to experiment without editing the config file.

---

## Configuration Validation

Qualimetrix validates your configuration file and reports clear errors for common mistakes.

### Unknown keys

Any unrecognized key — at the root level or inside a section — produces an error with a suggestion:

```
Invalid configuration in qmx.yaml:
  Unknown key "workes" in "parallel" section. Did you mean "workers"?
```

### Type errors

If a value has the wrong type, you'll get a clear message instead of silent fallback to defaults:

```
Invalid value for "cache.enabled": expected boolean, got string
```

### Unknown rule names

Misspelled rule names in the `rules:` section are rejected:

```
Unknown rule "complexty.cyclomatic" in qmx.yaml. Did you mean "complexity.cyclomatic"?
```

!!! tip
    Set a value to `~` (YAML null) or leave it empty to explicitly use the default — this is always valid.

---

## What's Next?

See the [CLI Options](../usage/cli-options.md) reference for the complete list of command-line options.
