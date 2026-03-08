# Configuration

AI Mess Detector works out of the box with sensible defaults. A configuration file lets you customize thresholds, disable rules, and exclude paths to fit your project.

---

## Configuration File

Create a YAML file in your project root. AI Mess Detector automatically looks for these file names (in order):

1. `aimd.yaml`
2. `aimd.yml`
3. `.aimd.yaml`
4. `.aimd.yml`

The first file found is used. You can also specify a file explicitly:

```bash
vendor/bin/aimd analyze src/ --config=my-config.yaml
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
    If you pass paths as CLI arguments (e.g., `vendor/bin/aimd analyze src/ lib/`), they take precedence over the config file.

### Exclude

Directories to skip entirely. Files in these directories are not analyzed at all:

```yaml
exclude:
  - vendor/
  - tests/Fixtures/
```

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

rules:
  complexity.cyclomatic:
    method:
      warning: 15
      error: 25

  size.method-count:
    warning: 25
    error: 40

  code-smell.boolean-argument:
    enabled: false
```

---

## CLI Options Override Config

Command-line options always take precedence over values in the configuration file. For example:

```bash
# Config says paths: [src/], but CLI overrides it
vendor/bin/aimd analyze lib/

# Add extra exclude paths on top of config
vendor/bin/aimd analyze src/ --exclude-path='src/Generated/*'
```

This makes it easy to experiment without editing the config file.

---

## What's Next?

See the [CLI Options](../usage/cli-options.md) reference for the complete list of command-line options.
