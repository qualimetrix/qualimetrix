# Qualimetrix modular architecture refactoring plan

## Outcome and scope

Replace the current hybrid rule (vertical only for “large” features, horizontal role buckets for “thin” checks) with a capability-oriented modular monolith. Keep a small analysis-run kernel and delivery infrastructure, but make each independently evolving check capability own its configuration, preparation, state, and rules. A capability exposes a stable external contract only when a named external consumer exists.

Use `Analysis`, `Evidence`, and `Policy` as navigation taxonomies that make the product model visible in namespaces. They contain no production types, state, shared contracts or qmx layers; they never grant wildcard sibling access. Architectural boundaries remain the leaf modules.

This plan deliberately migrates the proven scattered capabilities first. Thin metric/rule categories are inventoried and migrated only after their actual ownership is classified; no empty `Domain/Configuration/Processing/Rules` skeleton is required.

## Architectural decisions

1. A leaf module is a subject with one owner, lifecycle, README, tests, and explicit external consumers. Internal folders are free to follow the subject; only meaningful submodules become enforced boundaries.
2. A module with external consumers exposes `Qualimetrix\...\{Module}\Contract\**`. External code may import only that namespace. Contract DTOs and errors live there; internal entities, holders, raw config arrays, and framework types do not cross the boundary. A private leaf module has no `Contract/` directory.
3. Contract stability means consumers are insulated from internals. Breaking changes are allowed under the repository policy but require CHANGELOG migration notes and an ADR when the rationale is non-obvious.
4. `Analysis\Run` owns phase ports because it consumes them. Capabilities implement those ports and retain their prepared state; the kernel does not grow feature-specific fields.
5. Do not replace direct coupling with one untyped plugin bag/service locator. Extension points are phase-specific and typed. Participants within one family execute in deterministic order and fail container compilation on duplicate ids. Independence is preferred, but a dependency proven by P0 crosses a typed output of an earlier explicit phase.
6. Mutable per-run state is instance-owned and reset through lifecycle ports. Static feature holders are removed. Process-wide logging/profiling proxies are reviewed separately and are not silently legitimised by this migration.
7. `Core` shrinks to neutral value types with no natural subject owner. “Many imports” is not a reason to put a type there.
8. `Infrastructure` is delivery/composition only. Business/application pipelines such as finding evaluation do not live under Console.
9. `qmx.yaml` is a generated coarse enforcement projection: every production declaration belongs to its one semantic-owner layer except an explicitly declared singleton seam, and uncovered namespaces and projected graph cycles fail self-check. Exact `internal|contract` visibility, contract consumers and temporary grants live only in the authoritative internal manifest. Its checker runs before selfcheck and rejects an unlisted import even when the coarse owner/seam qmx allow would permit it.
10. Namespace depth communicates subject scale, not dependency privilege. `Analysis\Evidence\Duplication` and `Analysis\Policy\Baseline` are grouped for navigation; neither parent namespace is an allow-list target or a shared implementation home.
11. Cross-capability sequencing belongs to `Analysis\Run`. The capability that defines an operation owns its state and semantics; run orchestration owns only when it is invoked. Output-only projections, including Git-scoped views, belong to `Reporting`, not to policy evaluation.
12. Tests follow the owning subject. Test level (`Unit`, `Integration`, `Functional`) is an internal subdivision of a module, not the first directory level. A production move and its owned tests/fixtures/support move in the same package; no package may rely on the legacy role-first test tree remaining discoverable.
13. P0's participant inventory and the P1/P2 pilots inform the phase-port signatures below, but do not make them binding. P3 introduces the interfaces and their contract tests; its named phase-port contract gate must pass before P4 or any later consumer adopts them. The ADR must not describe unimplemented ports or target namespaces as current architecture.

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
        source_fqcn: null # permanent authorization is owner-wide for this target
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

Contract consumers are structured entries named explicitly on the target FQCN. A permanent entry has `closes_in: null` and `source_fqcn: null`; it authorises that semantic owner owner-wide for this exact target contract declaration. A temporary entry must name both a P1–P8 closure package and one exact `source_fqcn`; the source declaration's semantic owner must equal `owner`, and only that declaration may import the target. A fourth declaration of the same owner is rejected unless separately listed. Every entry must match at least one observed import to the exact target FQCN: permanent entries match any source declaration of their owner, temporary entries only their exact source. The generator derives coarse semantic-owner/seam qmx allows from both permanent and temporary used entries, while the manifest checker retains exact temporary source/target/lifecycle enforcement. It must neither infer a consumer from imports nor auto-approve a new import, and an unused consumer or projected allow is an error. The plan has no unused “future consumer” state.

A temporary internal grant is an exact observed `source_fqcn -> target_fqcn` import record with accountable owner, rationale and `closes_in`. The qmx projection can express only its coarse source semantic-owner/seam to target semantic-owner/seam edge, so that edge is not an exact grant and does not authorise sibling imports. Same-owner imports remain within one qmx owner layer. The manifest checker is the sole visibility/import authority: it requires the exact pair, rejects every other cross-owner internal import even when the coarse edge would match it, and fails an unused grant or projected grant edge. A package changes consumers/grants deliberately, then regenerates qmx and inventories; observation never mutates policy.

The generator parses the current production AST and fails unless each declaration has exactly one manifest entry and each manifest declaration exists exactly once in the AST. It emits the production, import, participant, state, Reporting, test, fixture, documentation and PHPUnit-discovery inventories under `docs/internal/generated/modular-architecture/`, including owner/status and `closure_package` columns where migration closure applies. Documentation rows outside P1-P8 use exactly one shared-governance disposition: `P0-D`, `permanent`, or `shared`. It also replaces only marked generated ownership/allow regions in `qmx.yaml`. Its `--check` mode renders to memory or a temporary location and compares all outputs without writing the worktree. This adds no public qmx schema, manifest option or runtime API.

### Feasibility evidence from the current snapshot

These figures describe the generated snapshot reviewed at the P0 design gate; they are assertions over today's inputs, not constants that a future generator may hardcode:

| Evidence                                        | Current result                          | Consequence                                                                                         |
| ----------------------------------------------- | --------------------------------------: | --------------------------------------------------------------------------------------------------- |
| AST declarations / source files                 | 695 / 693                               | ownership is declaration-based; two multi-declaration files must not be collapsed to file ownership |
| Exact FQCN dependency graph                     | 695 vertices / 2,951 edges / DAG        | current code cycles are not the cause of the projected owner cycles                                 |
| Cross-owner exact imports                       | 1,945 pairs; generated-inventory diff 0 | manifest and projection use the same current dependency extraction                                  |
| Semantic-owner graph before seams               | 37 vertices / 191 edges                 | coarse aggregation creates two SCCs, of sizes 10 and 2                                              |
| Exact imports targeting `internal` declarations | 85 unique source/target FQCN pairs      | the manifest validates every pair as an exact temporary grant                                       |
| Coarse owner edges projected from those grants  | 16                                      | qmx cannot authorise exact imports; the mandatory manifest checker rejects every unlisted pair      |
| Graph after inclusion-minimal seams             | 51 vertices / 245 edges / no SCC        | 37 non-empty semantic-owner layers plus 14 singleton seams form an internal DAG                     |
| Final graph including `external`                | 52 layers / 296 allow edges             | 51 internal layers each add one edge to the final no-dependency `external` layer                    |

The rejected partial 41-layer `qmx.yaml` draft proved only selected explicit
Metrics ownership. It auto-enrolled declarations through broad role buckets and
did **not** satisfy the owner-level P0 DoD. The current generated projection has
replaced that draft with semantic-owner/seam membership; its presence is P0-A
implementation evidence, not acceptance of the proposed P1-P8 physical layout.

