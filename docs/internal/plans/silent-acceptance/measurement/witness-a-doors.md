# Witness A — doors of user input into Qualimetrix

Base: main == 4bb128fd, clean tree. Two TSVs produced next to this file:
`doors-cli.tsv`, `doors-config.tsv`.

## Method

- CLI: temp script `dump-cli-doors.php` builds the container via
  `ContainerFactory`, constructs `Infrastructure\Console\Application` exactly
  as `bin/qmx` does, registers the same `ContainerCommandLoader` map, then
  calls `Application::all()` and, for every command whose `getName()` equals
  its key in that map (skips alias-duplicate entries — none turned out to
  exist), walks `getDefinition()->getOptions()` and `->getArguments()`. Also
  walked `Application::getDefinition()` for the global option set. No command
  was executed — pure introspection.
- Config: read `src/Analysis/Configuration/ConfigSchema::ENTRIES` via a second
  temp script, plus called `allowedRootKeys()`, `sectionKeys()`,
  `identifierKeyedOptions()` to see the schema's own derived view of itself.

## Sums

**Commands found via `Application::all()`: 17**, of which:
- 13 are Qualimetrix product commands (registered in `ContainerCommandLoader`
  in `bin/qmx`): `check`, `baseline:generate`, `baseline:update`,
  `baseline:cleanup`, `baseline:explain`, `baseline:rename-channels`,
  `debug:layer-assignment`, `directives`, `graph:export`, `hook:install`,
  `hook:uninstall`, `hook:status`, `rules`.
- 4 are Symfony Console built-ins, not product doors, but still real CLI
  input surface a user can hit: `_complete`, `completion`, `help`, `list`.

**CLI doors: 125 rows** (`doors-cli.tsv`, 126 lines with header), of which:
- 9 rows are global (apply to every command, from `Application::getDefinition()`):
  `--help`, `--silent`, `--quiet`, `--verbose`, `--version`, `--ansi`,
  `--no-ansi`, `--no-interaction`, plus the `command` argument itself.
- By "accepts a value": **75 option rows accept a value**, **32 option rows
  are pure flags** (`acceptValue()===false`), **18 argument rows** (Symfony
  arguments always accept a value).
- Largest single command: `check`, 37 doors (36 options + 1 argument). Of
  those, **10 are array doors** (`isArray()===true`): options `--preset`,
  `--exclude`, `--suppress-path`, `--suppress-namespace`, `--format-opt`,
  `--exclude-health`, `--disable-rule`, `--only-rule`, `--rule-opt`, plus the
  `paths` argument.

**Config doors: 16 rows in `ConfigSchema::ENTRIES`** (`doors-config.tsv`),
mapping to **17 allowed root keys** (`ConfigSchema::allowedRootKeys()`). The
gap is real, not an artifact of counting: `excludeHealth` has NO literal
ENTRIES row of its own (checked — grepped `ENTRIES` for `EXCLUDE_HEALTH`,
found none); it becomes an allowed root key only because
`DOCUMENT_ROOTS` names it, and it becomes list-typed only because
`listKeys()` adds `$lists[self::EXCLUDE_HEALTH] = true` outside the ENTRIES
loop. So **ENTRIES is not itself the exhaustive root-door table** — it must
be read together with `DOCUMENT_ROOTS` to get the full 17.

Of the 16 ENTRIES rows: 4 are typed section sub-keys (`cache.dir`,
`cache.enabled`, `parallel.workers`, `coupling.frameworkNamespaces`, root
type `null` = "section-subkey"); the remaining 12 are top-level roots (7
`list`, 3 `scalar`, 2 `mixed`: `rules` and `architecture`).

## Blind spots — named honestly

