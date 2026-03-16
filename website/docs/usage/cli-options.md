# CLI Options

AI Mess Detector provides the `analyze` command for code analysis and several utility commands for baseline management, git hooks, and dependency graph visualization.

## analyze command

```bash
bin/aimd check [options] [--] [<paths>...]
```

### Paths argument

Specify one or more directories or files to analyze:

```bash
# Analyze specific directories
bin/aimd check src/ lib/

# Analyze a single file
bin/aimd check src/Service/UserService.php
```

If you omit paths, AIMD auto-detects them from the `autoload` section of your `composer.json`.

---

## File options

### `--config`, `-c`

Path to a YAML configuration file:

```bash
bin/aimd check src/ --config=aimd.yaml
```

### `--exclude`

Exclude directories from analysis. Can be repeated:

```bash
bin/aimd check src/ --exclude=src/Generated --exclude=src/Legacy
```

### `--exclude-path`

Suppress violations for files matching a glob pattern. The files are still analyzed (their metrics contribute to namespace-level calculations), but violations are not reported. Can be repeated:

```bash
bin/aimd check src/ --exclude-path="src/Entity/*" --exclude-path="src/DTO/*"
```

---

## Output options

### `--format`, `-f`

Choose the output format. Default: `summary`.

```bash
bin/aimd check src/ --format=json
bin/aimd check src/ --format=sarif
```

Available formats: `summary`, `text`, `text-verbose`, `json`, `metrics-json`, `checkstyle`, `sarif`, `gitlab`, `github`, `html`.

See [Output Formats](output-formats.md) for details on each format.

### `--group-by`

Group violations in the output. Default depends on the formatter.

```bash
bin/aimd check src/ --format=text-verbose --group-by=rule
```

Available values: `none`, `file`, `rule`, `severity`.

### `--format-opt`

Pass formatter-specific options as key=value pairs. Can be repeated:

```bash
bin/aimd check src/ --format-opt=key=value
```

**JSON format options:**

| Option              | Default | Description                          |
| ------------------- | ------- | ------------------------------------ |
| `violations=N\|all` | 50      | Max violations in output (0=none)    |
| `limit=N`           | 50      | Alias for `violations`               |
| `top=N`             | 10      | Number of worst offenders to include |

```bash
bin/aimd check src/ --format=json --format-opt=limit=100
bin/aimd check src/ --format=json --format-opt=violations=all
```

### `--fail-on`

Set the minimum severity that causes a non-zero exit code. Default: all severities cause failure.

```bash
# Only fail on errors, allow warnings
bin/aimd check src/ --fail-on=error

# Explicit: fail on warnings too (default behavior)
bin/aimd check src/ --fail-on=warning
```

This is useful in CI to adopt AIMD gradually — start with `--fail-on=error` and tighten to `--fail-on=warning` once warnings are resolved.

Can also be set in `aimd.yaml`:

```yaml
fail_on: error
```

### `--exclude-health`

Exclude specific health dimensions from scoring. The excluded dimensions are not shown in the health summary and do not contribute to the overall score. Can be repeated:

```bash
# Exclude typing from health scoring
bin/aimd check src/ --exclude-health=typing

# Exclude multiple dimensions
bin/aimd check src/ --exclude-health=typing --exclude-health=maintainability
```

Available dimensions: `complexity`, `cohesion`, `coupling`, `typing`, `maintainability`.

Can also be set in `aimd.yaml`:

```yaml
exclude_health:
  - typing
```

### `--detail`

Show a grouped violation list after the summary. Only affects `summary` format.

```bash
# Default limit (200 violations)
bin/aimd check src/ --detail

# Show all violations (no limit)
bin/aimd check src/ --detail=all

# Custom limit
bin/aimd check src/ --detail=50
```

Auto-enabled when `--namespace` or `--class` is used.

### `--namespace`

Filter output to a specific namespace subtree. Uses boundary-aware prefix matching.

```bash
bin/aimd check src/ --namespace=App\\Service
```

Filters violations and worst offenders to the specified namespace. Shows subtree health scores. Auto-enables `--detail`.

Mutually exclusive with `--class`.

### `--class`

Filter output to a specific class by exact FQCN match.

```bash
bin/aimd check src/ --class=App\\Service\\UserService
```

Filters violations to the specified class. Auto-enables `--detail`.

Mutually exclusive with `--namespace`.

---

## Cache options

AIMD caches parsed ASTs to speed up repeated runs.

### `--no-cache`

Disable caching entirely:

```bash
bin/aimd check src/ --no-cache
```

### `--cache-dir`

Set a custom cache directory. Default: `.aimd-cache`.

```bash
bin/aimd check src/ --cache-dir=/tmp/aimd-cache
```

### `--clear-cache`

Clear the cache before running analysis:

```bash
bin/aimd check src/ --clear-cache
```

