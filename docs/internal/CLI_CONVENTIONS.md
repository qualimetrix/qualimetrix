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

Dynamic options generated from rule classes via `getCliAliases()`.

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
