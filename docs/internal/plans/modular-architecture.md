# Qualimetrix modular architecture refactoring plan

## Outcome and scope

Replace the current hybrid rule (vertical only for “large” features, horizontal role buckets for “thin” checks) with a capability-oriented modular monolith. Keep a small analysis kernel and delivery infrastructure, but make each independently evolving check capability own its configuration, preparation, state, rules, and stable external contract.

`Operations` and `Checks` may remain documentation/navigation groups only. They are not dependency layers and never grant wildcard sibling access.

This plan deliberately migrates the proven scattered capabilities first. Thin metric/rule categories are inventoried and migrated only after their actual ownership is classified; no empty `Domain/Configuration/Processing/Rules` skeleton is required.

## Architectural decisions

1. A top-level module is a subject with one owner, lifecycle, README, tests, and explicit external consumers. Internal folders are free to follow the subject; only meaningful submodules become enforced boundaries.
2. A module with external consumers exposes `Qualimetrix\{Module}\Contract\**`. External code may import only that namespace. Contract DTOs and errors live there; internal entities, holders, raw config arrays, and framework types do not cross the boundary.
3. Contract stability means consumers are insulated from internals. Breaking changes are allowed under the repository policy but require CHANGELOG migration notes and an ADR when the rationale is non-obvious.
4. The analysis kernel owns phase ports because it consumes them. Capabilities implement those ports and retain their prepared state; the kernel does not grow feature-specific fields.
5. Do not replace direct coupling with one untyped plugin bag/service locator. Extension points are phase-specific and typed. Participants within one family are independent, execute in deterministic order, and fail container compilation on duplicate ids.
6. Mutable per-run state is instance-owned and reset through lifecycle ports. Static feature holders are removed. Process-wide logging/profiling proxies are reviewed separately and are not silently legitimised by this migration.
7. `Core` shrinks to neutral value types with no natural subject owner. “Many imports” is not a reason to put a type there.
8. `Infrastructure` is delivery/composition only. Business/application pipelines such as finding evaluation do not live under Console.
9. `qmx.yaml` is fail-closed: every production namespace is owned by exactly one module/layer; sibling internals are forbidden; only exact `*-contract` targets are public; cycles and uncovered namespaces fail self-check. Temporary grants name owner and deletion condition.

## Target topology

```text
src/
  Analysis/                 # run orchestration kernel
    Contract/               # phase ports, AnalysisResult/API
    Discovery|Collection|Aggregation|Execution/...
  Configuration/            # source merging and neutral run configuration
    Contract/
  DependencyModel/          # dependency graph construction/query/export contracts
    Contract/
  Architecture/             # layer policy capability
    Contract/
  Duplication/              # token/block duplication capability
    Contract/
  ComputedMetrics/          # formulas, evaluation, health subdomain initially
    Contract/
  Measurement/              # metric/repository/collector contracts
    Contract/
  Finding/                  # violation, rule and channel contracts
    Contract/
  InlinePolicy/             # source suppression and threshold overrides
    Contract/
  Baseline/                 # accepted-state ceiling and lifecycle
    Contract/
  FindingEvaluation/        # ordered application of finding policies/projections
    Contract/
  Reporting/                # result-to-output capability
    Contract/
  Checks/                   # optional navigation-only taxonomy; no PHP types or qmx layer
    Complexity|Maintainability|Coupling|Cohesion|Design|Size|CodeSmell|Security/...
  Infrastructure/           # Console, DI, cache, parallel, persistence/delivery adapters
  Core/                     # neutral primitives only
```

`Checks/` is optional. If PSR-4/qmx tooling makes a physical container architectural, put check modules directly under `src/`; the invariant is more important than visual grouping.

## Initial contracts (signatures, not implementations)

```php
// Analysis-owned, phase-specific ports. Participants in one family are
// independent; dependencies between them are forbidden rather than ordered.
interface RunLifecycleParticipantInterface
{
    public function reset(): void;
}

interface GraphPreparationParticipantInterface
{
    public function id(): string;
    public function prepare(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $metrics,
        RuleSelection $selection,
    ): void;
}

interface FileSetInspectionParticipantInterface
{
    public function id(): string;
    /** @param list<AnalysedFile> $files */
    public function inspect(array $files, RuleSelection $selection): void;
}

interface MetricDerivationParticipantInterface
{
    public function id(): string;
    public function derive(MetricRepositoryInterface $metrics, RuleSelection $selection): void;
}

// Configuration-owned neutral syntax contract. A capability parses its own
// section into its private typed configuration; the kernel stores no
// heterogeneous result bag.
interface ConfigurationDocumentInterface
{
    public function section(ConfigurationSectionName $name): ConfigurationNode;
}
```