The current generated projection uses the following 14 singleton enforcement seams. A seam changes only the qmx enforcement vertex for one exact legacy FQCN; its true `semantic_owner` and `visibility` remain manifest facts. All 37 owner layers remain non-empty (the smallest retains three declarations). The set is inclusion-minimal: returning any one row to its owner recreates the cycle named in the reason column.

| Exact seam FQCN                                                   | True owner / visibility                        | Closure | Incoming → outgoing projection and removal proof                                                             |
| ----------------------------------------------------------------- | ---------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------------ |
| `Qualimetrix\Analysis\Collection\Declaration\DeclarationBindings` | `Analysis.Run` / contract                      | P6      | Run/SourceControls → Measurement/Inline/Core.Path/Core.Symbol; removal cycles Run ↔ Inline                   |
| `Qualimetrix\Analysis\Collection\SourceControl\SourceControls`    | `Analysis.Policy.Inline` / contract            | P6      | Run → Inline/DeclarationBindings; removal cycles Inline ↔ Run                                                |
| `Qualimetrix\Analysis\RuleExecution\RuleExecutor`                 | `Analysis.Finding` / internal                  | P6      | DI → Configuration/Finding/Observability; removal closes Configuration→ComputedMetrics→Finding→Configuration |
| `Qualimetrix\Architecture\Processing\ArchitectureLifecycleHook`   | `Analysis.Policy.Architecture` / internal      | P4      | none → Configuration/Architecture/Run; removal cycles Configuration ↔ Architecture                           |
| `Qualimetrix\Baseline\Suppression\RuleValidatorMapFactory`        | `Analysis.Policy.Inline` / contract            | P6      | DI/Parallel → Finding; removal cycles Finding ↔ Inline                                                       |
| `Qualimetrix\Baseline\Suppression\SuppressionFilter`              | `Analysis.Policy.Inline` / contract            | P6      | Console/DI → Finding/Inline; removal cycles Finding ↔ Inline                                                 |
| `Qualimetrix\Configuration\ComputedMetricsConfigResolver`         | `Analysis.Evidence.ComputedMetrics` / contract | P5      | Console/DI → Configuration/Computed/Finding/Symbol; removal cycles Config↔Computed                           |
| `Qualimetrix\Configuration\Exception\ConfigLoadException`         | `Analysis.Configuration` / contract            | P4      | Configuration/Architecture/Console → seam; removal cycles Config ↔ Architecture                              |
| `Qualimetrix\Configuration\Pipeline\DeferredWarning`              | `Analysis.Configuration` / contract            | P3      | Architecture → seam; removal cycles Configuration ↔ Architecture                                             |
| `Qualimetrix\Core\Metric\GlobalContextCollectorInterface`         | `Analysis.Evidence.Measurement` / contract     | P2      | Coupling/Design/Run/DI → DependencyModel/Measurement; removal cycles Measurement ↔ DependencyModel           |
| `Qualimetrix\Core\Rule\RuleMatcher`                               | `Analysis.Finding` / contract                  | P6      | Configuration/Finding/Inline/Run → seam; removal cycles Finding ↔ Inline                                     |
| `Qualimetrix\Core\Violation\Location`                             | `Analysis.Finding` / contract                  | P6      | capabilities/policies/Run/Reporting → Path; removal cycles Finding→DependencyModel                           |
| `Qualimetrix\Reporting\Health\HealthReasonBuilder`                | `Reporting` / contract                         | P5      | Health/Reporting → Computed/MetricHint; removal cycles Health ↔ Reporting                                    |
| `Qualimetrix\Reporting\Health\MetricHintProvider`                 | `Reporting` / contract                         | P5      | Health/Reporting/HealthReasonBuilder → seam; removal cycles Health ↔ Reporting                               |

The resulting current projection has 37 semantic-owner layers plus 14 seams and final `external`: 52 qmx layers total. Its 245-edge internal graph is a DAG; adding the 51 project-to-external edges keeps it a DAG. A seam is neither a module nor a publication decision, and its qmx permissions remain coarse. The mandatory manifest checker runs before selfcheck and remains the sole exact visibility/import authority.

### Rejected owner/status projection

The earlier 60 owner/status layers plus nine status-oriented seams are retained only as rejected feasibility evidence. The live probe found same-owner `contract ↔ internal` cycles before qmx generation. Omitting those same-owner edges made the declaration graph look acyclic but produced 637 architecture errors in sequential selfcheck (`MetricBag → DataBag`, `Baseline → BaselineIdentity`, Run internal → contract, and others). Adding more status seams would encode scattered legacy internals as false architectural boundaries. Visibility therefore remains exact manifest policy, not qmx layer identity.

Alternatives rejected at this gate:

- a public qmx manifest plus temporary-import overlay would make an internal migration escape hatch part of qmx's public schema and API;
- an inventory-only ratchet outside qmx would leave broad qmx ownership in place or duplicate the dependency-policy engine;
- deriving consumers and grants from observed imports would approve the very dependency that fail-closed enforcement must review.

## Target topology

```text
src/
  Analysis/                         # navigation taxonomy; no PHP types or qmx layer
    Run/                            # run orchestration kernel
      Contract/                     # phase ports, AnalysisResult/API
      Discovery|Collection|Aggregation|Execution/...
    Configuration/                  # source merging and neutral run configuration
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
      CircularDependency/           # cycle detection, result and finding capability
        Contract/?
      ComputedMetrics/              # formulas, evaluation, health subdomain initially
        Contract/?
      Complexity|Maintainability|Coupling|Cohesion|Design|Size|CodeSmell|Security/...
    Policy/                         # navigation taxonomy; no PHP types or qmx layer
      Architecture/                 # declared layer policy capability
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
      CircularDependency/{Unit,Integration,Fixtures,Support}/...
      ComputedMetrics/{Unit,Integration,Fixtures,Support}/...
      {Capability}/{Unit,Integration,Fixtures,Support}/...
    Policy/
      Architecture/{Unit,Integration,Fixtures,Support}/...
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

## Initial contracts (signatures, not implementations)

```php
// Analysis\Run-owned, phase-specific ports. Participants in one family are
// independent unless P0's participant/data-dependency inventory proves that
// one consumes another's typed output.
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
- participants within one family execute in stable id order and duplicate ids fail container compilation;
- independence is the default, not an axiom. P0 must enumerate participant inputs and outputs. A real dependency is represented by a typed output from an earlier explicit phase; do not hide it with `before/after`, priorities, a service locator or shared mutable state. Merge participants only when the ownership inventory shows one subject;
- processors keep typed results in their own module services; their rules receive those services by constructor injection;
- `AnalysisContext` retains only genuinely universal rule input. Remove `cycles` and `duplicateBlocks`; do not add `architecture`, computed-metric, or future feature payloads;
- `ResolvedConfiguration` retains neutral run config plus `ConfigurationDocumentInterface`. A module reads only its named node and immediately parses it into its private typed configuration; no caller retrieves an object by type/key from a heterogeneous registry;
- public signatures above remain non-binding through P0/P1/P2. Their inventories and pilot evidence may revise them. P3 introduces the binding interfaces and contract tests, and its phase-port contract gate rejects the design before P4+ adoption if it requires feature-specific fields, hidden ordering or an untyped participant-to-participant dependency.

## Capability inventory and disposition

| Current area                                                                                                                           | Subject owner                                               | First disposition                                                                             |
| -------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `Architecture/**` except circular-dependency types + architecture config in central pipeline                                           | `Analysis\Policy\Architecture`                              | declared layer policy; expose a contract only for named debug/run consumers                   |
| `Architecture/Rules/CircularDependency*` + cycle detector/result currently under Analysis/Core                                         | `Analysis\Evidence\CircularDependency`                      | separate evidence capability consuming the dependency graph contract                          |
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

