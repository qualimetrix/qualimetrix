# CLI Options

Qualimetrix provides the `check` command for code analysis and several utility commands for baseline management, git hooks, and dependency graph visualization.

## check command

```bash
bin/qmx check [options] [--] [<paths>...]
```

### Paths argument

Specify one or more directories or files to analyze:

```bash
# Analyze specific directories
bin/qmx check src/ lib/

# Analyze a single file
bin/qmx check src/Service/UserService.php
```

A missing resolved path or an explicitly named existing regular file whose
extension is not `.php` is refused with exit code 3 before discovery. This
applies to CLI, YAML and preset paths in `check`, `directives`, `graph:export`,
`debug:layer-assignment` and all four measuring baseline commands. Directories
are discovered normally; an empty PHP tree remains a separate analysis refusal.

A path you name that is itself a `vendor`, `node_modules` or `.git` directory (`bin/qmx check lib/vendor`) stops the run with a configuration error: Qualimetrix never walks into one, so the run would analyse nothing. Name a file or a directory inside it instead (`bin/qmx check vendor/acme/`); a `vendor` directory inside a path you name is still skipped.

Authored `exclude:` and `--exclude` apply to named files and directories from
CLI, YAML and presets. `exact:` removes only the named entry; `subtree:` reaches
its descendants. A matching written root is measured as excluded, not refused
as input. When `analyzed=0`, `failed=0` and `excluded + generatedExcluded > 0`,
the result is intentionally empty: `check` and `directives` return 0,
`graph:export` writes an empty graph, and `baseline:generate` writes an empty
baseline. Its explanation remains on stderr and in formats with a diagnostic
place. Incompleteness takes priority with exit 4: diagnostic output is retained,
but no baseline mutation or authoritative graph is published. Truly undiscovered
empty input retains each command's existing outcome.

`.` and canonically equivalent root spellings are valid. A directory or named
alias must canonically target the captured root or a descendant; an external
alias spelling targeting an internal directory is valid, the reverse refuses
with exit 3. For files, the canonical parent is checked and the final name stays
literal, including a named link to an outside file. `--working-dir` chooses the
invocation root; publication has no outside-root fallback.

If you omit paths, Qualimetrix auto-detects them from every path the `autoload` section of your `composer.json` declares — `psr-4` and `psr-0` roots, `classmap` entries (a `*` wildcard expanded to the directories it matches) and `files` entries alike. These are the same paths a run is judged against when Qualimetrix asks whether it covered the whole project, final scope also measures missing PHP, authored/generated removal and universe certainty. An entry that is, or lies inside, a `vendor`, `node_modules` or `.git` directory — which Qualimetrix never walks into — is left out of both and named in a warning instead: third-party code is not analysed as your project's. A declared path that does not exist on disk stops the run with a configuration error. `autoload-dev` is not included: test code is analysed only when you name its path (`bin/qmx check src/ tests/`, or `paths:` in `qmx.yaml`), or when you count it as part of the project with [`--include-autoload-dev`](#--include-autoload-dev).

---

## File options

### `--config`, `-c`

Path to a YAML configuration file:

```bash
bin/qmx check src/ --config=qmx.yaml
```

An empty value is not an omitted option. `--config=` — typically `--config=$QMX_CONFIG` with
the variable unset — is refused with exit code 3 instead of falling back to the `qmx.yaml` in
the working directory. The same holds for `--baseline=`, `--output=`, `--report=` and
`--preset=` (including an empty name in a list such as `--preset=strict,`): leave the option
out to get its default.

