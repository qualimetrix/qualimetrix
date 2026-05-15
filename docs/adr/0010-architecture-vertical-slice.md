# 0010. Architecture as Vertical Slice — Pilot

**Date:** 2026-05-15
**Status:** Accepted
**Builds on:** [0008 — `ArchitectureProcessor` Service](0008-architecture-processor-service.md)
**Related:** [0012 — Project Architectural Direction](0012-hybrid-architectural-direction.md) (Architecture is the pilot for the hybrid direction)

## Context

The Architecture-rules feature has four structural properties that the project's existing horizontal-layer organization handles awkwardly:

1. **A cross-layer-consuming rule.** `LayerViolationRule` needs Analysis-time artifacts — the dependency graph, the expanded layer registry, per-class membership context, prepared template instances. Most rules in the project read pre-computed metrics out of `MetricsRepository` and emit violations; that's the layered model's sweet spot. This rule needs more.
2. **An independent-lifecycle adapter.** `debug:layer-assignment` is not a thin wrapper over `qmx check` — it runs its own multi-stage flow (Discovery → Collection → bind → prepare → classify) so its output reflects the same prepared state the rule sees.
3. **A multi-stage processing pipeline.** Load → bind → prepare → classify ([ADR 0008](0008-architecture-processor-service.md)) is a feature-internal pipeline, not a thin operation that fits a single horizontal layer.
4. **A set of cross-cutting types.** `LayerRegistry`, `MembershipSpec`, `LayerSelector`, `AllowAliasExpander`, `TemplateLayerDefinition`, `ExcludeSpec`, etc. — these don't have a natural single horizontal-layer home. Phase 1 placed primitives in `Core/Architecture/` and validators in `Configuration/Architecture/` because that's where the dependency edges pointed, not because the boundary was meaningful.

Phase 1's `ArchitectureConfigurationHolder` was the first concession: a cross-layer bridge service whose only purpose was to let the rule reach configuration that the layered model could not deliver. The 8-agent review filed M3 / M4 / M9 / M10 as four separate findings, all rooted in the same cause: the feature is scattered across horizontal layers without a natural single coordinator.

Architecture is the **first project feature to fully meet both criteria** below. Computed Metrics partially qualifies (cross-layer scatter, no independent-lifecycle adapter); HTML Report has natural verticality but limited strain. ADR 0010 therefore frames Architecture as the **pilot** of the project's hybrid direction ([ADR 0012](0012-hybrid-architectural-direction.md)), not as a one-off exception.

## Decision

### Part 1: Architecture is reorganized as a vertical slice

```
src/Architecture/
├── Domain/              (was src/Core/Architecture/)
│   ├── Layer/           — LayerDefinition, MembershipSpec, ClassContext, LayerRegistry, ...
│   ├── Allow/           — LayerSelector, AllowTarget, AllowListEntry, LayerPolicy, ...
│   ├── ArchitectureConfiguration.php
│   └── CoverageMode.php
├── Configuration/       (was src/Configuration/Architecture/)
│   ├── ArchitectureConfigurationFactory.php
│   └── Validation/
├── Processing/          (was src/Analysis/Architecture/, plus new processor)
│   ├── ArchitectureProcessor.php
│   ├── ArchitectureProcessorInterface.php
│   ├── TupleExtractor.php           — extracted from LayerExpansionStage
│   ├── LayerInstantiator.php        — extracted from LayerExpansionStage
│   └── LayerExpansionResult.php
├── Rules/               (was src/Rules/Architecture/)
│   ├── LayerViolationRule.php       — constructor-injects ArchitectureProcessorInterface
│   ├── LayerViolationOptions.php
│   ├── CircularDependencyRule.php
│   └── CircularDependencyOptions.php
└── README.md
```

`ArchitectureConfigurationHolder` is **deleted**. Its two consumers — `RuntimeConfigurator` and the rule — migrate to the processor ([ADR 0008](0008-architecture-processor-service.md)). The `$architecture` field is removed from `AnalysisContext`, eliminating the Core → Architecture type edge. Test trees mirror the production layout under `tests/Architecture/{Unit,Integration,Functional,Fixtures}/...`.

