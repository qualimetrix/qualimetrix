# Configuration

## Subject and current boundary

`Analysis\\Configuration` owns loading, normalizing, ordering, validating, and
resolving the ordered `ConfigurationDocument` used for one analysis invocation.
It owns source resolution and schema semantics, not a cross-owner runtime DTO.
Each feature resolves its own immutable projection from that concrete document;
owner-local runtime state exists only where a long-lived service needs it.

The document is a narrow public source seam: it carries the invocation working
directory, a resolved document with provenance and diagnostics, and Composer's
two non-authored autoload-target facts. Finding declares `rules`, `only_rules`
and `disabled_rules` and reads their resolved nodes and authored history.
There are no raw rule contribution getters. Symfony input remains inside the Console adapter.
It does not expose a generic configuration interface, a universal invocation
context, or a carrier for feature fields.

## Structure

```text
Configuration/
├── Contract/
│   ├── Document/
│   │   ├── Schema/
│   │   │   ├── BareElementPolicy.php
│   │   │   ├── DocumentSectionSchemaInterface.php
│   │   │   ├── IntegerJudgement.php
│   │   │   ├── KeyDictionary.php
│   │   │   ├── MergePolicy.php
│   │   │   ├── NameVocabulary.php
│   │   │   ├── NodeCollectionFacts.php
│   │   │   ├── NodeMapFacts.php
│   │   │   ├── NodeScalarFacts.php
│   │   │   ├── NodeSchema.php
│   │   │   ├── NodeWording.php
│   │   │   ├── RefusedName.php
│   │   │   ├── ScalarForm.php
│   │   │   ├── SchemaWordSet.php
│   │   │   ├── SectionDeclaration.php
│   │   │   ├── Shorthand.php
│   │   │   ├── TextRequirement.php
│   │   │   └── WordComparison.php
│   │   ├── ConfigurationDiagnostic.php
│   │   ├── Provenance.php
│   │   ├── ResolvedBareNameInterface.php
│   │   ├── ResolvedDocument.php
│   │   ├── ResolvedListInterface.php
│   │   ├── ResolvedMapInterface.php
│   │   ├── ResolvedOpaqueInterface.php
│   │   ├── ResolvedValueInterface.php
│   │   └── ResolvedWriteHistoryInterface.php
│   ├── Pipeline/
│   │   ├── CommandLinePathWrite.php
│   │   ├── ConfigurationPipelineInterface.php
│   │   └── ConfigurationResolutionRequest.php
│   ├── Refusal/
│   │   ├── ConfigurationOrigin.php
│   │   ├── ConfigurationRefusal.php
│   │   ├── ConfigurationSource.php
│   │   ├── RefusalInterface.php
│   │   └── RefusedPosition.php
│   └── ConfigurationDocument.php
├── Document/
│   ├── Resolved/
│   │   ├── ResolvedBareName.php
│   │   ├── ResolvedList.php
│   │   ├── ResolvedMap.php
│   │   ├── ResolvedOpaque.php
│   │   └── ResolvedScalar.php
│   ├── AuthoredLayer.php
│   ├── AuthoredNode.php
│   ├── AuthoredShape.php
│   ├── DocumentComposer.php
│   ├── DocumentSchema.php
│   ├── EmptyListOverrides.php
│   ├── KeyClaims.php
│   ├── KeyRecognition.php
│   ├── LayerMerge.php
│   ├── LayerReading.php
│   ├── NameRecognition.php
│   ├── NamedEntryReading.php
│   ├── PendingName.php
│   ├── ReadingContext.php
│   ├── ScalarConstraints.php
│   ├── ShorthandExpansion.php
│   ├── ShorthandTarget.php
│   ├── WrittenForm.php
│   └── WrittenNames.php
├── EditorSchema/
│   ├── DefinitionPool.php
│   ├── EditorSchemaJson.php
│   ├── EditorSchemaProjection.php
│   ├── NodeProjection.php
│   ├── NullAdmission.php
│   ├── PooledNode.php
│   ├── PortableRegex.php
│   ├── ScalarProjection.php
│   └── ShorthandProjection.php
├── Loader/
│   ├── CommandLineLayer.php
│   ├── CommandLineSyntax.php
│   ├── CommandLineValue.php
│   ├── ConfigLoaderInterface.php
│   ├── DocumentKeyNormalizer.php
│   ├── ImportChain.php
│   ├── ImportEntries.php
│   ├── ImportGlob.php
│   ├── ImportLinearization.php
│   ├── ImportListSchema.php
│   ├── ImportTarget.php
│   ├── LoadedDocument.php
│   ├── ResolvedImportEntry.php
│   ├── RootContainerShapes.php
│   ├── SectionNormalizationPolicy.php
│   └── YamlConfigLoader.php
├── Pipeline/
│   ├── Stage/
│   │   ├── CliStage.php
│   │   ├── ComposerDiscoveryStage.php
│   │   ├── ConfigFileStage.php
│   │   ├── DefaultsStage.php
│   │   └── PresetStage.php
│   ├── ConfigurationLayer.php
│   ├── ConfigurationPipeline.php
│   └── ConfigurationStageInterface.php
├── Preset/
│   └── PresetResolver.php
├── ConfigKeySpelling.php
├── ConfigSchema.php
├── ConfigurationRoot.php
├── DocumentRoots.php
├── RetiredSuppressionOptions.php
└── SelectorYamlDecoder.php
```

