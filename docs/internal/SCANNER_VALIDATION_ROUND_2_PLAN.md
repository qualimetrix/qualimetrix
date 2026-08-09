# Scanner Validation Round 2 — Implementation Plan

**Status:** approved implementation contract

**Inputs:** [Round 1 findings](SCANNER_VALIDATION_ROUND_1_FINDINGS.md),
[ADR 0021](../adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md)

## Scope and invariants

Round 1 confirmed that callable metrics lose closures and arrows, property hooks
have no callable model, dependency and coupling views use one insufficient
identity, and several PHP 8.5 constructs have incorrect semantics. This round
implements the approved model; it does not revise the Round 1 findings record.

Conditional duplicate-FQN semantics remain Round 1 deferred decision D-001.
They are distinct from F-006 through F-014: this plan makes declaration keys
collision-resistant, but does not decide whether mutually exclusive duplicate
logical declarations represent one or multiple semantic symbols.

The dependency direction remains `Infrastructure -> Analysis ->
Metrics/Rules/Reporting/Configuration -> Core`. Identity is a cross-cutting
primitive, so new identity value objects live in the existing `Core/Symbol`
subject. No role-named directory is introduced.

The following rules are non-negotiable:

1. A declaration finding, its baseline identity, and its formatter fingerprint
   use its `DeclarationPath`, never a logical class aggregate. Adding a sibling
   declaration therefore cannot change an existing declaration key.
2. `SymbolPath` remains the logical identity of named methods, functions,
   classes, files, namespaces, and project aggregates. It is not overloaded to
   distinguish duplicate declarations.
3. There are no `Method` compatibility aliases, deprecated config channels, or
   baseline migration shims. This is a breaking rename to `Callable`.
4. `qmx-baseline.json` is never regenerated automatically. Its update or
   migration is an explicit, separately reviewed user action after the new
   identities and output are verified.
5. Every public contract migration is documented with a `Breaking` changelog
   entry and consumer-oriented migration instructions.

## Approved subject and identity contract

`MetricSubject` is a tagged union. Its alternatives are intentionally not
interchangeable.

| Variant         | Carries                                                       | Used for                                                                                         | Must not be used for                                            |
| --------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ | --------------------------------------------------------------- |
| `declaration`   | `DeclarationPath`                                             | Callable/declaration metrics, violations, inline controls, baseline keys, formatter fingerprints | ClassRank graph vertices or logical class roll-up               |
| `logical-class` | `LogicalClassPath`                                            | ClassRank graph metric and its one logical aggregation/scaling pass                              | Per-declaration controls, findings, baseline keys, fingerprints |
| `aggregate`     | existing logical `SymbolPath` for file, namespace, or project | Existing aggregate metrics and reports                                                           | Disambiguating declarations                                     |

`DeclarationPath` has these immutable components:

```text
logical: SymbolPath
file: project-relative RelativePath
startFilePos: int
ordinal: int (only when declarations share logical + file + startFilePos)
```

The ordinal is deterministic from source traversal order within the collision
group, is omitted from the canonical form unless a collision exists, and is
never the ordinary sibling sequence. Consequently, adding a different sibling
declaration does not renumber or re-key an existing declaration. `LogicalClassPath`
wraps only the logical class `SymbolPath`; no file or source position is added
to it.

The planned public shape is descriptive, not implementation code:

```text
MetricSubject ::= Declaration(DeclarationPath)
                | LogicalClass(LogicalClassPath)
                | Aggregate(SymbolPath[file|namespace|project])
CallableKind  ::= Method | Function | PropertyHook | AnonymousCallable
```

`DeclarationPath` keeps exact lexical class context separately from its nullable
aggregation owner. The lexical context is the exact enclosing class declaration
(including an anonymous class when applicable). The aggregation owner is the
named class to which class metrics roll up, or `null`. This prevents an
anonymous class from being silently merged into a named outer class while still
allowing an anonymous callable nested in that class to receive correct lexical
attribution.

## Callable contract

`SymbolLevel::Method` becomes `SymbolLevel::Callable`; `RuleLevel::Method` and
all callable-level rule/config channels become `Callable`/`callable` in the same
change. The old labels, YAML keys, CLI aliases, enum cases, and display strings
are removed rather than bridged.

| `CallableKind`      | Declaration identity                                                   | Class roll-up | Namespace/project roll-up | Exact enclosing class may receive RFC attribution |
| ------------------- | ---------------------------------------------------------------------- | ------------- | ------------------------- | ------------------------------------------------- |
| `Method`            | `DeclarationPath`                                                      | Yes           | Yes                       | Yes                                               |
| `Function`          | `DeclarationPath`                                                      | No            | Yes                       | No                                                |
| `PropertyHook`      | `DeclarationPath`                                                      | Yes           | Yes                       | Yes                                               |
| `AnonymousCallable` | `DeclarationPath`; `closure` or `arrow` is syntax metadata, not a kind | No            | Yes                       | Yes, for its body/expression calls                |

Hooks are class-rollable callables but not ordinary declared methods for
method-only metrics: `methodCount` and WMC count methods only. RFC consists of
own methods plus hooks; its direct call semantics must not count a first-class
callable capture as execution. The control lookup for a hook is exactly:

`hook > property > class > config`.

F-009 is resolved by recording callable capture separately from invocation in
RFC and Halstead. Capture may retain a dependency edge when the resolver can
name its target, but it does not add an executed RFC target and does not use the
ordinary invocation operator.

F-011 is only partially accepted: an initializer is not a synthetic callable.
Property and class-constant initializers have no callable owner, so their
first-class captures do not create callable metric work. The Halstead method
declaration subtree does include promoted-parameter defaults: a first-class
capture in such a default belongs to the constructor's Halstead result. It is
still a capture, not RFC execution. A capture inside any other real callable is
attributed to that real `DeclarationPath`, never inferred from an unrelated
initializer position.

## Callable providers and aggregation inventory

The inventory below is the complete current visitor set. P2 must inspect each
row and either migrate it to `MetricSubject`/`DeclarationPath` or add an
explicit regression proving it deliberately has no callable output. This avoids
assuming that all visitors share the same lifecycle.

| Area              | Visitors and providers                                                                                                                                                          | Required result                                                                                      |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| Complexity        | `CognitiveComplexityVisitor`/Collector, `CyclomaticComplexityVisitor`/Collector, `NpathComplexityVisitor`/Collector                                                             | One declaration result per supported callable; hooks, closures, and arrows follow the table above    |
| Halstead          | `HalsteadVisitor`/Collector                                                                                                                                                     | Callable result plus distinct capture, invocation, `clone`, and pipe operator semantics              |
| Size              | `MethodStatementCountVisitor`/Collector, `MethodCountVisitor`/Collector, `LocVisitor`/Collector, `ClassCountVisitor`/Collector                                                  | Statement count remains independent of NPath's one-statement base; method count remains methods-only |
| Structure         | `RfcVisitor`/Collector, `LcomVisitor`/Collector, `TccLccVisitor`/Collector, `UnusedPrivateVisitor`/Collector, `InheritanceDepthVisitor`/Collector                               | RFC follows methods-plus-hooks; class-only analyses explicitly reject non-class callables            |
| Design and smells | `TypeCoverageVisitor`/Collector, `CodeSmellVisitor`/Collector, `ParameterCountVisitor`/Collector, `UnreachableCodeVisitor`/Collector, `IdenticalSubExpressionVisitor`/Collector | Function-like signatures and hook controls have declared coverage or an asserted inapplicability     |
| Security          | `HardcodedCredentialsVisitor`/Collector, `SecurityPatternVisitor`/Collector, `SensitiveParameterVisitor`/Collector                                                              | Callable findings preserve the declaration identity and control precedence                           |

No P2 change is complete until the renamed provider interface,
`CallableWithMetrics`, collector registration, and all consumers have
been migrated together. The former nullable-path drop in `FileProcessor` is
not an allowed retention point.

### Existing aggregation strategies to retain

The migration changes the leaf identity and eligible callable set, not the
mathematical aggregation strategy declared by existing collectors.

| Leaf metric(s) / current collector                          | Callable-to-class | Namespace and project                   |
| ----------------------------------------------------------- | ----------------- | --------------------------------------- |
| `ccn`; `cognitive`                                          | sum, average, max | sum, average, max, p95                  |
| `npath`                                                     | max, average      | max, average, p95                       |
| `halstead.volume`; `halstead.difficulty`; `halstead.effort` | average, max      | average, max, p95                       |
| `halstead.bugs`; `halstead.time`                            | sum, max          | sum, max for namespace; sum for project |
| `methodStatementCount`                                      | sum, average, max | sum, average, max                       |
| `mi`                                                        | average, min      | average, min, p5                        |

`CallableToClassAggregator`, `ClassToNamespaceAggregator`,
`NamespaceMetricContributions`, and `NamespaceToProjectAggregator` retain the
declared strategy matrix. The only changed inputs are the complete callable
set and the explicit choice of class owner from `MetricSubject`.

## Dependency projections and coupling contract

Every dependency records an exact declaration source and a logical target.
Architecture rules resolve that logical target against owned declarations and
may yield zero, one, or many owned targets; it must not choose one duplicate
declaration by accident. Projection consumers are separate:

| Consumer                         | Source/target projection                                   | Required semantics                                                                 |
| -------------------------------- | ---------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Architecture and cycle reporting | exact declaration source -> 0..N owned declaration targets | Finding/control/baseline identity remains the affected declaration                 |
| CBO and namespace coupling       | logical source -> unique logical target set                | Deduplicate logical targets and sources; retain external targets                   |
| ClassRank                        | `LogicalClassPath` vertex graph                            | Calculate one metric per logical class, aggregate and scale once                   |
| ClassRank rule/reporting         | logical score projected to every declaration               | Each declaration has its own finding, controls, baseline identity, and fingerprint |

F-013 requires the graph universe to come from the repository's declared
logical classes, not only from edge endpoints. Graph adjacency includes every
such vertex, including degree-zero vertices; coupling collectors emit known
zeroes rather than absent metrics.

## Projection inventory

All projections consume the same subject contract. P5 must inventory and test
the following rather than repairing only one formatter: generic JSON,
metrics JSON, text and verbose text, summary/health text, HTML tree/debt/
health sections, Checkstyle, GitLab Code Quality fingerprints, GitHub Actions,
SARIF locations and partial fingerprints, dependency JSON/DOT exports,
baseline identities/entries/ceiling/update/explain flows, `Violation`
fingerprints, health contributors and namespace drill-down, and rule-channel
selection. No projection may reconstruct an identity from a display name.

## Confirmed construct semantics

| Finding | Implementation contract                                                                                                                                                                                                                          |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| F-007   | Recognise PHP 8.5 `clone($object, [...])` as language `clone`, not a global function invocation. It has no fictitious RFC external target and its Halstead operator is `clone`.                                                                  |
| F-008   | A nullsafe access contributes zero-based branch contribution plus the enclosing callable's one statement base. One access therefore yields NPath 2 and a two-access chain yields 3, subject to ordinary surrounding control-flow multiplication. |
| F-009   | See callable contract: capture and invocation are separate; capture is not RFC execution.                                                                                                                                                        |
| F-010   | PHP 8.5 pipe records the concrete Halstead operator `|>`, not `binary_op`; other metric neutralities stay tested.                                                                                                                                |
| F-011   | See partial rejection above: property/class-constant initializers have no callable owner; promoted-parameter defaults belong to constructor Halstead but a capture remains non-executing for RFC.                                                |
| F-013   | See repository-universe and degree-zero adjacency contract above.                                                                                                                                                                                |
| F-014   | Every modern metric deviation uses the durable component README `> **Note:**` and EN/RU website `!!! info "Deviation from original spec"` forms.                                                                                                 |

## Sequential implementation packages

No production packages run in parallel. A package starts only after its
predecessor's diff and machine DoD are verified. Files named below are the
complete allowed production/test/documentation set for that package; a newly
discovered file requires returning to this plan before editing it.

### P1 — Atomic mechanical contract migration

**Depends on:** none.

**Files:** this is one atomic, mechanical rename package, not a Core-only
package. It creates `src/Core/Symbol/{DeclarationPath,LogicalClassPath,
MetricSubject,CallableKind}.php`, replaces
`src/Core/Metric/{MethodWithMetrics,MethodMetricsProviderInterface}.php` with
`src/Core/Metric/{CallableWithMetrics,CallableMetricsProviderInterface}.php`,
replaces `src/Analysis/Aggregator/MethodToClassAggregator.php` with
`src/Analysis/Aggregator/CallableToClassAggregator.php`, and updates
`src/Core/{Metric/SymbolLevel.php,Rule/RuleLevel.php,Symbol/SymbolPath.php,
README.md}`.

