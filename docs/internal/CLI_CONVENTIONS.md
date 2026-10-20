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
bin/qmx baseline:rename-channels    # sub-object qualifier: rewrites channel keys only
bin/qmx debug:layer-assignment      # sub-object qualifier: one question about layers
```

Two rules govern the qualifier:

1. **It names a phase or the sub-object actually affected.** `rename-channels`
   rewrites the channel keys of accepted entries, not the whole file, and the
   bare `baseline:rename` would read as "rename the baseline file". Two phases
   of one operation that must be reviewed between each other take one
   qualifier each (`<verb>-plan`, `<verb>-apply`).
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

5. **Machine-readable output** — expose it as `--format={text|json|...}` defaulting to `text`,
   never as a boolean `--json`. This holds for every command that renders a result, `debug:*`
   included: `check`, `graph:export` and `debug:layer-assignment` all read the same way, and a
   boolean flag cannot grow a third representation without becoming a second option.

Graph's `--direction` has no short alias: Symfony reserves `-d` for its global
`--working-dir` option. `graph:export --format/-f` is the graph's own `dot|json`
dictionary, independent of the analysis `format` key in the shared document.
Commands that read the document judge it completely before consuming only their
own values; a command profile does not make invalid authored values invisible.

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
--cyclomatic-warning          # complexity.ccn, method level (default)
--cyclomatic-class-warning    # complexity.ccn, class level
--cbo-warning                 # coupling.cbo, class level (default)
--cbo-ns-warning              # coupling.cbo, namespace level
--lcom-min-methods            # cohesion.lcom, non-threshold option
--mi-exclude-tests            # maintainability.mi, boolean option
```

**Naming the `rule-short-name`:**
- Use the metric abbreviation if it's well-known: `cbo`, `lcom`, `wmc`, `noc`, `dit`, `mi`, `npath`
- Use the readable name if the abbreviation is obscure: `cyclomatic` (not `cc`), `cognitive`, `instability`, `distance`
- Use the rule's second segment if unambiguous: `method-count`, `class-count`, `property`
- Never use the group name alone: ~~`coupling-warning`~~, ~~`size-class-warning`~~

### Universal rule options

Options that apply to any rule, not tied to a specific one:

```
--disable-rule=<selector> # exact producer/channel or strict descendants X.*
--only-rule=<selector>    # select exact producer/channel or strict descendants X.*
--rule-opt=<rule:opt=val>  # generic rule option override
```

The selection options are channel-aware. A bare selector addresses an exact
producer (`complexity.ccn`) or a declared channel code. `complexity.*` selects
strict descendants of `complexity`, never the prefix itself; bare `complexity`
is refused. A `channel-name:level` pair narrows a declared channel to a level
it actually reports at. `--rule-opt` accepts exact producer names only, because
options configure the producer rather than an emitted channel.

### Rule option grammar and provenance

Document owners declare every accepted key, level slot and value form before
an Options instance exists. Canonical YAML uses `snake_case`; canonical CLI
addresses use `kebab-case`. Declared snake, camel and kebab spellings of the
same key are equivalent, but unrelated case variants are refused with the
canonical hint. Two spellings of one key are duplicate writes, not two options.
Constructor reflection and a permissive unknown-option warning are not the schema.

`--rule-opt=PRODUCER:OPTION=YAML_VALUE` and dedicated aliases use the same
YAML value grammar and authored CLI layer. A sequence requires a sequence:
`--lcom-exclude-methods='[getName, getDescription]'`. Booleans and numeric
forms retain their actual type; null does not become a string. Duplicate writes
through two aliases, or an alias and `--rule-opt`, refuse with exit 3.
An option address may traverse a declared level slot only. Channel-keyed
`suppress_namespace_channels` maps belong in YAML.

A bare selection name addresses an exact producer or channel. `X.*` addresses
strict descendants, never `X` itself; a bare group prefix is refused with the
starred hint. A `channel-name:level` pair uses a declared channel code and a
level that the same channel reports at. An identically named producer works
because that name is also a channel; producers with differently named channels
have no `producer:level` alias. Bare producer selection still addresses the
producer as a whole. No legacy `ruleName#violationCode` selector exists.

Selection compares authored layer precedence and specificity. A later exact
producer enable can reverse a lower disable; same-layer exact enable beats a
less specific group disable. Exact enable and disable of one producer in one
layer refuse even under a later override. `only_rules` is a filter, not an
enable statement. Empty selections, dead selectors and explicit enables outside
the effective filter refuse with their decisive writers; later disables may
lawfully narrow an earlier filter. Option activity, including a mode of
`ignore`, is concluded after typed options are built. Preparation uses that
final answer and does not rerun name matching.

File/preset diagnostics preserve authored full paths and their real source.
CLI diagnostics name the actual flag or option locator and have no document
position. Do not relabel authored failures as `resolved`; cross-layer refusals
retain every contributing writer. Listings print accepted options independently
of aliases. `qmx rules` also prints `Selection source: ... (...; layer N)` for
the effective only writer and every decisive disabled writer. Repeated cells
of one writer are deduplicated; identical text from different layers is not.
