# P4 — Architecture policy and circular-dependency preparation

> **Status:** Completed. P4-A through P4-E implementation and serial publication are complete, all confirmed findings from both review rounds are fixed, the root-owned aggregate validation is green, and P5 is unblocked.
> **Prerequisites:** [Plan overview](../modular-architecture.md) and [decisions and target](decisions-and-target.md). This is the sole detailed P4 record for future P4 agents.
### P4 — Isolate Architecture policy and its circular-dependency preparation

**Status:** Completed. P4-A through P4-E implementation and serial publication
are complete, all confirmed findings from both review rounds are fixed, the
root-owned aggregate validation is green, and P5 is unblocked. The P3 entry gate was
freshly green on `57fa22fa`: `composer check` passed with 7,224 tests, 22,078
assertions and one skip; PHPStan, exact architecture governance and selfcheck
passed.

#### P4 ownership correction and subject-cohesion decision

The old P4 outline contradicted the authoritative P0/P3 evidence. Its prose put
circular-dependency preparation "under Architecture", while the manifest,
generated production inventory and generated test topology assign it to the
distinct owner `Analysis.Evidence.CircularDependency`. Fresh inspection confirms
the manifest decision:

| Test                     | Declared layer policy                                                     | Circular-dependency evidence                                                       |
| ------------------------ | ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Subject/name             | user-declared layer membership and allowed relations                      | strongly connected class-dependency components                                     |
| Configuration            | parses the `architecture:` document node; expands template layers         | no feature configuration node; rule options only affect finding selection/severity |
| Runtime input            | dependency graph plus the complete collected class universe               | dependency graph only                                                              |
| State/readers            | configured/prepared layer policy; layer rule and debug assignment adapter | prepared cycle set; circular-dependency rule only                                  |
| Independent change       | selectors, membership, coverage, relation policy and diagnostics          | SCC/path identity, display labels, size policy and cycle finding                   |
| Counterfactual ownership | would be duplicated by every declared-policy consumer if split            | would move intact with detector/result/rule and is not needed by layer policy      |

P4 therefore lands two sibling leaves, not a leaf plus a nested implementation
detail:

```text
Analysis/Policy/Architecture                 # declared layer policy
Analysis/Evidence/CircularDependency         # observed dependency-cycle evidence
```

`Analysis`, `Policy` and `Evidence` remain navigation-only taxonomies. Neither
is a qmx layer or allow target. The rule category and existing
`architecture.circular-dependency` public name remain unchanged; a shared CLI
category is not shared ownership. This corrects the earlier P4 bullet and does
not change ADR 0022's accepted leaf-capability direction.

#### Fresh finite P4 inventory

The current manifest has exactly **58 declarations with
`closure_package=P4`**: 52 declared-policy declarations and six circular
declarations. The following tables are the executable source inventory; the
old `src/Architecture/**` wording is only a scope summary.

