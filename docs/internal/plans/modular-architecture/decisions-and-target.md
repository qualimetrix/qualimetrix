# Modular architecture decisions and target

> **Status:** Durable cross-phase decision record. Current phase status is owned
> only by the plan overview.
> **Read first:** [Plan overview](../modular-architecture.md) and [ADR 0022](../../../adr/0022-capability-oriented-modular-monolith.md).
> **Related phase records:** [P0](p0-governance.md), [P1](p1-duplication.md), [P2](p2-dependency-model.md), [P3](p3-run-measurement-configuration.md), [P4](p4-architecture-policy.md), [P5](p5-computed-metrics.md), and [P5–P8](roadmap-p5-p8.md).
## Architectural decisions

1. A leaf module is a subject with one owner, lifecycle, README, tests, and explicit external consumers. Internal folders are free to follow the subject; only meaningful submodules become enforced boundaries.
2. A module with external consumers exposes `Qualimetrix\...\{Module}\Contract\**`. External code may import only that namespace. Contract DTOs and errors live there; internal entities, holders, raw config arrays, and framework types do not cross the boundary. A private leaf module has no `Contract/` directory.
3. Contract stability means consumers are insulated from internals. Breaking changes are allowed under the repository policy but require CHANGELOG migration notes and an ADR when the rationale is non-obvious.
4. `Analysis\Run` owns only a port for an operation it actually invokes. P3 proves one such port, file-set inspection; DependencyModel separately owns the extraction contract it promises to Measurement, Run and Infrastructure. Capabilities retain their own prepared state, and the kernel does not grow feature-specific fields.
5. Do not replace direct coupling with one untyped plugin bag/service locator. Extension points are phase-specific and typed. Participants within one family execute in deterministic order and fail container compilation on duplicate ids. Independence is preferred, but a dependency proven by P0 crosses a typed output of an earlier explicit phase.
6. Mutable per-run state is instance-owned and reset through lifecycle ports. Static feature holders are removed. Process-wide logging/profiling proxies are reviewed separately and are not silently legitimised by this migration.
7. `Core` shrinks to neutral value types with no natural subject owner. “Many imports” is not a reason to put a type there.
8. `Infrastructure` is delivery/composition only. Business/application pipelines such as finding evaluation do not live under Console.
9. `qmx.yaml` is a generated coarse enforcement projection: every production declaration belongs to one semantic-owner layer, no singleton enforcement seam remains, and uncovered namespaces and projected graph cycles fail self-check. Exact `internal|contract` visibility, contract consumers, and permanent exact composition bindings live only in the authoritative internal manifest. Its checker runs before selfcheck and rejects an unlisted import even when a coarse qmx owner edge would permit it.
10. Namespace depth communicates subject scale, not dependency privilege. `Analysis\Evidence\Duplication` and `Analysis\Policy\Baseline` are grouped for navigation; neither parent namespace is an allow-list target or a shared implementation home.
11. Cross-capability sequencing belongs to `Analysis\Run`. The capability that defines an operation owns its state and semantics; run orchestration owns only when it is invoked. Output-only projections, including Git-scoped views, belong to `Reporting`, not to policy evaluation.
12. Tests follow the owning subject. Test level (`Unit`, `Integration`, `Functional`) is an internal subdivision of a module, not the first directory level. A production move and its owned tests/fixtures/support move in the same package; no package may rely on the legacy role-first test tree remaining discoverable.

## Current measured topology

The manifest governs 789 declarations in 787 files through 37 semantic owners
and zero enforcement seams. It records 64 permanent exact composition bindings,
which retain 13 required coarse owner pairs; qmx has 227 declared allow edges.
The generated test topology records 659 governed artifacts, 102 fixture
directories, 518 PHPUnit classes, and 7,036 semantic IDs. The self-analysis
input has 787 analyzed files, 243 active baseline groups across 183 subjects,
and zero dogfood findings. These are measured publication facts, not a separate
source of architectural authority.
13. P0's participant inventory and the P1/P2 pilots inform the rejected phase-port hypotheses below, but do not make them binding. P3 introduces only the reviewed FileSetInspection and DependencyTraversal contracts and their contract tests. P4 and later packages must prove any further port from their actual input, output and consumers. The ADR must not describe unimplemented ports or target namespaces as current architecture.

