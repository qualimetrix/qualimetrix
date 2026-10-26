# 0132. Editor Schema of the Configuration Document

**Date:** 2026-10-26
**Status:** Accepted

## Context

`qmx.yaml` had no editor support: no completion of its eighteen roots, of the
rule names or of their options, and a misspelt key surfaced only when
`qmx check` ran. Every key, its spellings, scalar forms, word sets, minimums,
shorthands and collection shapes are already declared once, as `NodeSchema`
facts registered by each owner and read by the document engine. A JSON Schema
written by hand beside them would be a second source of truth that drifts.

The engine is not only the declarations, though. The YAML loader changes the
shape of a document before the engine sees it (`[]` is an empty collection of
any kind, a map keyed 0..n is a list, Symfony expands the merge key `<<`),
owner closures judge formulas, selectors, regular expressions and paths, and
some judgements exist only after the layers merge. An editor, in turn, parses
YAML with its own parser and sees one file, never the presets beside it.

## Decision

### The promise: one file, form only, never stricter

`qmx.schema.json` is a draft-07 JSON Schema projected from the registered
declarations. It judges **one file as one authored layer** and promises form:
keys and their accepted spellings, scalar types, word sets, minimums, a
shorthand written beside its target in the same layer, and where `~` and empty
collections are admitted.

The promise is one-sided: **where the editor and the engine type a scalar
alike, the schema never refuses what the engine accepts.** It refuses only
what is malformed whatever the other layers say. It is narrowed explicitly:

- Outside it: whatever an owner's closure judges (formulas, selectors,
  regular expressions, paths, references to declared architecture layers,
  constraints between fields), whatever depends on merged layers, and opaque
  nodes, whose form only their owner reads — they project to a description
  and admit anything. Words that an owner's judge checks rather than a
  declared word set, such as `format` and `fail_on`, are plain strings.
- Outside it as a named limitation, not a defect: scalars that YAML parsers
  type differently. The product reads Symfony YAML; editors use their own
  parser, YAML 1.2 for the Red Hat extension. `1_000`, `0755` and dates can
  be accepted by one and flagged by the other.
- Retired root keys such as `exclude_paths` are refused by the loader with a
  sentence naming their replacement. The schema refuses them as unknown keys
  of a closed root, without that sentence. Retired keys inside a section are
  declared, and the schema carries their sentence as the description of a key
  it refuses.
- Wider than the engine on purpose: a list also admits a map whose keys are
  all integers. The loader reads only a map keyed 0..n as a list; JSON Schema
  cannot check the sequence, and refusing it would refuse a form the engine
  accepts. Every object also admits `<<`, because an editor's parser may leave
  the merge key in place where Symfony expands it.

### The oracle is the engine

A unit test of the projection compares its output with what its author
expected, so it shares the author's misreadings of the engine. The promise is
therefore held against the engine itself, twice:

- A differential test: each case is read by the product's YAML loader and
  judged by `DocumentComposer` against the registered document schema, and
  declares both verdicts and, for a schema refusal, where it refuses. A case
  may not declare "the engine accepts, the schema refuses"; a test that
  misstates the engine's verdict fails. Its texts with merge keys are read
  by Symfony on both sides, which expands `<<`, so they do not reach the
  schema's admission of a literal `<<`.
- A corpus of real texts: every tracked configuration and preset,
  `qmx.yaml.example`, and every `yaml` block of the website whose top-level
  keys are document roots. Each text the engine accepts must pass the
  committed `qmx.schema.json`, the file users receive, with a floor on the
  number of texts judged so that a selection that narrows silently fails.

The corpus was kept because it caught what the table did not. When it was
introduced, a planted mutation that typed every number option as an integer
passed every case of the table and was refused by sixteen real texts, among
them this repository's `qmx.yaml` and the `legacy` preset. The table is
written by the same hand as the mapping and shares its blind spots; the corpus
is what people actually write and copy.

