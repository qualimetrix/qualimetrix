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
├── MeasuredFindingSet.php         # The set a baseline measures (ADR 0017): the pipeline's findings before the baseline stage. Defined by config + source annotations; a CLI flag may narrow it, never widen it
├── FindingFilterOrchestrator.php  # Builds Reporting projection options and renders stage diagnostics; policy and ordering remain in Reporting
├── RuntimeConfigurator.php
├── RuntimeLoggerConfigurator.php    # Creates, publishes, and returns the logger for one run
├── AnalysisRuntimeConfigurator.php  # Per-run rule, collector, cache, and feature state
├── CheckScopeResolver.php           # Git scope first, then warnings for that exact scope
├── ResolvedCheckScope.php           # Resolved Git scope plus deferred warning messages
├── DiagnosticOutput.php              # Human diagnostics routed to stderr
├── RuleInputValidator.php            # Fail-closed selector/option-owner validation
├── ChannelExclusionKeyValidator.php  # Whether one exclude_namespace_channels key can exclude anything
├── ChannelExclusionKeyHints.php      # What to say when it cannot
├── ResultPresenter.php
├── CheckCommandDefinition.php
├── FilteredInputDefinition.php      # InputDefinition that hides rule-specific options from --help
├── OutputHelper.php                 # Line-by-line output with flush (avoids PTY truncation)
├── LayerAssignmentResolver.php      # Rebuilds collected project state for layer-assignment diagnostics
├── Progress/
│   ├── ConsoleProgressBar.php
│   └── SwitchableProgressReporter.php
└── Command/
    ├── CheckCommand.php             # Main analysis command
    ├── BaselineCleanupCommand.php   # Cleanup stale baseline entries
    ├── GraphExportCommand.php       # Export dependency graph (DOT, JSON)
    ├── HookInstallCommand.php       # Install pre-commit hook
    ├── HookStatusCommand.php        # Check hook status
    ├── HookUninstallCommand.php     # Remove pre-commit hook
    └── Debug/
        └── LayerAssignmentCommand.php # Validate input, configure runtime, and render layer matches
```

## Commands

### CheckCommand

**Name:** `check`

`CheckCommand` has ten constructor dependencies and thirteen properties. Its
direct collaborators are `RuleRegistryInterface`, `AnalysisPipelineInterface`,
`CacheFactory`, `FindingFilterOrchestrator`,
`ConfigurationPipelineInterface`, `RuntimeConfigurator`, `ResultPresenter`,
`RuleInputValidator`, `DiagnosticOutput`, and `CheckScopeResolver`. The command
has no logger, `GitScopeResolver`, or `ScopeWarningChecker` property.

`CheckScopeResolver` owns the narrow scope seam. It resolves
`GitScopeResolution` first, so invalid Git references fail before warnings or a
payload are produced, and only then computes partial-autoload warnings for the
resolved project root and paths. `ResolvedCheckScope` returns that unchanged
scope with its warning messages. `CheckCommand` validates the resolved paths
before emitting the messages through its stderr-only warning route; structured
stdout remains a clean report payload.

The Console package is an adapter. It imports Run, Configuration, Finding, and
Reporting contracts, parses options, configures one run, and renders
diagnostics; it does not own a pipeline phase or finding-policy state. The
Reporting-owned `FindingProjector` is the single authority for suppression,
configured exclusions, baseline judgment, annotation rejoin, and Git-last
projection.

`RuleInputValidator` validates selectors against one immutable rule-channel
snapshot for the resolved run. The snapshot is assembled by Infrastructure Rule
from `ResolvedComputedMetricDefinitions`; Console consumes only that resolved
snapshot while processing the invocation.

`ChannelExclusionKeyValidator` answers the one question that needs the universe
rather than the input: whether an `exclude_namespace_channels` key addresses a
channel the rule it is written under actually produces. Keys read `NameSelector`,
the one selector grammar; a key left in the retired `ruleName#violationCode`
spelling is refused by name rather than treated as an unknown channel.
`ChannelExclusionKeyHints`
carries the wording, split along the same seam as
`Inline\Directive\DirectiveAddressability` / `DirectiveNameHints`: one decides
whether a name is wrong, the other what to say about it.

`LayerAssignmentResolver` is an internal Console collaborator for
`debug:layer-assignment`. It owns the adapter-side discovery, generated-file
filtering, collection, dependency-graph and class-set preparation needed to
query `LayerAssignmentInspectorInterface`; the command retains input validation, runtime
configuration, error mapping and rendering. This keeps both declarations below
their constructor-dependency thresholds without introducing a public port.

**Arguments:**
- `paths` (required, array) — paths for analysis

**Exit codes:**

| Code | Description                                             |
| ---- | ------------------------------------------------------- |
| 0    | No findings                                             |
| 1    | Warnings present (but no errors)                        |
| 2    | Errors present                                          |
| 3    | Configuration or input error                            |
| 4    | Analysis incomplete; policy result is not authoritative |

Unknown `--only-rule` / `--disable-rule` selectors and unknown rule-option
owners are input errors (exit 3). On incomplete analysis, the selected report is
still rendered for diagnosis and exit 4 takes precedence over finding policy.
Non-payload diagnostics from `check` are written to stderr.

