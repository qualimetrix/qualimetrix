# Configuration

## Subject and current boundary

`Analysis\\Configuration` owns loading, normalizing, ordering, validating, and
resolving the ordered `ConfigurationDocument` used for one analysis invocation.
It owns source resolution and schema semantics, not a cross-owner runtime DTO.
Each feature resolves its own immutable projection from that concrete document;
owner-local runtime state exists only where a long-lived service needs it.

The document is a narrow public source seam: its contributions and working
directory are consumed by named owners, while Symfony input remains inside the
Console adapter. It does not expose a generic configuration interface, a
universal invocation context, or a carrier for feature fields.

## Structure

```text
Configuration/
├── Contract/
│   ├── ConfigurationDocument.php # ordered source contributions + the resolved document
│   ├── Discovery/                # Composer autoload-path reader
│   ├── Document/                 # the resolved document: values with provenance, diagnostics
│   │   └── Schema/               # the port an owner declares its section through
│   ├── Pipeline/                 # resolution request and pipeline contracts
│   └── Refusal/                  # ConfigurationRefusal — the one carrier for a configuration
│                                  # refusal by user input, and its origin/position vocabulary;
│                                  # per-source shorthands (atResolvedKey, aboutCommandLineInput, …)
│                                  # let a throw site name its source without importing the vocabulary
├── Discovery/          # Composer metadata reader
├── Document/           # the engine composing written layers into the resolved document
├── Loader/             # each source as a written layer for the engine (YAML file or preset →
│                       # LoadedDocument; command line → CommandLineLayer), and the legacy
│                       # folded values: key normalization and the checks of roots the engine
│                       # does not judge yet
├── Pipeline/           # ordered stage runner, source-layer value, rule-name validator, the
│   │                   # `~`-as-unwritten normalizer (ConfigDataNormalizer)
│   └── Stage/          # defaults, Composer, preset, file, CLI stages
├── Preset/             # built-in and custom preset resolution
├── ConfigKeySpelling.php   # the snake/kebab/camel fold of a key, and its inverse
├── ConfigSchema.php        # every YAML key, its result key, type and normalization policy
├── ConfigurationRoot.php   # the roots Configuration declares: key and schema of each
├── DocumentRoots.php       # every root of the document and who declares it
├── UndeclaredRoot.php      # the stand-in for a known root no owner has declared yet
├── SelectorYamlDecoder.php  # explicit selector mapping → Core path/namespace pattern
└── RetiredSuppressionOptions.php # the retired `exclude*` spellings and the one refusal
```

## Resolution model

`ConfigurationPipelineInterface` runs ordered stages over a
`ConfigurationResolutionRequest`, then produces `ConfigurationDocument`.
`ConfigSchema` remains the single source of YAML key names and types. The
precedence order, lowest first, is defaults, Composer discovery, presets, the
configuration file, and CLI options — the stage priorities 0, 10, 15, 20 and 30.
Stages do not merge: the document keeps every contribution in that order, and
each owner folds its own key — a scalar is usually taken from the last layer
that wrote it, while each collection states its own semantics (`disabled_rules`
accumulates, `only_rules` is replaced). Composer discovery contributes the
production and `autoload-dev` targets — every autoload form, `psr-4`, `psr-0`,
`classmap` and `files`, the list the scope denominator also reads — under two
internal keys rather than `paths`: `include_autoload_dev`, which a later
source may write, decides which of them Run takes as the default paths. The
lists are the manifest as written: Run, not the reader, drops a target that
lies inside `vendor`, `node_modules` or `.git`, because that is Run's
discovery rule.