---

## Baseline options

Baselines let you ignore known violations and focus on new ones. See [Baseline](baseline.md) for the full guide.

### `--generate-baseline`

Run analysis and save all current violations to a baseline file:

```bash
bin/aimd check src/ --generate-baseline=baseline.json
```

### `--baseline`

Filter out violations that exist in the baseline file:

```bash
bin/aimd check src/ --baseline=baseline.json
```

### `--show-resolved`

Show how many violations from the baseline have been fixed:

```bash
bin/aimd check src/ --baseline=baseline.json --show-resolved
```

### `--baseline-ignore-stale`

By default, AIMD reports an error if the baseline references files that no longer exist. This flag silently ignores stale entries instead:

```bash
bin/aimd check src/ --baseline=baseline.json --baseline-ignore-stale
```

---

## Suppression options

### `--show-suppressed`

Show violations that were suppressed by `@aimd-ignore` tags:

```bash
bin/aimd check src/ --show-suppressed
```

### `--no-suppression`

Ignore all `@aimd-ignore` tags and report every violation:

```bash
bin/aimd check src/ --no-suppression
```

---

## Git scope options

Analyze or report only changed files. See [Git Integration](git-integration.md) for the full guide.

### `--analyze`

Control which files to analyze. Accepts a git scope expression:

```bash
bin/aimd check src/ --analyze=git:staged          # only staged files
bin/aimd check src/ --analyze=git:main..HEAD       # only files changed since main
```

### `--report`

Control which violations to report. Analyzes the full project but only shows violations from changed files:

```bash
bin/aimd check src/ --report=git:main..HEAD
bin/aimd check src/ --report=git:origin/develop..HEAD
```

### `--report-strict`

In diff mode, only show violations from the changed files themselves. Without this flag, violations from parent namespaces are also shown:

```bash
bin/aimd check src/ --report=git:main..HEAD --report-strict
```

---

## Execution options

### `--workers`, `-w`

Control parallel processing. Default: auto-detect based on CPU count.

```bash
# Disable parallel processing (single-threaded)
bin/aimd check src/ --workers=0

# Use exactly 4 workers
bin/aimd check src/ --workers=4
```

!!! tip
    Use `--workers=0` for debugging or when running in environments that do not support `ext-parallel`.

### `--log-file`

Write a debug log to a file:

```bash
bin/aimd check src/ --log-file=aimd.log
```

### `--log-level`

Set the minimum log level. Default: `info`.

```bash
bin/aimd check src/ --log-file=aimd.log --log-level=debug
```

Available levels: `debug`, `info`, `warning`, `error`.

### `--no-progress`

Disable the progress bar. Useful in CI pipelines:

```bash
bin/aimd check src/ --no-progress
```

---

## Profiling options

### `--profile`

Enable the internal profiler. Optionally specify a file to save the profile:

```bash
# Show profiling summary on screen
bin/aimd check src/ --profile

# Save profile to file
bin/aimd check src/ --profile=profile.json
```

### `--profile-format`

Choose the profile export format. Default: `json`.

```bash
bin/aimd check src/ --profile=profile.json --profile-format=chrome-tracing
```

Available formats: `json`, `chrome-tracing`.

