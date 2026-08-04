# CLI Naming Conventions

This document defines naming rules for CLI commands, arguments, and options.
All new CLI elements must follow these conventions.

---

## Commands

### Top-level commands

Primary actions that take source code as input and produce analysis results.
These are the main user workflows, used frequently.

```
bin/qmx check src/       # code → violations
bin/qmx metrics src/     # code → raw metrics (planned)
```

### Namespaced commands (`noun:verb`)

Management commands for specific subsystems or artifacts.
Grouped by the noun — the object being managed.

```
bin/qmx graph:export     # dependency graph management
bin/qmx baseline:cleanup # baseline file management
bin/qmx hook:install     # git hook management
bin/qmx hook:status
bin/qmx hook:uninstall
```

**Rule of thumb:** If the command operates on source code and produces analysis output → top-level.
If it manages a tool subsystem or resource → namespaced.

### The verb segment

The verb is kebab-case. It is normally a single word (`export`, `install`,
`cleanup`, `update`), and a **qualifier is permitted** when the bare verb would
be ambiguous or would overstate what the command touches:

```
bin/qmx baseline:migrate-plan       # phase qualifier: produce the disposition plan
bin/qmx baseline:migrate-apply      # phase qualifier: apply a reviewed plan
bin/qmx baseline:rebase-contracts   # sub-object qualifier: rewrites contract metadata only
```

Two rules govern the qualifier:

1. **It names a phase or the sub-object actually affected.** `migrate-plan` and
   `migrate-apply` are the two phases of one migration; `rebase-contracts`
   rewrites the contract manifest, not the whole file, and the bare
   `baseline:rebase` would read as "rebase the baseline".
2. **It never restates the namespace noun.** The noun segment already scopes the
   command: ~~`baseline:cleanup-baseline`~~, ~~`hook:install-hook`~~.

Prefer separate verbs over one command with mode options. Two phases that must
be reviewed between each other are two commands, not `--plan` / `--apply` flags
on one — the mode flag hides the review step that makes the split worth having.

---

## Options

### General rules

1. **One name per option.** No shortcut aliases that duplicate another option's functionality.
   Use `--report=git:staged` instead of providing a separate `--staged` shortcut.

2. **Short flags (`-f`, `-w`, `-c`)** — only for the most frequently used options (≤ 6 total).

3. **Boolean flags (`VALUE_NONE`)** — use `--no-{feature}` pattern (e.g., `--no-cache`, `--no-progress`).
   If the option needs a value, do NOT use the `--no-*` prefix — use `--{feature}=true/false` instead.

4. **Repeatable options** — use `VALUE_IS_ARRAY` (e.g., `--exclude`, `--disable-rule`).

### Rule CLI aliases

Dynamic options generated from rule classes via the repeatable class-level attribute `#[CliAlias('alias', 'optionName')]`, read at runtime by `CliAliasReader`.

**Format:** `{rule-short-name}[-{level}]-{option}`

| Part              | Description                                                      | Examples                                              |
| ----------------- | ---------------------------------------------------------------- | ----------------------------------------------------- |
| `rule-short-name` | Brief, recognizable name of the **rule/metric** (not the group!) | `cyclomatic`, `lcom`, `cbo`, `mi`                     |
| `level`           | *(optional)* Scope level for hierarchical rules                  | `method`, `class`, `ns`                               |
| `option`          | The option being set, in kebab-case                              | `warning`, `error`, `min-methods`, `exclude-readonly` |

**Examples:**

```
--cyclomatic-warning          # complexity.cyclomatic, method level (default)
--cyclomatic-class-warning    # complexity.cyclomatic, class level
--cbo-warning                 # coupling.cbo, class level (default)
--cbo-ns-warning              # coupling.cbo, namespace level
--lcom-min-methods            # design.lcom, non-threshold option
--mi-exclude-tests            # maintainability.index, boolean option
```

**Naming the `rule-short-name`:**
- Use the metric abbreviation if it's well-known: `cbo`, `lcom`, `wmc`, `noc`, `dit`, `mi`, `npath`
- Use the readable name if the abbreviation is obscure: `cyclomatic` (not `cc`), `cognitive`, `instability`, `distance`
- Use the rule's second segment if unambiguous: `method-count`, `class-count`, `property`
- Never use the group name alone: ~~`coupling-warning`~~, ~~`size-class-warning`~~

### Universal rule options

Options that apply to any rule, not tied to a specific one:

```
--disable-rule=<prefix>    # disable rules by name or group prefix
--only-rule=<prefix>       # run only matching rules
--rule-opt=<rule:opt=val>  # generic rule option override
```

These use the rule's full NAME (`complexity.cyclomatic`, `design.lcom`) or group prefix (`complexity`, `design`).

### Rule option key casing: canon vs. accepted input

Rule option keys reach the tool through three channels — `qmx.yaml`, presets,
and `--rule-opt=RULE:OPTION=VALUE` (including the short `#[CliAlias(...)]`
flags above) — and all three land in the same internal representation before
an Options class ever sees them. This section documents what the code
actually does today, not an aspirational convention.

**Canonical spelling per channel:**

| Channel                            | Canonical casing | Example                                                                |
| ---------------------------------- | ---------------- | ---------------------------------------------------------------------- |
| `qmx.yaml` / preset YAML           | `snake_case`     | `exclude_namespaces: [...]`, `max_distance_warning: 0.5`               |
| `--rule-opt` / `#[CliAlias]` flags | `kebab-case`     | `--rule-opt=coupling.cbo:min-class-count=5`, `--cyclomatic-warning=15` |

The repository's own root `qmx.yaml` follows this and is snake_case
throughout (`exclude_namespaces`, `max_distance_warning`, `min_afferent`,
`max_warning`, …) — treat it as the reference example, not the kebab-case
form implied by `--rule-opt` alone.

**All three spellings are always accepted, everywhere.** Internally, every
option key is normalized to camelCase (the PHP constructor parameter name)
before it reaches an Options class:

- `RuleOptionsFactory::normalizeKeys()` normalizes `qmx.yaml`/preset keys
  (snake_case, kebab-case, or already-camelCase) to camelCase.
- `RuleOptionsParser::normalizeOptionName()` does the same for `--rule-opt`
  and `#[CliAlias]` option names.

So `exclude_namespaces`, `exclude-namespaces`, and `excludeNamespaces` are
all equivalent in `qmx.yaml`; `min-class-count`, `min_class_count`, and
`minClassCount` are all equivalent on `--rule-opt`. There is no channel where
only one casing works — the canonical spellings above are the *documented,
idiomatic* choice per channel, not the *only accepted* one.

**Reporting:** when the tool needs to show option names back to the user
(the "Unknown option ... Available options: ..." warning from
`RuleOptionsFactory::warnAboutUnknownKeys()`), it converts the internal
camelCase name to kebab-case via `toCanonicalDisplayName()` — e.g. a
`maxDistanceWarning` constructor parameter is reported as
`max-distance-warning`, regardless of which casing the user actually typed.
Kebab-case is therefore the spelling users see in tool output, even though
`qmx.yaml` itself is conventionally snake_case.

> **Known gap:** `warnAboutUnknownKeys()` only recognizes constructor
> parameter names (reflected off the Options class). Shorthand keys consumed
> by `ThresholdParser` but not also a constructor parameter — the bare
> `threshold` key, or rule-specific ones like `param_threshold` on
> `design.type-coverage` — are invisible to this check and can trigger a
> false "Unknown option" warning even though `ThresholdParser` accepts them
> correctly. See `RuleOptionsFactory::warnAboutUnknownKeys()` docblock.
