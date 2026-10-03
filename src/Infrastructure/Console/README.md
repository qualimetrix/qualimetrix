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
├── AuthoredRuleOptionWrites.php    # preserves authored occurrences across input adapters
├── RuleOptionArgv.php              # repeated argv tokens and their original ordinal
├── CliRuleOptionAddressing.php     # alias admission and the declared option address
├── ConfigurationInputAdapter.php   # shared document and CLI ingress
├── ConfigurationDiagnosticsPublisher.php # warnings and source diagnostics on the error stream
├── RuleListingPresenter.php        # producer rows, computed footer and selection sources
├── MeasuredFindingSet.php         # The set a baseline measures (ADR 0017): the pipeline's findings before the baseline stage. Defined by configuration alone — qmx.yaml, source annotations, and the config CLI flags baseline commands share with check (--preset, --disable-rule, --only-rule, --include-generated, --include-autoload-dev), which can narrow or widen it; check's own --suppress-path/--suppress-namespace flags never reach it, since baseline commands deliberately omit them
├── FindingFilterOrchestrator.php  # Builds Reporting projection options and renders stage diagnostics; policy and ordering remain in Reporting
├── BaselineFilterReporter.php     # Private stale, inert and scope-mismatch wording with the selected error writer
├── DirectiveAuditTextPresenter.php # Private text wording; the facade owns shared text/JSON values
├── ExitPolicySection.php            # every writing layer's fail_on value, using the resolved ExitPolicy validator
├── MemoryLimitSection.php           # every writing layer's memory_limit syntax, using RuntimeLimits
├── RuntimeConfigurator.php
├── AnalysisPreflightProfile.php     # Closed analysis and graph consumer profiles
├── AnalysisInputPathValidator.php   # Missing path and explicit non-PHP regular-file refusal
├── ProjectSourceConfigurator.php    # Current manifest facts, namespace binding and DIT install anchor
├── RuntimeLoggerConfigurator.php    # Creates, publishes, and returns the logger for one run
├── AnalysisRuntimeConfigurator.php  # Prepares and commits per-run rule, collector and feature state
├── PreparedAnalysisRuntimeConfiguration.php # Accepted analysis values before stores commit
├── RunConfigurationPreparation.php  # Resolves the run, cache and parallel values together
├── ResolvedRunConfiguration.php     # Immutable accepted run/cache/parallel values
├── ObservedProjectScopeReasons.php  # Projects already observed source issues and install-root omissions
├── CheckScopeResolver.php           # Pure transfer of the initial measurement after Git resolution
├── ResolvedCheckScope.php           # Resolved Git scope plus deferred warning messages
├── ErrorStream.php                   # The run's single error-stream owner: the progress section and every diagnostic writer
├── Refusal/
│   ├── ConsoleExitCode.php             # shared terminal exit vocabulary
│   ├── MachineReadableFormats.php      # formats that publish a structured refusal
│   ├── EnvironmentRefusal.php          # storage and delivery refusal wording
│   ├── FileTargetRefusal.php           # Core failure kind to refusal family
│   └── RefusalPresenter.php            # terminal classification and stream publication
├── RuleInputValidator.php            # Fail-closed selector/option-owner validation
├── ChannelExclusionKeyValidator.php  # Whether one suppress_namespace_channels key can exclude anything
├── ChannelExclusionKeyHints.php      # What to say when it cannot
├── ResultPresenter.php
├── ReportCoverageProjection.php     # The run's coverage as a report publishes it, failures relative to the project
├── RunTarget/
│   ├── RunTargets.php               # shared claims and teardown for report/profile/log
│   ├── RunTargetSession.php         # command outcome, cleanup and terminal classification
│   ├── TargetAccess.php             # pure CLI target-access judgement
│   ├── TargetCollisions.php         # identity/name conflicts before and after claim
│   └── ProcessStreams.php           # current descriptor identity and Linux access-mode inspection
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
        ├── LayerAssignmentCommand.php # Validate input, configure runtime, and publish JSON
        └── LayerAssignmentTextPresenter.php # Render measured layer assignments as text
