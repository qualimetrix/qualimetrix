# Agent discoverability: a documentation pointer in the tool's own output

## Goal

An agent whose instructions say nothing about Qualimetrix has only the CLI. It
must be able to reach the full documentation from any output it happens to see,
and the map it reaches must not lie about what the tool can do.

Two halves, in this order:

1. `website/docs/llms.txt` — the agent-facing index — must describe the surface
   that exists. Measured: 193 surface elements, about 37% mentioned; 8 of 13
   commands, all 17 configuration root keys and all 3 presets absent.
2. Every output channel where it is appropriate carries a pointer to it.

The order is a requirement, not a preference: a pointer to a stale map is worse
than no pointer, because the agent believes it and stops looking.

## Measured inputs this plan stands on

Three enumerations live beside this file. Read them rather than trusting prose
here; each carries its own "how obtained, and what that method cannot see" line,
and in each case that line is the part worth reading.

- `enumeration-cli-surface.md` — CLI surface against `llms.txt`, seven groups.
- `enumeration-output-channels.md` — 34 output channels, and which can hold a
  pointer.
- `enumeration-coupling-controls.md` — the 46 governance controls that couple two
  kinds of artifact. This one exists because the first version of this plan
  claimed each stage would validate on its own, and that is a claim about a set
  of controls which had not been enumerated.

## Two principles, both learned from review

**A stage owns every artifact its own validation reads.** The first version
grouped stages by subject and left documentation and the gate declaration to the
end. `check:code` runs the governance suite, and
`FormatOptionKeys/OutputFormatSchemaConsistencyTest` compares structured JSON
schemas against the EN and RU documentation pages in both directions, so a format
change without its page is red. Stages "gate" and "documentation" are therefore
gone; each stage below carries its own.

**The plan does not predict what a tool will say.** The second review round found
three defects of one shape: this plan described the gate's and the generators'
behaviour in prose, and each description was wrong in a new way — a ceiling
counted in the wrong unit, three declared surfaces where seven move, and a
package built to solve a problem the gate had already solved before this branch
existed. So no stage below enumerates which surfaces the gate will flag or which
generated artifacts will go stale. Each stage instead **runs the tool and
declares or regenerates whatever it reports**. Where a number appears, it is a
measurement with its command beside it, not a forecast.

This is the recurring failure mode in this repository, recorded in its own
guidance: a text that restates a tool's rules goes red in a new place each round,
because the cause is not the wording.

## Architecture decision

One canonical value, consumed everywhere; no channel spells the URLs itself. It
lives at `src/Core/ProductIdentity.php`, beside `Core/Version.php`, which is the
existing precedent for product identity in `Core`: no capability changes because
the website moved, and the semantics are neutral constants.

```
ProductIdentity::docsUrl(): string
ProductIdentity::llmsTxtUrl(): string
ProductIdentity::pointerText(): string   // plain text, no markup
ProductIdentity::identity(): array       // version, package, docs, llmsTxt
```

`pointerText()` returns **plain text**. Dimming is an Ansi concern owned by
`Reporting`, so a `Core` value that promised a styled line would be putting a
presentation decision in the wrong module; each channel styles what it prints.

`identity()` omits `timestamp`, and the earlier reason given for that — keeping
`Core` free of a clock — was false: `Core\Time\ClockInterface` already exists.
The real reason is narrower: a timestamp is a fact about a run, not about the
product, and every channel that publishes one already obtains it its own way.
Callers merge it.

The pointer text:

```
Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt
```

The `AI agents:` label is load-bearing: it is what makes an agent fetch the
second address instead of skimming past a generic docs link.

## Channel decisions

**Carries the line (15):** `summary` tail, `text` tail, `health` tail, the
`getHelp()` header (bare `qmx` and `list`), the `list` command's `Help:` section
(which is what `--help` renders), the `Usage:` block of `rules`,
the tails of `baseline:generate|update|cleanup|explain`, the text branch of
`baseline:rename-channels`, the `Diagnostic hint:` block of
`debug:layer-assignment`, the text branch of `directives`, the tails of
`hook:install|uninstall|status`, the free-text refusal on stderr, and the HTML
report footer.

**Carries structured fields (8):** `json` and `suppressed` extend their existing
`meta`; `metrics` extends its root fields, which play the same role; `sarif`
repoints the tool-level `informationUri` and puts `llmsTxt` in a `properties`
bag; `directives`, `baseline:rename-channels` and `debug:layer-assignment`
gain a `meta` object, having none today; and `graph:export --format=json`
extends the `meta` block its envelope already had.

**Carries the short form:** the `Help:` section of every command. Five commands
have none today and get one.

