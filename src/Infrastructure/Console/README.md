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
├── MeasuredViolationSet.php         # The set a baseline measures (ADR 0017): the pipeline's findings before the baseline stage. Defined by config + source annotations; a CLI flag may narrow it, never widen it
├── ViolationFilterPipeline.php      # suppression -> path exclusion -> namespace exclusion -> baseline -> git scope; --no-suppression-annotations restores annotated findings after the baseline stage
├── ViolationFilterOptions.php
├── CliOnlyNarrowing.php             # check-only narrowing: --exclude-path / --exclude-namespace / --no-suppression-annotations
├── ViolationFilterResult.php
├── GitScopeFilterConfig.php
├── RuntimeConfigurator.php
├── AnalysisRuntimeConfigurator.php  # Per-run rule, collector, computed-metric, and feature state
├── DiagnosticOutput.php              # Human diagnostics routed to stderr
├── RuleInputValidator.php            # Fail-closed selector/option-owner validation
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

| Code | Description                                             |
| ---- | ------------------------------------------------------- |
| 0    | No violations                                           |
| 1    | Warnings present (but no errors)                        |
| 2    | Errors present                                          |
| 3    | Configuration or input error                            |
| 4    | Analysis incomplete; policy result is not authoritative |

Unknown `--only-rule` / `--disable-rule` selectors and unknown rule-option
owners are input errors (exit 3). On incomplete analysis, the selected report is
still rendered for diagnosis and exit 4 takes precedence over violation policy.
Non-payload diagnostics from `check` are written to stderr.

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

The command refuses partial analysis with exit 4. It writes no stdout artifact,
does not create a missing destination, and preserves an existing destination.

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

| Option                         | Description                                                                                                                                                                                                                                                                                                                                  |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--baseline`                   | Use baseline file                                                                                                                                                                                                                                                                                                                            |
| `--show-resolved`              | Show count of resolved violations                                                                                                                                                                                                                                                                                                            |
| `--show-suppressed`            | Show suppressed violations — `@qmx-ignore` tags and per-rule `exclude_namespaces`/`exclude_paths` exclusions, each listed in its own block                                                                                                                                                                                                   |
| `--no-suppression-annotations` | Report findings `@qmx-ignore` suppresses. It does **not** change what a baseline measures: the annotated findings never reach the baseline stage and are never captured, so they are shown at their own severity and compared against no entry. A flag may narrow the measured set (`--exclude-path`, `--exclude-namespace`), never widen it |

### `check`'s baseline reporting

`ViolationFilterOrchestrator` prints up to three unconditional, non-failing
reports about the loaded baseline — each with its own header and its own
explaining line, so they never run together. None of the three prints
anything on a run without `--baseline`.

- **Stale entries** — an entry whose identity (ADR 0017: symbol, channel, edge)
  did not appear in the measured set. `--show-resolved` reads the same
  list and reports the same predicate in a different unit — entries, not
  violations. Because the predicate is keyed on the *full* identity rather
  than the symbol, a group that shrank without vanishing (say five members
  down to two) is neither stale nor "resolved": its identity still fired, so
  it is invisible to `--show-resolved` by design (ADR 0017 residual-limitation
  list, item 2) — not a bug to be fixed later.
- **Inert entries** — an entry the loaded baseline could not apply at all:
  malformed, addressing an undeclared channel, mismatching its channel's
  shape in either direction, an unrecognized `mode`, or a duplicate identity
  (ADR 0017). Each line names the symbol, the channel, the entry's selector and the
  reason. An inert entry does not suppress anything and is not a load error —
  the findings it was meant to cover are reported at their own severity, and
  the run does not fail on it.
- **Scope mismatch** — when this run's analysed paths do not cover the
  baseline file's recorded `scope` (ADR 0017), `check` names the uncovered paths.
  This never fails the run: a narrower run legitimately sees fewer
  identities, and failing on it would punish the ordinary case of checking
  one directory. The scope guard that *does* refuse to run is a precondition
  of the writing commands (`baseline:update`, `baseline:cleanup`), not a
  `check` behaviour — every identity under an uncovered path looks absent
  from this run and is already counted among the stale entries above.

### Rules

| Option                 | Description                                                             |
| ---------------------- | ----------------------------------------------------------------------- |
| `--cyclomatic-warning` | Cyclomatic complexity warning threshold                                 |
| `--cyclomatic-error`   | Cyclomatic complexity error threshold                                   |
| `--disable-rule`       | Disable a rule or group (prefix match)                                  |
| `--only-rule`          | Run only the specified producer, group, violation code, or full channel |
| `--rule-opt`           | Rule option `RULE:OPTION=VALUE`                                         |

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
bin/qmx baseline:generate baseline.json src/

# Export dependency graph
bin/qmx graph:export src/ --output=graph.dot

# Git hooks
bin/qmx hook:install
bin/qmx hook:status
bin/qmx hook:uninstall
```

## Definition of Done

- `CheckCommand` works with all options
- Exit codes are correct (0/1/2 policy, 3 input/configuration, 4 incomplete analysis)
- Progress bar works for large projects
- Git integration via --report option
- Baseline management via options
- GraphExportCommand exports the graph
- Hook commands manage pre-commit hook
- Output formatting via FormatterRegistry
- Unit tests for commands
- End-to-end integration tests