### P0 — Establish authoritative ownership and fail-closed enforcement — Completed

**Status:** Completed. Final verification is green: the manifest covers 695 declarations in 693 files and 37 semantic owners; the generated projection has 14 singleton seams, 52 total layers and 296 allow edges; all 85 exact internal grants project to 16 coarse edges. Full PHPUnit passed with 7,200 tests and 21,118 assertions, and architecture governance plus selfcheck are green. P1 has since completed; no later P2-P8 physical move or P3 phase-port contract was implied by P0 completion alone.

P0 was split into executable, non-overlapping file slices. The manifest and its qmx projection form one atomic enforcement package. Shared outputs belong to that slice only; later slices consume them read-only.

#### P0-A — Authoritative ownership and enforcement — Completed

Files: `docs/internal/modular-architecture-manifest.json`, `docs/internal/modular-architecture-manifest.schema.json`, and narrow `.gitignore` exceptions for exactly those two otherwise ignored JSON files; modular-architecture inventory/projection generators; the generated ownership/allow region and its immediately preceding topology header comments in `qmx.yaml`; generated production/import/participant/state/Reporting/test/fixture/documentation/PHPUnit TSV or text artifacts; the `composer check` script entry that invokes generator `--check`; qmx projection/invariant tests and architecture topology fixtures; the enforcement-foundation production/tests below. No ADR, general documentation, baseline or plan file.

**Verified:** manifest/AST coverage, exact consumers/grants, generated 37-owner + 14-seam + `external` DAG, freshness checking, isolated-class coverage and exact declared-cycle validation are green.

The only new `.gitignore` exceptions are `!docs/internal/modular-architecture-manifest.json` and `!docs/internal/modular-architecture-manifest.schema.json`; the repository-wide JSON ignore policy otherwise remains intact.

Enforcement-foundation file scope:

- coverage: `src/Architecture/Rules/LayerViolationRule.php` and `tests/Architecture/Unit/Rules/CoverageDiagnosticsTest.php`;
- exact allow-cycle validation and wiring: `src/Architecture/Configuration/Validation/ExactAllowCycleValidator.php`, `src/Architecture/Configuration/Validation/AllowValidator.php`, `src/Architecture/Configuration/ArchitectureConfigurationFactory.php`, `tests/Architecture/Unit/Configuration/Validation/ExactAllowCycleValidatorTest.php`, `tests/Architecture/Unit/Configuration/Validation/AllowValidatorTest.php` and `tests/Architecture/Unit/Configuration/ArchitectureConfigurationFactoryTest.php`;
- warning-contract migration: remove `src/Architecture/Configuration/Validation/MutualAllowDetector.php` and `tests/Architecture/Unit/Configuration/Validation/MutualAllowDetectorTest.php`; retain and revalidate the real warning in `src/Architecture/Configuration/Validation/WildcardSelfAllowDetector.php` and `tests/Architecture/Unit/Configuration/Validation/WildcardSelfAllowDetectorTest.php`; align its deferred-warning wiring/comments in `src/Architecture/Configuration/Validation/LongFormAllowEntryNormalizer.php`, `src/Configuration/Pipeline/ConfigurationPipeline.php`, `src/Infrastructure/DependencyInjection/Configurator/ConfigurationConfigurator.php`, `src/Infrastructure/Console/RuntimeConfigurator.php`, `tests/Integration/Configuration/DeferredWarningIntegrationTest.php`, `tests/Unit/Configuration/Pipeline/ConfigurationPipelineTest.php`, `tests/Unit/Infrastructure/Console/RuntimeConfiguratorTest.php` and the comments-only example in `tests/Functional/Console/Command/CheckCommandTest.php`.

- Replace path heuristics, inferred visibility and inferred consumers with exact manifest declarations and exact observed import-pair checks.
- Preserve AST facts independently from policy: the parser discovers declarations/imports; the manifest supplies true owner, `internal|contract`, structured per-FQCN consumers with lifecycle, exact temporary internal grants and singleton seams.
- Make coverage account for canonical-deduplicated analysed classes even when the dependency graph has no edges, so an isolated uncovered class fails while an owned isolated class remains clean.
- Preserve exact self-edges through allow normalization, then run `ExactAllowCycleValidator` from `ArchitectureConfigurationFactory` before analysis; reject exact self-, two- and three-plus-node declared allow cycles. Do not duplicate actual code-cycle detection.
- Remove mutual-allow warning semantics and its detector entirely; mutual exact allows are now a hard two-node cycle error, while the unrelated `wildcard-self-allow` warning continues through the deferred-warning pipeline.
- Derive coarse semantic-owner/seam qmx allows from used permanent and temporary per-FQCN consumer entries and exact grants. The 85 internal pairs currently contribute 16 coarse owner edges; never describe a qmx edge as an exact grant.
- Generate one layer per 37 semantic owners, the exact 14 singleton seams above, and final `external`. Visibility is never part of a qmx layer name or membership rule. `external` excludes `Qualimetrix\**`, project layers may depend on it, and it depends on none.
- Keep `Analysis`, `Evidence`, `Policy` and every unlisted child namespace uncovered; no owner/category template or broad role-bucket pattern may enrol a new declaration.
- Emit `closure_package` for every movable production/test/documentation row, temporary contract consumer and temporary internal grant. Counts in reports are computed from input, never asserted as generator constants.
- Implement normal write mode and a no-write `--check` comparison mode; validate the declared qmx allow graph as a DAG before analysis, while actual code-cycle detection remains a separate rule.

DoD: for the reviewed snapshot, 695 declarations in 693 files map 1:1 to the AST and exactly one of 37 owners; all 771 structured consumer entries are explicit and used, and every observed cross-owner import matches one. Permanent entries have `source_fqcn: null` and `closes_in: null`; temporary entries name an exact source FQCN and closure package, their source semantic owner equals `owner`, and a fourth same-owner source declaration is rejected; internal declarations cannot publish consumers. All 85 unique imports whose target is `internal` match used exact source-FQCN/target-FQCN grants; they contribute 16 coarse owner edges, and every other cross-owner import enabled only by a coarse qmx edge is rejected by the manifest checker before selfcheck. Unused consumers, projected allows, exact grants and projected grant edges fail. The qmx projection has 37 non-empty owner layers plus 14 singleton seams and `external` (52 total); the 51-vertex/245-edge internal graph and 52-vertex/296-edge final graph are DAGs, and returning any seam to its true owner recreates a cycle. qmx membership equals manifest semantic owner except for the declared enforcement layer of those 14 exact FQCNs; visibility never changes qmx membership, and a new declaration or import cannot add a consumer, layer, allow or grant during regeneration. Focused regressions prove an isolated uncovered class fails even with an empty dependency graph, an owned isolated class stays clean, repository/edge class evidence deduplicates, and ignore mode remains unchanged. Exact declared self-, two- and three-plus-node cycles fail before analysis; no production/test reference to `MutualAllowDetector` or active mutual-allow warning remains, and the `wildcard-self-allow` deferred-warning seam remains green. Topology fixtures additionally prove same-owner internal/contract imports remain inside one owner layer, permanent and exact temporary contract imports are checked by the manifest, a fourth same-owner temporary source and an unlisted cross-owner internal pair fail despite a coarse qmx allow, seam diagnostics retain true manifest owner, taxonomy-root and unlisted-child declarations are uncovered, and uncovered endpoint/isolated class/actual class-cycle cases fail. The two internal manifest JSON files are not ignored while unrelated JSON policy stays unchanged. Re-run the existing 304-test Architecture/configuration/DI/console seam evidence, all inventories, mandatory manifest check before selfcheck, `composer check` freshness and selfcheck; all are green and `--check` leaves the worktree byte-for-byte unchanged. The 695/771/85/16/14 and graph counts are generated snapshot evidence, never hardcoded invariants.