## P0 design gate: manifest and generated enforcement

P0 uses a versioned internal manifest as the single source of truth for migration ownership. It is build-time governance input, not a runtime module manifest, service registry or DI discovery mechanism. Generated TSV inventories are review views of that source and current AST/import evidence; they are not an alternative authority.

The manifest contract is:

```yaml
version: 1
owners:
  Analysis.Evidence.Example:
    # ... owner metadata
declarations:
  Qualimetrix\Example\ExactType:
    owner: Analysis.Evidence.Example
    visibility: contract # internal | contract
    consumers:
      - owner: Permanent.Consumer.Owner
        source_fqcn: Qualimetrix\Named\ExactConsumer # permanent exact-source authorization
        closes_in: null # permanent consumer
      - owner: Temporary.Consumer.Owner
        source_fqcn: Qualimetrix\Temporary\ExactConsumer
        closes_in: P3 # temporary consumer; closure is mandatory
temporary_grants:
  - source_fqcn: Qualimetrix\Source\ExactType
    target_fqcn: Qualimetrix\Target\ExactInternalType
    owner: Target.Owner
    rationale: "..."
    closes_in: Pn
enforcement_seams:
  Qualimetrix\Example\ExactLegacyType:
    semantic_owner: Analysis.Evidence.Example
    closes_in: Pn
```

Contract consumers are structured entries named explicitly on the target FQCN. The default/omitted relation kind is `import`. New permanent relations name an exact `source_fqcn` and use `closes_in: null`; temporary relations name the exact source plus a P1–P8 closure package. In both cases the source semantic owner must equal `owner`, and another declaration of the same owner is rejected unless separately listed. Legacy permanent owner-wide entries (`source_fqcn: null`, `closes_in: null`) remain schema-valid only for already reviewed relations and are not a template for new grants. Every import entry must match an observed import to the exact target FQCN.

The sole non-import relation is `contract_composition`, used when a producer-owned contract is nested in another public carrier and crosses a serialization boundary without the boundary importing the nested FQCN. It is mutually exclusive with import authorization: `source_fqcn` and `closes_in` are null, while `carrier_fqcn` and `boundary_fqcn` are required exact production FQCNs. It is used only when the resolved native AST proves all of the following: target and carrier are distinct `contract` declarations of the same owner; the carrier stores the exact target in a declared typed property or typed promoted constructor property; the boundary declaration exists and has the relation's consumer owner; the boundary implements `Amp\Parallel\Worker\Task`; its native `run(...)` return type resolves to the exact carrier; the carrier has a separately used normal import consumer for that owner; and the target has no cross-owner import relying on this composition relation. Namespace-local names are resolved exactly, so the positive promoted-private `FileProcessingResult` property reference to same-namespace `SuccessfulFileProcessing` is evidence even without a `use`; an ordinary parameter, docblock, `@implements` or generic annotation is supplemental only and never satisfies native stored containment, Task implementation or return-type proof. Missing, duplicate, same-FQCN, wrong-owner, wrong-visibility, absent native stored containment, parameter-only containment, non-Task boundary, missing/wrong/native-less `run()` return, PHPDoc-only/generic evidence, direct-import-substitution and unused composition relations fail closed. A composition relation is one used contract-consumer entry for inventory/count purposes, but grants no import authorization and adds no qmx allow edge; the carrier's normal used import consumer remains the sole authorization/projection source. Generated ownership TSV renders its relation kind, carrier and boundary deterministically rather than making it look like an observed direct import.

