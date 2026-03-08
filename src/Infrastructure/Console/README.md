# Console — CLI Application

## Overview

CLI application based on Symfony Console with support for:
- Multiple analysis commands
- Flexible configuration via options
- Progress reporting for large projects
- Git integration for change analysis
- Baseline management
- Graph export

## Commands

### AnalyzeCommand

**Name:** `analyze`

**Dependencies (via constructor):**
- `RuleRegistryInterface` — for CLI option discovery
- `ConfigLoaderInterface` — loading config files
- `AnalyzerInterface` — running analysis
- `FormatterRegistryInterface` — output formatting
- `CacheFactory` — for --clear-cache
- `ConfigurationProviderInterface` — setting runtime config
- `RuleOptionsFactory` — setting CLI options

**Arguments:**
- `paths` (required, array) — paths for analysis

**Exit codes:**

| Code | Description                      |
| ---- | -------------------------------- |
| 0    | No violations                    |
| 1    | Warnings present (but no errors) |
| 2    | Errors present                   |

### BaselineCleanupCommand

Cleanup baseline from stale entries (violations that have already been fixed).

**Name:** `baseline:cleanup`

**Arguments:**
- `baseline-file` (required) — path to baseline file

### GraphExportCommand

Export dependency graph to DOT format (Graphviz).

**Name:** `graph:export`

**Options:**
- `--output` — output file path (default: stdout)
- `--namespace` — filter by namespace prefix

**Output format:**
- DOT format (Graphviz)
- Circular dependencies highlighted in red
- Clustering by namespace

### Hook Commands

**HookInstallCommand** — install pre-commit hook
**HookStatusCommand** — check hook status
**HookUninstallCommand** — remove pre-commit hook

## CLI Options (main)

### Configuration and Formatting

| Option     | Short | Default | Description                                       |
| ---------- | ----- | ------- | ------------------------------------------------- |
| `--config` | `-c`  | —       | Path to config file                               |
| `--format` | `-f`  | `text`  | Output format (text/json/checkstyle/sarif/gitlab) |

### Caching

| Option          | Default       | Description                       |
| --------------- | ------------- | --------------------------------- |
| `--no-cache`    | false         | Disable caching                   |
| `--cache-dir`   | `.aimd-cache` | Cache directory                   |
| `--clear-cache` | false         | Clear cache before analysis       |
| `--storage`     | `auto`        | Storage type (auto/sqlite/memory) |

### Git Integration

| Option            | Default | Description                                          |
| ----------------- | ------- | ---------------------------------------------------- |
| `--analyze`       | —       | File scope for analysis (git:staged, git:main..HEAD) |
| `--report`        | —       | Violation scope for report                           |
| `--staged`        | false   | Alias for --analyze=git:staged                       |
| `--diff`          | —       | Alias for --report=git:<ref>..HEAD                   |
| `--report-strict` | false   | Show only violations in changed files                |

### Logging and Progress

| Option          | Default | Description                |
| --------------- | ------- | -------------------------- |
| `--log-file`    | —       | Log file path (JSON Lines) |
| `--log-level`   | `info`  | Minimum log level          |
| `--no-progress` | false   | Disable progress bar       |

### Baseline

| Option                    | Description                               |
| ------------------------- | ----------------------------------------- |
| `--baseline`              | Use baseline file                         |
| `--generate-baseline`     | Generate baseline from current violations |
| `--show-resolved`         | Show count of resolved violations         |
| `--baseline-ignore-stale` | Ignore stale entries (do not fail)        |
| `--show-suppressed`       | Show suppressed violations (@aimd-ignore) |
| `--no-suppression`        | Ignore suppression tags                   |

### Rules

| Option           | Description                                         |
| ---------------- | --------------------------------------------------- |
| `--cc-warning`   | Cyclomatic complexity warning threshold             |
| `--cc-error`     | Cyclomatic complexity error threshold               |
| `--disable-rule` | Disable a rule or group (prefix match)              |
| `--only-rule`    | Run only the specified rule or group (prefix match) |
| `--rule-opt`     | Rule option `RULE:OPTION=VALUE`                     |

Full list of options available via `bin/aimd analyze --help`.

## Progress Reporter

Analysis progress display for large projects.

### ConsoleProgressBar

Implementation using Symfony ProgressBar.

**Features:**
- Shown only for projects > 10 files
- Automatically disabled for non-TTY (CI, pipes)
- Disabled in quiet mode (`-q`)
- Shows current file, progress, ETA, memory usage

**Output format:**
```
Analyzing src/...
 142/500 [========>-------------------]  28% < 1 min  16 MB
 Analyzing UserService.php
```

**Automatic disabling:**
- Non-TTY output (CI, pipes)
- Quiet mode (`-q`)
- Verbose mode (`-v`, `-vv`, `-vvv`) — detailed logging is shown instead of progress bar

## Usage Examples

```bash
# Full project analysis
bin/aimd analyze src/

# With config file
bin/aimd analyze src/ --config=aimd.yaml

# Different output formats
bin/aimd analyze src/ --format=json
bin/aimd analyze src/ --format=checkstyle

# Pre-commit: staged files only
bin/aimd analyze src/ --staged

# PR review: full analysis, report only for changes
bin/aimd analyze src/ --diff=main

# With baseline
bin/aimd analyze src/ --baseline=baseline.json

# Generate baseline
bin/aimd analyze src/ --generate-baseline=baseline.json

# Export dependency graph
bin/aimd graph:export src/ --output=graph.dot

# Git hooks
bin/aimd hook:install
bin/aimd hook:status
bin/aimd hook:uninstall
```

## Definition of Done

- `AnalyzeCommand` works with all options
- Exit codes are correct (0/1/2)
- Progress bar works for large projects
- Git integration via --staged, --diff options
- Baseline management via options
- GraphExportCommand exports the graph
- Hook commands manage pre-commit hook
- Output formatting via FormatterRegistry
- Unit tests for commands
- End-to-end integration tests
