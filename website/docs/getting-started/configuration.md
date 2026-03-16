# Configuration

AI Mess Detector works out of the box with sensible defaults. A configuration file lets you customize thresholds, disable rules, and exclude paths to fit your project.

---

## Configuration File

Create a file named `aimd.yaml` in your project root. AI Mess Detector automatically looks for this file.

You can also specify a file explicitly:

```bash
vendor/bin/aimd check src/ --config=my-config.yaml
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
    If you pass paths as CLI arguments (e.g., `vendor/bin/aimd check src/ lib/`), they take precedence over the config file.

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

Glob patterns for suppressing violations. Unlike `exclude`, these files **are still analyzed** (their metrics are collected), but violations are not reported. This is useful for generated code, simple data classes, or entity files where complexity rules don't make sense:

```yaml
exclude_paths:
  - src/Entity/*
  - src/DTO/*
```

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
fail_on: error    # Only fail on errors (warnings exit 0)
# fail_on: warning  # Fail on warnings too (default)
# fail_on: none     # Never fail on violations
```

### Exclude Health

Exclude specific health dimensions from scoring. The excluded dimensions are not shown in the health summary and do not contribute to the overall score:

```yaml
exclude_health:
  - typing
  - maintainability
```

Equivalent CLI: `--exclude-health=typing --exclude-health=maintainability`

### Format

Set the default output format:

```yaml
format: summary   # Default
# format: json
# format: html
```

---

## Full Example

```yaml
paths:
  - src/

exclude:
  - vendor/
  - tests/Fixtures/

exclude_paths:
  - src/Entity/*
  - src/DTO/*

include_generated: false

format: summary
fail_on: error

exclude_health:
  - typing

disabled_rules:
  - code-smell.boolean-argument
  - duplication

rules:
  complexity.cyclomatic:
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
vendor/bin/aimd check lib/

# Add extra exclude paths on top of config
vendor/bin/aimd check src/ --exclude-path='src/Generated/*'
```

This makes it easy to experiment without editing the config file.

---

## What's Next?

See the [CLI Options](../usage/cli-options.md) reference for the complete list of command-line options.