The generator derives coarse semantic-owner/seam qmx allows only from permanent and temporary used import entries, while the manifest checker retains exact temporary source/target/lifecycle enforcement and separately validates composition evidence. It must neither infer a relation from imports nor auto-approve a new import, and an unused consumer or projected allow is an error. The plan has no unused “future consumer” state.

A temporary internal grant is an exact observed `source_fqcn -> target_fqcn` import record with accountable owner, rationale and `closes_in`. The qmx projection can express only its coarse source semantic-owner/seam to target semantic-owner/seam edge, so that edge is not an exact grant and does not authorise sibling imports. Same-owner imports remain within one qmx owner layer. The manifest checker is the sole visibility/import authority: it requires the exact pair, rejects every other cross-owner internal import even when the coarse edge would match it, and fails an unused grant or projected grant edge. A package changes consumers/grants deliberately, then regenerates qmx and inventories; observation never mutates policy.

The generator parses the current production AST and fails unless each declaration has exactly one manifest entry and each manifest declaration exists exactly once in the AST. It emits the production, import, participant, state, Reporting, test, fixture, documentation and PHPUnit-discovery inventories under `docs/internal/generated/modular-architecture/`, including owner/status and `closure_package` columns where migration closure applies. Documentation rows outside P1-P8 use exactly one shared-governance disposition: `P0-D`, `permanent`, or `shared`. It also replaces only marked generated ownership/allow regions in `qmx.yaml`. Its `--check` mode renders to memory or a temporary location and compares all outputs without writing the worktree. This adds no public qmx schema, manifest option or runtime API.

### Current measured topology

The generated authority currently records 789 declarations in 787 files and
37 semantic owners. All 659 governed artifacts, 102 fixture directories, 518
PHPUnit classes, and 7,019 expanded test IDs have one accountable owner or
explicit shared-governance disposition.

| Evidence                       | Current result                                                  | Consequence                                                                                         |
| ------------------------------ | --------------------------------------------------------------: | --------------------------------------------------------------------------------------------------- |
| Exact owner graph              | 37 owner layers, 0 seams                                        | qmx enforces only semantic-owner topology; no declaration is split into a synthetic layer.          |
| Permanent composition bindings | 64 exact rows → 13 coarse qmx pairs                             | the manifest authorizes exact composition imports; qmx retains the corresponding coarse pair edges. |
| Generated qmx projection       | final `external`, 227 declared allow edges                      | uncovered declarations and coarse owner cycles fail closed.                                         |
| Self-analysis                  | 787 files, active baseline 243 groups / 183 subjects, dogfood 0 | current repository analysis has no new accepted-warning delta.                                      |

Composition bindings are permanent policy, not migration exceptions. They are
reviewed exact source/target declarations in the manifest and remain stable
when multiple exact bindings collapse into one coarse qmx owner pair. The
manifest checker remains the sole exact visibility/import authority and runs
before selfcheck; qmx does not authorize an unlisted exact import.

## Target topology

```text
src/
  Analysis/                         # navigation taxonomy; no PHP types or qmx layer
    Run/                            # run orchestration kernel
      Contract/                     # proven Run promises: FileSet inspection and run APIs
      Discovery|Collection|Pipeline|FileSetInspection/...
    Configuration/                  # ordered document resolution and owner-local runtime state
      Contract/?                    # only if a named external consumer needs it
    Finding/                        # finding, rule and channel contracts
      Contract/
    Evidence/                       # navigation taxonomy; no PHP types or qmx layer
      Measurement/                  # metric/repository/collector contracts
        Contract/
      DependencyModel/              # dependency graph facts, construction and query
        Contract/
      Duplication/                  # token/block duplication capability
        Contract/?
      ComputedMetrics/              # formulas, evaluation, health subdomain initially
        Contract/?
      Complexity|Maintainability|Coupling|Cohesion|Design|Size|CodeSmell|Security/...
    Policy/                         # navigation taxonomy; no PHP types or qmx layer
      Architecture/                 # declared layer policy plus circular-dependency preparation
        Contract/?
      Inline/                       # source suppression and threshold overrides
        Contract/?
      Baseline/                     # accepted-state ceiling and lifecycle
        Contract/?
  Reporting/                # result-to-output capability, including graph projections
    Contract/
  Infrastructure/           # Console, DI, cache, parallel, persistence/delivery adapters
  Core/                     # neutral primitives only
```

