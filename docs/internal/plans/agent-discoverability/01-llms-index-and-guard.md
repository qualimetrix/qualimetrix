# Stage 01 — `llms.txt` describes the surface that exists, and a guard keeps it that way

Independently shippable and first on purpose: every later stage points at this
file, and a pointer to a stale map is worse than no pointer. This stage touches
no `src/`.

## The cut

193 surface elements do not fit an index and must not. `llms.txt` is the index;
`llms-full.txt`, generated from the site nav, is the detail.

> An element belongs in `llms.txt` when an agent cannot learn that it exists from
> the CLI itself.

| Group                | In the index                                 | Authoritative source                        |
| -------------------- | -------------------------------------------- | ------------------------------------------- |
| Commands             | All, one line each                           | `#[AsCommand]` attributes on the filesystem |
| Presets              | All 3                                        | their registry                              |
| `qmx.yaml` root keys | All 17, names only                           | `ConfigSchema::allowedRootKeys()`           |
| Inline directives    | All 4                                        | their registry                              |
| Output formats       | All 12                                       | `FormatterRegistry`                         |
| Command options (88) | **No** — address: `qmx <command> --help`     | —                                           |
| Rules (54)           | Groups only, as today — address: `qmx rules` | —                                           |

**17, not 19.** The earlier figure came from adding `ConfigSchema::ENTRIES` (16)
to a hand-picked subset of `DOCUMENT_ROOTS` (which has 4 members, not 3).
`allowedRootKeys()` is the one method that already unions both and de-duplicates;
it returns 17. Neither the index nor the guard may recompute that union.

**The spelling is the key as `allowedRootKeys()` returns it** — camelCase
(`computedMetrics`, `excludeHealth`, `suppressPaths`), matching `qmx.yaml`. The
index writes that spelling and the guard matches that spelling, or the two
compare different alphabets and agree about nothing.

Form follows the file's existing style: actions and addresses, no rationale, no
tutorial prose.

Expected size: about +40 lines, 4.7 KB to roughly 7 KB.

## Packages, in sequence

Two executors, and they must be different. One agent that writes both the index
and the guard over it fails consistently — the guard inherits the index's
omissions and passes. That is why this is two packages and not one.

### Rewrite the index

- Files: `website/docs/llms.txt`.
- Input: `enumeration-cli-surface.md`.
- The `llms-full.txt` link stays; it is the only route from index to detail.

DoD:
- All 13 commands, 3 presets, 17 root keys, 4 directives, 12 formats present.
- `--fail-on` present; it decides the CI exit code and is absent today.
- `llms-full.txt` link present.
- `composer docs:check` green.

### The guard

- Files: one new test under `governance/DocumentationCensus/`. That group is
  already registered in the `Governance` suite, so no registration address is
  touched and no new group is created. Its existing control already couples
  documentation to the live formatter registry, which is this guard's shape.

**Sources, and the reason they are named here.** The first version of this plan
told the guard to read the command names out of `bin/qmx`'s `ContainerCommandLoader`
map. That is wrong twice. `bin/qmx` ends in `exit()` and cannot be included, so
the extraction would be a regex over text, and a regex that matches nothing
yields an empty expected set — over which "every element is present" passes
green. And this repository has already decided the question and written down
why: `governance/ConsoleComposition/CommandRegistrationTest` reads the command
set off the filesystem via `#[AsCommand]` and only *checks* the map, because "an
enumeration taken from the map could only ever confirm the map against itself".

So: commands come from `#[AsCommand]`, exactly as that control does. The other
four sets come from their registries, which return arrays.

**The self-refusal, stated precisely.** The circularity that killed the first
version was specific to *text scraping*: a regex over `bin/qmx` can match nothing
and yield an empty expected set, over which "every element is present" passes. A
registry call cannot fail that way — it returns its array or the code does not
run. So the two halves of the promise are not the same:

- **Commands.** The `#[AsCommand]` filesystem sweep *can* come back empty, so it
  needs a second witness. That witness is the command count in `bin/qmx`'s map:
  13 against 13 today. `CommandRegistrationTest`'s docblock forbids taking the
  *enumeration* from the map, and using it only as a count does not violate that —
  the map confirming itself is the thing forbidden, and here the map confirms a
  different source. `CommandRegistrationTest` itself asserts no cardinality, so it
  cannot serve as the witness.
- **Presets, formats, directives, configuration.** Extraction and count come from
  the same registry call, and no independent second source exists in the tree. The
  guard therefore promises less for these four: it refuses an empty or
  single-element set, and it refuses a mismatch against the index. It does **not**
  promise to detect a registry that silently lost a member — that is
  `ConfigSchemaEntryClosureTest`'s and the registries' own controls' job, not
  this one's.

Narrowing the promise is deliberate. A guard that claimed an independent witness
it does not have would be the same defect one level up.

```
itNamesEveryRegisteredCommand()
itNamesEveryPresetFormatAndDirective()
itNamesEveryConfigurationRootKey()
itRefusesWhenAnyExtractionDisagreesWithItsIndependentCount()
```

DoD — the last item is the one that matters:
- Removing any single command, preset, format, directive or root key from
  `llms.txt` turns the guard red, naming what is missing.
- Removing a `DOCUMENT_ROOTS`-only key turns it red, proving both configuration
  sources are reached through `allowedRootKeys()`.
- `composer check:code` green.
- **The guard refuses its own extraction failure.** Neutralise each extraction in
  turn — make it return nothing — and the guard must go red every time. A check
  that cannot refuse would make this whole stage decorative, and that is the
  defect this repository keeps finding in its own controls.

## Edge cases

- A command token appearing inside unrelated prose (`check` inside a sentence):
  match the token, not a bare substring, or the guard reports false presence.
- An option name shared by several commands with different meanings — `--format`
  on five, `--namespace` on two. The index describes them per command or not at
  all.

## Test plan

The guard's behaviour and its refusals as above, plus a strict docs build. No
product code runs in this stage.