### BaselineCleanupCommand

Cleanup baseline from stale entries (findings that have already been fixed).

**Name:** `baseline:cleanup`

**Arguments:**
- `baseline-file` (required) — path to baseline file

### GraphExportCommand

Export dependency graph in DOT or JSON format.

The command is an adapter: it obtains the graph through
`DependencyGraphAnalyzerInterface` and renders it through Reporting's public
`DependencyGraphProjectionInterface`. It never imports or constructs the
internal DOT/JSON exporters.

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

| Option     | Short | Default | Description                                                      |
| ---------- | ----- | ------- | ---------------------------------------------------------------- |
| `--config` | `-c`  | —       | Path to config file                                              |
| `--format` | `-f`  | `text`  | Output format (text/json/checkstyle/sarif/gitlab/suppressed/...) |

### Caching

| Option          | Default      | Description                 |
| --------------- | ------------ | --------------------------- |
| `--no-cache`    | false        | Disable caching             |
| `--cache-dir`   | `.qmx-cache` | Cache directory             |
| `--clear-cache` | false        | Clear cache before analysis |

### Git Integration

| Option            | Default | Description                         |
| ----------------- | ------- | ----------------------------------- |
| `--report`        | —       | Finding scope for report            |
| `--report-strict` | false   | Show only findings in changed files |

### Logging and Progress

| Option          | Default | Description                |
| --------------- | ------- | -------------------------- |
| `--log-file`    | —       | Log file path (JSON Lines) |
| `--log-level`   | `info`  | Minimum log level          |
| `--no-progress` | false   | Disable progress bar       |

### Baseline

| Option                         | Description                                                                                                                                                                                                                                                                                                                                                                                           |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--baseline`                   | Use baseline file                                                                                                                                                                                                                                                                                                                                                                                     |
| `--show-resolved`              | Show count of resolved findings                                                                                                                                                                                                                                                                                                                                                                       |
| `--show-suppressed`            | Show suppressed findings — `@qmx-ignore` tags and per-rule `exclude_namespaces` / `exclude_namespace_channels` / `exclude_paths` exclusions, each listed in its own block. `--format=suppressed` (or `format: suppressed` in `qmx.yaml`) reports the same composition, across all seven suppression mechanisms, as machine-readable JSON — either route arms the same capture (`RuntimeConfigurator`) |
| `--no-suppression-annotations` | Report findings `@qmx-ignore` suppresses. It does **not** change what a baseline measures: the annotated findings never reach the baseline stage and are never captured, so they are shown at their own severity and compared against no entry. A flag may narrow the measured set (`--exclude-path`, `--exclude-namespace`), never widen it                                                          |

### `check`'s baseline reporting

`FindingFilterOrchestrator` prints up to three unconditional, non-failing
reports about the loaded baseline — each with its own header and its own
explaining line, so they never run together. None of the three prints
anything on a run without `--baseline`.

- **Stale entries** — an entry whose complete v11 identity (typed subject,
  channel, optional semantic occurrence, and optional edge)
  did not appear in the measured set. `--show-resolved` reads the same
  list and reports the same predicate in a different unit — entries, not
  findings. Because the predicate is keyed on the *full* identity rather
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

| Option                 | Description                                                           |
| ---------------------- | --------------------------------------------------------------------- |
| `--cyclomatic-warning` | Cyclomatic complexity warning threshold                               |
| `--cyclomatic-error`   | Cyclomatic complexity error threshold                                 |
| `--disable-rule`       | Disable a rule or channel by exact name, or a group as `X.*`          |
| `--only-rule`          | Run only the specified producer, group, finding code, or full channel |
| `--rule-opt`           | Rule option `RULE:OPTION=VALUE`                                       |

Full list of options available via `bin/qmx check --help`.

## Progress Reporter

Analysis progress display for large projects.

### ConsoleProgressBar

Implementation using Symfony ProgressBar.

**The bar is drawn on standard error.** The report is the payload of standard
output, and a bar written there prefixes `--format=json` with terminal control
bytes on a TTY. `RuntimeConfigurator` builds a `ConsoleSectionOutput` over the
error stream by hand — `getErrorOutput()` returns a plain `StreamOutput`, which
has no `section()` — and hands it to the bar. The bar no longer asks its output
whether it can make a section; whether progress is possible at all is the
configurator's decision.

Diagnostics (`DiagnosticOutput`, the logger factory) write to the error stream
directly, not through that section, so a warning emitted mid-run can tear the
bar's frame. That is an accepted cost, not an oversight.

**Features:**
- Shown only for projects > 10 files
- Automatically disabled when standard error is not a terminal (CI, pipes)
- Disabled in quiet mode (`-q`)
- Shows current file, progress, ETA, memory usage
- Accepted by all seven analysing commands via `--no-progress`: `check`,
  `directives`, `debug:layer-assignment` and the four `baseline:*` commands

**Output format:**
```
Analyzing src/...
 142/500 [========>-------------------]  28% < 1 min  16 MB
 Analyzing UserService.php
```

**Automatic disabling:**
- Standard error is not a terminal (CI, pipes, redirected stderr)
- The output has no distinguishable error stream (a buffer, `NullOutput`)
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


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