#### P0-B — Documentation and ADR 0022 alignment — Completed

Files: ADR 0022, `docs/ARCHITECTURE.md`, affected source/component READMEs, root/project instructions and module README template. No manifest, generator, qmx, tests, plan or baseline file.

**Verified:** ADR 0022 is accepted, ADR 0012 is superseded, current manifest/qmx enforcement is distinguished from pending P1-P8 physical moves, and phase ports remain explicitly non-binding until P3.

- Supersede the “large vertical / thin layered” rule while preserving its historical rationale.
- Document taxonomy-only containers, leaf ownership, contract publication, manifest authority, generated enforcement and the fact that generated inventories are neither runtime metadata nor DI registration.
- Preserve the distinction between current implemented governance (authoritative 695-declaration/37-owner manifest plus generated 37-owner + 14-seam + `external` projection) and the pending P1-P8 physical layout. Retain the former 41-layer draft and 60/9 status probe only as rejected feasibility history, and describe phase ports only as non-binding hypotheses informed by P0/P1/P2.
- Record ADR 0022 as `Accepted` after the green P0-D review; no other package owns those decision-status documents. Completed.

DoD: accepted-state docs accurately describe P0 enforcement as current without claiming P1-P8 moves; public docs do not expose an internal manifest/qmx option; ADR alternatives, rejected projections and the 14 temporary enforcement seams match this design gate; direct `bin/qmx check` is distinguished from mandatory repository governance; final P0 completion rechecked all docs for current/target consistency.

#### P0-C — Baseline reconciliation — Completed

Files: `qmx-baseline.json` and baseline-specific verification evidence only. No manifest, generator, qmx configuration, topology test, plan or documentation file.

**Verified:** selfcheck is green with no architecture coverage/layer escape hatch in the v11 ratchet.

- Reconcile only findings whose identity changes because fail-closed topology becomes a hard gate.
- Review any residual snapshot delta; do not regenerate accepted debt mechanically and do not baseline architecture coverage or layer violations.

DoD: `composer selfcheck` has no new topology violation; any baseline delta is minimal, explained and contains no ownership/grant escape hatch.

#### P0-D — Final plan review gate — Completed

Files: this plan only. P0-D read P0-A evidence, P0-B's staged docs, P0-C evidence and all generated inventories but edited no implementation/configuration/documentation artifact.

**Verified:** the final independent governance review returned GO; generated inventories and qmx are fresh, the exact/coarse enforcement seam is covered, and full PHPUnit plus architecture/selfcheck are green.

- Materialise P1–P8's exact production/test/documentation scope through authoritative generated TSV rows whose `closure_package` is exactly P1 through P8, rather than duplicating hundreds of paths in prose. Every non-migration documentation row uses exactly one of `P0-D`, `permanent`, or `shared` and is not silently assigned to a migration package by filename substrings.
- Record design-gate evidence, resolved dispositions and package dependencies, then perform a fresh architecture-plan review.
- Authorise P0-B's `Proposed -> Accepted` documentation closure after enforcement, baseline and plan findings are resolved. Completed.

DoD: the review confirmed manifest/AST 1:1 coverage, all 85 exact internal import-pair checks and their 16 coarse owner projections, all 771 explicit/used structured consumers with valid permanent owner-wide or temporary exact-source lifecycle, 37 non-empty owner layers + 14 inclusion-minimal seams + external, 245-edge internal and 296-edge final DAGs, non-inferred imports, used closure-named exact grants, narrow manifest JSON ignore exceptions, `composer check` freshness enforcement and the complete topology fixture matrix. Documentation inventory discovery is committable/reproducible, root instructions and shared governance documents have explicit non-migration dispositions, and no generated P1-P8 row contradicts a P0/shared owner. P0-A, P0-B, P0-C and P0-D are complete; the generated 52-layer projection is current enforcement, while the rejected 41-layer draft and rejected 60/9 status projection remain historical evidence only.

For every P1–P8 package, rows bearing that closure in the generated production, test and documentation inventories are the authoritative enumeration of artifacts whose **semantic ownership or physical location migrates** in that package. They are not the complete executable edit set. Each package must additionally enumerate its exact integration consumers, DI adapters, discovery guards, governance inputs/outputs and current-layout documentation; touching those files does not change their later semantic owner or closure package. Documentation rows marked `P0-D`, `permanent`, or `shared` remain governance/lifecycle dispositions, but a package updates them when its landed current state would otherwise make them false. The package first changes manifest policy deliberately, applies its physical and integration edits, regenerates the 37-owner/remaining-seam qmx projection and every inventory, proves the new projection DAG and every remaining seam necessary, and finally runs the generator in `--check` mode. Generated TSVs enumerate migration-owned moves and verify freshness; they are not runtime manifests, DI registries or substitutes for the package's explicit integration list.

### P1 — Co-locate Analysis\Evidence\Duplication and isolate its run-scoped result

**Status:** Completed. Implementation, review fixes and final re-review are complete. P1 deliberately absorbs the Duplication-specific isolation formerly deferred to P3. It does not introduce a generic phase participant or bind any of P3's proposed Run-owned ports. The final post-P1 snapshot contains 697 declarations in 695 files, retains 37 semantic owners and 14 singleton seams, and projects 84 exact internal grants to 15 coarse edges. Full PHPUnit passes with 7,206 tests, 21,273 assertions and one skip; architecture generation/check, selfcheck, full CS, P1-scoped PHPStan, cross-tool tests, strict documentation build and leak checks are green. Baseline reconciliation is complete without accepting new debt. Full PHPStan and therefore aggregate `composer check` remain red only for the pre-existing unrelated `LoggerFactory.php:72` `missingType.iterableValue` finding; this is not claimed as P1-green evidence. P2 is now the next package and has not started.

#### Boundary and lifecycle

- Move the 17 existing declarations to the leaf module and add one internal run-scoped result provider. `DuplicateBlock`, `DuplicateLocation`, the provider, detector implementation, rule and options are internal module types.
- Replace the current returning detector contract with the one narrow external inspection contract required by `Analysis\Pipeline\MetricEnricher`. Its inspection operation replaces the provider's complete result for that run; a reset operation clears prior state before the enabled/disabled decision. The exact signatures are `reset(): void` and `inspect(array $files): void`, with `@param list<SplFileInfo> $files` on the contract. The rule receives the internal provider by constructor injection and reads `list<DuplicateBlock>` from it; neither Run nor `AnalysisContext` transports that list.
- Remove `duplicateBlocks` from `EnrichmentResult`, `AnalysisContext` and the `AnalysisPipeline` projection in P1. An enabled run followed by a disabled or no-match run must expose an empty provider result, never the previous run's blocks. The disabled path performs only the provider reset: no tokenisation, hash index, duplicate block or candidate-filter allocation.
- Publish only `Analysis\Evidence\Duplication\Contract\DuplicationInspectionInterface`. Its sole temporary exact application consumer is `Qualimetrix\Analysis\Pipeline\MetricEnricher` (`owner: Analysis.Run`, `closes_in: P3`). `Infrastructure.DependencyInjection` retains a permanent owner-wide consumer for composition wiring. The dedicated configurator may scan/register internals but no production declaration outside the module imports an internal Duplication FQCN.
- Do not add PHPDoc-only consumer semantics, transitive contract closure, a qmx seam or a generic phase port in P1. Removing the universal payload eliminates the earlier need to expose `DuplicateBlock`/`DuplicateLocation` and keeps the existing manifest observation model intact.

#### Exact production move map