P0 feasibility evidence confirms this physical taxonomy: PSR-4 maps leaf descendants without requiring a type in each parent, Symfony can scan the existing leaf service directories, and exact qmx membership leaves an unlisted parent or child uncovered. Therefore `Analysis`, `Evidence` and `Policy` remain empty containers rather than layers or allow targets; the leaf modules are the boundaries.

## Target test topology and ownership

```text
tests/
  Analysis/
    Run/{Unit,Integration,Fixtures,Support}/...
    Configuration/{Unit,Integration,Fixtures,Support}/...
    Finding/{Unit,Integration,Fixtures,Support}/...
    Evidence/
      Duplication/{Unit,Integration,Fixtures,Support}/...
      DependencyModel/{Unit,Integration,Fixtures,Support}/...
      ComputedMetrics/{Unit,Integration,Fixtures,Support}/...
      {Capability}/{Unit,Integration,Fixtures,Support}/...
    Policy/
      Architecture/{Unit,Integration,Fixtures,Support}/... # includes circular-dependency preparation tests
      Inline/{Unit,Integration,Fixtures,Support}/...
      Baseline/{Unit,Integration,Fixtures,Support}/...
  Reporting/{Unit,Integration,Functional,Fixtures,Support}/...
  Infrastructure/{Unit,Integration,Functional,Fixtures,Support}/...
  Core/{Unit,Fixtures,Support}/...
  System/{UserScenario}/...       # only whole-product behaviour with no honest leaf owner
  TestSupport/                    # navigation taxonomy only; no files directly in this root
    {NeutralSubject}/...          # named test-infrastructure subject with its own owner/lifecycle
```

Rules:

- the path and `Qualimetrix\Tests\...` namespace mirror the owning production subject before the test level is appended;
- module-specific fixtures, builders, fake services and process scripts stay with that module; shared usage alone never justifies relocation;
- `TestSupport` is a navigation taxonomy, not a shared role bucket. It contains no files or common contract directly; every child is a named neutral subject with coherent semantics, owner and lifecycle, justified independently of its consumer count;
- a test that crosses modules is owned by the module whose promise it verifies. `System` is the last resort for a named whole-product scenario, not a replacement `Integration/` role bucket;
- P0 inventories every test class, fixture file/directory, support class and non-PHP test process/script as `current path -> subject owner -> target path -> PHPUnit suite/package` before any test move;
- each production package moves its owned tests atomically, updates namespaces/imports/fixture paths and adjusts `phpunit.xml.dist`, Composer autoload/classmaps and focused commands as required;
- before the first move, record the PHPUnit-discovered test manifest. After every package, compare discovery so renamed/moved tests cannot disappear silently; then run the focused tests and `composer check`;
- test topology is checked mechanically by P8. Legacy role-first roots may remain only for artifacts that P0 explicitly classifies as cross-module/system-owned with a named closure or permanent owner; `TestSupport` must be empty outside its named subject children.

## Superseded phase-port hypotheses

The former `RunLifecycleParticipantInterface`, `GraphPreparationParticipantInterface`, generic file-set signature with `RuleSelection`, and `MetricDerivationParticipantInterface` were P0 hypotheses, not current or approved contracts. P3's evidence invalidates them: Architecture needs a class universe and private configuration, global derivation needs graph-dependent ordering, and the existing lifecycle hook transports feature configuration. They must not be implemented or published merely to satisfy the old plan count.