Both run the validator in strict draft-07 mode, because its default mode skips
keywords such as `if`/`then` that editors honour, and a test would pass where
an editor flags. A freshness check in `check:artifacts` fails when a
declaration changed and the committed file did not.

### A closed vocabulary is a predicate with a derived judge

Rule names are a closed set the editor must enumerate. Before, `rules` was a
predicate vocabulary whose judge looked the name up in the rule registry, so
the names were invisible to anything reading the declarations.
`NameVocabulary::closed(names, refusal)` declares the list, and the judge is
derived from it: a member is admitted, any other name is refused with the
owner's wording. The list is the one source of truth for both the engine and
the editor.

- Not a new vocabulary kind: the engine branches on the kind where it reads
  and judges names, and a new kind would change each of those branches for no
  change in behaviour. The engine sees a predicate, exactly as before.
- Not `fixed`: a fixed vocabulary folds names with the spelling rule of keys,
  which would start accepting respelled rule names — a behaviour change.
- Not a list for the editor beside the judge: that is the second source of
  truth the decision removes.

A node may also carry a description, documentation for editors only; the rule
entries carry each rule's description.

### The projection lives in Configuration

`Analysis\Configuration\EditorSchema` reads only `NodeSchema` facts and is
reached through one entry, `EditorSchemaProjection::project()`. Configuration
cannot import Finding, which already depends on it, so whatever the projection
needs about rules arrives through the declaration (closed names,
descriptions). `scripts/generate-configuration-schema.php` is a thin entry
that collects the registered sections, as the merge-table generator does, and
writes or checks the file.

The alternative, the whole projection inside the script, was rejected: the
mapping needs unit tests per node kind, and a test root under `scripts/` costs
a set of registration addresses, several of which fail silently when missed.

### Spellings, completion and determinism

Each key is a property under its canonical spelling, so completion offers that
one. Its other accepted spellings reach the same definition through
`patternProperties`: accepted, not proposed. Closed rule names are matched
exactly as written. A retired key is a pattern whose schema refuses
everything, carrying its replacement sentence as the description. Patterns
use only the subset ECMA-262 and PCRE read alike, since editors evaluate them
as ECMA-262 and PHP validators as PCRE.

Repeated subtrees are pooled into `definitions` named by a hash of their
content, so names survive sections being added or reordered. The file carries
neither the product version nor `$id`, so it changes only when a declaration
does.

### Hosting

The file is committed at the repository root and generated, never edited.

- It ships in the Composer dist package, so
  `vendor/qualimetrix/qualimetrix/qmx.schema.json` is exactly the installed
  version. This is the recommended path.
- Every GitHub release attaches it as an asset uploaded from the tag's
  checkout, so the latest-release download address serves the file of the newest
  release whose workflow attached it (the asset is attached right after the
  release is created, so a brief gap is possible). The tag's raw address serves one fixed version.
- The phar does not carry it: editors read a schema from disk or by URL, never
  from inside an archive.
- Not the website: it can be redeployed by hand from `main` for a
  documentation-only change, and a schema served from there would stop
  matching the latest release.
- No `$id`: it would repeat a public address inside generated content and tie
  the file to one host.
- No per-version map in a public schema catalogue: it would need a change to
  someone else's repository on every release. An exact match is the vendored
  file or the tag address.

## Consequences

- A declaration change regenerates the schema; a stale committed file fails
  `check:artifacts`. Adding a key or a rule needs no hand-written schema.
- Users see form errors while typing; meaning errors still appear only from
  `qmx check`, and the documentation says so.
- An editor may disagree with `qmx` on scalars the two parsers type
  differently, and a schema from a different release may flag keys the
  installed version accepts or miss keys it added. Both are documented.
- Whether editors follow the redirect of the latest-release address is not
  verified; the documentation recommends the vendored file and does not
  promise the redirect.
- The admission of a literal `<<` is held only by the projection's unit
  tests: no test reads YAML the way an editor's parser does.
- A word set hidden in an owner's judge stays a plain string until its owner
  declares it as words.