| Current root                        | Exact declarations                                                                                                                                                                                                                                                                                                                                                                          | Count | P4 disposition                                                                                                                                                                                                                     |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----: | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Architecture/Configuration`    | `Allow/AllowAliasExpander`; `ArchitectureConfigurationFactory`; `ArchitectureFactoryResult`; `Validation/AllowValidator`; `Validation/CoverageValidator`; `Validation/ExactAllowCycleValidator`; `Validation/ExcludeBlockValidator`; `Validation/LayerCriterionNormalizer`; `Validation/LayersValidator`; `Validation/LongFormAllowEntryNormalizer`; `Validation/WildcardSelfAllowDetector` | 11    | move to `Analysis/Policy/Architecture/Configuration`; keep the subject-owned classes directly in that directory except for exact allow/capture syntax under `Configuration/Allow` -- the role bucket `Validation` does not survive |
| `src/Architecture/Domain`           | `ArchitectureConfiguration`; `CoverageMode`                                                                                                                                                                                                                                                                                                                                                 | 2     | move to `Analysis/Policy/Architecture/Configuration`                                                                                                                                                                               |
| `src/Architecture/Domain/Allow`     | `AllowListEntry`; `AllowTarget`; `CaptureBinding`; `InvalidSelectorException`; `LayerSelector`; `LayerSelectorParser`; `ParseCapturedState`; `SelectorKind`; `SelectorSegment`                                                                                                                                                                                                              | 9     | move to `Analysis/Policy/Architecture/Configuration/Allow`                                                                                                                                                                         |
| `src/Architecture/Domain/Layer`     | `CapturePattern`; `ClassContext`; `ClassContextFactory`; `ClassSet`; `CriterionListValidator`; `ExcludeSpec`; `InvalidLayerDefinitionException`; `LayerCriteriaMatcher`; `LayerDefinition`; `LayerMatch`; `LayerPolicy`; `LayerRegistry`; `MatchMode`; `MatchedCriterion`; `MatchedCriterionKind`; `MembershipResult`; `MembershipSpec`; `TemplateLayerDefinition`                          | 18    | move to `Analysis/Policy/Architecture/Layer`; internalise the five current Console/Run leaks behind the contracts below                                                                                                            |
| `src/Architecture/Processing`       | `ArchitectureLifecycleHook`; `ArchitectureProcessor`; `ArchitectureProcessorInterface`; `LayerExpansionException`; `LayerExpansionResult`; `LayerExpansionStage`; `LayerInstantiator`; `TupleExtractor`                                                                                                                                                                                     | 8     | delete the hook and old interface; rename the processor to internal `ArchitecturePolicy`; move expansion implementation under `Layer/Expansion`; replace the escaping exception with the contract exception below                  |
| `src/Architecture/Rules` policy set | `LayerViolationFinding`; `LayerViolationOptions`; `LayerViolationRule`; `OwnedLayerTargets`                                                                                                                                                                                                                                                                                                 | 4     | move together to `Analysis/Policy/Architecture/LayerViolation`                                                                                                                                                                     |
| circular set                        | `Analysis\Collection\Dependency\CircularDependencyDetector`; `Analysis\Collection\Dependency\Cycle`; `Analysis\Collection\Dependency\CycleMemberLabels`; `Core\Dependency\CycleInterface`; `Architecture\Rules\CircularDependencyOptions`; `Architecture\Rules\CircularDependencyRule`                                                                                                      | 6     | move detector/model/options/rule to `Analysis/Evidence/CircularDependency`; delete the unnecessary interface because producer and only reader now share an owner                                                                   |

The 52 declared-policy rows total 11 + 2 + 9 + 18 + 8 + 4. The six circular
rows bring the finite P4 input to 58. `ArchitectureLifecycleHook`,
`ArchitectureProcessorInterface`, `CycleInterface`, the generic
`AnalysisLifecycleHookInterface`, and Configuration's architecture-only
`DeferredWarning` are deletions, not compatibility aliases.

#### Accepted target topology

```text
src/Analysis/Policy/Architecture/
├── Contract/
│   ├── ArchitectureConfigurationException.php
│   ├── ArchitectureConfigurationWarning.php
│   ├── ArchitecturePolicyConfiguratorInterface.php
│   ├── ArchitecturePreparationException.php
│   ├── LayerAssignment.php
│   ├── LayerAssignmentInspectorInterface.php
│   ├── LayerAssignmentMatch.php
│   └── LayerPolicyPreparationInterface.php
├── Configuration/
│   ├── Allow/                    # exact allow/capture syntax and alias expansion
│   ├── AllowValidator.php         # configuration-owned validation, not a role directory
│   ├── CoverageValidator.php
│   ├── ExactAllowCycleValidator.php
│   ├── ExcludeBlockValidator.php
│   ├── LayerCriterionNormalizer.php
│   ├── LayersValidator.php
│   ├── LongFormAllowEntryNormalizer.php
│   ├── WildcardSelfAllowDetector.php
│   ├── ArchitectureConfiguration.php
│   ├── ArchitectureConfigurationFactory.php
│   ├── ArchitectureFactoryResult.php
│   └── CoverageMode.php
├── Layer/
│   ├── Expansion/                # expansion result/stage, instantiator and tuple extraction
│   └── ...                       # the exact 18 layer-model declarations above
├── LayerViolation/               # finding, options, rule and owned-target projection
├── ArchitecturePolicy.php        # instance-owned configured/prepared state
└── README.md