**DI registration.** A new `ArchitectureConfigurator` (in `src/Infrastructure/DependencyInjection/Configurator/`) scans `src/Architecture/Rules/**/*Rule.php` with prefix `Qualimetrix\Architecture\Rules\`. It mirrors `RuleConfigurator`'s configuration (autoconfigured for the `qmx.rule` tag, autowiring disabled, lazy), so `LayerViolationRule` and `CircularDependencyRule` are picked up by `RuleRegistryCompilerPass` and end up in `RuleRegistry`. The same configurator also registers the `ArchitectureProcessorInterface` alias (per [ADR 0008](0008-architecture-processor-service.md)).

### Part 2: Adapter-exclusion principle

Vertical slice contains the domain types (`Domain/`), domain behavior (`Processing/`), domain consumers internal to the feature (`Rules/`), and feature-specific configuration loaders (`Configuration/`).

**Adapters — CLI commands, HTTP endpoints, message handlers — live in `src/Infrastructure/`** and depend on the slice through its public service contracts. `LayerAssignmentCommand` stays at `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php` and injects `ArchitectureProcessorInterface` plus Discovery and Collection services. Infrastructure → Architecture and Infrastructure → Analysis are both legal under CLAUDE.md Critical Rule 1 (dependencies flow downward).

Pulling the command into the slice was rejected: it would force Architecture to depend on `symfony/console`, which is an infrastructure concern. The same logic applies to any future HTTP or message-bus adapter — they belong where the framework integration lives, not in the domain slice.

`RuntimeConfigurator` (Infrastructure) is the boot-time configuration-resolution lifecycle hook for all features — not an Architecture-specific adapter. It stays in Infrastructure and injects `ArchitectureProcessorInterface` like any other consumer.

### Part 3: Criteria for applying vertical slice to a feature

Vertical slice is appropriate when **both** conditions hold:

1. **Cross-layer-consuming rule** — the feature has a rule (or analogous consumer) that needs Analysis-time artifacts: the dependency graph, an expanded registry, multi-stage prepared state. Rules that simply read pre-computed metrics do not qualify.
2. **Independent-lifecycle adapter** — the feature has a debug or inspection command (or similar consumer) with its own multi-stage flow, not a thin wrapper over `qmx check`.

Architecture meets both. Complexity, Cohesion, Coupling, Size, Code Smell, Security, and Design do not — their rules read pre-computed metrics and they have no independent-lifecycle adapter. They stay layered. Computed Metrics and HTML Report are qualified candidates discussed in [ADR 0012](0012-hybrid-architectural-direction.md) but are out of scope for this remediation.

### Part 4: External contracts (domain boundary)

The slice's external dependency surface is explicit:

- **Depends on:** `Qualimetrix\Core\*` (cross-cutting primitives), `Qualimetrix\Core\Rule\RuleInterface` (registration contract), `Qualimetrix\Configuration\Loader\YamlConfigLoader`, `Qualimetrix\Configuration\ConfigSchema`, Symfony DI
- **Depended on by:** `Qualimetrix\Analysis\Pipeline\*` (pipeline calls `processor->prepare()`), `Qualimetrix\Infrastructure\Console\Command\Debug\*` (debug command), `Qualimetrix\Infrastructure\DependencyInjection\*` (DI configurator)
- **No Core → Architecture edge.** Achieved by removing `$architecture` from `AnalysisContext`. This is the single most important boundary property: Core stays pristine.

**Explicit inbound deptrac edges:**

- `Analysis.Pipeline → Architecture` — new edge for `ArchitectureProcessor` consumption from `AnalysisPipeline`
- `Configuration → Architecture` — for `ArchitectureConfigurationFactory` consumption in `ConfigurationPipeline`
- `Infrastructure.* → Architecture` — DI configurator + `LayerAssignmentCommand` + `RuntimeConfigurator`

Architecture itself depends only outward on Core / Configuration loader / `RuleInterface`.

### Part 5: Internal freedom

Inside `src/Architecture/`, sub-namespaces (`Domain`, `Configuration`, `Processing`, `Rules`) may depend on each other freely. Deptrac enforces only the **external** boundary of the slice — what enters and leaves it. Internal organization is a refactoring decision, not an architectural constraint.

### Alternatives considered

- **Status quo with [ADR 0008](0008-architecture-processor-service.md) carve-out only.** Rejected — keeps M3/M4/M9/M10 partially open; the processor centralizes lifecycle but the cross-layer scatter of types remains.
- **Full project restructure to vertical slices.** Rejected — most features fit the layered model honestly; mass migration imposes cost without commensurate benefit. See [ADR 0012](0012-hybrid-architectural-direction.md).
- **Intermediate "ApplicationServices" layer.** Rejected — introduces a new horizontal layer for one feature's strain; abstraction without recurrence.
- **Deptrac exception for `Rules → Analysis` (Architecture only).** Rejected — convention erosion; the boundary that exists today loses its meaning.
- **Domain-events / pubsub model.** Rejected — async-style infrastructure for a synchronous case.

## Consequences

- The single-coordinator claim of [ADR 0008](0008-architecture-processor-service.md) becomes honest at the boundary level: the rule injects the processor and lives in the same slice
- M3, M4, M9, M10 from the 8-agent review are closed by construction
- Future Architecture-feature extensions touch one tree; reviewers and contributors don't chase the feature across `Core`, `Configuration`, `Analysis`, and `Rules`
- The slice becomes the **pilot** of the hybrid direction ([ADR 0012](0012-hybrid-architectural-direction.md)); subsequent qualified migrations (Computed Metrics, HTML Report) reuse the playbook
- Migration cost (file moves, imports, deptrac, DI configurator): estimated ~50 files and ~200 imports; the work is mechanical and isolatable
- External library consumers of `Qualimetrix\Rules\Architecture\*` (if any exist beyond the project itself) will see a namespace change — CHANGELOG documents the assumption; deprecated re-exports can be added if such consumers are discovered post-release

## References

- Vertical slice root: `src/Architecture/`
- Domain layer: `src/Architecture/Domain/` (primitives, registry, allow-list types)
- Configuration layer: `src/Architecture/Configuration/` (factory, validators)
- Processing layer: `src/Architecture/Processing/` (processor, expansion helpers)
- Rules layer: `src/Architecture/Rules/` (layer-violation, circular-dependency)
- Debug adapter (outside slice): `src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php`
- Builds on: [ADR 0008](0008-architecture-processor-service.md) (processor service contract)
- Direction context: [ADR 0012](0012-hybrid-architectural-direction.md) (Architecture as pilot of hybrid model)
- Prior decisions inherited: [ADR 0005](0005-architecture-rules.md), [ADR 0006](0006-architecture-rules-declaration-order.md), [ADR 0007](0007-architecture-rules-phase-2-design.md)