1. **A door's value can itself carry sub-keys, invisible to reflection.**
   `check --rule-opt` and `--format-opt` each accept a value shaped
   `rule-name:option=value` / `key=value`; Symfony's `InputOption` reports
   only "array of strings", never the key names a user can write inside one
   value. `--exclude-health` and `--group-by` similarly hide a fixed
   vocabulary (`complexity, cohesion, coupling, typing, maintainability` /
   `none, file, rule, severity, class, namespace`) behind a free-text option
   type. `--all` is documented as "alias for `--format-opt=violations=all
   --detail=all`" — a door that is itself sugar for two other doors.
2. **`rules.<rule-name>.<option>` is a real, wide door tree this method does
   not enumerate.** `ConfigSchema::RULES` is `MIXED` /
   `PRESERVE_IMMEDIATE_CHILDREN`: level-1 keys are user-typed rule slugs
   (arbitrary), and each rule's own option names (thresholds,
   `suppress_namespace_channels`, `enabled`, …) are validated by that rule's
   own `RuleOptionsInterface` class, never by `ConfigSchema`. I only found
   `suppress_namespace_channels` by grepping usage sites
   (`RuleNamespaceExclusionProvider.php`, `RuleOptionsFactory.php`,
   `ChannelExclusionKeyValidator.php`) — `ConfigSchema` itself never names it.
   The same is true for every per-rule threshold key across every rule under
   `src/Analysis/Evidence/*`. Enumerating those fully would mean walking every
   `*Options` class, which this pass did not do.
3. **`architecture` and `computedMetrics` are free-form subtrees.**
   `architecture` is `PRESERVE_SUBTREE` (layer names, `allow`, `relations`,
   `exclude`, `max_expanded_layers`, at arbitrary depth); `computedMetrics` is
   `PRESERVE_IMMEDIATE_CHILDREN` (metric names, then `formula`, `level`,
   `thresholds`). Both are validated deep inside their own configurators, not
   visible from `ConfigSchema::ENTRIES`, which emits exactly one row per root.
   Not enumerated in `doors-config.tsv` beyond that one row each.
4. **Environment/`$_SERVER` input is out of the door definition given, but
   exists in the product** (e.g. `QMX_MKDOCS`, `QMX_PRIVATE_TERMS` per
   top-level CLAUDE.md) — not covered by either script, not in either TSV,
   named here only so it isn't mistaken for "checked and absent."
5. **A command class that exists but was never added to
   `ContainerCommandLoader`'s array would be invisible to this method.** I
   built `Application` and the loader exactly like `bin/qmx` does, so this
   blind spot is theoretical for the real entrypoint — but I did not
   cross-check "every `*Command.php` file under
   `src/Infrastructure/Console/Command/`" against the 13 registered names to
   positively rule out an orphan. Flagged as an undone check, not silently
   skipped.
6. Symfony built-ins (`_complete`, `completion`, `help`, `list`) are real
   doors a user can hit (`bin/qmx list`, `bin/qmx help check`) but are not
   product-authored; included in `doors-cli.tsv`, called out separately in
   the sums rather than folded into "13 commands."

## Doubtful/borderline rows — where I made a reading call

- `check --profile` and `check --detail`: `acceptValue()===true` but
  `isValueRequired()===false` — Symfony's `VALUE_OPTIONAL` mode (bare
  `--profile` shows a summary, `--profile=file` writes to file; bare
  `--detail` uses a default limit, `--detail=N`/`--detail=all` overrides).
  The TSV's binary `required` column collapses this three-way distinction
  (none/optional/required) to `no` — same as a genuine flag. Treat any
  `required=no` + `accepts_value=yes` row as "read the description," not as
  "behaves like a flag."
- Global `--verbose`: one row, not three, even though the CLI shorthand is
  `-v|-vv|-vvv` (three verbosity levels via one shortcut string) — counted as
  a single door with a compound shortcut column.
- `coupling` is counted once among the 17 root doors even though it is both a
  `sectionKeys()` entry (fixed sub-key `coupling.frameworkNamespaces`) AND a
  `DOCUMENT_ROOTS` entry (the same root is also handed whole to a different
  consumer as an "ordered configuration document," per project CLAUDE.md).
  Whether that is "one door with two consumers" or "two doors sharing a key"
  is a judgment call; I picked the former and flagged it rather than picking
  a number that hides the duplication.
- I did not attempt to decide whether `--baseline` (a file-path option) and
  the standalone `baseline:*` commands constitute "the same door" wearing two
  hats or genuinely separate doors — they are separate CLI surfaces
  (different commands/options) so both are listed separately in the TSV, but
  a reader assembling a mental model of "baseline configuration" should know
  they are scattered across 5 places (1 option + 4 commands' worth of
  options/arguments).
