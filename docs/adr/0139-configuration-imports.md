# 0139. Configuration Imports

**Date:** 2026-10-27
**Status:** Accepted

**Builds on:** [ADR 0086](0086-one-configuration-document-merged-by-declared-policy.md),
whose extension point said importing a file would be "a layer like any other".

## Context

One configuration was one file. A project whose configuration grows — this
repository's `qmx.yaml` is mostly a generated architecture region — could not
keep its parts small and about one subject, and a base shared by several
projects had to be copied rather than overridden.

ADR 0086 already composes any number of ordered authored layers by each node's
declared policy, and `ConfigurationOrigin::importedThrough()` already let a
refusal name a file together with the file that named it. What was missing was
the key, the order, file identity, path bases, and a loader that could name an
imported file at all: `ConfigLoaderInterface::read()` took a path string, so a
refusal while reading a preset called it `configuration file "/abs/…"`.

## Decision

### 1. A root `imports:` list; each file is a layer

The root configuration file — auto-discovered `qmx.yaml`/`qmx.yml` or
`--config` — may write `imports:`, a list of file paths. Each entry becomes its
own `AuthoredLayer` with origin `ConfigFile(locator).importedThrough(root)`. No
new source kind and no new merge policy: every key combines by the policy it
already declares, so a later file replaces a scalar or a list, merges a map,
and adds to a set. Removing a set entry or appending to a list stays
unexpressible, as ADR 0086 records; issue
[#216](https://github.com/qualimetrix/qualimetrix/issues/216) holds that design.

The name follows the code's own vocabulary (`importedThrough`) and Symfony's
`imports:` with the same meaning. `extends` already names a layer criterion;
`includes` was the runner-up.

### 2. Flat order: the entries as written, then the root

`imports: [A, B]` in F gives the layers `A B F`: a later entry overrides an
earlier one, the root overrides all. The chain occupies the configuration-file
band (stage priority 20), above presets and below the command line, so a
file's base overrides a `--preset` exactly as the file does.

Only the root is read for `imports`. An imported file that writes the key — any
value, `[]` included — is refused at that key with the hint to list its bases
in the root. Also refused: the same file twice in the list, naming both
positions, and an entry naming the root itself. A preset that writes `imports`
is refused: no measured case asked for it, and whether two presets importing
one base share a placement, and how the near-config-name diagnostic treats an
imported preset base, are open questions. A file given both as `--preset` and
as an import is placed in both bands.

For a flat list the C3 linearization of section 7 yields exactly the written
order, so every configuration valid now stays valid when nesting is allowed.

### 3. Identity, not spelling

One file is one `Core\FileTarget\FileIdentity` (device, inode, type). `./a.yaml`,
`a.yaml` and a symlink to it are the same file. A target that cannot be
stat'ed, reports inode 0, or is not a regular file (a directory, a FIFO) is
refused before it is read — never a silent fallback to comparing paths, never
a blocking read.

### 4. Path bases

An entry resolves against the directory of the file that writes it, as that
file was opened — not its display name and not the process working directory.
Every other path in any file of the chain keeps the request's working directory
as its base, as in the root file today; re-basing value paths is out of scope.
The asymmetry is stated in the key's description and on the configuration page.

An imported file's display locator joins the root locator's directory with the
entry and normalizes it lexically; the file itself is opened by its physical
path, so through a symlinked directory followed by `..` the two can differ.

### 5. Declared once, read per file, never composed

`ConfigurationRoot::Imports` declares the key, so the root dictionary, the
spelling refusals (`Imports:` refused with the canonical spelling, `import:`
answered with "did you mean") and the editor schema come from one declaration.
Its grammar — non-empty strings, not a URL, no glob character, a `.yaml` or
`.yml` extension in any case — is judged in each layer by the element's own
judgement; the loader's supported extensions are a public constant of
`YamlConfigLoader`, and the uncalled `ConfigLoaderInterface::supports()` is
gone.

The chain finds the literal `imports` child of the root (the key has exactly
one accepted spelling), composes a one-key slice of it against that
declaration so refusals are positioned at `imports[N]`, and hands the composer
every layer without the key. `ConfigSchema::PER_FILE_ROOTS` names such roots:
they belong to `allowedRootKeys()` but have no `ENTRIES` row and are not
`DOCUMENT_ROOTS`. `ConfigurationPipeline` refuses, as a programmer error, to
compose a layer that still carries one, and the merge-table generator describes
them as read per file. A flag on `SectionDeclaration` was rejected: any owner
could then claim "never merged" in the documentation while `DocumentSchema`
still merged the key.

### 6. The loader takes an origin

`ConfigLoaderInterface::read(string $physicalPath, ConfigurationOrigin $origin)`.
Every whole-document refusal (missing, unreadable, unparseable, not a mapping,
retired root key) carries that origin, importer chain included, and words its
subject through `describe()`. A preset's read refusal and a missing preset file
now name `preset "…"`. For a missing imported file the chain's identity check
speaks first, in the same wording.

### 7. Extension path: nested imports (not built)

Nesting is lifted by allowing `imports` in imported files and ordering by C3
linearization. A file's linearization `lin(F)` lists, lowest first, every file
contributing to F, with F last.

- **Computation.** Standard C3 (Python's MRO) is given F's imports *reversed*
  as bases, because the written list is last-wins while C3 reads highest
  priority first. Results are memoized highest-first and reversed once, at the
  output. Any other mapping is detectable on the diamond (`D B A F`, `F B A D`,
  `F A B D` are all wrong) and flips the verdict of `F: [D, A]` against
  `F: [A, D]` with `A: [D]`.
- **Constraints kept.** F above each file it imports; in one list a later entry
  above an earlier one; and monotonicity — `lin(F)` computed with F as root is
  a subsequence of the linearization of every file importing F, so a file means
  the same whatever imports it.
- **What is refused.** A graph is refused exactly when the C3 merge at some
  file has no admissible head. That is stricter than the first two constraints:
  `F: [a, b, c]`, `c: [b, d]`, `d: [a]` has an order satisfying them
  (`a b d c F`) but is refused, because `lin(c)` = `b a d c` puts `a` above `b`
  — a pair `c` never wrote, derived through `d: [a]` — while F demands the
  opposite. Over all graphs up to five files, 6 of 1 011 solvable graphs are
  refused, all at five files. This is the price of monotonicity; a topological
  sort with a tie-break would change accepted orders (149 of about 208 000
  random 6–8 file graphs) and lose it.
- **Cycles first.** An in-progress stack holding the root from the start and a
  done-set, both keyed by `FileIdentity` (a path key recurses forever on
  `./qmx.yaml`), detect cycles before C3 runs; the refusal reports the stack
  slice from the first occurrence of the repeated file. The done-set also keeps
  diamonds from re-reading files.
- **The refusal of an unsolvable graph** names the file whose merge got stuck
  (possibly an inner one), a cycle among the remaining chains with each edge
  tagged by its owner — a written list with entry positions, or a derived
  linearization printed whole so the author sees the pair was derived — the
  chain by which each conflicting file was reached, and the hint to reorder or
  drop one entry.

| Graph                                        | Order (lowest first)                      |
| -------------------------------------------- | ----------------------------------------- |
| `F: [A, B]`                                  | `A B F`                                   |
| `F: [A, B]`, `A: [D]`, `B: [D]` (diamond)    | `D A B F`                                 |
| `F: [D, A]`, `A: [D]`                        | `D A F`                                   |
| `F: [A, D]`, `A: [D]`                        | refused                                   |
| `F: [A, B]`, `A: [D]`, `B: [C, D]`           | `C D A B F`                               |
| `G: [H, B]`, `H: [Q]`, `B: [X, Y]`, `Y: [Q]` | `X Q H Y B G` (B alone: `X Q Y B` — kept) |
| `F: [a, b, c]`, `c: [b, d]`, `d: [a]`        | refused (derived pair)                    |

Rejected for nesting: re-placing a repeated file (a later copy silently undoes
an earlier override); refusing every repeat (it kills the shared-base diamond);
first placement plus a sibling check (it refuses solvable graphs and does not
keep a file's meaning). Lifting the restriction also needs a fresh look at the
near-config-name diagnostic and at imports in presets.

## Consequences

- A configuration can be split by subject and a shared base overridden rather
  than copied, with every refusal naming the file and its importer, in text and
  in JSON (`source[].imported_by`), and `-v` listing each file in precedence
  order.
- `architecture.unmatched-type` identity includes the layer index and origin
  chain of the write naming the type. Moving that criterion into an imported
  file re-identifies the finding and its baseline entry goes stale, as adding a
  preset already does. Whether identity should drop provenance is issue
  [#214](https://github.com/qualimetrix/qualimetrix/issues/214); a test pins
  today's behaviour.
- `check --output` is guarded only against `--config` and `--baseline`, so it
  can overwrite an imported file, as it can the auto-discovered `qmx.yaml`
  (issue [#215](https://github.com/qualimetrix/qualimetrix/issues/215)).
- The chain lives in `Loader/` beside the file reader rather than in a sibling
  namespace of its own: measured, a separate namespace raised the
  `Analysis\Configuration` namespace's coupling above its accepted ceiling.
