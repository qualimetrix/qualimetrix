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

**Files:** `src/Rules/**/*.php`, `src/Baseline/**/*.php`,
`src/Core/Violation/{Violation,ViolationCollection}.php`,
`src/Reporting/**/*.php`, `src/Infrastructure/Console/{FormatterContextFactory,ViolationFilterOrchestrator}.php`,
`src/Architecture/Rules/{LayerViolationRule,LayerViolationOptions}.php`,
`src/Architecture/Domain/Allow/LayerSelector.php`,
`src/Architecture/Domain/Layer/{ClassContextFactory,LayerMatch,LayerPolicy,LayerRegistry,MembershipResult}.php`,
`src/Architecture/Processing/{LayerExpansionResult,LayerExpansionStage}.php`,
`src/Analysis/{Collection/Metric/CompositeCollector,Pipeline/AnalysisPipeline}.php`,
`src/Analysis/Collection/{FileProcessor,FileProcessingResult,CollectedFileData}.php`,
`src/Baseline/Suppression/{SuppressionExtractor,SuppressionFilter,ThresholdOverrideExtractor,ThresholdOverrideExtractionResult}.php`,
`tests/Architecture/{Integration/InlineSuppressionLayerViolationIntegrationTest,Integration/LayerViolationIntegrationTest,Unit/Rules/LayerViolationOptionsTest,Unit/Rules/LayerViolationRuleTest}.php`,
`tests/Architecture/{Integration/CaptureBindingIntegrationTest,Integration/LayerCriteriaIntegrationTest,Integration/LayerExcludeIntegrationTest,Integration/LayerTemplateExpansionIntegrationTest,Integration/RelationsFilterIntegrationTest,Unit/Rules/CoverageDiagnosticsTest}.php`,
`tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php`,
`tests/Unit/Analysis/Collection/FileProcessorTest.php`,
`tests/Unit/Baseline/Suppression/{SuppressionExtractorTest,SuppressionFilterTest,ThresholdOverrideExtractorTest}.php`,
`tests/Unit/Rules/ThresholdOverrideIntegrationTest.php`, and all direct
`tests/{Unit,Functional,Integration}/{Rules,Baseline,Reporting,Infrastructure/Console}/` tests.

**Work:** apply hook control precedence (`hook > property > class > config`),
turn P2+P3's dependency primitives into declaration findings, and implement
LayerViolation's 0..N owned-target projection. Migrate every inventory
projection to the subject source of truth. Baseline parsing/identity supports
the new deliberate schema only; it provides no automatic legacy conversion or
regenerated file.

**DoD:** hook/property/class/config controls obey their declared precedence;
LayerViolation produces one independently controlled declaration finding for
each 0..N owned target as applicable; every listed formatter emits the same
declaration identity where it has an identity field; baseline and GitLab/SARIF
fingerprints stay stable after an unrelated sibling is added; no legacy
`method` alias remains in public configuration or output.

### P6 — Coupling and ClassRank

**Depends on:** P5.

**Files:** `src/Metrics/Coupling/{CouplingCollector,ClassRankCollector,AbstractnessCollector,DistanceCollector}.php`,
`src/Rules/Coupling/{CboRule,ClassRankRule,InstabilityRule,DistanceRule,CboOptions,ClassCboOptions,ClassInstabilityOptions,ClassRankOptions,DistanceOptions,NamespaceCboOptions,NamespaceInstabilityOptions}.php`,
`src/Reporting/Impact/{ClassRankIndex,ClassRankResolver}.php`, and their direct
`tests/Unit/{Metrics/Coupling,Rules/Coupling,Reporting/Impact}/` tests.

**Work:** apply logical CBO source/target deduplication while retaining
external targets; calculate ClassRank once per `LogicalClassPath`, then project
its score to declaration findings with independent controls and identities.

**DoD:** duplicate declarations create one logical ClassRank metric but two
independent declaration findings; external CBO targets remain; isolated class
coupling values are explicit zeroes at all applicable levels.

### P7 — Documentation, migration, and status

**Depends on:** P6.

**Files:** `CHANGELOG.md`, `docs/adr/README.md`, this plan,
`docs/adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md`,
affected `src/**/README.md`, and matched EN/RU pages under `website/docs/`.

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
and reviewed. No commit, push, baseline regeneration, or release is part of
this plan.