A key is written once per document. `suppress_paths`, `suppress-paths` and
`suppressPaths` fold into one key, so writing two of them in one mapping is
refused rather than resolved by whichever comes last. Any other spelling of
the same words (`Fail_On`, `FAIL_ON`) is refused with the canonical key
offered, at every depth the engine reads. A refusal about a key answers in the
spelling its author used.
`RetiredSuppressionOptions` holds the retired `exclude*` suppression spellings
and the one sentence refusing them, for all four doors: the YAML root, a
`rules:` block, `--rule-opt`, and the rule-option factory behind it. Each door
used to carry its own copy of the family and of the sentence, and the copies had
already drifted apart in wording. A refusal answers in the spelling its author
wrote, so the doors that still hold it — the loader, handed the
*pre-normalization* `rules:` section by `YamlConfigLoader`, and the `--rule-opt`
parser — are the ones that raise it: below them `exclude_paths`,
`exclude-paths` and `excludePaths` are one key. Configuration owns the subject
because the rule layer already imports Configuration and the reverse edge would
be a cycle. `ConfigKeySpelling` is that fold and its inverse, shared by every
door rather than spelled out again in each.

`ConfigurationDocument` preserves ordered source contributions. Feature leaves
consume their own contribution key: for example,
Architecture policy parses and merges only `architecture` after the Console
logger exists, then returns typed warnings through its own contract. The
central pipeline neither contains an Architecture object nor transports a
feature-specific deferred warning. ComputedMetrics folds `computed_metrics` and
`exclude_health` directly from the same ordered document and publishes an
instance-owned catalog only after full validation. Coupling likewise folds the
canonical `coupling.framework_namespaces` contribution into its own run-scoped
state. The document root remains normalized and schema-governed even though the
mixed carrier copies that value.

## Document engine

`Document/DocumentComposer` composes the layers, lowest precedence first, into
`Contract/Document/ResolvedDocument` in four fixed phases: (1) each layer alone
— every dictionary key recognised, every written value's form judged,
shorthands expanded, `~` dropped as "not written" at any depth (a named-map
entry keeps its name, see below); (2) the layers
merged by each node's declared `MergePolicy`; (3) names whose vocabulary is
another node (`allow` keyed by the layer names `layers` declares) judged
against that node as merged, in the words of every layer that wrote the name;
(4) every leaf keeps the layer that won it and
every merged node its contributors (`Provenance`: source, the key path as the
author spelled it, the line when the format reports one).

- An owner declares its root through
  `Contract/Document/Schema/DocumentSectionSchemaInterface` — a key and a
  `NodeSchema` built from `scalar`, `map` (with `Shorthand`s), `list`
  (replaced whole), `set` (accumulated), `namedMap` (with a `NameVocabulary`)
  or `opaque` (kept per layer for an owner that still folds it) — and
  `ConfigurationPipeline::addSection()` registers it. What the engine tells an
  author about a node beyond its form — `withHint()`, `announcingEmptyOverride()`
  — travels as its `NodeWording`. A node may add `judgedInEachLayer()`: the owner's judgement of what the engine carries
  unread below it, run in phase 1 on each layer's value, so a malformed value a
  higher layer replaces is still refused in the layer that wrote it. An empty collection reads
  by the declaration: a map it changes nothing, a list it replaces, a set it
  adds nothing to.
- A dictionary key is accepted in its snake_case, camelCase or kebab-case
  spelling (`ConfigKeySpelling::acceptedSpellings()`); the same words in any
  other style are refused with the canonical key offered.
