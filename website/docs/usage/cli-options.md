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

If you omit paths, Qualimetrix auto-detects them from the `autoload` section of your `composer.json`.

---

## File options

### `--config`, `-c`

Path to a YAML configuration file:

```bash
bin/qmx check src/ --config=qmx.yaml
```

### `--exclude`

Exclude directories from analysis. Can be repeated:

```bash
bin/qmx check src/ --exclude=src/Generated --exclude=src/Legacy
```

### `--include-generated`

By default, Qualimetrix automatically skips files that contain a `@generated` annotation in the first 2 KB. This flag overrides that behavior and includes generated files in the analysis:

```bash
bin/qmx check src/ --include-generated
```

Can also be set in `qmx.yaml`:

```yaml
include_generated: true
```

### `--exclude-path`

Suppress violations for files matching a glob pattern. The files are still analyzed (their metrics contribute to namespace-level calculations), but violations are not reported. Can be repeated:

```bash
bin/qmx check src/ --exclude-path="src/Entity/*" --exclude-path="src/DTO/*"
```

Merged with `exclude_paths` from `qmx.yaml` — both sources are combined.

!!! warning "Does not apply to `architecture.*` rules"
    `architecture.layer-violation` and `architecture.circular-dependency` violations are never
    suppressed by this option — see [Exclude Paths](../getting-started/configuration.md#exclude-paths)
    for why and for the alternatives.

### `--exclude-namespace`

Suppress violations for classes in namespaces matching a prefix or glob pattern. The classes are still analyzed (their metrics contribute to aggregated calculations), but violations are not reported. Can be repeated:

```bash
bin/qmx check src/ --exclude-namespace="App\Entity" --exclude-namespace="App\DTO\*"
```

Merged with `exclude_namespaces` from `qmx.yaml` — both sources are combined.

!!! warning "Does not apply to `architecture.*` rules"
    `architecture.layer-violation` and `architecture.circular-dependency` violations are never
    suppressed by this option — see [Exclude Namespaces](../getting-started/configuration.md#exclude-namespaces)
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

Available formats: `summary`, `text`, `text-verbose`, `json`, `metrics`, `checkstyle`, `sarif`, `gitlab`, `github`, `health`, `html`.

See [Output Formats](output-formats.md) for details on each format.

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

**JSON format options:**

| Option              | Default | Description                          |
| ------------------- | ------- | ------------------------------------ |
| `violations=N\|all` | all     | Max violations in output (0=none)    |
| `limit=N`           | all     | Alias for `violations`               |
| `top=N`             | 10      | Number of worst offenders to include |

```bash
bin/qmx check src/ --format=json --format-opt=limit=100
bin/qmx check src/ --format=json --format-opt=violations=all
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

Show a grouped violation list after the summary. Only affects `summary` format.

```bash
# Default limit (200 violations)
bin/qmx check src/ --detail

# Show all violations (no limit)
bin/qmx check src/ --detail=all

# Custom limit
bin/qmx check src/ --detail=50
```

Auto-enabled when `--namespace` or `--class` is used.

### `--all`

Show all violations without truncation. This is a shorthand for `--format-opt=violations=all --detail=all`.

```bash
# Show all violations in JSON format
bin/qmx check src/ --format=json --all

# Show all violations in summary format
bin/qmx check src/ --all
```

Cannot be combined with `--format-opt=violations=N` (numeric limit) — this produces a clear error. Combining `--all` with `--format-opt=violations=all` is allowed (they are synonyms).

### `--namespace`

Filter output to a specific namespace subtree. Uses boundary-aware prefix matching.

```bash
bin/qmx check src/ --namespace=App\\Service
```

Filters violations and worst offenders to the specified namespace. Shows subtree health scores. Auto-enables `--detail`.

Mutually exclusive with `--class`.

### `--class`

Filter output to a specific class by exact FQCN match.

```bash
bin/qmx check src/ --class=App\\Service\\UserService
```

Filters violations to the specified class. Auto-enables `--detail`.

Mutually exclusive with `--namespace`.

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

### `--clear-cache`

Clear the cache before running analysis:

```bash
bin/qmx check src/ --clear-cache
```

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

The commands below are the only baseline write and migration surface:

```text
bin/qmx baseline:generate <baseline> [<paths>...] [--mode=MODE] [--force]
bin/qmx baseline:migrate  <baseline> [<paths>...] [--force]
bin/qmx baseline:update   <baseline> [<paths>...] [--force]
bin/qmx baseline:cleanup  <baseline> [<paths>...] [--remove=REMOVE]... [--force]
bin/qmx baseline:explain  <symbol> [<paths>...] [--baseline=BASELINE] [--channel=CHANNEL]
```

All five commands accept `--config=CONFIG`, `--preset=PRESET`, `--disable-rule=DISABLE-RULE`, `--only-rule=ONLY-RULE`, and `--rule-opt=RULE-OPT`. They do not accept any exclusion or suppression option.

- `baseline:generate` captures the current measured findings. `--mode=ratchet` is the default; `--mode=suppress` records unconditional acceptance for captured identities. Its `--force` overwrites an existing file.
- `baseline:migrate` converts only a v5 file by making a fresh v10 capture. Its `--force` replaces a destination that is not v5 with that fresh capture.
- `baseline:update` tightens existing entries only. Its `--force` overrides the recorded-scope coverage guard.
- `baseline:cleanup` lists candidates by default and removes only repeated `--remove=REMOVE` selectors. Its `--force` also overrides the scope guard.
- `baseline:explain` shows the configured threshold, accepted baseline level, and source override for a canonical symbol; `--channel=CHANNEL` narrows the answer.

All lifecycle commands refuse incomplete analysis with exit 4 before interpreting
or writing a baseline. `--force` overrides file/scope guards only; it cannot make
a partial measured set acceptable. Existing destinations remain byte-identical,
and `baseline:generate` does not create a missing destination.

The removed `--generate-baseline` and `--baseline-ignore-stale` options have no aliases. Use `baseline:generate` and explicit `baseline:cleanup --remove` instead.

---

## Suppression options

### `--show-suppressed`

Show violations that were suppressed by `@qmx-ignore` tags, and violations suppressed by a
per-rule `exclude_namespaces` / `exclude_namespace_channels` / `exclude_paths` entry in `qmx.yaml` (see
[Rules](../getting-started/configuration.md#rules)):

```bash
bin/qmx check src/ --show-suppressed
```

Independently of `--show-suppressed`, running with `-v` prints a per-rule count of how many
violations were suppressed this way. The namespace bucket includes both namespace options and
is separate from `exclude_paths`; each is broken down by rule name. Unlike `@qmx-ignore`, this suppression is otherwise silent: nothing in
the default output indicates it happened.

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
    (`--exclude-path`, `--exclude-namespace`); none can widen it.

---

## Git scope options

Report only violations from changed files. See [Git Integration](git-integration.md) for the full guide.

### `--report`

Control which violations to report. Analyzes the full project but only shows violations from changed files:

```bash
bin/qmx check src/ --report=git:main..HEAD
bin/qmx check src/ --report=git:origin/develop..HEAD
```

### `--report-strict`

In diff mode, only show violations from the changed files themselves. Without this flag, violations from parent namespaces are also shown:

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

# Auto-detect worker count
bin/qmx check src/ --workers=0

# Use exactly 4 workers
bin/qmx check src/ --workers=4
```

!!! tip
    Use `--workers=1` for debugging or single-process environments. `--workers=0` means auto-detect, not sequential execution.

### `--memory-limit`

Set the PHP memory limit for analysis. By default, PHP's `memory_limit` from `php.ini` is used.

```bash
# Set memory limit to 1GB for large projects
bin/qmx check src/ --memory-limit=1G

# Unlimited memory
bin/qmx check src/ --memory-limit=-1
```

Valid formats: `-1` (unlimited), or a positive integer with optional `K`/`M`/`G` suffix (e.g., `512M`, `2G`).

Equivalent YAML: `memory_limit: 1G`

### `--log-file`

Write a debug log to a file:

```bash
bin/qmx check src/ --log-file=qmx.log
```

### `--log-level`

Set the minimum log level. Default: `info`.

```bash
bin/qmx check src/ --log-file=qmx.log --log-level=debug
```

Available levels: `debug`, `info`, `warning`, `error`.

### `--no-progress`

Disable the progress bar. Useful in CI pipelines:

```bash
bin/qmx check src/ --no-progress
```

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

### `--profile-format`

Choose the profile export format. Default: `json`.

```bash
bin/qmx check src/ --profile=profile.json --profile-format=chrome-tracing
```

Available formats: `json`, `chrome-tracing`.

!!! tip
    Use `chrome-tracing` format and open the file in Chrome DevTools (chrome://tracing) for a visual timeline.

---

## Rule options

### `--disable-rule`

Disable a producer rule, an entire group, or a finding channel by prefix. Channel selectors
use the same bare-name or explicit `ruleName#violationCode` forms as `--only-rule`. Disabling
one channel keeps its producer active so that other channels can still be reported. Can be repeated:

```bash
# Disable one rule
bin/qmx check src/ --disable-rule=size.class-count

# Disable all complexity rules
bin/qmx check src/ --disable-rule=complexity

# Disable multiple
bin/qmx check src/ --disable-rule=complexity --disable-rule=design.lcom

# Disable only one computed finding channel
bin/qmx check src/ --disable-rule=health.complexity
```

!!! tip "Memory optimization"
    Disabling `duplication.code-duplication` also skips the memory-intensive duplication detection phase entirely. On large codebases (500+ files), this can significantly reduce memory usage. Use `--disable-rule=duplication` if you encounter out-of-memory errors.

### `--only-rule`

Run only matching producer rules or finding channels. A bare selector matches a producer
name, channel `ruleName`, or `violationCode` by prefix. Use
`ruleName#violationCode` for an explicit full channel. Can be repeated:

```bash
# Run only complexity rules
bin/qmx check src/ --only-rule=complexity

# Run two specific rules
bin/qmx check src/ --only-rule=complexity.cyclomatic --only-rule=size.method-count

# Select one channel emitted by the computed.health producer
bin/qmx check src/ --only-rule=health.complexity
bin/qmx check src/ --only-rule=computed.health#health.complexity
```

Selectors must match a registered producer, group, or emitted channel. Unknown
selectors fail closed with exit 3 before stdout receives a report payload.
Likewise, the owner before `:` in `--rule-opt=RULE:OPTION=VALUE` must be an exact
producer rule, not a group or channel.

### `--rule-opt`

Override rule options from the command line. Format: `rule-name:option=value`. Can be repeated:

```bash
bin/qmx check src/ --rule-opt=complexity.cyclomatic:method.warning=15
bin/qmx check src/ --rule-opt=complexity.cyclomatic:method.error=30
```

`exclude_namespace_channels` is configured in YAML, not through `--rule-opt`: each selector
requires a non-empty list of namespace patterns, while `--rule-opt` carries scalar values.

<!-- llms:skip-begin -->
### Rule-specific shortcut flags

Many rules have dedicated CLI flags for quick rule-option configuration:

=== "Complexity"

| Flag                           | Rule                  | Option            |
| ------------------------------ | --------------------- | ----------------- |
| `--cyclomatic-warning=N`       | complexity.cyclomatic | method.warning    |
| `--cyclomatic-error=N`         | complexity.cyclomatic | method.error      |
| `--cyclomatic-class-warning=N` | complexity.cyclomatic | class.max_warning |
| `--cyclomatic-class-error=N`   | complexity.cyclomatic | class.max_error   |
| `--cognitive-warning=N`        | complexity.cognitive  | method.warning    |
| `--cognitive-error=N`          | complexity.cognitive  | method.error      |
| `--cognitive-class-warning=N`  | complexity.cognitive  | class.max_warning |
| `--cognitive-class-error=N`    | complexity.cognitive  | class.max_error   |
| `--npath-warning=N`            | complexity.npath      | method.warning    |
| `--npath-error=N`              | complexity.npath      | method.error      |
| `--npath-class-warning=N`      | complexity.npath      | class.max_warning |
| `--npath-class-error=N`        | complexity.npath      | class.max_error   |
| `--wmc-warning=N`              | complexity.wmc        | warning           |
| `--wmc-error=N`                | complexity.wmc        | error             |

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

=== "Size"

| Flag                       | Rule              | Option  |
| -------------------------- | ----------------- | ------- |
| `--class-count-warning=N`  | size.class-count  | warning |
| `--class-count-error=N`    | size.class-count  | error   |
| `--method-count-warning=N` | size.method-count | warning |
| `--method-count-error=N`   | size.method-count | error   |

=== "Design"

| Flag                                 | Rule                 | Option              |
| ------------------------------------ | -------------------- | ------------------- |
| `--dit-warning=N`                    | design.inheritance   | warning             |
| `--dit-error=N`                      | design.inheritance   | error               |
| `--lcom-warning=N`                   | design.lcom          | warning             |
| `--lcom-error=N`                     | design.lcom          | error               |
| `--lcom-min-methods=N`               | design.lcom          | minMethods          |
| `--lcom-exclude-readonly`            | design.lcom          | excludeReadonly     |
| `--noc-warning=N`                    | design.noc           | warning             |
| `--noc-error=N`                      | design.noc           | error               |
| `--type-coverage-param-warning=N`    | design.type-coverage | param_warning       |
| `--type-coverage-param-error=N`      | design.type-coverage | param_error         |
| `--type-coverage-return-warning=N`   | design.type-coverage | return_warning      |
| `--type-coverage-return-error=N`     | design.type-coverage | return_error        |
| `--type-coverage-property-warning=N` | design.type-coverage | property_warning    |
| `--type-coverage-property-error=N`   | design.type-coverage | property_error      |
| `--property-exclude-readonly`        | size.property-count  | excludeReadonly     |
| `--property-exclude-promoted-only`   | size.property-count  | excludePromotedOnly |

=== "Maintainability"

| Flag                    | Rule                  | Option        |
| ----------------------- | --------------------- | ------------- |
| `--mi-warning=N`        | maintainability.index | warning       |
| `--mi-error=N`          | maintainability.index | error         |
| `--mi-min-statements=N` | maintainability.index | minStatements |
| `--mi-exclude-tests`    | maintainability.index | excludeTests  |

=== "Code Smell"

| Flag                                    | Rule                                 | Option              |
| --------------------------------------- | ------------------------------------ | ------------------- |
| `--constructor-overinjection-warning=N` | code-smell.constructor-overinjection | warning             |
| `--constructor-overinjection-error=N`   | code-smell.constructor-overinjection | error               |
| `--data-class-woc-threshold=N`          | design.data-class                    | wocThreshold        |
| `--data-class-wmc-threshold=N`          | design.data-class                    | wmcThreshold        |
| `--data-class-min-methods=N`            | design.data-class                    | minMethods          |
| `--data-class-exclude-readonly`         | design.data-class                    | excludeReadonly     |
| `--data-class-exclude-promoted-only`    | design.data-class                    | excludePromotedOnly |
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

| Flag                                                    | Rule                             | Option                     |
| ------------------------------------------------------- | -------------------------------- | -------------------------- |
| `--circular-deps`                                       | architecture.circular-dependency | enabled                    |
| `--max-cycle-size=N`                                    | architecture.circular-dependency | maxCycleSize               |
| `--layer-violation`                                     | architecture.layer-violation     | enabled                    |
| `--layer-violation-severity=SEVERITY`                   | architecture.layer-violation     | severity                   |
| `--layer-violation-unreachable-layer-severity=SEVERITY` | architecture.layer-violation     | unreachable_layer_severity |
| `--layer-violation-potential-shadow-severity=SEVERITY`  | architecture.layer-violation     | potential_shadow_severity  |
| `--layer-violation-empty-template-severity=SEVERITY`    | architecture.layer-violation     | empty_template_severity    |

---

<!-- llms:skip-end -->

## Other commands

### baseline:cleanup

Inspect stale candidates in a baseline. Without `--remove`, it only lists them and never writes the file; remove an explicitly reviewed selector as described in [Baseline](baseline.md):

```bash
bin/qmx baseline:cleanup baseline.json src/
bin/qmx baseline:cleanup baseline.json src/ --remove=<selector>
```

### graph:export

Export the dependency graph for visualization:

```bash
# Export as DOT (default)
bin/qmx graph:export src/ -o graph.dot

# Export as JSON (aggregated adjacency list with metadata)
bin/qmx graph:export src/ --format=json -o graph.json

# Filter by namespace
bin/qmx graph:export src/ --namespace=App\\Service --namespace=App\\Repository

# Exclude namespaces
bin/qmx graph:export src/ --exclude-namespace=App\\Generated

# Change layout direction
bin/qmx graph:export src/ --direction=TB

# Disable namespace grouping
bin/qmx graph:export src/ --no-clusters
```

| Option                   | Description                                             |
| ------------------------ | ------------------------------------------------------- |
| `-o`, `--output=FILE`    | Output file (default: stdout)                           |
| `-f`, `--format=FORMAT`  | `dot` (default) or `json`                               |
| `-d`, `--direction=DIR`  | Graph direction: `LR`, `TB`, `RL`, `BT` (default: `LR`) |
| `--no-clusters`          | Do not group nodes by namespace                         |
| `--namespace=NS`         | Include only these namespaces (repeatable)              |
| `--exclude-namespace=NS` | Exclude these namespaces (repeatable)                   |

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
  complexity.cognitive                     Checks cognitive complexity at method and class levels
    --cognitive-warning (--rule-opt=complexity.cognitive:method.warning=...)
    --cognitive-error (--rule-opt=complexity.cognitive:method.error=...)
    --cognitive-class-warning (--rule-opt=complexity.cognitive:class.max_warning=...)
    --cognitive-class-error (--rule-opt=complexity.cognitive:class.max_error=...)
  complexity.cyclomatic                    Checks cyclomatic complexity at method and class levels
    --cyclomatic-warning (--rule-opt=complexity.cyclomatic:method.warning=...)
    --cyclomatic-error (--rule-opt=complexity.cyclomatic:method.error=...)
    --cyclomatic-class-warning (--rule-opt=complexity.cyclomatic:class.max_warning=...)
    --cyclomatic-class-error (--rule-opt=complexity.cyclomatic:class.max_error=...)
  ...

Usage: bin/qmx check --disable-rule=<name> | --only-rule=<name>
        bin/qmx check --rule-opt=<name>:<option>=<value>
```

Rules are grouped by category, and each CLI alias is listed with the long
`--rule-opt` form it expands to. Default threshold values are not part of this
output — see [Default thresholds](../reference/default-thresholds.md).
