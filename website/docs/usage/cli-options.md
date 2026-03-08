# CLI Options

AI Mess Detector provides the `analyze` command for code analysis and several utility commands for baseline management, git hooks, and dependency graph visualization.

## analyze command

```bash
bin/aimd analyze [options] [--] [<paths>...]
```

### Paths argument

Specify one or more directories or files to analyze:

```bash
# Analyze specific directories
bin/aimd analyze src/ lib/

# Analyze a single file
bin/aimd analyze src/Service/UserService.php
```

If you omit paths, AIMD auto-detects them from the `autoload` section of your `composer.json`.

---

## File options

### `--config`, `-c`

Path to a YAML configuration file:

```bash
bin/aimd analyze src/ --config=aimd.yaml
```

### `--exclude`

Exclude directories from analysis. Can be repeated:

```bash
bin/aimd analyze src/ --exclude=src/Generated --exclude=src/Legacy
```

### `--exclude-path`

Suppress violations for files matching a glob pattern. The files are still analyzed (their metrics contribute to namespace-level calculations), but violations are not reported. Can be repeated:

```bash
bin/aimd analyze src/ --exclude-path="src/Entity/*" --exclude-path="src/DTO/*"
```

---

## Output options

### `--format`, `-f`

Choose the output format. Default: `text`.

```bash
bin/aimd analyze src/ --format=json
bin/aimd analyze src/ --format=sarif
```

Available formats: `text`, `text-verbose`, `json`, `checkstyle`, `sarif`, `gitlab`.

See [Output Formats](output-formats.md) for details on each format.

### `--group-by`

Group violations in the output. Default depends on the formatter.

```bash
bin/aimd analyze src/ --format=text-verbose --group-by=rule
```

Available values: `none`, `file`, `rule`, `severity`.

### `--format-opt`

Pass formatter-specific options as key=value pairs. Can be repeated:

```bash
bin/aimd analyze src/ --format-opt=key=value
```

---

## Cache options

AIMD caches parsed ASTs to speed up repeated runs.

### `--no-cache`

Disable caching entirely:

```bash
bin/aimd analyze src/ --no-cache
```

### `--cache-dir`

Set a custom cache directory. Default: `.aimd-cache`.

```bash
bin/aimd analyze src/ --cache-dir=/tmp/aimd-cache
```

### `--clear-cache`

Clear the cache before running analysis:

```bash
bin/aimd analyze src/ --clear-cache
```

---

## Baseline options

Baselines let you ignore known violations and focus on new ones. See [Baseline](baseline.md) for the full guide.

### `--generate-baseline`

Run analysis and save all current violations to a baseline file:

```bash
bin/aimd analyze src/ --generate-baseline=baseline.json
```

### `--baseline`

Filter out violations that exist in the baseline file:

```bash
bin/aimd analyze src/ --baseline=baseline.json
```

### `--show-resolved`

Show how many violations from the baseline have been fixed:

```bash
bin/aimd analyze src/ --baseline=baseline.json --show-resolved
```

### `--baseline-ignore-stale`

By default, AIMD reports an error if the baseline references files that no longer exist. This flag silently ignores stale entries instead:

```bash
bin/aimd analyze src/ --baseline=baseline.json --baseline-ignore-stale
```

---

## Suppression options

### `--show-suppressed`

Show violations that were suppressed by `@aimd-ignore` tags:

```bash
bin/aimd analyze src/ --show-suppressed
```

### `--no-suppression`

Ignore all `@aimd-ignore` tags and report every violation:

```bash
bin/aimd analyze src/ --no-suppression
```

---

## Git scope options

Analyze or report only changed files. See [Git Integration](git-integration.md) for the full guide.

### `--staged`

Analyze only files staged for commit. Shortcut for `--analyze=git:staged`:

```bash
bin/aimd analyze src/ --staged
```

### `--diff=REF`

Report only violations in files changed compared to a git reference. Shortcut for `--report=git:REF..HEAD`:

```bash
bin/aimd analyze src/ --diff=main
bin/aimd analyze src/ --diff=origin/develop
```

### `--analyze`

Fine-grained control over which files to analyze:

```bash
bin/aimd analyze src/ --analyze=git:staged
bin/aimd analyze src/ --analyze=git:main..HEAD
```

### `--report`

Fine-grained control over which violations to report:

```bash
bin/aimd analyze src/ --report=git:main..HEAD
```

### `--report-strict`

In diff mode, only show violations from the changed files themselves. Without this flag, violations from parent namespaces are also shown:

```bash
bin/aimd analyze src/ --diff=main --report-strict
```

---

## Execution options

### `--workers`, `-w`

Control parallel processing. Default: auto-detect based on CPU count.

```bash
# Disable parallel processing (single-threaded)
bin/aimd analyze src/ --workers=0

# Use exactly 4 workers
bin/aimd analyze src/ --workers=4
```

!!! tip
    Use `--workers=0` for debugging or when running in environments that do not support `ext-parallel`.

### `--log-file`

Write a debug log to a file:

```bash
bin/aimd analyze src/ --log-file=aimd.log
```

### `--log-level`

Set the minimum log level. Default: `info`.

