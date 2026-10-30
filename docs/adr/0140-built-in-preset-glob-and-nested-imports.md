# 0140. Built-in Preset, Glob and Nested Imports

**Date:** 2026-10-30
**Status:** Accepted

**Builds on:** [ADR 0139](0139-configuration-imports.md). It replaces that
ADR's flat rule (§2), its refusal of glob characters (§5) and its "extension
path, not built" (§7) with what follows; identity, path bases, per-file
reading and the origin-taking loader are unchanged.

## Context

Three gaps of `imports:` were measured in a sandbox run of `bin/qmx`:

1. **A built-in preset cannot be named from a configuration file.**
   `--preset` reaches it only from the command line. The pre-commit hook runs
   `check <files>` (`PreCommitHook`) and `action.yml` has no `preset` input, so
   the hook and CI judge by different rules from a developer's
   `qmx check --preset=strict`. The workaround imports
   `vendor/qualimetrix/qualimetrix/src/Analysis/Configuration/Preset/strict.yaml`,
   an internal path that does not exist in the phar or the Docker image.
2. **Globs are refused.** A directory of parts that other tools or packages
   add to (`config/qmx/*.yaml`) needs its root edited for every new part.
3. **Only the root imports.** A shared standard cannot split itself; its
   consumer must list the package's internals.

Out of scope: `qmx.dist.yaml` discovery, stale values from a shared base,
re-basing value paths, regex entries, and `imports` in a file given to
`--preset`.

## Decision

### D1. Entry classification, in this order

1. An entry containing `*`, `?` or `[` is a **glob**, judged by D2 — so `*` and
   `str*` are refused for their missing extension, never as unknown presets.
