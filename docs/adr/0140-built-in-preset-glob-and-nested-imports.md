# 0140. Built-in Preset, Explicit Selector and Nested Imports

**Date:** 2026-10-30
**Status:** Accepted

**Builds on:** [ADR 0139](0139-configuration-imports.md). It replaces that
ADR's flat rule (§2), its refusal of glob characters (§5) and its "extension
path, not built" (§7) with what follows; a string entry stays a file path or a
preset name, and `*`, `?` and `[` are ordinary characters in it again; identity, path bases, per-file
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
2. **A set of files cannot be named.** A directory of parts that other tools
   or packages add to (`config/qmx`) needs its root edited for every new part.
3. **Only the root imports.** A shared standard cannot split itself; its
   consumer must list the package's internals.

Out of scope: `qmx.dist.yaml` discovery, stale values from a shared base,
re-basing value paths, and `imports` in a file given to `--preset`.

## Decision

### D1. Entry classification

A string entry with no `/`, no `\` and no extension is a **built-in preset
name**: `imports: [strict]`. Any other string is a **path**, judged as before:
`.yaml` or `.yml` in any letter case, so `team.YML` keeps working. A
**one-entry mapping** is a selector (D2). Anything else is refused.

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

### D2. Explicit selectors, not globs

A set of files is named by a `subtree` or `regex` selector decoded by
`SelectorYamlDecoder::decodePath()`, the decoder every path selector of the
configuration uses ([ADR 0077](0077-open-universe-selectors-are-explicit.md)):
`{subtree: config/qmx}` is a directory and everything below it,
`{regex: 'vendor/[^/]+/[^/]+/qmx-extension\.yaml'}` is a delimiterless PCRE
fragment that must match the whole relative path. `{exact: …}` is refused: one
file is a plain string, and one meaning has one spelling.

The first design of this ADR was a glob (`*`, `?`, `[...]` within one segment).
It was withdrawn before release. ADR 0077 removed the punctuation heuristic
("a value holding `*`, `?` or `[` is a glob") from every public selector over
paths, and `SelectorImplementationBoundaryTest` refuses `fnmatch()` in
production — it reddened on the glob's matcher. Keeping the exception would
have left `imports` as the one door where punctuation picks the semantics, and
a private grammar (no `**`, no braces, no escapes) beside the reviewed one.

**Universe.** Regular files whose name ends in `.yaml` or `.yml` (any case)
below the importing file's real directory, each named by a `RelativePath` from
that directory. A selector never reaches above it; a file above is a string
entry. Matching is the selector's own full-path match, so `regex` is
case-sensitive and `subtree` also selects a directory whose name looks like a
configuration file.

**Walk.** The walk is ours, not `glob()` or Finder: PHP's `glob()` skips an
unreadable directory without saying so, also with `GLOB_ERR` (measured on
macOS), so a silently shorter list would change what is enforced.

- `subtree` starts in its directory, which must exist as a directory. `regex`
  starts in the literal directory prefix of its fragment: the leading run of
  characters that are not regex metacharacters, less its last character when a
  quantifier follows (`acme?` may omit the `e`), cut after its last `/`; none
  when the fragment holds `|` anywhere. An empty prefix walks the importing
  file's directory. The prefix only bounds the walk and must exist as a
  directory; matching is always the full-subject match.
- Every directory entered must be listable **and** searchable, checked before
  it is listed. A directory with read but no search permission lists its names
  yet hides what they stand for (`stat` of each child fails), so a check on the
  listing alone yields an empty selector without an error — the defect the
  first implementation shipped with and review found. It is refused, as is one
  that cannot be listed.
- A symlinked directory is followed; each real directory is visited once, by
  identity, so a link loop ends. A file reachable under two names is named by
  the first in byte order of the walk.
- A matched `.yaml`/`.yml` name that is not a regular file (a pipe, a broken
  link) is refused like an explicit entry. A name the selector does not match is
  never judged, so a fixture in `vendor/` cannot fail an unrelated selector.
  This narrows the first reading of "a yaml-named entry that is not a regular
  file is refused", which would make a regex over `vendor/` unusable.
- Matches are sorted by byte order of the relative path. A selector skips a
  file named as a string anywhere in the same list, a file an earlier selector
  placed, and the importing file. Two string entries naming one file stay
  refused.

**Entries are judged in written order.** A first pass only learns which files
the strings name, without refusing, so a selector can leave them to their
explicit entry; refusals are raised in the second pass, in the order written.
The first implementation resolved all strings before any glob and so hid an
earlier refusal behind a later one.

**Zero matches are accepted and not reported.** That is the purpose of a
selector over optional files. Rejected: a warning (it prints on every run that
lacks the optional files) and a `-v` line (no route for a verbose-only notice
reaches the configuration stage).

**Order among the matches of one selector is decided by the C3 merge.** The
matches form a group. In the merge at the importing file a group fixes no order
among its members — only the files they import and the other entries do — and
where nothing decides, byte order of the path does (a later name is higher).
String entries are groups of one, so a graph without selectors linearizes
exactly as standard C3. These measurements are about "a set of matches", not
about the glob grammar. They were taken on a model of the merge over random
graphs with one set in the root, 20,000 per run:

| Strategy for the matches                | Result                                                                                    |
| --------------------------------------- | ----------------------------------------------------------------------------------------- |
| byte order fed to C3 as a written order | accepts 5,693 of the 10,354 graphs some order of the matches accepts                      |
| stable topological sort by "reaches"    | refuses 866 of the 20,000 that another order would accept                                 |
| groups (chosen)                         | accepts every graph some order accepts; plain lists identical to C3 on 20,000 flat graphs |

The smallest counterexample to byte order is `F: [set{addon, standard}]`,
`addon: [standard]`: byte order puts `standard` above `addon`, while `addon`
needs the opposite. Reachability misses constraints that are not reach edges:
`F: [set{a, b, c}]`, `c: [b, d]`, `d: [a]` (a pair derived through a
linearization) and `F: [set{a, b}, e]`, `e: [b, a]` (a later string entry).
The implementation agrees with the model on all 3,007 grouped graphs of up to
four files tried.

Rejected: a glob grammar (above); byte order as a written list; the
reachability pre-sort; refusing a set whose matches import one another.

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
`ResolvedImportEntry`, `ImportTarget`, `ImportSelection`, `ImportLinearization`.
No new namespace, for the reason ADR 0139 records for the chain. The element of
the `imports` list is declared as a per-layer value judged by `ImportListSchema`,
because a string-or-mapping element is not one of the engine's typed nodes; the
price is that the editor schema describes it in words and no longer rejects a
number or an empty string at that position (the engine still does).

## Residues

- **Monotonicity can fail for a file that imports a file which selects.** The
  inner file's byte-order tie-break is not visible to the outer merge as a
  constraint, so `G: [F, X]`, `F: [set{a, b}]`, `X: [b, a]` is refused although
  `b a F X G` exists. Measured: 138 of 10,853 random graphs with nested sets.
  The refusal names the conflicting entries; the author reorders them or names
  the matches explicitly.
- **A silent zero-match selector** (above), and **case-sensitive matching**
  (`team.YAML` is found by an explicit entry and by a `regex` written for it,
  but a pattern ending in `\.yaml` does not match it).
- **Descendants only.** A selector never reaches above the importing file's
  directory; and a nested file whose selector matches the file that imported it
  (both in one directory) is refused as a cycle, while a match of the importing
  file itself is skipped.
- **Walk cost is bounded by the literal prefix only.** A fragment without one
  walks the importing file's whole directory, `vendor/` and `node_modules/`
  included. A directory reached through two names is walked under the first
  name only, so a fragment that matches only the second name finds nothing.
- **A selector over `vendor/` hands every matching package the power to change
  the configuration**, the fail threshold included, and one over a name
  packages use for their own configuration (`qmx.yaml`) imports files written
  for other projects. Both are documented warnings, not refusals: no refusal
  can tell a trusted package from another one.
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
