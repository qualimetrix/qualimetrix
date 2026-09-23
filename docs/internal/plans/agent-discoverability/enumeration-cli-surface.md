# Enumeration of the Qualimetrix public CLI surface and cross-check against `website/docs/llms.txt`

Branch at capture time: `claude/tool-user-documentation-52d014`, commit `f9ef589c`.

This is a document of facts, not a plan and not an edit to `llms.txt`. No existing project file has been changed.

## How it was obtained and what this method does not see

| #   | Group                      | Capture tool                                                                                                                                                                                                           | Blind spots of the method                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| --- | -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Command names              | The `ContainerCommandLoader` map in `bin/qmx` (literal file read)                                                                                                                                                      | Will not show a command registered outside this map (for example, if a second `setCommandLoader` appears, or a command is added directly via `$application->add()`); does not distinguish a command from an alias, should one appear; Symfony's built-in commands (`list`, `help`, `completion`) are not in the map — they are not part of the product, which is exactly what needed to be excluded.                                                                                                                                                                                                                      |
| 2   | Command options            | Running `php bin/qmx <command> --help` for each of the 13 commands                                                                                                                                                     | Shows only options registered through `InputDefinition` — will not show an option the code reads directly from `$_SERVER`/the environment or from a config file without declaring it through Symfony Console; does not show hidden/undocumented aliases if any are set up without a description; does not show value semantics (the valid values of `--format` for each command had to be taken from the description text, not from a list of choices).                                                                                                                                                                   |
| 3   | Configuration keys         | Literal reading of `ConfigSchema::ENTRIES` in `src/Analysis/Configuration/ConfigSchema.php`                                                                                                                            | `ENTRIES` is not the sole source of root keys: `ConfigSchema::DOCUMENT_ROOTS` adds `coupling`, `computedMetrics`, `excludeHealth` as allowed roots of `qmx.yaml`, but they have no entry of their own in `ENTRIES` (except `architecture`, which is in both) — this method would miss such a key unless `DOCUMENT_ROOTS` is checked separately (which I did, see group 3, the three rows marked "doubtful"). The method also does not check that a key from `ENTRIES` actually affects anything further down the pipeline — only that it is declared by the schema.                                                       |
| 4   | Rule identifiers           | Running `php bin/qmx rules`                                                                                                                                                                                            | The `rules` command lists only what is registered in `RuleConfigurator`/the child configurators for the current build; a rule disabled by default but registered will still show up (every rule in the output has an `enabled` flag), while a rule forgotten during registration in a capability configurator (a registration bug) will not show up and will not be caught by this method. Hidden CLI-flag aliases (for example, `--cyclomatic-warning` as sugar over `--rule-opt=complexity.ccn:callable.warning=`) are shown, but completeness is likewise not guaranteed if such sugar is not set up for a given rule. |
| 5   | Inline `@qmx-*` directives | `grep -rohE '@qmx-[a-z-]+' src/` across all of `src/`, cross-checked by directly reading `src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php` and `Directive/DirectiveNameHints.php`                         | A grep over literal text finds a directive only if its prefix is written in the code as a string literal in exactly this form; it will not find a directive assembled by string concatenation, and it does not distinguish a directive that is actually used from one merely mentioned as an example in a comment/docblock (such cases had to be manually filtered out by context). It also does not check that each directive found is actually active in the current version (not marked as removed while still left in a docblock example).                                                                            |
| 6   | Output formats             | Reading `src/Reporting/Formatter/FormatterRegistry.php` (the `getAvailableNames()` method and the `HIDDEN_FORMATTERS` constant) plus a `grep` over all classes implementing `FormatterInterface` and their `getName()` | The `--help` text of the `check` command is a hand-written string in the command's code, not a reflection of the registry: it manually lists 11 formats and does not include `text-verbose`, which the registry keeps as `HIDDEN_FORMATTERS` (deprecated but functional). Had I captured formats from `--help`, I would have silently lost `text-verbose`. Remaining blind spot of the method: it does not check whether a formatter is actually registered in the DI container at runtime (a static code review was used, not a runtime container dump).                                                                 |
| 7   | Presets                    | Reading `src/Analysis/Configuration/Preset/PresetResolver.php`, the `BUILT_IN_PRESETS` constant                                                                                                                        | Will not see a preset added outside this constant (for example, via a separate alias mechanism, should one appear); does not check that the preset's YAML file (`ci.yaml`/`legacy.yaml`/`strict.yaml`) physically exists and is valid — only that the name is declared as built-in.                                                                                                                                                                                                                                                                                                                                       |