Constraints:

- inputs contain only the data named by that phase contract, never a mutable universal context;
- participants within one family are independent and execute in stable id order; duplicate ids fail container compilation. If one participant needs another, merge them under one capability owner or introduce a new explicit phase — do not add `before/after`, priorities or a hidden dependency DAG;
- processors keep typed results in their own module services; their rules receive those services by constructor injection;
- `AnalysisContext` retains only genuinely universal rule input. Remove `cycles` and `duplicateBlocks`; do not add `architecture`, computed-metric, or future feature payloads;
- `ResolvedConfiguration` retains neutral run config plus `ConfigurationDocumentInterface`. A module reads only its named node and immediately parses it into its private typed configuration; no caller retrieves an object by type/key from a heterogeneous registry;
- public signatures above are hypotheses to prove in the first two pilots. P0 adds contract tests and a design gate: reject them before broad migration if they require feature-specific fields or participant-to-participant dependencies.

## Capability inventory and disposition

| Current area                                                                                                                         | Subject owner                             | First disposition                                                                            |
| ------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------- | -------------------------------------------------------------------------------------------- |
| `Architecture/**` + architecture config in central pipeline + circular-dependency rule                                               | `Architecture`                            | second capability pilot; expose contract and remove reverse dependencies                     |
| `Analysis/Duplication`, `Core/Duplication`, `Rules/Duplication`                                                                      | `Duplication`                             | first low-risk pilot; module owns detection state and rule                                   |
| `Core/ComputedMetric`, `Configuration/*ComputedMetric*`, `Metrics/ComputedMetric`, `Rules/ComputedMetric`, health-specific reporting | `ComputedMetrics` with `Health` subdomain | third pilot; remove static definition holder                                                 |
| `Core/Dependency`, `Analysis/Collection/Dependency`, graph export contracts                                                          | `DependencyModel`                         | foundational capability used by Analysis, Architecture and Coupling                          |
| `Core/Metric` contracts and repositories/collector extensions                                                                        | `Measurement`                             | foundational subject; inventory parallel-worker reconstruction before move                   |
| `Core/Violation`, `Core/Rule`, channel registries                                                                                    | `Finding`                                 | foundational subject; public rule/channel contracts, internal registries                     |
| source annotations, threshold overrides and their extractors/application                                                             | `InlinePolicy`                            | feature-owned model and worker-aware extraction                                              |
| `Baseline` ceiling/lifecycle                                                                                                         | `Baseline`                                | independent capability; do not merge with InlinePolicy                                       |
| Console violation-filter application order and git projection                                                                        | `FindingEvaluation`                       | application pipeline leaves Console; Git remains adapter                                     |
| `Reporting`                                                                                                                          | `Reporting`                               | retain subject, add narrow input/output contract and keep formatters internal                |
| `Configuration`                                                                                                                      | `Configuration`                           | retain only merge/source/schema mechanics and neutral runtime config                         |
| `Analysis`                                                                                                                           | `Analysis`                                | retain orchestration; split phase internals by subject, not generic extension implementation |
| metric/rule categories                                                                                                               | check capability modules                  | migrate after exhaustive class/co-change/import classification below                         |

Before moving thin checks, enumerate every class in `Metrics/{category}`, `Rules/{category}`, related config/defaults/docs/tests and map it to exactly one proposed owner. Required candidate set from the current tree: `CodeSmell`, `Complexity`, `ComputedMetric`, `Coupling`, `Design`, `Halstead`, `Maintainability`, `Security`, `Size`, `Structure`, `Duplication`, plus shared `Rules/Support` and Metrics foundation types. Decide explicitly whether Halstead belongs to Maintainability and whether Structure splits into Cohesion/Size/Design; names alone do not decide this.

## Work packages

### P0 — Inventory ownership, record invariants and freeze new leaks

Files: new ADR; `docs/ARCHITECTURE.md`; root/project instructions; `qmx.yaml`; `src/Architecture/Rules/LayerViolationRule.php` and its coverage collaborators/tests; architecture topology fixtures; module README template; generated read-only ownership/import inventory artifact.

- Supersede the “large vertical / thin layered” decision, preserving historical rationale.
- Define module, public contract, ownership, taxonomy-only container, state, and context-locality rules.
- Enumerate all seven current auto-registered extension families (`rule`, regular/derived/global collector, formatter, configuration stage, lifecycle hook), every mutable holder/registry, and every production namespace into an owner/public-or-internal table before fixing a contract template.
- Add fail-closed rules that prevent new cross-module internal imports and uncovered production namespaces before moving code.
- Add a machine-readable module manifest only if qmx cannot derive exact public/internal ownership from patterns; it must not duplicate DI registration.