Warnings about a configuration a command accepted — `only_rules: []` lifting a
preset's filter, for example — are written to stderr as `Warning:` lines by every
command that reads the configuration, and `check --format=json` also carries them
under `configurationDiagnostics`. See
[Configuration warnings](../getting-started/configuration.md#configuration-warnings).

### `--exclude`

Exclude directories from analysis with an explicit path selector. Can be repeated:

```bash
bin/qmx check src/ --exclude=subtree:src/Generated --exclude=exact:src/Legacy
```

Binding is reported through
[`discovery.unmatched-exclude`](../rules/discovery.md). PHP-path completeness
and universe certainty permit judging unsettled selectors; authored/generated
removal alone does not close that question. A bound selector remains `Removed`
on a narrow run. A possible hidden subtree from the same source gives a qualified
warning; one from another source gives a named unjudged value and a request to
rerun without that exclusion. A removed written root is measured, not refused.
See [Paths argument](#paths-argument).

### `--include-generated`

By default, Qualimetrix automatically skips files that contain a `@generated` annotation in the first 2 KB. This flag overrides that behavior and includes generated files in the analysis:

```bash
bin/qmx check src/ --include-generated
```

Can also be set in `qmx.yaml`:

```yaml
include_generated: true
```

### `--include-autoload-dev`

Counts the code `composer.json` declares under `autoload-dev` as part of the project. Both halves of a run follow it together:

- a run with no paths analyses every path `autoload-dev` declares, in any autoload form, beside the `autoload` ones;
- a run is judged against `autoload-dev` too when Qualimetrix asks whether it covered the whole project — so `bin/qmx check src/ --include-autoload-dev` warns that `tests/` was not analysed, and the channels that only speak on a whole-project run stay silent.

Paths you name yourself are not widened. Off by default:

```bash
bin/qmx check --include-autoload-dev
```

Can also be set in `qmx.yaml`:

```yaml
include_autoload_dev: true
```

### `--suppress-path`

Suppress violations for files selected by `exact:`, `subtree:`, or `regex:`. CLI values split on the first colon; regex fragments are delimiterless and automatically full-anchored. The files are still analyzed, and the option can be repeated:

```bash
bin/qmx check src/ --suppress-path='subtree:src/Entity' --suppress-path='regex:src/DTO/.*\.php'
```

Merged with `suppress_paths` from `qmx.yaml` — both sources are combined.

!!! warning "Does not apply to `architecture.*` rules"
    `architecture.layer-violation` and `architecture.circular-dependency` violations are never
    suppressed by this option — see [Suppress Paths](../getting-started/configuration.md#suppress-paths)
    for why and for the alternatives.

### `--suppress-namespace`

Suppress violations for classes selected by an explicit namespace selector. `subtree:` follows namespace boundaries; `regex:` is a full-subject PCRE fragment. The classes are still analyzed, and the option can be repeated:

```bash
bin/qmx check src/ --suppress-namespace='subtree:App\Entity' --suppress-namespace='regex:App\\DTO(?:\\[^\\]+)*'
```

Merged with `suppress_namespaces` from `qmx.yaml` — both sources are combined.

!!! warning "Does not apply to `architecture.*` rules"
    `architecture.layer-violation` and `architecture.circular-dependency` violations are never
    suppressed by this option — see [Suppress Namespaces](../getting-started/configuration.md#suppress-namespaces)
    for why and for the alternatives.

---

## Preset options

### `--preset`

Apply a named preset or a custom YAML file. Can be repeated or comma-separated:

```bash
# Built-in presets
bin/qmx check src/ --preset=strict
bin/qmx check src/ --preset=legacy

# Combine presets (merged left-to-right)
bin/qmx check src/ --preset=strict,ci
bin/qmx check src/ --preset=strict --preset=ci

# Custom preset file
bin/qmx check src/ --preset=./my-preset.yaml
```

Available built-in presets: `strict`, `legacy`, `ci`.

Presets are applied after `composer.json` auto-detection but before `qmx.yaml`, so your config file always takes precedence. See [Configuration > Presets](../getting-started/configuration.md#presets) for details.

---

## Output options

### `--format`, `-f`

Choose the output format. Default: `summary`.

```bash
bin/qmx check src/ --format=json
bin/qmx check src/ --format=sarif
```

Available formats: `summary`, `text`, `text-verbose`, `json`, `metrics`, `checkstyle`, `sarif`, `gitlab`, `github`, `health`, `html`, `suppressed`.

See [Output Formats](output-formats.md) for details on each format.

### `--output`, `-o`

Write the report to a file instead of stdout:

```bash
bin/qmx check src/ --format=html --output=report.html
```

An existing regular file is written in place: its inode, owner, permissions
and hard links remain. A new name is claimed only after configuration, scope,
selector and baseline-input refusals; a failed write attempts to remove the new
file the run still owns. If removal fails, qmx names the cleanup failure and the
file can remain. An existing file can retain partial bytes after a write failure.
Create the destination's parent directory before running qmx.

A symbolic link is followed only when another user cannot place that directory
entry; accepted links remain intact and write their resolved referents. This
also applies to a dangling link into an existing parent. A placeable link
refuses on both ordinary and thread-safe PHP. Writable exposure elsewhere in
the path is reported on stderr before analysis. Component swaps, ACLs,
hard-link provenance and non-local filesystems retain their platform limits.

`/dev/stdout`, `/dev/stderr`, `/dev/fd/N`, `/proc/self/fd/N`,
`/proc/thread-self/fd/N`, `php://stdout`, `php://stderr` and `php://fd/N`
address an existing process stream; `/proc` spellings require procfs.
`file:///absolute/path` addresses a file.
Other URI schemes refuse. A named pipe opens only after input refusals and
needs its reader; `/dev/null` remains supported.

Descriptor existence is checked before analysis. Linux fdinfo also permits an
early refusal of a descriptor opened only for reading. On macOS PHP does not
expose that original access flag, so an unknown mode is left to the actual write
and its environment refusal. Descriptor and stream writes use blocking mode.

Report, profile and log targets are judged together. Two targets for one
ordinary inode, or one absent name, refuse before analysis, including a target
that aliases the report's stdout. Character devices such as a terminal or
`/dev/null` may coincide. Missing parents, directories, inaccessible paths,
closed descriptors and write failures exit 3; the reason distinguishes
configuration input from an environment failure. A report write failure with
unwritable stdout sends its refusal to stderr.

### `--group-by`

Group violations in the output. Default depends on the formatter.

```bash
bin/qmx check src/ --format=text-verbose --group-by=rule
```

Available values: `none`, `file`, `rule`, `severity`, `class`, `namespace`.

### `--format-opt`

Pass formatter-specific options as key=value pairs. Can be repeated:

```bash
bin/qmx check src/ --format-opt=key=value
```

A key no formatter reads is refused with exit 3. A key some other formatter
reads stays accepted, so a script that sweeps one option set across formats
still works. Each key may be written once: `--format-opt=violations=2
--format-opt=violations=1` is refused with exit 3 rather than the later value
winning, and every pair is checked as written, so an unparsable value is
refused even when another pair or `--all` would have replaced it. Two keys
that set one value are refused the same way: `violations` beside `limit`, or
`limit` beside `--all` (which writes `violations=all`).

**JSON format options:**

| Option                   | Default | Description                                                                                                 |
| ------------------------ | ------- | ----------------------------------------------------------------------------------------------------------- |
| `violations=N\|all`      | all     | Max violations in output (0=none)                                                                           |
| `limit=N\|all`           | all     | The same value as `violations`, except that `0` means no limit; write one of the two                        |
| `top=N`                  | 10      | Number of worst offenders to include                                                                        |
| `rank-by=count\|density` | count   | Reorder worst-offender lists by violation count (default) or by [violation density](output-formats.md#json) |

```bash
bin/qmx check src/ --format=json --format-opt=limit=100
bin/qmx check src/ --format=json --format-opt=violations=all
bin/qmx check src/ --format=json --format-opt=rank-by=density
```

**Summary format options:**

| Option                   | Default | Description                                                                       |
| ------------------------ | ------- | --------------------------------------------------------------------------------- |
| `top=N`                  | 3       | Number of worst offenders to include                                              |
| `rank-by=count\|density` | count   | Reorder worst-offender lists by violation count (default) or by violation density |

```bash
bin/qmx check src/ --format-opt=rank-by=density
```

!!! note "Two unrelated options named `top`"
    `--format-opt=top=N` (JSON and summary formats) caps the worst-namespace/worst-class
    offender lists. The global [`--top`](#--top) flag is a different knob: it caps the
    separate "Top issues by impact" list. The two can be set independently.

**Health format options:**

| Option           | Default | Description                                              |
| ---------------- | ------- | -------------------------------------------------------- |
| `contributors=N` | 3       | Number of worst contributors listed per health dimension |

**HTML format options:**

| Option              | Default       | Description                                         |
| ------------------- | ------------- | --------------------------------------------------- |
| `project-name=NAME` | auto-detected | Overrides the project name shown in the HTML report |

```bash
bin/qmx check src/ --format=html --format-opt=project-name="My Project" -o report.html
```

### `--fail-on`

Set the minimum severity that causes a non-zero exit code. Default: `error`.

```bash
# Default behavior: only errors cause non-zero exit code
bin/qmx check src/

# Also fail on warnings
bin/qmx check src/ --fail-on=warning

# Never fail on violations
bin/qmx check src/ --fail-on=none
```

By default, warnings are shown in the output but do not cause CI failure. Use `--fail-on=warning` to also fail on warnings.

Can also be set in `qmx.yaml`:

```yaml
fail_on: warning   # also fail on warnings
```

### `--exclude-health`

Exclude specific health dimensions from scoring. The excluded dimensions are not shown in the health summary and do not contribute to the overall score. Can be repeated:

```bash
# Exclude typing from health scoring
bin/qmx check src/ --exclude-health=typing

# Exclude multiple dimensions
bin/qmx check src/ --exclude-health=typing --exclude-health=maintainability
```

Available dimensions: `complexity`, `cohesion`, `coupling`, `typing`, `maintainability`.

Can also be set in `qmx.yaml`:

```yaml
exclude_health:
  - typing
```

### `--detail`

Adds a grouped violation list after the summary; the value is an optional cap on its
length. Omitted, the list is not shown (unless `--namespace` or `--class` is used); written
without a value, it shows up to 200 violations; `--detail=N` caps it at `N`; `--detail=all` or
`--detail=0` removes the cap. Only affects `summary` format.

```bash
# Default limit (200 violations)
bin/qmx check src/ --detail

# Show all violations (no limit)
bin/qmx check src/ --detail=all

# Custom limit
bin/qmx check src/ --detail=50
```

Auto-enabled when `--namespace` or `--class` is used.

### `--top`

Number of top-impact issues to show. Default: `10`; `0` disables the section.

```bash
# Default: top 10
bin/qmx check src/

# Show the top 25
bin/qmx check src/ --top=25

# Disable the section
bin/qmx check src/ --top=0
```

Controls the "Top issues by impact" section of the `summary` format and the
`topIssues` key of `--format=json` — a list of findings ranked by impact
(combining ClassRank, severity and remediation time), separate from the
worst-namespace and worst-class offender lists. No other format renders it.

This is a different knob from `--format-opt=top=N`, which caps the worst-offender
lists instead — see the [format options tables](#--format-opt) above.

### `--all`

Show all violations without truncation. This is a shorthand for `--format-opt=violations=all --detail=all`.

```bash
# Show all violations in JSON format
bin/qmx check src/ --format=json --all

# Show all violations in summary format
bin/qmx check src/ --all
```

Cannot be combined with `--format-opt=violations=N` (numeric limit) or with `--detail`/`--detail=N` (a capped list; `--detail` alone is a cap of 200) — each is refused with exit 3 before the analysis, rather than one of the two silently winning. Combining `--all` with `--format-opt=violations=all`, `--detail=all` or `--detail=0` is allowed (they are synonyms).

### `--namespace`

Filter output with an explicit namespace selector. Use `exact:` for one namespace, `subtree:` for it and its boundary-separated descendants, or `regex:` for a delimiterless PCRE fragment. Bare and empty values are refused; regex always covers the full namespace.

```bash
bin/qmx check src/ --namespace='subtree:App\Service'
bin/qmx check src/ --namespace='regex:App\\(?:Billing|Sales)\\Order'
```

Filters violations and worst offenders to the selected namespaces. Shows subtree health scores. Auto-enables `--detail`.

Project-wide findings (`architecture.coverage-gap` and the other diagnostics that judge the run as a whole) are never selected by a namespace selector: they belong to no namespace.

The same matching rule governs the health drill-down and the worst-offender lists this option turns on, and the `include_namespaces` option of `coupling.distance`.

A pattern that selects no analysed namespace is refused with exit 3, and the
refusal says how many namespaces the run did have. A pattern that does select
something and still reports nothing prints the ordinary empty result — that is
the half of the pair worth telling apart.

Mutually exclusive with `--class`. Refused with exit 3 before the analysis
under `--format=gitlab` or `--format=checkstyle`, including when a configuration
file selects the format: their consumers read every entry as a finding, so
neither can say the report is a [partial view](output-formats.md#summary-default).

### `--class`

Filter output to a specific class by exact FQCN match.

```bash
bin/qmx check src/ --class=App\\Service\\UserService
```

Filters violations to the specified class. Auto-enables `--detail`.

An FQCN matching no analysed class is refused with exit 3, for the same reason
`--namespace` is: an empty report otherwise reads as a clean class.

Mutually exclusive with `--namespace`. Refused under `gitlab` and `checkstyle`
as [`--namespace`](#--namespace) is.

---

## Cache options

Qualimetrix caches parsed ASTs to speed up repeated runs.

### `--no-cache`

Disable caching entirely:

```bash
bin/qmx check src/ --no-cache
```

### `--cache-dir`

Set a custom cache directory. Default: `.qmx-cache`.

```bash
bin/qmx check src/ --cache-dir=/tmp/qmx-cache
```

The path is judged without creating directories. An explicitly configured path
that cannot provide a writable, searchable directory is refused with exit 3.
An unusable implicit default disables caching and produces one warning.
An eligible directory is created only after the remaining input checks succeed.

### `--clear-cache`

Clear the cache before running analysis:

```bash
bin/qmx check src/ --clear-cache
```

Clearing starts after input checks and file-target claims. A cache entry that
cannot be removed produces an environment error (exit 3), naming the directory,
the remaining entry count and the reason. An incomplete clear never prints
"Cache cleared.".

---

## Baseline options

See [Baseline](baseline.md) for the lifecycle and file format.

### `--baseline=BASELINE`

Use a baseline file to apply accepted ceilings to live findings:

```bash
bin/qmx check src/ --baseline=baseline.json
```

### `--show-resolved`

Count entries whose complete identity no longer appears in the measured set:

```bash
bin/qmx check src/ --baseline=baseline.json --show-resolved
```

Stale and inert entries are reported without failing the run or disabling other baseline entries. A group that still fires with fewer members is not resolved.

### Baseline lifecycle commands

The commands below are the complete baseline write and inspection surface:

```text
bin/qmx baseline:generate <baseline> [<paths>...] [--mode=MODE] [--force]
bin/qmx baseline:update   <baseline> [<paths>...] [--force]
bin/qmx baseline:cleanup  <baseline> [<paths>...] [--remove=REMOVE]... [--force]
bin/qmx baseline:explain  <symbol> [<paths>...] [--baseline=BASELINE] [--channel=CHANNEL]
bin/qmx baseline:rename-channels <baseline> <map> [--format=FORMAT]
```

The first four commands accept `--config=CONFIG`, `--preset=PRESET`, `--disable-rule=DISABLE-RULE`, `--only-rule=ONLY-RULE`, and `--rule-opt=RULE-OPT`. All four also accept `--include-generated`, `--include-autoload-dev`, `--no-cache`, `--workers`/`-w` and `--memory-limit`. They do not accept any exclusion or suppression option. `baseline:rename-channels` accepts none of them: it runs no analysis, so there is no measured set for them to define.

- `baseline:generate` captures the current measured findings. `--mode=ratchet` is the default; `--mode=suppress` records unconditional acceptance for captured identities. Its `--force` overwrites an existing file.
- `baseline:update` tightens existing entries only. Its `--force` overrides the recorded-scope coverage guard.
- `baseline:cleanup` lists candidates by default and removes only repeated `--remove=REMOVE` selectors. Its `--force` also overrides the scope guard.
- `baseline:explain` shows the configured threshold, accepted baseline level, and source override for a canonical symbol; `--channel=CHANNEL` narrows the answer.
- `baseline:rename-channels` rewrites the `channel` field of the entries a declared tab-separated map names, and nothing else, without analysing anything. Exit `1` covers a refusal on content or an unreadable baseline or map file; `2` is a malformed `--format` value. Either way the baseline is left byte-identical, and the refusal is reported in the chosen format — under `--format=json` as an object with an `error` key. See [Carry a baseline onto renamed channels](baseline.md#carry-a-baseline-onto-renamed-channels) — note that carrying an entry changes its selector.

The four analysing commands refuse incomplete analysis with exit 4 before interpreting
or writing a baseline. `--force` overrides file/scope guards only; it cannot make
a partial measured set acceptable. Existing destinations remain byte-identical,
and `baseline:generate` does not create a missing destination.

Loadable baseline versions and the migration procedure for an older file are
documented under [Replace an older baseline](baseline.md#replace-an-older-baseline).

The removed `--generate-baseline` and `--baseline-ignore-stale` options have no aliases. Use `baseline:generate` and explicit `baseline:cleanup --remove` instead.

---

## Suppression options

### `--show-suppressed`

Show violations that were suppressed by `@qmx-ignore` tags, and violations suppressed by a
per-rule `suppress_namespaces` / `suppress_namespace_channels` / `suppress_paths` entry in `qmx.yaml` (see
[Rules](../getting-started/configuration.md#rules)):

```bash
bin/qmx check src/ --show-suppressed
```

Independently of `--show-suppressed`, running with `-v` prints a per-rule count of how many
violations were suppressed this way. The namespace bucket includes both namespace options and
is separate from `suppress_paths`; each is broken down by rule name. Unlike `@qmx-ignore`, this suppression is otherwise silent: nothing in
the default output indicates it happened.

`--show-suppressed` renders part of this as prose on the text surface.
`--format=suppressed` reports the full composition — all eight suppression
mechanisms, not only these two — as machine-readable JSON; see
[Output Formats](output-formats.md#suppressed). Either `--show-suppressed` or
selecting `--format=suppressed` (including `format: suppressed` in
`qmx.yaml`) is enough to arm the per-rule exclusion capture; you do not need
both. The two surfaces are not otherwise equivalent — see
[suppressed](output-formats.md#suppressed) for what each one shows.

Suppression composition includes eight mechanisms, including produced-finding
`selection` removals. A producer that never ran produced nothing to remove and
is reported separately under `notRun`, not byMechanism. Threshold-audit effects,
formatter truncation and namespace/class drill-down retain their separate roles;
truncation/drill-down change presentation, not underlying finding identity.

### `--no-suppression-annotations`

Report every violation, including the ones `@qmx-ignore` tags suppress:

```bash
bin/qmx check src/ --no-suppression-annotations
```

!!! note "It does not change what a baseline measures"

    The flag affects the report only. A baseline measures the findings your
    configuration and your source annotations leave standing, so a finding an
    `@qmx-ignore` tag removes is never captured into a baseline and never
    compared against one — whether or not this flag is passed.

    The visible consequence: under this flag an annotated finding is shown at
    its **own** severity and is never promoted to an error, because no baseline
    entry covers it. A flag can narrow what a baseline measures
    (`--suppress-path`, `--suppress-namespace`); none can widen it.

---

## Git scope options

Publish findings relative to changed files while retaining project-scoped diagnostics. See [Git Integration](git-integration.md) for the full guide.

### `--report`

Limit publication by Git while preserving selected analysis paths. Non-strict mode also retains relevant namespace/project findings; project-scoped configuration channels remain visible:

```bash
bin/qmx check src/ --report=git:main..HEAD
bin/qmx check src/ --report=git:origin/develop..HEAD
```

### `--report-strict`

Limit code findings to changed files without namespace/project widening. All nine project-scoped configuration channels remain visible even in strict mode:

```bash
bin/qmx check src/ --report=git:main..HEAD --report-strict
```

---

## Execution options

### `--workers`, `-w`

Control parallel processing. Default: auto-detect based on CPU count.

```bash
# Disable parallel processing (single-threaded)
bin/qmx check src/ --workers=1

# Disable parallel processing (sequential)
bin/qmx check src/ --workers=0

# Use exactly 4 workers
bin/qmx check src/ --workers=4
```

!!! tip
    Use `--workers=1` for debugging or single-process environments. `--workers=0` disables parallelism (sequential execution); auto-detect is the default when the option is omitted.

### `--memory-limit`

Set the PHP memory limit for analysis. By default, PHP's `memory_limit` from `php.ini` is used.

The limit governs the worker processes too (see [`--workers`](#--workers--w)), where files are parsed and measured. A worker that runs out of memory fails the file it held: the run reports that file as failed, exits with code 4, and the failure names the limit the workers ran with; PHP's own "Allowed memory size … exhausted" line appears on stderr.

```bash
# Set memory limit to 1GB for large projects
bin/qmx check src/ --memory-limit=1G

# Unlimited memory
bin/qmx check src/ --memory-limit=-1
```

Valid formats: `-1` (unlimited), or a positive integer with optional `K`/`M`/`G` suffix (e.g., `512M`, `2G`). `0` and a leading zero (`010M`, which PHP would read as octal) are refused with exit code 3.

Equivalent YAML: `memory_limit: 1G`

### `--log-file`

Write the run's log to a file, one JSON record per line:

```bash
bin/qmx check src/ --log-file=qmx.log
```

The file is appended to; its parent directory must already exist. Log records
produced during input checks are buffered. The target is claimed together with
the report and profile targets after input checks, then buffered records are
appended. Invalid input leaves the log file untouched.

The same link, descriptor and collision rules as `--output` apply. An unwritable
target is refused with exit code 3. If writing fails during analysis, the logger
remembers the first cause and counts lost records; the command reports an
environment error before publishing the report. A partial line can remain.
An empty or blank value, including `--log-file=$LOG` with an unset variable, is
refused with exit code 3. Leave the option out to write no file log. Only `check`
takes the option.

### `--log-level`

Set the minimum level of log lines, for the `--log-file` file and for the
console:

```bash
bin/qmx check src/ --log-file=qmx.log --log-level=debug
```

Available levels: `debug`, `info`, `warning`, `error`. A value outside them is
refused with exit 3 instead of falling back to a default.

| Written                    | Console                                               | `--log-file` |
| -------------------------- | ----------------------------------------------------- | ------------ |
| nothing                    | `warning`; `info` with `-v`; `debug` with `-vv`       | `info`       |
| a level, with `-v` or more | that level                                            | that level   |
| a level, without `-v`      | that level if stricter than `warning`, else `warning` | that level   |

Without `-v` the level can only narrow the console: `--log-level=error` hides
warnings, while `--log-level=debug --log-file=qmx.log` writes a detailed file and
leaves the terminal as quiet as it was. `-q` silences the console whatever the
level; the file still receives its lines.

### `--no-progress`

Disable the progress bar. Useful in CI pipelines:

```bash
bin/qmx check src/ --no-progress
```

Accepted by every command that shows a progress bar: `check`, `directives`,
`debug:layer-assignment`, `baseline:generate`, `baseline:update`,
`baseline:cleanup` and `baseline:explain`. `graph:export` analyses a tree too
but draws no bar, so it does not take the option.

The progress bar is written to standard error, so the report on standard output
stays machine-readable even on a terminal — `bin/qmx check src/ --format=json >
report.json` produces valid JSON without this flag. It is drawn only when
standard error is a terminal; redirecting standard error silences the bar
without touching the report, rather than writing control bytes into the file.

The bar shares the error stream with detailed logging (`-v`, `-vv`, `-vvv`) and
with warnings emitted during a run. Both are drawn through one owner: a
diagnostic line pushes the bar down and stays on the screen, and the bar is
redrawn beneath it. Raising verbosity therefore does not turn the bar off, and
the bar does not eat log lines.

### `--silent`, `-q`/`--quiet`

Symfony console flags that suppress normal output. Both are accepted by every
command:

```bash
bin/qmx check src/ --silent
bin/qmx check src/ -q
```

!!! warning "`--silent` no longer guarantees zero bytes on every exit code"
    `--silent` and `-q`/`--quiet` currently behave identically: both suppress the
    report on standard output, but a configuration or input refusal (e.g. a path
    that does not exist) is still written to **standard error**. A run refused
    before analysis starts produces 0 bytes on stdout and a human-readable error
    line on stderr, whichever of the two flags was passed:

    ```bash
    bin/qmx check src/DoesNotExist --silent
    # exit code 3, empty stdout, error message on stderr
    ```

    A CI wrapper built on the older assumption that `--silent` means "zero output
    on any exit code" will see that stderr text on a refusal. Redirect standard
    error too (`--silent 2>/dev/null`) if that assumption must hold.

---

<!-- llms:skip-begin -->
## Profiling options

### `--profile`

Enable the internal profiler. Optionally specify a file to save the profile:

```bash
<!-- llms:skip-end -->

# Show profiling summary on screen
bin/qmx check src/ --profile

# Save profile to file
bin/qmx check src/ --profile=profile.json
```

A target that cannot be written is refused with exit code 3 before analysis
starts, by the same rules as [`--output`](#--output--o): a directory or a name
ending in `/`, a file in a directory that does not exist or cannot be written,
or a file that is not writable. An empty `--profile=` is refused the same way. A
write that still fails after the run also exits with code 3, never reported
beside a finished run. The report is already published by then, so the reason
goes to stderr and stdout keeps the report as its only document, even under
`--format=json`.

If cleanup also fails after that completed report, both causes are printed on
stderr and stdout retains one report. An internal failure keeps exit 1; an
environment cleanup failure takes exit 3 over the findings exit code.

### `--profile-format`

Choose the profile export format. Default: `json`. Any other value is refused with exit
code 3 before analysis starts, whether or not `--profile` names a file.

```bash
bin/qmx check src/ --profile=profile.json --profile-format=chrome-tracing
```

Available formats: `json`, `chrome-tracing`.

!!! tip
    Use `chrome-tracing` format and open the file in Chrome DevTools (chrome://tracing) for a visual timeline.

---

## Rule options

### `--disable-rule`

Disable a producer rule, an entire group, or a finding channel. A selector is either an
**exact** name (a producer rule or a channel), or `X.*` for strictly the **descendants** of
`X` — `X` itself is not included; a group is selected only this way (`complexity.*`). A bare
prefix without the star is an error. A channel selector can be narrowed to one level of the aggregation tree with
`:level`, same as `--only-rule`. Disabling one channel keeps its producer active so that other
channels can still be reported. Can be repeated:

```bash
# Disable one rule
bin/qmx check src/ --disable-rule=size.class-count

# Disable all complexity rules
bin/qmx check src/ --disable-rule=complexity.*

# Disable multiple
bin/qmx check src/ --disable-rule=complexity.* --disable-rule=cohesion.lcom

# Disable only one computed finding channel
bin/qmx check src/ --disable-rule=health.complexity
```

!!! tip "Duplication memory failure"
    If PHP exhausts memory during `duplication.clone`, the detector emits a short
    stderr diagnostic with the failure site and current `memory_limit`, recommends
    `--memory-limit` or `memory_limit` in `qmx.yaml`, and exits with code 4. A
    fatal OOM may interrupt report delivery; valid or complete JSON is not promised.
    A late false read also makes the run incomplete with exit 4 and clears partial
    Duplication output. An empty result from that run is not evidence of zero copies.
    To skip detection deliberately, disable `duplication.clone` itself.

### `--only-rule`

Run only matching producer rules or finding channels. A selector is either an **exact** name
(a producer rule or a channel), or `X.*` for strictly its **descendants** — the only way to
select a group, either optionally narrowed to one level of the aggregation tree with `:level`.
A selector carrying a level keeps its producer running, since a producer filtered out would
never emit the level that was asked for. Can be repeated:

```bash
# Run only complexity rules
bin/qmx check src/ --only-rule=complexity.*

# Run two specific rules
bin/qmx check src/ --only-rule=complexity.ccn --only-rule=size.method-count

# Select one channel of a built-in health dimension: producer and channel
# share the name, since each of the six dimensions is its own producer
bin/qmx check src/ --only-rule=health.complexity
```

Selectors must match a registered producer or emitted channel exactly, or resolve an
`X.*` to at least one descendant. Unknown selectors — including a bare prefix without the
star, or an `X.*` that matches nothing — fail closed with exit 3 before stdout receives a
report payload. When the starred spelling would have matched, the refusal names it:

```text
Rule selector "complexity" does not match any registered producer or channel. A bare prefix is not a group: write "complexity.*" to select every rule under "complexity".
```

`computed` is the one bare word that is accepted: it is the name of a producer, not a group.

Likewise, the owner before `:` in `--rule-opt=RULE:OPTION=VALUE` must be an exact
producer rule, not a group or channel — a group or channel there is an error. The same rule
governs the `rules:` YAML section keys.

!!! note "Selection roles are declared per channel"
    `annotation.unresolved-directive` and `annotation.unused-directive` are
    directly selectable. Unsupported/invalid thresholds follow the addressed
    producer. Explicitly disabling annotation.directive stops its
    producer. Root path exclusions and namespace exclusions retain their own
    subject-level applicability; they are not a second selection resolver.

### `--rule-opt`

Override rule options from the command line. Format: `rule-name:option=value`, where
`rule-name` must be an exact producer rule — never a group, never a channel, and never a
wildcard. The same exact-producer constraint governs the `rules:` YAML section keys;
level-qualified selection instead addresses a declared channel code. Can be repeated:

```bash
bin/qmx check src/ --rule-opt=complexity.ccn:callable.warning=15
bin/qmx check src/ --rule-opt=complexity.ccn:callable.error=30
```

All three ways of getting it wrong are refused with exit 3, where the option
pair used to be dropped without a word: a value written without `=VALUE`, a
rule name no registered producer answers to, and an option name that rule does
not accept. The refusal for the last one lists the options the rule does
accept.

`suppress_namespace_channels` is configured in YAML, not through `--rule-opt`: each selector
requires a non-empty list of namespace patterns, while channel-keyed dictionaries are not CLI dotted addresses. Its
keys are channel selectors and follow the same exact-or-`X.*` rule as `@qmx-ignore` — a bare
prefix like `health` is now an error, not a shorthand for `health.*`. A key may add `:namespace`
and no other level: the option is offered namespace aggregates only, so any other level would
name a filter that can never fire.

<!-- llms:skip-begin -->
### Rule-specific shortcut flags

Many rules have dedicated CLI flags for quick rule-option configuration:

=== "Complexity"

| Flag                           | Rule                 | Option             |
| ------------------------------ | -------------------- | ------------------ |
| `--cyclomatic-warning=N`       | complexity.ccn       | callable.warning   |
| `--cyclomatic-error=N`         | complexity.ccn       | callable.error     |
| `--cyclomatic-class-warning=N` | complexity.ccn       | class.max_warning  |
| `--cyclomatic-class-error=N`   | complexity.ccn       | class.max_error    |
| `--cognitive-warning=N`        | complexity.cognitive | callable.warning   |
| `--cognitive-error=N`          | complexity.cognitive | callable.error     |
| `--cognitive-class-warning=N`  | complexity.cognitive | class.max_warning  |
| `--cognitive-class-error=N`    | complexity.cognitive | class.max_error    |
| `--npath-warning=N`            | complexity.npath     | callable.warning   |
| `--npath-error=N`              | complexity.npath     | callable.error     |
| `--npath-class-warning=N`      | complexity.npath     | class.max_warning  |
| `--npath-class-error=N`        | complexity.npath     | class.max_error    |
| `--wmc-warning=N`              | complexity.wmc       | warning            |
| `--wmc-error=N`                | complexity.wmc       | error              |
| `--wmc-exclude-data-classes`   | complexity.wmc       | excludeDataClasses |

=== "Coupling"

| Flag                            | Rule                 | Option                |
| ------------------------------- | -------------------- | --------------------- |
| `--cbo-warning=N`               | coupling.cbo         | class.warning         |
| `--cbo-error=N`                 | coupling.cbo         | class.error           |
| `--cbo-ns-warning=N`            | coupling.cbo         | namespace.warning     |
| `--cbo-ns-error=N`              | coupling.cbo         | namespace.error       |
| `--distance-warning=N`          | coupling.distance    | max_distance_warning  |
| `--distance-error=N`            | coupling.distance    | max_distance_error    |
| `--instability-class-warning=N` | coupling.instability | class.max_warning     |
| `--instability-class-error=N`   | coupling.instability | class.max_error       |
| `--instability-ns-warning=N`    | coupling.instability | namespace.max_warning |
| `--instability-ns-error=N`      | coupling.instability | namespace.max_error   |
| `--class-rank-warning=N`        | coupling.class-rank  | warning               |
| `--class-rank-error=N`          | coupling.class-rank  | error                 |

=== "Size"

| Flag                         | Rule                | Option  |
| ---------------------------- | ------------------- | ------- |
| `--class-count-warning=N`    | size.class-count    | warning |
| `--class-count-error=N`      | size.class-count    | error   |
| `--method-count-warning=N`   | size.method-count   | warning |
| `--method-count-error=N`     | size.method-count   | error   |
| `--property-count-warning=N` | size.property-count | warning |
| `--property-count-error=N`   | size.property-count | error   |

=== "Design"

| Flag                                 | Rule                          | Option              |
| ------------------------------------ | ----------------------------- | ------------------- |
| `--dit-warning=N`                    | design.dit                    | warning             |
| `--dit-error=N`                      | design.dit                    | error               |
| `--lcom-warning=N`                   | cohesion.lcom                 | warning             |
| `--lcom-error=N`                     | cohesion.lcom                 | error               |
| `--lcom-min-methods=N`               | cohesion.lcom                 | minMethods          |
| `--lcom-exclude-readonly`            | cohesion.lcom                 | excludeReadonly     |
| `--lcom-exclude-methods='[NAME]'`    | cohesion.lcom                 | excludeMethods      |
| `--noc-warning=N`                    | design.noc                    | warning             |
| `--noc-error=N`                      | design.noc                    | error               |
| `--param-type-coverage-warning=N`    | design.type-coverage.param    | warning             |
| `--param-type-coverage-error=N`      | design.type-coverage.param    | error               |
| `--return-type-coverage-warning=N`   | design.type-coverage.return   | warning             |
| `--return-type-coverage-error=N`     | design.type-coverage.return   | error               |
| `--property-type-coverage-warning=N` | design.type-coverage.property | warning             |
| `--property-type-coverage-error=N`   | design.type-coverage.property | error               |
| `--property-exclude-readonly`        | size.property-count           | excludeReadonly     |
| `--property-exclude-promoted-only`   | size.property-count           | excludePromotedOnly |

=== "Maintainability"

| Flag                    | Rule               | Option        |
| ----------------------- | ------------------ | ------------- |
| `--mi-warning=N`        | maintainability.mi | warning       |
| `--mi-error=N`          | maintainability.mi | error         |
| `--mi-min-statements=N` | maintainability.mi | minStatements |
| `--mi-exclude-tests`    | maintainability.mi | excludeTests  |

=== "Code Smell"

| Flag                                    | Rule                                 | Option              |
| --------------------------------------- | ------------------------------------ | ------------------- |
| `--constructor-overinjection-warning=N` | code-smell.constructor-overinjection | warning             |
| `--constructor-overinjection-error=N`   | code-smell.constructor-overinjection | error               |
| `--data-class-woc-threshold=N`          | design.data-class                    | wocThreshold        |
| `--data-class-wmc-threshold=N`          | design.data-class                    | wmcThreshold        |
| `--data-class-min-members=N`            | design.data-class                    | minMembers          |
| `--data-class-exclude-readonly`         | design.data-class                    | excludeReadonly     |
| `--data-class-exclude-promoted-only`    | design.data-class                    | excludePromotedOnly |
| `--data-class-exclude-exceptions`       | design.data-class                    | excludeExceptions   |
| `--god-class-wmc-threshold=N`           | design.god-class                     | wmcThreshold        |
| `--god-class-lcom-threshold=N`          | design.god-class                     | lcomThreshold       |
| `--god-class-tcc-threshold=N`           | design.god-class                     | tccThreshold        |
| `--god-class-class-loc-threshold=N`     | design.god-class                     | classLocThreshold   |
| `--god-class-min-criteria=N`            | design.god-class                     | minCriteria         |
| `--god-class-min-methods=N`             | design.god-class                     | minMethods          |
| `--god-class-exclude-readonly`          | design.god-class                     | excludeReadonly     |
| `--long-parameter-list-warning=N`       | code-smell.long-parameter-list       | warning             |
| `--long-parameter-list-error=N`         | code-smell.long-parameter-list       | error               |
| `--long-parameter-list-vo-warning=N`    | code-smell.long-parameter-list       | vo-warning          |
| `--long-parameter-list-vo-error=N`      | code-smell.long-parameter-list       | vo-error            |
| `--unreachable-code-warning=N`          | code-smell.unreachable-code          | warning             |
| `--unreachable-code-error=N`            | code-smell.unreachable-code          | error               |

=== "Architecture"

| Flag                                  | Rule                             | Option       |
| ------------------------------------- | -------------------------------- | ------------ |
| `--circular-deps`                     | architecture.circular-dependency | enabled      |
| `--max-cycle-size=N`                  | architecture.circular-dependency | maxCycleSize |
| `--layer-violation`                   | architecture.layer-violation     | enabled      |
| `--layer-violation-severity=SEVERITY` | architecture.layer-violation     | severity     |
| `--unassigned-class-mode=MODE`        | architecture.unassigned-class    | mode         |

---

<!-- llms:skip-end -->

## Other commands

### baseline:cleanup

Inspect stale candidates in a baseline. Without `--remove`, it only lists them and never writes the file; remove an explicitly reviewed selector as described in [Baseline](baseline.md):

```bash
bin/qmx baseline:cleanup baseline.json src/
bin/qmx baseline:cleanup baseline.json src/ --remove=<selector>
```

### debug:layer-assignment

`--preset=PRESET` applies a named or file preset and can be repeated.
Configured paths receive the same existence and PHP-file checks as the other
measuring commands.


Report which architecture layer a class is assigned to, and every other layer whose criteria would also have matched it (a potential shadow source). See [Inspecting layer assignment for a single class](../rules/architecture.md#debug-layer-assignment) for the full walkthrough.

```bash
bin/qmx debug:layer-assignment 'App\Service\Foo'
bin/qmx debug:layer-assignment 'App\Service\Foo' --config qmx.yaml

# Machine-readable output — for agents and scripts, not for parsing the text report
bin/qmx debug:layer-assignment 'App\Service\Foo' --format=json
```

| Option                | Description                                                       |
| --------------------- | ----------------------------------------------------------------- |
| `-c`, `--config=FILE` | Path to `qmx.yaml` (default: `qmx.yaml` in the current directory) |
| `--format=FORMAT`     | `text` (default) or `json`                                        |

`--format=json` serializes the same resolution the text report renders. The command answers only for classes the run analysed: an FQN naming no analysed declaration — a typo, or a class kept out of the run by `paths`, `exclude` or the generated-file filter — exits with code 3 and the error envelope instead of being classified. Schema:

```json
{
  "meta": {
    "version": "0.26.0",
    "package": "qmx",
    "timestamp": "2026-01-15T10:30:00+00:00",
    "docs": "https://qualimetrix.dev",
    "llmsTxt": "https://qualimetrix.dev/llms.txt"
  },
  "fqn": "App\\Service\\Foo",
  "assigned": { "layer": "any-foo", "criteria": ["pattern \"App\\**\\Foo\""] },
  "contendingMatches": [],
  "shadowed": [
    { "layer": "service", "criteria": ["pattern \"App\\Service\\**\""], "reported": true }
  ],
  "shadowedBy": "any-foo",
  "undecided": [],
  "contenders": [],
  "chainStopsAt": [],
  "hasLayers": true
}
```

- `meta` is the same block `check --format=json` opens with: the tool's `version`, `package`, the run's `timestamp`, and the documentation addresses `docs` and `llmsTxt` (see [Documentation addresses in JSON reports](output-formats.md#documentation-addresses)).
- `assigned` is `null` when no layer matched; then `contendingMatches` and `shadowed` are empty too.
- `shadowed` lists the matches after `shadowedBy`, the first match the run established, in declaration order. Each loses the class whichever way the layers the run could not answer answer, and `reported` says whether `architecture.potential-shadow` reports it. `shadowedBy` is `null` when `shadowed` is empty, and is not `assigned` when a match whose `exclude:` went unanswered stands in front of it.
- `contendingMatches` lists, in the same form, every other match after `assigned`: the matches whose `exclude:` went unanswered and, when one stands in front of it, `shadowedBy`. Which of them owns the class depends on those clauses, so `reported` is always `false`. Together with `shadowed` it is every match the text report lists after the assignment.
- `undecided` names the layers the run could not answer that bear on the assignment, `contenders` the layers that could own the class once they are answered, and `chainStopsAt` where the class's inheritance chain left the analysed paths; all three are empty when the run answered every layer. `assigned: null` beside a non-empty `undecided` means "could not tell", not "no layer claims this class". See [Inspecting layer assignment for a single class](../rules/architecture.md#debug-layer-assignment) for the full rules.
- `hasLayers` distinguishes "no layers configured" (`false`) from "layers configured but none matched this class" (`true` with `assigned: null`).
- On error, `--format=json` prints `{"error": "...", "exit_code": N, "position": ..., "source": ...}` to stdout instead of the human `<error>` line, and an unrecognized `--format` value exits with code 3 regardless of format.

### directives

Report what every inline `@qmx-ignore` and `@qmx-threshold` in the analysed tree actually does. A suppression is judged by what it silenced; a threshold directive is judged by removing it and executing the rules again over the run's own measurements — by default only the rule it addresses, one execution per directive.

```bash
bin/qmx directives src/

# Re-execute every enabled rule instead of just the addressed one — the control the narrow default is measured against
bin/qmx directives src/ --sweep=full

# Machine-readable output — for agents, scripts and CI
bin/qmx directives src/ --format=json
```

| Option                    | Description                                                                              |
| ------------------------- | ---------------------------------------------------------------------------------------- |
| `-c`, `--config=FILE`     | Path to `qmx.yaml` (default: `qmx.yaml` in the current directory)                        |
| `--format=FORMAT`         | `text` (default) or `json`                                                               |
| `--sweep=SCOPE`           | How much of the rule layer each counterfactual re-executes: `narrow` (default) or `full` |
| `--preset=PRESET`         | Apply a named preset (repeatable)                                                        |
| `--only-rule=RULE`        | Judge under a run that ran only these rules (repeatable)                                 |
| `--disable-rule=RULE`     | Judge under a run with these rules off (repeatable)                                      |
| `--rule-opt=RULE:OPT=VAL` | Judge under a run with this rule option (repeatable)                                     |

The four selection options exist because a verdict is relative to the run that produced it: point the command at the same rules and boundaries your CI checks with, or it will answer about a different run.

A `@qmx-threshold` names exactly one rule, so under `--sweep=narrow` a counterfactual re-executes only that rule. `--sweep=full` re-executes every enabled rule for the same verdicts, at far higher cost — it is not a slower fallback but the control that measures, rather than assumes, that removing a directive of one rule cannot move another rule's findings: the two scopes are swept over the same tree and compared verdict for verdict. On this project's own `src/` the narrow sweep is several times cheaper and the two scopes agree on every verdict. Both the text report and `--format=json` state the sweep the verdicts were measured under.

Exit codes: `0` no publishable refusal or observable inert directive, including
a complete intentionally empty excluded set; `2` at least one publishable
refusal or inert directive whose boundary is observable; `3` bad
input/configuration, including truly undiscovered empty input; `4` incomplete
input; `1` unexpected command failure. Incompleteness takes priority.
Text and JSON retain all sites, including refusals whose channels the final
selection does not publish. A measured scope note appears in text and JSON
`scope.note`.

There are five verdicts:

| Verdict               | What it states                                                                                 |
| --------------------- | ---------------------------------------------------------------------------------------------- |
| effective             | It silenced a produced finding or its removal changes the threshold result.                    |
| applied-boundary-only | It applied; only the boundary printed by the finding moved (JSON: `overrun`).                  |
| inert                 | It silenced nothing or its removal changes nothing; exit 2 only if the boundary is observable. |
| unmeasured            | Its producer did not run, or another threshold directive masks it.                             |
| refused               | It could not be admitted or applied, with a nonempty list of refusal details.                  |

Every read tag has one site, independent of its declaration bindings.
Suppression/diagnostic positions distinguish identical comments on one line;
JSON still publishes `file`, `line`, `form` and `target`, not the internal
position. For next-line controls, `line` is the tag line, not the target
after the comment. Refused sites carry required
`refusals: [{channel, message}]`; other effects carry an empty list.
`reason` describes only Unmeasured, with `masked_by` when applicable.
Neither refusal JSON nor site JSON publishes the internal addressed producer.

!!! warning "A verdict is relative to the analysed scope"

    A threshold on a metric computed over the analysed subgraph — coupling above all — can be alive over the whole project and dead over one directory of it, and neither answer is wrong. Point the command at what the project actually analyses. The report prints the scope it measured under, and a run that failed to parse part of the tree exits `4` instead of calling anything dead.

!!! info "Judged against what the rules produced, not against the report"

    `suppress_paths`, `suppress_namespaces` and `suppress_namespace_channels` suppress **publication**, not measurement. A directive that moved a finding inside an excluded namespace still did something, so the audit asks its question against every finding the rules produced, not against the report. The one channel outside that universe is `annotation.unused-directive`, which a run assembles after the rules have run — no directive may address it, so no verdict is judged against it.

    Suppression receives no credit for silencing a configuration error
    (`annotation.unresolved-directive` and its two siblings). Those channels
    are exempt from annotation suppression by construction; admission and
    reach can still refuse a directive before its effect is judged.

    An explicit selector addressing `annotation.unused-directive` or
    `duplication.clone` is **refused**, after reach/level admission. The audit
    carries the same refusal details as `check`. Blanket `*` and bare file
    directives remain effective/inert over other findings and cannot silence
    either banned channel.

The `applied-boundary-only` verdict deliberately makes no claim about direction. The rule layer has no notion of which way is stricter — `coupling.instability` is worse when higher, `cohesion.tcc` when lower — so a directive that tightens a boundary and one that raises a boundary the measured value had already passed are the same observable. In `--format=json` this verdict keeps the stable key `overrun`.

Where a rule publishes no boundary alongside its finding, an `inert` verdict carries a note saying so, and **does not fail the build**: a boundary the value had already passed would have looked identical, so demanding the directive be deleted would report an unasked question as proven debt. `--format=json` reports it as `"boundary_observable": false`.

On error, `--format=json` prints `{"error": "...", "exit_code": N, "position": ..., "source": ...}` to stdout instead of the human `<error>` line.

### graph:export

The command reads the shared configuration document. Without path arguments,
it uses configured or Composer defaults and applies `exclude` and the
`@generated` filter. It accepts `--config`, `--preset`, `--exclude`,
`--include-generated`, `--include-autoload-dev`, `--no-cache`, `--workers`/`-w`
and `--memory-limit`. `--format`/`-f` selects only `dot` or `json`, independently
of the document's analysis `format`. `--direction` has no short alias; global
`-d` changes the working directory.


Export the dependency graph for visualization:

```bash
# Export as DOT (default)
bin/qmx graph:export src/ -o graph.dot

# Export as JSON (aggregated adjacency list with metadata)
bin/qmx graph:export src/ --format=json -o graph.json

# Filter by namespace
bin/qmx graph:export src/ --namespace='subtree:App\Service' --namespace='subtree:App\Repository'

# Exclude namespaces
bin/qmx graph:export src/ --exclude-namespace='subtree:App\Generated'

# Change layout direction
bin/qmx graph:export src/ --direction=TB

# Disable namespace grouping
bin/qmx graph:export src/ --no-clusters
```

| Option                         | Description                                                                        |
| ------------------------------ | ---------------------------------------------------------------------------------- |
| `-o`, `--output=FILE`          | Output file (default: stdout)                                                      |
| `-f`, `--format=FORMAT`        | `dot` (default) or `json`                                                          |
| `--direction=DIR`              | Graph direction: `LR`, `TB`, `RL`, `BT` (default: `LR`)                            |
| `--no-clusters`                | Do not group nodes by namespace                                                    |
| `--namespace=SELECTOR`         | Include only namespaces selected by `exact:`, `subtree:`, or `regex:` (repeatable) |
| `--exclude-namespace=SELECTOR` | Exclude namespaces using the same explicit forms (repeatable)                      |

A `--namespace` value matching no vertex is refused with exit 3 rather than
exporting an empty graph. `--exclude-namespace` keeps its silence on purpose: a
missed exclusion leaves the picture whole, so the viewer loses nothing.

An `--output` target is judged and written the way `check` treats its own
[`--output`](#--output--o): a directory or a name ending in `/`, an unwritable
existing file and a new name in a directory that does not exist or does not
allow creating a file are refused with exit 3 before any file is read; an existing target —
a file, a symbolic link, `/dev/stdout`, a named pipe — is written in place.

If any discovered file fails parsing or processing, `graph:export` exits 4 and
emits no partial graph. It does not create a missing output file and preserves
an existing destination byte-for-byte.

### hook:install

Install a git pre-commit hook:

```bash
bin/qmx hook:install

# Overwrite existing hook
bin/qmx hook:install --force
```

### hook:status

Show the current status of the pre-commit hook:

```bash
bin/qmx hook:status
```

### hook:uninstall

Remove the pre-commit hook:

```bash
bin/qmx hook:uninstall

# Restore the original hook from backup
bin/qmx hook:uninstall --restore-backup
```

### rules

Accepts `--config=FILE` and repeatable `--preset=PRESET`, judges the complete
document and includes named computed metrics. It marks rules disabled by the
current final `only_rules`/`disabled_rules` selection. It requests no analysis
paths, so an empty PHP tree does not prevent listing rules. An invalid document
refuses with exit 3. No separate Console parser of `rules: false` or
`enabled: false` drives this mark; those switches belong to rule resolution.


List all available rules with their descriptions and CLI options:

```bash
# List all rules
bin/qmx rules

# Filter by group
bin/qmx rules --group=complexity
```

**Example output** (for `--group=complexity`):

```
4 rules available

Complexity
  complexity.ccn                           Checks cyclomatic complexity at method and class levels
    complexity.ccn judges complexity.ccn, complexity.ccn.max
    options: enabled, threshold
    options at callable: enabled, error, threshold, warning
    options at class: enabled, max-error, max-warning, threshold
    --cyclomatic-warning (--rule-opt=complexity.ccn:callable.warning=...)
    --cyclomatic-error (--rule-opt=complexity.ccn:callable.error=...)
    --cyclomatic-class-warning (--rule-opt=complexity.ccn:class.max-warning=...)
    --cyclomatic-class-error (--rule-opt=complexity.ccn:class.max-error=...)
  ...

Every rule also takes: suppress-namespace-channels, suppress-namespaces, suppress-paths

Usage: bin/qmx check --disable-rule=<name> | --only-rule=<name>
        bin/qmx check --rule-opt=<name>:<option>=<value>

Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt
```

Rules are grouped by category. `options:` names what the rule accepts in its
own block, and an `options at <level>:` line names what a level slot accepts —
these are the complete set, whether or not an option also has a CLI alias. The
three keys in the footer are legal under every rule. `enabled` is listed with
each rule, including `architecture.unassigned-class`: false disables it even in a reportable mode;
explicit true with `mode: ignore` refuses because the producer would remain inactive.

Each CLI alias is listed with the long `--rule-opt` form it expands to. Default
threshold values are not part of this output — see
[Default thresholds](../reference/default-thresholds.md).

## Typed rule values and final selection

Both `--rule-opt` and dedicated aliases parse the same declared YAML value form.
For example `--lcom-exclude-methods='[getName, getDescription]'` writes a sequence;
CSV or a scalar is not that sequence. Null does not become text. Writing the same
canonical option twice through aliases or `--rule-opt` in one invocation refuses.
A dotted option address traverses only a declared level slot, never arbitrary
channel-keyed dictionaries. Put `suppress_namespace_channels` maps in YAML.

A bare name selects an exact producer or channel; `X.*` selects strict descendants.
A pair `channel-name:level` requires that declared channel code to report at the
specified level. When producer and channel have the same name, it works as a
channel name; there is no differently named producer:level alias. The same witness
must also belong to the supplied producer/set for namespace-channel exclusions.

Higher-layer decisions win before specificity; at the same layer an exact producer
enable beats a group disable. A later exact enable can cancel a lower disable.
An exact enable and disable of the same producer in one layer refuses even if
later overridden. `only_rules` selects a filter; it does not enable an inactive
producer. Empty effective selection, a dead exact selector, an enable outside its
own/lower effective filter and an explicit enable with muted option activity refuse.
A higher disable may intentionally narrow an earlier filter. Empty only_rules
removes a lower filter, while empty maps never reset options.

Most channels are directly selectable. Declared diagnostic roles admit filter-exempt
channels or diagnostics that follow a selected addressed producer. These are not
extra producer enable statements: explicitly disabling their producer still stops
it. `annotation.unresolved-directive` and `annotation.unused-directive` are
directly selectable; unsupported/invalid threshold errors follow the
addressed producer. Fix the source input rather than hiding a configuration error
with an unrelated only filter.

`bin/qmx rules` prints accepted root/level options independently of CLI aliases,
then the effective only filter and all tied decisive disabled texts. It also prints
`Selection source: ... (...; layer N)` from the actual origin and layer index.
One writer repeated across cells prints once; equal text from distinct layers does
not merge. Listing judges the document and stated selection, not effective-band
build/conclude or runtime state. Directive text/JSON preserve all decisive disabling
texts and omit ones canceled by later enable; JSON selection.disabled stays a string
list. For values and layer forms see [Configuration](../getting-started/configuration.md).