Additionally, for group 2: matching an option against `llms.txt` was not done with a blanket grep over the whole file (that produces false positives — for example, the line `--namespace='subtree:App\Service'` belongs only to `check`, but a naive substring search would also match it for `graph:export --namespace`, which has a different meaning). Every row of the group 2 tables was cross-checked manually: `verbatim`/`semantic` is assigned only when the option's spelling appears in an example or phrase directly tied to that specific command.

## Summary numbers

| Group                                                                      | Total                                                     | Missing from llms.txt (`no`) | `semantic` | `verbatim` |
| -------------------------------------------------------------------------- | --------------------------------------------------------- | ---------------------------- | ---------- | ---------- |
| 1. Commands (13 from the `ContainerCommandLoader` map)                     | 13                                                        | 8                            | 0          | 5          |
| 2. Command options (13 commands)                                           | 88                                                        | 78                           | 1          | 9          |
| 3. Configuration keys (`ConfigSchema::ENTRIES` + additional doubtful rows) | 19 (16 from `ENTRIES` + 3 doubtful from `DOCUMENT_ROOTS`) | 19                           | 0          | 0          |
| 4. Rule identifiers (`bin/qmx rules`)                                      | 54                                                        | 10                           | 43         | 1          |
| 5. Inline `@qmx-*` directives                                              | 4                                                         | 2                            | 0          | 2          |
| 6. Output formats                                                          | 12 (11 public + 1 hidden `text-verbose`)                  | 1                            | 0          | 11         |
| 7. Presets                                                                 | 3                                                         | 3                            | 0          | 0          |

**Total across all seven groups:** 13+88+19+54+4+12+3 = 193 surface elements; of these, only 5 commands (13−8), 10 options (88−78), 0 configuration keys, 44 rules (54−10, of which only 1 by exact id), 2 directives (4−2), 11 formats (12−1), and 0 presets are mentioned in `llms.txt` literally or in substance — **72 of 193 (≈37%) in total**, and almost all of it is "semantic" mentions by group/example (43 of the 44 "present" rules) rather than an addressed list.

---

## 1. Command names

Source: the `$application->setCommandLoader(new ContainerCommandLoader($container, [...]))` map in `bin/qmx` (the lines after `use ... ContainerCommandLoader`). Exactly 13 entries.

| Command                    | In llms.txt | What the agent won't learn if `no`                                                                                                                    |
| -------------------------- | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `check`                    | verbatim    | —                                                                                                                                                     |
| `baseline:generate`        | verbatim    | —                                                                                                                                                     |
| `baseline:update`          | no          | won't learn that a baseline can be "topped up" to match current findings without a full regeneration, without losing already-accepted stricter levels |
| `baseline:cleanup`         | no          | won't learn how to find and remove baseline entries that nothing reports anymore                                                                      |
| `baseline:explain`         | no          | won't learn how to see the effective threshold for a specific metric subject and where it came from (baseline vs qmx.yaml vs `@qmx-threshold`)        |
| `baseline:rename-channels` | no          | won't learn that when a rule/channel is renamed, the baseline can be carried over via a declarative map instead of losing accepted entries            |
| `debug:layer-assignment`   | no          | won't learn how to diagnose which architectural layer a class falls into and why (including which other layers would also have matched)               |
| `directives`               | no          | won't learn about the audit of "stale" `@qmx-*` directives — which of them no longer suppress/override anything                                       |
| `graph:export`             | verbatim    | —                                                                                                                                                     |
| `hook:install`             | verbatim    | —                                                                                                                                                     |
| `hook:uninstall`           | no          | won't learn how to remove an installed pre-commit hook                                                                                                |
| `hook:status`              | no          | won't learn how to check whether the hook is installed and in what state                                                                              |
| `rules`                    | verbatim    | —                                                                                                                                                     |

---

## 2. Command options

Source: `php bin/qmx <command> --help` for each of the 13 commands (full output saved at capture time). Global Symfony options (`--ansi`/`--no-ansi`, `--quiet`/`-q`, `-v`/`vv`/`vvv`/`--verbose`, `--no-interaction`/`-n`, `--working-dir`/`-d`, `--silent`, `--version`/`-V`, `--help`/`-h`) are excluded from the enumeration per the task's direct instruction.