DoD: current tree passes through explicit temporary grants; every grant lists exact edge, owner, and closure package; fixtures prove sibling-internal import, cycle, an uncovered dependency endpoint, and an isolated uncovered class with no dependency edges all fail.

### P1 — Co-locate Duplication as the low-risk public-boundary pilot

Files: all current `src/Analysis/Duplication/*.php`, `src/Core/Duplication/*.php`, `src/Rules/Duplication/*.php`; their direct tests; `src/Analysis/Pipeline/{EnrichmentResult,MetricEnricher}.php`; `src/Core/Rule/AnalysisContext.php`; exact Duplication DI configurators/container entries; `qmx.yaml`; listed READMEs/docs.

- Move the 17 known feature files under `src/Duplication/` and declare exact public/internal namespaces.
- Preserve current orchestration temporarily through a P0 grant; do not invent the generic phase seam during a namespace move.
- Treat `DuplicateBlock`/`DuplicateLocation` as module-owned contract only while external consumers exist; target removal from universal context in P2.

DoD: all direct duplication tests, memory-limit process test, registry/channel wiring, topology fixtures and `composer check` pass; only the explicitly granted Analysis seam imports the Duplication contract and nobody imports internals.

### P2 — Introduce narrow kernel-owned phase contracts and finish Duplication isolation

Files: `src/Analysis/Contract/**`, `src/Analysis/Lifecycle/**`, `src/Analysis/Pipeline/**`, `src/Configuration/Contract/**`, `src/Configuration/Pipeline/**`, DI compiler/configurator files, focused tests and READMEs.

- Extract the three phase-specific ports above and deterministic independent-participant composites.
- Replace feature-specific orchestration in `AnalysisPipeline`/`MetricEnricher` with these ports while adapters still delegate to existing services.
- Execute participants in stable id order and reject duplicate ids. Participant dependencies are invalid by design, so there is no dependency graph or cycle mechanism.
- Keep observable pipeline order and skip-disabled-feature behaviour identical.
- Convert Duplication to `FileSetInspectionParticipantInterface`, inject its run-scoped result provider into its rule, and remove duplicate blocks from `AnalysisContext`/`EnrichmentResult`.

DoD: `AnalysisPipeline` and `MetricEnricher` import no Duplication implementation/rule classes; contract tests prove stable order, independence rule, reset between two runs, disabled-step skip and duplicate-id failure; Duplication's P0 grant closes.

### P3 — Isolate Architecture completely, including circular-cycle preparation

Files: `src/Architecture/**`, architecture DI configurator, related commands/adapters, central configuration/pipeline seams, qmx rules, Architecture tests/README.

- Define the minimum Architecture contract required by debug/export adapters and the analysis kernel.
- Move section resolution, preparation, graph binding and prepared state behind Architecture-owned services.
- Have Architecture adapters implement the Analysis-owned graph-preparation port. Move the circular-dependency detector/result out of `MetricEnricher`/`AnalysisContext` into Architecture in this same package, so no temporary reverse edge or double migration remains.
- Remove `ResolvedConfiguration::$architecture`, direct `ArchitectureConfigurationFactory` construction in central configuration, and direct Architecture imports from Analysis.
- Architecture parses its node from `ConfigurationDocumentInterface`; central Configuration stores neither an Architecture object nor a heterogeneous resolved-object bag.
- Narrow CLI/debug commands to `Architecture\Contract`; implementation scanning remains composition-root metadata, not a public dependency.

DoD: no `configuration -> architecture` or `analysis -> architecture-internal` allowance; no mutual allow; external imports target only `architecture-contract`; architecture rules and debug command retain behaviour; two sequential runs prove state reset.

### P4 — Extract ComputedMetrics and remove static state

Files: `Core/ComputedMetric/**`, computed/health config resolvers, metric/rule paths, health reporting paths, runtime configurator, its exact DI wiring, docs/tests/qmx.

- Co-locate definition parsing, validation, dependency ordering, evaluation, options and rules.
- Replace `ComputedMetricDefinitionHolder` with an instance-owned run definition catalog.
- Implement MetricDerivation participant; the module parses its own configuration node.
- Keep Health as a named subdomain for this package; split only if inventory shows an independent lifecycle/public consumer set.
- Reporting consumes a narrow ComputedMetrics/Health read contract, not internals.

DoD: central Configuration/Analysis/RuntimeConfigurator contain no computed-feature knowledge; two different configurations in one process cannot leak definitions; formula cycles/errors, exclude-health and all health formats retain direct regressions.

### P5 — Extract DependencyModel

Files: current `Core/Dependency/**`, `Analysis/Collection/Dependency/**`, graph exporters, affected Architecture/Coupling/Analysis imports, DI, docs/tests/qmx.