The only P3 binding signature is the concrete `FileSetInspectionParticipantInterface` in the P3 amendment. A future capability receives a neutral `ConfigurationDocumentInterface` only after its owning package proves the syntax/document boundary; no `ResolvedConfiguration` field or heterogeneous registry is a substitute. `AnalysisContext` retains only universal rule input; cycles and duplicate blocks remain capability state, never new context payloads.

## Capability inventory and disposition

| Current area                                                                                                                           | Subject owner                                               | First disposition                                                                             |
| -------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `Architecture/**` except circular-dependency types + architecture config in central pipeline                                           | `Analysis\Policy\Architecture`                              | declared layer policy; expose a contract only for named debug/run consumers                   |
| `Architecture/Rules/CircularDependency*` + cycle detector/result currently under Analysis/Core                                         | `Analysis\Policy\Architecture\CircularDependency`           | Architecture-owned policy preparation consuming the DependencyModel contract                  |
| `Analysis/Duplication`, `Core/Duplication`, `Rules/Duplication`                                                                        | `Analysis\Evidence\Duplication`                             | first low-risk pilot; module owns detection state and rule                                    |
| `Core/ComputedMetric`, `Configuration/*ComputedMetric*`, `Metrics/ComputedMetric`, `Rules/ComputedMetric`, health-specific computation | `Analysis\Evidence\ComputedMetrics` with `Health` subdomain | remove static definition holder; Reporting retains rendering only                             |
| `Core/Dependency`, `Analysis/Collection/Dependency` except cycle-specific and export types                                             | `Analysis\Evidence\DependencyModel`                         | graph facts/construction/query used by Run, Architecture, CircularDependency and Coupling     |
| dependency graph DOT/JSON exporters and their format contract                                                                          | `Reporting`                                                 | output projections consuming only the DependencyModel contract                                |
| `Core/Metric` contracts and repositories/collector extensions                                                                          | `Analysis\Evidence\Measurement`                             | foundational evidence subject; inventory parallel-worker reconstruction before move           |
| `Core/Violation`, `Core/Rule`, channel registries                                                                                      | `Analysis\Finding`                                          | foundational analysis subject; public rule/channel contracts, internal registries             |
| source annotations, threshold overrides and their extractors/application                                                               | `Analysis\Policy\Inline`                                    | feature-owned model and worker-aware extraction                                               |
| `Baseline` ceiling/lifecycle                                                                                                           | `Analysis\Policy\Baseline`                                  | independent policy capability; do not merge with Inline                                       |
| Cross-policy invocation order currently under Console                                                                                  | `Analysis\Run`                                              | application orchestration only; operations and state remain with their capability owners      |
| Git-scoped finding projection currently under Console                                                                                  | `Reporting`                                                 | output projection; Git client remains an Infrastructure adapter behind a Reporting-owned port |
| `Reporting`                                                                                                                            | `Reporting`                                                 | retain rendering/projection; reclassify health, impact, debt and filtering computations       |
| `Configuration`                                                                                                                        | `Analysis\Configuration`                                    | retain only merge/source/schema mechanics and neutral runtime config                          |
| `Analysis` orchestration excluding capability implementations                                                                          | `Analysis\Run`                                              | orchestration; split phase internals by subject, not generic extension implementation         |
| metric/rule categories                                                                                                                 | `Analysis\Evidence\{Capability}`                            | migrate after exhaustive class/co-change/import classification below                          |

Before moving thin checks, use the exact declaration rows in `production-ownership.tsv` and the package columns in the generated production/test/documentation inventories; prose globs in this plan are scope summaries, not executable enumerations. The P0 disposition is: Halstead belongs to `Analysis.Evidence.Maintainability`; WMC belongs to `Analysis.Evidence.Complexity`; the legacy `Structure` folder is not an owner and its declarations split into the inventory's Cohesion, Size and Design owners. Apply the same row-level evidence to every current `Reporting/**` type before moving it.

## Work packages