src/Analysis/Evidence/CircularDependency/
├── Contract/CircularDependencyPreparationInterface.php
├── CircularDependencyAnalysis.php    # instance-owned prepared result
├── CircularDependencyDetector.php
├── Cycle.php
├── CycleMemberLabels.php
├── CircularDependencyOptions.php
├── CircularDependencyRule.php
└── README.md
```

`Contract/` is present only because there are named external consumers. All
configuration model, layer model, expansion machinery, concrete policy state,
cycle model/result and both rule implementations remain internal. Adapters stay
under `Infrastructure`.

The target has an explicit machine-checked internal DAG, not merely two coarse
owner layers. The manifest-backed import inventory feeds a new
`ArchitectureInternalTopologyTest` with these only allowed Architecture zones:
`Contract -> neutral external contracts`; `Configuration/Allow -> Contract`;
`Layer -> Contract + Configuration/Allow`; `Configuration -> Contract +
Configuration/Allow + Layer`; `Layer/Expansion -> Contract + Configuration +
Configuration/Allow + Layer`;
`ArchitecturePolicy -> Contract + Configuration + Layer + Layer/Expansion`;
and `LayerViolation -> Contract + ArchitecturePolicy + Configuration + Layer`.
This ordering follows the materialized imports: allow syntax is the lower-level
language, the layer model consumes it, and Configuration constructs layer model
values. P4-A derives and records the exact symbol edges for all 52 moved
declarations before freezing this allow set; an edge outside the enumerated DAG
pauses the package instead of silently widening it. Imports within one named
zone are local; every other cross-zone edge is rejected from the exact
declaration inventory. CircularDependency has the smaller checked shape
`Contract -> neutral DependencyModel contract`, while its flat internal root may
depend on that contract and no external owner may import the internal root.
There is no `Validation`, `Service`, `Model`, or other role-defined target zone.

#### Directed configuration and runtime contracts

P4 adds the neutral Configuration-owned document boundary:

```php
interface ConfigurationDocumentInterface
{
    /** @return list<mixed> values in source-precedence order */
    public function contributions(string $topLevelKey): array;
}
```

`Analysis\Configuration\ConfigurationDocument` is the immutable implementation
over the ordered normalized source documents, not only the final merged map.
`ConfigurationLayer` therefore retains its ordered source documents;
single-source stages contribute one document, while `PresetStage` retains each
selected preset document in preset order instead of irreversibly collapsing the
Architecture node. `ConfigurationPipeline` flattens those source documents in
stage priority order and constructs the document before producing the remaining
transitional fields. `TransitionalResolvedConfiguration` carries that interface
as `$document`; it does not carry `ArchitectureConfiguration`, a feature
registry, a heterogeneous resolved-object map, or architecture warnings. The
existing neutral/runtime/rule/computed transitional fields remain for their own
P5-P7 closures.

Architecture owns the merge of its contributed nodes: later `layers` replaces
the ordered list wholesale, `allow` merges by source while each source target
list is replaced, and scalar `coverage` is replaced. The existing six
Architecture-focused `ConfigurationMergerTest` cases move with this behavior to
`ArchitectureConfigurationFactoryTest`; preset -> preset and preset -> project
regressions are retained. `ConfigurationMerger::mergeArchitecture()` and its
`ConfigSchema::ARCHITECTURE` dispatch are deleted. Configuration retains only
the neutral root-key transport facts that `architecture` is an allowed,
associative, preserve-subtree document node; it knows no Architecture subkeys
or merge rules. No generic merge-policy registry or feature callback is added.

Architecture owns these exact promises:

```php
interface ArchitecturePolicyConfiguratorInterface
{
    /** @return list<ArchitectureConfigurationWarning> */
    public function configure(ConfigurationDocumentInterface $document): array;
}

interface LayerPolicyPreparationInterface
{
    public const string PRODUCER_RULE_NAME = 'architecture.layer-violation';

    /** @param iterable<SymbolPath> $classUniverse */
    public function prepare(
        DependencyGraphInterface $graph,
        iterable $classUniverse,
        bool $enabled,
    ): void;
}

interface LayerAssignmentInspectorInterface
{
    /** @param iterable<SymbolPath> $classUniverse */
    public function inspect(
        DependencyGraphInterface $graph,
        iterable $classUniverse,
        SymbolPath $subject,
    ): LayerAssignment;
}
```

`ArchitecturePolicy::configure()` first clears all prior configured/prepared
state, reads `$document->contributions('architecture')`, applies the
Architecture-owned merge semantics in source order, and validates the resulting
node. An absent contribution list is the empty/default Architecture
configuration. It then installs only the newly parsed state and returns warning
values. `ArchitectureConfigurationWarning` contains only the
warning message and PSR-3 context because the sole production warning is the
wildcard-self-allow warning and its level is always `warning`. Infrastructure
logs those values immediately after logger setup. Artificial transport of
arbitrary log levels is removed with `DeferredWarning`.

All architecture syntax failures become
`ArchitectureConfigurationException`; expansion/preparation failures become
`ArchitecturePreparationException`. Check, baseline and debug adapters catch
the Architecture-owned exceptions alongside Configuration's loader error and
preserve the current user-facing messages and exit codes. Architecture no
longer imports `ConfigLoadException` or `DeferredWarning`, so the direction is
`Architecture -> Configuration.Contract.ConfigurationDocument`, never
`Configuration -> Architecture`.

Every `LayerPolicyPreparationInterface::prepare()` call first clears prior
prepared graph/class state. With `$enabled=false` it performs zero class-context
construction, graph binding or template expansion and leaves the rule with no
prepared result. With `$enabled=true` it builds its private `ClassSet` and
`ClassContextFactory`, expands templates and binds the current graph. Run uses
the contract constant for producer selection and never imports the rule class.

`LayerAssignment` is the immutable adapter projection:

```php
final readonly class LayerAssignment
{
    /** @param list<LayerAssignmentMatch> $matches */
    public function __construct(public array $matches, public bool $hasLayers);
}

final readonly class LayerAssignmentMatch
{
    /** @param non-empty-list<string> $criteria */
    public function __construct(public string $layerName, public array $criteria);
}
```

The criterion strings are produced inside Architecture from the internal
`MatchedCriterion::describe()` semantics. `LayerAssignmentResolver` retains
discovery, collection and graph building, but imports only the inspector and
projection contracts; it no longer constructs `ClassSet`/`ClassContextFactory`
or reads a prepared registry.

CircularDependency publishes one exact Run-consumed promise:

```php
interface CircularDependencyPreparationInterface
{
    public const string PRODUCER_RULE_NAME = 'architecture.circular-dependency';