!!! tip
    Use `chrome-tracing` format and open the file in Chrome DevTools (chrome://tracing) for a visual timeline.

---

## Rule options

### `--disable-rule`

Disable a specific rule or an entire group by prefix. Can be repeated:

```bash
# Disable one rule
bin/aimd check src/ --disable-rule=size.class-count

# Disable all complexity rules
bin/aimd check src/ --disable-rule=complexity

# Disable multiple
bin/aimd check src/ --disable-rule=complexity --disable-rule=design.lcom
```

!!! tip "Memory optimization"
    Disabling `duplication.code-duplication` also skips the memory-intensive duplication detection phase entirely. On large codebases (500+ files), this can significantly reduce memory usage. Use `--disable-rule=duplication` if you encounter out-of-memory errors.

### `--only-rule`

Run only the specified rules or groups. Can be repeated:

```bash
# Run only complexity rules
bin/aimd check src/ --only-rule=complexity

# Run two specific rules
bin/aimd check src/ --only-rule=complexity.cyclomatic --only-rule=size.method-count
```

### `--rule-opt`

Override rule options from the command line. Format: `rule-name:option=value`. Can be repeated:

```bash
bin/aimd check src/ --rule-opt=complexity.cyclomatic:method.warning=15
bin/aimd check src/ --rule-opt=complexity.cyclomatic:method.error=30
```

### Rule-specific shortcut flags

Many rules have dedicated CLI flags for quick threshold adjustments:

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

| Flag                 | Rule                  | Option       |
| -------------------- | --------------------- | ------------ |
| `--mi-warning=N`     | maintainability.index | warning      |
| `--mi-error=N`       | maintainability.index | error        |
| `--mi-min-loc=N`     | maintainability.index | minLoc       |
| `--mi-exclude-tests` | maintainability.index | excludeTests |

=== "Code Smell"

| Flag                                    | Rule                                 | Option              |
| --------------------------------------- | ------------------------------------ | ------------------- |
| `--constructor-overinjection-warning=N` | code-smell.constructor-overinjection | warning             |
| `--constructor-overinjection-error=N`   | code-smell.constructor-overinjection | error               |
| `--data-class-woc-threshold=N`          | code-smell.data-class                | wocThreshold        |
| `--data-class-wmc-threshold=N`          | code-smell.data-class                | wmcThreshold        |
| `--data-class-min-methods=N`            | code-smell.data-class                | minMethods          |
| `--data-class-exclude-readonly`         | code-smell.data-class                | excludeReadonly     |
| `--data-class-exclude-promoted-only`    | code-smell.data-class                | excludePromotedOnly |
| `--god-class-wmc-threshold=N`           | code-smell.god-class                 | wmcThreshold        |
| `--god-class-lcom-threshold=N`          | code-smell.god-class                 | lcomThreshold       |
| `--god-class-tcc-threshold=N`           | code-smell.god-class                 | tccThreshold        |
| `--god-class-class-loc-threshold=N`     | code-smell.god-class                 | classLocThreshold   |
| `--god-class-min-criteria=N`            | code-smell.god-class                 | minCriteria         |
| `--god-class-min-methods=N`             | code-smell.god-class                 | minMethods          |
| `--god-class-exclude-readonly`          | code-smell.god-class                 | excludeReadonly     |
| `--long-parameter-list-warning=N`       | code-smell.long-parameter-list       | warning             |
| `--long-parameter-list-error=N`         | code-smell.long-parameter-list       | error               |
| `--unreachable-code-warning=N`          | code-smell.unreachable-code          | warning             |
| `--unreachable-code-error=N`            | code-smell.unreachable-code          | error               |

=== "Architecture"

| Flag                 | Rule                             | Option       |
| -------------------- | -------------------------------- | ------------ |
| `--circular-deps`    | architecture.circular-dependency | enabled      |
| `--max-cycle-size=N` | architecture.circular-dependency | maxCycleSize |

---

## Other commands

### baseline:cleanup

Remove stale entries (references to files that no longer exist) from a baseline file:

```bash
bin/aimd baseline:cleanup baseline.json
```

### graph:export

Export the dependency graph for visualization:

```bash
# Export as DOT (default)
bin/aimd graph:export src/ -o graph.dot

# Export as Mermaid
bin/aimd graph:export src/ --format=mermaid -o graph.md

# Filter by namespace
bin/aimd graph:export src/ --namespace=App\\Service --namespace=App\\Repository

# Exclude namespaces
bin/aimd graph:export src/ --exclude-namespace=App\\Generated

# Change layout direction
bin/aimd graph:export src/ --direction=TB

# Disable namespace grouping
bin/aimd graph:export src/ --no-clusters
```

| Option                   | Description                                             |
| ------------------------ | ------------------------------------------------------- |
| `-o`, `--output=FILE`    | Output file (default: stdout)                           |
| `-f`, `--format=FORMAT`  | `dot` (default) or `mermaid`                            |
| `-d`, `--direction=DIR`  | Graph direction: `LR`, `TB`, `RL`, `BT` (default: `LR`) |
| `--no-clusters`          | Do not group nodes by namespace                         |
| `--namespace=NS`         | Include only these namespaces (repeatable)              |
| `--exclude-namespace=NS` | Exclude these namespaces (repeatable)                   |

### hook:install

Install a git pre-commit hook:

```bash
bin/aimd hook:install

# Overwrite existing hook
bin/aimd hook:install --force
```

### hook:status

Show the current status of the pre-commit hook:

```bash
bin/aimd hook:status
```

### hook:uninstall

Remove the pre-commit hook:

```bash
bin/aimd hook:uninstall

# Restore the original hook from backup
bin/aimd hook:uninstall --restore-backup
```

### rules

List all available rules with their descriptions and CLI options:

```bash
# List all rules
bin/aimd rules

# Filter by group
bin/aimd rules --group=complexity
```

**Example output:**

```
complexity.cyclomatic    Cyclomatic complexity (McCabe)
  --cyclomatic-warning=N         method.warning (default: 10)
  --cyclomatic-error=N           method.error (default: 20)
  --cyclomatic-class-warning=N   class.max_warning (default: 30)
  --cyclomatic-class-error=N     class.max_error (default: 50)

complexity.cognitive     Cognitive complexity (SonarSource)
  --cognitive-warning=N          method.warning (default: 15)
  --cognitive-error=N            method.error (default: 30)
  ...
```