`Document/` owns written nodes, layer identity and positioned reading
context. Both document composition and name binding consume these facts; authored
facts depend on neither operation.

## Resolution model

`ConfigurationPipelineInterface` runs ordered stages over a
`ConfigurationResolutionRequest`, then produces `ConfigurationDocument`.
`ConfigSchema` names canonical ingress keys and keeps the legacy flat mappings;
it is not the authority for value form or merge semantics. `ConfigurationRoot`
declares Configuration-owned roots, and a capability declares its own root
through `DocumentSectionSchemaInterface`. The precedence order, lowest first,
is defaults, Composer discovery, presets, the configuration file with the
files it imports, and CLI options — the stage priorities 0, 10, 15, 20 and 30. Stages preserve each
source contribution in that order; the document engine merges each declared
root by its `NodeSchema`. A scalar normally takes the last writer, while the
declared collection policy determines whether a list replaces or a set
accumulates. Composer discovery contributes the production and `autoload-dev`
targets — every autoload form, `psr-4`, `psr-0`, `classmap` and `files`, the
list the scope denominator also reads — under two internal keys rather than
`paths`: `include_autoload_dev`, which a later source may write, decides which
of them Run takes as the default paths. The lists are the manifest as written:
Run, not the reader, drops a target that lies inside `vendor`, `node_modules`
or `.git`, because that is Run's discovery rule.

A key is written once per document. `suppress_paths`, `suppress-paths` and
`suppressPaths` fold into one key, so writing two of them in one mapping is
refused rather than resolved by whichever comes last. Any other spelling of
the same words (`Fail_On`, `FAIL_ON`) is refused with the canonical key
offered, at every depth the engine reads. A refusal about a key answers in the
spelling its author used.
`RetiredSuppressionOptions` holds the retired suppression spellings and their
replacement hint. Registered owner schemas reject those spellings before merging,
while authored nodes still retain the spelling, path and layer. `ConfigKeySpelling`
provides the shared spelling vocabulary; no later raw rule-option walk guesses
what an author wrote.

`ConfigurationDocument` exposes the resolved document, its diagnostics and the
working directory to named owners. `fileReadAs(?FileIdentity)` answers which
physical file an authored layer was read from — root, imports, presets — has a
given identity, named as refusals name it, or null. The identity is the one the
loader took from the handle it read through (none for another stream wrapper
than the plain filesystem, or inode 0), so a command that writes can refuse a
target that is one of those files without depending on a Configuration type.
The `composer.json` the composer stage read is a source of its own with its
file identity, also when it declares no autoload target. Architecture reads its registered
`architecture` section from the resolved document after the Console logger
exists, then returns typed warnings through its own contract. ComputedMetrics
likewise reads its registered `computed_metrics` and `exclude_health` sections
and publishes an instance-owned catalog only after full validation. The central
pipeline neither contains an Architecture or ComputedMetrics object nor
transports feature-specific deferred warnings. Cache, Coupling, Console,
Parallel, Run and Reporting likewise resolve only their declared values; this
does not turn the document into a cross-owner runtime DTO.

The immutable schema exposes its facts through `scalar`, `map`, `collection`,
`wording` and `layerJudge`. A list's element belongs to
`collection->element`; a fixed named map's body belongs to `map->entry`,
while dynamic entries use `map->entryForName(name)`. Required missing element
schemas refuse. Scalar vocabularies use `words(SchemaWordSet::of(...))` or
`words(SchemaWordSet::foldingCase(...))`; non-empty vocabularies may include a
blank word, preserving the former document declaration's domain. The separate
`nonEmpty()` constraint decides whether authored blank text is accepted.
The public migration is recorded in
[ADR 0092](../../../docs/adr/0092-typed-document-declarations-and-option-judgement.md).