    public function prepare(DependencyGraphInterface $graph, bool $enabled): void;
}
```

`CircularDependencyAnalysis::prepare()` clears its previous result on every
call; disabled preparation performs zero SCC traversal and leaves an empty
result. Enabled preparation stores the detector's canonical `list<Cycle>`.
`CircularDependencyRule` reads that internal service directly. No cycle type or
result crosses the leaf boundary, so `CycleInterface`,
`TransitionalEnrichmentResult::$cycles` and `AnalysisContext::$cycles` are
deleted. `TransitionalMetricEnricher` invokes the preparation contract at the
existing phase position between computed evaluation and file-set inspection,
preserving profiler span `cycles`, rule selection and phase order without
returning a feature payload.

Neither contract is a generic Run participant. There is no runtime list,
service locator, feature registry, generic lifecycle hook, graph-preparation
participant or universal result bag.

#### Exact consumer and seam closure matrix

| Current consumer/import                                                                                                          | P4 replacement                                                                                                                         |
| -------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `ConfigurationPipeline -> ArchitectureConfigurationFactory`                                                                      | delete; Pipeline publishes `ConfigurationDocumentInterface` only                                                                       |
| `ConfigurationMerger -> ConfigSchema::ARCHITECTURE + mergeArchitecture()`                                                        | delete the subject-specific dispatch; Architecture merges ordered document contributions                                               |
| `PresetStage -> ConfigurationMerger` for multiple presets                                                                        | retain generic-field merging while `ConfigurationLayer` preserves each normalized source document for capability-owned merge semantics |
| `TransitionalResolvedConfiguration -> ArchitectureConfiguration`                                                                 | delete `$architecture`; add neutral `$document`                                                                                        |
| eleven Architecture factory/validator imports of Configuration `ConfigLoadException`/`DeferredWarning`                           | Architecture-owned exception/warning values                                                                                            |
| `ArchitectureLifecycleHook -> AnalysisLifecycleHookInterface + TransitionalResolvedConfiguration`                                | delete both generic hook declarations; explicit Infrastructure call to `ArchitecturePolicyConfiguratorInterface`                       |
| `AnalysisPipeline -> ClassContextFactory, ClassSet, ArchitectureProcessorInterface, LayerViolationRule`                          | `LayerPolicyPreparationInterface` only                                                                                                 |
| `TransitionalMetricEnricher -> CircularDependencyDetector + CircularDependencyRule`                                              | `CircularDependencyPreparationInterface` only                                                                                          |
| `TransitionalEnrichmentResult -> Cycle` and `AnalysisContext -> CycleInterface`                                                  | delete both payloads; rule reads internal `CircularDependencyAnalysis`                                                                 |
| `LayerAssignmentResolver/Command -> ClassSet, ClassContextFactory, ArchitectureProcessorInterface, LayerMatch, MatchedCriterion` | inspector plus immutable assignment projection contracts                                                                               |
| `CheckCommand -> LayerExpansionException`                                                                                        | `ArchitecturePreparationException`                                                                                                     |
| Architecture DI temporary construction grant                                                                                     | exact registrations inside `ArchitectureConfigurator`; no grant after target ownership is published                                    |
| `ConfigurationConfigurator -> ArchitectureConfigurationFactory`                                                                  | delete the cross-owner registration; `ArchitectureConfigurator` registers the concrete policy and its three Architecture contracts     |
| `AnalysisConfigurator -> ArchitectureProcessorInterface`                                                                         | inject `LayerPolicyPreparationInterface` into `AnalysisPipeline`                                                                       |
| `ContainerFactory -> AnalysisLifecycleHookInterface`                                                                             | delete lifecycle autoconfiguration; composition calls the named Architecture configurator explicitly                                   |

P4 removes exactly the three manifest seams
`seam-config-load-exception`, `seam-deferred-warning`, and
`seam-architecture-lifecycle-hook`, plus the exact temporary grant
`ArchitectureConfigurator -> ArchitectureProcessor`. It replaces every broad
or `source_fqcn:null` P4-era consumer with the materialized exact source FQCN
and target contract. No permanent consumer is approved from owner-level fan-in.

#### Exact test, fixture and support topology

Fresh generated discovery records **40 P4 PHPUnit classes / 778 IDs**, **52
fixtures**, and **four support files**. P4 moves them subject-first and compares
the expanded discovery manifest before and after the move.

Declared-policy test classes (34):

- Integration: `CaptureBindingIntegrationTest` (3),
  `FailClosedModularTopologyIntegrationTest` (6),
  `LayerCriteriaIntegrationTest` (4), `LayerExcludeIntegrationTest` (4),
  `LayerTemplateExpansionIntegrationTest` (5),
  `LayerViolationIntegrationTest` (5),
  `ModularArchitectureGovernanceIntegrationTest` (21),
  `Phase1ConfigCompatibilityTest` (2), and
  `RelationsFilterIntegrationTest` (7).
- Configuration: `AllowAliasExpanderTest` (34),
  `ArchitectureConfigurationFactoryTest` (21), `AllowValidatorTest` (54),
  `CoverageValidatorTest` (9), `ExactAllowCycleValidatorTest` (4),
  `LayersValidatorTest` (101), and `WildcardSelfAllowDetectorTest` (11).
- Domain/layer: `LayerSelectorTest` (42),
  `ArchitectureConfigurationTest` (3), `CoverageModeTest` (10),
  `CapturePatternTest` (27), `ClassContextFactoryTest` (11), `ClassSetTest` (3),
  `ExcludeSpecTest` (20), `LayerDefinitionTest` (80), `LayerPolicyTest` (29),
  `LayerRegistryTest` (29), and `TemplateLayerDefinitionTest` (13).
- Preparation/rule: `ArchitectureProcessorTest` (14),
  `LayerExpansionStageTest` (21), `LayerInstantiatorTest` (5),
  `TupleExtractorTest` (14), `CoverageDiagnosticsTest` (11),
  `LayerViolationOptionsTest` (17), and `LayerViolationRuleTest` (40).

Their target root is
`tests/Analysis/Policy/Architecture/{Unit,Integration}`. Rename
`ArchitectureProcessorTest` with the implementation; preserve every existing
ID, and add direct contract tests for disabled zero-work preparation,
configuration replacement and the immutable debug projection.

Circular test classes (5) move to
`tests/Analysis/Evidence/CircularDependency/Unit`: `CircularDependencyRuleTest`
(21), `CircularDependencyDetectorTest` (9), `CycleIdentityStabilityTest` (11),
`CycleMemberLabelsTest` (20), and `CycleTest` (22). Replace context-fixture
injection with the internal prepared-result fixture and add enabled/disabled
replacement tests. `LayerAssignmentCommandTest` (15) moves independently to
the already-recursive `tests/Infrastructure/Console/Functional` root.

The exact four supports are:

- `tests/Architecture/Support/AllowListBuilder.php` ->
  `tests/Analysis/Policy/Architecture/Support/AllowListBuilder.php`;
- `tests/Architecture/Support/ArchitectureViolationProjector.php` ->
  `tests/Analysis/Policy/Architecture/Support/ArchitectureViolationProjector.php`;
- `tests/Architecture/Support/ProcessorBuilder.php` -> the renamed policy
  support under `tests/Analysis/Policy/Architecture/Support`;
- `tests/Support/Dependency/AdjacencyGraphBuilder.php` ->
  `tests/Analysis/Evidence/CircularDependency/Support/AdjacencyGraphBuilder.php`.

The 52 policy fixtures move from `tests/Architecture/Fixtures` to
`tests/Analysis/Policy/Architecture/Fixtures` as these exact families/counts:
`CaptureBindingSample` (4), `CriteriaSample` (10), `ExcludeSample` (7),
`ModularTopologySample` (13), `RelationsSample` (6), `Sample` (7, including
the three JSON golden files), and `TemplateSample` (5). The two
`ModularTopologySample/Cycle` files remain policy-governance fixtures rather
than circular-detector fixtures. `InlineSuppressionLayerViolationIntegrationTest`
and all `tests/Architecture/Fixtures/IgnoreSample` files are P6-owned and must
not move in P4.

Direct non-owned consumers that P4 rewrites and re-proves are:

- `tests/Analysis/Run/Unit/Pipeline/AnalysisPipelineTest.php` (22),
  `MetricEnricherTest.php` (6),
  `tests/Analysis/Run/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`
  (5), and `tests/Analysis/Run/Support/Pipeline/TestPipelineBuilder.php`;
- `tests/Analysis/Configuration/Integration/DeferredWarningIntegrationTest.php`
  (4), renamed/replaced by Configuration-document boundary coverage;
- `tests/Analysis/Configuration/Unit/Pipeline/ConfigurationPipelineTest.php`;
- `tests/Unit/Infrastructure/Console/RuntimeConfiguratorTest.php` (20),
  `tests/Integration/DependencyInjection/ContainerFactoryTest.php` (22),
  `tests/Integration/Violation/ChannelCoverageTest.php` (12), and
  `tests/Unit/Core/Rule/AnalysisContextTest.php` (9).

New sequential-run integration coverage uses one container and two different
documents/graphs. It proves that the second run cannot see the first run's
configured/prepared layer policy or cycle result, and that disabling either
producer resets only that capability and performs zero preparation while the
other capability remains active.

`phpunit.xml.dist` removes the old Architecture Unit/Integration roots and adds
the two exact new roots. The existing recursive Infrastructure suite already
discovers the Console target, so no duplicate suite is added. Final discovery
must contain all 778 retained IDs exactly once plus only the explicitly named
new contract/reset IDs; zero old class identities remain.

#### Governance, generated artifacts, baseline and documentation

P4-A updates the authoritative manifest and the production/test inventory
generators with exact current-to-target rows before generated publication. It
does not invent target counts: isolated generation calculates them from the
materialized source and must reconcile the planned declaration delta. The
current 717/715 snapshot loses five declarations (`ArchitectureLifecycleHook`,
`ArchitectureProcessorInterface`, `AnalysisLifecycleHookInterface`,
`CycleInterface`, `DeferredWarning`) and adds the two Configuration-document
types, seven Architecture contract values/promises, one Circular preparation
contract, one internal Circular prepared-state service and one Circular DI
configurator. The expected target is therefore **724 declarations / 722 files**;
any different materialized count stops publication and amends this inventory
rather than changing a generated assertion blindly.

The qmx projection must have separate concrete owner layers for
`Analysis.Policy.Architecture` and `Analysis.Evidence.CircularDependency`, no
taxonomy target, no `src/Architecture` path, no three removed seams, and no P4
temporary grant. `composer architecture:generate` runs twice and both generated
trees must be byte-identical before `composer architecture:check`.
The exact-import checker also supplies the zone map asserted by
`ArchitectureInternalTopologyTest`; publication fails on every Architecture
cross-zone edge outside the DAG above and on every external import of either
leaf's internals. Coarse owner qmx edges are not accepted as a substitute for
this recursive check.

The baseline has exactly these **21** P4-relevant current identities: callable
rows for `CircularDependencyDetector::strongConnect`,
`LayersValidator::rejectDuplicatePatterns`, `WildcardSelfAllowDetector::detect`,
`SelectorSegment::capture`, `CapturePattern::compile`,
`LayerDefinition::__construct`, `LayerDefinition::validateName`,
`TupleExtractor::collectObservedTuples`, `TupleExtractor::extractTuple`, and
`LayerViolationRule::collectEdgeViolations`; class rows for
`CycleMemberLabels`, `ArchitectureConfigurationFactory`, `LayersValidator`,
`TemplateLayerDefinition`, `LayerExpansionResult`, `CircularDependencyOptions`,
`LayerViolationRule`, and `ArchitectureConfigurator`; namespace rows for
`Architecture\Configuration`, `Architecture\Domain\Layer`, and
`Architecture\Rules`. P4 re-keys only still-present facts, removes only proved
resolutions, tightens proved improvements and rejects additions or magnitude
regressions. It never bulk-regenerates `qmx-baseline.json`.

P4-E's evidence ledger re-keys the ten callable facts and six still-present
class facts at their materialized identities. `LayerViolationRule` improves
from CBO 24 to 23 and the ratchet is tightened accordingly. Within the original
21-identity ledger, it removes the resolved `ArchitectureConfigurationFactory`
and `ArchitectureConfigurator` class facts plus the three obsolete Architecture
namespace facts: sixteen re-keys/tightenings and five proved identity
resolutions. P4 also resolves two additional measured baseline channel rows on
`TransitionalResolvedConfiguration::__construct` -- constructor over-injection
and long parameter list, both magnitude 8 -- because removing the
`deferredWarnings` transport removes that constructor input. They are recorded
separately rather than silently folded into the finite 21-identity migration
ledger.

The complete qmx-policy delta has four evidence-backed exclusions and no new
baseline acceptance. Three namespace findings are structural: the Layer and
Configuration/Allow distance roots, and the exact Layer/Expansion cohesion
root. The fourth is the exact
`src/Analysis/Evidence/DependencyModel/Contract/DependencyGraphInterface.php`
CBO exclusion: the measured CBO is 30 because this public graph contract is a
deliberately widely consumed hub. It does not exclude the Contract namespace or
any sibling declaration. Completeness is checked against both sources: every
P4-caused disappearance from the pre-P4 baseline is listed as a re-key,
tightening, or proved resolution, and every new P4 dogfood finding is either
fixed or named here with its exact qmx scope; no residual delta may be hidden by
bulk baseline generation or a broader exclusion. Final dogfooding reports 722
analyzed files and zero findings.

Documentation writers are the moved Architecture README, the new
CircularDependency README, `AGENTS.md`, `docs/ARCHITECTURE.md`, ADR 0022,
`src/Analysis/README.md`, `src/Analysis/Configuration/README.md`,
`src/Analysis/Run/README.md`, `src/Analysis/Evidence/DependencyModel/README.md`,
`src/Core/README.md`, `src/Infrastructure/README.md`, `src/Rules/README.md`,
`CHANGELOG.md`, and this plan. Historical ADRs 0008-0011 keep their historical
decision text but receive a short supersession pointer where an unqualified
current path/contract claim would otherwise be false. Unrelated internal plans
are not mechanically rewritten merely because they record a historical path.
The changelog Breaking entry names removal of the old internal namespaces and
the migration to the two new leaves; no aliases or shims are added.

Worker payloads remain unchanged: graph construction and both P4 preparations
run in the main process after collection. No policy state, cycle result or
capability service enters `SuccessfulFileProcessing`, PHP serialization,
igbinary serialization or cache payloads. The existing real-Amp sequential vs
worker dependency oracle remains mandatory.

#### Executable P4 packages and file ownership

Packages are sequential at their shared seams; only B and C may execute in
parallel, and only with the file sets below. Every implementing agent works in
the main checkout with strict file isolation, runs no repository-wide git
operation, and returns its exact changed files and focused DoD evidence.

| Package                                                          | Sole-writer file set                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | Depends on       | Validation / expected red                                                                                                                                                                                                                                                                                                                                |
| ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **P4-A — governance intent**                                     | `docs/internal/modular-architecture-manifest.json`, its schema only if the accepted contract shape requires it, both modular-architecture inventory generators, and the governance-source assertions in `ModularArchitectureGovernanceIntegrationTest`                                                                                                                                                                                                                                                                                                                                                                                                            | reviewed P4 plan | isolated generation from the declared target set, Architecture internal-zone DAG and exact-set negative probes; published generated files remain untouched, so current-tree freshness may be red until E                                                                                                                                                 |
| **P4-B — declared-policy leaf and Configuration document**       | all 52 exact policy declarations above; new `ConfigurationDocumentInterface` and `ConfigurationDocument`; existing `ConfigSchema`, `ConfigurationLayer`, `ConfigurationMerger`, `ConfigurationPipeline`, `PresetStage`, `TransitionalResolvedConfiguration`, and deleted `DeferredWarning`; the 34 policy test classes, 52 policy fixtures, three policy supports, new `ArchitectureInternalTopologyTest`, `ConfigSchemaTest`, `ConfigurationMergerTest`, `ConfigurationPipelineTest`, `PresetStageTest`, `YamlNormalizationCharacterizationTest`, and `YamlKeyReachabilityTest`                                                                                  | A                | moved policy/configuration unit and integration IDs; all six Architecture merge cases plus preset chaining; configuration error/warning production parity; processor-state replacement; exact internal DAG; no Architecture import or merge policy in Configuration. Runtime/Run/Console/DI tests may remain red only for the old contract until D       |
| **P4-C — CircularDependency leaf and universal-payload removal** | the exact six circular declarations; new Circular analysis/preparation contract; `AnalysisContext`; `TransitionalMetricEnricher`; `TransitionalEnrichmentResult`; five circular tests, circular support, `AnalysisContextTest`, `MetricEnricherTest`, and channel coverage                                                                                                                                                                                                                                                                                                                                                                                        | A                | all circular unit IDs, channel declaration, enabled/disabled zero-work, canonical identity and no `cycles` payload. DI/whole-pipeline construction may remain red only until D                                                                                                                                                                           |
| **P4-D — Run, adapters and composition integration**             | `AnalysisPipeline`; deleted `Analysis/Run/Contract/Lifecycle/AnalysisLifecycleHookInterface`; `TestPipelineBuilder`, `AnalysisPipelineTest`, and `AnalysisPipelineIntegrationTest`; `RuntimeConfigurator`; `AnalysisRuntimeConfigurator`; `CheckCommand`; `LayerAssignmentCommand`; `LayerAssignmentResolver`; `BaselineCommand`; `BaselineRunInterface`; `ArchitectureConfigurator`; `AnalysisConfigurator`; `ConfigurationConfigurator`; new `CircularDependencyConfigurator`; `ContainerFactory`; `DeferredWarningIntegrationTest`, `RuntimeConfiguratorTest`, `LayerAssignmentCommandTest`, `BaselineCommandFailureReportingTest`, and `ContainerFactoryTest` | B and C          | Run phase order, two-capability sequential reset, delayed Architecture-warning parity after logger setup, debug parity, Check and all five baseline command message/exit-code parity for invalid Architecture configuration, deletion of lifecycle autoconfiguration, exact constructor/alias/tag counts and real-Amp oracle; no expected red at handoff |
| **P4-E — serial publication and closure**                        | all generated modular-architecture artifacts, generated/manual `qmx.yaml`, `qmx-baseline.json`, `phpunit.xml.dist`, all named READMEs/ADRs/CHANGELOG/website current-state docs, and P4 status/evidence in this plan                                                                                                                                                                                                                                                                                                                                                                                                                                              | D                | two byte-identical generations, discovery equality, baseline ledger, docs/leak/diff gates, aggregate validation and required review; no expected red                                                                                                                                                                                                     |

**P4-E publication evidence (2026-08-13):** two consecutive
`composer architecture:generate` runs produced byte-identical `qmx.yaml` and
all generated governance artifacts. `composer architecture:check` passed with
724 declarations, 37 semantic-owner layers, 8 seams, 64 exact internal grants
and 11 coarse edges. Generated and live PHPUnit discovery contain the same
7,236 test identities; suites are Functional 22/227, Infrastructure 5/36,
Integration 51/350 and Unit 422/6,623. The exact P4 delta is 43 classes / 795
IDs / 52 fixtures / 4 supports: 778 retained IDs plus 6 Architecture contribution-merge, 2
Architecture-warning, 3 Architecture-topology, a net 1 ArchitecturePolicy
lifecycle, 2 CircularDependency-reset and 3 governance IDs. The lifecycle net
is three new IDs replacing two obsolete processor-interface/reset identities.
The retained P6 inline-suppression test is explicitly listed once in the
Integration suite.

The first and second review rounds are resolved. Their fixes
replace owner-wide contract consumers with 22 exact permanent P4 relations
(20 import and 2 surface relations) and fail-closed schema/checker coverage;
restore pre-P4 Check, Baseline and debug
Architecture failure framing; add the compiled-container sequential reset
regression; make the baseline/qmx delta ledger complete; refresh current
dogfooding and PHPStan fixture scopes; and republish all affected projections.
Focused evidence includes the complete governance class (24 tests / 1,554
assertions), the Run/Console/DI framing and reset set (101 tests / 459
assertions), strict documentation (6 tests / 30 assertions), exact discovery
equality, zero-finding sequential dogfood, leak check and `git diff --check`.
The final root-owned `composer check` passed with 7,236 PHPUnit tests, 22,862
assertions and one skip; 17 Python tests; PHPStan over 1,243 files with no
errors; exact architecture governance at 724 declarations in 722 files, 37
semantic owners, 8 seams and 64 grants collapsing to 11 coarse edges; and
selfcheck over 722 files with zero violations. CS and leak checks also passed.
P4 is therefore Completed and P5 is unblocked.

B and C have disjoint production/test paths: B is the sole writer of
Configuration document/layer/merge files and all Architecture policy paths; C
is the sole writer of circular paths, `AnalysisContext` and transitional
enrichment. D alone deletes the Run lifecycle contract and writes shared Run,
Console and DI seams. Neither B nor C runs dependency installs, generation or
full tests concurrently. D starts only after the root orchestrator verifies both
diffs and focused gates. E is the only writer of generated files, qmx, baseline,
PHPUnit discovery configuration and shared documentation.

#### Per-package and final Definition of Done

- Configuration owns one immutable ordered source document and imports no
  Architecture type. Architecture merges and parses only its own contributed
  nodes and exposes only the exact contracts above; preset chaining preserves
  source order, and circular preparation has no configuration-document
  dependency.
- No production import remains from Run or Console to Architecture/Circular
  internals. No `configuration -> architecture`, mutual owner allow, taxonomy
  allow target, generic lifecycle/graph participant, or P4 temporary seam/grant
  remains.
- No subject-specific Architecture merge branch remains in `ConfigurationMerger`
  or `ConfigSchema`; the six existing merge regressions pass against the
  Architecture-owned merger, and the exact internal-zone DAG rejects every
  unlisted cross-zone import.
- Disabled layer policy performs zero class-universe/context/expansion work;
  disabled circular dependency performs zero SCC work. The two selectors reset
  independently across sequential runs.
- Layer violation channels, coverage/shadow/empty-template diagnostics,
  circular finding identity/severity/recommendation, wildcard warning text and
  context, configuration failures, debug output and command exit codes retain
  direct regressions.
- `AnalysisContext` and transitional enrichment contain no cycle payload;
  Configuration contains no Architecture object or deferred Architecture log
  transport; all feature state is instance-owned inside its leaf.
- All 43 current P4 test classes / 795 IDs, 52 fixtures and four supports are
  accounted for at exact target paths: 778 retained IDs plus six Architecture
  contribution-merge, two Architecture-warning, three Architecture-topology, a
  net one ArchitecturePolicy-lifecycle, two CircularDependency-reset, and three
  governance IDs. P6-owned inline-suppression files remain untouched. Every
  moved ID appears exactly once.
- No P4 object enters worker/cache serialization. Sequential and real-Amp graph
  evidence remains identical.
- Baseline reconciliation is evidence-driven over the exact 21 current
  identities plus the two explicitly measured
  `TransitionalResolvedConfiguration` constructor channel resolutions; qmx
  policy accounts for all four exact structural exclusions, with zero
  unreviewed baseline addition, magnitude regression, or blanket Contract
  exclusion.
- Focused package gates, `composer architecture:check`, governance/topology
  tests, full PHPUnit, cross-tool tests, PHPStan, CS, selfcheck, strict docs,
  leak check, `git diff --check`, and aggregate `composer check` pass.
- A root-launched independent review covers subject ownership, contract
  minimality/direction, configuration error/warning timing, reset/selection
  semantics, Run/debug/DI seams, exact inventories, worker safety, generated
  arithmetic and baseline evidence. Every confirmed finding including LOW is
  fixed in its owning package and revalidated; only then may P4 be marked
  Completed and P5 unblocked.
