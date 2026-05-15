# Architecture (vertical slice)

Layer-policy and circular-dependency detection for static analysis. This is the
project's **pilot vertical slice** — the first feature organized as a
self-contained domain tree rather than scattered across the horizontal layers.
See [ADR 0010](../../docs/adr/0010-architecture-vertical-slice.md) for the
pilot rationale and [ADR 0012](../../docs/adr/0012-hybrid-architectural-direction.md)
for the project-wide hybrid direction.

The slice covers two user-facing rules:

- `architecture.layer-violation` — reports dependency edges that cross layer
  boundaries and are not in the policy allow-list (multi-criterion membership,
  template layers, captured allow selectors, relation filters, exclude blocks).
- `architecture.circular-dependency` — reports class-level dependency cycles
  (with `maxCycleSize` and reporting controls).

Plus the analysis-time machinery that prepares layer membership for those rules
(layer registry, template expansion, allow-list evaluation, capture bindings,
relation filters, exclude blocks).

## Layout

```
src/Architecture/
├── Domain/             # Pure VOs / enums / exceptions — no DI
│   ├── ArchitectureConfiguration.php        # Top-level VO: registry + policy + coverage
│   ├── ArchitectureConfigurationHolder.php  # Mutable runtime holder (DI-injected)
│   ├── CoverageMode.php                     # ignore / warn / error enum
│   ├── Allow/                               # Allow-list selectors (see below)
│   └── Layer/                               # Layer primitives (see below)
├── Configuration/      # YAML → typed configuration; validators
│   ├── ArchitectureConfigurationFactory.php # YAML map → ArchitectureConfiguration
│   ├── ArchitectureFactoryResult.php        # Configuration + DeferredWarnings
│   ├── Allow/AllowAliasExpander.php         # Relation aliases (inheritance → extends/implements/trait_use, etc.)
│   └── Validation/                          # Pre-construction validators / normalizers
├── Processing/         # Analysis-time pipeline helpers
│   ├── LayerExpansionStage.php              # Template-layer expansion (runs after collection)
│   ├── LayerExpansionResult.php             # Result VO (concrete LayerDefinitions + diagnostics)
│   └── LayerExpansionException.php
├── Rules/              # User-facing rules — slice's only outward consumers
│   ├── LayerViolationRule.php
│   ├── LayerViolationOptions.php
│   ├── CircularDependencyRule.php
│   └── CircularDependencyOptions.php
└── README.md           # This file
```

> **Note (Phase 2 of remediation).** `ArchitectureConfigurationHolder` is the
> last cross-layer-bridge concession remaining from the layered design.
> Phase 4 (ADR 0008) replaces it with `ArchitectureProcessor` and removes
> the temporary `AnalysisContext::$architecture` field. Until then the
> holder is registered in
> [`AnalysisConfigurator`](../Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php),
> not in `ArchitectureConfigurator`.

## External boundary

Per ADR 0010 the slice has a single explicit boundary:

- **Depends on:** `Core` (cross-cutting primitives — `SymbolPath`, `Violation`,
  `Severity`, `Dependency*`, `NamespaceMatcher`), `Rules` (only `RuleInterface`
  registration contract), `Configuration` (`YamlConfigLoader`, `ConfigSchema`),
  Symfony DI.
- **Depended on by:** `Analysis.Pipeline` (will call
  `ArchitectureProcessor::prepare()` post-Phase 4),
  `Configuration` (consumes `ArchitectureConfigurationFactory` in
  `ConfigurationPipeline`),
  `Infrastructure.Console` (`LayerAssignmentCommand`, `RuntimeConfigurator`),
  `Infrastructure.DI` (`ArchitectureConfigurator`).

Deptrac enforces this surface. **Internal sub-namespaces (`Domain`,
`Configuration`, `Processing`, `Rules`) may depend on each other freely** —
internal organization is a refactoring concern, not an architectural constraint
(ADR 0010 Part 5).

Adapters live in Infrastructure:

- `LayerAssignmentCommand` at
  `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php` —
  injects the slice's public service contracts. Keeping the command in
  Infrastructure honours the adapter-exclusion principle (ADR 0010 Part 2 /
  ADR 0012 rule 4): symfony/console is an infrastructure concern, not a
  domain one.

## Sub-namespaces

### Domain/

Pure value-objects, enums, exceptions. No DI registration; no framework
dependencies. Constructed by factories (`ArchitectureConfigurationFactory`,
`LayerSelectorParser`) and the `ClassContextFactory`.

Highlights:

- `ArchitectureConfiguration` — the typed top-level VO carrying the
  `LayerRegistry`, `LayerPolicy`, `CoverageMode`, optional template entries,
  and `maxExpandedLayers` bound.
- `Layer/LayerDefinition` + `Layer/TemplateLayerDefinition` — concrete layers
  and parameterised templates. Membership criteria live on
  `MembershipSpec` (five criterion kinds: patterns / suffix / attributes /
  implements / extends, with optional `ExcludeSpec` hard-filter) and the
  match walk delegates to `LayerCriteriaMatcher`.
- `Layer/LayerRegistry` — ordered list of `LayerDefinition`s with a cached
  `resolveLayer(SymbolPath): ?string` and a `resolveAll(SymbolPath):
  list<LayerMatch>` for shadow diagnostics.