| Current declaration/file                                    | Target file                                                                     | Target visibility |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------- | ----------------- |
| `src/Analysis/Duplication/ContentHintExtractor.php`         | `src/Analysis/Evidence/Duplication/ContentHintExtractor.php`                    | internal          |
| `src/Analysis/Duplication/DataDeclarationTagger.php`        | `src/Analysis/Evidence/Duplication/DataDeclarationTagger.php`                   | internal          |
| `src/Analysis/Duplication/DuplicateBlockFinder.php`         | `src/Analysis/Evidence/Duplication/DuplicateBlockFinder.php`                    | internal          |
| `src/Analysis/Duplication/DuplicateSearchRequest.php`       | `src/Analysis/Evidence/Duplication/DuplicateSearchRequest.php`                  | internal          |
| `src/Analysis/Duplication/DuplicationDetector.php`          | `src/Analysis/Evidence/Duplication/DuplicationDetector.php`                     | internal          |
| `src/Analysis/Duplication/DuplicationDetectorInterface.php` | `src/Analysis/Evidence/Duplication/Contract/DuplicationInspectionInterface.php` | contract          |
| `src/Analysis/Duplication/HashIndexBuildResult.php`         | `src/Analysis/Evidence/Duplication/HashIndexBuildResult.php`                    | internal          |
| `src/Analysis/Duplication/HashIndexBuilder.php`             | `src/Analysis/Evidence/Duplication/HashIndexBuilder.php`                        | internal          |
| `src/Analysis/Duplication/NormalizedToken.php`              | `src/Analysis/Evidence/Duplication/NormalizedToken.php`                         | internal          |
| `src/Analysis/Duplication/PackedPosition.php`               | `src/Analysis/Evidence/Duplication/PackedPosition.php`                          | internal          |
| `src/Analysis/Duplication/RetokenizedFiles.php`             | `src/Analysis/Evidence/Duplication/RetokenizedFiles.php`                        | internal          |
| `src/Analysis/Duplication/SaturatingCandidateFilter.php`    | `src/Analysis/Evidence/Duplication/SaturatingCandidateFilter.php`               | internal          |
| `src/Analysis/Duplication/TokenNormalizer.php`              | `src/Analysis/Evidence/Duplication/TokenNormalizer.php`                         | internal          |
| `src/Core/Duplication/DuplicateBlock.php`                   | `src/Analysis/Evidence/Duplication/DuplicateBlock.php`                          | internal          |
| `src/Core/Duplication/DuplicateLocation.php`                | `src/Analysis/Evidence/Duplication/DuplicateLocation.php`                       | internal          |
| `src/Rules/Duplication/CodeDuplicationOptions.php`          | `src/Analysis/Evidence/Duplication/CodeDuplicationOptions.php`                  | internal          |
| `src/Rules/Duplication/CodeDuplicationRule.php`             | `src/Analysis/Evidence/Duplication/CodeDuplicationRule.php`                     | internal          |
| new                                                         | `src/Analysis/Evidence/Duplication/DuplicationResultProvider.php`               | internal          |

The four Run/Finding integration files are exact and retain their later owners:

| File                                         | P1 responsibility                                                                             |
| -------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `src/Analysis/Pipeline/MetricEnricher.php`   | Reset the inspection contract every run; invoke inspection only when the producer is enabled. |
| `src/Analysis/Pipeline/EnrichmentResult.php` | Remove the Duplication payload.                                                               |
| `src/Analysis/Pipeline/AnalysisPipeline.php` | Stop projecting Duplication state into rule execution.                                        |
| `src/Core/Rule/AnalysisContext.php`          | Remove the universal `duplicateBlocks` field/constructor argument.                            |

The exact DI/composition, production discovery-comment and runtime-port integration slice is:

