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
a blocking read. A failed `stat` reports no reason PHP can read, so such an
entry is refused as "not found or not accessible".

These refusals are raised on the imported file's origin, so text and JSON
(`source[].imported_by`) name the missing file together with its importer, as
the loader's own refusals of an imported file do. The price is the position:
the refusal does not point at `imports[N]` of the root, and a JSON consumer is
handed a file that may not exist; the importer and the entry's name in the
sentence locate it instead. The same file written twice is refused on the root
at the later entry, `imports[N]`, naming both positions; an entry naming the
root itself is refused at that entry.

### 4. Path bases

An entry resolves against the real directory of the file that writes it — a
root that is a symlink resolves against its target's directory, so a
configuration linked into a project reads the bases beside it — not against
its display name and not the process working directory.
Every other path in any file of the chain keeps the request's working directory
as its base, as in the root file today; re-basing value paths is out of scope.
The asymmetry is stated in the key's description and on the configuration page.

An imported file is named by a path that leads to the file read: the root
locator's directory joined with the entry, with `.` and empty segments dropped
and `x/..` kept as written, because after a symlinked directory it leads
elsewhere than collapsing it would say. When the root file is itself a symlink,
its locator's directory is the link's, not the one entries resolve against, so
its imports are named by their physical path instead. Either way two different
files never share a name. A physical name is specific to the checkout's
location, and it reaches `architecture.unmatched-type` identity like any origin
(see Consequences).

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
  preset already does; under a symlinked root, whose imports are named by
  their physical path, it also differs between checkouts in different
  directories. Whether identity should drop provenance is issue
  [#214](https://github.com/qualimetrix/qualimetrix/issues/214); a test pins
  today's behaviour.
- `check --output` is guarded only against `--config` and `--baseline`, so it
  can overwrite an imported file, as it can the auto-discovered `qmx.yaml`
  (issue [#215](https://github.com/qualimetrix/qualimetrix/issues/215)).
- The chain lives in `Loader/` beside the file reader rather than in a sibling
  namespace of its own: measured, a separate namespace raised the
  `Analysis\Configuration` namespace's coupling above its accepted ceiling.

## Amendment, 2026-10-28: an unmatched type is its type and its path

The second **Consequences** bullet, and §4's remark that a physical name
reaches `architecture.unmatched-type` identity, record what was decided then.
The identity of an `architecture.unmatched-type` occurrence is now the type
and its authored path (`architecture.layers[0].implements[1]`) alone; the
writing file, its importer chain, its layer index and its line are named in the
message and no longer identify it (issue
[#214](https://github.com/qualimetrix/qualimetrix/issues/214)).

Measured on one project with the same missing type at
`architecture.layers[0].implements[0]`, before the change: three keys for one
finding — in the root, moved into an imported file, and in the root with a
`--preset` below it — and the root's key also changed when the root merely
gained an import that writes no `architecture`. After it, one key for all four.

Dropping the writer cannot merge two findings. `architecture.layers` is
replaced whole by the last layer that writes it, so every criterion of one run
comes from one file and the path names one value of it. Measured: a root and an
import, or a root and a preset, that both write the type at the same path give
one finding, the root's. The line carries nothing either — the YAML loader
records none, so it was a constant. What is kept: an occurrence at another path
— a layer inserted above, a criterion moved to another key — is another
occurrence, as an edit of the authored position should be.

The single-writer premise is checked where identities are formed:
`ArchitectureConfiguration::namedTypes()` refuses, as a programmer error, two
writes of one type at one path with different provenance. A list that appends
across files (issue [#216](https://github.com/qualimetrix/qualimetrix/issues/216))
breaks the premise and must add the writer back to the identity then; until it
does, such a configuration reaches that refusal instead of merging two
findings silently.

`composer gate -- --reference=15b9a9456dfa576d6a4a7d7ec6b673541ba86866`
was RED without declarations: the `layers` corpus case's one
`architecture.unmatched-type` record changed occurrence, and with it the JSON,
SARIF, GitLab and `baseline:explain` surfaces. It is GREEN with the record
withdrawn and introduced and the three whole-invocation surfaces declared as
exact surfaces — identity tokens only (exit 0, at this change's head, with the
write guard of the next amendment in place).

The baseline consequence is one-time: every accepted `architecture.unmatched-type`
entry goes stale once and the finding is reported anew, as with any identity
change (see `CHANGELOG.md`).

## Amendment, 2026-10-28: a written file is never a file the configuration was read from

The third **Consequences** bullet records the guard as it was. A command that
writes now refuses, before analysis, a target that is any physical file an
authored layer was read from — the root (discovered or `--config`), each
import, each preset — compared by `FileIdentity` (issue
[#215](https://github.com/qualimetrix/qualimetrix/issues/215)).

The set is not a list of option names. The YAML loader reads the bytes and
`fstat()`s the same handle, so the identity is that of the file parsed, not of
whatever the path names a moment later. `AuthoredLayer` carries it into the
document's applied sources, and `ConfigurationDocument::fileReadAs(?FileIdentity)`
answers which file read has a given identity, named as refusals name it
(`configuration file "base.yaml" (imported by configuration file "qmx.yaml")`).
Configuration answers the question; the writers ask it without depending on a
Configuration type. A file read through another stream wrapper than the plain
filesystem — a built-in preset inside the phar — gets no identity: its
synthetic device and inode could equal a real file's, and no written target can
be a wrapper path. The handle, not the spelling, decides: `a://q.yaml` names a
plain file. A plain file reporting inode 0 gets none either and is not guarded;
unlike an import (§3), refusing to read it would make such a filesystem
unusable for the root configuration.

The writing commands, enumerated by their write path rather than by name:

| Command                                                                            | Writes                                | Reads configuration | Guarded by                                                       |
| ---------------------------------------------------------------------------------- | ------------------------------------- | ------------------- | ---------------------------------------------------------------- |
| `check`                                                                            | `--output`, `--profile`, `--log-file` | yes                 | `RunTargets::claim($document->fileReadAs(...))`                  |
| `graph:export`                                                                     | `--output`                            | yes                 | `RunTargets::claim()`, the question from `PreparedAnalysisInput` |
| `baseline:generate`                                                                | the baseline argument                 | yes                 | `BaselineRunInterface::measure()`                                |
| `baseline:update`, `baseline:cleanup`                                              | the baseline argument                 | yes                 | `BaselineRunInterface::measure()`, behind the baseline grammar   |
| `baseline:rename-channels`                                                         | the baseline argument                 | no                  | —                                                                |
| `hook:install`, `hook:uninstall`                                                   | the Git hook and its backup           | no                  | —                                                                |
| `baseline:explain`, `directives`, `debug:layer-assignment`, `rules`, `hook:status` | standard output only                  | some                | —                                                                |

The question is an explicit argument at every guarded write, not state left on
a shared service: `RunTargets::claim()` takes it as a required parameter and refuses as `--output and configuration file
"qmx.yaml" name the same input target`. `BaselineRunInterface::measure()` takes
a closure it calls with the question once the configuration resolves and
before anything is analysed; the baseline commands pass one refusing their
baseline file as a configuration error (`Baseline "qmx.yaml" is configuration
file "qmx.yaml"; a baseline cannot replace …`), like their other refusals of
the baseline argument. A first version registered the files on `RunTargets`
from the shared runtime setup; review found that an unregistered list could not
be told from an empty one and that a list outlived the command that set it, and
tests that give a command its own `RunTargets` showed the coupling was real.
For `baseline:update` and `baseline:cleanup` the baseline is read before the
configuration, so a configuration file is refused first as an invalid baseline;
the guard stands behind that grammar.

Not covered: standard output redirected by the shell into a configuration
file (`>`, `>>`, `1<>`) — the shell opens it before qmx starts, `>` has
already truncated it, and a refusal printed to standard output would land in
it too; `composer.json`, which `ComposerDiscoveryStage` reads through the
project-manifest snapshot rather than as an authored layer; and the analysed
sources. The last two are the same harm over a different input set.

## Amendment, 2026-10-28: a written file is never a file the run reads

The previous amendment's **Not covered** paragraph named three gaps; two are
closed and the third is narrowed. The question the writers ask is now
`ResolvedRunConfiguration::fileReadAs(?FileIdentity)`, built once by
`RunConfigurationPreparation` — the step `check`, the `graph:export`
preflight and `BaselineRun` already share — so no writer gained a type.
`FileIdentity`'s inbound coupling rose above the class threshold with it and is
an exact exception in `qmx.yaml`: every reader that records an input and every
writer comparing one must name it. It answers for three input kinds:

- **Configuration files**, as before, through `ConfigurationDocument::fileReadAs()`.
- **The project manifest.** `ComposerDiscoveryStage` contributes a layer
  carrying the identity of the `composer.json` it read whenever the manifest
  was read (valid or not), also when it declares no autoload target, so the
  document answers `composer.json`. The stage states the file by path just
  after the reader's only read of it in the invocation: every later reader is
  served that snapshot, not the file, and all of them — the stage, the scope
  coverage, the project source binding, the baseline coverage and the HTML
  metadata — read the document's working directory. Recording the identity
  inside the reader was measured first and rejected: it added a Core type to
  the Composer adapter namespace and a parameter to `ComposerManifestFacts`,
  both above their accepted ceilings.
- **Source files.** `ProjectFilesInterface::select()` returns the files the
  walk of `discover()` accepts — analysed, excluded as generated (whose headers
  are read), or a second name of either — without reading a file. The answer is
  lazy and asked only for a regular-file identity, so a target that does not
  exist yet, a terminal or a pipe walks nothing. Measured on 41,390 files, warm:
  the whole question takes 1.2–1.4 s, of which the walk is 1.1 s and the
  identity comparison 0.13 s; `discover()` would add 2.2–3 s of header reads,
  which is why the selection stops before the generated-file filter. The
  analysis of the same tree took 687 s. The question asks the unscoped run: a
  Git report scope narrows the reported paths, not the walk.

The check must run before the targets are claimed, not after discovery inside
the pipeline: `--log-file` is opened for appending at claim, and the pipeline
logs before it discovers, so a refusal after discovery would already have
appended to a source.

**Standard output.** When `check` or `graph:export` reports on standard output,
`RunTargets::claim()` asks the same question about descriptor 1, before any
other target: a target refusal raised first would be written to that input in
the requested format. A match is refused with
`EnvironmentRefusal::aboutStandardOutput()`, which `RunTargetSession` presents
with no envelope format, so the refusal goes to stderr and nothing reaches the
input. `>>` and `1<>` are prevented; `>` has already emptied the file before
qmx starts, and the refusal then says which input the shell destroyed. A
refusal reached before the inputs are known — an invalid option value, a
configuration that does not resolve — is still presented in the requested
format on standard output: which file standard output is cannot be judged
against inputs nobody has read yet.

A `composer.json` that declares no autoload target is now an applied source,
so `check -v` lists it under "Configuration loaded from". The selection walk is
measured against a cold analysis; with a warm AST cache the analysis is
shorter and the walk a larger share of it, still paid only when a target or
redirected standard output is an existing regular file.

Still not covered: Composer files read at other roots —
`ComposerAutoloadMap` asks `InstallLocator` for manifests up to twelve levels
above each analysed path and reads `vendor/composer/installed.json`, the
generated classmap and vendor class files, lazily after claim; the standard
output of the baseline commands, which print their outcome and some refusals
there; and standard error.