- `Layer/ClassContext` + `Layer/ClassContextFactory` — read-only class view
  used by `LayerDefinition::matches()`; the factory binds the run's
  `DependencyGraphInterface` to resolve transitive interfaces / parents.
- `Layer/LayerPolicy` — allow-list traversal (entry list of
  `(LayerSelector, list<AllowTarget>)` pairs). Honours the relation gate
  and `allow_cross_instance` flag (Steps E/G).
- `Allow/LayerSelector` + `Allow/LayerSelectorParser` — D4 grammar selectors
  (exact / glob / captured). `AllowTarget` bundles the target selector
  with optional `relations: list<DependencyType>` and `allow_cross_instance`
  flag.

### Configuration/

Translates the raw YAML `architecture:` map into the `Domain/` types.

- `ArchitectureConfigurationFactory::fromArray()` — produces an
  `ArchitectureFactoryResult` (configuration + deferred warnings such as
  `mutual-allow` symmetry detection or `wildcard-self-allow`).
- `Allow/AllowAliasExpander` — expands relation aliases (e.g. `inheritance`
  → `extends` + `implements` + `trait_use`) and validates direct
  `DependencyType` tokens reflectively against `DependencyType::cases()`,
  so a new enum case automatically reaches the YAML grammar without code
  changes.
- `Validation/` — stateless validators / normalizers:
  - `LayersValidator` — top-level shape validation, name regex, duplicate
    detection, template-vs-static dispatch.
  - `AllowValidator` — selector parse + cross-reference against declared
    layer names (exact-form only, so glob/captured forms aren't rejected
    pre-expansion).
  - `CoverageValidator`, `ExcludeBlockValidator`, `LayerCriterionNormalizer`,
    `LongFormAllowEntryNormalizer`, `MutualAllowDetector`,
    `WildcardSelfAllowDetector`.

The factory's deferred warnings are aggregated by `ConfigurationPipeline`
into `ResolvedConfiguration::$deferredWarnings`.

### Processing/

Analysis-time helpers — the post-collection pipeline stage that turns
template entries into concrete layers.

- `LayerExpansionStage::expand(list<TemplateLayerDefinition>, ClassSet,
  int $maxExpandedLayers): LayerExpansionResult` — walks every class in the
  project against every template's capture-producing patterns, collects
  the observed `(var → value)` tuples, applies any `passesNonCapturePatterns`
  filter (delegated to `NamespaceMatcher::matchesSingle`), and emits one
  `LayerDefinition::expanded(...)` per tuple. Empty templates surface as
  diagnostic entries; cumulative expansion is bounded by
  `architecture.max_expanded_layers`.
- `LayerExpansionResult` — concrete layer list plus diagnostics
  (empty templates, classes that hit the expansion bound).
- `LayerExpansionException` — fatal failures during expansion (e.g.
  CapturePattern compile errors slipping past pre-validation).

### Rules/

The slice's user-facing consumers.

- `LayerViolationRule` — reads `AnalysisContext::$architecture` (Phase 4
  will switch this to constructor-injected `ArchitectureProcessorInterface`),
  binds the registry to the run's dependency graph, then walks all
  dependency edges. Emits four violation kinds: `architecture.layer-violation`,
  `architecture.coverage` (when `coverage != ignore`),
  `architecture.unreachable-layer` (info), `architecture.potential-shadow`
  (info), and `architecture.empty-template` (warning).
- `LayerViolationOptions` — the rule's Options DTO (severity, max-violations
  reporting controls). All layer/policy configuration lives in
  `ArchitectureConfiguration`, not in this Options.
- `CircularDependencyRule` — detects directed cycles in the class-level
  dependency graph using Johnson's algorithm.
- `CircularDependencyOptions` — `maxCycleSize` plus reporting controls.

## DI registration

[`ArchitectureConfigurator`](../Infrastructure/DependencyInjection/Configurator/ArchitectureConfigurator.php)
scans:

- `src/Architecture/Rules/*Rule.php` — autoconfigured for the `qmx.rule`
  tag (lazy, autowiring off — `RuleOptionsCompilerPass` handles the
  `RuleOptionsInterface` argument).
- `src/Architecture/Processing/*.php` — autowired services.
- `src/Architecture/Configuration/Validation/*.php` — autowired services.

`Domain/` types are pure VOs and are intentionally **not** scanned; they
are constructed by factories / context builders, not retrieved from the
container.

`ArchitectureConfigurationHolder` and `LayerExpansionStage` remain
registered in [`AnalysisConfigurator`](../Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php)
because they bridge analysis-time pipeline state. Phase 4 / ADR 0008 will
fold both behind `ArchitectureProcessor` and the registration will move
here.

## References

- [ADR 0005 — Architecture rules (superseded)](../../docs/adr/0005-architecture-rules.md)
- [ADR 0006 — Declaration-order matching](../../docs/adr/0006-architecture-rules-declaration-order.md)
- [ADR 0007 — Phase 2 design (multi-criterion membership, template layers, captures, exclude, relation filters)](../../docs/adr/0007-architecture-rules-phase-2-design.md)
- [ADR 0008 — ArchitectureProcessor service](../../docs/adr/0008-architecture-processor-service.md)
- [ADR 0010 — Architecture as vertical slice (pilot)](../../docs/adr/0010-architecture-vertical-slice.md)
- [ADR 0012 — Hybrid architectural direction](../../docs/adr/0012-hybrid-architectural-direction.md)