| File                                                                              | P1 responsibility                                                                                                     |
| --------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php`    | Remove concrete/internal Duplication registration and inject only the contract into Run.                              |
| `src/Infrastructure/DependencyInjection/Configurator/DuplicationConfigurator.php` | New composition adapter: scan detector/provider/rule, alias the contract and preserve `qmx.rule` autoconfiguration.   |
| `src/Infrastructure/DependencyInjection/Configurator/RuleConfigurator.php`        | Update comments that otherwise imply every non-Architecture rule lives under `src/Rules/**`.                          |
| `src/Infrastructure/DependencyInjection/ContainerFactory.php`                     | Invoke the new configurator in deterministic configuration order.                                                     |
| `src/Configuration/ConfigurationProviderInterface.php`                            | Record the real one-consumer CBO fan-in increase at the stable runtime configuration port without hiding the DI edge. |
| `src/Configuration/RuleThresholdKeyGroupRegistry.php`                             | Update comments that otherwise imply every Options class lives under `src/Rules/**`; runtime keys stay unchanged.     |
| `tests/Integration/DependencyInjection/ContainerFactoryTest.php`                  | Prove detector alias, provider injection, rule registration and channel/registry visibility.                          |

#### Exact test and discovery scope

The eight migration-owned tests move to `tests/Analysis/Evidence/Duplication/Unit/`; their generated current total is exactly 75 discovered test cases:

| Current test                                                            | Target test                                                                      | Cases |
| ----------------------------------------------------------------------- | -------------------------------------------------------------------------------- | ----: |
| `tests/Unit/Analysis/Duplication/ContentHintExtractorTest.php`          | `tests/Analysis/Evidence/Duplication/Unit/ContentHintExtractorTest.php`          | 14    |
| `tests/Unit/Analysis/Duplication/DataDeclarationTaggerTest.php`         | `tests/Analysis/Evidence/Duplication/Unit/DataDeclarationTaggerTest.php`         | 15    |
| `tests/Unit/Analysis/Duplication/DuplicationDetectorTest.php`           | `tests/Analysis/Evidence/Duplication/Unit/DuplicationDetectorTest.php`           | 16    |
| `tests/Unit/Analysis/Duplication/DuplicationMemoryLimitProcessTest.php` | `tests/Analysis/Evidence/Duplication/Unit/DuplicationMemoryLimitProcessTest.php` | 2     |
| `tests/Unit/Analysis/Duplication/SaturatingCandidateFilterTest.php`     | `tests/Analysis/Evidence/Duplication/Unit/SaturatingCandidateFilterTest.php`     | 2     |
| `tests/Unit/Analysis/Duplication/TokenNormalizerTest.php`               | `tests/Analysis/Evidence/Duplication/Unit/TokenNormalizerTest.php`               | 10    |
| `tests/Unit/Core/Duplication/DuplicateBlockIdentityTest.php`            | `tests/Analysis/Evidence/Duplication/Unit/DuplicateBlockIdentityTest.php`        | 2     |
| `tests/Unit/Rules/Duplication/CodeDuplicationRuleTest.php`              | `tests/Analysis/Evidence/Duplication/Unit/CodeDuplicationRuleTest.php`           | 14    |

The process memory test must stop deriving the repository root from its legacy directory depth. Its target regression resolves the repository root stably, proves `vendor/autoload.php` and `bin/qmx` exist there, and keeps both the 24 MB candidate-index probe and full CLI duplicate-detection probe green.

The following integration/discovery files stay in place and appear only once in the executable scope:

| File                                                                                                | Guard changed by P1                                                           |
| --------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php`                                             | No Duplication payload reaches `AnalysisContext`.                             |
| `tests/Unit/Analysis/Pipeline/MetricEnricherTest.php`                                               | Reset/replace lifecycle, enabled inspection and disabled zero-work path.      |
| `tests/Integration/Violation/ChannelCoverageTest.php`                                               | Moved rule still declares and emits the same channel.                         |
| `tests/Unit/Rules/ThresholdOverrideIntegrationTest.php`                                             | Moved options retain override behaviour; also a rule/options discovery guard. |
| `tests/Integration/Documentation/DocumentationConsistencyTest.php`                                  | Rule discovery includes capability-owned rules outside `src/Rules/**`.        |
| `tests/Unit/Configuration/RuleThresholdKeyGroupRegistryDriftTest.php`                               | Threshold call-site discovery includes the moved rule/options.                |
| `tests/Unit/Rules/ThresholdValidatorAssignmentTest.php`                                             | Threshold-aware Options discovery includes the moved class.                   |
| `tests/Unit/Infrastructure/DependencyInjection/CompilerPass/ChannelDeclarationCompilerPassTest.php` | Its production-rule discovery contract/comments name all current rule roots.  |

`phpunit.xml.dist` adds the exact target Unit directory. The discovery gate compares the before/after `--list-tests` inventory: all 75 existing migrated test IDs remain in the Unit suite exactly once and the old eight paths disappear. P1 adds exactly two lifecycle regressions in `MetricEnricherTest`: `itClearsDuplicationResultsWhenTheNextRunDisablesTheRule` (enabled -> disabled) and `itReplacesDuplicationResultsWhenTheNextEnabledRunFindsNoMatches` (enabled -> no match). Live discovery disproved the earlier `7,200 + 2` estimate because P1-A also adds `itEncodesThePostP1DuplicationBoundary`, `itClassifiesLegacyAndTargetDuplicationTestsWithoutACatchAll` and `itClassifiesTheP1DuplicationModuleReadmeExactly`, while P1-D adds `itWiresTheDuplicationCapabilityThroughItsContractAndRegistries`. The complete six-case delta is those four integration/governance cases plus the two lifecycle cases, for 7,206 full-project cases; any further delta blocks P1.

#### Exact documentation and governance scope

Documentation reviewed/updated atomically with the landed current state:

| File                                                                        | Required update                                                                                                                       |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Analysis/Evidence/Duplication/README.md`                               | New leaf README: subject, one contract, provider lifecycle, dependencies, owned tests, registration and P3 closure.                   |
| `src/Analysis/README.md`                                                    | Remove the legacy Duplication subtree and universal payload description.                                                              |
| `src/Core/README.md`                                                        | Remove `Core/Duplication`.                                                                                                            |
| `src/Rules/README.md`                                                       | Point Duplication rule/options to their capability owner.                                                                             |
| `src/Infrastructure/README.md`                                              | Add `DuplicationConfigurator` and its exact registration responsibility.                                                              |
| `website/docs/rules/duplication.md`, `website/docs/rules/duplication.ru.md` | Preserve user behaviour while aligning implementation notes/owned location.                                                           |
| `docs/ARCHITECTURE.md`, `AGENTS.md`                                         | Mark P1's physical leaf and result isolation as current without claiming P2+.                                                         |
| `docs/adr/0022-capability-oriented-modular-monolith.md`                     | Record P1 as landed evidence; keep P3 ports non-binding.                                                                              |
| `CHANGELOG.md`                                                              | Add the complete Breaking migration mapping, including FQCN moves, removed universal constructor fields and detector contract change. |
| `docs/internal/plans/modular-architecture.md`                               | Mark P1 complete only after package E's final evidence/review.                                                                        |

Governance edits/outputs are exact: `docs/internal/modular-architecture-manifest.json`; `scripts/generate-modular-architecture-production-inventory.php`; `scripts/generate-modular-architecture-test-inventory.php`; `phpunit.xml.dist`; `qmx.yaml`; `qmx-baseline.json`; all 12 files under `docs/internal/generated/modular-architecture/`; and `tests/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php`. The schema and coordinator script are reviewed but unchanged unless their current contracts actually fail. The manifest removes the P1 internal concrete-detector grant, publishes only the inspection contract, records its one temporary exact Run consumer plus permanent Infrastructure consumer, and keeps all other Duplication declarations internal.

Baseline reconciliation is evidence-driven: generate a current candidate and compare it structurally with the pre-P1 ratchet. Re-key the moved `DataDeclarationTagger` FQCN/path/offset entry only if its WMC facts and magnitude/count are unchanged. Classify the existing `MetricEnricher` entry separately as unchanged, identity-re-keyed, resolved by the P1 refactor, or a regression; only the first three outcomes may land, with evidence for the chosen classification. The `ConfigurationProviderInterface` CBO change from 21 to 22 is real and legitimate: `DuplicationConfigurator` adds one explicit consumer to this stable runtime configuration port. Preserve that DI edge, remove the old CBO 21 baseline row, and use the source-level inclusive threshold 23 with a reason; current fan-in 22 passes with no headroom, while the next consumer at 23 fails. Do not accept new debt, bulk-regenerate unrelated entries or require a preset delta count. Cache/serialization compatibility is evidence, not assumed work: the inventory must remain empty for Duplication types in cache, parallel collection and serializer payloads; the P1 move therefore changes no cache key or wire schema.

#### P1 execution packages

```text
P1-A governance intent
  -> P1-B module move
  -> {P1-C Run/Finding isolation || P1-D DI/discovery wiring}
  -> P1-E generated/docs/baseline/validation/review closure
```

- **P1-A — governance intent — Completed:** the only writer of `docs/internal/modular-architecture-manifest.json`, the production/test inventory generator inputs and `ModularArchitectureGovernanceIntegrationTest`. It establishes the reviewed declaration/visibility/consumer/grant policy but does not write generated artifacts or claim a green intermediate generator before B/C/D land.
- **P1-B — module move — Completed:** the 17 moves, new provider, eight owned tests and module README.
- **P1-C — Run/Finding isolation — Completed:** the four Run/Finding production files and the four downstream tests (`AnalysisPipelineTest`, `MetricEnricherTest`, `ChannelCoverageTest`, `ThresholdOverrideIntegrationTest`).
- **P1-D — DI/discovery wiring — Completed:** the seven exact DI/composition, production discovery-comment and runtime-port integration files, including the justified inclusive CBO threshold 23 on `ConfigurationProviderInterface`; `src/Infrastructure/README.md`; the three remaining named discovery guards; and `ChannelDeclarationCompilerPassTest`. It does not edit any B/C path.
- **P1-E — serial integration — Completed:** the only writer of all 12 generated artifacts, `qmx.yaml`, `phpunit.xml.dist`, evidence-driven `qmx-baseline.json` reconciliation (including removal of the old `ConfigurationProviderInterface` CBO 21 row) and the remaining shared documentation/CHANGELOG; it also owns full validation and required review. It runs only after B, C and D all complete and their diffs are independently verified.

B starts only after A. C and D are file-disjoint and may execute in parallel only after B; E starts after both and is the sole generated/qmx/PHPUnit/baseline/remaining-shared-docs writer. No agent uses git operations or runs dependency-mutating commands in the shared tree.

DoD: all 75 existing migrated IDs run exactly once; the six named P1 additions are the two lifecycle, three governance boundary/classification and one DI wiring regressions; and the final full-project count is 7,206. The memory-limit process tests resolve the new root and pass; the dedicated configurator registers the detector, provider and rule, and channel/rule/options registries discover the moved classes. Two runs on one container prove no stale provider state (enabled then disabled, and enabled then no matches). The disabled path performs exactly one O(1) provider reset and zero inspection calls, tokenisation, hashing, duplicate-block creation or candidate-filter allocation. `EnrichmentResult`, `AnalysisContext` and `AnalysisPipeline` have no `duplicateBlocks` field/argument/transport. Exactly one temporary contract consumer exists (`MetricEnricher -> DuplicationInspectionInterface`, `closes_in: P3`), Infrastructure is its permanent composition consumer, and no production declaration outside the module imports a Duplication internal. The internal grant closes, no new seam or taxonomy allow target appears, the generated DAG and every remaining seam pass, baseline reconciliation contains no accepted debt and records the evidence-backed `DataDeclarationTagger` and `MetricEnricher` classifications, cache/wire inventory stays empty, Breaking migration notes are complete, and full PHPUnit, architecture check, selfcheck, full CS, P1-scoped PHPStan, docs build and focused process/registry/topology tests pass. Aggregate `composer check` remains blocked only by the unrelated pre-existing `LoggerFactory.php:72` PHPStan finding recorded in the completed status evidence.

### P2 — Extract Analysis\Evidence\DependencyModel and Reporting graph projections — Not started

**Status:** Not started. P1 is complete, so P2 is the next executable package; its own design and review gates below still apply.

Files: current `Core/Dependency/**`; `Analysis/Collection/Dependency/**` except the cycle detector/result files assigned by P0 to CircularDependency; dependency graph exporters; graph-export command/adapters; affected Analysis/Coupling imports; DI; docs/tests/qmx.

- Separate graph facts/query contract from AST collection adapters and graph algorithms.
- Apply the P2 manifest rows and named contract consumers, regenerate qmx/inventories, and remove the `GlobalContextCollectorInterface` singleton seam only after returning it to `Analysis.Evidence.Measurement` leaves the projected graph acyclic. Close exact grants independently when their observed import pairs disappear.
- Give graph facts, construction and query a coherent DependencyModel owner; keep AST traversal integration as a Run/Infrastructure adapter according to dependency direction.
- Move DOT/JSON exporters and their format-facing contract to a Reporting-owned graph-projection subdomain. They consume only `DependencyModel\Contract`; the CLI command remains an Infrastructure adapter.
- Exclude cycle detection, cycle presentation and the circular-dependency rule from DependencyModel unless P0's class/co-change inventory disproves their independent subject.
- Migrate current Analysis and Coupling consumers to the public graph contract; Architecture and CircularDependency consume it in P4.

DoD: no feature imports graph implementation; DependencyModel has no feature or Reporting dependency; graph exporters import only `dependency-model-contract`; graph construction, DOT/JSON output and command behaviour retain direct regressions; no taxonomy namespace is an allow target.

### P3 — Move the complete Run/Configuration kernel and introduce phase contracts

Files: current `src/Analysis/{Aggregator,Collection,Discovery,Exception,Lifecycle,Namespace_,Pipeline,Repository,RuleExecution}/**` after the P1/P2 extractions; target `src/Analysis/Run/**`; the P0-classified neutral subset of current `src/Configuration/**`; target `src/Analysis/Configuration/**`; DI compiler/configurator files; focused tests and READMEs. P0 materialises the exact file list and assigns every remaining feature-specific Configuration file to P4–P7 before this package is executable.

- Move every remaining Analysis orchestration class under `Analysis\Run`; do not leave production types directly in the `Analysis` taxonomy.
- Apply the P3 manifest rows, regenerate qmx/inventories, require P1's one temporary `MetricEnricher -> DuplicationInspectionInterface` consumer to disappear rather than become permanent or be inferred from remaining imports, and remove the `DeferredWarning` singleton seam only after returning it to `Analysis.Configuration` leaves the projected graph acyclic.
- Extract the three phase-specific ports above and deterministic participant composites.
- Replace feature-specific orchestration in `AnalysisPipeline`/`MetricEnricher` with these ports while adapters still delegate to existing services.
- Execute participants in stable id order and reject duplicate ids. Implement only the independence or typed earlier-phase dependencies proven by P0; do not add a generic participant dependency graph.
- Keep observable pipeline order and skip-disabled-feature behaviour identical.
- Adapt the already-isolated Duplication inspector to the accepted Run-owned `FileSetInspectionParticipantInterface`, replace `MetricEnricher`'s direct capability call, and close the single P1 temporary consumer. P3 does not recreate or relocate the provider, rule injection or universal-context removal completed in P1.
- Move neutral configuration source/schema/merge mechanics to `Analysis\Configuration`; leave no production types directly in the `Analysis` taxonomy.

DoD and named gate — **P3 phase-port contract gate**: no production PHP file remains directly under the `Analysis` taxonomy or an unassigned legacy Analysis subdirectory; `AnalysisPipeline` and `MetricEnricher` import no Duplication implementation or capability-owned inspection contract; binding interface contract tests prove stable order, every P0-recorded dependency, reset between two runs, disabled-step skip and duplicate-id failure; P1's single temporary consumer closes; every current Configuration class is either moved to the neutral owner or assigned to a named later package. P4+ is blocked until this gate passes and the accepted signatures are reflected in the manifest and ADR.

### P4 — Isolate Architecture policy and CircularDependency evidence

Files: current `src/Architecture/**`; cycle detector/result files assigned by P0; architecture DI configurator; related commands/adapters; central configuration/pipeline seams; qmx rules; focused tests/READMEs.

Entry gate: the P3 phase-port contract gate is green; P4 consumes only those accepted interfaces.

- Keep declared layer configuration, expansion, membership and layer-violation evaluation under `Analysis\Policy\Architecture`.
- Apply the P4 manifest rows and explicit consumers, regenerate qmx/inventories, and remove the `ArchitectureLifecycleHook` and `ConfigLoadException` singleton seams only after returning each declaration to its true owner leaves the projected graph acyclic.
- Move cycle detection, its result model/options/rule and prepared state under `Analysis\Evidence\CircularDependency`; consume only `DependencyModel\Contract`.
- Define the minimum Architecture contract required by debug adapters; Run sees the capability only through its own phase ports. Define a CircularDependency contract only if a named consumer remains after removing its universal-context payload.
- Have both capabilities implement the appropriate Analysis\Run-owned graph-preparation port while retaining their own state.
- Remove `ResolvedConfiguration::$architecture`, direct `ArchitectureConfigurationFactory` construction in central configuration, direct Architecture internals from Run, and cycles from `AnalysisContext`.
- Each capability parses its node from `ConfigurationDocumentInterface`; central Configuration stores neither feature object nor a heterogeneous resolved-object bag.

DoD: no `configuration -> architecture`, `run -> architecture-internal`, `run -> circular-dependency-internal`, or mutual allow; external imports target only proven contracts; layer-policy, circular-dependency and debug-command behaviour remains; two sequential runs prove both capability states reset independently.

### P5 — Extract Analysis\Evidence\ComputedMetrics and remove static state

Files: `Core/ComputedMetric/**`, computed/health config resolvers, metric/rule paths, health reporting paths, runtime configurator, its exact DI wiring, docs/tests/qmx.

- Co-locate definition parsing, validation, dependency ordering, evaluation, options and rules.
- Apply the P5 manifest rows, regenerate qmx/inventories, and remove the `ComputedMetricsConfigResolver`, `HealthReasonBuilder` and `MetricHintProvider` singleton seams only when returning each declaration to its true owner leaves the projected graph acyclic.
- Replace `ComputedMetricDefinitionHolder` with an instance-owned run definition catalog.
- Implement MetricDerivation participant; the module parses its own configuration node.
- Keep Health as a named subdomain for this package; split only if inventory shows an independent lifecycle/public consumer set.
- Move health computation identified by P0 out of Reporting; pure health rendering remains in Reporting and consumes a narrow ComputedMetrics/Health read contract.

DoD: `Analysis\Configuration`, `Analysis\Run` and `RuntimeConfigurator` contain no computed-feature knowledge; two different configurations in one process cannot leak definitions; formula cycles/errors, exclude-health and all health formats retain direct regressions.

### P6 — Separate Finding and Policy capabilities; place orchestration and projections honestly

Files: `Baseline/**`, `Core/Suppression/**`, `Core/Violation/Filter/**`, suppression collection code, `Infrastructure/Console/ViolationFilter*`, git scope adapter, reporting seams, docs/tests/qmx.

- Use the P0 context map for source annotations, rule/config exclusion, baseline ceiling, Git projection and presentation; name producer, semantic owner, consumer and order for every stage.
- Apply the P6 manifest rows, regenerate qmx/inventories, and remove the `DeclarationBindings`, `SourceControls`, `RuleExecutor`, `RuleValidatorMapFactory`, `SuppressionFilter`, `RuleMatcher` and `Location` singleton seams only after returning each declaration to its true owner leaves the projected graph acyclic.
- Move neutral violation/rule/channel contracts to `Analysis\Finding`, source annotation ownership to `Analysis\Policy\Inline`, and retain `Analysis\Policy\Baseline` as its own capability.
- Put only cross-capability invocation order in `Analysis\Run`; do not create a `FindingEvaluation` module or shared policy-state holder.
- Move Git-scoped and other output-only projections to Reporting. Keep the Git client as an Infrastructure adapter behind a Reporting-owned port.
- Move framework-free orchestration out of Console; Console only parses options and renders diagnostics.
- Keep Baseline as a peer policy capability unless the P0 ownership map disproves its independent lifecycle; do not force all policy into one implementation module.

DoD: invocation/projection order has one authoritative contract test; each result/state type has one owner; Run orchestration holds no feature state; Reporting projection has no Git concrete import; Baseline remains fail-safe and scope semantics/golden output are unchanged.

### P7 — Classify and migrate thin evidence capabilities in vertical batches

Files per batch are disjoint by proposed check owner: implementation + rule + config/defaults + tests + docs. Shared DI/qmx files are a final serial integration package, not concurrently edited.

1. Refresh P0's exhaustive ownership table and add a 6–12 month focused co-change matrix excluding mass commits.
2. Keep the reviewed dispositions unless new evidence triggers a plan/ADR amendment: Halstead moves with Maintainability, WMC with Complexity, and legacy Structure declarations split among Cohesion, Size and Design exactly as enumerated in the generated inventory.
3. For each accepted capability, update its P7 manifest rows and named consumers, regenerate qmx/inventories, then move metric collectors and rules together under `Analysis\Evidence\{Capability}`. Give it a `Contract` namespace only if another module actually consumes it.
4. Delete empty role buckets and shrink Metrics/Rules foundation; do not recreate identical internal role folders by template.

DoD per batch: one owner for every moved class; no cross-check internal import; external imports only through actual contracts; all rule names/options/output stay stable unless a documented breaking change is intentional; README gives an agent a bounded reading set.

### P8 — Remove grants and ratchet context locality

Files: `qmx.yaml`, architecture fixtures, module READMEs, scripts/CI, ADR completion note.

- Remove all exact temporary grants from the manifest and their derived coarse qmx edges.
- Remove every temporary exact contract-consumer entry; a surviving contract consumer is permanent only with an observed exact target import, `source_fqcn: null` and `closes_in: null`.
- Apply the P8 manifest rows, remove every now-unused grant/seam, and regenerate qmx/inventories until `--check` is clean.
- Consolidate the duplicated `LoggerFactory` ownership/test locations in P8 according to the generated rows; do not leave parallel legacy and target test owners.
- Treat the 28 generated orphan candidates as candidates only: prove absence of direct, dynamic, fixture-path and process consumers before deleting each row. Otherwise move it to its assigned owner and record the retained disposition.
- Add a report/test listing public imports and fan-in per module; treat growth as review evidence, not an automatic design score.
- Add a context-locality checklist to module READMEs: owned code/tests/docs, public dependencies, adapters, change recipes.
- Add a test-topology check: every module-owned test/support/fixture path maps to its subject, every permanent `System` scenario and `TestSupport` child has a recorded subject owner/justification, no file lives directly in the `TestSupport` taxonomy, and production code never imports test namespaces.
- Run complete validation and self-analysis; intentionally review any baseline change rather than regenerating it mechanically.

DoD: no temporary contract consumer, temporary internal grant or singleton seam remains; no mutual allow, taxonomy allow target, wildcard sibling access, uncovered production namespace, feature static holder, feature payload in universal contexts, unowned legacy test path or silently undiscovered moved test; LoggerFactory has one coherent owner/test location; every one of the 28 orphan candidates has recorded consumer proof and a move-or-delete disposition; `composer check`, selfcheck, docs build and direct module regressions pass.

## Package dependencies and execution

```text
[complete] P0-A -> P0-B -> P0-C -> P0-D -> P0 completion gate
[complete] P1-A -> P1-B -> {P1-C || P1-D} -> P1-E -> P1 completion gate -> [next, not started] P2 -> P3 (closes P1's MetricEnricher consumer) -> phase-port contract gate -> P4 -> P5 -> P6 -> P7 -> P8
```

P0 completed discovery, decision and enforcement; it does not authorise the unchanged remainder automatically. P0-A atomically established authority, generated qmx enforcement, reproducible evidence and freshness checks, including ownership of the qmx topology header immediately adjacent to its generated region. P0-B closed decision/general documentation, P0-C reconciled the baseline, and P0-D completed final review and shared-document dispositions. P1-A through P1-E and the final P1 re-review are complete; P2 is now next and has not started. Generated rows bearing an exact P1–P8 closure are the authoritative migration-owned move enumerations; the explicit package tables add integration, DI, discovery, governance and current-documentation edits without changing those files' later owners. Documentation rows marked `P0-D`, `permanent`, or `shared` do not grant later package ownership and still change when the landed current state would otherwise make them false. Phase-port signatures remain non-binding until P3's contract gate.

Packages P1–P6 then land sequentially because they share Run, DI, configuration and topology seams. P1's single temporary `MetricEnricher -> DuplicationInspectionInterface` relation creates the explicit lifecycle dependency on P3; P3 must replace that direct call with its accepted Run-owned port and remove the relation rather than silently make it permanent. DependencyModel lands before P3 binds Run's graph-aware phase ports, and the P3 gate passes before Architecture/CircularDependency consumes them. Migration-owned artifacts come from the generated rows, while each package's explicit integration tables name the additional consumers and guards it may edit without stealing their later ownership; moving production without its owned tests is an incomplete package. P7 batches may be delegated in parallel only after an execution plan enumerates exact non-overlapping production/tests/docs files; shared DI, `qmx.yaml`, `phpunit.xml.dist`, Composer dev-autoload/classmaps, root docs and generated inventories form a named serial integration package. No package described with `**` is eligible for parallel execution as-is.

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
- Git projection changes only the reported view and never the accepted-state/policy result;
- taxonomy directories contain no production types and cannot be dependency targets;
- a listed contract consumer without an observed exact target import fails; a temporary consumer cannot omit/mismatch `source_fqcn`, cannot disagree with its semantic `owner`, and cannot omit or outlive `closes_in`;
- a fourth declaration of an otherwise authorised temporary consumer owner cannot reuse its coarse qmx allow;
- an unlisted exact internal import fails even when one of the 16 coarse semantic-owner edges would otherwise permit it;
- every moved test remains present in the PHPUnit discovery manifest, with no duplicate old/new class;
- module-specific fixtures and support code move with their owner while neutral TestSupport consumers remain green;
- layer policy and circular-dependency evidence can be enabled, disabled and reset independently;
- dynamic rule discovery and constructor dependencies still work after moves;
- cache payload versioning is bumped or proven compatible for moved class names;
- public contract break produces CHANGELOG migration entry;
- taxonomy container cannot become qmx allow target.

## Explicit non-goals

- no generic marketplace/plugin API for third-party checks;
- no `Api/`/`Contract/` folder for private leaf code;
- no `FindingEvaluation` role bucket or policy/projection state shared through the Run kernel;
- no placement of circular-dependency detection under Architecture merely because its rule currently lives there;
- no numeric context-window hard gate;
- no mass migration based only on names or current directory categories;
- no preservation shim for obsolete internal namespaces.