The complete allowed consumer set is the union of the following deterministic
searches, frozen before the first P1 edit. Copy each sorted result into the P1
execution record, deduplicate the paths, and edit only that resulting named set
plus the new replacement files above:

```bash
rg -l --glob '*.php' --glob '*.yaml' --glob '*.yml' --glob '*.json' --glob '*.md' 'SymbolLevel::Method|RuleLevel::Method|MethodWithMetrics|MethodMetricsProviderInterface|MethodToClassAggregator|getMethodsWithMetrics|getCallablesWithMetrics' src tests docs website qmx.yaml composer.json | sort
rg -l 'method:' src tests docs website | sort
rg -l --glob '*.php' "case Method[[:space:]]*=[[:space:]]*['\"]method['\"]|['\"]method['\"][[:space:]]*=>" src tests | sort
rg -n --glob '*.php' "['\"]method['\"]" src tests
rg -l --glob '*.yaml' --glob '*.yml' '^[[:space:]]*method:' src tests website qmx.yaml | sort
rg -l --glob '*.php' --glob '*.md' --glob '*.yaml' --glob '*.yml' --glob '*.json' '\.method\b' src tests docs website qmx.yaml composer.json | sort
rg -l --glob '*.json' '"type"[[:space:]]*:[[:space:]]*"method"|"method"' tests docs website | sort
```

The frozen inventory is classified as follows; each row's command result is an
explicit list, never a directory wildcard.

Every occurrence in the exact PHP string-token inventory (`rg -n ...
"['\"]method['\"]" src tests`) is classified before editing. A hierarchy
contract occurrence (array key, comparison, return value, serialized
expectation, or `RuleOptionThresholdModeResolver` branch that denotes the
callable level) is renamed to `callable`. A legitimate PHP method/business-data
occurrence is retained only after its exact `file:line`, reason, and reviewer
are added to the P1 reviewed allowlist. This classification deliberately covers
array access, comparisons/returns, PHP serialized expectations, and the
threshold-mode resolver rather than treating only YAML keys as configuration.

Every occurrence in the exact `method:` inventory (`rg -l 'method:' src tests
docs website | sort`) is likewise classified. A hierarchy/config/SymbolPath or
baseline canonical prefix, and every public rule-channel suffix such as
`complexity.cyclomatic.callable`, is renamed to `callable`. The reviewed
allowlist may retain only: P1's transitional internal DTO argument; Halstead
operand labels named `method:`; metric identifiers such as
`unusedPrivate.method:*`; ordinary prose or PHP member/business data about a
method; and intentional legacy v5 migration fixtures. The transitional DTO
allowlist is P1-only and must be removed by P2.

| Consumer category                            | Files that must be present in the frozen result                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core and aggregation                         | `src/Core/Metric/{SymbolLevel,MethodWithMetrics,MethodMetricsProviderInterface}.php`, `src/Core/Rule/RuleLevel.php`, `src/Core/Symbol/SymbolPath.php`, `src/Analysis/Aggregator/{MethodToClassAggregator,MetricAggregator,NamespaceMetricContributions}.php`, `src/Analysis/Collection/FileProcessor.php`                                                                                                                                                                                                                                                                                                           |
| Callable metric producers                    | `src/Metrics/CodeSmell/{ParameterCountCollector,ParameterCountVisitor,UnreachableCodeCollector,UnreachableCodeVisitor}.php`, `src/Metrics/Complexity/{CognitiveComplexityCollector,CognitiveComplexityVisitor,CyclomaticComplexityCollector,CyclomaticComplexityVisitor,NpathComplexityCollector,NpathComplexityVisitor}.php`, `src/Metrics/Halstead/{HalsteadCollector,HalsteadVisitor}.php`, `src/Metrics/{Maintainability/MaintainabilityIndexCollector,Size/MethodStatementCountCollector,Size/MethodStatementCountVisitor,Structure/LcomVisitor,Structure/TccLccVisitor,Structure/UnusedPrivateCollector}.php` |
| Rules, configuration, baseline, console      | `src/Rules/CodeSmell/UnreachableCodeOptions.php`, `src/Rules/Complexity/{CognitiveComplexityOptions,CognitiveComplexityRule,ComplexityOptions,ComplexityRule,NpathComplexityOptions,NpathComplexityRule}.php`, `src/Configuration/{RuleOptionThresholdModeResolver,RuleOptionsFactory}.php`, `src/Configuration/Pipeline/ConfigurationMerger.php`, `src/Baseline/BaselineWriter.php`, `src/Infrastructure/Console/{ViolationFilterOrchestrator,Command/BaselineExplainCommand}.php`, `src/Configuration/Preset/{legacy,strict}.yaml`                                                                                |
| Tests and fixtures                           | Every exact test/fixture path in the command output under `tests/`, including the Core, Analysis, Baseline, Configuration, Metrics, Rules, Console, and Violation suites; no test path is admitted by a glob after the inventory is frozen.                                                                                                                                                                                                                                                                                                                                                                         |
| Tracked configuration and reference material | Every exact matching `src/Configuration/` and `src` README path, plus every exact `website/`, `qmx.yaml`, and `composer.json` path in the command output whose `method:` occurrence is a hierarchy channel or canonical symbol prefix; ordinary prose and PHP member names are excluded and recorded as such.                                                                                                                                                                                                                                                                                                       |

**Work:** define the Core type names and atomically rename `Method` to
`Callable` in enum values, channels, config keys, DTO/provider/aggregator type
names, imports, tests, fixtures, and reference examples. `CallableWithMetrics`
has a deliberately transitional **internal** payload equivalent to the old
`namespace`, `class`, `method`, `line`, and `metrics` shape. It is neither a
public compatibility shim nor the final callable contract. Preserve current
methods-only behavior: P1 does not add hook or anonymous-callable semantics,
does not thread final declaration metadata, and does not change storage
identity. No compatibility alias, adapter, dual parser, or temporary deprecated
API is permitted.

**DoD:** existing behavior tests are green with only vocabulary/canonical-value
expectations intentionally updated; PHPStan and CS are green. Where meaningful,
first prove the mechanical guard red by a targeted old-name/old-channel
mutation, then restore the rename and prove the guard green. After migration,
run the union patterns above as a forbidden-old-contract guard across the
tracked active consumer set. Historical ADRs and Round 1 findings are excluded
from that set. The initial reviewed historical allowlist is
`docs/adr/0002-html-report.md`,
`docs/adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md`,
`docs/internal/CLI_CONVENTIONS.md`, `docs/internal/PRODUCT_ROADMAP.md`,
`docs/internal/SCANNER_VALIDATION_ROUND_1_FINDINGS.md`,
`docs/internal/SCANNER_VALIDATION_ROUND_2_PLAN.md`, and
`docs/internal/plans/global-functions-graph.md`; if another historical document
is matched, add its exact path and rationale to that reviewed allowlist rather
than weakening a pattern. Repeat the exact PHP string-token search in the
post-guard; it fails on every occurrence not classified as hierarchy contract
or present with exact `file:line` rationale in the reviewed allowlist. The
post-guard also repeats the exact `method:` inventory and fails on every
occurrence that is neither renamed nor in its reviewed allowlist. The project
is never intentionally left non-compiling between packages.

### P2+P3 — Atomic callable production and declaration-aware transport

**Depends on:** P1.

**Reviewed package additions:** `tests/Unit/Metrics/Complexity/CyclomaticComplexityVisitorTest.php`
exercises the final callable payload for every `CallableKind` and both
anonymous syntax variants. It is intentionally added before the fixture/test
path is created.

**Files:** the exact semantic inventory below replaces the inherited P1
producer row. A class-only or otherwise inapplicable row remains in scope; its
direct test proves inapplicability rather than allowing the file to be omitted.

| Area                            | Exact production files                                                                                                                                                                                                                                                                                      |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core callable/subject contracts | `src/Core/Metric/{CallableWithMetrics,CallableMetricsProviderInterface,MetricRepositoryInterface,ClassWithMetrics,ClassMetricsProviderInterface,NamespaceWithMetrics,NamespaceMetricProviderInterface}.php`; `src/Core/Symbol/{CallableKind,DeclarationPath,LogicalClassPath,MetricSubject,SymbolInfo}.php` |
| Complexity                      | `src/Metrics/Complexity/{CognitiveComplexityVisitor,CognitiveComplexityCollector,CyclomaticComplexityVisitor,CyclomaticComplexityCollector,NpathComplexityVisitor,NpathComplexityCollector}.php`                                                                                                            |
| Halstead                        | `src/Metrics/Halstead/{HalsteadVisitor,HalsteadCollector}.php`                                                                                                                                                                                                                                              |
| Size                            | `src/Metrics/Size/{MethodStatementCountVisitor,MethodStatementCountCollector,LocVisitor,LocCollector,ClassCountVisitor,ClassCountCollector}.php`; `src/Metrics/Structure/{MethodCountVisitor,MethodCountCollector}.php`                                                                                     |
| Structure                       | `src/Metrics/Structure/{RfcVisitor,RfcCollector,LcomVisitor,LcomCollector,TccLccVisitor,TccLccCollector,UnusedPrivateVisitor,UnusedPrivateCollector,InheritanceDepthVisitor,InheritanceDepthCollector}.php`                                                                                                 |
| Design and code smell           | `src/Metrics/Design/{TypeCoverageVisitor,TypeCoverageCollector}.php`; `src/Metrics/CodeSmell/{CodeSmellVisitor,CodeSmellCollector,ParameterCountVisitor,ParameterCountCollector,UnreachableCodeVisitor,UnreachableCodeCollector,IdenticalSubExpressionVisitor,IdenticalSubExpressionCollector}.php`         |
| Security                        | `src/Metrics/Security/{HardcodedCredentialsVisitor,HardcodedCredentialsCollector,SecurityPatternVisitor,SecurityPatternCollector,SensitiveParameterVisitor,SensitiveParameterCollector}.php`                                                                                                                |
| Shared collector lifecycle      | `src/Metrics/{AbstractCollector,ResettableVisitorInterface,VisitorMethodTrackingTrait}.php`; `src/Metrics/Structure/ClassVisitorStackTrait.php`                                                                                                                                                             |

The exact class-provider data carriers required to emit final declaration
identity are also in scope: `src/Metrics/Structure/{InheritanceClassInfo,
LcomClassData,MethodCountMetrics,TccLccClassData,UnusedPrivateClassData}.php`,
`ClassRfcData` in `RfcVisitor.php`, the class range in `LocVisitor.php`, and
the class-info carrier in `TypeCoverageVisitor.php`. Each carries
`startFilePos` or the final `DeclarationPath`; `line` remains presentation
metadata only and is never converted into a source offset. Their existing
direct collector tests are already listed below.

The exact direct Core tests are
`tests/Unit/Core/Metric/{CallableWithMetricsTest,ClassWithMetricsTest,NamespaceWithMetricsTest}.php`,
`tests/Unit/Core/Symbol/{CallableKindTest,DeclarationPathTest,LogicalClassPathTest,MetricSubjectTest,SymbolInfoTest,SymbolPathCanonicalKeyStabilityTest,SymbolPathTest}.php`,
and `tests/Unit/Analysis/Repository/InMemoryMetricRepositoryTest.php`. The exact
metric tests are
`tests/Unit/Metrics/Complexity/{CognitiveComplexityVisitorTest,CognitiveComplexityCollectorTest,CyclomaticComplexityVisitorTest,CyclomaticComplexityCollectorTest,NpathComplexityVisitorTest,NpathComplexityCollectorTest}.php`,
`tests/Unit/Metrics/Halstead/{HalsteadVisitorTest,HalsteadCollectorTest}.php`,
`tests/Unit/Metrics/Size/{MethodStatementCountVisitorTest,MethodStatementCountCollectorTest,LocVisitorTest,LocCollectorTest,ClassCountVisitorTest,ClassCountCollectorTest}.php`,
`tests/Unit/Metrics/Structure/{MethodCountVisitorTest,MethodCountCollectorTest,RfcVisitorTest,RfcCollectorTest,LcomVisitorTest,LcomCollectorTest,TccLccVisitorTest,TccLccCollectorTest,UnusedPrivateVisitorTest,UnusedPrivateCollectorTest,InheritanceDepthVisitorTest,InheritanceDepthCollectorTest}.php`,
`tests/Unit/Metrics/Design/{TypeCoverageVisitorTest,TypeCoverageCollectorTest}.php`,
`tests/Unit/Metrics/CodeSmell/{CodeSmellVisitorTest,CodeSmellCollectorTest,ParameterCountVisitorTest,ParameterCountCollectorTest,UnreachableCodeVisitorTest,UnreachableCodeCollectorTest,IdenticalSubExpressionVisitorTest,IdenticalSubExpressionCollectorTest}.php`,
and `tests/Unit/Metrics/Security/{HardcodedCredentialsVisitorTest,HardcodedCredentialsCollectorTest,SecurityPatternVisitorTest,SecurityPatternCollectorTest,SensitiveParameterVisitorTest,SensitiveParameterCollectorTest}.php`.
Tests not present at package start are new exact allowed paths; a class-only or
inapplicable visitor gets a direct negative contract test rather than omission.