```

`ExitPolicySection` and `MemoryLimitSection` declare the Console-owned
`fail_on` and `memory_limit` roots. Their context-free forms are judged in every
writing layer through the same validators the resolved runtime values use.
`ExitPolicy::CONFIGURATION_KEY` and `RuntimeLimits::MEMORY_LIMIT_KEY` name
those owner roots; `ConfigSchema` retains the flat ingress mappings.
Whether PHP can apply a valid memory limit depends on the running process and
is judged only when configuring that runtime. `fail_on: false` is refused;
`fail_on: none` selects the policy that does not fail for findings.

The pipeline result exposes `measured` for repository, coverage, namespace tree,
final project scope and duration, and `directives` for observed suppression and
threshold-override maps. Console reads `findings()` for execution publication
plus late findings. Baseline's `MeasuredAnalysisRun.findings` remains its
post-suppression set; it is not the pipeline's late-published list. Hand-built
fixtures without rule execution explicitly pass null and their findings as late.

## Commands

### CheckCommand

**Name:** `check`

`CheckCommand` orchestrates the shared document, runtime, rule inputs, scope
and report adapters. `ConfigurationInputAdapter` and
`CheckConfigurationResolvers` prepare its inputs through `RunConfigurationPreparation`; the command does not perform
another manifest read.

`RunConfigurationResolver` captures the invocation's universe and input evidence.
`CheckScopeResolver` resolves Git publication scope without widening analysis paths
or rereading the manifest. The pipeline adds final filesystem facts once and
publishes the final `ProjectScopeMeasurement`; initial target state alone is not
the report. A complete file roster can cover PHP paths, and an omitted empty
directory alone is not missing PHP evidence.

Finding's single `ProjectScopeJudgement` travels in the result/context and copied
threshold contexts. Declaration absence and exclude-selector completeness are
separate questions; Console does not synthesize another coverage boolean.
`FindingFilterOrchestrator` uses that judgement for per-value suppression binding.
Reports preserve reasons and each skipped `{channel, option, pattern}` value.
`AnalysisInputPathValidator` still refuses missing paths and explicitly named
non-PHP regular files before discovery.

Authored excludes remove named entries. If `analyzed=0`, `failed=0` and
`excluded + generatedExcluded > 0`, the complete intentionally empty result
succeeds with measured counts and an explanation; an empty unrelated named root
is not described as excluded. Any incomplete input takes priority with exit 4.
`check`/`directives` retain diagnostic reports; baseline commands do not mutate,
and graph does not publish an authoritative artifact. Truly undiscovered empty
input retains each command's existing outcome.

`DirectiveAuditPresenter` owns shared text/JSON values and JSON serialization;
private `DirectiveAuditTextPresenter` renders human wording from those values. For intentionally
empty coverage it builds `ReportCoverage` from its report and asks
`CoverageNarrator` for the note, rendered as text and `scope.note` in JSON beside
the existing verdict/selection/sweep fields. The command passes no separate note
parameter. `ReportCoverageProjection` transfers named `excluded` independently
of `discovered`: analyzed PHP plus generated-excluded PHP plus selected failed
terminal entries. Diagnostics remain on stderr and structured stdout retains its format.

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

`LayerAssignmentResolver::resolve(RunConfiguration, SymbolPath)` receives the captured
configuration directly and delegates to `ProjectFilesInterface` with its universe,
aliases and generated policy. It does not reconstruct config from paths/excludes/root
or expose `resolveIncludingGenerated()`.

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
| 3    | Configuration, input or environment refusal             |
| 4    | Analysis incomplete; policy result is not authoritative |

Unknown `--only-rule` / `--disable-rule` selectors and unknown rule-option
owners are input errors (exit 3); a bare group prefix is refused with the
`NAME.*` spelling named when that spelling would match. `ConfigurationInputAdapter`
refuses an empty value for the five doors whose owners would read it as
"not given" (`--config`, `--preset`, `--baseline`, `--output`, `--report`), and
`ProfilePresenter::refuseImpossibleExport()` refuses a `--profile-format` outside
its closed set and a `--profile` target the export cannot be written to, and
`ResultPresenter::assertOutputIsWritable()` the same for `--output` — all before
analysis. `RunTargets` owns report, profile and log target judgement, collision
checks and held resources. Core resolves target components and trusted links;
unknown wrappers, exposed links and unsupported targets refuse. Pure preflight
checks writable regular targets and writable/searchable parents without opening
them. Descriptor existence is checked on both platforms; Linux fdinfo also
identifies descriptors opened only for reading. On macOS an unknown original
access mode is left to the actual write and its typed environment refusal.

Check and Graph report every judged directory exposure on stderr before claiming
targets or entering analysis, naming the option, target, directory and actor.
`OutputHelper` checks complete writes and flush for the actual borrowed
`StreamOutput` resource and preserves raw bytes and the NORMAL verbosity
threshold, including SILENT and QUIET. Other `OutputInterface` implementations
retain their own write contract.

Claims happen after configuration, scope, selector and baseline input checks,
before cache clearing or analysis. An existing file is held without truncation
until report delivery, preserving inode, ownership, mode and hard links. A new
unwritten name is removed during teardown when cleanup succeeds. A write failure can leave an existing
file partly written. Closed symbolic links keep their entry and write the resolved
referent; their parent must already exist. Explicit descriptors retain offset
and use blocking writes. Equal ordinary inodes and equal absent names refuse,
including collisions with implicit report stdout; character devices may coincide.
The shared logger buffers early records, attaches its claimed target, latches
append failures and reports lost records at settle before report publication.
`RunTargetSession` runs Check and Graph actions, attempts target cleanup, then
classifies the primary and cleanup failures. After successful report or graph
publication, both diagnostics use stderr without another stdout envelope.
An environment cleanup failure overrides a findings exit code; an internal
failure keeps exit 1. Failed cleanup can leave an unwritten new target behind,
and names that failure. The publication marker covers completed presenter
calls, not partially written output or exceptions inside the presenter. The
existing inner claim cleanup and terminal-presenter fallback retain their own
boundaries. `FormatOptionPairs` judges every written `--format-opt` pair
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
The JSON envelope is `{error, exit_code, position, source}`: `position` publishes a
refusal's `RefusedPosition` (`path`, `written`, `accepted`, `closed`) — the
refused spot as its throw site located it — and is `null` for every outcome
without one, including a merged value whose sentence names its key. On incomplete analysis, the selected report is
still rendered for diagnosis and exit 4 takes precedence over finding policy.
Non-payload diagnostics from `check` are written to stderr.

`ConfigurationInputAdapter` resolves the configuration document for every
command that reads it — `check`, and through `AnalysisPreflight` and
`BaselineRun` `directives`, `debug:layer-assignment`, `graph:export` and the
four `baseline:*` commands that measure, and directly `rules` — and answers its author there too:
`writeDiagnostics()` prints each warning about the accepted configuration on
stderr as one `Warning:` line, after the runtime is configured, and
`publishedDiagnostics()` gives `check`'s report the same warnings with their
sources in the refusal envelope's `source` form.

The adapter and runtime resolvers read `fail_on`, `memory_limit` and `format`
from resolved leaves. When PHP rejects a requested memory limit, the runtime
wrapper preserves the refusal's sources, position, summary and previous cause
through `ConfigurationRefusal::acrossLayers()`; there is no origin getter to
reconstruct.

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
internal DOT/JSON exporters. Its graph profile resolves Run, Cache, Parallel,
Coupling and memory-limit inputs after the entire document is judged, and
passes `RunConfiguration` plus the configured finder to the analyzer. The graph
format remains `GraphExportFormat::Dot|Json`, separate from the analysis output
format. No Finding selection or analysis-format consumer runs on this path.

**Name:** `graph:export`

**Options:**
- `--output` — output file path (default: stdout)
- `--namespace` — include an explicit `exact:`, `subtree:`, or `regex:` namespace selector (repeatable)
- `--exclude-namespace` — exclude an explicit namespace selector (repeatable; exclusion wins)
- `--format` / `-f` — output format: `dot` (default) or `json`
- `--config`, `--preset` — shared document sources
- `--exclude`, `--include-generated`, `--include-autoload-dev` — run discovery policy
- `--no-cache`, `--workers` / `-w`, `--memory-limit` — run settings
- `--direction` — DOT direction; no short alias

With no path argument, graph export uses resolved document or Composer defaults.
`rules` reads and judges the document without requesting a Run configuration or
refusing an empty analysis tree. It includes named computed metrics and marks
the current stated `only_rules`/`disabled_rules` selection. Finding owns the
shared resolver; Console has no second raw rule parser.
`debug:layer-assignment` accepts `--preset`; all four measuring baseline commands
share `--no-cache`, `--workers` and `--memory-limit`.

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

They refuse the way every other command does: by throwing a typed input or
environment refusal, which `Application::doRun()` turns into exit 3 and a
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
| `--show-suppressed`            | Show suppressed findings — `@qmx-ignore` tags and per-rule `suppress_namespaces` / `suppress_namespace_channels` / `suppress_paths` exclusions, each listed in its own block. `--format=suppressed` (or `format: suppressed` in `qmx.yaml`) reports the same composition, across all eight suppression mechanisms, as machine-readable JSON — either route arms the same capture (`RuntimeConfigurator`) |
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


## Prepared rule handoff and listing

The configuration input adapter resolves the complete declared document and
builds the actual invocation channel snapshot. Measurement commands perform
RuleEnablementResolver decide → RuleOptionsBuild build → conclude before runtime
publication. Aliases and rule-opt contribute to one authored CLI layer with the
same YAML value grammar, duplicate-write refusal and actual option locator.
`RuleOptionArgv` preserves repeated tokens before Symfony folds scalar options;
`AuthoredRuleOptionWrites` retains the bound-input fallback.
`CliRuleOptionAddressing` judges aliases and addresses through the single
`RuleOptionSurface` declaration. `ConfigurationInputAdapter` owns ingress
and delegates diagnostic publication to `ConfigurationDiagnosticsPublisher`.
The shared document/run doors and mandatory scope remain unchanged.

`rules` reads declared forms and resolves stated selection without build,
conclude or store commit. It lists accepted root and level options separately
from aliases and shared framework footer. It retains the effective only filter
and all tied decisive disabling writers. `Selection source` names actual
origin.describe() and zero-based layerIndex; repeated cells of a writer collapse,
while identical displayed text from distinct writers remains distinct.
`RuleListingPresenter` renders the selected rows, computed-metric footer and
selection sources together. DoD distinguishes a valid listing from successful
effective-band preflight.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.

### Terminal refusals

The final application and command catch branches use `RefusalPresenter::unhandled()`
to classify configuration refusals, environment refusals and raw Core environment
failures. Refusals exit with code 3; unrelated exceptions remain internal errors
with code 1. Configuration source metadata, including import chains, survives
the shared `RefusalInterface`. A failure after report publication is written to
stderr so stdout retains one report document.

For a stream output, the JSON refusal writer verifies every write and the final
flush. If stdout cannot accept the envelope, the same terminal diagnostic is
published on stderr. Partial delivery can leave incomplete JSON on stdout; the
stderr diagnostic still explains the refusal. Buffered outputs retain their raw
quiet output semantics.

## Hook publication

Hook commands receive the shared `ErrorStream` as their third constructor
argument. Core judgement precedes writing and reports the first writable exposure
for each hook or backup target. An unreadable existing hook refuses with
environment exit 3 rather than being reported healthy or replaced as foreign.
Installing a foreign-hook backup preserves its mode; restoring it moves the inode
back and consumes the backup name. Content publication uses a complete temporary
sibling. The subject-owned unlink and restore rename repeat entry identities
immediately beforehand, with a remaining inspection/use race.

`BaselineGenerateCommand` requires the same stream as its fourth argument and
reports destination exposure before measurement. It passes a prepared target to
the Baseline writer; parent creation is the caller's responsibility.