- Separate graph facts/query contract from AST collection adapter and algorithms.
- Give graph construction/query/export a coherent owner; keep AST traversal integration as an Analysis/Infrastructure adapter according to dependency direction.
- Migrate Architecture and Coupling to the public graph contract.

DoD: no feature imports graph implementation; DependencyModel has no feature dependency; graph construction, cycles, exports and coupling regressions remain green.

### P6 — Separate Finding, InlinePolicy, Baseline and FindingEvaluation

Files: `Baseline/**`, `Core/Suppression/**`, `Core/Violation/Filter/**`, suppression collection code, `Infrastructure/Console/ViolationFilter*`, git scope adapter, reporting seams, docs/tests/qmx.

- Use the P0 context map for source annotations, rule exclusion, config exclusion, baseline ceiling, git projection and presentation; name producer/consumer and order for every stage.
- Move neutral violation/rule/channel contracts to Finding, source annotation ownership to InlinePolicy, retain Baseline as its own capability, and place only cross-policy application order in FindingEvaluation.
- Move framework-free filtering/application orchestration out of Console; Console only parses options and renders diagnostics.
- Keep Git client as Infrastructure adapter behind a consumer-owned port.
- Keep Baseline as a peer capability unless the P0 ownership map disproves its independent lifecycle; do not force everything into one mega-module.

DoD: filter order has one authoritative contract test; each result/state type has one owner; FindingEvaluation has no Symfony/Git concrete imports; Baseline remains fail-safe and scope semantics/golden output are unchanged.

### P7 — Classify and migrate thin checks in vertical batches

Files per batch are disjoint by proposed check owner: implementation + rule + config/defaults + tests + docs. Shared DI/qmx files are a final serial integration package, not concurrently edited.

1. Refresh P0's exhaustive ownership table and add a 6–12 month focused co-change matrix excluding mass commits.
2. Resolve ambiguous categories (`Halstead`, `Structure`, `Design`, `Coupling`) from algorithms/data/lifecycle, not current folder names.
3. For each accepted capability, move metric collectors and rules together. Give it a `Contract` namespace only if another module actually consumes it.
4. Delete empty role buckets and shrink Metrics/Rules foundation; do not recreate identical internal role folders by template.

DoD per batch: one owner for every moved class; no cross-check internal import; external imports only through actual contracts; all rule names/options/output stay stable unless a documented breaking change is intentional; README gives an agent a bounded reading set.

### P8 — Remove grants and ratchet context locality

Files: `qmx.yaml`, architecture fixtures, module READMEs, scripts/CI, ADR completion note.

- Remove all temporary edges from P0.
- Add a report/test listing public imports and fan-in per module; treat growth as review evidence, not an automatic design score.
- Add a context-locality checklist to module READMEs: owned code/tests/docs, public dependencies, adapters, change recipes.
- Run complete validation and self-analysis; intentionally review any baseline change rather than regenerating it mechanically.

DoD: no mutual allow, wildcard sibling access, uncovered production namespace, feature static holder, or feature payload in universal contexts; `composer check`, selfcheck, docs build and direct module regressions pass.

## Package dependencies and execution

```text
P0 -> P1 -> P2 -> P3 -> P4 -> P5 -> P6 -> P7 -> P8
```

Packages P0–P6 land sequentially because they share kernel, DI, configuration and topology seams. P7 batches may be delegated in parallel only after an execution plan enumerates exact non-overlapping production/tests/docs files; shared DI, `qmx.yaml`, root docs and generated inventories form a named serial integration package. No package described with `**` is eligible for parallel execution as-is.

Each package is behavioural-preservation first: move + contract + architecture tests in one package, no compatibility shim. Validate focused tests after every package, then `composer check`; run standard/extended review according to changed contracts and inspect seams explicitly.

## Edge cases and regression matrix

- two analyses with different config in one process;
- a disabled expensive capability does zero preparation/allocation;
- empty project and incomplete analysis result;
- parallel collection serialization contains only neutral contracts, never module services/state;
- config warnings emitted after logger setup without a global feature holder;
- deterministic extension order and duplicate-id failure;
- CLI debug/export adapters consume only public contracts;
- baseline + source suppression + CLI exclusion + git projection preserve exact order;
- dynamic rule discovery and constructor dependencies still work after moves;
- cache payload versioning is bumped or proven compatible for moved class names;
- public contract break produces CHANGELOG migration entry;
- taxonomy container cannot become qmx allow target.

## Explicit non-goals

- no generic marketplace/plugin API for third-party checks;
- no `Api/`/`Contract/` folder for private leaf code;
- no numeric context-window hard gate;
- no mass migration based only on names or current directory categories;
- no preservation shim for obsolete internal namespaces.