```bash
bin/aimd analyze src/ --log-file=aimd.log --log-level=debug
```

Available levels: `debug`, `info`, `warning`, `error`.

### `--no-progress`

Disable the progress bar. Useful in CI pipelines:

```bash
bin/aimd analyze src/ --no-progress
```

---

## Profiling options

### `--profile`

Enable the internal profiler. Optionally specify a file to save the profile:

```bash
# Show profiling summary on screen
bin/aimd analyze src/ --profile

# Save profile to file
bin/aimd analyze src/ --profile=profile.json
```

### `--profile-format`

Choose the profile export format. Default: `json`.

```bash
bin/aimd analyze src/ --profile=profile.json --profile-format=chrome-tracing
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
bin/aimd analyze src/ --disable-rule=size.class-count

# Disable all complexity rules
bin/aimd analyze src/ --disable-rule=complexity

# Disable multiple
bin/aimd analyze src/ --disable-rule=complexity --disable-rule=design.lcom
```

### `--only-rule`

Run only the specified rules or groups. Can be repeated:

```bash
# Run only complexity rules
bin/aimd analyze src/ --only-rule=complexity

# Run two specific rules
bin/aimd analyze src/ --only-rule=complexity.cyclomatic --only-rule=size.method-count
```

### `--rule-opt`

Override rule options from the command line. Format: `rule-name:option=value`. Can be repeated:

```bash
bin/aimd analyze src/ --rule-opt=complexity.cyclomatic:method.warning=15
bin/aimd analyze src/ --rule-opt=complexity.cyclomatic:method.error=30
```

### Rule-specific shortcut flags

Many rules have dedicated CLI flags for quick threshold adjustments:

=== "Complexity"

    | Flag | Rule | Option |
    |------|------|--------|
    | `--cc-warning=N` | complexity.cyclomatic | method.warning |
    | `--cc-error=N` | complexity.cyclomatic | method.error |
    | `--cc-class-warning=N` | complexity.cyclomatic | class.max_warning |
    | `--cc-class-error=N` | complexity.cyclomatic | class.max_error |
    | `--cognitive-warning=N` | complexity.cognitive | method.warning |
    | `--cognitive-error=N` | complexity.cognitive | method.error |
    | `--cognitive-class-warning=N` | complexity.cognitive | class.max_warning |
    | `--cognitive-class-error=N` | complexity.cognitive | class.max_error |
    | `--npath-warning=N` | complexity.npath | method.warning |
    | `--npath-error=N` | complexity.npath | method.error |
    | `--npath-class-warning=N` | complexity.npath | class.max_warning |
    | `--npath-class-error=N` | complexity.npath | class.max_error |
    | `--wmc-warning=N` | complexity.wmc | warning |
    | `--wmc-error=N` | complexity.wmc | error |

=== "Coupling"

    | Flag | Rule | Option |
    |------|------|--------|
    | `--cbo-class-warning=N` | coupling.cbo | class.warning |
    | `--cbo-class-error=N` | coupling.cbo | class.error |
    | `--cbo-ns-warning=N` | coupling.cbo | namespace.warning |
    | `--cbo-ns-error=N` | coupling.cbo | namespace.error |
    | `--distance-warning=N` | coupling.distance | max_distance_warning |
    | `--distance-error=N` | coupling.distance | max_distance_error |
    | `--coupling-class-warning=N` | coupling.instability | class.max_warning |
    | `--coupling-class-error=N` | coupling.instability | class.max_error |
    | `--coupling-ns-warning=N` | coupling.instability | namespace.max_warning |
    | `--coupling-ns-error=N` | coupling.instability | namespace.max_error |

=== "Size"

    | Flag | Rule | Option |
    |------|------|--------|
    | `--ns-warning=N` | size.class-count | warning |
    | `--ns-error=N` | size.class-count | error |
    | `--size-class-warning=N` | size.method-count | warning |
    | `--size-class-error=N` | size.method-count | error |

=== "Design"

    | Flag | Rule | Option |
    |------|------|--------|
    | `--dit-warning=N` | design.inheritance | warning |
    | `--dit-error=N` | design.inheritance | error |
    | `--lcom-warning=N` | design.lcom | warning |
    | `--lcom-error=N` | design.lcom | error |
    | `--lcom-min-methods=N` | design.lcom | minMethods |
    | `--noc-warning=N` | design.noc | warning |
    | `--noc-error=N` | design.noc | error |

=== "Maintainability"

    | Flag | Rule | Option |
    |------|------|--------|
    | `--mi-warning=N` | maintainability.index | warning |
    | `--mi-error=N` | maintainability.index | error |
    | `--mi-min-loc=N` | maintainability.index | minLoc |

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

| Option | Description |
|--------|-------------|
| `-o`, `--output=FILE` | Output file (default: stdout) |
| `-f`, `--format=FORMAT` | `dot` (default) or `mermaid` |
| `-d`, `--direction=DIR` | Graph direction: `LR`, `TB`, `RL`, `BT` (default: `LR`) |
| `--no-clusters` | Do not group nodes by namespace |
| `--namespace=NS` | Include only these namespaces (repeatable) |
| `--exclude-namespace=NS` | Exclude these namespaces (repeatable) |

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
