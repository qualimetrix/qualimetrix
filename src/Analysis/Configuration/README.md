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
│   ├── ConfigurationDocument.php # resolved document, diagnostics and Composer target facts
│   ├── Document/                 # the resolved document: provenance, diagnostics and read-only
│   │                             # Resolved{Map,List,Opaque,BareName}Interface forms
│   │   └── Schema/               # the port an owner declares its section through
│   │       ├── NodeSchema.php        # merge policy and typed scalar/map/collection facts
│   │       ├── NodeScalarFacts.php   # scalar forms, floor, vocabulary and text requirement
│   │       ├── NodeMapFacts.php      # fixed keys, bare fields and named-entry schemas
│   │       ├── NodeCollectionFacts.php # list element and bare-element policy
│   │       ├── SchemaWordSet.php     # sensitive/folding words, with WordComparison
│   │       ├── TextRequirement.php  # unconstrained or non-blank text
│   │       ├── BareElementPolicy.php # list-only or one bare element
│   │       ├── IntegerJudgement.php # pure integer grammar
│   │       └── SectionDeclaration.php # atomic canonical key and node schema
│   ├── Pipeline/                 # resolution request and pipeline contracts
│   └── Refusal/                  # RefusalInterface — shared summary, position and source metadata;
│                                  # ConfigurationRefusal carries a configuration
│                                  # refusal by user input, and its origin/position vocabulary;
│                                  # per-source shorthands (atResolvedKey, aboutCommandLineInput, …)
│                                  # let a throw site name its source without importing the vocabulary
├── Document/           # the engine composing written layers into the resolved document
│   ├── ShorthandExpansion.php # canonical leaf expansion and overlap refusals
│   ├── ShorthandTarget.php    # one typed expansion destination
│   ├── NamedEntryReading.php  # named bodies and authored bare names
│   ├── ScalarConstraints.php  # value judgement after scalar-form admission
│   ├── EmptyListOverrides.php # live diagnostic for a standing empty replacement
│   └── Resolved/       # internal concrete resolved forms, including ResolvedScalar
├── Loader/             # each source as a written layer for the engine (YAML file or preset →
│                       # LoadedDocument; command line → CommandLineLayer), plus the narrow
│                       # source-origin and positioned-node preservation
│   └── CommandLineSyntax.php # scalar/flow-list grammar before declared judgement
├── Pipeline/           # ordered source stages and authored layer assembly
│   └── Stage/          # defaults, Composer, preset, file, CLI stages
├── Preset/             # built-in and custom preset resolution
├── ConfigKeySpelling.php   # the snake/kebab/camel fold of a key, and its inverse
├── ConfigSchema.php        # canonical ingress keys and legacy flat mappings
├── ConfigurationRoot.php   # the roots Configuration declares: one atomic declaration each
├── DocumentRoots.php       # canonical root dictionary and CLI document paths
├── SelectorYamlDecoder.php  # explicit selector mapping → Core path/namespace pattern
└── RetiredSuppressionOptions.php # the retired `exclude*` spellings and the one refusal
```

## Resolution model

`ConfigurationPipelineInterface` runs ordered stages over a
`ConfigurationResolutionRequest`, then produces `ConfigurationDocument`.
`ConfigSchema` names canonical ingress keys and keeps the legacy flat mappings;
it is not the authority for value form or merge semantics. `ConfigurationRoot`
declares Configuration-owned roots, and a capability declares its own root
through `DocumentSectionSchemaInterface`. The precedence order, lowest first,
is defaults, Composer discovery, presets, the configuration file, and CLI
options — the stage priorities 0, 10, 15, 20 and 30. Stages preserve each
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
working directory to named owners. Architecture reads its registered
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
presets keep their resolved file path. Directory discovery refusals name `.`
and near-file diagnostics name the observed directory entry.
Every document-reading command uses this same stage and judges the complete
context-free document before consuming its own values. Console's closed
preflight profile controls CLI ingress and actual consumers, not the schema.

## Document engine

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
  author about a node beyond its form — `withHint()`, `announcingEmptyOverride()`
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
  A name is judged whatever is written under it: an entry whose body is `~`,
  `{}` or nothing but `~` stays in the document as a
  `ResolvedBareNameInterface`, which a body any layer wrote stands over. Its
  owner says what naming it alone means.
- A node may carry a hint (`NodeSchema::withHint()`): the engine adds it to its
  refusal of the form written at that node — `paths: [2024]` is told to quote
  the name, a bare string in `exclude` is shown the selector kinds.
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

`SelectorYamlDecoder` is the configuration ingress for the shared selector
language. A selector list entry is exactly one mapping — `{exact: value}`,
`{subtree: value}`, or `{regex: value}` — never a bare string. It retains the
document origin and list position when translating mapping or PCRE validation
failures to `ConfigurationRefusal`, then builds the separator-bound Core value.
The declared entry form preserves malformed selector mappings for an authored
refusal rather than silently converting them into unwritten configuration.

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
rule classes. `DocumentationConsistencyTest` requires every alias to remain in
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

`ConfigLoaderInterface::read(physicalPath, sourceName)` returns `LoadedDocument`
containing the positioned `AuthoredNode`, not a normalized rule contribution.
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