2. An entry with no `/`, no `\` and no extension is a **built-in preset name**:
   `imports: [strict]`.
3. Anything else is a **path**, judged as before: `.yaml` or `.yml` in any
   letter case, so `team.YML` keeps working.

A bare name that is not built-in is refused at its position: `Unknown preset
"x". Available presets: ci, legacy, strict. A file needs a .yaml or .yml
extension.` The preset's origin is `Preset(name)` imported through the file
that names it; its applied-source name is the bare name (`strict`), distinct
from the `preset:strict` of the `--preset` band, because applied sources are
deduplicated by name and a preset given both ways must be applied in both
places. Outside the phar its key is its file identity, so the name and the
vendor path of one file are refused as the same file twice.

Rejected: the `--preset` grammar as it is (its extension check is
case-sensitive, so `base.YAML` would become an unknown preset); a prefix such
as `preset:strict` (a second spelling of one name).

### D2. Globs

`*`, `?` and `[...]` match within one path segment. Refused by the grammar:
`**`, `{`, a backslash (no escaping) and a `[` without its `]`. The extension
rule is judged on the last segment. Matching is case-sensitive (`*.yaml` does
not match `team.YAML`) and a wildcard does not match a leading dot.

The walk is our own (`scandir` and `fnmatch`), not `glob()`, which skips an
unreadable directory without saying so, also with `GLOB_ERR` (measured on
macOS), so a silently shorter list would change what is enforced. A directory
the walk must list or traverse and cannot is refused; an intermediate
candidate that is not a directory (`vendor/autoload.php` under `vendor/*/…`)
is passed over; the literal directory before the first wildcard must exist; a
final match that is not a regular file is refused as an explicit entry is.

A glob skips a file named explicitly anywhere in the same list, a file an
earlier glob placed, and the importing file; one file reached twice by one glob
(a symlinked sibling directory) is placed once. Two explicit entries naming one
file stay refused.

**Zero matches are accepted and not reported.** That is the purpose of a glob
over optional files. Rejected: a warning (it prints on every run that lacks the
optional files) and a `-v` line (no route for a verbose-only notice reaches the
configuration stage). The residue is a typo after the first wildcard
(`*.yml` for `*.yaml`), which matches nothing and says nothing.

**Order among the matches of one glob is decided by the C3 merge.** The matches
form a group. In the merge at the importing file a group fixes no order among
its members — only the files they import and the other entries do — and where
nothing decides, byte order of the path does (a later name is higher).
Explicit entries are groups of one, so a graph without globs linearizes
exactly as standard C3. Measured on a model of the merge over random graphs
with one glob in the root, 20,000 per run:

| Strategy for the matches                       | Result                                                                 |
| ---------------------------------------------- | ---------------------------------------------------------------------- |
| byte order fed to C3 as a written order        | accepts 5,693 of the 10,354 graphs some order of the matches accepts   |
| stable topological sort by "reaches"          | refuses 866 of the 20,000 that another order would accept              |
| groups (chosen)                                | accepts every graph some order accepts; plain lists identical to C3 on 20,000 flat graphs |

The smallest counterexample to byte order is `F: [glob{addon, standard}]`,
`addon: [standard]`: byte order puts `standard` above `addon`, while `addon`
needs the opposite. Reachability misses constraints that are not reach edges:
`F: [glob{a, b, c}]`, `c: [b, d]`, `d: [a]` (a pair derived through a
linearization) and `F: [glob{a, b}, e]`, `e: [b, a]` (a later explicit entry).
The implementation agrees with the model on all 3,007 grouped graphs of up to
four files tried.

Rejected: byte order as a written list; the reachability pre-sort; refusing a
glob whose matches import one another.

### D3. Nested imports

Any file read through `imports` may write `imports` — built-in presets
included, though none does. A file given to `--preset` still refuses it. The
order is §7 of ADR 0139 unchanged: C3, each list reversed as bases, with the
groups of D2.

- **Cycles** are detected by a stack keyed by target identity and seeded with
  the root, and refused at the closing entry (`imports[N]` of the file that
  names a file on the stack), printing the stack slice. An entry naming its own
  file keeps the earlier wording.
- A file reached twice is read once and named by the first chain that reaches it
  (depth-first, written order).
- **An unsolvable merge** is refused on the stuck file's origin, naming the
  cycle of constraints with each edge tagged by its owner (a written list with
  entry positions, or a derived linearization printed whole), how each file of
  the cycle was reached, and the hint to reorder or drop an entry.
- Every file names its own entries relative to its own directory, or its real
  directory when it is a symlink or a built-in preset.

The near-config-name diagnostic is unaffected: it runs only when no root
configuration exists.

### D4. Placement

The classes live in `Loader/` beside the chain: `ImportEntries`,
`ResolvedImportEntry`, `ImportTarget`, `ImportGlob`, `ImportLinearization`.
No new namespace, for the reason ADR 0139 records for the chain.

## Residues

- **Monotonicity can fail for a file that imports a file which globs.** The
  inner file's byte-order tie-break is not visible to the outer merge as a
  constraint, so `G: [F, X]`, `F: [glob{a, b}]`, `X: [b, a]` is refused although
  `b a F X G` exists. Measured: 138 of 10,853 random graphs with nested globs.
  The refusal names the conflicting entries; the author reorders them or names
  the matches explicitly.
- **A silent zero-match glob** (above), and **case-sensitive matching**
  (`team.YAML` is matched by an explicit entry but not by `*.yaml`).
- **A glob over `vendor/` hands every matching package the power to change the
  configuration**, the fail threshold included, and a glob over a name packages
  use for their own configuration (`qmx.yaml`) imports files written for other
  projects. Both are documented warnings, not refusals: no refusal can tell a
  trusted package from another one.
- Value paths in imported files keep the working directory as their base
  (ADR 0139 §4).

## Consequences

- The hook and the GitHub Action apply a preset through the configuration file
  they already read, so `imports: [strict]` makes them agree with local runs.
  A preset given with `--preset` is still not imported by anything, and a file
  given to `--preset` still cannot import.
- Every refusal and `-v` line keeps naming a file with its importer chain; JSON
  `source[].imported_by` nests as deep as the chain, and may name a preset.
- An imported preset is a layer in the configuration-file band, above a
  `--preset` preset of the same name; a file that imports a preset and an
  identical `--preset` applies it twice, at two precedences.
- The filesystem behaviour the walk is built around (unreadable directories,
  symlinked directories, `..` after a wildcard) was measured on macOS only.
