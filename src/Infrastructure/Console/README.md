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
├── CliSelectorDecoder.php       # explicit kind:value scalar → Core path/namespace pattern
├── CliOptionsParser.php
├── MeasuredFindingSet.php         # The set a baseline measures (ADR 0017): the pipeline's findings before the baseline stage. Defined by configuration alone — qmx.yaml, source annotations, and the config CLI flags baseline commands share with check (--preset, --disable-rule, --only-rule, --include-generated, --include-autoload-dev), which can narrow or widen it; check's own --suppress-path/--suppress-namespace flags never reach it, since baseline commands deliberately omit them
├── FindingFilterOrchestrator.php  # Builds Reporting projection options and renders stage diagnostics; policy and ordering remain in Reporting
├── RuntimeConfigurator.php
├── RuntimeLoggerConfigurator.php    # Creates, publishes, and returns the logger for one run
├── AnalysisRuntimeConfigurator.php  # Per-run rule, collector, cache, and feature state
├── CheckScopeResolver.php           # Git scope first, then warnings for that exact scope
├── ResolvedCheckScope.php           # Resolved Git scope plus deferred warning messages
├── ErrorStream.php                   # The run's single error-stream owner: the progress section and every diagnostic writer
├── RuleInputValidator.php            # Fail-closed selector/option-owner validation
├── ChannelExclusionKeyValidator.php  # Whether one suppress_namespace_channels key can exclude anything
├── ChannelExclusionKeyHints.php      # What to say when it cannot
├── ResultPresenter.php
├── ArtifactFile.php                 # A file an option names for an artifact (--output, --profile, graph --output): one model of the target for the precheck and the write
├── CommandLineSpelling.php          # An option or argument value as argv would spell it; every valued door reads through it
├── FormatOptionPairs.php            # The --format-opt door: every written pair judged, a repeated key and two spellings of one value refused
├── CheckCommandDefinition.php
├── FilteredInputDefinition.php      # InputDefinition that hides rule-specific options from --help
├── OutputHelper.php                 # Line-by-line output with flush (avoids PTY truncation)
├── RunningBinaryLocator.php         # Where the qmx binary running this process lives on disk
├── RunningBinaryLocatorInterface.php
├── Hook/
│   └── PreCommitHook.php            # The generated pre-commit hook: its text, its marker, and what counts as ours
├── LayerAssignmentResolver.php      # Rebuilds collected project state for layer-assignment diagnostics
├── Progress/
│   ├── ConsoleProgressBar.php
│   ├── ProgressConfigurator.php      # Whether this run shows a frame, and on what
│   └── SwitchableProgressReporter.php
└── Command/
    ├── CheckCommand.php             # Main analysis command
    ├── AbstractHookCommand.php      # Shared by the three below: locate the repository, spell hooks/pre-commit, refuse once
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
`RuleInputValidator`, and `CheckScopeResolver`. The command
has no logger, `GitScopeResolver`, or `ScopeWarningChecker` property.

`CheckScopeResolver` owns the narrow scope seam. It resolves
`GitScopeResolution` first, so invalid Git references fail before warnings or a
payload are produced, and only then asks Run's `ProjectScopeCoverage` which of
the project's autoload targets — production, plus `autoload-dev` under
`AutoloadDevPolicy::Include` — the resolved paths leave uncovered. That one
measurement feeds every output of `ResolvedCheckScope`: `ScopeWarningChecker`
renders its uncovered targets as the partial-autoload warning, its
`ProjectScopeState` decides the `coversProjectScope` boolean `CheckCommand` puts
on the scoped `RunConfiguration` (true for `Covered` and for `Unknown`, where
the manifest declares nothing and the paths are the project), and the same
state becomes the `Reporting\ReportProjectScope` `ResultPresenter` adds to the
report — naming, on a `Narrowed` run, the uncovered targets and
`ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS` as not judged. A rule that must
stay quiet on a slice, the warning about that slice and the report's statement
of it cannot disagree. The suppression audit reads the same answer rather than
measuring again: `FindingFilterOrchestrator::valueScope()` builds Finding's
per-value `ValueScopeJudgement` once from `ResolvedCheckScope` — `null` on a
narrowed run — and both the audit's findings and the values it skipped, which
`projectScope()` adds to the report's scope, are read from it. The measurement's pruned targets —
declared entries under a `vendor`, `node_modules` or `.git` directory, which are
neither analysed by default nor counted — get a warning line of their own,
independent of coverage: a whole-project run can still have dropped them.
The coverage is taken for the resolved paths, not the configured ones: a Git
report scope narrows the run after the configuration was resolved. `CheckCommand` validates the resolved paths
before emitting the messages through its stderr-only warning route; structured
stdout remains a clean report payload.