The Analysis transport/repository/aggregation/dependency set is:
`src/Analysis/Collection/{FileProcessor,FileProcessingResult,CollectedFileData,CollectionResult,CollectionPhaseOutput,CollectionOrchestrator}.php`;
`src/Analysis/Collection/Metric/{CollectionOutput,CompositeCollector,DerivedMetricExtractor}.php`;
`src/Analysis/Aggregator/{MetricAggregator,CallableToClassAggregator,ClassToNamespaceAggregator,NamespaceMetricContributions,NamespaceToProjectAggregator,TreeAwareNamespaceAggregator,AggregationHelper}.php`;
`src/Analysis/Repository/{InMemoryMetricRepository,DefaultMetricRepositoryFactory}.php`;
`src/Analysis/Collection/Dependency/{DependencyVisitor,DependencyGraphBuilder,DependencyGraph,DependencyResolver,CircularDependencyDetector}.php`;
`src/Analysis/Collection/Dependency/Handler/{CatchInstanceofHandler,ClassLikeHandler,DependencyContext,FunctionLikeHandler,InstantiationHandler,NodeDependencyHandlerInterface,PropertyHandler,StaticAccessHandler,TraitUseHandler,TypeDependencyHelper}.php`;
`src/Analysis/Pipeline/{AnalysisPipeline,DependencyGraphAnalyzer}.php`;
`src/Infrastructure/Console/Command/Debug/LayerAssignmentCommand.php`;
`src/Core/Dependency/{Dependency,DependencyGraphInterface,EmptyDependencyGraph}.php`;
`src/Core/README.md`, `src/Metrics/README.md`,
`src/Analysis/README.md`, `src/Metrics/CodeSmell/README.md`,
`src/Metrics/Complexity/README.md`, `src/Metrics/Halstead/README.md`,
`src/Metrics/Maintainability/README.md`, `src/Metrics/Size/README.md`, and
`src/Metrics/Structure/README.md`. Newly named semantic fixtures are admitted
only after appending their exact paths to this package's reviewed file list;
the package does not reopen the P1 mechanical consumer set.

The direct Analysis/Core Dependency test inventory is literal and complete;
no row admits a directory wildcard:

| Production slice                                                                                        | Exact direct and integration tests                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| ------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Collection DTOs, `FileProcessor`, and `CollectionOrchestrator`                                          | `tests/Unit/Analysis/Collection/FileProcessingResultTest.php`; `tests/Unit/Analysis/Collection/FileProcessorTest.php`; `tests/Unit/Analysis/Collection/CollectionResultTest.php`; `tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php`; `tests/Integration/Analysis/MultiNamespaceAnalysisTest.php`; `tests/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`                                                                                                                                                                                                                                                                                                                                                             |
| `CompositeCollector`, collection output, `DerivedMetricExtractor`, and final provider signature callers | `tests/Unit/Analysis/Collection/Metric/CompositeCollectorTest.php`; `tests/Unit/Analysis/Collection/Metric/DerivedCollectorSortTest.php`; `tests/Unit/Analysis/Collection/Metric/DerivedMetricExtractorTest.php`; `tests/Integration/Rules/LongParameterListVoPropagationTest.php`; `tests/Unit/Metrics/AnonymousClassContextRegressionTest.php`; `tests/Unit/Rules/Maintainability/MaintainabilityRuleTest.php`                                                                                                                                                                                                                                                                                                                             |
| Repository and repository factory                                                                       | `tests/Unit/Analysis/Repository/InMemoryMetricRepositoryTest.php`; `tests/Integration/DependencyInjection/ContainerFactoryTest.php`; `tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php`; `tests/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| All aggregators and `AggregationHelper`                                                                 | `tests/Unit/Analysis/Aggregator/AggregationHelperTest.php`; `tests/Unit/Analysis/Aggregator/ClassToNamespaceAggregatorTest.php`; `tests/Unit/Analysis/Aggregator/GlobalFunctionAggregationTest.php`; `tests/Unit/Analysis/Aggregator/MetricAggregatorTest.php`; `tests/Unit/Analysis/Aggregator/NamespaceMetricContributionsTest.php`; `tests/Unit/Analysis/Aggregator/NamespaceToProjectAggregatorTest.php`; `tests/Unit/Analysis/Aggregator/TreeAwareNamespaceAggregatorTest.php`; `tests/Integration/Metrics/GoldenFileAggregationTest.php`; `tests/Integration/Metrics/MetricInvariantTest.php`; `tests/Integration/WmcIntegrationTest.php`                                                                                              |
| Dependency visitor, resolver, graph, builder, circular detector, and every handler/helper               | `tests/Unit/Analysis/Collection/Dependency/DependencyVisitorTest.php`; `tests/Unit/Analysis/Collection/Dependency/DependencyResolverTest.php`; `tests/Unit/Analysis/Collection/Dependency/DependencyGraphTest.php`; `tests/Unit/Analysis/Collection/Dependency/CircularDependencyDetectorTest.php`; `tests/Unit/Analysis/Collection/Dependency/TypeDependencyHelperTest.php`; `tests/Unit/Analysis/Collection/Dependency/CycleIdentityStabilityTest.php`; `tests/Unit/Analysis/Pipeline/DependencyGraphAnalyzerTest.php`; `tests/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`                                                                                                                                                   |
| Core dependency contracts                                                                               | `tests/Unit/Core/Dependency/DependencyTest.php`; `tests/Unit/Core/Dependency/EmptyDependencyGraphTest.php`; `tests/Unit/Analysis/Collection/Dependency/DependencyGraphTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Sequential/parallel transport parity                                                                    | `tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php`; `tests/Integration/Analysis/MultiNamespaceAnalysisTest.php`; `tests/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Degree-zero graph caller seam                                                                           | `tests/Unit/Analysis/Collection/Dependency/DependencyGraphTest.php`; `tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php`; `tests/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`; `tests/Unit/Analysis/Pipeline/DependencyGraphAnalyzerTest.php`; `tests/Functional/Console/Command/GraphExportCommandTest.php`; `tests/Functional/Console/LayerAssignmentCommandTest.php`; `tests/Support/Pipeline/TestPipelineBuilder.php`; `tests/Unit/Metrics/Coupling/CouplingCollectorTest.php`; `tests/Unit/Metrics/Coupling/ClassRankCollectorTest.php`; `tests/Unit/Metrics/Structure/DitGlobalCollectorTest.php`; `tests/Unit/Metrics/Structure/NocCollectorTest.php`; `tests/Fixtures/CouplingProject/Isolated/StandaloneClass.php` |

**Work:** atomically replace P1's transitional internal payload with the final
DTO. Thread project-relative file, start-file position, `CallableKind`, exact
lexical declaration context, nullable aggregation owner, and metrics through
every callable producer. Handle property hooks and anonymous-callable
closure/arrow syntax variants, preserving their emitted metrics; class-only
visitors must prove deliberate inapplicability. In the same atomic package,
transport that final DTO through collection, repository, aggregation, and
dependency primitives. Preserve exact declaration results end-to-end, use the
aggregation owner for class roll-up, retain exact declaration source plus
logical target, and seed graph vertices from the repository universe. This
package implements typed subject operations in the repository contracts; it
does not overload the existing `SymbolPath` aggregate API. It does not decide
final Architecture findings or controls.

The graph-builder contract is mandatory and has no default or service-locator
fallback:

```text
DependencyGraphBuilder::build(
    array $dependencies,
    iterable<LogicalClassPath> $logicalClassUniverse,
): DependencyGraph
```

`AnalysisPipeline` and the debug `LayerAssignmentCommand` pass the typed
repository logical-class universe. Standalone `DependencyGraphAnalyzer`
collects the declared logical-class universe during its existing parsing path
and passes it to the builder while preserving the public GraphExport interface.

The exact-declaration `Dependency` primitive necessarily changes compile-time
consumers before P5 can project a finding. This atomic package also admits the
following **mechanical projection migration only**:

```text
src/Analysis/Collection/Dependency/{CircularDependencyDetector,
DependencyGraphBuilder,Export/DotExporter,Export/JsonGraphExporter}.php
src/Metrics/{Coupling/ClassRankCollector,Coupling/CouplingCollector,
Structure/DitGlobalCollector,Structure/NocCollector}.php
src/Rules/Coupling/CboRule.php
src/Architecture/{Domain/Layer/ClassContextFactory,Rules/LayerViolationRule}.php
```

Those consumers must use `Dependency::sourceLogical()` and
`Dependency::targetLogical()` where their existing logical graph/coupling
projection needs a `SymbolPath`. This is not permission to add a compatibility
field, alias, implicit conversion, or second source representation to
`Dependency`. `LayerViolationRule` remains limited to mechanical compilation
against the exact source; its P5 0..N owned-target projection, finding identity,
and control precedence remain deferred. Coupling, ClassRank, DIT, NOC, and CBO
retain their current logical-projection behaviour; P6 changes their semantics.

The exact direct test callers admitted for that migration are:

```text
tests/Architecture/Unit/Domain/Layer/{ClassContextFactoryTest,LayerRegistryTest}.php
tests/Architecture/Unit/Rules/{CoverageDiagnosticsTest,LayerViolationRuleTest}.php
tests/Integration/Pipeline/AnalysisPipelineIntegrationTest.php
tests/Support/Dependency/AdjacencyGraphBuilder.php
tests/Unit/Analysis/Collection/{CollectionOrchestratorTest,
Dependency/{DependencyGraphTest,DependencyVisitorTest,TypeDependencyHelperTest,
Export/{DotExporterTest,JsonGraphExporterTest}}}.php
tests/Unit/Analysis/Pipeline/{AnalysisPipelineTest,DependencyGraphAnalyzerTest,
MetricEnricherTest}.php
tests/Unit/Core/Dependency/DependencyTest.php
tests/Unit/Metrics/Coupling/{ClassRankCollectorTest,CouplingCollectorTest}.php
tests/Unit/Metrics/Structure/{DitGlobalCollectorTest,NocCollectorTest}.php
tests/Unit/Rules/Coupling/CboRuleTest.php
tests/Unit/Metrics/AnonymousClassContextRegressionTest.php
tests/Functional/Console/Command/GraphExportCommandTest.php
tests/Functional/Console/Command/{CheckCommandTest,CheckCommandPathInputTest}.php
```

Every edited constructor/field assertion in this list constructs a real
`DeclarationPath` and checks the logical projection deliberately. The package
must finish with PHPStan green across the project; it may not leave a temporary
red compile state for P5 or retain a compatibility shim.

The two admitted CLI files keep their one-class fixtures explicitly clean by
also disabling `coupling.class-rank`: the mandatory P3 degree-zero universe
correctly makes an isolated class eligible for ClassRank (1.0 for a one-vertex
graph), while these tests exercise path and output-format contracts rather
than that rule's result.

The only non-Analysis implementation of the expanded typed repository contract
is also admitted as a compile-only seam:

```text
src/Baseline/BoundaryExplanationService.php
tests/Unit/Baseline/BoundaryExplanationServiceTest.php
```

Its anonymous null repository implements the typed declaration and
logical-class methods as empty/no-op operations consistent with its existing
null aggregate behaviour. It does not change baseline explanation semantics,
baseline identity, filtering, or P5 lifecycle behaviour.

The exhaustive declaration-add caller inventory additionally admits these
compile/test-only migrations and no production semantics:

```text
tests/Functional/Console/Command/BaselineExplainCommandTest.php
tests/Unit/Baseline/BoundaryExplanationServiceTest.php
tests/Unit/Reporting/Formatter/Html/HtmlTreeBuilderTest.php
```

Together with the already listed aggregator, repository, and pipeline tests,
these paths replace declaration-level `add(SymbolPath, ...)` calls with the
typed callable/class operation. File, namespace, project, and other aggregate
`add()` calls remain unchanged.

**DoD:** fixtures for each `CallableKind` and both anonymous syntax variants
emit the final callable metadata and metrics, including file/start position,
lexical context, and aggregation owner. No P1-shaped payload remains in a
producer. Duplicate logical declarations remain distinct through collection,
repository, aggregation, and dependency primitives; sequential and parallel
collection produce the same declaration set; dependency primitives retain
exact source/logical target; degree-zero classes enter graph adjacency. Typed
repository subject operations are covered directly while existing
`SymbolPath` file/namespace/project aggregate operations retain their contract.
For duplicate logical FQNs, derived Maintainability Index results remain
separate by both `DeclarationPath` and `CallableKind` through extraction,
repository storage, and aggregation. Empty-dependency declared classes remain
present through pipeline, debug layer assignment, and graph export. Multiple
declarations of one logical class create one logical graph vertex, never
duplicates. Coupling, ClassRank, DIT, and NOC regressions consume that same
universe contract. One machine validation occurs at the end
of the entire atomic package—there is no intermediate P2/P3 validation
boundary. Control precedence, declaration findings, and LayerViolation's 0..N
owned-target projection are deferred to P5.

### P4 — NPath boundary semantics

**Depends on:** P2+P3.

**Files:** `src/Metrics/Complexity/NpathExpressionCalculator.php`,
`src/Metrics/Complexity/NpathComplexityVisitor.php`,
`src/Metrics/Complexity/NpathComplexityCollector.php`,
`tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php`, and
`tests/Unit/Metrics/Complexity/NpathExpressionCalculatorTest.php`.

**Work:** apply F-008's zero-based nullsafe contribution and the enclosing
callable's one-statement base. This package does not modify Halstead or RFC.

**DoD:** isolated and chained nullsafe fixtures match their frozen values, and
unchanged control fixtures retain existing values. The integrated repeat also
reconfirms the P2+P3 clone-with and pipe fixtures without modifying their visitors.

### P5 — Rules, baseline, and reporting projections

**Depends on:** P4.

#### P5 decisions and contracts

`Violation` has one mandatory typed identity and two deliberately non-identifying
projections:

```text
Violation::__construct(
    Location $location,
    MetricSubject $subject,
    SymbolPath $symbolPath,
    // ... existing finding fields
    ?string $occurrenceKey = null,
)
Violation::getFingerprint(): string
```

- `subject` is the finding identity. No constructor default, nullable form,
  `SymbolPath` fallback, location-derived reconstruction, or compatibility
  overload is allowed. `reportedAsBreach()` copies it.
- `symbolPath` is the logical/display projection. It remains available to
  human-facing and aggregate consumers but never substitutes for `subject`;
  group findings may intentionally display a more specific path than their
  project aggregate subject.
- `occurrenceKey` is optional because one subject/channel may deliberately be
  one baseline group. When supplied, it is a stable semantic discriminator and
  participates in baseline identity and CI fingerprints. It never contains or
  derives from `Location`, line, message text, discovery order, or a sorted
  location list. `OccurrenceKey::semantic(string $kind, array $scalarEvidence)`
  canonicalizes named scalar evidence and hashes it with full SHA-256; identical
  semantic occurrences intentionally share a key and remain count-bounded.
- `location` and `relatedLocations` are presentation/navigation data only.
  Neither participates in baseline identity or a CI fingerprint.
- The canonical fingerprint consists of the violation channel, the subject's
  canonical key, optional occurrence key, and the existing logical
  dependency-edge discriminator when present. GitLab and SARIF consume this
  value; line and message text do not.

Property is a control scope, not a fifth `MetricSubject` variant. Add
`Core\Suppression\ControlScope` beside the existing suppression contracts and
carry the explicit scope on extracted suppressions and threshold overrides.
The declaration-ranked contract surface is
`ControlScope::{Hook,Property,Callable,Class}` plus deterministic
`specificity(): int`; configuration remains the base Options value rather than
a synthetic extracted scope. `File` and `NextLine` are separate physical
suppression modes, not ranks in the declaration hierarchy.

The complete AST ingress matrix is fixed here:

| AST node                                               | Declaration-ranked binding                                                                                                  | Physical suppression       | Threshold ingress                                                                         |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- | -------------------------- | ----------------------------------------------------------------------------------------- |
| `PropertyHook`                                         | Hook declaration, rank `Hook`                                                                                               | Its source range           | Yes                                                                                       |
| `Property`                                             | Every hook declared by that property, rank `Property`; no property subject                                                  | Its source range           | Yes                                                                                       |
| `ClassMethod`, `Function_`, `Closure`, `ArrowFunction` | Exact callable declaration, rank `Callable`                                                                                 | Its source range           | Yes                                                                                       |
| `Class_`, `Interface_`, `Trait_`, `Enum_`              | Exact named-class declaration, rank `Class`                                                                                 | Its source range           | Yes                                                                                       |
| promoted `Param`                                       | Exact constructor callable at rank `Callable`; promotion does not create a property subject or synthetic `Property` control | The parameter source range | No independent threshold record; it inherits the constructor callable/class configuration |
| non-promoted `Param`                                   | Owning callable                                                                                                             | The parameter source range | No independent threshold record                                                           |
| `EnumCase` and `ClassConst`                            | No new subject; their owning class remains the declaration subject                                                          | Their own source range     | No                                                                                        |
| `Expression`                                           | No declaration rank                                                                                                         | `NextLine` only            | No                                                                                        |
| file-leading comment                                   | No declaration rank                                                                                                         | `File` only                | No                                                                                        |

Resolution uses the explicit bound declaration and exactly these applicability
chains:

```text
property hook -> hook -> declaring property -> enclosing class -> config
method or anonymous callable in a class -> callable -> enclosing class -> config
global function or global anonymous callable -> callable -> config
named class -> class -> config
```

Nested declaration metadata transports both the exact finding declaration and
its enclosing named-class binding. Range size only breaks a tie between two
controls at the same applicable step. Property-scoped controls are expanded to
their hook subjects during extraction under the zero-hook rules specified in
Slice A0. `AbstractRule` is the sole threshold/options seam:
rules pass `MetricSubject`, not `(file,line)`, and it asks `AnalysisContext` for
the ranked binding. `SuppressionFilter` first evaluates subject-bound symbol
controls, then independently evaluates physical file/next-line controls.
Direct threshold and suppression regressions cover every chain's most-specific
winner and fallback to each next step, including hook-to-property,
property-to-class, callable-to-class, class-to-config, and global
callable-to-config.

The baseline file contract advances explicitly to **version 11**. Its complete
grouping tuple is channel, canonical `MetricSubject`, optional occurrence key,
and optional logical edge. A v10
file is rejected at the envelope with consumer-facing guidance: declaration
identity cannot be inferred safely from a logical symbol key, so the consumer
must run a fresh analysis, map/split accepted entries deliberately, and write a
new v11 file (or regenerate and review the accepted state). There is no
automatic v10 conversion. The existing v5-to-v10 reader/migrator remains
historical and is not reused, relabelled, or routed as v10-to-v11; the current
CLI must not suggest it for a v10 file.

```text
BaselineIdentity::__construct(
    string $subjectKey,
    ViolationChannel $channel,
    ?string $occurrenceKey = null,
    ?BaselineEdge $edge = null,
)
BaselineIdentity::forViolation(Violation $violation): BaselineIdentity
```

The v10 `symbolKey` name is removed from the v11 in-memory contract; the writer
groups entries by `subjectKey`, and the loader treats that outer key as an
opaque canonical typed-subject string.

`LayerViolationRule` keeps policy matching on logical source/target paths and
keeps the dependency use-site `Location`, but projects finding identity as
follows:

```text
ownedTargets = repository declarations for dependency.targetLogical
if ownedTargets is empty:
    emit exactly one finding whose subject is dependency.source declaration