## Configuration-file discovery

Automatic discovery compares directory entries byte for byte with `qmx.yaml`
and `qmx.yml`. Both exact names together refuse with exit 3; specify one through
`--config`. With neither exact name, a near name produces a document diagnostic
rather than being loaded. An exact name beside a near name is loaded without
that warning. Explicit `--config` selects its named file directly.
An unlistable search directory refuses instead of inferring that no config exists.
The loader reads the physical file while auto-discovered `qmx.yaml` or
`qmx.yml` is named by its filename in authored origins, diagnostics and
refusals. An explicit `--config` keeps the supplied path as its source name;
a preset is named as it was written (`preset "strict"`, `preset "./team.yaml"`),
including when its file cannot be read. Directory discovery refusals name `.`
and near-file diagnostics name the observed directory entry.
Every document-reading command uses this same stage and judges the complete
context-free document before consuming its own values. Console's closed
preflight profile controls CLI ingress and actual consumers, not the schema.

## Configuration imports

The root configuration file — the discovered one or `--config` — may list
other files under `imports`, and any file read through `imports` may do the
same. `ConfigFileStage` hands the root's physical path and origin to
`Loader/ImportChain`, which returns the layers of the configuration-file band
in the C3 order of the import graph, the root last. Each layer's origin is
`importedThrough()` the file that named it. Refusals and `-v`
(`appliedSources()`, one entry per file) therefore name every file with its
importer chain, and JSON publishes it, nested, under `source[].imported_by`.
ADR 0139 records the flat chain and ADR 0140 the three extensions below.

- **Entries.** `Loader/ImportEntries` turns one list into
  `ResolvedImportEntry` values, each a position and the `ImportTarget`s it
  leads to. An entry holding `*`, `?` or `[` is a glob; else one with no
  directory part and no extension is a built-in preset name
  (`PresetResolver::builtInPath()`); else a file path. A preset's origin is
  `Preset(name)` imported through the file, and its applied-source name is the
  bare name, distinct from the `preset:<name>` of `--preset`.
- **Globs.** `Loader/ImportGlob` owns the grammar (`*`, `?`, `[...]` within a
  segment; no `**`, braces or escapes) and walks directories itself, because
  PHP's `glob()` skips an unreadable directory without saying so. A directory
  that must be listed or entered and cannot is refused; no match is accepted
  silently. The matches of one glob form a group the linearization does not
  order internally.
- **Walk and order.** `ImportChain` walks depth-first with a stack keyed by
  target identity (a cycle is refused at the closing entry), reads each file
  once and names it by the first chain reaching it. `Loader/ImportLinearization`
  is pure C3: each list is given reversed as bases, groups as unordered
  members, and an unsolvable merge is refused on the stuck file naming the
  cycle of constraints. A `--preset` file still refuses `imports` (`PresetStage`).
- **Identity.** Files are compared by `Core\FileTarget\FileIdentity`
  (device, inode, type), so two spellings or a symlink of one file are one
  file. An entry naming its own file or a file an earlier entry placed is
  refused at that entry, naming both positions. A target that cannot be
  stat'ed ("not found or not accessible": PHP reports no reason), reports
  inode 0 or is not a regular file is refused before it is read, on the
  imported origin (`ImportTarget::ofFile()`). A built-in preset read from
  inside the phar has no identity and is keyed by name.