The Console package is an adapter. It imports Run, Configuration, Finding, and
Reporting contracts, parses options, configures one run, and renders
diagnostics; it does not own a pipeline phase or finding-policy state. The
Reporting-owned `FindingProjector` is the single authority for suppression,
configured exclusions, baseline judgment, annotation rejoin, and Git-last
projection.

`CliSelectorDecoder` owns the scalar form of the shared selector language:
`exact:value`, `subtree:value`, or `regex:value`. It splits at the first colon
only, refuses bare values through `ConfigurationRefusal::aboutCommandLineInput`,
and delegates binding and PCRE validation to Core. `CliOptionsParser` retains
the Finding-owned `RULE:OPTION=VALUE` grammar; selector-valued rule options are
wired only once their Finding owners accept the bound values.

`RuleInputValidator` validates selectors against one immutable rule-channel
snapshot for the resolved run. The snapshot is assembled by Infrastructure Rule
from `ResolvedComputedMetricDefinitions`; Console consumes only that resolved
snapshot while processing the invocation.

`ChannelExclusionKeyValidator` answers the one question that needs the universe
rather than the input: whether a `suppress_namespace_channels` key addresses a
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

The resolver also owns the answer to "was this class analysed at all": an FQN
that names no analysed declaration raises `ConfigurationRefusal` (exit 3)
instead of reaching the inspector, so "never analysed" and "analysed, no layer
matched" stop sharing the `(no layer)` report. Membership folds ASCII case the
way PHP folds class names; layer matching itself stays case-sensitive.

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
owners are input errors (exit 3); a bare group prefix is refused with the
`NAME.*` spelling named when that spelling would match. `ConfigurationInputAdapter`
refuses an empty value for the five doors whose owners would read it as
"not given" (`--config`, `--preset`, `--baseline`, `--output`, `--report`), and
`ProfilePresenter::refuseImpossibleExport()` refuses a `--profile-format` outside
its closed set and a `--profile` target the export cannot be written to, and
`ResultPresenter::assertOutputIsWritable()` the same for `--output` — all before
analysis. Both targets, and `graph:export --output`, are judged by
`ArtifactFile`, which also makes the write, so the precheck cannot model a
different write than the one made. The write is a shell's `>` as nearly as
PHP allows, and the kernel decides what a path leads to: a target it reaches is
opened by the path as written and written in place; a name it reaches nothing
at is created by `touch()`, whose open the kernel resolves, so a dangling link
creates its target and a link `fs.protected_symlinks` forbids is refused —
every other PHP open resolves links in userspace, beyond that rule. The
precheck asks only what the kernel answers without a write (not a directory; a
reachable target writable; a new name's directory writable and searchable; a
descriptor held and, on Linux, open for writing) and leaves a link it does not
follow to the write, which refuses after the run. The supported spellings
`/dev/stdout`, `/dev/stderr`, `/dev/fd/N` and `/proc/self/fd/N`, exactly as
written, go through `php://fd/N` in blocking mode, because PHP on Linux opens
those paths by resolving them, which fails on a pipe and truncates a redirected
file; another spelling is opened by its path. A write that fails midway removes
a file it created and leaves an existing target partly written. A profile write
that still fails after the report is published
ends the run with exit 3 through `RefusalPresenter::refusalAfterPublishedReport()`:
the sentence goes to stderr whatever the format, so stdout keeps the report as
its only document. `FormatOptionPairs` judges every written `--format-opt` pair
before any fold by key and refuses a key written twice, and two keys that set
one value (`violations` and `limit`, or `limit` beside `--all`);
`FormatterContextFactory` refuses `--detail` or `--detail=N` beside `--all` the
same way, and — in `bindFormatBeforeAnalysis()`, against the resolved format,
and again in `create()` — a `--namespace` or `--class` selection under a format
`OutOfScopeFindings::FORMATS_WITHOUT_A_PLACE` names. `--report`
is read here, through `CommandLineSpelling`, and handed to
`Git\GitScopeResolver` as a string.
Every valued option and argument is read through `CommandLineSpelling`: argv
delivers strings, and an embedder's array input may deliver any PHP value, so an
integer is read as its digits and any other shape is refused (exit 3) instead
of reaching a string-typed reader as a type error (exit 1) or being dropped.
Flags are read as booleans, and a value-optional option decides its "written
alone" forms (`null`, or `true` from an array input) before spelling the value.
`Application::doRun()` reads the long `--format` off the raw tokens, so a
refusal it catches is enveloped for the JSON formats like one a command catches,
and `RefusalPresenter` frames the fallback path exactly like a carried refusal.
The JSON envelope is `{error, exit_code, position}`: `position` publishes a
refusal's `RefusedPosition` (`path`, `written`, `accepted`, `closed`) — the
refused spot as its throw site located it — and is `null` for every outcome
without one, including a merged value whose sentence names its key. On incomplete analysis, the selected report is
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
- `--namespace` — include an explicit `exact:`, `subtree:`, or `regex:` namespace selector (repeatable)
- `--exclude-namespace` — exclude an explicit namespace selector (repeatable; exclusion wins)
- `--format` — output format: `dot` (default) or `json`