- A `namedMap`'s `NameVocabulary` is `fixed` (a dictionary, spelling rule
  included), `predicate` (an open grammar judged in the writing layer; a
  `RefusedName` says why and, for a few alternatives, which) or `fromSibling`
  (names another node declares, judged after the merge; exact unless the
  vocabulary's own judge says how a written name refers to the declared ones).
  A name is judged whatever is written under it: an entry whose body is `~`,
  `{}` or nothing but `~` stays in the document as `ResolvedBareName`, which a
  body any layer wrote stands over, and its owner says what naming it alone
  means.
- A node may carry a hint (`NodeSchema::withHint()`): the engine adds it to its
  refusal of the form written at that node — `paths: [2024]` is told to quote
  the name, a bare string in `exclude` is shown the selector kinds.
- The engine never learns the format: a loader hands it an `AuthoredLayer` —
  the source's `ConfigurationOrigin` and an `AuthoredNode` tree with the keys as
  written. A new format is a loader producing that tree (with lines, if it has
  them); an imported file is a layer whose origin names its importer through
  `ConfigurationOrigin::importedThrough()`, so a refusal names both files.
- A refusal of a resolved value comes from the value itself —
  `ResolvedValueInterface::refusal()` names the winning layer of a leaf, or every
  contributor of a merged node through `ConfigurationRefusal::acrossLayers()`.
  The JSON refusal envelope publishes those sources as `source`.
- Diagnostics — warnings about accepted configuration — travel with the
  resolved document (`ConfigurationDocument::diagnostics()`). Every command
  that resolves the document prints them on stderr, and `check`'s JSON report
  publishes them under `configurationDiagnostics`; both are written by
  `Infrastructure\Console\ConfigurationInputAdapter`.

The author-facing table of every node's policy, `~` and empty value is
generated from these declarations — the sections the container registers,
completed by `DocumentRoots::completing()` exactly as the pipeline completes
them — into `website/docs/getting-started/configuration.md` and its Russian
twin by `scripts/generate-configuration-merge-table.php`
(`composer configuration:merge-table`). `configuration:merge-table:check`, in
`check:artifacts`, fails when a declaration changed and the page did not. The
decision and what it leaves unexpressible are recorded in
[ADR 0086](../../../docs/adr/0086-one-configuration-document-merged-by-declared-policy.md).

The engine runs in every resolution, beside `contributions()` while owners
move to it. Every stage hands its sources over as written through
`ConfigurationLayer::$authored`, read before any key is folded or any `~`
erased: the defaults (empty), each preset as a layer of its own, the file, and
the command line — a layer without positions whose every value carries the
option that wrote it (`ConfigurationResolutionRequest::$cliOptionNames`), so a
refusal names `option --format`. Composer discovery is not a written layer: its
two target lists are internal keys no author may write, and Run reads them from
`contributions()` until that goes.

The root dictionary is closed: `ConfigurationRoot` declares every root outside
the capability-owned `DOCUMENT_ROOTS` and `rules`; an owner declares its own
root by registering its section autoconfigured (the container hands every such
section to `ConfigurationPipeline`); a known root nobody declared yet is
carried unread (`UndeclaredRoot`), and any other root is refused as unknown,
`~` or not. A suggestion offers the canonical key, whatever the style of the
key it answers.

The loader still folds each file into the values `contributions()` returns,
and still judges what the engine does not yet: the `rules` block, and the
container and sub-keys of an undeclared root. A refusal from that fold is
held in the layer (`LoadedDocument::$deferredRefusal`) and raised only after
the engine accepted every layer, so a root the engine declares is refused in
the engine's words, naming the layer that wrote it.

`SelectorYamlDecoder` is the configuration ingress for the shared selector
language. A selector list entry is exactly one mapping — `{exact: value}`,
`{subtree: value}`, or `{regex: value}` — never a bare string. It retains the
document origin and list position when translating mapping or PCRE validation
failures to `ConfigurationRefusal`, then builds the separator-bound Core value.
`ConfigDataNormalizer` preserves those mappings, including malformed null
values, until this decoder can reject them instead of silently treating them as
unwritten configuration.

## Public contracts and adapters

External consumers use only declared `Contract/` promises. Loader types,
including `Loader/ConfigLoaderInterface`, are internal and are composed behind
the Configuration boundary. Infrastructure composition registers the pipeline
and its stages; Console adapts Symfony input into the resolution request.
Consumers resolve only their named value: Run produces `RunConfiguration`,
Finding produces `FindingConfiguration`, Cache and Parallel produce their local
configurations, and Reporting resolves output and finding-projection values.
No consumer may construct a feature configuration factory through Configuration
or add a feature field to a shared carrier.

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
- Every new YAML key is added to `ConfigSchema` and consumed by its natural
  owner, rather than extending a generic configuration carrier.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