- **Path bases.** An entry resolves against the real directory of the file
  that writes it (a symlink resolves to its target's directory). Its display
  name is a path that leads to that file: the writing file's locator directory
  joined with the entry, dropping only `.` segments — `x/..` stays — or, when
  that file is a symlink, the physical path. Two files therefore never share a
  name. Every other path in any file keeps the request's working directory as
  its base.
- **Read per file, never composed.** `ConfigurationRoot::Imports` declares the
  key and `Loader/ImportListSchema` its grammar (non-empty strings, no URL,
  a glob within the grammar above, a preset name, or a local `.yaml`/`.yml`
  path, judged in each layer). The chain reads the entries by composing a
  one-key slice of each file, then hands every layer on without the key.
  `ConfigSchema::PER_FILE_ROOTS` names such roots: they join
  `allowedRootKeys()` but have no `ENTRIES` row and are not `DOCUMENT_ROOTS`;
  `ConfigurationPipeline` refuses to compose a layer that still carries one
  (a programmer error), and the merge-table generator describes them as read
  per file. `ResolvedDocument::get('imports')` is therefore always null.
  Helpers that compose directly, without the pipeline, merge the key as a
  plain list under the same grammar.

## Document engine

`Document/NameBinding` owns authored key/name recognition and pending names.
`DocumentComposer` retains composition and its ordered phases.

`Document/DocumentComposer` composes the layers, lowest precedence first, into
`Contract/Document/ResolvedDocument` in four fixed phases: (1) each layer alone
— every dictionary key recognised, every written value's form judged,
shorthands expanded, `~` under a map key dropped as "not written" (a list item
written `~` is refused, and a named-map
entry keeps its name, see below); (2) the layers
merged by each node's declared `MergePolicy`; (3) names whose vocabulary is
another node (`allow` keyed by the layer names `layers` declares) judged
against that node as merged, in the words of every layer that wrote the name;
(4) every leaf keeps the layer that won it and
every merged node its contributors (`Provenance`: source, the key path as the
author spelled it, the line when the format reports one, and the layer's
precedence index within this composed document).

- An owner declares its root through
  `Contract/Document/Schema/DocumentSectionSchemaInterface` — `declaration()` returns one immutable
  `SectionDeclaration` with readonly `key` and `schema`. The engine reads
  this pair once per provider in its fold. Its `NodeSchema` is built from `scalar`, `map` (with `Shorthand`s), `list`
  (replaced whole), `set` (accumulated), `namedMap` (with a `NameVocabulary`)
  or `opaque` (kept per layer for an owner that still folds it) — and
  `ConfigurationPipeline::addSection()` registers it. What the engine tells an
  author about a node beyond its form — `NodeSchema::withWording()`
  — travels as its `NodeWording`. A node may add `judgedInEachLayer()`: the owner's judgement of what the engine carries
  unread below it, run in phase 1 on each layer's value, so a malformed value a
  higher layer replaces is still refused in the layer that wrote it. An empty collection reads
  by the declaration: a map it changes nothing, a list it replaces, a set it
  adds nothing to.
- Invalidity visible without merged context is judged in every writing layer:
  form, forbidden emptiness and closed-dictionary membership. Reporting declares
  its format dictionary this way; `format: bogus` and `cache.dir: ""` refuse
  even under a valid CLI override. A constraint requiring merged context is
  judged only on the winning value.
- A dictionary key is accepted in its snake_case, camelCase or kebab-case
  spelling (`ConfigKeySpelling::acceptedSpellings()`); the same words in any
  other style are refused with the canonical key offered.
- A `namedMap`'s `NameVocabulary` is `fixed` (a dictionary, spelling rule
  included), `predicate` (an open grammar judged in the writing layer; a
  `RefusedName` says why and, for a few alternatives, which) or `fromSibling`
  (names another node declares, judged after the merge; exact unless the
  vocabulary's own judge says how a written name refers to the declared ones).
  `NameVocabulary::closed()` is a `predicate` whose judge is derived from a
  listed set of names: the owner words only the refusal, and `closedNames()`
  lets a projection enumerate exactly what the engine admits. Finding's
  `rules` uses it; the engine's branches on vocabulary kind see a predicate.
  A name is judged whatever is written under it: an entry whose body is `~`,
  `{}` or nothing but `~` stays in the document as a
  `ResolvedBareNameInterface`, which a body any layer wrote stands over. Its
  owner says what naming it alone means.
- A node may carry advice (`NodeSchema::withWording(hint:, literalHints:)`): the
  engine adds it to every refusal of a value written at that node, of its form
  or of a constraint (minimum, non-blank text, word set) — `paths: [2024]` is
  told to quote the name, a bare string in `exclude` is shown the selector
  kinds. A `LiteralHint` replaces the general hint for exactly one scalar,
  compared strictly; a collection matches no literal. `fail_on` declares one
  for `false` and one for `info` beside a general hint, and `format` one for
  the retired `text-verbose`. Two hints for one literal, or a hint for a word
  the node's set accepts, are refused at declaration.
- A value outside a word set is refused at a closed position: `written` is the
  value and `accepted` the declared words, in declaration order.
- A node may carry a description (`NodeSchema::withWording(description:)`). The engine
  never reads it; it is what an editor shows for the node. Finding describes
  each rule entry with the rule's own description.
- The engine never learns the format: a loader hands it an `AuthoredLayer` —
  the source's `ConfigurationOrigin` and an `AuthoredNode` tree with the keys as
  written. A new format is a loader producing that tree (with lines, if it has
  them); an imported file is a layer whose origin names its importer through
  `ConfigurationOrigin::importedThrough()`, so a refusal names both files.
- A resolved value refuses through `ResolvedValueInterface::refuse()`: it throws
  a `ConfigurationRefusal` from `Provenance::refusalOf()`, naming the winning
  layer of a leaf or every contributor of a merged node. A caller that must
  carry the exception object creates it from the same provenance factory. The
  text presenter names those authors and the JSON refusal envelope publishes
  those sources as `source`.
  Joint refusals order writers by their document precedence, including
  several presets of the same source kind. Their default position belongs to
  the last writer; an explicit null preserves a positionless refusal.
  Provenance from different composed documents is not combined.
  Both configuration and environment refusals implement the shared
  `RefusalInterface`; only configuration refusals carry authored sources.
  Console owns environment wording and terminal stream selection.
- Diagnostics — warnings about accepted configuration — travel with the
  resolved document (`ConfigurationDocument::diagnostics()`). Every command
  that resolves the document prints them on stderr, and `check`'s JSON report
  publishes them under `configurationDiagnostics`; both are written by
  `Infrastructure\Console\ConfigurationInputAdapter`. Source-resolution
  warnings, including a near configuration filename, are retained by
  `ConfigurationLayer`, collected by `ConfigurationPipeline`, and joined to
  the engine's diagnostics by `ConfigurationDocument::diagnostics()`; authored
  merge warnings are preserved.

The author-facing table of every node's policy, `~` and empty value is
generated from these declarations — the sections the container registers,
the same complete registered section set the pipeline consumes — into `website/docs/getting-started/configuration.md` and its Russian
twin by `scripts/generate-configuration-merge-table.php`
(`composer configuration:merge-table`). `configuration:merge-table:check`, in
`check:artifacts`, fails when a declaration changed and the page did not. The
decision and what it leaves unexpressible are recorded in
[ADR 0086](../../../docs/adr/0086-one-configuration-document-merged-by-declared-policy.md).

The engine runs in every resolution. Cache, Coupling, Console, Parallel, Run,
Reporting, FindingProjection and Finding read their declared resolved values.
Finding registers a `RulesSection` for each of `rules`, `only_rules` and
`disabled_rules`; option keys, level slots, shorthands and values are judged
in every authored layer before winners are chosen.
Composer discovery uses the invocation facts supplied by
`Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface`.
Infrastructure's Composer adapter alone reads, decodes and caches the analysed
`composer.json`; Configuration, Run, Measurement, Design's install adapters and
HTML metadata share that root snapshot. Configuration owns neither the reader
nor a second decoder. Invalid records retain typed issues beside accepted
fragments; Run owns whether those fragments establish a project universe.

Composer discovery is not an authored layer: its production and development
targets are available only through `discoveredProductionAutoloadTargets()` and
`discoveredDevelopmentAutoloadTargets()`, while Run owns the decision whether
to use the development targets. Every authored stage hands its sources over as
written through
`ConfigurationLayer::$authored`, read before any key is folded or any `~`
erased: the defaults (empty), each preset as a layer of its own, the file, and
the command line — a layer without positions whose every value carries the
option that wrote it (`ConfigurationResolutionRequest::$cliOptionNames`), so a
refusal names `option --format`.

The root dictionary is closed: `ConfigSchema` enumerates the accepted root
keys. `ConfigurationRoot` declares the roots with Configuration-owned value
forms. Run declares `paths`, Console declares `fail_on` and `memory_limit`,
Parallel declares `parallel`, Infrastructure Cache declares `cache`, and Reporting declares `format`; the evidence
and policy owners declare their own sections. Each owner registers its section
autoconfigured, and the container hands those instances to
`ConfigurationPipeline`. Every accepted root has an actual registered declaration. A known but
undeclared root is a configuration error, not an unread transport escape;
an unknown root is refused, `~` or not. A suggestion offers the canonical key, whatever the style of the
key it answers.

`ConfigSchema::EXCLUDE` names both the authored `exclude` root and its resolved
result key. The former `EXCLUDES` constant and `excludes` result key are removed.
`Infrastructure\\Cache\\CacheSection` owns cache forms and the default directory;
`ParserConfigurator` registers that section explicitly with the document engine.

`YamlConfigLoader` returns the positioned authored document. The stage supplies
its real ConfigFile or Preset origin; the document engine reads it against all
registered declarations. There is no deferred raw-rule refusal or second
normalization pass after composition. Command-line values arrive as one authored
layer through the same schema, with their option locator and no file position.
`CommandLinePathWrite` requires the complete authored expression as well as the
option name. `ConfigurationOrigin` keeps that expression for statement/refusal
wording while the machine source locator remains the option name.

Symfony YAML owns value parsing. It reads unquoted `+2` and `2.0` as floats;
integer options refuse both with the parsed value and a hint to write `2`.
The file, rule-option and alias doors share `WrittenForm` admission. File
refusals retain their path and parsed value; CLI refusals also retain the authored
expression. No source rewriting or float-to-integer conversion occurs.

`SelectorYamlDecoder` is the configuration ingress for the shared selector
language. The authored schema refuses a bare string before decoder translation;
its diagnostic describes the selector mapping grammar. A selector list entry is
exactly one mapping — `{exact: value}`,
`{subtree: value}`, or `{regex: value}` — never a bare string. It retains the
document origin and list position when translating mapping or PCRE validation
failures to `ConfigurationRefusal`, then builds the separator-bound Core value.
The declared entry form preserves malformed selector mappings for an authored
refusal rather than silently converting them into unwritten configuration.

## Editor schema

`EditorSchema/` projects the same declarations into `qmx.schema.json`, a
draft-07 JSON Schema that gives editors completion and form validation for
one authored file. `EditorSchemaProjection::project()` takes the document
root (`DocumentSchema::root()`) and is the only entry;
`EditorSchemaJson::encode()` serializes it deterministically. It reads only
`NodeSchema` facts, so it knows nothing about Finding or the container:
`scripts/generate-configuration-schema.php` collects the registered sections
through `scripts/configuration-declarations.php`, the collection the merge
table generator shares.

Its promise is one-sided: on the same scalar typing, the schema never refuses
what the engine accepts in one layer. It expresses form — keys and their
three spellings, scalar forms, word sets, minimums, shorthand conflicts,
`~` and empty collections, and the collection shapes the YAML loader accepts.
Owner judges, merged-context constraints and opaque nodes stay outside it.
Where a form is expressible only approximately, the schema is wider, never
stricter. The decisions are recorded in
[ADR 0132](../../../docs/adr/0132-editor-schema-of-the-configuration-document.md).

- `tests/Analysis/Configuration/Integration/EditorSchema/` holds the promise
  case by case: each case is read by the product's YAML loader and judged by
  `DocumentComposer`, and declares both verdicts; a case may not declare a
  refusal the engine does not make.
- `governance/ConfigurationEditorSchema/` holds it on real texts: tracked
  configurations and the documentation's YAML examples the engine accepts.
- `configuration:schema:check`, in `check:artifacts`, fails when a declaration
  changed and `qmx.schema.json` did not.

## Public contracts and adapters

External consumers use only declared `Contract/` promises. Loader types,
including `Loader/ConfigLoaderInterface`, are internal and are composed behind
the Configuration boundary. Infrastructure composition registers the pipeline
and its stages; Console adapts Symfony input into the resolution request.
The resolved-document read surface consists of `ResolvedValueInterface` and the
four shape contracts `ResolvedMapInterface`, `ResolvedListInterface`,
`ResolvedOpaqueInterface`, and `ResolvedBareNameInterface`. Consumers read
through those contracts; they do not construct, mutate, or import the concrete
forms under `Document/Resolved/`.
Consumers resolve only their named value: Run produces `RunConfiguration`,
Finding produces `FindingConfiguration`, Cache and Parallel produce their local
configurations, and Reporting resolves output and finding-projection values.
No consumer may construct a feature configuration factory through Configuration
or add a feature field to a shared carrier.

`IntegerJudgement` lets an owner supply a pure integer grammar returning a
refusal message or `null` to `NodeSchema::judgedInEachLayer()`. The declaration
accepts it only on an integer scalar. Layer reading establishes that form and
retains authored provenance when it raises the message; the grammar does not
need a resolved-node parameter to judge a numeric range.

`ResolvedDocument::get()` takes canonical schema paths. An undeclared path is
a programmer error (`LogicException`), including a misspelt child beneath an
unwritten parent. A declared but unwritten value remains `null`. The canonical
`ConfigSchema` roots are `computed_metrics` and `exclude_health`, matching the
document's section keys.
An open named map declares a name slot structurally: a read of an unwritten
name returns `null`. Lookup does not repeat the owner's authored-name judgement;
fixed dictionaries still reject an undeclared name.

`ConfigurationDocument` has no generic raw-value operation or temporary rule
contribution getters. Its two Composer discovery facts are source-specific;
all authored feature values are consumed through their declared resolved section.

## CLI option aliases

This is the canonical internal reference for aliases discovered from current
rule classes. `DocumentationRuleSurfaceTest` requires every alias to remain in
this moved README; the user-facing CLI reference remains under `website/docs/`.

| Option                                  | Rule                                 | Field                 |
| --------------------------------------- | ------------------------------------ | --------------------- |
| `--circular-deps`                       | architecture.circular-dependency     | enabled               |
| `--max-cycle-size=N`                    | architecture.circular-dependency     | maxCycleSize          |
| `--layer-violation`                     | architecture.layer-violation         | enabled               |
| `--layer-violation-severity=SEVERITY`   | architecture.layer-violation         | severity              |
| `--unassigned-class-mode=MODE`          | architecture.unassigned-class        | mode                  |
| `--constructor-overinjection-warning=N` | code-smell.constructor-overinjection | warning               |
| `--constructor-overinjection-error=N`   | code-smell.constructor-overinjection | error                 |
| `--long-parameter-list-warning=N`       | code-smell.long-parameter-list       | warning               |
| `--long-parameter-list-error=N`         | code-smell.long-parameter-list       | error                 |
| `--long-parameter-list-vo-warning=N`    | code-smell.long-parameter-list       | vo-warning            |
| `--long-parameter-list-vo-error=N`      | code-smell.long-parameter-list       | vo-error              |
| `--unreachable-code-warning=N`          | code-smell.unreachable-code          | warning               |
| `--unreachable-code-error=N`            | code-smell.unreachable-code          | error                 |
| `--cognitive-warning=N`                 | complexity.cognitive                 | callable.warning      |
| `--cognitive-error=N`                   | complexity.cognitive                 | callable.error        |
| `--cognitive-class-warning=N`           | complexity.cognitive                 | class.max_warning     |
| `--cognitive-class-error=N`             | complexity.cognitive                 | class.max_error       |
| `--cyclomatic-warning=N`                | complexity.ccn                       | callable.warning      |
| `--cyclomatic-error=N`                  | complexity.ccn                       | callable.error        |
| `--cyclomatic-class-warning=N`          | complexity.ccn                       | class.max_warning     |
| `--cyclomatic-class-error=N`            | complexity.ccn                       | class.max_error       |
| `--npath-warning=N`                     | complexity.npath                     | callable.warning      |
| `--npath-error=N`                       | complexity.npath                     | callable.error        |
| `--npath-class-warning=N`               | complexity.npath                     | class.max_warning     |
| `--npath-class-error=N`                 | complexity.npath                     | class.max_error       |
| `--wmc-warning=N`                       | complexity.wmc                       | warning               |
| `--wmc-error=N`                         | complexity.wmc                       | error                 |
| `--wmc-exclude-data-classes=N`          | complexity.wmc                       | excludeDataClasses    |
| `--cbo-warning=N`                       | coupling.cbo                         | class.warning         |
| `--cbo-error=N`                         | coupling.cbo                         | class.error           |
| `--cbo-ns-warning=N`                    | coupling.cbo                         | namespace.warning     |
| `--cbo-ns-error=N`                      | coupling.cbo                         | namespace.error       |
| `--class-rank-warning=N`                | coupling.class-rank                  | warning               |
| `--class-rank-error=N`                  | coupling.class-rank                  | error                 |
| `--distance-warning=N`                  | coupling.distance                    | max_distance_warning  |
| `--distance-error=N`                    | coupling.distance                    | max_distance_error    |
| `--instability-class-warning=N`         | coupling.instability                 | class.max_warning     |
| `--instability-class-error=N`           | coupling.instability                 | class.max_error       |
| `--instability-ns-warning=N`            | coupling.instability                 | namespace.max_warning |
| `--instability-ns-error=N`              | coupling.instability                 | namespace.max_error   |
| `--data-class-woc-threshold=N`          | design.data-class                    | wocThreshold          |
| `--data-class-wmc-threshold=N`          | design.data-class                    | wmcThreshold          |
| `--data-class-min-members=N`            | design.data-class                    | minMembers            |
| `--data-class-exclude-readonly=N`       | design.data-class                    | excludeReadonly       |
| `--data-class-exclude-promoted-only=N`  | design.data-class                    | excludePromotedOnly   |
| `--data-class-exclude-exceptions=N`     | design.data-class                    | excludeExceptions     |
| `--god-class-wmc-threshold=N`           | design.god-class                     | wmcThreshold          |
| `--god-class-lcom-threshold=N`          | design.god-class                     | lcomThreshold         |
| `--god-class-tcc-threshold=N`           | design.god-class                     | tccThreshold          |
| `--god-class-class-loc-threshold=N`     | design.god-class                     | classLocThreshold     |
| `--god-class-min-criteria=N`            | design.god-class                     | minCriteria           |
| `--god-class-min-methods=N`             | design.god-class                     | minMethods            |
| `--god-class-exclude-readonly=N`        | design.god-class                     | excludeReadonly       |
| `--dit-warning=N`                       | design.dit                           | warning               |
| `--dit-error=N`                         | design.dit                           | error                 |
| `--lcom-warning=N`                      | cohesion.lcom                        | warning               |
| `--lcom-error=N`                        | cohesion.lcom                        | error                 |
| `--lcom-exclude-readonly=N`             | cohesion.lcom                        | excludeReadonly       |
| `--lcom-min-methods=N`                  | cohesion.lcom                        | minMethods            |
| `--lcom-exclude-methods=V`              | cohesion.lcom                        | excludeMethods        |
| `--noc-warning=N`                       | design.noc                           | warning               |
| `--noc-error=N`                         | design.noc                           | error                 |
| `--param-type-coverage-warning=N`       | design.type-coverage.param           | warning               |
| `--param-type-coverage-error=N`         | design.type-coverage.param           | error                 |
| `--return-type-coverage-warning=N`      | design.type-coverage.return          | warning               |
| `--return-type-coverage-error=N`        | design.type-coverage.return          | error                 |
| `--property-type-coverage-warning=N`    | design.type-coverage.property        | warning               |
| `--property-type-coverage-error=N`      | design.type-coverage.property        | error                 |
| `--mi-warning=N`                        | maintainability.mi                   | warning               |
| `--mi-error=N`                          | maintainability.mi                   | error                 |
| `--mi-exclude-tests=N`                  | maintainability.mi                   | excludeTests          |
| `--mi-min-statements=N`                 | maintainability.mi                   | minStatements         |
| `--class-count-warning=N`               | size.class-count                     | warning               |
| `--class-count-error=N`                 | size.class-count                     | error                 |
| `--method-count-warning=N`              | size.method-count                    | warning               |
| `--method-count-error=N`                | size.method-count                    | error                 |
| `--property-count-warning=N`            | size.property-count                  | warning               |
| `--property-count-error=N`              | size.property-count                  | error                 |
| `--property-exclude-readonly=N`         | size.property-count                  | excludeReadonly       |
| `--property-exclude-promoted-only=N`    | size.property-count                  | excludePromotedOnly   |

Use `--rule-opt=RULE:OPTION=VALUE` for every option without a short alias.

## Definition of Done

- The same input layers resolve deterministically and invalid document data
  fails with `ConfigurationRefusal` (`Contract/Refusal/`).
- Two analysis invocations in one process do not leak owner-local runtime or
  rule-option state.
- Every new YAML key has a canonical ingress mapping where needed and is
  declared by its natural owner (`ConfigurationRoot` or an owner section),
  rather than extending a generic configuration carrier.


## Document and transport contracts

`ConfigLoaderInterface::read(physicalPath, origin)` returns `LoadedDocument`
containing the positioned `AuthoredNode`, not a normalized rule contribution.
Every whole-document refusal it raises carries that origin, importer chain
included, and words its subject through `origin->describe()`.
The source stage carries its real origin in `ConfigurationLayer::authored` and
passes source diagnostics independently. `ConfigurationPipeline` composes those
layers against Configuration-owned roots and the registered owner sections.
`ConfigurationDocument::resolved()` is the authored feature read port; its
working directory, applied sources, diagnostics and two Composer discovery target
facts remain available. There are no raw rules/only/disabled contribution getters.

`ConfigSchema::DOCUMENT_ROOTS` includes `RULES`: this is one canonical dictionary
entry for the Finding-owned document root, not a legacy alias, another owner or
a second rule validator. `DocumentRoots` describes canonical keys/CLI paths;
actual `DocumentSectionSchemaInterface` declarations provide their forms and
merge semantics. The known dictionary alone cannot enroll an undeclared section.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.

The atomic declaration and shared Reporting format vocabulary are described in
[ADR 0088](../../../docs/adr/0088-atomic-section-declarations-and-format-vocabulary.md).

Authored tree values, name binding and reading context share the Document
operation: binding reads the same authored nodes and provenance as layer
composition. They do not declare separate module lifecycles.