**Output formats:**
- **DOT** (Graphviz) — circular dependencies highlighted in red, clustering by namespace
- **JSON** — structured graph data for programmatic consumption

The command refuses partial analysis with exit 4. It writes no stdout artifact,
does not create a missing destination, and preserves an existing destination.

### Hook Commands

**HookInstallCommand** — write `.git/hooks/pre-commit`
**HookStatusCommand** — check hook status
**HookUninstallCommand** — remove the hook, if it is ours

The hook's contents are generated rather than shipped: `/scripts/` is excluded
from the composer distribution, so a script living there reaches no consumer
([ADR 0068](../../../docs/adr/0068-the-pre-commit-hook-is-generated-not-shipped.md)).
All three commands test `is_link` before `file_exists`, because a hook
installed by an earlier release is now a symlink leading nowhere, and
`file_exists` follows the link and calls it absent.

They refuse the way every other command does: by throwing a
`ConfigurationRefusal`, which `Application::doRun()` turns into exit 3 and a
line on stderr — no hook command writes its reason to stdout or returns 1.
The `.backup` slot is single because `--restore-backup` reads it by that name,
so `hook:install --force` refuses to overwrite a slot holding a different hook
rather than lose it. `RunningBinaryLocator` resolves a relative
`SCRIPT_FILENAME`/`argv[0]` through the entry script PHP opened at startup,
not against the working directory `--working-dir` has since changed.

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

| Option          | Default | Description                                                                                                                                                              |
| --------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `--log-file`    | —       | Log file path (JSON Lines); a path that cannot be written is refused with exit 3                                                                                         |
| `--log-level`   | —       | Minimum log level for `--log-file` and, with `-v` or more, the console; without `-v` it can only narrow the console. Not given: verbosity chooses, the file takes `info` |
| `--no-progress` | false   | Disable progress bar                                                                                                                                                     |

### Baseline

| Option                         | Description                                                                                                                                                                                                                                                                                                                                                                                              |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--baseline`                   | Use baseline file                                                                                                                                                                                                                                                                                                                                                                                        |
| `--show-resolved`              | Show count of resolved findings                                                                                                                                                                                                                                                                                                                                                                          |
| `--show-suppressed`            | Show suppressed findings — `@qmx-ignore` tags and per-rule `suppress_namespaces` / `suppress_namespace_channels` / `suppress_paths` exclusions, each listed in its own block. `--format=suppressed` (or `format: suppressed` in `qmx.yaml`) reports the same composition, across all seven suppression mechanisms, as machine-readable JSON — either route arms the same capture (`RuntimeConfigurator`) |
| `--no-suppression-annotations` | Report findings `@qmx-ignore` suppresses. It does **not** change what a baseline measures: the annotated findings never reach the baseline stage and are never captured, so they are shown at their own severity and compared against no entry. A flag may narrow the measured set (`--suppress-path`, `--suppress-namespace`), never widen it                                                           |

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
bytes on a TTY. The section is built by `ErrorStream` — `getErrorOutput()`
returns a plain `StreamOutput`, which has no `section()` — and handed to the
bar. The bar no longer asks its output whether it can make a section; whether
progress is possible at all is `ProgressConfigurator`'s decision, taken on the
same four gates as before — an error stream of its own, decoration,
`--no-progress`, quiet mode.

**Diagnostics share the frame's owner.** `ErrorStream` holds the section list
and creates the diagnostic section before the progress section, which puts the
frame at the bottom of the screen: a log line, a preflight warning, a report
note or an uncaught throwable erases the frame, is written permanently, and the
frame is redrawn beneath it. Progress and detailed logging are therefore both
shown at `-v`, `-vv` and `-vvv`; neither erases the other.

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