**`--help` is its own path, and the plan first got it wrong.** One seam does not
cover all three header invocations. Bare `qmx` and `qmx list` execute
`ListCommand`, which describes the application object, so the overridden
`getHelp()` renders. `--help` with no command name is rewritten to the `help`
command with `command_name = 'list'`, and `HelpCommand` describes the *command*
object — `TextDescriptor` runs `describeCommand()` and the application header
never appears. Measured on the tree, after this plan asserted otherwise. It is
covered by calling `setHelp()` on the existing `list` command instance, which is
public API and not the same as replacing a framework command. Keeping the four
framework commands out of the *invariant's population* is a statement about what
the guard asserts over, not about which channels carry the pointer.

**Excluded, with cause:**

| Channel                                                                                                                                                                 | Why not                                                                                        |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `checkstyle`                                                                                                                                                            | Fixed XML schema, no field for free text outside a violation                                   |
| `gitlab`                                                                                                                                                                | Payload is a bare JSON array, no wrapper object                                                |
| `github`                                                                                                                                                                | Command stream with no header or footer; any line renders as an annotation                     |
| `graph:export` stdout (DOT)                                                                                                                                             | The output *is* the artifact, a DOT graph with no envelope to extend                           |
| `--version`                                                                                                                                                             | Unix convention: name and version only. `getHelp()` reaches the header without touching it     |
| `text-verbose`                                                                                                                                                          | Deprecated. It delegates to `text` and inherits whatever `text` prints                         |
| JSON refusal envelope                                                                                                                                                   | `{error, exit_code}` is deliberately closed at two keys                                        |
| HTML `#node-summary`, coverage banner                                                                                                                                   | Per-selection and conditional                                                                  |
| `Infrastructure\Profiler\Export\JsonExporter`, `...\ChromeTracingExporter`                                                                                              | The output *is* the artifact (a profiling trace), not a report                                 |
| `Infrastructure\Logging\FileLogger` JSON lines                                                                                                                          | A log stream, not a report                                                                     |
| `Analysis\Policy\Baseline\BaselineDocumentLayout` (the baseline file, written by `baseline:generate`, `update`, `cleanup`, and rewritten in place by `rename-channels`) | A versioned input artifact (format v13) the tool reads back, with its own schema; not a report |

## Stage map

| Stage                                  | Subject                                                                               | Depends on |
| -------------------------------------- | ------------------------------------------------------------------------------------- | ---------- |
| [01](01-llms-index-and-guard.md)       | `llms.txt` rewritten to the measured surface, plus its guard                          | —          |
| [02](02-pointer-and-human-channels.md) | The canonical value and every human-readable channel, with their docs and gate delta  | 01         |
| [03](03-machine-metadata-fields.md)    | The eight JSON-bearing channels, with `output-formats` EN and RU and their gate delta | 02         |
| [04](04-html-footer.md)                | HTML: the gate's bundle normalization first, then the footer                          | 02         |

## Cross-cutting requirements

- **Verbosity.** The pointer is absent under `--quiet` and `--silent`.
- **No markup.** Nothing prints `<info>` or any other tag under `--no-ansi`.
  `getHelp()`'s stock return carries markup, so the override is where a leak is
  likeliest: `php bin/qmx --no-ansi | head -3`.
- **One wording.** Every channel reads `ProductIdentity`. There is exactly one
  pre-existing literal in `src/` — `SarifRuleCollector::DOCS_BASE_URI` — and
  stage 03 owns folding it in. Any other literal is a defect.
- **The manifest is sequential, and a value cannot land alone.** Two separate
  refusals, both measured. A declared consumer that does not import is refused
  (`unused contract consumer entry`, exit 1), so consumers cannot be
  pre-declared. And a `contract`-visibility declaration with no consumers at all
  is refused earlier still (`contract declaration … must publish at least one
  used consumer`). So `ProductIdentity` and its first consumer land in **one**
  package, and every later package adds its own imports, its own consumer rows
  and regenerates the architecture artifacts. Packages inside a stage run in
  sequence. `architecture:check` is not part of `check:code`; it runs in
  `check:artifacts` and as the first half of `selfcheck`.
- **Adding a test file invalidates generated inventories.** The
  test-inventory generator enumerates `*Test.php` through `git ls-files`, and its
  artifacts are byte-compared. Every package that adds a test — most of them —
  regenerates them as part of its own work. `qmx.yaml` is generated and
  byte-compared too, and it names `Core` classes, so the package that introduces
  `ProductIdentity` regenerates it.

## Deliberately not in scope

- Relocating `metrics`' root-level identity fields into a nested `meta`. It would
  make every JSON shape identical but migrates fields that already publish
  correctly. Recorded as the next normalization.
- An offline clause in the pointer. Bare `qmx` already lists all 13 commands with
  descriptions, which is the offline orientation path.
- A "capabilities not yet enabled" report in `check`. That is the other half of
  the original question, and a pointer does not answer it.

## Out-of-package change already made

`docs/internal/plans/README.md` gained this plan's index row.
`PlanningRecords/PlanningRecordIsolationTest` requires every plan directory to be
registered there, so creating this directory turned `composer test` red before a
line of product code existed. Recorded here because it belongs to no package.