else:
    emit exactly one finding for each exact owned target declaration
// ... preserve logical policy matching, message, dependency target and type
```

Each emitted declaration is controlled independently. An external/unowned
target therefore remains attributable to the exact source; one or more owned
targets produce one finding per exact target declaration. Graph JSON/DOT remain
logical projections. The breaking control matrix is literal:

| Owned target count | Finding subject                                | Declaration controls                                                                             | Physical controls                                                                                 |
| ------------------ | ---------------------------------------------- | ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------- |
| 0                  | Exact source declaration                       | Source hook/property/callable/class controls                                                     | `NextLine` and `File` use the dependency use-site location/file                                   |
| 1                  | The exact owned target declaration             | Target hook/property/callable/class controls; a source symbol suppression does not match         | `NextLine` and `File` still use the dependency use-site location/file                             |
| N                  | One finding per exact owned target declaration | Each target resolves controls independently; a source symbol suppression suppresses none of them | The one use-site `NextLine`/`File` suppression applies to all N projections of that physical edge |

Every architecture finding additionally uses a semantic occurrence key made
from exact source canonical, logical target canonical, dependency type, and
projected target canonical when present. Repeated identical semantic edges are
one count-bounded identity; the use-site line is presentation only.

CBO, ClassRank, Instability, every other class-score rule, and the class branch
of `ComputedMetricRule` emit exact declaration subjects in P5. Existing logical
scores are looked up once and projected to all owned declarations here; P6
changes only calculation, source/target deduplication, and degree-zero
semantics. No P5 target-state finding uses `MetricSubject::logicalClass()`.
`CircularDependencyRule` is a graph-group finding: it uses the project
aggregate subject plus an occurrence key over the sorted complete list of
logical cycle member canonicals. This avoids an arbitrary representative
declaration and remains stable when an unrelated class is added; member names
remain the display projection.

`CodeDuplicationRule` is also a project-group finding. `DuplicateBlockFinder`
computes a full SHA-256 over the complete normalized matched token sequence and
token count; `DuplicateBlock` transports that value as the occurrence key. The
primary file remains display/location only. A primary-file subject was rejected
because adding a lexically earlier related copy would change identity. Location
order and related siblings do not enter the key; full-length hashing avoids a
truncation collision surface, and a synthetic collision regression proves two
different normalized sequences do not collapse in the producer contract.

For the five scalar file-entry chains the baseline/fingerprint tuple is always
`channel + subject + optional occurrence + optional edge`. Channel still owns
rule/type semantics; occurrence only distinguishes semantic evidence inside
that channel:

| Producer -> payload -> collector -> rule chain                                                                                          | Channel already distinguishes            | Exact occurrence evidence                                                    | Always ignored                                                         |
| --------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- | ---------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `CodeSmellVisitor` -> `CodeSmellLocation` -> `CodeSmellCollector` -> `AbstractCodeSmellRule`                                            | Concrete smell rule/type                 | `type`, nullable `extra`, nullable `promoted`                                | `line`, `column`, location, traversal/discovery order, message         |
| `IdenticalSubExpressionVisitor` -> `IdenticalSubExpressionFinding` -> `IdenticalSubExpressionCollector` -> `IdenticalSubExpressionRule` | One identical-subexpression channel only | finding `type` and `detail`                                                  | `line`, location, traversal/discovery order, message                   |
| `SecurityPatternVisitor` -> `SecurityPatternLocation` -> `SecurityPatternCollector` -> `AbstractSecurityPatternRule`                    | Concrete security rule/type              | pattern `type` and extracted `superglobal`; raw detector context is excluded | `line`, raw context, location, traversal/discovery order, message      |
| `HardcodedCredentialsVisitor` -> `CredentialLocation` -> `HardcodedCredentialsCollector` -> `HardcodedCredentialsRule`                  | Hardcoded-credentials channel            | `pattern` only; the current VO has no name and secret values are forbidden   | `line`, credential value, location, traversal/discovery order, message |
| `SensitiveParameterVisitor` -> `SensitiveParameterLocation` -> `SensitiveParameterCollector` -> `SensitiveParameterRule`                | Sensitive-parameter channel              | `paramName`, which the collector must now retain                             | `line`, location, traversal/discovery order, message                   |

Two occurrences with the same channel, subject, and exact evidence
intentionally share one occurrence key and remain distinguished by baseline
count. This is deliberate grouping, not a hash accident. Full SHA-256 makes a
cryptographic collision a documented residual risk; digest truncation is
forbidden.

Generic JSON violation objects expose `subject` (canonical typed identity),
`channel`, nullable `occurrence`, nullable structured `edge`, and `symbol`
(logical display). HTML violation objects
likewise expose `subject`/`occurrence` and retain `symbolPath` as display/tree
attachment. GitLab and SARIF use the canonical identity fingerprint. Checkstyle and GitHub Actions remain
location projections, Metrics JSON remains a metric-repository projection, and
Summary/Text/Health remain intentional logical aggregate/display projections.
Shape tests pin each of those choices.

The complete 11-formatter projection inventory is:

| Formatter file                                           | P5 projection                                                                        |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `src/Reporting/Formatter/CheckstyleFormatter.php`        | Physical location and logical display; no independent identity field                 |
| `src/Reporting/Formatter/GitLabCodeQualityFormatter.php` | Canonical channel/subject/occurrence/edge fingerprint plus physical location         |
| `src/Reporting/Formatter/GithubActionsFormatter.php`     | Physical workflow annotation and logical display                                     |
| `src/Reporting/Formatter/Health/HealthTextFormatter.php` | Logical aggregate health projection                                                  |
| `src/Reporting/Formatter/Html/HtmlFormatter.php`         | Canonical subject/occurrence in violation payload; logical tree attachment           |
| `src/Reporting/Formatter/Json/JsonFormatter.php`         | Canonical channel/subject/occurrence/edge plus logical symbol                        |
| `src/Reporting/Formatter/MetricsJsonFormatter.php`       | Metric-repository logical/aggregate export, not finding identity                     |
| `src/Reporting/Formatter/Sarif/SarifFormatter.php`       | Canonical channel/subject/occurrence/edge partial fingerprint plus physical location |
| `src/Reporting/Formatter/Summary/SummaryFormatter.php`   | Logical aggregate/display projection                                                 |
| `src/Reporting/Formatter/TextFormatter.php`              | Logical display plus physical location                                               |
| `src/Reporting/Formatter/TextVerboseFormatter.php`       | Logical display plus physical and related locations                                  |

#### Complete violation-constructor inventory

The 29 layered production files containing every current `new Violation(` site
are, literally:

```text
src/Rules/CodeSmell/AbstractCodeSmellRule.php
src/Rules/CodeSmell/ConstructorOverinjectionRule.php
src/Rules/CodeSmell/IdenticalSubExpressionRule.php
src/Rules/CodeSmell/LongParameterListRule.php
src/Rules/CodeSmell/UnreachableCodeRule.php
src/Rules/CodeSmell/UnusedPrivateRule.php
src/Rules/Complexity/CognitiveComplexityRule.php
src/Rules/Complexity/ComplexityRule.php
src/Rules/Complexity/NpathComplexityRule.php
src/Rules/ComputedMetric/ComputedMetricRule.php
src/Rules/Coupling/CboRule.php
src/Rules/Coupling/ClassRankRule.php
src/Rules/Coupling/DistanceRule.php
src/Rules/Coupling/InstabilityRule.php
src/Rules/Design/DataClassRule.php
src/Rules/Design/GodClassRule.php
src/Rules/Design/TypeCoverageRule.php
src/Rules/Duplication/CodeDuplicationRule.php
src/Rules/Maintainability/MaintainabilityRule.php
src/Rules/Security/AbstractSecurityPatternRule.php
src/Rules/Security/HardcodedCredentialsRule.php
src/Rules/Security/SensitiveParameterRule.php
src/Rules/Size/ClassCountRule.php
src/Rules/Size/MethodCountRule.php
src/Rules/Size/PropertyCountRule.php
src/Rules/Structure/InheritanceRule.php
src/Rules/Structure/LcomRule.php
src/Rules/Structure/NocRule.php
src/Rules/Structure/WmcRule.php
```

The two Architecture constructor owners are
`src/Architecture/Rules/CircularDependencyRule.php` and
`src/Architecture/Rules/LayerViolationRule.php`. The two Analysis diagnostics
are owned by `src/Analysis/Pipeline/AnalysisPipeline.php`. No other production
constructor is admitted without first appending its exact path here.

The subject projection matrix for this inventory is:

| Emission set                                                                                                                                                                                                                                                    | Subject in P5                                                                                                                                                               |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Callable findings in `ComplexityRule`, `CognitiveComplexityRule`, `NpathComplexityRule`, `MaintainabilityRule`, and `ConstructorOverinjectionRule`                                                                                                              | Exact callable declaration from `allCallables()` / `SymbolInfo::subject`                                                                                                    |
| Callable/function findings in `LongParameterListRule` and `UnreachableCodeRule`                                                                                                                                                                                 | Exact callable declaration; functions do not gain a class owner                                                                                                             |
| Class findings in `ComplexityRule`, `CognitiveComplexityRule`, `NpathComplexityRule`, `UnusedPrivateRule`, `MethodCountRule`, `PropertyCountRule`, `DataClassRule`, `GodClassRule`, `TypeCoverageRule`, `InheritanceRule`, `LcomRule`, `NocRule`, and `WmcRule` | Exact named-class declaration from `allDeclarations()`; duplicate logical classes remain separate                                                                           |
| `AbstractCodeSmellRule`, `IdenticalSubExpressionRule`, `AbstractSecurityPatternRule`, `HardcodedCredentialsRule`, and `SensitiveParameterRule`                                                                                                                  | Exact declaration transported in the scalar DataBag entry; genuinely top-level occurrences use the file aggregate subject; semantic entry evidence supplies `occurrenceKey` |
| `ClassCountRule`, `DistanceRule`, non-class branches of `ComputedMetricRule`, and namespace/project branches of the listed multi-level rules                                                                                                                    | The exact file/namespace/project aggregate subject already being measured                                                                                                   |
| Class branch of `ComputedMetricRule`; class branches of `CboRule`, `ClassRankRule`, and `InstabilityRule`                                                                                                                                                       | One exact named-class declaration finding per owned declaration while reading the existing logical score; P6 owns calculation changes only                                  |
| `CodeDuplicationRule`                                                                                                                                                                                                                                           | Project aggregate subject plus normalized-content occurrence key; primary/related locations are presentation-only                                                           |
| `CircularDependencyRule`                                                                                                                                                                                                                                        | Project aggregate subject plus sorted-complete-member occurrence key; cycle graph semantics remain logical                                                                  |
| `LayerViolationRule`                                                                                                                                                                                                                                            | Exact source declaration for zero owned targets; one exact target declaration per owned target for one or more, plus semantic edge occurrence key                           |
| Unsupported-threshold and malformed-threshold diagnostics in `AnalysisPipeline`                                                                                                                                                                                 | Exact declaration bound by extraction when available, otherwise the file aggregate subject                                                                                  |

The inherited emitters are part of the same inventory even though their
constructor is physically in a base class. The nine code-smell classes are
`BooleanArgumentRule`, `CountInLoopRule`, `DebugCodeRule`, `EmptyCatchRule`,
`ErrorSuppressionRule`, `EvalRule`, `ExitRule`, `GotoRule`, and
`SuperglobalsRule`. The three security classes are `CommandInjectionRule`,
`SqlInjectionRule`, and `XssRule`. Their literal production paths and direct
rule regressions are owned exactly once by the file-entry transport slice
below. Mechanically, the 29 layered constructor-owner
files contain two abstract bases; replacing those two with their 12 concrete
inheritors yields all 39 concrete layered rules. Adding the two Architecture
rules yields the complete 41-rule inventory.

#### P5-A — one atomic compile-complete identity/control/rule wave

**Dependencies:** P4. This is one atomic package split into file-isolated
slices only to make ownership reviewable. Execution is sequential in the
literal order A0, A1, A2, A3, A4, A5; no slice is claimed to compile independently
and no slice is validated as a deliverable. The combined wave is validated only
after all slices land. `VisitorMethodTrackingTrait`, `FileProcessor`,
`AnalysisPipeline`, and the three complexity rules overlap P2/P4 work and are
edited sequentially after those packages. The corresponding sequential test
overlaps are `CollectionOrchestratorTest`, `FileProcessorTest`,
`AnalysisPipelineTest`, `FileProcessingResultWireFormatTest`,
`CognitiveComplexityRuleTest`, `ComplexityRuleTest`, and
`NpathComplexityRuleTest`; their literal paths appear once in the slice lists
below. Agents must not run whole-tree git operations.

The complete sequential overlap audit is literal. P2 already edits the
following files, so P5-A must start from the verified P2 result and must not be
run in parallel with it:

```text
src/Metrics/VisitorMethodTrackingTrait.php
src/Metrics/CodeSmell/CodeSmellCollector.php
src/Metrics/CodeSmell/CodeSmellVisitor.php
src/Metrics/CodeSmell/IdenticalSubExpressionCollector.php
src/Metrics/CodeSmell/IdenticalSubExpressionVisitor.php
src/Metrics/Security/HardcodedCredentialsCollector.php
src/Metrics/Security/HardcodedCredentialsVisitor.php
src/Metrics/Security/SecurityPatternCollector.php
src/Metrics/Security/SecurityPatternVisitor.php
src/Metrics/Security/SensitiveParameterCollector.php
src/Metrics/Security/SensitiveParameterVisitor.php
src/Rules/Coupling/CboRule.php
```

The previously identified P2/P4 sequential overlaps are also owned only by
their P5-A slice during this wave:

```text
src/Analysis/Collection/FileProcessor.php
src/Analysis/Pipeline/AnalysisPipeline.php
src/Rules/Complexity/CognitiveComplexityRule.php
src/Rules/Complexity/ComplexityRule.php
src/Rules/Complexity/NpathComplexityRule.php
tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php
tests/Unit/Analysis/Collection/FileProcessorTest.php
tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php
tests/Unit/Infrastructure/Parallel/FileProcessingResultWireFormatTest.php
tests/Unit/Rules/Complexity/CognitiveComplexityRuleTest.php
tests/Unit/Rules/Complexity/ComplexityRuleTest.php
tests/Unit/Rules/Complexity/NpathComplexityRuleTest.php
```

Exactly these eight listed package paths are absent at planning time and are
created by their owning P5-A slice; every other listed production/test path
already exists:

```text
src/Core/Suppression/ControlScope.php
src/Core/Violation/OccurrenceKey.php
src/Core/Symbol/MetricSubjectCodec.php
tests/Unit/Core/Duplication/DuplicateBlockIdentityTest.php
tests/Unit/Core/Symbol/MetricSubjectCodecTest.php
tests/Unit/Core/Violation/OccurrenceKeyTest.php
tests/Unit/Rules/AbstractRuleSubjectControlTest.php
tests/Integration/Rules/PropertyHookControlPrecedenceTest.php
```

**Slice A0 — identity, controls, and Analysis diagnostics. Production files:**

```text
src/Core/Violation/Violation.php
src/Core/Violation/OccurrenceKey.php
src/Core/Rule/AnalysisContext.php
src/Core/Suppression/ControlScope.php
src/Core/Suppression/Suppression.php
src/Core/Suppression/ThresholdDiagnostic.php
src/Core/Suppression/ThresholdOverride.php
src/Analysis/Collection/FileProcessor.php
src/Analysis/Pipeline/AnalysisPipeline.php
src/Rules/AbstractRule.php
src/Baseline/Suppression/SuppressionExtractor.php
src/Baseline/Suppression/SuppressionFilter.php
src/Baseline/Suppression/ThresholdOverrideExtractor.php
src/Baseline/Suppression/ThresholdOverrideExtractionResult.php
```

**Slice A0 tests:**

```text
tests/Unit/Core/Violation/ViolationTest.php
tests/Unit/Core/Violation/OccurrenceKeyTest.php
tests/Unit/Core/Suppression/SuppressionTest.php
tests/Unit/Core/Suppression/ThresholdOverrideTest.php
tests/Unit/Core/Rule/AnalysisContextThresholdTest.php
tests/Unit/Analysis/Collection/FileProcessorTest.php
tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php
tests/Unit/Baseline/Suppression/SuppressionExtractorTest.php
tests/Unit/Baseline/Suppression/SuppressionFilterTest.php
tests/Unit/Baseline/Suppression/ThresholdOverrideExtractorTest.php
tests/Unit/Rules/ThresholdOverrideIntegrationTest.php
tests/Unit/Rules/AbstractRuleSubjectControlTest.php
tests/Integration/Baseline/ThresholdAnnotationParserPathTest.php
tests/Integration/Rules/PropertyHookControlPrecedenceTest.php
```

`ThresholdOverrideExtractorTest` is the direct
`ThresholdDiagnostic` contract test. Extraction binds both a valid
`ThresholdOverride` and every malformed `ThresholdDiagnostic` to a mandatory
subject before either VO enters collection transport. Property-scoped
suppression and threshold annotations are expanded to one binding per declared
hook while retaining rank `Property`. A valid property threshold annotation
with zero declared hooks produces no declaration override because property is
not a subject; a malformed annotation still produces exactly one mandatory diagnostic bound to the enclosing
named-class declaration or, when none exists, the file aggregate subject.
`CollectedFileData`,
`FileProcessingResult`, and `CollectionResult` already transport typed VO lists
without projection and require no production edit; `FileProcessorTest`,
`CollectionOrchestratorTest`, and `FileProcessingResultWireFormatTest` are
validation witnesses for preservation through sequential and parallel paths.
The unsupported-override diagnostic in `AnalysisPipeline` consumes the bound
override subject; malformed-diagnostic emission consumes the bound diagnostic
subject. Neither reconstructs identity from file/line.

The occurrence, AbstractRule, and property-hook tests are new. Together they
freeze scalar semantic-key canonicalization, the full AST ingress matrix,
hook/property/callable-or-class/config precedence for both threshold and
suppression controls, equal-rank ties, and the separation between
subject-bound and physical dependency-use-site controls.

**Slice A1 — scalar file-entry subject/occurrence transport. Production
files:**

```text
src/Core/Symbol/MetricSubjectCodec.php
src/Metrics/VisitorMethodTrackingTrait.php
src/Metrics/CodeSmell/CodeSmellCollector.php
src/Metrics/CodeSmell/CodeSmellLocation.php
src/Metrics/CodeSmell/CodeSmellVisitor.php
src/Metrics/CodeSmell/IdenticalSubExpressionCollector.php
src/Metrics/CodeSmell/IdenticalSubExpressionFinding.php
src/Metrics/CodeSmell/IdenticalSubExpressionVisitor.php
src/Metrics/Security/CommandInjectionDetector.php
src/Metrics/Security/CredentialLocation.php
src/Metrics/Security/HardcodedCredentialsCollector.php
src/Metrics/Security/HardcodedCredentialsVisitor.php
src/Metrics/Security/SecurityPatternCollector.php
src/Metrics/Security/SecurityPatternLocation.php
src/Metrics/Security/SecurityPatternVisitor.php
src/Metrics/Security/SensitiveParameterCollector.php
src/Metrics/Security/SensitiveParameterLocation.php
src/Metrics/Security/SensitiveParameterVisitor.php
src/Metrics/Security/SqlInjectionDetector.php
src/Metrics/Security/XssDetector.php
src/Rules/CodeSmell/AbstractCodeSmellRule.php
src/Rules/CodeSmell/BooleanArgumentRule.php
src/Rules/CodeSmell/CountInLoopRule.php
src/Rules/CodeSmell/DebugCodeRule.php
src/Rules/CodeSmell/EmptyCatchRule.php
src/Rules/CodeSmell/ErrorSuppressionRule.php
src/Rules/CodeSmell/EvalRule.php
src/Rules/CodeSmell/ExitRule.php
src/Rules/CodeSmell/GotoRule.php
src/Rules/CodeSmell/IdenticalSubExpressionRule.php
src/Rules/CodeSmell/SuperglobalsRule.php
src/Rules/Security/AbstractSecurityPatternRule.php
src/Rules/Security/CommandInjectionRule.php
src/Rules/Security/HardcodedCredentialsRule.php
src/Rules/Security/SensitiveParameterRule.php
src/Rules/Security/SqlInjectionRule.php
src/Rules/Security/XssRule.php
```

`MetricSubjectCodec` is a scalar component codec, not a collector-interface
change. Its exact DataBag schema is:

```text
MetricSubjectCodec::encodeFile(): array<string, int|string>
MetricSubjectCodec::encodeClass(
    string $namespace,
    string $class,
    int $startFilePos,
    ?int $collisionOrdinal = null,
): array<string, int|string>
MetricSubjectCodec::encodeMethod(
    string $namespace,
    string $class,
    string $member,
    int $startFilePos,
    ?int $collisionOrdinal = null,
): array<string, int|string>
MetricSubjectCodec::encodeFunction(
    string $namespace,
    string $member,
    int $startFilePos,
    ?int $collisionOrdinal = null,
): array<string, int|string>
MetricSubjectCodec::decode(
    array<string, int|string> $components,
    RelativePath $containerFile,
): MetricSubject
```

Every emitted component value is a string or integer; `null` is forbidden.
The nullable method argument means “omit `collisionOrdinal`”, never “serialize
a null”. An empty global namespace is encoded as the empty string. The complete
key matrix is:

| Shape                | Required keys                                                                                   | Optional keys      | Forbidden keys                                                                    |
| -------------------- | ----------------------------------------------------------------------------------------------- | ------------------ | --------------------------------------------------------------------------------- |
| file                 | `subjectKind=file`                                                                              | none               | `logicalKind`, `namespace`, `class`, `member`, `startFilePos`, `collisionOrdinal` |
| declaration class    | `subjectKind=declaration`, `logicalKind=class`, `namespace`, `class`, `startFilePos`            | `collisionOrdinal` | `member`                                                                          |
| declaration method   | `subjectKind=declaration`, `logicalKind=method`, `namespace`, `class`, `member`, `startFilePos` | `collisionOrdinal` | none                                                                              |
| declaration function | `subjectKind=declaration`, `logicalKind=function`, `namespace`, `member`, `startFilePos`        | `collisionOrdinal` | `class`                                                                           |

`decode()` receives only this extracted component map, not the surrounding
entry's occurrence-evidence fields. It fails fast on a null/non-scalar value,
unknown key, missing required key, forbidden key, invalid discriminator, or
wrong scalar type. `collisionOrdinal` is omitted when absent and must be a
non-negative integer when present.

Visitors and collectors transport only those named scalar components plus
semantic occurrence evidence. They do not put `RelativePath`, `MetricSubject`,
or another object into `DataBag`; `CompositeCollector` and
`MetricCollectorInterface` therefore do not change. At rule execution, the
current file `SymbolInfo` container supplies the authoritative
`$fileInfo->file` argument and the rule decodes the subject from that file plus
the transported components. No absolute path, current working directory,
source line, presentation `Location`, or repository search participates in
decode. `subjectKind=file` admits no declaration-component keys and decodes to
the aggregate for `$containerFile`; top-level occurrences and
occurrences owned only by an anonymous class use this form. Named declarations
use `logicalKind`, namespace/class/member, `startFilePos`, and the optional
same-position collision ordinal installed by `VisitorMethodTrackingTrait`.
Credential evidence hashes patterns but never secret values. Identical scalar
evidence intentionally shares an occurrence group and is count-bounded.

**Slice A1 tests:**

```text
tests/Unit/Core/Symbol/MetricSubjectCodecTest.php
tests/Unit/Metrics/CodeSmell/CodeSmellCollectorTest.php
tests/Unit/Metrics/CodeSmell/CodeSmellVisitorBooleanArgumentTest.php
tests/Unit/Metrics/CodeSmell/CodeSmellVisitorTest.php
tests/Unit/Metrics/CodeSmell/IdenticalSubExpressionCollectorTest.php
tests/Unit/Metrics/CodeSmell/IdenticalSubExpressionVisitorTest.php
tests/Unit/Metrics/Security/CommandInjectionDetectorTest.php
tests/Unit/Metrics/Security/HardcodedCredentialsCollectorTest.php
tests/Unit/Metrics/Security/HardcodedCredentialsVisitorTest.php
tests/Unit/Metrics/Security/SecurityPatternCollectorTest.php
tests/Unit/Metrics/Security/SecurityPatternVisitorTest.php
tests/Unit/Metrics/Security/SensitiveParameterCollectorTest.php
tests/Unit/Metrics/Security/SensitiveParameterVisitorTest.php
tests/Unit/Metrics/Security/SqlInjectionDetectorTest.php
tests/Unit/Metrics/Security/XssDetectorTest.php
tests/Unit/Rules/CodeSmell/BooleanArgumentRuleTest.php
tests/Unit/Rules/CodeSmell/CountInLoopRuleTest.php
tests/Unit/Rules/CodeSmell/DebugCodeRuleTest.php
tests/Unit/Rules/CodeSmell/EmptyCatchRuleTest.php
tests/Unit/Rules/CodeSmell/ErrorSuppressionRuleTest.php
tests/Unit/Rules/CodeSmell/EvalRuleTest.php
tests/Unit/Rules/CodeSmell/ExitRuleTest.php
tests/Unit/Rules/CodeSmell/GotoRuleTest.php
tests/Unit/Rules/CodeSmell/IdenticalSubExpressionRuleTest.php
tests/Unit/Rules/CodeSmell/SuperglobalsRuleTest.php
tests/Unit/Rules/Security/CommandInjectionRuleTest.php
tests/Unit/Rules/Security/HardcodedCredentialsRuleTest.php
tests/Unit/Rules/Security/SensitiveParameterRuleTest.php
tests/Unit/Rules/Security/SqlInjectionRuleTest.php
tests/Unit/Rules/Security/XssRuleTest.php
tests/Integration/Rules/CodeSmellLinePrecisionTest.php
tests/Integration/Rules/CodeSmellRuleContractTest.php
tests/Unit/Infrastructure/Parallel/FileProcessingResultWireFormatTest.php
tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php
```

Direct regressions cover callable, class initializer, property initializer,
property hook, global/top-level, enum case, class constant, ordinary parameter,
and promoted-parameter producers; scalar serialization survives sequential and
parallel transport; two identical semantic occurrences share count identity
without a line-derived lookup, while different semantic evidence does not. A
same-position collision fixture proves the scalar entry's decoded declaration
canonical is byte-identical to the declaration stored by the callable
repository path. Codec tests also prove that decode requires the caller's
authoritative `RelativePath`, that no file field is admitted in the scalar
entry, and that top-level/anonymous-class-unowned entries become file
aggregates without cwd, absolute-path, line, or location fallback. The direct
codec test has one valid fixture for each of file, declaration-class,
declaration-method, and declaration-function shapes, plus rejection fixtures
for null, unknown, missing, forbidden, wrong-type, and invalid-discriminator
inputs; it also proves an absent collision ordinal is omitted on encode.

**Slice A2 — remaining code-smell constructors. Production files:**

```text
src/Rules/CodeSmell/ConstructorOverinjectionRule.php
src/Rules/CodeSmell/LongParameterListRule.php
src/Rules/CodeSmell/UnreachableCodeRule.php
src/Rules/CodeSmell/UnusedPrivateRule.php
```

**Slice A2 tests:**

```text
tests/Integration/Rules/LongParameterListVoPropagationTest.php
tests/Unit/Rules/CodeSmell/ConstructorOverinjectionRuleTest.php
tests/Unit/Rules/CodeSmell/LongParameterListRuleTest.php
tests/Unit/Rules/CodeSmell/UnreachableCodeRuleTest.php
tests/Unit/Rules/CodeSmell/UnusedPrivateRuleTest.php
```

**Slice A3 — metric, design, size, and structure constructors. Production
files:**

`CboRule`, `ClassRankRule`, `DistanceRule`, and `InstabilityRule` are deliberate
sequential overlaps with P6: P5 installs their final exact finding identity;
P6 later changes calculation/deduplication inputs without changing that identity.

```text
src/Rules/Complexity/CognitiveComplexityRule.php
src/Rules/Complexity/ComplexityRule.php
src/Rules/Complexity/NpathComplexityRule.php
src/Rules/ComputedMetric/ComputedMetricRule.php
src/Rules/Coupling/CboRule.php
src/Rules/Coupling/ClassRankRule.php
src/Rules/Coupling/DistanceRule.php
src/Rules/Coupling/InstabilityRule.php
src/Rules/Design/DataClassRule.php
src/Rules/Design/GodClassRule.php
src/Rules/Design/TypeCoverageRule.php
src/Rules/Maintainability/MaintainabilityRule.php
src/Rules/Size/ClassCountRule.php
src/Rules/Size/MethodCountRule.php
src/Rules/Size/PropertyCountRule.php
src/Rules/Structure/InheritanceRule.php
src/Rules/Structure/LcomRule.php
src/Rules/Structure/NocRule.php
src/Rules/Structure/WmcRule.php
```

**Slice A3 tests:**

```text
tests/Unit/Rules/Complexity/CognitiveComplexityRuleTest.php
tests/Unit/Rules/Complexity/ComplexityRuleTest.php
tests/Unit/Rules/Complexity/NpathComplexityRuleTest.php
tests/Unit/Rules/ComputedMetric/ComputedMetricRuleTest.php
tests/Unit/Rules/Coupling/CboRuleTest.php
tests/Unit/Rules/Coupling/ClassRankRuleTest.php
tests/Unit/Rules/Coupling/DistanceRuleTest.php
tests/Unit/Rules/Coupling/InstabilityRuleTest.php
tests/Unit/Rules/Design/DataClassRuleTest.php
tests/Unit/Rules/Design/GodClassRuleTest.php
tests/Unit/Rules/Design/TypeCoverageRuleTest.php
tests/Unit/Rules/Maintainability/MaintainabilityRuleTest.php
tests/Unit/Rules/Size/ClassCountRuleTest.php
tests/Unit/Rules/Size/MethodCountRuleTest.php
tests/Unit/Rules/Size/PropertyCountRuleTest.php
tests/Unit/Rules/Structure/InheritanceRuleTest.php
tests/Unit/Rules/Structure/LcomRuleTest.php
tests/Unit/Rules/Structure/NocRuleTest.php
tests/Unit/Rules/Structure/WmcRuleTest.php
```

**Slice A4 — duplication group identity. Production files:**

```text
src/Core/Duplication/DuplicateBlock.php
src/Analysis/Duplication/DuplicateBlockFinder.php
src/Rules/Duplication/CodeDuplicationRule.php
```

**Slice A4 tests:**

```text
tests/Unit/Core/Duplication/DuplicateBlockIdentityTest.php
tests/Unit/Analysis/Duplication/DuplicationDetectorTest.php
tests/Unit/Rules/Duplication/CodeDuplicationRuleTest.php
tests/Integration/Violation/ChannelCoverageTest.php
```

The finder hashes the complete normalized matched token sequence, not the hint
or locations; the VO preserves it; the rule uses project subject plus that
key. Regressions permute locations, add a lexically earlier sibling, use equal
line/token counts with different normalized content, and inject two distinct
full digests to prove identity follows content rather than presentation.

**Slice A5 — Architecture constructors and LayerViolation projection.
Production files:**

```text
src/Architecture/Rules/CircularDependencyRule.php
src/Architecture/Rules/LayerViolationRule.php
```

**Slice A5 tests:**

```text
tests/Architecture/Integration/CaptureBindingIntegrationTest.php
tests/Architecture/Integration/InlineSuppressionLayerViolationIntegrationTest.php
tests/Architecture/Integration/LayerCriteriaIntegrationTest.php
tests/Architecture/Integration/LayerExcludeIntegrationTest.php
tests/Architecture/Integration/LayerTemplateExpansionIntegrationTest.php
tests/Architecture/Integration/LayerViolationIntegrationTest.php
tests/Architecture/Integration/RelationsFilterIntegrationTest.php
tests/Architecture/Unit/Domain/Allow/LayerSelectorTest.php
tests/Architecture/Unit/Domain/Layer/ClassContextFactoryTest.php
tests/Architecture/Unit/Domain/Layer/LayerPolicyTest.php
tests/Architecture/Unit/Domain/Layer/LayerRegistryTest.php
tests/Architecture/Unit/Processing/LayerExpansionStageTest.php
tests/Architecture/Unit/Rules/CircularDependencyRuleTest.php
tests/Architecture/Unit/Rules/CoverageDiagnosticsTest.php
tests/Architecture/Unit/Rules/LayerViolationOptionsTest.php
tests/Architecture/Unit/Rules/LayerViolationRuleTest.php
```

Only the two rule files require production edits: P2 already installed exact
dependency sources, owned-declaration lookup, and unchanged logical policy
matching. The selector, class-context, registry, policy, membership, and
expansion tests above are validation-only witnesses; their corresponding
production files are not owned or edited in P5.

**Combined Wave A DoD:** all 29 layered and both Architecture constructor
owners pass a mandatory non-null `MetricSubject`; the Analysis diagnostics do
the same. The current raw `rg 'new Violation\(' src --glob '*.php'` inventory
has 45 textual matches, of which two are docblock examples; an AST-aware or anchored
executable inventory must find exactly 43 constructor expressions and every
expression must belong to the 29 layered files, two Architecture files, or one
Analysis file enumerated above. Raw comment-inclusive count is not accepted as
compile-completeness evidence. The 12 inherited emitters consume the scalar subject payload
without a line/location lookup. Callable and every class-score rule prove
duplicate logical declarations yield independent subjects; a target-state
search finds no `MetricSubject::logicalClass()` in finding construction.
Occurrence-bearing file entries, duplication groups, cycles, and architecture
edges have distinct semantic keys with no location component. Hook controls use
explicit ranks and the full AST matrix, including target-subject controls with
dependency use-site presentation. LayerViolation freezes 0, 1, and multiple
owned-target cases, source-symbol non-suppression for owned targets, physical
next-line/file behavior, and unchanged logical relation matching. Circular
dependency findings are project-group identities while graph export remains
logical.
Run the listed tests together, then `composer phpstan`, `composer cs-check`, and
`git diff --check`; no slice-level green claim is accepted before this combined
validation.

#### P5-B — baseline v11 schema and lifecycle

**Depends on:** complete P5-A. **Production files:**

```text
src/Baseline/Baseline.php
src/Baseline/BaselineCapture.php
src/Baseline/BaselineCleaner.php
src/Baseline/BaselineEntry.php
src/Baseline/BaselineEntryParser.php
src/Baseline/BaselineGenerator.php
src/Baseline/BaselineIdentity.php
src/Baseline/BaselineLoader.php
src/Baseline/BaselineUpdater.php
src/Baseline/BaselineWriter.php
src/Baseline/BoundaryExplanation.php
src/Baseline/BoundaryExplanationService.php
src/Baseline/EffectiveBoundary.php
src/Baseline/EffectiveBoundaryBaselineSource.php
src/Baseline/EntrySelector.php
src/Baseline/Filter/BaselineCeilingStage.php
src/Baseline/InertBaselineEntry.php
src/Baseline/MigrationReport.php
src/Baseline/MigrationReportDroppedEntry.php
```

`src/Baseline/BaselineMigrator.php`, `src/Baseline/V5Baseline.php`,
`src/Baseline/V5BaselineReader.php`, `src/Baseline/V5Entry.php`, and
`src/Baseline/V5UnreadableRecord.php` are regression witnesses for the old
v5-to-v10 route, not implementation surfaces for v11; they may only receive
message/type fixes required to keep that historical boundary explicit.

**Tests:**

```text
tests/Unit/Baseline/BaselineCleanerTest.php
tests/Unit/Baseline/BaselineEntryParserTest.php
tests/Unit/Baseline/BaselineEntryTest.php
tests/Unit/Baseline/BaselineGeneratorTest.php
tests/Unit/Baseline/BaselineIdentityTest.php
tests/Unit/Baseline/BaselineLoaderTest.php
tests/Unit/Baseline/BaselineMigratorTest.php
tests/Unit/Baseline/BaselineRoundTripVOTest.php
tests/Unit/Baseline/BaselineTest.php
tests/Unit/Baseline/BaselineUpdaterTest.php
tests/Unit/Baseline/BaselineWriterTest.php
tests/Unit/Baseline/BoundaryExplanationServiceTest.php
tests/Unit/Baseline/EntrySelectorTest.php
tests/Unit/Baseline/Filter/BaselineCeilingStageAcceptanceTest.php
tests/Unit/Baseline/Filter/BaselineCeilingStageFailSafeTest.php
tests/Unit/Baseline/Filter/BaselineCeilingStageJudgeAllTest.php
tests/Unit/Baseline/Filter/BaselineCeilingStagePromotionTest.php
tests/Unit/Baseline/Filter/CeilingStageFixtures.php
tests/Unit/Baseline/GroupAcceptanceTest.php
tests/Unit/Baseline/RunScopeTest.php
tests/Unit/Baseline/V5BaselineReaderTest.php
tests/Integration/Baseline/BaselineWorkflowTest.php
tests/Integration/Baseline/CaptureFromMeasuredSetTest.php
```

**DoD:** writer/loader round-trip v11 subject and occurrence keys without logical collapse;
same-FQN declarations form separate entries; edge grouping remains logical and
typed; v10 is rejected before entry parsing with exact manual guidance; no
loader, writer, migrator, command hint, or test presents the v5-to-v10 migrator
as a v10-to-v11 path. An unrelated sibling does not change an existing subject
identity. Baseline grouping and `reportedAsBreach()` preserve the subject.
Run the listed tests, `composer phpstan`, `composer cs-check`, and
`git diff --check`. The tracked dogfooding v10 baseline is deliberately not
silently rewritten by the schema package; final selfcheck waits for the
reviewed P5-F migration.

#### P5-C — non-HTML wire projections

**Depends on:** P5-B and executes after it. **Production files:**

```text
src/Reporting/FormatterContext.php
src/Reporting/Formatter/CheckstyleFormatter.php
src/Reporting/Formatter/GitLabCodeQualityFormatter.php
src/Reporting/Formatter/GithubActionsFormatter.php
src/Reporting/Formatter/Json/JsonFormatter.php
src/Reporting/Formatter/Json/JsonViolationSection.php
src/Reporting/Formatter/MetricsJsonFormatter.php
src/Reporting/Formatter/Sarif/SarifFormatter.php
src/Reporting/Formatter/Sarif/SarifRuleCollector.php
```

**Tests:**

```text
tests/Unit/Reporting/FormatterContextTest.php
tests/Unit/Reporting/Formatter/CheckstyleFormatterTest.php
tests/Unit/Reporting/Formatter/GitLabCodeQualityFormatterTest.php
tests/Unit/Reporting/Formatter/GithubActionsFormatterTest.php
tests/Unit/Reporting/Formatter/Json/JsonViolationSectionTest.php
tests/Unit/Reporting/Formatter/JsonFormatterTest.php
tests/Unit/Reporting/Formatter/MetricsJsonFormatterTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifFormatterPosixSeparatorTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifRuleCollectorTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifSchemaValidationTest.php
tests/Unit/Reporting/Formatter/SarifFormatterTest.php
tests/Functional/Reporting/CoverageProjectionFormatterTest.php
tests/Functional/Reporting/JsonShapePreservationTest.php
```

**DoD:** GitLab and SARIF fingerprints are channel/subject/occurrence/edge based and
contain no location or message component; duplicate logical declarations have
different fingerprints; adding an unrelated sibling leaves existing values
unchanged. Generic JSON pins canonical `subject`, logical `symbol`, `channel`,
nullable `occurrence`, and nullable structured dependency `edge`; its stable
ordering is channel/subject/occurrence/edge and is directly consumable by the
same-run P5-F crosswalk without parsing the subject string.
Checkstyle/GitHub location shapes and Metrics JSON's logical repository export
are byte-stable apart from deliberately updated fixtures. Run the listed tests,
`composer phpstan`, `composer cs-check`, and `git diff --check`.

#### P5-D — HTML, health, summary, text, and report projections

**Depends on:** P5-C and executes after it. **Production files:**

```text
src/Reporting/Report.php
src/Reporting/ReportBuilder.php
src/Reporting/Formatter/Health/HealthTextFormatter.php
src/Reporting/Formatter/Html/HtmlFormatter.php
src/Reporting/Formatter/Html/HtmlTreeBuilder.php
src/Reporting/Formatter/Html/HtmlTreeNode.php
src/Reporting/Formatter/Html/HtmlViolationPartitioner.php
src/Reporting/Formatter/Summary/SummaryFormatter.php
src/Reporting/Formatter/Summary/TopIssuesRenderer.php
src/Reporting/Formatter/Summary/ViolationSummaryRenderer.php
src/Reporting/Formatter/Support/DetailedViolationRenderer.php
src/Reporting/Formatter/Support/ViolationSorter.php
src/Reporting/Formatter/TextFormatter.php
src/Reporting/Formatter/TextVerboseFormatter.php
```

**Tests:**

```text
tests/Unit/Reporting/ReportTest.php
tests/Unit/Reporting/ReportBuilderTest.php
tests/Unit/Reporting/Formatter/HealthTextFormatterTest.php
tests/Unit/Reporting/Formatter/Html/HtmlTreeBuilderTest.php
tests/Unit/Reporting/Formatter/Html/HtmlViolationPartitionerTest.php
tests/Unit/Reporting/Formatter/HtmlFormatterTest.php
tests/Unit/Reporting/Formatter/Summary/TopIssuesRendererTest.php
tests/Unit/Reporting/Formatter/Summary/ViolationSummaryRendererTest.php
tests/Unit/Reporting/Formatter/SummaryFormatterTest.php
tests/Unit/Reporting/Formatter/Support/DetailedViolationRendererTest.php
tests/Unit/Reporting/Formatter/Support/ViolationSorterTest.php
tests/Unit/Reporting/Formatter/TextFormatterTest.php
tests/Unit/Reporting/Formatter/TextVerboseFormatterTest.php
tests/Unit/Reporting/Formatter/ArchitectureViolationSmokeTest.php
```

**DoD:** Report transports the same `Violation` objects without reconstructing
identity; HTML pins canonical `subject` and nullable `occurrence` beside logical `symbolPath` and keeps
logical tree attachment; Health/Summary/Text sort, aggregate, and display by
their documented logical projection. Duplicate declarations are not lost from
HTML violation payloads. No template JavaScript contract changes; if an
implementation proves otherwise, its exact JS/template paths must be added to
this package before editing and `composer test:js` plus `composer build:js`
become mandatory. Otherwise run the listed PHP tests, `composer phpstan`,
`composer cs-check`, and `git diff --check`.

#### P5-E — console/baseline bridge

**Depends on:** P5-D and executes after it. **Production files:**

```text
src/Infrastructure/Console/FormatterContextFactory.php
src/Infrastructure/Console/MeasuredViolationSet.php
src/Infrastructure/Console/ViolationFilterOptions.php
src/Infrastructure/Console/ViolationFilterOrchestrator.php
src/Infrastructure/Console/ViolationFilterPipeline.php
src/Infrastructure/Console/ViolationFilterResult.php
src/Infrastructure/Console/Command/BaselineCaptureReporter.php
src/Infrastructure/Console/Command/BaselineCleanupCommand.php
src/Infrastructure/Console/Command/BaselineCommand.php
src/Infrastructure/Console/Command/BaselineCommandDefinition.php
src/Infrastructure/Console/Command/BaselineConfiguredThresholds.php
src/Infrastructure/Console/Command/BaselineExplainCommand.php
src/Infrastructure/Console/Command/BaselineGenerateCommand.php
src/Infrastructure/Console/Command/BaselineMigrateCommand.php
src/Infrastructure/Console/Command/BaselineRun.php
src/Infrastructure/Console/Command/BaselineRunContext.php
src/Infrastructure/Console/Command/BaselineRunInterface.php
src/Infrastructure/Console/Command/BaselineUpdateCommand.php
```

**Tests:**

```text
tests/Unit/Infrastructure/Console/FormatterContextFactoryTest.php
tests/Unit/Infrastructure/Console/MeasuredViolationSetTest.php
tests/Unit/Infrastructure/Console/ViolationFilterOrchestratorBaselineReportingTest.php
tests/Unit/Infrastructure/Console/ViolationFilterOrchestratorTest.php
tests/Unit/Infrastructure/Console/ViolationFilterPipelineTest.php
tests/Functional/Console/Command/BaselineCleanupCommandTest.php
tests/Functional/Console/Command/BaselineCommandFailureReportingTest.php
tests/Functional/Console/Command/BaselineCommandOptionSurfaceTest.php
tests/Functional/Console/Command/BaselineExplainCommandTest.php
tests/Functional/Console/Command/BaselineGenerateCommandTest.php
tests/Functional/Console/Command/BaselineIncompleteAnalysisTest.php
tests/Functional/Console/Command/BaselineLifecycleTest.php
tests/Functional/Console/Command/BaselineMeasuredSetSeamTest.php
tests/Functional/Console/Command/BaselineMigrateCommandTest.php
tests/Functional/Console/Command/BaselineRunBeforeLoadTest.php
tests/Functional/Console/Command/BaselineUpdateCommandTest.php
tests/Functional/Console/Command/CheckCommandBaselineTest.php
```

**DoD:** measured/filter/result transport preserves mandatory subjects; console
reports v11 selectors and subjects without reconstructing them from display
paths; every v10 load path prints the same manual migration/regeneration
guidance; `baseline:migrate` is never offered as v10-to-v11 conversion. Run the
listed tests, then the complete P5 test set, `composer test`, `composer phpstan`,
`composer cs-check`, and `git diff --check`. `composer selfcheck` remains the
explicit next-package stop condition until the repository's tracked baseline
is reviewed and migrated.

#### P5-F — explicit reviewed dogfooding baseline migration

**Depends on:** P5-E. **Owned tracked file:** `qmx-baseline.json` only. No
production code or test file belongs to this package.

This is a project-specific reviewed migration, not a loader conversion:

```text
old = exact v10 qmx-baseline.json bytes from the P5-A parent checkpoint
run = one measured analysis with pinned source scope, qmx configuration, CLI
      options, exclusions, and RunScope
crosswalk = before baseline serialization, write a deterministic scratch JSON
            projection of run.violations containing subject canonical, logical
            symbol, channel, occurrence, and structured edge
candidate = serialize the same run.violations as a fresh v11 baseline to a
            separate scratchpad path
assert identity-set(candidate) == identity-set(crosswalk)
for each old (logical symbol, channel, logical edge) group:
    map candidate entries through crosswalk.symbol, channel, occurrence, edge
    classify mapping as one-to-one, split, missing, or ambiguous
collapse mapped v11 entries back to the old logical grouping
compare count and sorted magnitudes with old acceptance
// ... reviewer resolves every split/missing/ambiguous row explicitly
replace qmx-baseline.json only after the report has no unexplained row
```

The P5-C generic JSON identity projection is the crosswalk source, but the
scratch projection and v11 candidate must be derived from the same
`MeasuredViolationSet`, before either is independently reread or rerun. The
report records the exact source paths/scope, configuration path and content
hash, effective CLI options, exclusions, and `RunScope`; a second analysis run
is not accepted as the candidate or crosswalk counterpart. Mapping reads the
explicit logical `symbol` field. It never parses the opaque canonical
`subject` key to reconstruct a logical symbol.

One-to-one rows preserve their accepted ceiling. Split rows may be accepted
only when the collapsed v11 count/magnitudes equal the old group and every new
subject/occurrence key is named in the report. Missing or ambiguous rows block
replacement; they are never silently dropped. A fresh v11 finding with no v10
ancestor is new debt and is not accepted by this migration. Regeneration is
therefore an input candidate, never the decision. The reviewer records the
map/split decision and complete JSON diff are then independently reviewed
before replacing the tracked file; this is an implementation review gate, not
an extra user-interaction gate. The v5-to-v10 migrator is not invoked or
mentioned as a solution.

**DoD:** `qmx-baseline.json` has version 11; a deterministic scratchpad report
accounts for every old entry and every candidate entry; candidate and
crosswalk identity sets are equal under
`channel + subject + optional occurrence + optional edge`; the report pins one
measured run's config/scope evidence; collapsed mapped
ceilings equal v10 for every accepted group; there are zero unexplained,
missing, ambiguous, or newly accepted rows; an independent reviewer accepts the
mapping report and JSON diff with no unresolved finding. `composer selfcheck`,
then `composer check`, exit zero. The
scratchpad candidate/report are not tracked.

#### P5 documentation contract assigned to P7

Implementation assertions change in
`tests/Architecture/Integration/InlineSuppressionLayerViolationIntegrationTest.php`,
`tests/Architecture/Integration/LayerViolationIntegrationTest.php`,
`tests/Architecture/Unit/Rules/LayerViolationRuleTest.php`,
`tests/Unit/Baseline/BaselineIdentityTest.php`,
`tests/Unit/Reporting/Formatter/GitLabCodeQualityFormatterTest.php`,
`tests/Unit/Reporting/Formatter/SarifFormatterTest.php`,
`tests/Unit/Reporting/Formatter/Json/JsonViolationSectionTest.php`, and
`tests/Unit/Reporting/Formatter/Html/HtmlViolationPartitionerTest.php` in their
already assigned P5 packages. User-facing history/documentation remains P7;
its complete literal ownership list appears exactly once in P7 below.

ADR 0021 records optional occurrence identity, project-group cycle/duplication
subjects, LayerViolation's breaking subject/control matrix, and the rejection
of primary-file/arbitrary-representative identity. CHANGELOG names baseline
v11 and source-symbol suppression behavior. EN/RU pages document the same
consumer migration and output fields.

### P6 — Coupling and ClassRank

**Depends on:** P5.

**Production files:**

```text
src/Metrics/Coupling/CouplingCollector.php
src/Metrics/Coupling/ClassRankCollector.php
src/Metrics/Coupling/AbstractnessCollector.php
src/Metrics/Coupling/DistanceCollector.php
src/Rules/Coupling/CboRule.php
src/Rules/Coupling/ClassRankRule.php
src/Rules/Coupling/InstabilityRule.php
src/Rules/Coupling/DistanceRule.php
src/Rules/Coupling/CboOptions.php
src/Rules/Coupling/ClassCboOptions.php
src/Rules/Coupling/ClassInstabilityOptions.php
src/Rules/Coupling/ClassRankOptions.php
src/Rules/Coupling/DistanceOptions.php
src/Rules/Coupling/NamespaceCboOptions.php
src/Rules/Coupling/NamespaceInstabilityOptions.php
src/Reporting/Impact/ClassRankIndex.php
src/Reporting/Impact/ClassRankResolver.php
```

**Tests:**

```text
tests/Unit/Metrics/Coupling/AbstractnessCollectorTest.php
tests/Unit/Metrics/Coupling/ClassRankCollectorTest.php
tests/Unit/Metrics/Coupling/CouplingCollectorTest.php
tests/Unit/Metrics/Coupling/DistanceCollectorTest.php
tests/Unit/Reporting/Impact/ClassRankResolverTest.php
tests/Unit/Reporting/Impact/ImpactCalculatorTest.php
tests/Unit/Rules/Coupling/CboOptionsTest.php
tests/Unit/Rules/Coupling/CboRuleTest.php
tests/Unit/Rules/Coupling/ClassRankRuleTest.php
tests/Unit/Rules/Coupling/DistanceOptionsTest.php
tests/Unit/Rules/Coupling/DistanceRuleTest.php
tests/Unit/Rules/Coupling/InstabilityOptionsTest.php
tests/Unit/Rules/Coupling/InstabilityRuleTest.php
tests/Unit/Rules/Coupling/NamespaceCboOptionsTest.php
tests/Unit/Rules/Coupling/NamespaceInstabilityOptionsTest.php
```

**Work:** apply logical CBO source/target deduplication while retaining
external targets; calculate ClassRank once per `LogicalClassPath`; and make
degree-zero CBO, ClassRank, and Instability values explicit. P5 already owns
projection of every logical class score to exact declaration findings with
independent controls and identities; P6 must not reintroduce a logical-class
finding or change the P5 finding contract.

The four rule-file overlaps with P5-A3 are sequential by dependency, not
parallel ownership; P6 owns only calculation-facing edits after P5 is green.

**DoD:** duplicate declarations create one logical ClassRank metric but two
independent declaration findings; external CBO targets remain; isolated class
coupling values are explicit zeroes at all applicable levels.

### P7 — Documentation, migration, and status

**Depends on:** P6.

**Files:**

```text
AGENTS.md
CHANGELOG.md
docs/adr/README.md
docs/adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md
docs/internal/SCANNER_VALIDATION_ROUND_2_PLAN.md
src/Analysis/README.md
src/Architecture/README.md
src/Baseline/README.md
src/Core/README.md
src/Infrastructure/Console/README.md
src/Infrastructure/README.md
src/Metrics/Coupling/README.md
src/Metrics/README.md
src/Reporting/README.md
src/Rules/README.md
website/docs/rules/architecture.md
website/docs/rules/architecture.ru.md
website/docs/rules/coupling.md
website/docs/rules/coupling.ru.md
website/docs/rules/duplication.md
website/docs/rules/duplication.ru.md
website/docs/usage/baseline.md
website/docs/usage/baseline.ru.md
website/docs/usage/output-formats.md
website/docs/usage/output-formats.ru.md
```

**Work:** document callable ownership, hook controls, identity schema,
consumer migration, and metric deviations using F-014's durable forms; update
Round 1 status only after each verified fix is actually complete.

**DoD:** every breaking surface has a consumer migration step and CHANGELOG
entry; each changed metric's component README and EN/RU website pages agree;
there is no claim that a deferred or unimplemented finding is fixed.

### P8 — Integrated validation and independent review

**Depends on:** P7.

**Files:** no production edits; only validation evidence and the central status
documents if the evidence proves their status change.

**Work:** run focused regressions per package, then `composer check`; run
`composer test:js` and `composer build:js` if HTML changed; build website docs
strictly if website files changed; perform the mandated independent review and
verify every reported issue against code.

**DoD:** all required commands exit zero, or an unrelated/environmental
failure is isolated with reproducible evidence; full review has no unresolved
confirmed finding; baseline was not auto-regenerated; Round 1 findings are
updated only with verified dispositions.

## Test sequence and stop conditions

Each package begins with a regression at its public seam and proves the old
behavior fails before production code is changed. Tests cover duplicate same-
logical declarations, start-position collision, sibling stability, all callable
kinds, hook-control precedence, anonymous-class lexical context, capture versus
invocation, 0..N dependency resolution, zero-degree graph vertices, ClassRank
projection, and every serialized fingerprint. Any discovery that requires a
file outside its listed package stops implementation until this plan is revised
and reviewed. No commit, push, automatic baseline regeneration, or release is
part of this plan; the only baseline replacement is the explicit
map/split/equivalence review in P5-F.
