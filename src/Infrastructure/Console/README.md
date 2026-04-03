# Console — CLI Application

## Overview

CLI application based on Symfony Console with support for:
- Multiple analysis commands
- Flexible configuration via options
- Progress reporting for large projects
- Git integration for change analysis
- Baseline management
- Graph export

## Structure

```
Console/
├── Application.php
├── CliOptionsParser.php
├── ViolationFilterPipeline.php
├── ViolationFilterOptions.php
├── ViolationFilterResult.php
├── GitScopeFilterConfig.php
├── RuntimeConfigurator.php
├── ResultPresenter.php
├── CheckCommandDefinition.php
├── FilteredInputDefinition.php      # InputDefinition that hides rule-specific options from --help
├── OutputHelper.php                 # Line-by-line output with flush (avoids PTY truncation)
├── Progress/
│   ├── ConsoleProgressBar.php
│   ├── ProgressReporterHolder.php
│   └── DelegatingProgressReporter.php
└── Command/
    ├── CheckCommand.php             # Main analysis command
    ├── BaselineCleanupCommand.php   # Cleanup stale baseline entries
    ├── GraphExportCommand.php       # Export dependency graph (DOT, JSON)
    ├── HookInstallCommand.php       # Install pre-commit hook
    ├── HookStatusCommand.php        # Check hook status
    └── HookUninstallCommand.php     # Remove pre-commit hook
```

## Commands

### CheckCommand

**Name:** `check` (alias: `analyze` — deprecated)

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
| 3    | Configuration or input error     |

### BaselineCleanupCommand

Cleanup baseline from stale entries (violations that have already been fixed).

**Name:** `baseline:cleanup`

**Arguments:**
- `baseline-file` (required) — path to baseline file

### GraphExportCommand

Export dependency graph in DOT or JSON format.

**Name:** `graph:export`

**Options:**
- `--output` — output file path (default: stdout)
- `--namespace` — filter by namespace prefix
- `--format` — output format: `dot` (default) or `json`

**Output formats:**
- **DOT** (Graphviz) — circular dependencies highlighted in red, clustering by namespace
- **JSON** — structured graph data for programmatic consumption

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

| Option          | Default      | Description                 |
| --------------- | ------------ | --------------------------- |
| `--no-cache`    | false        | Disable caching             |
| `--cache-dir`   | `.qmx-cache` | Cache directory             |
| `--clear-cache` | false        | Clear cache before analysis |

### Git Integration

| Option            | Default | Description                           |
| ----------------- | ------- | ------------------------------------- |
| `--report`        | —       | Violation scope for report            |
| `--report-strict` | false   | Show only violations in changed files |

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
| `--show-suppressed`       | Show suppressed violations (@qmx-ignore)  |
| `--no-suppression`        | Ignore suppression tags                   |

### Rules

| Option                 | Description                                         |
| ---------------------- | --------------------------------------------------- |
| `--cyclomatic-warning` | Cyclomatic complexity warning threshold             |
| `--cyclomatic-error`   | Cyclomatic complexity error threshold               |
| `--disable-rule`       | Disable a rule or group (prefix match)              |
| `--only-rule`          | Run only the specified rule or group (prefix match) |
| `--rule-opt`           | Rule option `RULE:OPTION=VALUE`                     |

Full list of options available via `bin/qmx check --help`.

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
bin/qmx check src/

# With config file
bin/qmx check src/ --config=qmx.yaml

# Different output formats
bin/qmx check src/ --format=json
bin/qmx check src/ --format=checkstyle

# PR review: full analysis, report only for changes
bin/qmx check src/ --report=git:main..HEAD

# With baseline
bin/qmx check src/ --baseline=baseline.json

# Generate baseline
bin/qmx check src/ --generate-baseline=baseline.json

# Export dependency graph
bin/qmx graph:export src/ --output=graph.dot

# Git hooks
bin/qmx hook:install
bin/qmx hook:status
bin/qmx hook:uninstall
```

## Definition of Done

- `CheckCommand` works with all options
- Exit codes are correct (0/1/2)
- Progress bar works for large projects
- Git integration via --report option
- Baseline management via options
- GraphExportCommand exports the graph
- Hook commands manage pre-commit hook
- Output formatting via FormatterRegistry
- Unit tests for commands
- End-to-end integration tests
