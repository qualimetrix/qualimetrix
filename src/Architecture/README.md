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
│   ├── CoverageMode.php                     # ignore / warn / error enum
│   ├── Allow/                               # Allow-list selectors (see below)
│   └── Layer/                               # Layer primitives (see below)
├── Configuration/      # YAML → typed configuration; validators
│   ├── ArchitectureConfigurationFactory.php # YAML map → ArchitectureConfiguration
│   ├── ArchitectureFactoryResult.php        # Configuration + DeferredWarnings
│   ├── Allow/AllowAliasExpander.php         # Relation aliases (inheritance → extends/implements/trait_use, etc.)
│   └── Validation/                          # Pre-construction validators / normalizers
├── Processing/         # Analysis-time pipeline helpers
│   ├── ArchitectureProcessor.php            # Single coordinator for the rules-pipeline lifecycle (ADR 0008)
│   ├── ArchitectureProcessorInterface.php   # Public contract for the processor
│   ├── LayerExpansionStage.php              # Template-layer expansion (runs after collection)
│   ├── TupleExtractor.php                   # Observed-tuple collector (extracted from LayerExpansionStage)
│   ├── LayerInstantiator.php                # Concrete-layer factory (extracted from LayerExpansionStage)
│   ├── LayerExpansionResult.php             # Result VO (concrete LayerDefinitions + diagnostics)
│   └── LayerExpansionException.php
├── Rules/              # User-facing rules — slice's only outward consumers
│   ├── LayerViolationRule.php
│   ├── LayerViolationOptions.php
│   ├── CircularDependencyRule.php
│   └── CircularDependencyOptions.php
└── README.md           # This file
```

## External boundary

Per ADR 0010 the slice has a single explicit boundary:

- **Depends on:** `Core` (cross-cutting primitives — `SymbolPath`, `Violation`,
  `Severity`, `Dependency*`, `NamespaceMatcher`), `Rules` (only `RuleInterface`
  registration contract), `Configuration` (`YamlConfigLoader`, `ConfigSchema`),
  Symfony DI.
- **Depended on by:** `Analysis.Pipeline` (calls
  `ArchitectureProcessor::prepare()` between Collection and Enrichment),
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

- `LayerViolationRule` — pulls the prepared configuration from the
  constructor-injected `ArchitectureProcessorInterface` (ADR 0008), reads its
  registry already bound to the run's dependency graph, then walks all
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

`ArchitectureProcessor` (registered here via the
`ArchitectureProcessorInterface` alias) coordinates the rules-pipeline
lifecycle: `RuntimeConfigurator` iterates `AnalysisLifecycleHookInterface`
hooks (the slice ships `ArchitectureLifecycleHook` which calls
`reset() + bind()` on the processor), `AnalysisPipeline` calls `prepare()`
(skipping the call when `architecture.layer-violation` is disabled, since
it is the sole consumer of `getPreparedConfiguration()`), and
`LayerViolationRule` reads `getPreparedConfiguration()`. The hook
indirection keeps `RuntimeConfigurator` feature-agnostic — future slices
add their own hooks without modifying any Infrastructure type. See ADR 0008.

## Testing approach

The pilot's tests form a three-level pyramid. Future vertical slices should
mirror this split so the playbook (the `vertical-slice-playbook` memory
entry) stays applicable.

### Unit — `tests/Architecture/Unit/`

Pure-PHP tests of single classes. No DI, no fixtures on disk. Mirrors the
`src/Architecture/{Domain,Configuration,Processing,Rules}/` shape:

- `Unit/Domain/` — Value objects and enums (`ArchitectureConfigurationTest`,
  `CoverageModeTest`).
- `Unit/Processing/` — Helpers exercised in isolation
  (`ArchitectureProcessorTest`, `LayerExpansionStageTest`,
  `LayerInstantiatorTest`, `TupleExtractorTest`).
- `Unit/Rules/` — Rules with mocked `AnalysisContext`
  (`LayerViolationRuleTest`, `LayerViolationOptionsTest`,
  `CircularDependencyRuleTest`, `CoverageDiagnosticsTest`).
- `Unit/Configuration/` — Loaders and validators
  (`ArchitectureConfigurationFactoryTest`, `Validation/*Test`,
  `Allow/AllowAliasExpanderTest`).

Use these for fast feedback while iterating on a single class. They
typically run in milliseconds and dominate the slice's test count.

### Integration — `tests/Architecture/Integration/`

End-to-end pipeline tests using
[`TestPipelineBuilder`](../../tests/Support/Pipeline/TestPipelineBuilder.php)
against synthetic fixture projects under `tests/Architecture/Fixtures/`.
They run the real `AnalysisPipeline` with a real `ArchitectureProcessor`
pre-bound to a hand-crafted `ArchitectureConfiguration`, so every assertion
covers the same code path production hits (Discovery → Collection →
Architecture prepare → RuleExecution).

Use these to verify cross-class behaviour: layer assignment under
template expansion, allow-list filtering, relations filter, coverage
diagnostics, inline suppression. The fixture per behaviour is the
testability axis — adding a new behaviour usually means adding a new
fixture sample directory plus one integration test class.

Helpers in `tests/Architecture/Support/`:

- `ProcessorBuilder` — fluent construction of a configured
  `ArchitectureProcessor` for tests that don't need the full pipeline.
- `AllowListBuilder` — builds an allow-list policy from exact maps
  without reaching through configuration loaders.
- `ArchitectureViolationProjector` — extracts a comparable summary from
  the violation list (rule name, source/target layers, source FQN) so
  tests assert on stable shape rather than full violation objects.

### Functional — `tests/Functional/Console/`

Full CLI invocation through the Symfony Console application. Today the
slice contributes `LayerAssignmentCommandTest`, which exercises
`debug:layer-assignment` end-to-end including configuration loading,
discovery, collection, and per-class layer resolution. This level is
small by design — most behaviour is covered cheaper at the integration
level. Add a functional test only when CLI-specific concerns (option
parsing, exit codes, output formatting) need pinning.

### Choosing the right level

- A behaviour visible from a single class → unit test.
- A behaviour spanning the configuration → processor → rule pipeline →
  integration test with a fixture.
- A behaviour that only manifests at the CLI boundary (exit code,
  human-readable output, argument parsing) → functional test.

When in doubt, prefer the cheaper level. Integration and functional tests
that could be unit tests slow the suite without adding signal; unit tests
that could be integration tests miss bugs that live in the wiring.

## References

- [ADR 0005 — Architecture rules (superseded)](../../docs/adr/0005-architecture-rules.md)
- [ADR 0006 — Declaration-order matching](../../docs/adr/0006-architecture-rules-declaration-order.md)
- [ADR 0007 — Phase 2 design (multi-criterion membership, template layers, captures, exclude, relation filters)](../../docs/adr/0007-architecture-rules-phase-2-design.md)
- [ADR 0008 — ArchitectureProcessor service](../../docs/adr/0008-architecture-processor-service.md)
- [ADR 0010 — Architecture as vertical slice (pilot)](../../docs/adr/0010-architecture-vertical-slice.md)
- [ADR 0012 — Hybrid architectural direction](../../docs/adr/0012-hybrid-architectural-direction.md)