### `check` (36 options)

| Option                         | In llms.txt | What the agent won't learn                                                                                                                                                                                                    |
| ------------------------------ | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--preset`                     | no          | the agent won't learn about the strict/legacy/ci presets at all (see group 7)                                                                                                                                                 |
| `--exclude`                    | no          | won't learn about the exact:/subtree:/regex: directory-exclusion selectors                                                                                                                                                    |
| `--suppress-path`              | no          | won't learn that findings can be suppressed by path without touching the source                                                                                                                                               |
| `--suppress-namespace`         | no          | won't learn about suppression by namespace                                                                                                                                                                                    |
| `--include-generated`          | no          | won't learn that files with @generated are skipped by default and that this can be disabled                                                                                                                                   |
| `-c, --config`                 | semantic    | the path to qmx.yaml is mentioned in one phrase ("qmx.yaml (auto-discovered) or --config=path") without tying it to specific commands — the text does not make clear that the baseline commands and directives also accept it |
| `-f, --format`                 | verbatim    | —                                                                                                                                                                                                                             |
| `-o, --output`                 | verbatim    | only the short alias `-o` is shown; the long form `--output` does not appear anywhere                                                                                                                                         |
| `--fail-on`                    | no          | won't learn how to control the exit code (0/1/2/3) for a CI gate — a parameter critical for integration                                                                                                                       |
| `--namespace`                  | verbatim    | the spelling is shared with the differently-meaning option `graph:export --namespace`                                                                                                                                         |
| `--class`                      | no          | won't learn about filtering the report by a specific class                                                                                                                                                                    |
| `--no-cache`                   | no          | won't learn about disabling the AST cache                                                                                                                                                                                     |
| `--cache-dir`                  | no          | won't learn that the cache directory is configurable                                                                                                                                                                          |
| `--clear-cache`                | no          | won't learn about forcing a cache clear                                                                                                                                                                                       |
| `--baseline`                   | verbatim    | —                                                                                                                                                                                                                             |
| `--show-resolved`              | no          | won't learn about counting "resolved" baseline entries                                                                                                                                                                        |
| `--show-suppressed`            | no          | won't learn about showing suppressed findings as prose (easily confused with the `suppressed` format, which does something similar but differently)                                                                           |
| `--no-suppression-annotations` | no          | won't learn how to temporarily ignore `@qmx-ignore` when counting the report                                                                                                                                                  |
| `--report`                     | verbatim    | —                                                                                                                                                                                                                             |
| `--report-strict`              | no          | won't learn about the strict file-matching mode for a git scope                                                                                                                                                               |
| `-w, --workers`                | no          | won't learn about controlling parallelism                                                                                                                                                                                     |
| `--log-file`                   | no          | won't learn about writing a debug log                                                                                                                                                                                         |
| `--log-level`                  | no          | won't learn about logging levels                                                                                                                                                                                              |
| `--no-progress`                | no          | won't learn how to disable the progress bar (important for CI logs)                                                                                                                                                           |
| `--memory-limit`               | no          | won't learn about configuring PHP's memory_limit                                                                                                                                                                              |
| `--profile`                    | no          | won't learn about the built-in profiler                                                                                                                                                                                       |
| `--profile-format`             | no          | won't learn about the profile export format (json/chrome-tracing)                                                                                                                                                             |
| `--group-by`                   | no          | won't learn about grouping findings                                                                                                                                                                                           |
| `--format-opt`                 | no          | won't learn about formatter-specific options                                                                                                                                                                                  |
| `--detail`                     | verbatim    | —                                                                                                                                                                                                                             |
| `--top`                        | no          | won't learn about the top impact-issues view                                                                                                                                                                                  |
| `--all`                        | no          | won't learn about the alias for full output without truncation                                                                                                                                                                |
| `--exclude-health`             | no          | won't learn that a measurement can be excluded from the health score                                                                                                                                                          |
| `--disable-rule`               | verbatim    | a comment in the example ("(star required)") contradicts `--help` ("prefix e.g., complexity"), but that is a correctness question, not a presence one                                                                         |
| `--only-rule`                  | verbatim    | —                                                                                                                                                                                                                             |
| `--rule-opt`                   | no          | won't learn how to set a specific rule's threshold via the CLI                                                                                                                                                                |

### `baseline:generate` (8 options)

| Option           | In llms.txt | What the agent won't learn                                                                                          |
| ---------------- | ----------- | ------------------------------------------------------------------------------------------------------------------- |
| `-c, --config`   | no          | the command is mentioned only as the positional call `baseline:generate b.json src/`; none of its options are shown |
| `--preset`       | no          | won't learn that a preset also filters the composition of the generated baseline                                    |
| `--disable-rule` | no          | won't learn that rules can be excluded when generating the baseline                                                 |
| `--only-rule`    | no          | similarly — won't learn about `--only-rule` here                                                                    |
| `--rule-opt`     | no          | won't learn about tuning thresholds during generation                                                               |
| `--no-progress`  | no          | —                                                                                                                   |
| `--mode`         | no          | won't learn about the ratchet/suppress modes — a key fork in baseline semantics                                     |
| `-f, --force`    | no          | won't learn that an existing baseline can be overwritten with a flag                                                |

### `baseline:update` (7 options)

| Option           | In llms.txt | What the agent won't learn                      |
| ---------------- | ----------- | ----------------------------------------------- |
| `-c, --config`   | no          | the command is not mentioned in llms.txt at all |
| `--preset`       | no          | —                                               |
| `--disable-rule` | no          | —                                               |
| `--only-rule`    | no          | —                                               |
| `--rule-opt`     | no          | —                                               |
| `--no-progress`  | no          | —                                               |
| `--force`        | no          | —                                               |

### `baseline:cleanup` (8 options)

| Option           | In llms.txt | What the agent won't learn                       |
| ---------------- | ----------- | ------------------------------------------------ |
| `-c, --config`   | no          | the command is not mentioned in llms.txt at all  |
| `--preset`       | no          | —                                                |
| `--disable-rule` | no          | —                                                |
| `--only-rule`    | no          | —                                                |
| `--rule-opt`     | no          | —                                                |
| `--no-progress`  | no          | —                                                |
| `--remove`       | no          | won't learn how to remove a stale baseline entry |
| `--force`        | no          | —                                                |

### `baseline:explain` (8 options)

| Option           | In llms.txt | What the agent won't learn                                                                                          |
| ---------------- | ----------- | ------------------------------------------------------------------------------------------------------------------- |
| `-c, --config`   | no          | the command is not mentioned in llms.txt at all                                                                     |
| `--preset`       | no          | —                                                                                                                   |
| `--disable-rule` | no          | —                                                                                                                   |
| `--only-rule`    | no          | —                                                                                                                   |
| `--rule-opt`     | no          | —                                                                                                                   |
| `--no-progress`  | no          | —                                                                                                                   |
| `--baseline`     | no          | the spelling matches check's `--baseline`, but here it belongs to a different command, which llms.txt does not name |
| `--channel`      | no          | won't learn about filtering by a rule-name#violation-code channel                                                   |

### `baseline:rename-channels` (1 option)

| Option     | In llms.txt | What the agent won't learn                                                                                    |
| ---------- | ----------- | ------------------------------------------------------------------------------------------------------------- |
| `--format` | no          | the command is not mentioned in llms.txt at all; the option's spelling appears only in the context of `check` |

### `debug:layer-assignment` (3 options)

| Option          | In llms.txt | What the agent won't learn                      |
| --------------- | ----------- | ----------------------------------------------- |
| `-c, --config`  | no          | the command is not mentioned in llms.txt at all |
| `--format`      | no          | —                                               |
| `--no-progress` | no          | —                                               |

### `directives` (8 options)

| Option           | In llms.txt | What the agent won't learn                              |
| ---------------- | ----------- | ------------------------------------------------------- |
| `-c, --config`   | no          | the command is not mentioned in llms.txt at all         |
| `--format`       | no          | —                                                       |
| `--no-progress`  | no          | —                                                       |
| `--sweep`        | no          | won't learn about the narrow/full directive-audit modes |
| `--preset`       | no          | —                                                       |
| `--disable-rule` | no          | —                                                       |
| `--only-rule`    | no          | —                                                       |
| `--rule-opt`     | no          | —                                                       |

### `graph:export` (6 options)

| Option                | In llms.txt | What the agent won't learn                                                                                                                  |
| --------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `-o, --output`        | verbatim    | the short alias `-o` is shown in the example `graph:export src/ -o graph.dot`                                                               |
| `-f, --format`        | no          | won't learn that the format can be json, not only dot                                                                                       |
| `--direction`         | no          | won't learn about controlling the graph direction LR/TB/RL/BT                                                                               |
| `--no-clusters`       | no          | won't learn about disabling namespace grouping                                                                                              |
| `--namespace`         | no          | the spelling matches check's `--namespace`, but here it is a different option (include only these namespaces), never shown for graph:export |
| `--exclude-namespace` | no          | won't learn about excluding namespaces from the graph                                                                                       |

### `hook:install` (1 option)

| Option        | In llms.txt | What the agent won't learn                                                              |
| ------------- | ----------- | --------------------------------------------------------------------------------------- |
| `-f, --force` | no          | the command is shown as a bare call `hook:install`; the overwrite flag is not mentioned |

### `hook:uninstall` (1 option)

| Option                 | In llms.txt | What the agent won't learn                      |
| ---------------------- | ----------- | ----------------------------------------------- |
| `-r, --restore-backup` | no          | the command is not mentioned in llms.txt at all |

### `hook:status` (0 options)

_No options beyond the global Symfony ones._

### `rules` (1 option)

| Option        | In llms.txt | What the agent won't learn                                                     |
| ------------- | ----------- | ------------------------------------------------------------------------------ |
| `-g, --group` | no          | the command is shown as a bare call `rules`; the group filter is not mentioned |

---

## 3. Configuration keys

Source: literal reading of the `ConfigSchema::ENTRIES` array in `src/Analysis/Configuration/ConfigSchema.php` (16 rows). Additionally included (flagged "doubtful") are three root keys from `ConfigSchema::DOCUMENT_ROOTS` that have no entry of their own in `ENTRIES` but that `allowedRootKeys()` still recognizes as legal `qmx.yaml` roots: `coupling`, `computedMetrics`, `excludeHealth`. `llms.txt` never describes the structure of `qmx.yaml` in substance (only the phrase "`qmx.yaml` (auto-discovered) or `--config=path`"), so all 19 rows are `no`.

| Key (camelCase, from `ENTRIES`/`DOCUMENT_ROOTS`)               | Root type                  | In llms.txt | Doubtful                                                                            | What the agent won't learn                                                                                                               |
| -------------------------------------------------------------- | -------------------------- | ----------- | ----------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `paths`                                                        | list                       | no          | —                                                                                   | won't learn the YAML key name for the list of analyzed paths                                                                             |
| `exclude`                                                      | list                       | no          | —                                                                                   | won't learn the YAML key name for excluding directories (only that exclusion is possible in principle, via a documentation link)         |
| `format`                                                       | scalar                     | no          | —                                                                                   | won't learn that the output format can be pinned in the config, not only via a flag                                                      |
| `rules`                                                        | mixed                      | no          | —                                                                                   | won't learn about the `rules:` section with rule thresholds/options in YAML                                                              |
| `disabledRules / disabled_rules`                               | list                       | no          | —                                                                                   | won't learn the YAML equivalent of `--disable-rule`                                                                                      |
| `onlyRules / only_rules`                                       | list                       | no          | —                                                                                   | won't learn the YAML equivalent of `--only-rule`                                                                                         |
| `suppressPaths / suppress_paths`                               | list                       | no          | —                                                                                   | won't learn the YAML key for path-based suppression                                                                                      |
| `suppressNamespaces / suppress_namespaces`                     | list                       | no          | —                                                                                   | won't learn the YAML key for namespace-based suppression                                                                                 |
| `failOn / fail_on`                                             | scalar                     | no          | —                                                                                   | won't learn that the exit-code threshold can be pinned in the config                                                                     |
| `cache.dir`                                                    | `cache` section            | no          | —                                                                                   | won't learn about configuring the cache directory via YAML                                                                               |
| `cache.enabled`                                                | `cache` section            | no          | —                                                                                   | won't learn about disabling the cache via YAML                                                                                           |
| `parallel.workers`                                             | `parallel` section         | no          | —                                                                                   | won't learn about configuring the number of workers via YAML                                                                             |
| `coupling.frameworkNamespaces / coupling.framework_namespaces` | `coupling` section         | no          | —                                                                                   | won't learn about the list of framework namespaces for coupling metrics                                                                  |
| `includeGenerated / include_generated`                         | scalar                     | no          | —                                                                                   | won't learn the YAML equivalent of `--include-generated`                                                                                 |
| `memoryLimit / memory_limit`                                   | scalar                     | no          | —                                                                                   | won't learn about configuring memory_limit via YAML                                                                                      |
| `architecture`                                                 | mixed (`PRESERVE_SUBTREE`) | no          | —                                                                                   | won't learn about the existence of the architecture-policy DSL (layers/allow/coverage-gap) at all — a large functional area              |
| `coupling (root)`                                              | mixed (document)           | no          | **yes** — the key is recognized via `DOCUMENT_ROOTS`, not via its own `ENTRIES` row | won't learn that `coupling:` is a standalone root configuration document, not only `coupling.frameworkNamespaces`                        |
| `computedMetrics`                                              | mixed (document)           | no          | **yes** — likewise, no `ENTRIES` row                                                | won't learn about user-defined computed metrics in Symfony Expression Language at all                                                    |
| `excludeHealth`                                                | list (document)            | no          | **yes** — likewise, no `ENTRIES` row                                                | won't learn about excluding a measurement from the health score via YAML (the CLI equivalent `--exclude-health` is documented no better) |

---

## 4. Rule identifiers

Source: `php bin/qmx rules` (output header: "54 rules available"). `llms.txt` contains the line "Rule groups: `complexity`, `coupling`, `size`, `design`, `maintainability`, `cohesion`, `architecture`, `duplication`, `code-smell`, `security`" (10 of 15 rule groups) and one exact rule identifier — `coupling.cbo` (as an example value of `--only-rule`). A rule is counted as `semantic` if its group is named in that list (the "where to look" meaning is conveyed, but no exact id, thresholds, or options); as `no` if the group is not named at all.

| Groups not mentioned in llms.txt at all | Rules in the group |
| --------------------------------------- | ------------------ |
| `annotation`                            | 1                  |
| `computed`                              | 1                  |
| `discovery`                             | 1                  |
| `health`                                | 6                  |
| `suppression`                           | 1                  |

| Rule identifier                          | Description (brief)                                                                        | In llms.txt | Comment                                                                                                     |
| ---------------------------------------- | ------------------------------------------------------------------------------------------ | ----------- | ----------------------------------------------------------------------------------------------------------- |
| `annotation.directive`                   | Reports inline @qmx directives that address nothing, cannot apply, or no longer do anyt... | no          | the `annotation` group is not mentioned in "Rule groups" at all                                             |
| `architecture.circular-dependency`       | Detects circular dependencies between classes                                              | semantic    | the `architecture` group is named in the "Rule groups" list, but no exact id/threshold/options are given    |
| `architecture.layer-violation`           | Detects dependencies between layers that are not explicitly allowed by the architecture... | semantic    | the `architecture` group is named in the "Rule groups" list, but no exact id/threshold/options are given    |
| `architecture.unassigned-class`          | Counts analysed class-like declarations that no declared layer claims.                     | semantic    | the `architecture` group is named in the "Rule groups" list, but no exact id/threshold/options are given    |
| `code-smell.boolean-argument`            | Detects boolean arguments in method/function signatures                                    | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.constructor-overinjection`   | Checks number of constructor parameters (dependencies)                                     | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.count-in-loop`               | Detects count() calls in loop conditions                                                   | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.debug-code`                  | Detects debug code (var_dump, print_r, dd, etc)                                            | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.empty-catch`                 | Detects empty catch blocks                                                                 | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.error-suppression`           | Detects usage of error suppression operator (@)                                            | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.eval`                        | Detects usage of eval() function                                                           | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.exit`                        | Detects usage of exit() and die()                                                          | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.goto`                        | Detects usage of goto statement                                                            | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.identical-subexpression`     | Detects identical sub-expressions indicating copy-paste errors or logic bugs               | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.long-parameter-list`         | Checks number of parameters per method                                                     | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.superglobals`                | Detects direct access to superglobals                                                      | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.unreachable-code`            | Detects unreachable code after terminal statements                                         | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `code-smell.unused-private`              | Detects unused private methods, properties, and constants                                  | semantic    | the `code-smell` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `cohesion.lcom`                          | Checks Lack of Cohesion of Methods (high values indicate class should be split)            | semantic    | the `cohesion` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `complexity.ccn`                         | Checks cyclomatic complexity at method and class levels                                    | semantic    | the `complexity` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `complexity.cognitive`                   | Checks cognitive complexity at method and class levels                                     | semantic    | the `complexity` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `complexity.npath`                       | Checks NPath complexity at method and class levels                                         | semantic    | the `complexity` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `complexity.wmc`                         | Checks Weighted Methods per Class (sum of method complexities)                             | semantic    | the `complexity` group is named in the "Rule groups" list, but no exact id/threshold/options are given      |
| `computed`                               | Checks user-defined computed metrics against their thresholds                              | no          | the `computed` group is not mentioned in "Rule groups" at all                                               |
| `coupling.cbo`                           | Checks CBO (Coupling Between Objects) at class and namespace levels                        | verbatim    | —                                                                                                           |
| `coupling.class-rank`                    | Checks ClassRank (PageRank on dependency graph) to identify critical hub classes           | semantic    | the `coupling` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `coupling.distance`                      | Checks distance from main sequence at namespace level                                      | semantic    | the `coupling` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `coupling.instability`                   | Checks instability at class and namespace levels                                           | semantic    | the `coupling` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `coupling.unmatched-framework-namespace` | Reports a coupling.frameworkNamespaces selector that classified nothing                    | semantic    | the `coupling` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `design.data-class`                      | Detects classes whose public interface is mostly data access rather than behavior (Data... | semantic    | the `design` group is named in the "Rule groups" list, but no exact id/threshold/options are given          |
| `design.dit`                             | Checks Depth of Inheritance Tree (deep hierarchies increase complexity)                    | semantic    | the `design` group is named in the "Rule groups" list, but no exact id/threshold/options are given          |
| `design.god-class`                       | Detects God Classes (overly complex, large, low cohesion)                                  | semantic    | the `design` group is named in the "Rule groups" list, but no exact id/threshold/options are given          |
| `design.noc`                             | Checks Number of Children (many direct subclasses indicate wide impact)                    | semantic    | the `design` group is named in the "Rule groups" list, but no exact id/threshold/options are given          |
| `design.type-coverage.param`             | Checks type coverage of parameters per class                                               | semantic    | the `design` group is named in the "Rule groups" list, but no exact id/threshold/options are given          |
| `design.type-coverage.property`          | Checks type coverage of properties per class                                               | semantic    | the `design` group is named in the "Rule groups" list, but no exact id/threshold/options are given          |
| `design.type-coverage.return`            | Checks type coverage of return types per class                                             | semantic    | the `design` group is named in the "Rule groups" list, but no exact id/threshold/options are given          |
| `discovery.unmatched-exclude`            | Reports an exclude pattern that removed no directory from the analysed set                 | no          | the `discovery` group is not mentioned in "Rule groups" at all                                              |
| `duplication.clone`                      | Detects duplicated code blocks                                                             | semantic    | the `duplication` group is named in the "Rule groups" list, but no exact id/threshold/options are given     |
| `health.cohesion`                        | Checks the cohesion health score against its thresholds                                    | no          | the `health` group is not mentioned in "Rule groups" at all                                                 |
| `health.complexity`                      | Checks the complexity health score against its thresholds                                  | no          | the `health` group is not mentioned in "Rule groups" at all                                                 |
| `health.coupling`                        | Checks the coupling health score against its thresholds                                    | no          | the `health` group is not mentioned in "Rule groups" at all                                                 |
| `health.maintainability`                 | Checks the maintainability health score against its thresholds                             | no          | the `health` group is not mentioned in "Rule groups" at all                                                 |
| `health.overall`                         | Checks the overall health score against its thresholds                                     | no          | the `health` group is not mentioned in "Rule groups" at all                                                 |
| `health.typing`                          | Checks the typing health score against its thresholds                                      | no          | the `health` group is not mentioned in "Rule groups" at all                                                 |
| `maintainability.mi`                     | Checks Maintainability Index (lower values indicate harder to maintain code)               | semantic    | the `maintainability` group is named in the "Rule groups" list, but no exact id/threshold/options are given |
| `security.command-injection`             | Detects potential command injection vulnerabilities                                        | semantic    | the `security` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `security.hardcoded-credentials`         | Detects hardcoded credentials in code                                                      | semantic    | the `security` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `security.sensitive-parameter`           | Detects sensitive parameters missing #[\SensitiveParameter] attribute                      | semantic    | the `security` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `security.sql-injection`                 | Detects potential SQL injection vulnerabilities                                            | semantic    | the `security` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `security.xss`                           | Detects potential XSS vulnerabilities                                                      | semantic    | the `security` group is named in the "Rule groups" list, but no exact id/threshold/options are given        |
| `size.class-count`                       | Checks number of classes per namespace                                                     | semantic    | the `size` group is named in the "Rule groups" list, but no exact id/threshold/options are given            |
| `size.method-count`                      | Checks number of methods per class                                                         | semantic    | the `size` group is named in the "Rule groups" list, but no exact id/threshold/options are given            |
| `size.property-count`                    | Checks if classes have too many properties                                                 | semantic    | the `size` group is named in the "Rule groups" list, but no exact id/threshold/options are given            |
| `suppression.configuration`              | Reports a suppress_paths, suppress_namespaces or suppress_namespace_channels value that... | no          | the `suppression` group is not mentioned in "Rule groups" at all                                            |

---

## 5. Inline `@qmx-*` directives

Source: `grep -rohE '@qmx-[a-z-]+' src/` across the whole `src/` tree (not just `Policy/Inline/`), cross-checked by reading the docblocks of `src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php` (file-level / symbol-level / line-level) and `src/Analysis/Policy/Inline/Directive/DirectiveNameHints.php` (directive descriptions in terms of "channel"/"threshold"). Exactly 4 spellings were found, no others occurred.

| Directive               | In llms.txt | What the agent won't learn                                                                                                       |
| ----------------------- | ----------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `@qmx-ignore`           | verbatim    | —                                                                                                                                |
| `@qmx-ignore-next-line` | verbatim    | —                                                                                                                                |
| `@qmx-ignore-file`      | no          | won't learn about suppressing all findings in a file with a single file-level directive                                          |
| `@qmx-threshold`        | no          | won't learn that a rule's threshold can be overridden pointwise on a symbol (with a mandatory reason), without touching qmx.yaml |

---

## 6. Output formats

Source: `src/Reporting/Formatter/FormatterRegistry.php` — the `getAvailableNames()` method (the public list, filtered by `HIDDEN_FORMATTERS`) and the `HIDDEN_FORMATTERS = ['text-verbose']` constant itself; the full list of classes was found via `grep -rl "implements FormatterInterface" src/Reporting/` and confirmed against the return value of `getName()` in each.

| Format (`--format=...`) | In llms.txt | Comment                                                                                                                                                                                                                        |
| ----------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `summary`               | verbatim    | listed as the default format                                                                                                                                                                                                   |
| `text`                  | verbatim    | —                                                                                                                                                                                                                              |
| `json`                  | verbatim    | —                                                                                                                                                                                                                              |
| `checkstyle`            | verbatim    | —                                                                                                                                                                                                                              |
| `sarif`                 | verbatim    | —                                                                                                                                                                                                                              |
| `gitlab`                | verbatim    | —                                                                                                                                                                                                                              |
| `github`                | verbatim    | —                                                                                                                                                                                                                              |
| `metrics`               | verbatim    | —                                                                                                                                                                                                                              |
| `health`                | verbatim    | —                                                                                                                                                                                                                              |
| `html`                  | verbatim    | —                                                                                                                                                                                                                              |
| `suppressed`            | verbatim    | —                                                                                                                                                                                                                              |
| `text-verbose`          | no          | not a gap in llms.txt in the strict sense: the format is marked in the code as `HIDDEN_FORMATTERS` (deprecated, replaced by `--format=text --detail`), so its absence from the public documentation is most likely intentional |

---

## 7. Presets

Source: `src/Analysis/Configuration/Preset/PresetResolver.php`, the `BUILT_IN_PRESETS = ['ci', 'legacy', 'strict']` constant.

| Preset   | In llms.txt | What the agent won't learn                                                                                                                                                                                                                                  |
| -------- | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ci`     | no          | won't learn that built-in presets exist at all — the word "preset" does not occur in llms.txt even once (checked with grep; the only substring match for "ci" is a false positive, from the word "specific" and from "CI/CD" in link titles on the website) |
| `legacy` | no          | same as above                                                                                                                                                                                                                                               |
| `strict` | no          | same as above                                                                                                                                                                                                                                               |
