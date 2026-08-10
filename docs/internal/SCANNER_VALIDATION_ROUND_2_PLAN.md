# Scanner Validation Round 2 — Implementation Plan

**Status:** approved implementation contract

**Inputs:** [Round 1 findings](SCANNER_VALIDATION_ROUND_1_FINDINGS.md),
[ADR 0021](../adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md)

## Authoritative execution-status ledger

This ledger is the compact/session handoff authority. The detailed package
sections below remain the implementation and validation contracts; they do not
override execution status. Update this ledger only after a package's validation
and review have been accepted. Do not implement a stage again when it is marked
complete; resume at the first pending stage.

| Stage                   | Status            | Validation / review                                                                                              | Next                                                      |
| ----------------------- | ----------------- | ---------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| P1-P6 (pre-remediation) | Complete          | Package gates and reviews accepted                                                                               | None; retained as historical contract                     |
| P6-R0                   | Complete          | Validation and review accepted                                                                                   | None                                                      |
| P6-R1                   | Decision complete | R1 decisions recorded and reviewed                                                                               | None; execute only the decisions assigned to later stages |
| P6-R2-P6-R10            | Complete          | Each remediation package validated and review accepted                                                           | None                                                      |
| P6-R11                  | Complete          | Final fixes accepted; reproducible final evidence confirms acceptance                                            | None                                                      |
| P6-RG                   | Complete          | Reviewed evidence accepted; coupling-health gate passed                                                          | None                                                      |
| P6-RG1                  | Complete          | Exact correction and structural residual accepted; final reproducible evidence reviewed                          | None                                                      |
| P5-F                    | Complete          | Reviewed v11 migration evidence accepted; candidate validation, baseline tests, selfcheck, and full check passed | None                                                      |
| P7                      | Complete          | Docs-only implementation validated; independent reviews accepted                                                 | None                                                      |
| P8                      | Complete          | P8-R1 implementation, documentation, final evidence, and independent reviews accepted                            | None; Round 2 is complete                                 |

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

| Area                            | Exact production files                                                                                                                                                                                                                                                                                                                                |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core callable/subject contracts | `src/Core/Metric/{CallableWithMetrics,CallableMetricsProviderInterface,MetricRepositoryInterface,ClassWithMetrics,ClassMetricsProviderInterface,NamespaceWithMetrics,NamespaceMetricProviderInterface}.php`; `src/Core/Symbol/{CallableKind,DeclarationPath,LogicalClassPath,MetricSubject,SymbolInfo}.php`                                           |
| Complexity                      | `src/Metrics/Complexity/{CognitiveComplexityVisitor,CognitiveComplexityCollector,CyclomaticComplexityVisitor,CyclomaticComplexityCollector,NpathComplexityVisitor,NpathComplexityCollector}.php`                                                                                                                                                      |
| Halstead                        | `src/Metrics/Halstead/{HalsteadVisitor,HalsteadCollector}.php`                                                                                                                                                                                                                                                                                        |
| Size                            | `src/Metrics/Size/{MethodStatementCountVisitor,MethodStatementCountCollector,LocVisitor,LocCollector,ClassCountVisitor,ClassCountCollector}.php`; `src/Metrics/Structure/{MethodCountVisitor,MethodCountCollector}.php`                                                                                                                               |
| Structure                       | `src/Metrics/Structure/{RfcVisitor,RfcCollector,LcomVisitor,LcomCollector,TccLccVisitor,TccLccCollector,UnusedPrivateVisitor,UnusedPrivateCollector,InheritanceDepthVisitor,InheritanceDepthCollector}.php`                                                                                                                                           |
| Design and code smell           | `src/Metrics/Design/{TypeCoverageVisitor,TypeCoverageCollector}.php`; `src/Metrics/CodeSmell/{CodeSmellVisitor,CodeSmellCollector,ParameterCountVisitor,ParameterCountCollector,UnreachableCodeVisitor,UnreachableCodeCollector}.php`; `src/Metrics/CodeSmell/RepeatedExpression/{IdenticalSubExpressionVisitor,IdenticalSubExpressionCollector}.php` |
| Security                        | `src/Metrics/Security/{SecurityPatternVisitor,SecurityPatternCollector,SensitiveParameterVisitor,SensitiveParameterCollector}.php`; `src/Metrics/Security/Credential/{HardcodedCredentialsVisitor,HardcodedCredentialsCollector}.php`                                                                                                                 |
| Shared collector lifecycle      | `src/Metrics/{AbstractCollector,ResettableVisitorInterface,VisitorMethodTrackingTrait}.php`; `src/Metrics/Structure/ClassVisitorStackTrait.php`                                                                                                                                                                                                       |

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
`tests/Unit/Metrics/CodeSmell/{CodeSmellVisitorTest,CodeSmellCollectorTest,ParameterCountVisitorTest,ParameterCountCollectorTest,UnreachableCodeVisitorTest,UnreachableCodeCollectorTest}.php`,
`tests/Unit/Metrics/CodeSmell/RepeatedExpression/{IdenticalSubExpressionVisitorTest,IdenticalSubExpressionCollectorTest}.php`,
`tests/Unit/Metrics/Security/{SecurityPatternVisitorTest,SecurityPatternCollectorTest,SensitiveParameterVisitorTest,SensitiveParameterCollectorTest}.php`, and
`tests/Unit/Metrics/Security/Credential/{HardcodedCredentialsVisitorTest,HardcodedCredentialsCollectorTest}.php`.
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
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionCollector.php
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitor.php
src/Metrics/Security/Credential/HardcodedCredentialsCollector.php
src/Metrics/Security/Credential/HardcodedCredentialsVisitor.php
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
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionCollector.php
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionFinding.php
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitor.php
src/Metrics/Security/CommandInjectionDetector.php
src/Metrics/Security/Credential/CredentialLocation.php
src/Metrics/Security/Credential/HardcodedCredentialsCollector.php
src/Metrics/Security/Credential/HardcodedCredentialsVisitor.php
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
tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionCollectorTest.php
tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitorTest.php
tests/Unit/Metrics/Security/CommandInjectionDetectorTest.php
tests/Unit/Metrics/Security/Credential/HardcodedCredentialsCollectorTest.php
tests/Unit/Metrics/Security/Credential/HardcodedCredentialsVisitorTest.php
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
`composer cs-check`, and `git diff --check`. The expected dogfood failure is
captured as remediation input; `composer selfcheck` is deliberately deferred
until P6 and the P6-R remediation packages are complete. The tracked baseline
is not edited in P5-E.

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

**Depends on:** P5-E. P5-F is intentionally later: it is a gate over the final
P6 plus P6-R production state, not a prerequisite for calculation changes.

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

**DoD:** fixture oracles prove the logical-source/target deduplication,
external-target retention, zero-degree values, and one-logical-score/two-finding
projection independently of dogfood health. The reproduced current project
`health.coupling` of 47.624890160 does not fail P6's calculation contract; it
remains an unresolved source-quality ratchet owned by the global post-R11 gate.
It must return to the frozen floor 48.0 by reviewed source refactoring, never by
formula changes, threshold manipulation, controls, exclusions, or baseline
acceptance.

### P6-R — dogfood remediation before baseline migration

**Depends on:** P6. This phase is a hard production-quality gate. It resolves
the measured fresh debt and 32 of the 34 worsened ceilings; the remaining two
are the finite reviewed R6 structural outcomes below. P6-R does not regenerate
a baseline, raise a project-wide threshold, or change the duplication detector.
The scratch evidence is
`/private/tmp/qmx-p5f-CHiwIP/{mapping-report.json,duplication-audit-compact.json}`.

Every active package uses the same reproducible verification before its DoD is
accepted:

```text
run php bin/qmx check src/ --config=qmx.yaml --format=json --workers=0
without --baseline, presets, --only-rule, --disable-rule, --rule-opt, or CLI
source exclusions; pin source revision and qmx.yaml hash in scratch metadata
assert discovered == analyzed, failed == 0, generatedExcluded == 0
compare against the frozen P5-F inventory with identity-aware mapping
assert assigned-unresolved-fresh-identities(package) == empty
assert each mapped renamed-successor identity is present and <= its OLD ceiling
assert each reviewed-retired identity has the evidence required by its ledger
assert reviewed-structural-control-identities before R4 == empty
assert reviewed-structural-control-identities once R4 is complete and at every
  later checkpoint before R11 == {
  class Qualimetrix\Metrics\Security\Credential\CredentialLiterals,
  channel computed.health#health.cohesion,
  exact adjacent @qmx-ignore reason declared in P6-R4:
    Stateless credential-literal shapes share one classification policy and location boundary.
  class Qualimetrix\Metrics\Security\Credential\HardcodedCredentialsVisitor,
  channel design.data-class,
  exact adjacent @qmx-ignore reason declared in P6-R4:
    Traversal adapter intentionally delegates credential policy and retains only lifecycle state.
}
assert reviewed-structural-control-identities at R11 and every later checkpoint ==
  the exact two rows above plus {
    class Qualimetrix\Rules\CodeSmell\LongParameterListRule,
    channel computed.health#health.cohesion,
    exact adjacent @qmx-ignore reason:
      Interface metadata methods getCategory() and requires() return external enum/metric constants beside one cohesive analysis/projection component; LCOM4 cannot merge those stateless protocol methods.
  }
assert controlled identity count == 0 before R4, == 2 from R4 through R10,
  and == 3 at/after R11
assert all checkpoint-controlled identities are absent from the normal
  unbaselined result, appear exactly once each with byte-exact reasons in --show-suppressed
  evidence, and are excluded from unresolved/unexpected-fresh sets only after
  that proof
assert reviewed-structural-recalibration-identities == {
  class Qualimetrix\Architecture\Rules\LayerViolationRule,
  channel coupling.cbo#coupling.cbo.class, exact reviewed ceiling 24
  namespace Qualimetrix\Architecture\Rules,
  channel coupling.instability#coupling.instability.namespace,
  exact reviewed ceiling 26 / (26 + 2) = 0.928571
}
assert each assigned drift other than those exact two reviewed structural rows
  is direction-aware against its old boundary:
  every non-health drift in this frozen inventory is upper-bound and must be
    <= its old ceiling (including instability, CBO, cognitive, CCN, NPATH,
    WMC, parameter count, and duplication magnitude)
  only computed health.* drifts are lower-bound and must be >= their old floor
assert unexpected-fresh-identities(outside frozen unresolved inventory) == empty
```

The common verifier statement above is authoritative for the frozen P6-R
inventory, but P5-F's later exact-identity crosswalk discovered two
post-ledger fresh groups that it could not classify as old descendants. They
do not reopen or enlarge the 63-row frozen fresh inventory. Until P6-RG1 is
accepted, every post-ledger checkpoint must instead assert that the complete
unexpected-fresh set is exactly the two identities named in P6-RG1. Only after
the P6-RG1 inline correction is measured successfully may it become exactly the
one reviewed structural candidate.
Any other fresh identity fails the gate.

P5-F v2 later found one mapped ceiling worsening, not another fresh identity:
`ns:Qualimetrix\\Rules\\CodeSmell` /
`size.class-count#size.class-count`, old 23, measured 24. It does not change
the frozen 63-row or 34-row ledgers or the 321 measured identity count. Until
the exact decision gate below is approved independently, this row blocks P5-F;
after approval it is exactly one separate post-ledger mapped structural
recalibration. Any second mapped inventory delta, post-ledger recalibration, or
post-ledger fresh identity fails the gate.

The original evidence had 672 discovered/analyzed files and complete coverage;
new planned files may raise that number, so equality/completeness is the
invariant, not the literal count. Each package stores its unbaselined JSON and
comparison report in scratch space and runs its listed direct tests,
`composer phpstan`, `composer cs-check`, and `git diff --check`.

#### P6-R inventory and decisions

The non-duplication inventory is closed and assigned exactly once below. Counts
are groups, not individual diagnostics:

| Package                                                        | Exact fresh groups                                                                                                                                                                                                                                               | Count  |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -----: |
| P6-R0                                                          | `InMemoryMetricRepository` false `unused-private` receiver report                                                                                                                                                                                                | 1      |
| P6-R2                                                          | `FileProcessor` class cognitive/WMC; `bindingsForNode` cognitive/CCN/NPATH; `buildDeclarationBindings` cognitive/CCN/NPATH; `extractSuppressions` cognitive/CCN; `extractThresholdOverrides` cognitive; its collection closure CCN                               | 12     |
| P6-R3                                                          | `InMemoryMetricRepository` WMC; `addSubject` CCN; `all` cognitive; `indexNamespaceInfo` CCN; `mergeSubjectInfo` cognitive/CCN/NPATH; `mergeWith` cognitive                                                                                                       | 8      |
| P6-R4                                                          | `VisitorMethodTrackingTrait` cognitive/WMC/CBO and `enterFileEntrySubjectContext` cognitive; GodClass findings for `CodeSmellVisitor`, `IdenticalSubExpressionVisitor`, and `HardcodedCredentialsVisitor`                                                        | 7      |
| P6-R5                                                          | `CompositeCollector` WMC/CBO; `applyDerivedCollectors` cognitive/CCN; `collect` NPATH                                                                                                                                                                            | 5      |
| P6-R6                                                          | `LayerViolationRule` method-count                                                                                                                                                                                                                                | 1      |
| P6-R7                                                          | `NamespaceMetricContributions` WMC; `CollectionOrchestrator` CBO; `DependencyGraphBuilder` maintainability; `DependencyVisitor` instability; `AnalysisPipeline::analyze` NPATH; `DependencyGraphAnalyzer::declaredLogicalClasses` CCN                            | 6      |
| P6-R8                                                          | `BoundaryExplanationService` CBO plus `repositoryContains` and `subjectForIdentity` CCN; `SuppressionExtractor` cohesion and `extractFromText` cognitive; `BaselineEntryParser` WMC; `BaselineGenerator` instability                                             | 7      |
| P6-R9                                                          | `AbstractCodeSmellRule` cohesion; `AbstractSecurityPatternRule` instability; `UnusedPrivateRule::analyze` cognitive/CCN; `CboRule::analyzeClassLevel`, `ClassRankRule::analyze`, `NocRule::analyze` CCN; `LcomRule::analyze` NPATH; `WmcRule::analyze` cognitive | 9      |
| P6-R10                                                         | `MetricEnricher::enrich` NPATH; `NpathComplexityVisitor::enterNode` maintainability; `NpathExpressionCalculator` WMC and `calculateContributions` CCN; `MetricSubjectCodec::decode` CCN/NPATH; `HtmlViolationPartitioner::attach` CCN                            | 7      |
| **Frozen non-dup fresh total excluding the renamed successor** |                                                                                                                                                                                                                                                                  | **63** |

`FileProcessor::extractCallableMetrics` cognitive is deliberately outside that
fresh total. It is the renamed successor of old
`FileProcessor::extractMethodMetrics`, with old ceiling 15, and is counted once
as a mapped surviving identity. P6-R2 must reduce/preserve it at <=15; P5-F
projects the old ceiling 15 onto that successor. It is neither unresolved fresh
debt nor a reviewed retirement.

Of those 63 frozen fresh rows, P6-R0's false-positive row is already verified
clear, leaving 62 unresolved fresh rows when P6-R2 begins. Package DoD and the
common verifier distinguish this lifecycle state from the mapped successor and
from the ten reviewed-retirement candidates; the three classes are disjoint.

The 34 worsening one-to-one rows are also assigned exactly once:

| Owner                                                                      | Exact worsening rows                                                                                                                                                                                                                                                                                                                                                                                                                                   | Count  |
| -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -----: |
| P6-R2                                                                      | `FileProcessor` CBO 26 -> 34 and instability 0.923077 -> 0.941176                                                                                                                                                                                                                                                                                                                                                                                      | 2      |
| P6-R3                                                                      | `InMemoryMetricRepository::mergeWith` CCN 10 -> 13                                                                                                                                                                                                                                                                                                                                                                                                     | 1      |
| P6-R5                                                                      | `CompositeCollector::collect` CCN 10 -> 11                                                                                                                                                                                                                                                                                                                                                                                                             | 1      |
| P6-R6 remediation                                                          | `LayerViolationRule` WMC 73 -> 83                                                                                                                                                                                                                                                                                                                                                                                                                      | 1      |
| P5 identity topology / P6-R6 structural review / P5-F finite recalibration | `LayerViolationRule` CBO 23 -> 25, reviewed final structural ceiling 24; `Architecture\\Rules` namespace instability 0.923077 -> 0.928571, reviewed final structural ceiling 26 / (26 + 2) = 0.928571                                                                                                                                                                                                                                                  | 2      |
| P6-R7 active source rows                                                   | `DependencyVisitor` CBO 24 -> 26; `DependencyVisitor::enterNode` CCN 14 -> 15 and NPATH 576 -> 1440; `DependencyGraphBuilder::build` cognitive 16 -> 20, CCN 12 -> 15, and NPATH 592 -> 2368; `AnalysisPipeline` WMC 52 -> 56                                                                                                                                                                                                                          | 7      |
| Cross-package resolved, P6-R7 read-only oracle                             | `Analysis\\Collection` namespace CBO 19 -> 22 is now 18 after prior accepted packages; preserve <=19 without R7 ownership                                                                                                                                                                                                                                                                                                                              | 1      |
| Global post-R11 / pre-P5-F gate                                            | project `health.coupling` floor 48.0, currently 47.624890160                                                                                                                                                                                                                                                                                                                                                                                           | 1      |
| P6-R8                                                                      | `BoundaryExplanationService` WMC 54 -> 70 and instability 0.888889 -> 0.9; `SuppressionExtractor::extractFromText` CCN 12 -> 14                                                                                                                                                                                                                                                                                                                        | 3      |
| P6-R9                                                                      | `AbstractCodeSmellRule` CBO 20 -> 22; `CboRule` WMC 61 -> 63 and CBO 22 -> 23; `CboRule::checkCbo` parameters 9 -> 10; `DistanceRule::analyze` NPATH 441 -> 588; `InstabilityRule::analyzeClassLevel` CCN 10 -> 11; `TypeCoverageRule::checkCoverage` parameters 8 -> 9; `PropertyCountRule::analyze` cognitive 15 -> 17, CCN 12 -> 13, and NPATH 219 -> 435; `LcomRule::analyze` cognitive 15 -> 17 and CCN 12 -> 13; `WmcRule::analyze` CCN 11 -> 12 | 13     |
| P6-R10                                                                     | immutable `Violation::__construct` parameters 14 -> 16; `MethodCountCollector::getClassesWithMetrics` CCN 11 -> 12                                                                                                                                                                                                                                                                                                                                     | 2      |
| **Total**                                                                  |                                                                                                                                                                                                                                                                                                                                                                                                                                                        | **34** |

Thus 32 worsening rows are remediation targets and exactly two remain accepted
reviewed structural residual debt. The current disposition of those 32 is 31
package or cross-package remediation rows plus one unresolved global
post-R11 gate; final acceptance requires all 32 remediated and zero unresolved.
The two structural rows remain mapped surviving rows and are accounted once in
the 34-row ledger; neither is fresh, improved, retired, or permission for a
third recalibration.

Six improved one-to-one rows require no code and retain the old, tighter
ceilings: `Baseline` CBO 21 -> 20, `RuleOptionsFactory` WMC 70 -> 67,
`RuleExecutor::execute` cognitive 25 -> 24 and CCN 13 -> 11,
`SuppressionFilter::shouldInclude` CCN 13 -> 11, and
`AnalysisContext::getThresholdOverride` CCN 11 -> 10.

The D-001 per-declaration-calculation candidate is deferred and orthogonal to
ADR 0021. This plan preserves ADR 0021's approved projection contract: one
logical class score is projected to each owned declaration, whose controls and
finding identities are independent. That contract neither proves nor rejects a
future change to the metric's calculation granularity. D-001 is therefore not a
P6-R work package; `PropertyCountRuleTest` and the P5 declaration-identity tests
continue to pin current projection behaviour.

#### P6-R0 — completed bounded unused-private receiver correction

**Status:** complete and independently verified. **Owned files:**

```text
src/Metrics/Structure/UnusedPrivateVisitor.php
src/Metrics/Structure/UsageTrackingTrait.php
tests/Unit/Metrics/Structure/UnusedPrivateCollectorTest.php
```

The receiver-aware read/call path now distinguishes `$this`, same-class static
receivers, foreign receivers, and unknown receivers. The focused collector
regressions and a current workers=0 self-scan prove that the false
`InMemoryMetricRepository` unused-private finding is absent. This package is
recorded for inventory closure only and must not be reimplemented.

#### P6-R1 — subject-cohesion placement decision

**Files:** no production or test files. Before creating any path marked
`[new]` or relocating any full subject-stack member marked `[move existing]`
below, review its name, co-change boundary, public surface, dependency
direction, and counterfactual ownership under ADR 0016 and the
`dvizh-workflow:subject-cohesion` skill. The review either approves the exact
paths below or revises this plan before any creation; an implementer may not
silently choose a different helper location.

For P6-R4 the revised subject-cohesion decision has one closed foundation
inventory that must be approved together before implementation:

| Exact new path                                    | Subject responsibility                                                                                                         |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `src/Metrics/VisitorFileEntryScope.php` `[new]`   | live AST file-entry scope and lexical entry/exit transitions; no metric-specific counters or findings                          |
| `src/Metrics/VisitorCallableMetadata.php` `[new]` | immutable callable identity/metadata projection handed to consumers; no live traversal state or metric-specific values         |
| `src/Metrics/VisitorMethodContext.php` `[new]`    | composition facade over file-entry scope and callable metadata for the complete visitor consumer set; no metric-specific state |

The three names answer the foundation subject rather than an implementation
role, remain dependency-downward, and are not permission for additional root
Metrics helpers. Any fourth helper or shifted responsibility stops R4 and
requires another reviewed plan revision.

P6-R8 later hit a measured STOP and reopens R1 for exactly one proposed
production type and its direct test; neither path is authorized for creation
until an independent subject-cohesion review approves both rows and the
revised R8 dependency arithmetic:

| Exact proposed new path                                                       | Subject responsibility                                                                                                                                                                                                                                                                  |
| ----------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Baseline/BaselineEntryValues.php` `[new; R1 review required]`            | immutable value portion of one v11 entry (`count`, optional finite magnitudes, optional mode) plus strict decoding of exactly those raw fields; it owns no subject/channel/occurrence/edge identity, declaration lookup, grouping, capture timestamp/scope, or inert-entry presentation |
| `tests/Unit/Baseline/BaselineEntryValuesTest.php` `[new; R1 review required]` | direct value-schema contract for every count/magnitudes/mode success and rejection, with no parser, identity, registry, CLI, or workflow fixture responsibility                                                                                                                         |

The name answers “which baseline-entry data is this?” rather than naming a
generic parser/helper role. It co-changes with the v11 entry value schema,
depends only on existing Baseline value/rejection types, and is consumed only
by `BaselineEntryParser`; counterfactually it remains in Baseline if CLI,
Analysis, or storage adapters disappear. Approval is conditional on its own
unbaselined scan being fresh-clean; a generic decoder, shared utility folder,
second new type, extra file, or responsibility beyond the exact rows fails R1.

R4 also has this closed category-subject inventory. Every new companion owns
semantic policy and returns the existing location/finding VOs rather than
introducing a generic result abstraction; moved collectors, visitors, and VOs
retain their existing boundary responsibility inside the same subject stack:

| Exact target path                                                                                | Subject responsibility                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Metrics/CodeSmell/ControlFlow/ControlFlowSmells.php` `[new]`                                | exactly the current true control-flow semantics: empty-catch detection (including the foreach chain-of-responsibility return/continue exception), `goto`, `exit`/`die`, and `count`/`sizeof` in `for`/`while`/`do` loop conditions; returns existing `CodeSmellLocation` values and explicitly excludes eval, error suppression, superglobal access, debug calls, and boolean arguments |
| `src/Metrics/CodeSmell/Debug/DebugCodeSmells.php` `[new]`                                        | debug-code semantic recognition and existing `CodeSmellLocation` projection                                                                                                                                                                                                                                                                                                             |
| `src/Metrics/CodeSmell/BooleanArgument/BooleanArgumentSmells.php` `[new]`                        | boolean-argument semantic policy, including the promoted-property exclusion, and existing location projection                                                                                                                                                                                                                                                                           |
| `src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionCollector.php` `[move existing]` | repeated-expression collection boundary and metric projection                                                                                                                                                                                                                                                                                                                           |
| `src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitor.php` `[move existing]`   | repeated-expression AST traversal/delegation and subject lifecycle                                                                                                                                                                                                                                                                                                                      |
| `src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionFinding.php` `[move existing]`   | existing repeated-expression finding VO owned by the subject                                                                                                                                                                                                                                                                                                                            |
| `src/Metrics/CodeSmell/RepeatedExpression/RepeatedExpressions.php` `[new]`                       | repeated-expression normalization and narrow semantic equality, returning existing repeated-expression findings                                                                                                                                                                                                                                                                         |
| `src/Metrics/CodeSmell/RepeatedExpression/RepeatedConditions.php` `[new]`                        | repeated-condition policy and findings; depends only on `RepeatedExpressions` for the narrow equality operation                                                                                                                                                                                                                                                                         |
| `src/Metrics/Security/Credential/HardcodedCredentialsCollector.php` `[move existing]`            | hardcoded-credential collection boundary and metric projection                                                                                                                                                                                                                                                                                                                          |
| `src/Metrics/Security/Credential/HardcodedCredentialsVisitor.php` `[move existing]`              | credential AST traversal/delegation and subject lifecycle                                                                                                                                                                                                                                                                                                                               |
| `src/Metrics/Security/Credential/CredentialLocation.php` `[move existing]`                       | existing credential-location VO owned by the subject                                                                                                                                                                                                                                                                                                                                    |
| `src/Metrics/Security/Credential/CredentialLiterals.php` `[new]`                                 | credential-literal semantic classification and existing credential-location projection                                                                                                                                                                                                                                                                                                  |

`RepeatedConditions -> RepeatedExpressions` is the only new companion-to-
companion dependency. These category children are complete subject stacks, not
helper-only role buckets, and do not justify another shared helper.
The subject subnamespaces are the source-level response to the new
`size.class-count` signal: the root `CodeSmellVisitor` imports its named
single-policy children, while the complete RepeatedExpression and Credential
stacks move wholly into their child subjects; each child namespace owns only
the classes listed above;
within `CodeSmell\RepeatedExpression`, the collector may depend on its visitor,
the visitor may depend on `RepeatedExpressions` and `RepeatedConditions`, both
policies may return `IdenticalSubExpressionFinding`, and
`RepeatedConditions` may depend on sibling `RepeatedExpressions`. Within
`Security\Credential`, the collector may depend on its visitor, the visitor may
depend on `CredentialLiterals`, and the classifier may depend on
`SensitiveNameMatcher` and return `CredentialLocation`. No other child-to-child
edge is allowed. These moves create no additional class and need no `qmx.yaml`
pattern because the existing category patterns cover descendants.
`ControlFlowSmellsTest` owns every enumerated control-flow case and proves the
explicitly excluded non-control-flow cases are not accepted by that companion.

The R1 algorithm fence is finite and introduces no additional path:

- `ControlFlowSmells::containsCountCall` uses an iterative DFS worklist. It
  recognizes exactly named `count`/`sizeof` calls, skips `Closure` and
  `ArrowFunction` subtrees, and traverses nested Node/array subnodes. Its direct
  test covers nested expressions, `for`, `while`, `do`, both function names,
  and closure/arrow exclusion.
- `RepeatedExpressions` owns a closed `SUSPICIOUS_SIGILS` set containing
  exactly `===`, `==`, `!==`, `!=`, `>`, `<`, `>=`, `<=`, `<=>`, `&&`, `||`,
  `and`, `or`, `xor`, `-`, `/`, `%`, `^`, and `??`. A binary node obtains its
  sigil through php-parser v5's official `BinaryOp::getOperatorSigil()` and
  rejects sigils outside the set before any deep scan; it does not import the
  19 concrete BinaryOp subclasses. Ternary findings first choose
  `if ?? condition` versus `else`. Only then do iterative scans run.
- `areEqual` is an iterative structural pair worklist over identical values,
  scalar mismatches, arrays (count/key/value), and same-class AST nodes
  (declared subnode names). Side-effect detection is a separate iterative DFS
  over exactly `FuncCall`, `MethodCall`, `StaticCall`, `NullsafeMethodCall`,
  `New_`, `Yield_`, `YieldFrom`, pre/post increment/decrement, `Assign`,
  `AssignOp`, `AssignRef`, `ShellExec`, `Eval_`, `Exit_`, `Print_`, `Include_`,
  and `Throw_`. Direct exhaustive tests cover every operator, both ternary
  forms, every mismatch family, nested arrays/nodes, every side-effect kind,
  and side-effect-free controls. Operator tests instantiate all 19 supported
  concrete BinaryOp classes, assert their official sigils, and pin the
  non-suspicious `+`, `*`, `.`, `&`, `|`, `<<`, `>>`, and `**` controls.

Recursion, a generic AST utility, an additional helper path, and sharing these
policies outside their named companions are forbidden. No CBO/coupling
suppression, threshold, exclusion, or baseline entry is permitted for
`RepeatedExpressions`; the sigil boundary is the required source fix.

**DoD:** every `[new]` file and every `[move existing]` destination has one
owning subject, each moved source path is absent after R4, no new role bucket
is introduced, and package ownership below remains non-overlapping. The R4
decision additionally proves the three responsibilities above are mutually
exclusive, collectively cover only shared traversal context, and contain no
metric-specific state. It also proves each of the six companions has one
semantic subject, uses the existing VO boundary, and introduces only the single
explicit dependency above. R1 approves exactly two R4 controls: the exact
`CredentialLiterals` `@qmx-ignore health.cohesion` and the exact
`HardcodedCredentialsVisitor` `@qmx-ignore design.data-class` annotations and
reasons specified in R4. Any third annotation, changed reason, coupling
suppression, threshold, `qmx.yaml` exclusion, or attempt to baseline either
identity fails the gate.

All P6-R production packages execute sequentially in the shared tree, matching
the global rule above: P6-R1 -> R2 -> R3 -> R4 -> R5 -> R6 -> R7 -> R8 -> R9
-> R10 -> R11 -> the global coupling-health gate -> P5-F. No package may
overlap another package's test run, Composer
cache, scanner cache, or working-tree writes; worktree/cache-isolation choices
are therefore irrelevant to this plan. R3 alone edits the golden aggregation
oracle; R4 later executes it read-only. The intentional sequential
production/documentation overlaps are exhaustive:

| Earlier -> later                       | Exact overlapping production files                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P5-A -> P6-R8                          | `src/Baseline/Suppression/SuppressionExtractor.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| P5-B -> P6-R8                          | `src/Baseline/BaselineCapture.php`, `src/Baseline/BaselineEntryParser.php`, `src/Baseline/BaselineGenerator.php`, `src/Baseline/BoundaryExplanationService.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| P5-A -> P6-R10 -> P6-R11               | `src/Core/Symbol/MetricSubjectCodec.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| P6 -> P6-R9                            | `src/Rules/Coupling/CboRule.php`, `src/Rules/Coupling/ClassRankRule.php`, `src/Rules/Coupling/DistanceRule.php`, `src/Rules/Coupling/InstabilityRule.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| P6-R4 -> P6-R10                        | `qmx.yaml`, `src/Metrics/Complexity/NpathComplexityVisitor.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| P6-R4 -> P6-R11                        | `qmx.yaml`, `src/Metrics/VisitorFileEntryScope.php`, `src/Metrics/VisitorCallableMetadata.php`, `src/Metrics/VisitorMethodContext.php`, `src/Metrics/VisitorMethodTrackingTrait.php`; complete 12 consumer set `src/Metrics/CodeSmell/CodeSmellVisitor.php`, `src/Metrics/CodeSmell/ParameterCountVisitor.php`, `src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitor.php`, `src/Metrics/CodeSmell/UnreachableCodeVisitor.php`, `src/Metrics/Complexity/CognitiveComplexityVisitor.php`, `src/Metrics/Complexity/CyclomaticComplexityVisitor.php`, `src/Metrics/Complexity/NpathComplexityVisitor.php`, `src/Metrics/Halstead/HalsteadVisitor.php`, `src/Metrics/Security/Credential/HardcodedCredentialsVisitor.php`, `src/Metrics/Security/SecurityPatternVisitor.php`, `src/Metrics/Security/SensitiveParameterVisitor.php`, `src/Metrics/Size/MethodStatementCountVisitor.php`; `src/Metrics/README.md` |
| P6-R9 -> P6-R11                        | `src/Rules/CodeSmell/CodeSmellFinding.php`, `src/Rules/Security/SecurityPatternFinding.php`, `src/Rules/README.md`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| P6-R10 -> P6-R11                       | `src/Core/Symbol/MetricSubjectCodec.php`, `src/Metrics/Complexity/NpathComplexityVisitor.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| P6-R2 -> P6-R3 -> P6-R5 -> P6-R7 -> P7 | `src/Analysis/README.md`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| P6-R4 -> P7                            | `src/Metrics/README.md`, `src/Metrics/CodeSmell/README.md`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| P6-R6 -> P7                            | `src/Architecture/README.md`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| P6-R8 -> P7                            | `src/Baseline/README.md`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| P6-R9 -> P7                            | `src/Rules/README.md`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| P6-R11 -> P7                           | `src/Core/README.md`, `src/Metrics/README.md`, `src/Rules/README.md`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |

The complete sequential test overlap is:

| Earlier -> later         | Exact overlapping test files                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| P5-A -> P6-R8            | `tests/Unit/Baseline/Suppression/SuppressionExtractorTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| P5-A -> P6-R2 -> P6-R8   | `tests/Integration/Baseline/ThresholdAnnotationParserPathTest.php` (R8 read-only execution)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| P5-B -> P6-R8            | `tests/Unit/Baseline/BaselineEntryParserTest.php`, `tests/Unit/Baseline/BaselineGeneratorTest.php`, `tests/Unit/Baseline/BoundaryExplanationServiceTest.php`, `tests/Integration/Baseline/BaselineWorkflowTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| P5-A -> P6-R10 -> P6-R11 | `tests/Unit/Core/Symbol/MetricSubjectCodecTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| P6 -> P6-R9              | `tests/Unit/Rules/Coupling/CboRuleTest.php`, `tests/Unit/Rules/Coupling/ClassRankRuleTest.php`, `tests/Unit/Rules/Coupling/DistanceRuleTest.php`, `tests/Unit/Rules/Coupling/InstabilityRuleTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| P6-R3 -> P6-R4           | `tests/Integration/Metrics/GoldenFileAggregationTest.php` (edited only by R3; read-only execution in R4)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| P6-R4 -> P6-R10          | `tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| P6-R4 -> P6-R11          | complete 12-consumer set `tests/Unit/Metrics/CodeSmell/CodeSmellVisitorTest.php`, `tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitorTest.php`, `tests/Unit/Metrics/CodeSmell/ParameterCountCollectorTest.php`, `tests/Unit/Metrics/CodeSmell/UnreachableCodeCollectorTest.php`, `tests/Unit/Metrics/Complexity/CognitiveComplexityVisitorTest.php`, `tests/Unit/Metrics/Complexity/CyclomaticComplexityVisitorTest.php`, `tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php`, `tests/Unit/Metrics/Halstead/HalsteadCollectorTest.php`, `tests/Unit/Metrics/Security/Credential/HardcodedCredentialsVisitorTest.php`, `tests/Unit/Metrics/Security/SecurityPatternVisitorTest.php`, `tests/Unit/Metrics/Security/SensitiveParameterVisitorTest.php`, `tests/Unit/Metrics/Size/MethodStatementCountCollectorTest.php`; `tests/Integration/Rules/PropertyHookControlPrecedenceTest.php`, `tests/Integration/Architecture/DogfoodingTopologyTest.php` |
| P5-A -> P6-R9            | `tests/Integration/Violation/ChannelCoverageTest.php` (P5-A remains the sole editor; R9 executes it read-only)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| P5-A -> P6-R7 -> P6-R9   | `tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php` (P6-R7 remains the last editor; R9 executes it read-only)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| P6-R9 -> P6-R11          | `tests/Unit/Rules/CodeSmell/CodeSmellFindingTest.php`, `tests/Unit/Rules/Security/SecurityPatternFindingTest.php`; `tests/Integration/Violation/ChannelCoverageTest.php` is read-only in both                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| P6-R10 -> P6-R11         | `tests/Unit/Core/Symbol/MetricSubjectCodecTest.php`, `tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php`; `tests/Unit/Metrics/AnonymousClassContextRegressionTest.php` is read-only in R10 and a test-doc-only editor in R11                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |

These overlaps never authorize concurrent edits. Any newly discovered overlap
stops execution until this table is revised.

#### P6-R2 — declaration binding and source-control extraction

**Depends on:** P6-R1. **Production files:**

```text
src/Analysis/Collection/FileProcessor.php
src/Analysis/Collection/Declaration/DeclarationBindings.php [new]
src/Analysis/Collection/SourceControl/SourceControls.php [new]
```

**Component documentation:**

```text
src/Analysis/README.md
```

**Tests:**

```text
tests/Unit/Analysis/Collection/FileProcessorTest.php
tests/Unit/Analysis/Collection/Declaration/DeclarationBindingsTest.php [new]
tests/Unit/Analysis/Collection/SourceControl/SourceControlsTest.php [new]
tests/Integration/Baseline/ThresholdAnnotationParserPathTest.php
```

Move declaration binding construction and source suppression/threshold
extraction behind immutable results; keep parsing, collector invocation, and
`FileProcessingResult` assembly in `FileProcessor`. Direct tests pin named and
anonymous declarations, callable/property-hook binding, lexical/file controls,
and failure transport. **DoD:** all 12 unresolved fresh groups and both drift
rows assigned to P6-R2 clear or return to their v10 boundaries, and the mapped
`extractCallableMetrics` cognitive successor is present at <=15, without
changing serialized collection output. The two class-level drift assertions are
literal upper bounds: `FileProcessor` CBO <=26 and instability <=0.923077;
instability must never use a health-style `>=` comparator. In the same package,
`src/Analysis/README.md` adds both exact new paths to the Collection structure
diagram and documents their declaration-binding/source-control responsibilities,
inputs, immutable outputs, dependency direction, and focused-test contract.
The README must describe only verified R2 implementation; later P7 user-facing
integration preserves this component inventory rather than reconstructing it.

#### P6-R3 — typed repository indexes and merge decomposition

**Depends on:** P6-R2. R3 is the sole editor of the golden aggregation oracle;
R4 executes it later without editing. **Production files:**

```text
src/Analysis/Repository/InMemoryMetricRepository.php
src/Analysis/Repository/MetricSubjectIndex.php [new]
src/Analysis/Repository/NamespaceMetricIndex.php [new]
src/Analysis/Repository/RepositoryMerge.php [new]
```

**Component documentation:**

```text
src/Analysis/README.md
```

**Tests:**

```text
tests/Unit/Analysis/Repository/InMemoryMetricRepositoryTest.php
tests/Unit/Analysis/Repository/MetricSubjectIndexTest.php [new]
tests/Unit/Analysis/Repository/NamespaceMetricIndexTest.php [new]
tests/Unit/Analysis/Repository/RepositoryMergeTest.php [new]
tests/Integration/Metrics/GoldenFileAggregationTest.php
```

Separate typed subject lookup, namespace projection, and deterministic merge;
keep repository API and logical/declaration indexing semantics unchanged.
Tests cover duplicate logical declarations, namespace/file subjects, merge
order, scalar/structured payload collisions, and empty indexes. **DoD:** the
eight assigned fresh groups and `mergeWith` drift are gone or at/below the old
ceiling; golden aggregation is unchanged. R3 preserves R2's Collection
structure entries and responsibility contract in `src/Analysis/README.md`, then
adds the exact repository paths `MetricSubjectIndex.php`,
`NamespaceMetricIndex.php`, and `RepositoryMerge.php` to the Repository
structure. Their documentation states typed-index/namespace/merge
responsibilities, inputs, immutable outputs, dependency direction, repository
API invariants, and exact focused test files. It describes only verified R3
implementation.

#### P6-R4 — visitor method/context composition

**Depends on:** P6-R3. **Production files (complete consumer set):**

```text
qmx.yaml
src/Metrics/VisitorMethodTrackingTrait.php
src/Metrics/VisitorFileEntryScope.php [new]
src/Metrics/VisitorCallableMetadata.php [new]
src/Metrics/VisitorMethodContext.php [new]
src/Metrics/CodeSmell/ControlFlow/ControlFlowSmells.php [new]
src/Metrics/CodeSmell/Debug/DebugCodeSmells.php [new]
src/Metrics/CodeSmell/BooleanArgument/BooleanArgumentSmells.php [new]
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionCollector.php [move existing]
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitor.php [move existing]
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionFinding.php [move existing]
src/Metrics/CodeSmell/RepeatedExpression/RepeatedExpressions.php [new]
src/Metrics/CodeSmell/RepeatedExpression/RepeatedConditions.php [new]
src/Metrics/CodeSmell/CodeSmellVisitor.php
src/Metrics/CodeSmell/ParameterCountVisitor.php
src/Metrics/CodeSmell/UnreachableCodeVisitor.php
src/Metrics/Complexity/CognitiveComplexityVisitor.php
src/Metrics/Complexity/CyclomaticComplexityVisitor.php
src/Metrics/Complexity/NpathComplexityVisitor.php
src/Metrics/Halstead/HalsteadVisitor.php
src/Metrics/Security/Credential/HardcodedCredentialsCollector.php [move existing]
src/Metrics/Security/Credential/HardcodedCredentialsVisitor.php [move existing]
src/Metrics/Security/Credential/CredentialLocation.php [move existing]
src/Metrics/Security/Credential/CredentialLiterals.php [new]
src/Metrics/Security/SecurityPatternVisitor.php
src/Metrics/Security/SensitiveParameterVisitor.php
src/Metrics/Size/MethodStatementCountVisitor.php
```

**Component documentation:**

```text
src/Metrics/README.md
src/Metrics/CodeSmell/README.md
```

**Tests:**

```text
tests/Unit/Metrics/CodeSmell/CodeSmellVisitorTest.php
tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionCollectorTest.php [move existing]
tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitorTest.php [move existing]
tests/Unit/Metrics/CodeSmell/ControlFlow/ControlFlowSmellsTest.php [new]
tests/Unit/Metrics/CodeSmell/Debug/DebugCodeSmellsTest.php [new]
tests/Unit/Metrics/CodeSmell/BooleanArgument/BooleanArgumentSmellsTest.php [new]
tests/Unit/Metrics/CodeSmell/RepeatedExpression/RepeatedExpressionsTest.php [new]
tests/Unit/Metrics/CodeSmell/RepeatedExpression/RepeatedConditionsTest.php [new]
tests/Unit/Metrics/CodeSmell/ParameterCountCollectorTest.php
tests/Unit/Metrics/CodeSmell/UnreachableCodeCollectorTest.php
tests/Unit/Metrics/Complexity/CognitiveComplexityVisitorTest.php
tests/Unit/Metrics/Complexity/CyclomaticComplexityVisitorTest.php
tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php
tests/Unit/Metrics/Halstead/HalsteadCollectorTest.php
tests/Unit/Metrics/Security/Credential/HardcodedCredentialsCollectorTest.php [move existing]
tests/Unit/Metrics/Security/Credential/HardcodedCredentialsVisitorTest.php [move existing]
tests/Unit/Metrics/Security/Credential/CredentialLiteralsTest.php [new]
tests/Unit/Metrics/Security/SecurityPatternVisitorTest.php
tests/Unit/Metrics/Security/SensitiveParameterVisitorTest.php
tests/Unit/Metrics/Size/MethodStatementCountCollectorTest.php
tests/Integration/Rules/PropertyHookControlPrecedenceTest.php
tests/Integration/Metrics/GoldenFileAggregationTest.php [read-only oracle; edited only in P6-R3]
tests/Integration/Architecture/DogfoodingTopologyTest.php
```

Compose live AST file-entry state through `VisitorFileEntryScope`, immutable
callable identity/projection through `VisitorCallableMetadata`, and expose both
through the `VisitorMethodContext` facade; visitor-specific metric logic stays
outside the three foundation helpers. `CodeSmellVisitor`,
`IdenticalSubExpressionVisitor`, and `HardcodedCredentialsVisitor` retain AST
traversal/delegation only; the six companions own their semantic policy and
return the existing `CodeSmellLocation`, `IdenticalSubExpressionFinding`, and
credential-location VOs. `RepeatedConditions` calls `RepeatedExpressions` only
for narrow expression equality; no generic matcher/helper is introduced.
Within `CodeSmellVisitor`, "traversal/delegation" also includes exactly three
trivial one-node residual projections that are not control-flow policy: `eval`,
error suppression (including the directly suppressed function-name payload),
and direct superglobal access. `CodeSmellVisitorTest` directly pins those three
residuals and proves they are not routed through `ControlFlowSmells`; no other
residual grab-bag is permitted.

`CredentialLiterals` covers exactly seven AST shapes: variable assignment,
string-key array item, class constant, `define()` call, property default,
parameter default, and enum case. The companion owns sensitive-name matching,
minimum length, repeated-character, dot-identifier, human-message, non-string,
and malformed-`define` exclusions; `CredentialLiteralsTest` exhaustively pins
all seven shapes and every exclusion, while `HardcodedCredentialsVisitorTest`
pins delegation and existing location identity.

Exactly two adjacent inline controls are permitted in R4. The first is on the
`CredentialLiterals` class, with these exact bytes:

```text
@qmx-ignore health.cohesion -- Stateless credential-literal shapes share one classification policy and location boundary.
```

This is a reviewed structural exception: the class is one cohesive stateless
classifier across seven AST shapes; computed health cohesion is an inverted
compound signal here, so a threshold is the wrong control surface, while
splitting the classifier would duplicate sensitive-name/value policy and the
location boundary.

The second and final control is on `HardcodedCredentialsVisitor`, with these
exact bytes:

```text
@qmx-ignore design.data-class -- Traversal adapter intentionally delegates credential policy and retains only lifecycle state.
```

After proper extraction the visitor has WOC 100 and WMC 6: it is a deliberately
thin traversal adapter whose only state is traversal/lifecycle and accumulated
locations. Adding fake behavior to satisfy DataClass would be metric gaming,
not design improvement. No third R4 inline control and no coupling suppression
is allowed.

P6-R4's sole `qmx.yaml`
responsibility is exactly three new metrics-foundation architecture pattern
lines beside the existing exact `VisitorMethodTrackingTrait` pattern plus one
adjacent documentation-only topology comment replacement:

```text
# replace: "These three implementation primitives are shared ..."
# with:    "These cross-category implementation primitives are shared ..."
Qualimetrix\Metrics\VisitorFileEntryScope
Qualimetrix\Metrics\VisitorCallableMetadata
Qualimetrix\Metrics\VisitorMethodContext
```

P6-R4 must not change any `qmx.yaml` exclusion, metric/rule threshold, formula,
or other dogfooding policy; its `qmx.yaml` delta is exactly those three pattern
additions and that one stale numeric-comment replacement (four changed or added
lines total), with no other delta. The exact `CredentialLiterals` annotation
and exact `HardcodedCredentialsVisitor` annotation above are the sole R4
inline-control exceptions. The six companions live under the existing
CodeSmell/Security category layers and require no `qmx.yaml` pattern addition.

**DoD:** all 12 current trait consumers remain covered; the seven assigned
fresh groups clear; nested functions/closures/hooks and anonymous classes
retain identity; the unbaselined workers=0 package scan from the common verifier
has no assigned or unexpected fresh identity for any of
`VisitorFileEntryScope`, `VisitorCallableMetadata`, or `VisitorMethodContext`;
the same identity-set assertion is empty for five companions and the two
delegation visitors `CodeSmellVisitor` and `IdenticalSubExpressionVisitor`;
`CredentialLiterals` has no assigned or unexpected identity except its exact
reviewed/suppressed `health.cohesion` identity, and
`HardcodedCredentialsVisitor` has none except its exact reviewed/suppressed
`design.data-class` identity. A second exact workers=0 scan
`php bin/qmx check src/ --config=qmx.yaml --format=json --workers=0 --show-suppressed`
records exactly those two identities once each with their exact annotation
reasons, while the normal unbaselined scan excludes both. Neither is unresolved
fresh debt or a baseline candidate. The promoted-property
boolean-argument finding is absent in the focused and unbaselined results; the direct visitor
regression covers exactly eval, error suppression payload, and direct
superglobal residual projection, while the control-flow companion regression
covers exactly empty catch/chain exception, goto, exit/die, and count/sizeof in
for/while/do conditions; neither side accepts the other's cases and no
unclassified residual remains. `ControlFlowSmellsTest` proves the iterative DFS
on nested expressions and closure/arrow exclusion. `RepeatedExpressionsTest`
exhaustively proves all 19 concrete classes through their official sigils, the
eight non-suspicious controls, early binary/ternary paths, iterative structural
equality, iterative side-effect scan, and every enumerated side-effect kind;
the production class imports no concrete BinaryOp subtype and needs no coupling
control. `CredentialLiteralsTest` proves all seven AST shapes and all
named safe exclusions, and `HardcodedCredentialsVisitorTest` proves exact
delegation/location behavior. The normal unbaselined scan has no
`size.class-count` finding for parent namespaces `Metrics\CodeSmell` and
`Metrics\Security` or child namespaces `CodeSmell\ControlFlow`,
`CodeSmell\Debug`, `CodeSmell\BooleanArgument`,
`CodeSmell\RepeatedExpression`, and `Security\Credential`. Direct topology
evidence proves every cross-boundary consumer depends only on its named child
subject and the sole companion-to-companion edge is
`RepeatedConditions -> RepeatedExpressions`;
`qmx.yaml` contains exactly the three new metrics-foundation patterns plus the
accurate non-numeric adjacent topology comment, and its complete R4 diff is
exactly those four lines with no policy/control/exclusion/threshold/formula or
other config delta; and the unbaselined direct architecture
check
`php bin/qmx check src/ --config=qmx.yaml --only-rule=architecture.layer-violation --workers=0`
exits zero with complete coverage. Inspect its output to prove
all three new types are assigned to metrics-foundation and introduce no
forbidden edge. Update the expected root primitive inventory in
`DogfoodingTopologyTest` for all three types. Add exact child-stack assertions:

```text
CodeSmell\RepeatedExpression = {
  IdenticalSubExpressionCollector, IdenticalSubExpressionVisitor,
  IdenticalSubExpressionFinding, RepeatedExpressions, RepeatedConditions
}
Security\Credential = {
  HardcodedCredentialsCollector, HardcodedCredentialsVisitor,
  CredentialLocation, CredentialLiterals
}
CodeSmell parent repeated-expression remnants = {}
Security parent credential remnants = {}
```

The topology test pins the allowed stack dependencies enumerated in R1 and
rejects every other child-to-child edge. Run
`vendor/bin/phpunit tests/Integration/Architecture/DogfoodingTopologyTest.php`;
the full topology test must exit zero. A mechanical namespace/reference audit
finds zero old subject-owned file paths or old FQCN imports in `src/` and
`tests/`; every collector/visitor/VO/helper reference resolves to its child
namespace; and `composer phpstan` plus the focused tests compile. The only Rules
hit for `IdenticalSubExpression` is a non-import comment in
`src/Rules/CodeSmell/IdenticalSubExpressionRule.php`; verify it remains
non-binding, and update it only if it contains a stale namespace/path.

`src/Metrics/README.md` preserves all current metric algorithms, deviation
notes, and existing structure entries while adding the exact three
metrics-foundation entries, three singleton companion paths, and both complete
RepeatedExpression/Credential subject stacks. `src/Metrics/CodeSmell/README.md`
owns the exact RepeatedExpression moved/new paths, responsibilities, dependency
topology, and direct tests.
It documents live scope vs immutable metadata vs facade responsibilities, the
six semantic-policy responsibilities and existing VO return boundaries, the
sole `RepeatedConditions -> RepeatedExpressions` dependency, complete
12-visitor consumer set listed in this package, dependency direction, absence
of metric-specific state in the foundation helpers, the exact enumerated
ControlFlow-vs-trivial-residual split above, and the exact focused tests above.
Existing visitor tests remain integration/delegation contracts and directly own
the three trivial residual projections; the six new tests directly own
companion semantics. The README records the exact CredentialLiterals cohesion
annotation/stateless-seven-shape justification and the exact
HardcodedCredentialsVisitor DataClass annotation/thin-traversal-adapter
justification as two internal dogfood exceptions, not metric behavior changes
or accepted baseline debt. All focused visitor/companion tests
and `GoldenFileAggregationTest` must prove behavior and golden values unchanged.
P6-R10 runs later for NPATH dispatch and its
separate config responsibility; it does not edit `src/Metrics/README.md`
because it changes no metric formula, public metric contract, or Metrics file
inventory. If implementation disproves that premise, R10 stops and this plan is
revised before a documentation edit.

#### P6-R5 — derived collector execution

**Depends on:** P6-R4 and the corrected derived-metric semantics already pinned by P5.
**Production files:**

```text
src/Analysis/Collection/Metric/CompositeCollector.php
src/Analysis/Collection/Metric/DerivedCollectorRunner.php [new]
src/Analysis/Collection/Metric/DerivedMetricExtractor.php
```

**Component documentation:**

```text
src/Analysis/README.md
```

**Tests:**

```text
tests/Unit/Analysis/Collection/Metric/CompositeCollectorTest.php
tests/Unit/Analysis/Collection/Metric/DerivedCollectorRunnerTest.php [new]
tests/Unit/Analysis/Collection/Metric/DerivedCollectorSortTest.php
tests/Unit/Analysis/Collection/Metric/DerivedMetricExtractorTest.php
```

Extract topological ordering/execution and derived result application while
leaving base collector traversal in `CompositeCollector`. Direct tests pin
missing dependencies, cycles, deterministic ties, multi-level derivation, and
empty collections. **DoD:** five fresh groups and the `collect` drift clear;
derived metric order and values are byte-for-byte stable. R5 preserves R2's
Collection declaration/source-control entries and R3's Repository entries and
responsibility contracts in `src/Analysis/README.md`, then adds
`DerivedCollectorRunner.php` to the Collection/Metric structure. The runner
entry documents its topological ordering/execution responsibility, base and
derived metric inputs, stable derived-result output/application boundary,
dependency direction toward derived collectors/extractor rather than pipeline
or repository orchestration, and the exact focused runner/sort/extractor tests
listed above. It describes only verified R5 implementation.

#### P6-R6 — layer-owned targets and finding construction

**Depends on:** P6-R5. **Production files:**

```text
src/Architecture/Rules/LayerViolationRule.php
src/Architecture/Rules/OwnedLayerTargets.php [new]
src/Architecture/Rules/LayerViolationFinding.php [new]
```

**Component documentation:**

```text
src/Architecture/README.md
```

**Tests:**

```text
tests/Architecture/Unit/Rules/LayerViolationRuleTest.php
tests/Architecture/Integration/LayerViolationIntegrationTest.php
tests/Architecture/Integration/InlineSuppressionLayerViolationIntegrationTest.php
```

The responsibility boundary is exact. `LayerViolationRule` owns layer matches,
null/unresolved handling, allowed-policy decisions, recommendation construction,
and coverage accounting. `OwnedLayerTargets` is only the deterministic logical
target-to-owned-declarations index. `LayerViolationFinding` receives a
policy-approved edge plus the already-built ordered target list and owns 0..N
materialization: source-declaration fallback when the list is empty, one exact
finding per target otherwise, and complete `Violation` construction. It does
not evaluate policy, build recommendations, or own coverage state. No DoD
depends on claiming that this boundary removes a particular class dependency.

Tests pin zero/one/many targets, canonical target order, source fallback,
duplicate declarations, source/target and physical controls, structured edges,
semantic occurrences, and retained source use-site location. They also prove
allowed and unresolved edges never reach finding materialization, while
recommendation and coverage behavior remain owned by and unchanged in the
rule. **DoD:** the method-count fresh group is absent and `LayerViolationRule`
WMC returns to <=73 without changing ADR 0021/P5's LayerViolation 0..N target
and independent-control matrix. The same pinned workers=0 scan records exactly
`LayerViolationRule` coupling CBO 24 and `Architecture\\Rules` namespace
instability 26 / (26 + 2) = 0.928571. Those two mapped rows are reviewed
structural outcomes, not fixed rows and not unexpected fresh identities; no
other assigned or unexpected fresh identity remains.

Before P5-F, R6 and every later production package are forbidden from changing
any threshold, exclusion, formula, control, suppression, or baseline entry for
these two rows. R6 attaches exact dependency evidence: the class outcome is the
old dependency set minus the extracted `MatchedCriterion` and
`MatchedCriterionKind` edges, plus the honest P5 `MetricSubject` identity edge
and the two cohesive `OwnedLayerTargets` and `LayerViolationFinding` helper
edges, yielding Ca=1, Ce=23, CBO=24; the namespace union retains the moved
criterion edges and is therefore the old Ce=24 set plus exactly
`MetricSubject` and `OccurrenceKey`, with Ca=2 and Ce=26. Any additional edge,
different value, generic regeneration, or third exception fails R6 rather than
being accepted.

In the same package, `src/Architecture/README.md` preserves all prior
Architecture contracts, including ADR 0021's declaration-scoped 0..N target
projection and independent source/target control matrix, and adds both exact
new paths to the Rules structure. It documents `OwnedLayerTargets` as the
owned-target resolution boundary and `LayerViolationFinding` as the immutable
finding-to-`Violation` construction boundary; for each, it records exact
logical/declaration/control inputs, resolved-target or violation-ready outputs,
dependency direction, the exact Rule/index/finding boundary above, and the
three focused unit/integration test contracts listed above. It describes only
verified R6 implementation.

#### P6-R7 — pipeline, dependency, and aggregation hotspots

**Depends on:** P6-R6. **Production files:**

```text
src/Analysis/Aggregator/NamespaceMetricContributions.php
src/Analysis/Collection/CollectionOrchestrator.php
src/Analysis/Collection/Dependency/DependencyGraphBuilder.php
src/Analysis/Collection/Dependency/DependencyVisitor.php
src/Analysis/Pipeline/AnalysisPipeline.php
src/Analysis/Pipeline/DependencyGraphAnalyzer.php
```

**Component documentation:**

```text
src/Analysis/README.md
```

Stage 1 is complete and rejects the six-file coupling-health hypothesis. The
authoritative report is
`/private/tmp/qmx-r7-attribution-IzITsF/REPORT.md`. Stage 1 is complete only
through `/private/tmp/qmx-r7-attribution-IzITsF/evidence-manifest.json` with
SHA-256
`19615504547415c9ba1a06d59319e382fc15e13687bb9734000ca3f32107e952`;
the manifest pins `REPORT.md` SHA-256
`8ed6a09ba3c43550c400077f9426815f8debfb22ec3003f37c403df31b11d792`
and every artifact below. The report is backed by
`dependency-attribution.json`, `logical-edge-differential.json`,
`class-coupling-differential.json`, `fence-decision.json`, and
`formula-trace.json` in the same scratch directory. The pinned no-baseline,
no-cache, workers=0 current scan ran
`php bin/qmx check src/ --config=qmx.yaml --format=json --workers=0 --no-cache`
at revision `e04e88769640e5651b6a44ac10419a76c726f7a1` plus the pre-existing
dirty R2-R6 tree. It used `qmx.yaml` SHA-256
`6842b36e117438eb393577129109eeb028649c5a0a61468081f0971530ec6862`
and `src/**/*.php` content-list SHA-256
`aad20356b2fdcdc23e328c4f7e0fc2d66db47d732fa2f6ea90e25e528881f02b`;
coverage was complete at 689 discovered/analyzed, failed 0, generated excluded
0, and project `health.coupling=47.62489016022897`. The read-only executable
comparison snapshot is `ba1119a0`, complete at 666/666 and
`health.coupling=48.07282582696504`, the approved reproducible proxy for the
frozen 48.0 floor. Scratch metadata must retain both source identities, exact
commands/options, config/source hashes, coverage, exit codes, formula inputs,
and artifact hashes; the current command's exit 2 is finding-derived, not an
analysis failure.

The complete manifest-pinned run fields are:

| Field                   | Approved old run                                                                                                                                                                                                                                                                                                                                                                                                | Current Stage 1 run                                                                                                                                                                                                                                                                                                |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Source identity         | read-only `git archive ba1119a0bd87afa8f17f3ddbbfd43683c5db5ec5`; snapshot `/private/tmp/qmx-r7-attribution-IzITsF/checkpoint-ba1119a0`; read-only `vendor` symlink                                                                                                                                                                                                                                             | dirty linked worktree at HEAD/runner `e04e88769640e5651b6a44ac10419a76c726f7a1`                                                                                                                                                                                                                                    |
| Runner                  | repository-root cwd; repo-relative `bin/qmx`; runner revision `e04e88769640e5651b6a44ac10419a76c726f7a1`                                                                                                                                                                                                                                                                                                        | same cwd, executable, and runner revision                                                                                                                                                                                                                                                                          |
| Source/config proof     | config `09a7723dc063353357e0c0eb36a0ab73e79e469d03851bf369623f1c7406b65f`; PHP content list `454c9f5a5cc5e2dc8e9bd92735547d3dfed338ebb6a604d014a3d7369e588da2`; accepted floor `48.0`                                                                                                                                                                                                                           | diff `fd00cbb4ced5c7d1562194edcce9e28426370fb8053913fe179316c9c58ee43c`; status `c793982ddc6cef05d4f2d880b7fb8d1c5ee1dc1749b897ff035cdd231c5e3eb6`; config `6842b36e117438eb393577129109eeb028649c5a0a61468081f0971530ec6862`; PHP content list `aad20356b2fdcdc23e328c4f7e0fc2d66db47d732fa2f6ea90e25e528881f02b` |
| JSON / metrics commands | `php bin/qmx check /private/tmp/qmx-r7-attribution-IzITsF/checkpoint-ba1119a0/src --config=/private/tmp/qmx-r7-attribution-IzITsF/checkpoint-ba1119a0/qmx.yaml --format=json --workers=0 --no-cache`<br>`php bin/qmx check /private/tmp/qmx-r7-attribution-IzITsF/checkpoint-ba1119a0/src --config=/private/tmp/qmx-r7-attribution-IzITsF/checkpoint-ba1119a0/qmx.yaml --format=metrics --workers=0 --no-cache` | `php bin/qmx check src/ --config=qmx.yaml --format=json --workers=0 --no-cache`<br>`php bin/qmx check src/ --config=qmx.yaml --format=metrics --workers=0 --no-cache`                                                                                                                                              |
| Options / exit          | no baseline, preset, rule selection, CLI source exclusion, or cache; workers 0; JSON/metrics exit 2                                                                                                                                                                                                                                                                                                             | identical options; JSON/metrics exit 2                                                                                                                                                                                                                                                                             |
| Coverage                | complete, discovered/analyzed 666/666, failed 0, generated excluded 0                                                                                                                                                                                                                                                                                                                                           | complete, discovered/analyzed 689/689, failed 0, generated excluded 0                                                                                                                                                                                                                                              |
| Formula proof           | inputs `distance.avg=0.34178858267353496`, `cbo.avg=9.424287856071963`, `cbo.p95=28`, `cbo.max=128`; penalty `19.443191013546432`; exact `48.07282582696504`, reported `48.1`                                                                                                                                                                                                                                   | inputs `distance.avg=0.32845859553152834`, `cbo.avg=9.648335745296672`, `cbo.p95=27`, `cbo.max=132`; penalty `19.79536275976885`; exact `47.62489016022897`, reported `47.6`                                                                                                                                       |

Both runs use the manifest's exact computed-health expression; the health delta
is `-0.4479356667360648` and penalty delta `+0.3521717462224174`.

```text
clamp(100 * 18 / (18 + (distance__avg ?? 0) * 6
  + max((cbo__avg ?? 0) - 8, 0) * 3
  + max((cbo__p95 ?? 0) - 15, 0) * 0.4
  + max((cbo__max ?? 0) - 30, 0) ** 0.5 * 0.8), 0, 100)
```

| Manifest artifact                             | SHA-256                                                            |
| --------------------------------------------- | ------------------------------------------------------------------ |
| `REPORT.md`                                   | `8ed6a09ba3c43550c400077f9426815f8debfb22ec3003f37c403df31b11d792` |
| `dependency-attribution.json`                 | `7feb41860feb13c151d9ec7f4b5e5a8ee80ce6767b931538b54b356ffde7f630` |
| `logical-edge-differential.json`              | `b8277278694081febd0af34fe93b0a01f4fda39c70572e9a0eaf4cae112e0d42` |
| `class-coupling-differential.json`            | `c558e06ff08fb3722fe5e39ca9a69a2d0b1e0c41a8f715b8b264931fe254b8b2` |
| `fence-decision.json`                         | `c097e1fff507df722612b0385366856c34897471717765fba2b95cb12786d337` |
| `formula-trace.json`                          | `c98673e4a2775f3b09ea74a5288cfc53d767490c7a89a15303769b048f18803a` |
| `collect.php`                                 | `6f818687a186ffd86399fdf72567f448cfbc11cc808fd06650dbc206cd3ff70a` |
| `pinned-unbaselined.json`                     | `e76bcf10244f665311ab1a641dae4aed749193e211a5f070918cde40f3acf9f1` |
| `pinned-metrics.txt`                          | `c3daba076e4ed3f1ddf30b3d48a2966ea69dad83bdff7fbcddd67dc03003ad01` |
| `checkpoint-ba1119a0/pinned-unbaselined.json` | `edfead8c461915b977c040f91566afeb8f8e5741d60525d46306d62da64af3ec` |
| `checkpoint-ba1119a0/pinned-metrics.json`     | `8031ae1de08c91ba965e368b9e5bec62bb8f43c986365c871e5ab7b72bedfc01` |

Before R7 implementation, recompute the manifest file hash and every listed
artifact hash; any mismatch makes Stage 1 unverified and stops R7 for a new
read-only attribution, never for silent evidence refresh.

The differential is finite: 354 added logical edges, 159 removed, and 15 with
changed type sets, owned by 107 distinct declaring production paths. Exactly
four owners are inside the six-file fence and 103 are outside; the authoritative
inside/outside lists and 528 edge records are in `fence-decision.json` and
`logical-edge-differential.json`. Therefore there is no mechanical 103-file
fence expansion. R7 proceeds only for the six bounded file-local hotspots
already assigned below; project coupling health is removed from R7 production
ownership and deferred to the global post-R11 gate.

Formula changes, threshold changes, point controls, suppressions, `qmx.yaml`
exclusions, baseline edits, generic current-magnitude acceptance, and generic
expansion to the 103 outside owners are forbidden responses.

The reviewed implementation design is closed and needs no new production file,
class, DTO, or helper subject:

| File / exact hotspots | Private operation/result boundary | Typed inputs -> outputs and key pseudocode | Retained/removed dependencies and invariants | Exact direct test cases |
| --- | --- | --- | --- | --- |
| `src/Analysis/Aggregator/NamespaceMetricContributions.php`; fresh WMC 51 | Replace `collectFromCallables()`, `collectFromClasses()`, and `collectFromFunctions()` with private `collectFromSymbols()`; keep existing `appendValues()` | `MetricRepositoryInterface + list<SymbolInfo> + list<MetricDefinition> + target SymbolLevel -> array<string,list<int\|float>>`; one-pass dispatch: `Function_ -> Callable`; `type != null && member != null -> Callable`; `type != null && member == null -> Class_`; otherwise skip; append through existing boundary | Retain repository/metric/symbol dependencies; add none. Explicit namespace contributions stay explicit; sum-only total is included once; project derives only from physical file bags; physical-file attribution is unchanged | `NamespaceMetricContributionsTest`: one matrix for exact callable declaration, class, global function, file metric, namespace-owned sum, and non-sum/count expansion; project ignores namespace-owned value; mapping case covers one file/multiple namespaces, duplicate logical declaration, aggregate-only class, and one physical-file contribution per namespace |
| `src/Analysis/Collection/CollectionOrchestrator.php`; fresh CBO 20; **Breaking constructor change** | Private `foldResults(iterable $results, MetricRepositoryInterface $repository): CollectionPhaseOutput`; `collect()` is only empty fast path -> strategy execution -> fold | `iterable<FileProcessingResult> -> CollectionPhaseOutput(CollectionResult, list<Dependency>)`; successful terminal result registers exact declarations before derived extraction and contributes dependencies/controls; failure contributes only typed failure; progress/logging exactly once per terminal result | Remove concrete `NullProgressReporter` and `NullLogger` edges; retain `ProgressReporter` and `LoggerInterface`. Result order is stable; failures never leak metrics/dependencies/controls. Old constructor calls could omit logger/progress and receive concrete defaults; the new constructor requires explicit `ProgressReporter` and `LoggerInterface`. Per BC policy there is no default, overload, nullable fallback, or compatibility shim | `CollectionOrchestratorTest`: mixed success/parse failure/processing failure; exact analyzed/failure/dependency order; no failure payload; suppressions, threshold overrides/diagnostics retained; one progress advance per terminal result; preserve empty and all-failed cases; mechanical constructor-callsite check below |
| `src/Analysis/Collection/Dependency/DependencyGraphBuilder.php`; fresh maintainability 47.5; `build` cognitive 20 -> <=16, CCN 15 -> <=12, NPATH 2368 -> <=592 | Linear `build()` calls private `retainGraphDependencies()`, `indexGraphInputs()`, `expandNamespaceUniverse()`, `computeNamespaceCouplings()`, existing parent/class projections, then constructs `DependencyGraph`; `indexGraphInputs()` returns `{bySource: array<string,list<Dependency>>, byTarget: array<string,list<Dependency>>, classes: array<string,SymbolPath>, leafNamespaces: array<string,SymbolPath>}`; combine CE/CA into one coupling pass returning `{ce: array<string,StringSet>, ca: array<string,StringSet>}` | Exact dependencies + explicit logical universe -> queryable graph. Retain `Dependency`, `DependencyType`, `LogicalClassPath`, `SymbolPath`, `NamespaceTree`, `StringSet`, and builtin registry; add no dependency edge. Filter builtin target except `Extends`; keep declared degree-zero vertices and undeclared external targets; create parents after leaf universe; sibling edge is internal to common parent; class/namespace CE/CA deduplicate logical endpoints | New `tests/Unit/Analysis/Collection/Dependency/DependencyGraphBuilderTest.php`: empty dependencies + degree-zero class; undeclared external target; builtin non-extends filtered and builtin extends retained; duplicate edges yield CE/CA 1; sibling namespace edge internal to parent; external edge yields parent CE/CA. Run `CycleIdentityStabilityTest` read-only for permutation and unrelated-vertex identity/path stability |
| `src/Analysis/Collection/Dependency/DependencyVisitor.php`; fresh instability 0.807692; CBO 26 -> <=24; `enterNode` CCN 15 -> <=14, NPATH 1440 -> <=576 | Use `PhpParser\Node\Stmt\ClassLike` for named class/interface/trait/enum; retain `Class_` only for anonymous class. Private `consumeNamespaceOrImport(Node): bool`, `enterNamedClassLike(Node): bool`, `consumeAnonymousClass(Node): bool`, `dispatchInCurrentContext(Node): void`; `enterNode()` is ordered early returns; `leaveNode()` handles named `ClassLike` | Node traversal -> exact declaration-scoped dependencies. Remove `Interface_`, `Trait_`, `Enum_`; add `ClassLike`, net -2 edges. Preserve namespace reset/import order, required file path and `startFilePos`, enclosing context for anonymous class, ownerless skip, flush only on named-class exit, and full visitor/resolver reset | `DependencyVisitorTest`: matrix class/interface/trait/enum with exact source declaration; anonymous inside/outside; two namespace blocks without import leakage; reuse between files; every handler family remains covered; new methods use `itXxx` while existing legacy names need not be renamed |
| `src/Analysis/Pipeline/AnalysisPipeline.php`; fresh `analyze` NPATH 81920; WMC 56 -> <=52 | Resolve profiler once, then ordinary `start/stop`; linear `analyze()` calls private `buildDependencyGraph(list<Dependency>, MetricRepositoryInterface): DependencyGraph`, `prepareArchitectureForRun(graph, repository, onlyRules, disabledRules): void`, and `executeRulesForRun(repository, graph, EnrichmentResult, CollectionResult): list<Violation>`; reuse `CollectionPhaseOutput`, `EnrichmentResult`, `AnalysisCoverage`, `AnalysisResult`; replace manual match/filter loops with `array_any()` and filter/map | Preserve strict Discovery -> Collection -> graph build/free raw dependencies -> conditional Architecture prepare -> Enrichment -> Rules/annotation diagnostics -> Coverage -> Result. Architecture prepares only for enabled producer; enrichment receives analyzed files only; rules receive enrichment and threshold overrides; coverage uses the same frozen collection result. Add no phase DTO, production class, dependency direction, formula, selector, or output projection | `AnalysisPipelineTest`: recording collaborators prove collection -> architecture prepare -> enrichment-visible repository -> rule execution; separate disabled Architecture, empty collection, typed failure, degree-zero dependency universe, exact `AnalysisResult`; preserve coverage mismatch and diagnostic cases |
| `src/Analysis/Pipeline/DependencyGraphAnalyzer.php`; fresh `declaredLogicalClasses` CCN 14 | Replace four-way class-like match with `ClassLike`; recurse only through namespace blocks; skip non-`ClassLike` and anonymous nodes; named class-like maps to `LogicalClassPath(SymbolPath::forClass(...))` | `list<Node> + namespace -> list<LogicalClassPath>`; canonical-key dedup precedes builder. Remove `Class_`, `Interface_`, `Trait_`, `Enum_`; add `ClassLike`, net -3 edges. Preserve class/interface/trait/enum, global and bracketed namespaces, anonymous exclusion, degree-zero declarations, external targets, and successful files beside parse/processing failures | `DependencyGraphAnalyzerTest`: one fixture containing all four named kinds, anonymous class, global/bracketed namespaces, degree-zero declarations, and external target; assert vertices and coverage; retain mixed parse/processing-failure case |

`DependencyGraphBuilderTest.php` belongs to dependency-graph construction: it
owns vertex universe, builtin filtering, indexing, CE/CA, and parent projection.
`CycleIdentityStabilityTest.php` remains a read-only builder/detector/rule seam
oracle and does not own builder implementation. The constructor change is a
Breaking outward PHP contract and requires the exact P7 CHANGELOG/component
migration below. ADR and R7-specific website edits are not authorized: a
literal search found no `CollectionOrchestrator` constructor reference anywhere
under `website/`; the unrelated `LoggerInterface` examples there do not expose
this constructor.

The `CollectionOrchestrator` constructor change is explicitly Breaking: the old
signature allowed callers to omit `ProgressReporter` and `LoggerInterface` and
silently constructed null implementations, while the new signature makes both
collaborators mandatory. The change has an explicit all-callsite gate. The
reviewed inventory contains no direct `new CollectionOrchestrator`
under `src/`; `AnalysisConfigurator` registers the service with all five
arguments, including `DelegatingProgressReporter` and `DelegatingLogger`.
There are exactly 12 direct constructions, all in
`tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php`, and every one
already supplies both logger and progress, directly or through its two local
factory methods. `TestPipelineBuilder` and production consumers depend on
`CollectionOrchestratorInterface` and do not construct it. Immediately before
editing, rerun this complete `src/` + `tests/` constructor/DI/factory inventory
and compile all callsites. If any production or test construction omits either
mandatory collaborator, R7 stops for a reviewed ownership update; it must not
add defaults, nullable fallbacks, overloads, or a compatibility shim.

**Tests:**

```text
tests/Unit/Analysis/Aggregator/NamespaceMetricContributionsTest.php
tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php
tests/Unit/Analysis/Collection/Dependency/DependencyGraphBuilderTest.php [new]
tests/Unit/Analysis/Collection/Dependency/DependencyVisitorTest.php
tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php
tests/Unit/Analysis/Pipeline/DependencyGraphAnalyzerTest.php
tests/Unit/Analysis/Collection/Dependency/CycleIdentityStabilityTest.php
```

Use private, named phase results and single-purpose dependency projection
steps; do not alter the Discovery -> Collection -> Aggregation -> Rules order.
Tests pin empty/failed collection, logical/declaration graph vertices, external
targets, cycle identity, and aggregation invariants. **DoD:** six assigned fresh
groups are absent and the seven active code drift rows return to their
direction-aware old boundaries: `DependencyVisitor` CBO <=24; `enterNode` CCN
<=14 and NPATH <=576; `DependencyGraphBuilder::build` cognitive <=16, CCN <=12,
and NPATH <=592; `AnalysisPipeline` WMC <=52. The same scan records
`Analysis\\Collection` namespace CBO <=19 as a read-only cross-package oracle
(current Stage 1 value 18); R7 does not claim or modify that already resolved
row. Project `health.coupling` is reported but is not an R7 package DoD or
production-owned row. Project outputs and phase ordering remain stable.

R7 edits `src/Analysis/README.md` because the six existing components' phase
and dependency-projection responsibilities change even though no new
production class is added. It preserves every R2 declaration/source-control,
R3 repository, and R5 derived-runner structure entry and contract, then updates
only the six verified entries from the design table: namespace contribution
classification/physical-file attribution; collection strategy/fold and typed
failure handling; graph filtering/indexing/universe/CE-CA/parent projection;
visitor class-like context/dispatch/reset; complete pipeline phase order and
named existing result shapes; analyzer class-like discovery/canonical universe.
For each it maps the exact typed inputs, outputs, retained/removed dependency
direction, invariants, and named focused tests above, including
`DependencyGraphBuilderTest` as the graph-construction owner and
`CycleIdentityStabilityTest` only as a seam oracle. It records mandatory
logger/progress injection without a compatibility shim and an exact component
migration note: old direct construction could omit both collaborators; new
direct construction passes explicit `ProgressReporter` and `LoggerInterface`;
the shipped DI configuration already supplies delegating implementations. It
must not document the rejected six-file global-health ownership hypothesis or
invent a seventh responsibility/component.

#### P6-R8 — baseline parsing, explanation, and suppression responsibilities

**Depends on:** P6-R7 and renewed P6-R1 approval of the proposed production
path plus its direct test.
**Production files:**

```text
src/Baseline/BaselineEntryParser.php
src/Baseline/BaselineEntryValues.php [new; P6-R1 review required]
src/Baseline/BaselineCapture.php
src/Baseline/BaselineGenerator.php
src/Baseline/BoundaryExplanationService.php
src/Baseline/Suppression/SuppressionExtractor.php
```

**Component documentation:**

```text
src/Baseline/README.md
```

**Tests:**

```text
tests/Unit/Baseline/BaselineEntryParserTest.php
tests/Unit/Baseline/BaselineEntryValuesTest.php [new]
tests/Unit/Baseline/BaselineGeneratorTest.php
tests/Unit/Baseline/BoundaryExplanationServiceTest.php
tests/Unit/Baseline/Suppression/SuppressionExtractorTest.php
tests/Integration/Baseline/BaselineWorkflowTest.php
tests/Integration/Baseline/ThresholdAnnotationParserPathTest.php
```

The first implementation attempt is stopped: two complete pinned scans prove
the four-owner design cannot meet its own gate without metric gaming.
`BaselineGenerator` honestly reaches Ce=8, Ca=2, instability 0.8, but the rule
fires at `>=0.8`; removing another mandated dependency or inventing an incoming
edge is forbidden. The strict-reader parser remains WMC 52 and the rule fires
at `>=50`; further in-class branch rearrangement does not cross the boundary.
The supplied `/private/tmp/qmx-p6-r8-evidence/selfscan.json` is a complete
689/689 intermediate scan (it records parser WMC 56 before the final reader
reduction); the later repeated complete scan establishes WMC 52 and the same
generator 0.8 boundary. Boundary explanation is verified back at WMC 54 and
instability 0.888889 with its assigned new CCN groups absent, and suppression
is verified clear, so the interim disposition is exactly five of seven fresh
groups and all three drift rows cleared, with parser WMC and generator
instability still unresolved. This is progress evidence, not a ledger
reclassification: R8 continues to own all seven fresh and three drift rows.

The corrected design adds the exact R1-gated value subject above and reuses
the existing `BaselineCapture` result boundary. No generic helper, second new
production class, namespace, config/baseline/control change, or fake dependency
is authorized.

| Existing owner; exact assigned rows                                                                                                            | Private operation/result boundary and pseudocode                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | Dependencies and invariant                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | Focused cases in the seven-file fence                                                                                                                                                                                                                                                                                                                                                                          |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BaselineEntryParser`: fresh WMC                                                                                                               | Keep public `parse(string $subjectKey, mixed $raw): BaselineEntry\|InertBaselineEntry`, identity parsing, declaration lookup/shape validation, and exact `readRequiredNonEmptyString`, `readOptionalNonEmptyString`, `readOptionalObject`, `readEdge`, `readEdgeType`, and `describe` boundaries. Move only count/magnitudes/mode decoding to `BaselineEntryValues::decode(array $raw): self`; its constructor is private and exposes readonly `int $count`, `?array $magnitudes` (`?list<int\|float>`), and `?BaselineEntryMode $mode`. `decode` owns exact private `readRequiredInt(array $object, string $field, string $label): int`, `readOptionalList(array $object, string $field, string $label): ?array` (`?list<mixed>`), `readMagnitudes(array $raw): ?array` (`?list<int\|float>`), and `readMode(array $raw): ?BaselineEntryMode`; every defect throws the existing `BaselineEntryRejection` reason/message for the parser's identity-known catch. Pseudocode: parser establishes identity -> value VO decodes value fields -> parser constructs `BaselineEntry` and checks declaration shape.                                                                                                                                                                                                                                                                                                                                                                           | Parser loses the four value-decoding branches that left it at WMC 52; the new VO depends only on `BaselineEntryMode`, `BaselineEntryRejection`, and `InertEntryReason`, with one real production consumer, so its expected instability is `3/(3+1)=0.75`, below the strict 0.8 boundary. It owns no identity, edge, registry, grouping or output policy. Malformed v11 input still never escapes public `parse`; exact/raw selectors and payload are preserved.                                                                                                                           | New `BaselineEntryValuesTest`: absent/null/valid/invalid count, list, numeric member, finite-value constructor rejection, absent/null/valid/unknown mode, and exact rejection reasons/messages. `BaselineEntryParserTest`: identity/edge/registry/shape integration, value-VO rejection mapping, selectors and raw payload. `BaselineWorkflowTest`: exact v11 round trip.                                      |
| `BaselineGenerator`: fresh instability                                                                                                         | Keep public constructor and `generate(array $violations, array $scope): BaselineCapture`. Keep exact `groupViolations(array $violations): array` and `captureGroup(BaselineIdentity $identity, array $group): BaselineEntry\|UncapturedReason` contracts and deterministic first-seen grouping. Add `BaselineCapture::fromRejectedGroups(Baseline $baseline, array $rejected): self` with `@param list<array{identity: BaselineIdentity, reason: UncapturedReason, memberCount: positive-int}> $rejected`; it alone maps those records to `UncapturedGroup` VOs and calls the existing constructor. Generator accumulates those typed records rather than constructing `UncapturedGroup`, then calls the factory. Clock and registry remain honest constructor dependencies; `$clock->now()` is called exactly once and `captureGroup` alone queries declarations.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | This moves outcome materialization to the existing result that owns `uncaptured`, removing Generator's real `UncapturedGroup` edge without adding a replacement edge: Ce 8 -> 7, Ca remains 2, so instability becomes `7/(7+2)=0.777778`, strictly below 0.8. `BaselineCapture` adds only existing sibling `BaselineIdentity` and `UncapturedReason` edges to its already-owned Baseline/UncapturedGroup result boundary; its own fresh set must remain empty. No caller/fake incoming edge is added. Group identity, order, count, finite magnitude and refusal invariants remain exact. | `BaselineGeneratorTest`: literal Ce/Ca self-scan oracle plus clock/scope, deterministic grouping/refusal order, same-FQN subjects, occurrence/edge/selectors, magnitude and both refusal types; assert `fromRejectedGroups` produces exact reason/identity/member count and empty input. `BaselineWorkflowTest`: capture/write/load/application unchanged.                                                     |
| `BoundaryExplanationService`: fresh CBO plus `repositoryContains`/`subjectForIdentity` CCN; drift WMC 54 -> 70 and instability 0.888889 -> 0.9 | Keep public `explain(string $subjectKey, ?ViolationChannel $channelFilter, ?Baseline $baseline, array $measuredViolations, array $thresholdOverridesByFile, array $configuredThresholds, ?MetricRepositoryInterface $symbolLocations = null): BoundaryExplanation` with its existing list/map docblocks. Exact private shapes: `repositoryIndex(?MetricRepositoryInterface $repository): array` with `@return array<string, array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}}>`; `repositoryRecord(string $subjectKey, array $index): ?array` with the same record shape; `annotationFor(ViolationChannel $channel, array $thresholdOverridesByFile, MetricSubject $subject): ?ThresholdOverride` with `@param array<string, list<ThresholdOverride>>`. The index visits exact declarations, exact callables, logical classes and every aggregate `all(SymbolType)` row once; exact typed subjects are keyed by `subject->toCanonical()`, while a logical/aggregate row may record presence/location with null subject. `annotationFor` considers only exact subject equality and `ThresholdOverride::matches($channel->ruleName)`, thereby retaining exact, prefix and `*` wildcard matching; among matches it selects highest `ControlScope::specificity()`, then smallest finite span, then first extracted on a complete tie. Flow: identities + measured evidence/repository index -> status/location -> independent baseline/config/annotation sources. | Retain boundary/baseline VOs, repository, subjects/types/paths, overrides and violations. Remove `AnalysisContext`, `CallableWithMetrics`, `MetricBag`, `SymbolPath`, the anonymous null repository and duplicate scans. Measured evidence precedes repository; exact declaration/callable identity is not collapsed; logical/aggregate presence invents no declaration subject; baseline-only precedes unknown; zero is not absence; annotation precedence and wildcard semantics are unchanged.                                                                                         | `BoundaryExplanationServiceTest`: Current/BaselineOnly/Unknown and missing subjects; exact declaration/callable/logical/aggregate index; measured precedence/no guessing; all-three/absent/zero sources; v11 occurrence/edge; exact, prefix and `*` annotation patterns; specificity, smaller span and first-on-tie. `BaselineWorkflowTest`: same measured v11 set and accepted boundary.                      |
| `SuppressionExtractor`: fresh cohesion and `extractFromText` cognitive; drift `extractFromText` CCN 12 -> 14                                   | Keep all three public signatures. Add private string constants `MODE_FULL = 'full'`, `MODE_PHYSICAL = 'physical'`, `MODE_FILE_ONLY = 'file-only'` and annotate mode parameters as `'full'\|'physical'\|'file-only'`; no enum/file is added. Exact boundaries: `extractNode(Node $node, ?MetricSubject $subject, ?ControlScope $controlScope, string $mode): array` with `@return list<Suppression>`; `matchText(string $text): array` with `@return list<array{type: SuppressionType, rule: non-empty-string, reason: ?string}>`; `projectMatch(array $match, int $startLine, int $endLine, ?int $nodeEndLine, ?MetricSubject $subject, ?ControlScope $controlScope, string $mode): ?Suppression` with the match/mode shapes repeated in the docblock. Public `extract` calls Full with subject/scope, `extractPhysical` calls Physical with null bindings, and `extractFileLevelSuppressions` calls File-only with null bindings. `matchText` strips paired backticks once and returns File, NextLine, Symbol matches in that fixed order. Full projects all three and requires bindings for Symbol; Physical projects only File/NextLine and throws `LogicException` on any Symbol match; File-only projects File and silently ignores NextLine/Symbol, matching the current verified contract.                                                                                                                                                                                     | Retain node/comment and suppression/control VOs; add no AST helper. File uses start/default `*`; NextLine uses end; Symbol uses start plus node end/binding; docblock precedes regular comments. Exact/prefix/wildcard grammar and reasons stay fixed; paired backticks are inert and an unpaired backtick leaves the tag visible.                                                                                                                                                                                                                                                        | `SuppressionExtractorTest`: public-mode routing; all tags/styles and fixed ordering; Full binding projection; Physical mixed File/NextLine plus fail-fast Symbol; File-only File projection plus ignored NextLine/Symbol; wildcard/reason/line/end-line; prefix near misses; paired, mixed and unpaired backticks. `ThresholdAnnotationParserPathTest` remains an R8 read-only seam oracle; R2 is sole editor. |

The private array contracts above are literal, not shorthand. Parser
`readOptionalObject` has `@return ?array<mixed, mixed>`;
`BaselineEntryValues::readOptionalList` has `@return ?list<mixed>` and
`BaselineEntryValues::readMagnitudes` has `@return ?list<int|float>`.
Boundary `repositoryRecord` has
`@param array<string, array{subject: ?MetricSubject, location:
?array{0: RelativePath, 1: int}}> $index` and returns exactly
`?array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}}`.
Suppression `projectMatch` has
`@param array{type: SuppressionType, rule: non-empty-string, reason: ?string}
$match` and `@param 'full'|'physical'|'file-only' $mode`; `extractNode` carries
the same literal mode union. Implementers may not widen any of these to an
unstructured helper payload.
The preserved suppression public signatures are literal too:
`extract(Node $node, MetricSubject $subject, ControlScope $controlScope):
array` with `@return list<Suppression>`, `extractPhysical(Node $node): array`
with the same return, and `extractFileLevelSuppressions(Node $node): array`
with the same return. Full, Physical, and File-only are therefore internal
execution modes, not new caller-visible options.

The exact seven tests listed above are the complete fence. `BaselineWorkflowTest`
pins v10 rejection guidance to fresh analysis plus deliberate map/split and
reviewed v11 construction, never the historical `baseline:migrate` route, and
pins selectors from complete subject/channel/occurrence/edge identity.
`BoundaryExplanationServiceTest` pins missing repository subjects and exact
precedence; `SuppressionExtractorTest` pins escaped tags. The existing R2 ->
R8 `ThresholdAnnotationParserPathTest` overlap is execution-only for R8: a
required R8 edit stops for an ownership revision.

The finite callsite audit is `BaselineLoader::parse`,
`BaselineGenerateCommand::generate`, `BaselineExplainCommand::explain`, and
`FileProcessor`/`SourceControls` calling the three suppression entry points;
`OutputConfigurator` wires the same constructors. No constructor, public
signature/default/return, DI, CLI option/output, v11 schema/selector,
precedence, or accepted baseline state changes. The only added internal
surfaces are the exact `BaselineEntryValues` type and the exact
`BaselineCapture::fromRejectedGroups` named factory; neither changes an
existing caller contract. Discovery of an outward or breaking surface stops
for plan/P7 revision; no shim or CHANGELOG migration is authorized.

R8 updates `src/Baseline/README.md`: preserve and correct the v11 typed-subject,
occurrence, edge, selector and identity contracts, then document the four
assigned-row owners plus the exact `BaselineEntryValues` and `BaselineCapture`
boundaries: responsibilities, typed inputs/outputs, dependencies, invariants and
seven-file tests. It must not retain stale v10-as-current wording or advertise
the historical v5-to-v10 migrator for v10-to-v11. R9-R11 are not editors; a
discovered later editor stops for overlap revision.

**DoD:** all seven assigned fresh groups disappear, including
`BaselineEntryParser` WMC <50 and `BaselineGenerator` instability <0.8 with
the measured Ce=7/Ca=2 topology; the three upper-bound drifts return to
`BoundaryExplanationService` WMC <=54 and instability
<=0.888889 and `SuppressionExtractor::extractFromText` CCN <=12. The common
verifier proves no unexpected fresh identity and all other old ceilings;
`BaselineEntryValues` and the changed `BaselineCapture` themselves have empty
assigned and unexpected fresh sets. All seven tests and direct contracts pass,
docs match, and there is no threshold,
formula, exclusion, inline control, baseline acceptance/regeneration,
CLI/output, or outward-contract break.

#### P6-R9 — rule analysis helpers

**Depends on:** P6-R8. R1 **APPROVED** the two category-specific subject
companions below after verifying that the existing-file fence could not meet
two assigned topology outcomes honestly. P2 added the
required `MetricSubjectCodec` and `OccurrenceKey` edges to both abstract
occurrence-rule bases. Moving the same expressions into private methods changes
neither edge; importing `Metrics` location VOs would introduce a forbidden
Rules-to-Metrics peer dependency. `AbstractCodeSmellRule` must remove two net
dependencies to restore CBO 22 -> <=20, while
`AbstractSecurityPatternRule` must remove at least one net dependency to move
Ce/(Ce+Ca) below the fresh 0.8 boundary. The post-R8 scan makes the proposed
delta finite: CodeSmell is Ca=9/Ce=13/CBO=22, so removing the three projection
targets and adding one companion yields Ca=9/Ce=11/CBO=20;
SecurityPattern is Ca=3/Ce=12/I=12/(12+3)=0.8, so the same net -2 Ce yields
10/(10+3)=0.769230769. The approved decision is exactly two subject-owned
companions; implementation must not substitute a generic `RuleHelper`, a Core
occurrence factory without a demonstrated cross-subject contract, a fake
incoming edge, or a metric control. Any replacement requires a new reviewed
subject-cohesion decision and exact fence revision.

The first focused pass established the real transport input:
`MetricBag::entries()` exposes `list<array<string, scalar>>`, so both approved
public factories accept exactly `array<string, scalar>` and the bases pass the
raw entry directly. The earlier duplicated companion-level schema validators
are rejected by the complete self-scan correction below; the codec remains the
single subject-grammar authority. No caller adapter, local narrowed shape,
`@var`, assertion, PHPStan ignore, duplicate `type` entry field, or widened
public projection surface is allowed. Base missing-container failures remain
the existing `LogicException` and are not moved.

**Production files:**

```text
src/Rules/CodeSmell/AbstractCodeSmellRule.php
src/Rules/CodeSmell/CodeSmellFinding.php [new]
src/Rules/CodeSmell/UnusedPrivateRule.php
src/Rules/Coupling/CboRule.php
src/Rules/Coupling/ClassRankRule.php
src/Rules/Coupling/DistanceRule.php
src/Rules/Coupling/InstabilityRule.php
src/Rules/Design/TypeCoverageRule.php
src/Rules/Security/AbstractSecurityPatternRule.php
src/Rules/Security/SecurityPatternFinding.php [new]
src/Rules/Size/PropertyCountRule.php
src/Rules/Structure/LcomRule.php
src/Rules/Structure/NocRule.php
src/Rules/Structure/WmcRule.php
```

**Tests and component documentation:**

```text
tests/Unit/Rules/CodeSmell/CodeSmellFindingTest.php [new]
tests/Unit/Rules/CodeSmell/UnusedPrivateRuleTest.php
tests/Unit/Rules/Coupling/CboRuleTest.php
tests/Unit/Rules/Coupling/ClassRankRuleTest.php
tests/Unit/Rules/Coupling/DistanceRuleTest.php
tests/Unit/Rules/Coupling/InstabilityRuleTest.php
tests/Unit/Rules/Design/TypeCoverageRuleTest.php
tests/Unit/Rules/Size/PropertyCountRuleTest.php
tests/Unit/Rules/Structure/LcomRuleTest.php
tests/Unit/Rules/Structure/NocRuleTest.php
tests/Unit/Rules/Structure/WmcRuleTest.php
tests/Unit/Rules/CodeSmell/BooleanArgumentRuleTest.php
tests/Unit/Rules/Security/CommandInjectionRuleTest.php
tests/Unit/Rules/Security/SecurityPatternFindingTest.php [new]
tests/Unit/Rules/Security/SqlInjectionRuleTest.php
tests/Unit/Rules/Security/XssRuleTest.php
tests/Unit/Rules/ThresholdOverrideIntegrationTest.php
src/Rules/README.md
```

The concrete CodeSmell tests exercise their abstract base through
`BooleanArgumentRule`; the security-base contract is exercised through its
actual `CommandInjectionRule`, `SqlInjectionRule`, and `XssRule` consumers.
`HardcodedCredentialsRule` and `SensitiveParameterRule` do not extend that base
and their tests are therefore R11-only, not a fabricated R9 -> R11 overlap. No
nonexistent abstract-base test is assumed.

The complete abstract-base production consumer audit is closed:
`AbstractCodeSmellRule` has exactly `BooleanArgumentRule`, `CountInLoopRule`,
`DebugCodeRule`, `EmptyCatchRule`, `ErrorSuppressionRule`, `EvalRule`,
`ExitRule`, `GotoRule`, and `SuperglobalsRule`; `AbstractSecurityPatternRule`
has exactly `CommandInjectionRule`, `SqlInjectionRule`, and `XssRule`. None
constructs or overrides the proposed companion, none overrides `analyze()`, and
their public/protected base contracts remain unchanged. A later consumer or an
`analyze()` override discovered by the pre-edit literal audit stops R9 for a
test/fence revision.

The ownership is finite. Every newly named method on the twelve existing rule
owners is private. The two companions instead use the exact approved
visibility contract stated in their rows. For owners named again in the
complete-scan correction table immediately below, that later row is
authoritative and replaces the first-pass private signature, storage,
normalization and test details; there is no implementer choice between them.

| Production owner; exact assigned rows                                                                     | Subject/responsibility and private boundary                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | Typed flow, dependencies, invariant, and direct cases                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CodeSmellFinding` `[new]`; no ledger row, enables the adjacent base-owner rows                           | An `@internal final readonly class` CodeSmell-subject value/projection, not a generic factory. Its exact transport alias is `@phpstan-type CodeSmellEntry array<string, scalar>`; the narrow mandatory-field alias is removed. Its exact storage constructor remains `private function __construct(private int $line, private MetricSubject $subject, private string $extra, private bool $hasExtra, private bool $promoted, private bool $hasPromoted)`; there are no public properties or getters. `public static function fromEntry(array $entry, RelativePath $file): self` takes `CodeSmellEntry`. Private `normalizeEntry(array $entry): array{line: int, components: array<string, int\|string>, extra: string, hasExtra: bool, promoted: bool, hasPromoted: bool}` also takes `CodeSmellEntry`, validates before constructing; `public function toViolation(SymbolPath $fileSymbol, string $ruleName, string $smellType, Severity $severity, string $message, ?string $recommendation): Violation` is unchanged. | `line` is required and must be `int`; optional `extra` must be `string` when present; optional `promoted` must be `bool` when present. Exact failures are `Code-smell entry field "line" is required`, `Code-smell entry field "line" must be an integer`, `Code-smell entry field "extra" must be a string`, `Code-smell entry field "promoted" must be a boolean`, and `Code-smell entry subject component "{key}" must be an integer or string`. Missing `extra`/`promoted` normalize to `''`/`false` with presence false; present empty/false retain presence true. The seven subject keys alone are copied after type validation, then `MetricSubjectCodec` retains the discriminator matrix above plus exact required member/class, position and ordinal failures. Output preserves declaration subject, precise location, channel, fixed `1.0`, recommendation and occurrence evidence. `CodeSmellFindingTest` imports the broad alias, supplies required `RelativePath`; covers file/class/method/function shapes; missing and wrong-type line; for each of `subjectKind` and `logicalKind`, absent, `int`, `bool`, `float`, and unknown-string inputs with the exact codec/transport message above; wrong-type non-discriminator subject components; absent/empty/wrong-type extra; absent/false/wrong-type promoted; canonical occurrence stability and every exact message. Missing container remains only in `BooleanArgumentRuleTest`. The new type and `normalizeEntry` are unexpected-fresh empty. |
| `AbstractCodeSmellRule`; cohesion fresh and CBO 20 -> 22                                                  | Retains enabled/type iteration, container-file fail-fast, `shouldIncludeEntry()` policy and `buildMessage()` policy; `analyze()` passes the raw `array<string, scalar>` entry and resolved container file directly to `CodeSmellFinding::fromEntry()`, then invokes `toViolation()` with the exact file symbol and rule metadata. Delete the duplicated `subjectComponents()` boundary only after the companion owns it.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | Inputs/outputs and all concrete protected/public contracts stay unchanged. No base adapter, local narrowed shape, `@var`, assertion or ignore is allowed. The dependency delta remains exactly remove `MetricSubjectCodec`, `Location`, and `OccurrenceKey`, add `CodeSmellFinding`; this is the net -2 required for CBO <=20. `BooleanArgumentRuleTest` pins missing-container failure before projection, disabled/empty, every subject kind, filtering, precise line, recommendation and fixed occurrence magnitude. Cohesion fresh must disappear without a control.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `UnusedPrivateRule`; `analyze` cognitive fresh + CCN fresh                                                | Owns unused-private declaration policy. `violationsForDeclaration(SymbolInfo $classInfo, AnalysisContext $context): list<Violation>` returns empty for non-class/zero-total and otherwise materializes the three exact `ENTRY_KEYS`; `analyze()` only checks enabled and concatenates each declaration result.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | Preserve fail-fast missing exact subject/declaration, class-wide total repeated on every finding, entry order method/property/constant, optional name wording, warning severity and recommendation. `UnusedPrivateRuleTest` covers disabled, non-class, missing subject/declaration, zero, each entry key, named/unnamed entries, multiple entries and exact subject/location/magnitude. Both fresh method findings clear and the new private method is unexpected-fresh empty.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `CboRule`; `analyzeClassLevel` CCN fresh; class WMC 61 -> 63, CBO 22 -> 23; `checkCbo` parameters 9 -> 10 | Owns hierarchical CBO policy and direction-aware presentation. `checkCbo(int $cbo, SymbolInfo $info, MetricSubject $subject, MetricBag $metrics, ClassCboOptions\|NamespaceCboOptions $options, RuleLevel $level, AnalysisContext $context, array{applicationScope: bool, frameworkCe: ?int} $presentation): ?Violation`; class and namespace callers pass the exact two-field presentation. `buildRecommendation(int $cbo, int $ca, int $ce, int $threshold, ?SymbolPath $symbolPath, AnalysisContext $context, bool $applicationScope): string` and `getTopDependencies(?SymbolPath $path, AnalysisContext $context): string` read the graph through the already-owned context.                                                                                                                                                                                                                                                                                                                                        | Call effective options once; use `$options->getSeverity($cbo)`, return on null, select its one warning/error threshold, then construct exactly one Violation. Update `channelDeclarations()`'s docblock to cite `ClassCboOptions::getSeverity()` and `NamespaceCboOptions::getSeverity()` as the delegated `>= error`, then `>= warning` comparisons while retaining `magnitude`/`higher`; it must no longer claim the comparison is inline in `checkCbo()`. This removes duplicated severity construction (WMC <=61), reduces parameters to 8, and removes the direct `DependencyGraphInterface` edge (CBO <=22). Preserve all/application metric choice, framework suffix, Ca/Ce, class/namespace codes and levels, threshold overrides, direction text, top-five stable count ordering and no-graph fallback. `CboRuleTest` covers both levels, scopes, null/under/warning/error, declaration direction, override precedence, afferent/efferent/balanced, framework count, repeated/top-five dependencies and missing graph; `ThresholdOverrideIntegrationTest` pins selectors.                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `ClassRankRule`; `analyze` CCN fresh                                                                      | Owns scale-adjusted ClassRank policy. `violationForClass(SymbolInfo $classInfo, AnalysisContext $context, float $scaleFactor, int $classCount): ?Violation`; `analyze()` materializes declarations/count, handles zero once, computes scale once and appends non-null results.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | Preserve exact-class requirement, missing metric skip, effective per-subject override before scaling, warning/error thresholds divided by scale, occurrence channel and class-count message. `ClassRankRuleTest` covers zero/disabled, non-class, missing metric, 1/100/large-class scaling, below/equal warning/equal error, override and exact identity/message. Fresh analyze CCN clears; private result method has no unexpected fresh.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `DistanceRule`; `analyze` NPATH 441 -> 588                                                                | Owns namespace-distance policy and the no-project-namespace warning. `namespaceResult(SymbolInfo $namespaceInfo, AnalysisContext $context): array{present: bool, projectMatched: bool, violation: ?Violation}` distinguishes empty namespace, filter rejection, accepted-but-too-small/missing/below-threshold, and finding. `analyze()` only accumulates the two counters/results and emits the existing warning.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | Preserve include-prefix boundary semantics, resolver fallback, include-list precedence, total-vs-analyzed counter meaning, min class count, effective subject override, A/I values, severity/threshold and logger context. `DistanceRuleTest` directly covers each returned state through public analysis, exact/nested/nonmatching prefixes, resolver and explicit include, no-match warning/no-warning, min count, missing metric, warning/error and override. NPATH returns <=441 and the private result method is unexpected-fresh empty.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `InstabilityRule`; `analyzeClassLevel` CCN 10 -> 11                                                       | Owns class/namespace instability policy. `classViolation(SymbolInfo $classInfo, AnalysisContext $context, ClassInstabilityOptions $options): ?Violation` handles exact-class, metric, min-Ca, effective options and projection; `analyzeClassLevel()` only iterates/appends. Namespace behavior remains in its existing owner.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | Preserve missing subject failure, class type, missing instability, absent Ca as zero, minAfferent boundary, Ce default, exact class code/level/message, overrides and higher-worse thresholds. `InstabilityRuleTest` covers every guard, Ca one below/equal, warning/error/equality, Ce default, override and both levels. `analyzeClassLevel` returns CCN <=10 and the private method is unexpected-fresh empty.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `TypeCoverageRule`; `checkCoverage` parameters 8 -> 9                                                     | Owns the fixed param/return/property coverage matrix. `checkCoverage(MetricBag $metrics, MetricSubject $subject, SymbolInfo $classInfo, string $totalMetric, string $coverageMetric, array{label: string, code: 'param'\|'return'\|'property', hint: string, warning: float, error: float} $spec): ?Violation`; `analyze()` enumerates exactly three closed specs in param, return, property order.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | Derive symbol path/location from the existing subject/info, so the method has 6 parameters. Preserve total absent/zero skip, missing coverage as 0, lower-worse strict `<` comparisons, error-before-warning, effective override precedence, codes/hints/order and percentages. `TypeCoverageRuleTest` covers all three specs, zero/missing total, missing coverage, just below/equal each boundary, overrides and exact output; `ThresholdOverrideIntegrationTest` pins selector precedence.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `SecurityPatternFinding` `[new]`; no ledger row, enables the adjacent base-owner row                      | An `@internal final readonly class` SecurityPattern-subject value/projection. Its exact transport alias is `@phpstan-type SecurityPatternEntry array<string, scalar>`; the narrow mandatory-field alias is removed. Its exact storage constructor remains `private function __construct(private int $line, private MetricSubject $subject, private string $superglobal)`; there are no public properties or getters. `public static function fromEntry(array $entry, RelativePath $file): self` takes `SecurityPatternEntry`. Private `normalizeEntry(array $entry): array{line: int, components: array<string, int\|string>, superglobal: string}` also takes `SecurityPatternEntry`, validates before constructing; `public function toViolation(SymbolPath $fileSymbol, string $ruleName, string $patternType, Severity $severity, string $messageTemplate, ?string $recommendation): Violation` is unchanged.                                                                                                        | `line` is required and must be `int`; optional `superglobal` must be `string` when present. Exact failures are `Security-pattern entry field "line" is required`, `Security-pattern entry field "line" must be an integer`, `Security-pattern entry field "superglobal" must be a string`, and `Security-pattern entry subject component "{key}" must be an integer or string`. Missing `superglobal` normalizes to `''`; present empty remains `''`. The same seven-key typed component projection delegates the discriminator matrix above and semantic completeness checks to `MetricSubjectCodec`. Preserve precise location, fixed `1.0`, exact `{type, superglobal}` occurrence, message suffix and recommendation. `SecurityPatternFindingTest` imports the broad alias, supplies required `RelativePath`; covers every subject shape; missing/wrong-type line; for each of `subjectKind` and `logicalKind`, absent, `int`, `bool`, `float`, and unknown-string inputs with the exact codec/transport message above; wrong-type non-discriminator subject components; absent/empty/non-empty/wrong-type superglobal; canonical occurrence stability and every exact message. Missing container remains only in representative `CommandInjectionRuleTest`. The companion and `normalizeEntry` are unexpected-fresh empty.                                                                                                                                                                                   |
| `AbstractSecurityPatternRule`; instability fresh                                                          | Retains enabled/type iteration, container-file fail-fast and abstract pattern/severity/message/recommendation policy; `analyze()` passes the raw `array<string, scalar>` entry directly to `SecurityPatternFinding::fromEntry()`. Delete `subjectComponents()` only after the companion owns it.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Exact public/protected contracts and three concrete rule outputs remain unchanged. No base adapter, local narrowed shape, `@var`, assertion or ignore is allowed. Remove `MetricSubjectCodec`, `Location`, and `OccurrenceKey`, add `SecurityPatternFinding`; with Ca unchanged this reduces Ce by two and clears class instability 0.8 without a fake consumer. `CommandInjectionRuleTest` pins missing-container failure before projection; Command/SQL/XSS tests cover disabled/empty, subject/location/occurrence, superglobal message and recommendation.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| `PropertyCountRule`; `analyze` cognitive 15 -> 17, CCN 12 -> 13, NPATH 219 -> 435                         | Owns property-count eligibility and finding. `violationForClass(SymbolInfo $classInfo, AnalysisContext $context, PropertyCountOptions $options): ?Violation`; `analyze()` only iterates/appends.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Preserve exact-class requirement, missing metric, readonly and promoted-only exclusions independently and together, effective overrides, equality boundaries, message/recommendation/threshold. `PropertyCountRuleTest` covers every guard/exclusion combination, warning/error/equality, override and identity. Analyze returns cognitive <=15, CCN <=12, NPATH <=219; helper is unexpected-fresh empty.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `LcomRule`; `analyze` NPATH fresh; cognitive 15 -> 17 and CCN 12 -> 13                                    | Owns LCOM eligibility and finding. `violationForClass(SymbolInfo $classInfo, AnalysisContext $context, LcomOptions $options): ?Violation`; `analyze()` only iterates/appends.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | Preserve exact class, readonly exclusion, minMethods equality, missing LCOM, effective override, warning/error equality, LCOM value in message/recommendation and identity. `LcomRuleTest` covers every guard, min one below/equal, thresholds/override and output. Analyze clears fresh NPATH and returns cognitive <=15/CCN <=12; helper is unexpected-fresh empty.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `NocRule`; `analyze` CCN fresh                                                                            | Owns NOC finding policy. `violationForClass(SymbolInfo $classInfo, AnalysisContext $context, NocOptions $options): ?Violation`; `analyze()` only iterates/appends.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | Preserve exact class, null/zero skip, effective override, warning/error equality, message/recommendation and identity. `NocRuleTest` covers all guards, zero, boundaries, override and output. Fresh analyze CCN clears; helper is unexpected-fresh empty.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| `WmcRule`; `analyze` cognitive fresh; CCN 11 -> 12                                                        | Owns WMC eligibility/finding while existing `buildRecommendation()` retains its average-complexity policy. `violationForClass(SymbolInfo $classInfo, AnalysisContext $context, WmcOptions $options): ?Violation`; `analyze()` only iterates/appends.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     | Preserve exact class, data-class exclusion, missing WMC, effective override, warning/error equality, missing/zero/low/high method-count recommendation branches and output. `WmcRuleTest` covers all guards, thresholds/override, every recommendation branch and identity. Analyze clears cognitive and returns CCN <=11; both private methods are unexpected-fresh empty.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |

The complete 692/692 unbaselined scan at
`/private/tmp/qmx-p6-r9-evidence/selfscan.json` stops the first implementation
shape and supersedes only the private boundaries/test details named below. It
reported `checkCbo` at the strict eight-parameter boundary; companion
normalization CCN 11 / NPATH 252; helper CCN 10/11 findings in
`UnusedPrivateRule`, `CboRule`, `DistanceRule`, `PropertyCountRule`, and
`LcomRule`; CBO WMC 63; and six new duplication groups. These are failed
implementation shapes, not additions to the frozen ledger. No new path/type is
needed, so R1 remains approved; all public/outward contracts and every
unmentioned row above remain literal.

| Superseded owner detail                                                  | Exact corrected private design                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | Direct regression and gate                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CodeSmellFinding` and `SecurityPatternFinding` normalization/projection | Retain both approved `@internal final readonly` category subjects and their existing public `fromEntry()`/`toViolation()` methods. Each companion remains the **complete** category-specific projection: its private storage includes `Location` plus its decoded `MetricSubject` and category evidence; its public `toViolation()` constructs and returns the complete `Violation`, including location, subject, file symbol, channel, message, severity, fixed `1.0`, recommendation and semantic occurrence. There are no projection getters/public fields, and neither abstract base constructs `Violation`, `Location`, or `OccurrenceKey`. The bases retain only enabled/type iteration, filtering and policy inputs: CodeSmell supplies its already-built message plus rule/smell/severity/recommendation; Security supplies its message template plus rule/pattern/severity/recommendation. Both factories accept `array<string, scalar>` and preserve pre-extraction casts/key presence. Each companion has its own private seven-key map; one `array_intersect_key` plus one `int|string` filter feeds `MetricSubjectCodec::decode()`, the sole subject-grammar validator. Remove both `normalizeEntry()` methods and their duplicate transport grammar. To prevent copying while keeping full projection in each category owner, CodeSmell precomputes its five-field occurrence before its complete constructor call; Security first resolves its category-specific message suffix and two-field occurrence, then performs its complete constructor call with a distinct local flow. No shared helper, inheritance, new type, Core change, base-side projection, caller adapter, assertion or ignore is allowed. | Companion tests replace removed transport-error cases with scalar-cast/key-presence compatibility, all subject codec shapes, invalid codec components, exact stored container location and every complete `Violation` field. Base tests retain missing-container and policy/filter behavior and assert the full returned violation without constructing it. Both companions/private methods have empty fresh sets; the exact 18-line location and 36-line projection duplicate groups are absent. The base topology remains literal: remove `MetricSubjectCodec`, `Location`, and `OccurrenceKey`, add exactly its category companion; CodeSmell CBO is <=20 and Security instability is <0.8. |
| `UnusedPrivateRule`                                                      | Keep `violationsForDeclaration(SymbolInfo $classInfo, AnalysisContext $context): list<Violation>`, but move only optional-name formatting to `private function entryMessage(string $label, array $entry): string` with `@param array<string, scalar> $entry`. The declaration helper retains exact-subject/class/zero guards and the two closed loops.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | `UnusedPrivateRuleTest` covers named/unnamed formatting through public analysis plus every existing guard/order/magnitude case. `violationsForDeclaration` is CCN <10 and `entryMessage` has no fresh finding.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `CboRule`                                                                | Add exact `classViolation(SymbolInfo $info, AnalysisContext $context, ClassCboOptions $options): ?Violation` and `namespaceViolation(SymbolInfo $info, AnalysisContext $context, NamespaceCboOptions $options): ?Violation`; the two level methods only iterate/append. Rewrite public `analyze()`/`analyzeLevel()` as a CBO-specific closed class/namespace dispatch rather than copying Instability's scaffolding. Correct `checkCbo` to seven parameters: `checkCbo(int $cbo, SymbolInfo $info, MetricSubject $subject, ClassCboOptions\|NamespaceCboOptions $options, RuleLevel $level, AnalysisContext $context, array{applicationScope: bool, frameworkCe: ?int} $presentation): ?Violation`; it reads Ca/Ce through `$context->metrics->get($subject->toSymbolPath())`, so no `MetricBag` parameter or direct graph edge remains. `getTopDependencies(?SymbolPath $path, AnalysisContext $context): string` retains guards/counting/`arsort`, then uses `array_slice(array_keys($counts), 0, 5)` and `array_map` instead of the counter/break loop.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | `CboRuleTest` pins class/namespace/unsupported/disabled dispatch, nullable owner results, both scopes, context metric lookup, all thresholds/directions, framework suffix, no graph, repeated/tied and more-than-five dependency order. `checkCbo` parameters <8; `analyzeClassLevel` and `getTopDependencies` CCN <10; class WMC <=61 and CBO <=22; every new helper has empty fresh sets. Both exact Cbo/Instability duplication groups are absent.                                                                                                                                                                                                                                          |
| `DistanceRule`                                                           | `analyze()` accumulates `present` and `projectMatched` arithmetically as `int += (int) bool`, branching only for a non-null violation and the final warning. `namespaceResult()` owns only empty/filter status, then calls `matchedNamespaceViolation(SymbolInfo $info, AnalysisContext $context): ?Violation` for min-count, metric, override and projection.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               | Existing public cases pin all three result states and logger counts; add accepted-but-small/missing/below-threshold cases to the matched helper seam. Both `analyze` and `namespaceResult` are CCN <10 and the new helper has no fresh finding.                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `PropertyCountRule` and `LcomRule`                                       | Property adds `eligiblePropertyCount(MetricBag $metrics, PropertyCountOptions $options): ?int`; LCOM adds `eligibleLcom(MetricBag $metrics, LcomOptions $options): ?int`. Each owns only its subject-specific missing/exclusion/minimum guards. Their violation methods then apply effective options once and precompute subject-specific message/recommendation locals before the short `Violation` projection; they must not copy NOC or each other structurally.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Existing tests directly cover every eligibility outcome, thresholds, override and exact output. Both `violationForClass` methods are CCN <10; the two eligibility helpers have empty fresh sets; the exact Property/NOC and Property/LCOM duplicate groups are absent. Adding the already-Core `MetricBag` type must introduce no CBO/instability finding.                                                                                                                                                                                                                                                                                                                                     |

The correction owns all six duplicate groups literally: two companion groups,
two Cbo/Instability groups, Property/NOC, and Property/LCOM. A repeated group,
any other fresh identity, or any control/baseline/config change keeps R9
stopped. The 692/692 rerun, not focused green tests alone, is the acceptance
oracle.

No existing outward constructor, rule/options interface, CLI/config key, channel,
selector, message, ordering, severity, threshold comparison, subject, occurrence,
or recommendation contract changes. All existing constructor call sites remain
valid. The literal callsite audit found no production `new` call for the twelve
rules: production construction remains the existing tagged
`RuleOptionsCompilerPass` -> `RuleCompilerPass` path. Outside the exact focused
tests above, `tests/Integration/Violation/ChannelCoverageTest.php` directly
constructs exactly `BooleanArgumentRule`, `CommandInjectionRule`,
`ClassRankRule`, and `TypeCoverageRule`, while
`tests/Unit/Analysis/Pipeline/AnalysisPipelineTest.php` directly constructs
exactly `BooleanArgumentRule` from this R9 consumer set. Static edit ownership
does not move: P5-A remains sole editor of `ChannelCoverageTest`, and P6-R7 is
the last editor of `AnalysisPipelineTest`. R9 runs both complete files as
read-only seam/channel oracles; a needed assertion change stops for an explicit
ownership/overlap revision. The two new classes are internal subject companions
and add no public compatibility shim. A discovered outward change stops for
P7/CHANGELOG revision.

R9 updates `src/Rules/README.md`: preserve the complete
rule/channel/threshold documentation, add the exact two subject companions to
the structure, and document their entry-to-subject/occurrence responsibility,
inputs/outputs, dependency direction and direct tests together with the twelve
existing rule owners' analysis boundaries. R10 and R11 are not Rules README
editors because their approved work changes controls or private duplication
only; discovery of a changed documented responsibility stops that package for
an overlap revision. P7 is sequential and preserves R9's verified entries.

R9 ledger arithmetic is closed without assigning either new companion a row:
fresh `1 AbstractCodeSmellRule + 1 AbstractSecurityPatternRule + 2
UnusedPrivateRule + 1 CboRule + 1 ClassRankRule + 1 LcomRule + 1 NocRule + 1
WmcRule = 9`; worsening `1 AbstractCodeSmellRule + 3 CboRule + 1 DistanceRule
+ 1 InstabilityRule + 1 TypeCoverageRule + 3 PropertyCountRule + 2 LcomRule +
1 WmcRule = 13`. These are exactly the frozen ledger rows above, not new
measurements or duplicated helper assignments.

**DoD:** the recorded R1 APPROVE is the implementation gate for the exact two
paths and subjects; no further approval pause remains. All nine fresh groups and all 13
worsening rows assigned here clear under the common pinned verifier; every new
private method and both new companions have empty unexpected-fresh sets. The
listed focused tests remain green at no fewer than 445 tests / 1404 assertions;
target PHPStan reports zero errors for both abstract bases and companions, with
no caller adapter or static-analysis escape. Both bases are free of
`MetricSubjectCodec`, `Location`, and `OccurrenceKey`, never construct a
`Violation`, and depend only on their category companion for the complete
projection; neither companion exposes a projection getter/public field. Direct
factory tests prove codec validation, preserved scalar casts/key presence,
empty metrics,
class/namespace/declaration projection, exact override precedence, every
threshold boundary, messages, ordering, subjects and occurrence keys. A fresh
complete scan has 692/692 files, no `normalizeEntry`, no `checkCbo` parameter
finding, none of the eight listed helper CCN/NPATH findings, Cbo WMC <=61/CBO
<=22, and none of the six exact duplicate groups. The R9 self-scan changes no
formula, threshold, exclusion, inline control, baseline or accepted debt, and
the global ledger remains exactly 63 fresh / 34 worsening / 6 improved.

#### P6-R10 — proven structural controls and finite AST dispatch

**Depends on:** P6-R9. R10 needs no new type, path, move, public contract,
configuration edit or subject-cohesion decision, so R1 remains closed. Every
control is an adjacent symbol threshold on an existing owner; `qmx.yaml` is a
read-only R4 preservation oracle.

The live post-R9 input is closed mechanically. The six higher-worse/equality
rules need thresholds one above the accepted maximum; using the measured value
would leave their finding active. Maintainability is inverted and compares
strictly `<`, so its raw 33.3227 is accepted by an exact 33.3 point boundary.

| Assigned identity                                              | Live value | Exact R10 disposition               |
| -------------------------------------------------------------- | ---------: | ----------------------------------- |
| `MetricEnricher::enrich`, NPATH fresh                          | 67200      | point boundary 67201                |
| `NpathComplexityVisitor::enterNode`, maintainability fresh     | 33.3227    | inverted MI point boundary 33.3     |
| `NpathExpressionCalculator`, WMC fresh                         | 50         | point boundary 51                   |
| `NpathExpressionCalculator::calculateContributions`, CCN fresh | 13         | point boundary 14                   |
| `MetricSubjectCodec::decode`, CCN / NPATH fresh                | 12 / 288   | point boundaries 13 / 289           |
| `HtmlViolationPartitioner::attach`, CCN fresh                  | 10         | point boundary 11                   |
| `Violation::__construct`, parameters 14 -> 16                  | 16         | correct both point boundaries to 17 |
| `MethodCountCollector::getClassesWithMetrics`, CCN 11 -> 12    | 12         | source refactor to <=11; no control |

**Exact editable production/control set:**

```text
src/Analysis/Pipeline/MetricEnricher.php
src/Core/Symbol/MetricSubjectCodec.php
src/Reporting/Formatter/Html/HtmlViolationPartitioner.php
src/Core/Violation/Violation.php
src/Metrics/Complexity/NpathComplexityVisitor.php
src/Metrics/Complexity/NpathExpressionCalculator.php
src/Metrics/Structure/MethodCountCollector.php
```

**Exact focused test set:**

```text
tests/Unit/Analysis/Pipeline/MetricEnricherTest.php
tests/Unit/Core/Symbol/MetricSubjectCodecTest.php
tests/Unit/Reporting/Formatter/Html/HtmlViolationPartitionerTest.php
tests/Unit/Core/Violation/ViolationTest.php
tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php
tests/Unit/Metrics/Complexity/NpathExpressionCalculatorTest.php
tests/Integration/BaselineCeiling/NpathSaturationCeilingTest.php
tests/Unit/Metrics/Structure/MethodCountCollectorTest.php
tests/Unit/Metrics/AnonymousClassContextRegressionTest.php
```

Only `MethodCountCollectorTest.php` is an R10 test editor, for the exact
source-refactor equivalence cases below. The other eight files are complete
read-only behavioral oracles; a required assertion change in one of them stops
R10 for an ownership/overlap revision.

The production consumer census is newly derived from the live tree and contains
no test paths. `MetricEnricher` has runtime consumers `AnalysisPipeline` and
`AnalysisConfigurator`; `LayerExpansionStage` is a documentation-only source
reference. `MetricSubjectCodec` has exactly
seven production consumers: `VisitorFileEntryScope`, `VisitorMethodContext`,
`CodeSmellFinding`, `IdenticalSubExpressionRule`, `HardcodedCredentialsRule`,
`SecurityPatternFinding`, and `SensitiveParameterRule`.
`HtmlViolationPartitioner` is constructed only by `HtmlTreeBuilder`, itself
constructed only by `HtmlFormatter`.
`NpathComplexityVisitor` is constructed only by `NpathComplexityCollector`, and
`NpathExpressionCalculator` only by that visitor.
`MethodCountCollector::getClassesWithMetrics()` is consumed through
`ClassMetricsProviderInterface` by exactly `FileProcessor` and
`DerivedCollectorRunner`.

The test/reference census is separate and read-only except for the one editor
named above. `AnalysisPipelineTest`, `AnalysisPipelineIntegrationTest`,
`ChannelCoverageTest`, and `TestPipelineBuilder` exercise `MetricEnricher`;
`HtmlTreeBuilderTest` and `ArchitectureViolationSmokeTest` exercise the HTML
seam; `ContainerFactoryTest` is the MethodCount DI oracle. For NPATH,
`NpathComplexityCollectorTest` constructs `NpathComplexityCollector` twice and
`NpathComplexityVisitor` twice directly; `AnonymousClassContextRegressionTest`
constructs the visitor twice directly; `NpathSaturationCeilingTest` constructs
the collector once. The collector remains the sole production constructor.

`Violation` has one self-reconstruction in `reportedAsBreach()` and exactly 33
production construction files, all read-only because its runtime signature and
fields do not change:

```text
src/Analysis/Pipeline/AnalysisPipeline.php
src/Architecture/Rules/CircularDependencyRule.php
src/Architecture/Rules/LayerViolationFinding.php
src/Architecture/Rules/LayerViolationRule.php
src/Rules/CodeSmell/CodeSmellFinding.php
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
src/Rules/Security/HardcodedCredentialsRule.php
src/Rules/Security/SecurityPatternFinding.php
src/Rules/Security/SensitiveParameterRule.php
src/Rules/Size/ClassCountRule.php
src/Rules/Size/MethodCountRule.php
src/Rules/Size/PropertyCountRule.php
src/Rules/Structure/InheritanceRule.php
src/Rules/Structure/LcomRule.php
src/Rules/Structure/NocRule.php
src/Rules/Structure/WmcRule.php
```

This manifest is reproduced with
`rg -l 'new Violation\\(' src --glob '*.php'`; a new path stops R10 for a
manifest revision. `ViolationTest` is the direct constructor/copy oracle and
the complete suite is the indirect consumer oracle.

| Production owner; assigned row                                          | Exact boundary/design                                                                                                                                                                                                                                                                                                                        | Inputs, outputs, dependencies, invariants and direct tests                                                                                                                                                                                                                                                                                                                                                                                 |
| ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `MetricEnricher`; `enrich` NPATH fresh                                  | Keep `public enrich(MetricRepositoryInterface $repository, DependencyGraphInterface $graph, array $files, int $filesAnalyzed): EnrichmentResult`; retain its CCN control and add `@qmx-threshold complexity.npath warning=67201 error=67201` with the finite ordered enrichment-matrix reason. No helper extraction.                         | Retain all dependencies and aggregation -> global -> reaggregation -> computed -> cycles -> duplication order, span names, selector gates, logging and null/zero-file behavior. `MetricEnricherTest` covers every enabled/disabled/null gate; named pipeline tests run read-only. Only the assigned NPATH identity clears.                                                                                                                 |
| `MetricSubjectCodec`; `decode` CCN/NPATH fresh                          | Keep `public static decode(array $components, RelativePath $containerFile): MetricSubject`; add CCN `warning=13 error=13` and NPATH `warning=289 error=289` with canonical finite wire-grammar reasons. No dispatch/helper/exception change.                                                                                                 | Retain Core-only dependencies, all four subject shapes, exact required/allowed keys, ordinal/position rules and diagnostics. `MetricSubjectCodecTest` covers round trips and every malformed family; seven consumers compile. Both assigned identities clear with no new codec identity.                                                                                                                                                   |
| `HtmlViolationPartitioner`; `attach` CCN fresh                          | Keep `public attach(array $nodesByPath, array $violationsByNode, FormatterContext $context): void`; add CCN `warning=11 error=11` with finite attachment-projection reason. Do not refactor `partition` or payload construction.                                                                                                             | Unknown nodes, non-finite magnitude normalization, canonical subject/channel/occurrence, path and line remain exact. `HtmlViolationPartitionerTest` covers every attachment shape; builder/smoke tests run read-only. Existing `partition` debt receives no control and cannot worsen.                                                                                                                                                     |
| `Violation`; parameter drift                                            | Keep the exact public 16-parameter constructor and fields; change only its existing point control to `@qmx-threshold code-smell.constructor-overinjection warning=17 error=17`, preserving the flat immutable transport-VO reason.                                                                                                           | No dependency/signature/default/order/type change. `ViolationTest` reflectively pins every copied or intentionally rewritten field plus channel/fingerprint/display behavior. All 33 construction files remain valid; the drift clears without BC/CHANGELOG work.                                                                                                                                                                          |
| `NpathComplexityVisitor`; `enterNode` maintainability fresh             | Keep `public enterNode(Node $node): ?int` and add its adjacent exact `@qmx-threshold maintainability.index warning=33.3 error=33.3` with the finite statement/callable php-parser dispatch reason. This lower-worse rule uses strict `<`: raw 33.3227 is therefore accepted without rounding the evidence or excluding the rest of the file. | No dependency, dispatch or formula change. Class/namespace/property contexts, anonymous/nested scopes, factors, reset and return contract stay exact. Collector test, its four direct visitor constructions across two tests, and saturation integration are read-only behavior oracles. Only the assigned method identity clears; the rest of the file remains under MI analysis.                                                         |
| `NpathExpressionCalculator`; WMC and `calculateContributions` CCN fresh | Keep public `calculate(Expr): int`, `calculateContributions(Expr): array{ordinary:int, nullsafe:int}`, `saturatingAdd(int ...$values): int`, and `saturatingMultiply(int,int): int`; add class WMC `warning=51 error=51` and method CCN `warning=14 error=14`. No formula refactor.                                                          | Retain php-parser dependencies and exact leaves/wrappers/callable opacity/ternary/boolean/coalesce/match/nullsafe/dynamic-new algebra and saturation. Expression, collector and ceiling tests pin composition. Both assigned identities clear; golden values and existing cohesion remain unchanged.                                                                                                                                       |
| `MethodCountCollector`; `getClassesWithMetrics` CCN drift               | Keep `public getClassesWithMetrics(RelativePath $file): array`. In that method only, replace constructor-presence `? 1 : 0` with equivalent bool-to-int projection and use `max(1, methodCountTotal)` as the WOC denominator; add no helper/type/control and do not edit `collect()`.                                                        | The visitor invariant `0 <= methodCountPublicAll <= methodCountTotal` preserves zero-total WOC=0 and every non-empty result. Retain metric set, declaration path/line/order, anonymous exclusion and all flags. `MethodCountCollectorTest` pins empty, constructor/accessor, mixed/promoted and multi-class projections and their class bags. The method returns CCN <=11; `collect` and the improved duplication successor cannot worsen. |

`qmx.yaml` is not an R10 editor and its entire pre-R10 file SHA-256
`6842b36e117438eb393577129109eeb028649c5a0a61468081f0971530ec6862`
must remain unchanged.
As a focused R4 preservation oracle, lines 65-76 — the
cross-category-primitives comment and all six foundation patterns, including
R4's three additions — remain byte-for-byte at SHA-256
`4e6c3513fd4a6a8743c229b86b4eb28cb8fd95b577b039d932ddc80930eb19d2`.
R10 adds no global/default threshold, formula, architecture pattern, path or
namespace exclusion, baseline, `@qmx-ignore`, preset, CLI narrowing or generated
exclusion. In particular it adds no whole-file NPATH visitor exclusion;
`MethodCountCollector` receives no control.

Component responsibilities, inventories, public contracts and formulas do not
change, so R10 edits no README, website page, ADR or CHANGELOG.
`src/Analysis/README.md`, `src/Core/README.md`, `src/Reporting/README.md`, and
`src/Metrics/README.md` are read-only documentation oracles. P7 owns only the
later `src/Rules/README.md` pass and has zero R10 documentation overlap.

Overlap is closed: R9 -> R10 has no shared editable path. R4 -> R10 has no
editable overlap because `qmx.yaml` is read-only here. R10 -> R11 sequentially
reopens `NpathComplexityVisitor.php`; R11 must preserve its adjacent MI point
control and formula. `NpathComplexityCollectorTest.php` is read-only in R10 and
is next edited by R11. P7 has zero R10 source/test/control overlap.

**DoD:** all seven fresh groups and both drift rows are accounted exactly once.
The seven non-MI point identities (six fresh plus the `Violation` drift) and the
separate `enterNode` MI point identity are absent after controls;
`getClassesWithMetrics` is CCN <=11 with no unexpected identity. The nine
focused tests and named read-only seams are green; `composer phpstan`,
`composer cs-check`, and `git diff --check` pass. The R4 block retains its hash,
and its direct architecture check
`php bin/qmx check src/ --config=qmx.yaml --only-rule=architecture.layer-violation --workers=0`
exits zero with complete coverage. The common pinned verifier is exactly
`php bin/qmx check src/ --config=qmx.yaml --format=json --workers=0 --no-cache`,
without baseline, preset, rule selection, CLI source exclusions or rule
options. It records source revision plus dirty-tree content manifest/hash,
`qmx.yaml` hash, command/options, coverage, exit code and output/artifact hashes;
proves all 692 current files are discovered and analyzed, failed ==
generatedExcluded == 0, the global ledger remains 63 fresh / 34 worsening / 6
improved, no unassigned identity worsens, and no formula, global threshold,
baseline or additional control changed.

#### P6-R11 — duplication remediation without detector changes

**Depends on:** P6-R10. **R1 decision:** approved exactly as specified below.
The post-R10 compact evidence invalidates the inherited 19-block file list;
this corrected package is the sole implementation authorization. No
implementer may choose another helper, type, public surface, array-shaped
callable record, owner, detector change, or control beyond the finite
post-implementation R1 correction below.

The mechanically re-enumerated current detector set has 25 groups. Fourteen
belong to the original R11 ledger and remain active; eleven are pre-existing or
improved residual outside R11. The original 19 assigned identities therefore
close arithmetically as 14 active + 5 resolved.

**Six active worsening successors:**

| Current occurrence                                                 | Lines | Required predecessor ceiling | Current pair                                                     |
| ------------------------------------------------------------------ | ----: | ---------------------------: | ---------------------------------------------------------------- |
| `6e14c15fd5b2358bc89d5c4855e48e3721fd78cdb127a049debde8432f6cb964` | 56    | 48                           | `CyclomaticComplexityVisitor:202` / `NpathComplexityVisitor:254` |
| `cfee572cddb5cc0af3eb95dd29715f0de04a7bc751810b6ebb5421e2e454512c` | 37    | 32                           | `HalsteadVisitor:138` / `MethodStatementCountVisitor:91`         |
| `18b3fb5d0f6fafbdae68c413ff247baa6a91760f2ab2cb03693d70416a8ff411` | 32    | 31                           | `CognitiveComplexityRule:203` / `ComplexityRule:237`             |
| `c89f21a6b8804780237e5eec8cea9e0abdef4464714467c3b5fac7d720c558a6` | 31    | 28                           | `DataClassRule:78` / `GodClassRule:76`                           |
| `8ebdd25761c9aedd41a6ce70938c462bfd6852ac5876bc34a49f83d99b1c0013` | 23    | 22                           | `MaintainabilityRule:92` / `InheritanceRule:80`                  |
| `aa6fbcae7e3419632c5c7a60251264700f1d627cc13d7b4defe8540b729fc0f0` | 26    | 25                           | `MethodCountRule:97` / `InheritanceRule:74`                      |

**Eight active fresh/no-ancestor groups:**

| Occurrence                                                         | Lines | Current pair                                                                                                              |
| ------------------------------------------------------------------ | ----: | ------------------------------------------------------------------------------------------------------------------------- |
| `17f2a76fecd3d53c721a9bfd64a168872ba669ad0ffd732ca51a6f8471ace352` | 23    | `DotExporter:83` / `DotExporter:140`                                                                                      |
| `a4f805f803bbe4b7d4649cb32808ac506d81989f91d177f113e265a0e5715567` | 22    | `TypeCoverageVisitor:285` / `MethodCountVisitor:327`; the old `DependencyVisitor` occurrence migrated, it did not resolve |
| `c320a4d31b0ee06bf3016652d7d71cf9c44ce573901a07513ae222e52f080742` | 21    | `HalsteadVisitor:180` / `MethodStatementCountVisitor:137`                                                                 |
| `30828a62103ec2298297057964489aaf7deea7a788bf96d95113ca25492285fd` | 31    | `HalsteadVisitor:261` / `MethodStatementCountVisitor:222`                                                                 |
| `b420edb29c01449c5a1121a494f52f5d0ef7c0d1a81c0e9c0cdb04e306e97949` | 24    | `ConstructorOverinjectionRule:85` / `LongParameterListRule:96`                                                            |
| `c58dfc46988f8014caaf35efca5a6f2db1db53fa12d25cec61fe08851d17ff3d` | 27    | `ConstructorOverinjectionRule:85` / `UnreachableCodeRule:84`                                                              |
| `4c7b507b1e8bf0626f7f25c1fda24e5438b1786371fb9bb97e53cc2c681e3705` | 46    | `IdenticalSubExpressionRule:119` / `HardcodedCredentialsRule:111`                                                         |
| `79c655d195f4bf9ac9261743ff1847a69946f1f0acc65de3c106b13faf1b445c` | 47    | `HardcodedCredentialsRule:110` / `SensitiveParameterRule:98`                                                              |

The five assigned blocks already resolved by R7/R9 are the old
`06b7bf0676e9e032f187dea9ebbf94335c77ebf34b4ff76f1a056286cff92d20`
(`DependencyGraphBuilder` internal),
`d1fafbc8b357fb06bd7815d8f90dc40cc200de12e5446d60a1903bffbc7a8302`
(`InheritanceRule` / `LcomRule`),
`4fc2c2c9fff7e51bc04e94503b7abae2c71e440fb88a5c18160eab676e57baec`
(`InheritanceRule` / `NocRule`),
`3c0244f6cf101015d3fd38fee63890d79143cdc0c7af80f98c8db88ce463ef73`
(`CboRule` / `InstabilityRule`), and
`09405dec5d6f9142ca40124be84372ce3d7019776a4d238eb629409e8831003c`
(`PropertyCountRule` / `InheritanceRule`). They are absence oracles, not R11
edit authorization.

The pre-implementation eleven groups outside R11 remain read-only preservation
oracles:
`26a58f2d85548561d6fcc917fcb96cbc3d4be28a8be6e68ad8fe06eba740c39f`
(31), `31b8775fc4e026b195ccf4658c84f5e4487140ff179e7630a957597e745c5ec6`
(29), `340769d37bbfa5bb49848221d4ff08eee5dea9ebe581d7474de43fe0575a05e6`
(36, improved from 37),
`67c3f109dc2d3e97dfbc5970cdcc28984b8b97aed34bd04c794318b8a2b9fb9d`
(28), `811f8d3431093917bd00903847425e1aad39a1a5b056fadb7c3a28650f4a1c4f`
(30), `8344124eb0a837e96df5ecbc6afb2f6c57e78ec5e5bbb494ae83dfb265937906`
(41), `96c68d1b4e47d429fd7102e2e6eb0ed016c864097eb67169fbd42e7f0a6f9ecb`
(20), `9964fabbf3e849d9fadc64c9b0ef0e113ba0e4d50ab3fb4b105adfebc5dbb79f`
(46), `cf1eed47dd349df2fc2727d0a9436ea60b3952ad558fff90e151e47bf162a86f`
(45), `d3feecf066ef9d586a89e2e5f19bad14fdc5240f414b6b3f5208498f794848a9`
(30), and `ffe945d02651bf409e26730ccd6368e201469531411f2a1a7ad01426640e8c2d`
(24). The old improved `InstabilityRule` successor is now absent and the
deleted `BaselineMigrateCommand` block remains retired.

The final 693-file scan closes that preservation ledger as nine surviving and
two improved-away, with no regression. Eight identities remain literal:
`26a58f2d85548561d6fcc917fcb96cbc3d4be28a8be6e68ad8fe06eba740c39f`,
`31b8775fc4e026b195ccf4658c84f5e4487140ff179e7630a957597e745c5ec6`,
`340769d37bbfa5bb49848221d4ff08eee5dea9ebe581d7474de43fe0575a05e6`,
`67c3f109dc2d3e97dfbc5970cdcc28984b8b97aed34bd04c794318b8a2b9fb9d`,
`9964fabbf3e849d9fadc64c9b0ef0e113ba0e4d50ab3fb4b105adfebc5dbb79f`,
`cf1eed47dd349df2fc2727d0a9436ea60b3952ad558fff90e151e47bf162a86f`,
`d3feecf066ef9d586a89e2e5f19bad14fdc5240f414b6b3f5208498f794848a9`,
and `ffe945d02651bf409e26730ccd6368e201469531411f2a1a7ad01426640e8c2d`.
Old `8344124eb0a837e96df5ecbc6afb2f6c57e78ec5e5bbb494ae83dfb265937906`
maps to token-successor
`fffecc238d6441b05e587f494f23e452447aa3a1c8093259277d5cb534bdbe9e`
at the same 41-line `InheritanceDepthVisitor` / `MethodCountVisitor` pair.
Old `811f8d3431093917bd00903847425e1aad39a1a5b056fadb7c3a28650f4a1c4f`
and `96c68d1b4e47d429fd7102e2e6eb0ed016c864097eb67169fbd42e7f0a6f9ecb`
are improved-away. All fourteen assigned groups are absent.

**Finite active finding-owner census (20 production owners):**

```text
src/Analysis/Collection/Dependency/Export/DotExporter.php
src/Metrics/Complexity/CyclomaticComplexityVisitor.php
src/Metrics/Complexity/NpathComplexityVisitor.php
src/Metrics/Design/TypeCoverageVisitor.php
src/Metrics/Halstead/HalsteadVisitor.php
src/Metrics/Size/MethodStatementCountVisitor.php
src/Metrics/Structure/MethodCountVisitor.php
src/Rules/CodeSmell/ConstructorOverinjectionRule.php
src/Rules/CodeSmell/IdenticalSubExpressionRule.php
src/Rules/CodeSmell/LongParameterListRule.php
src/Rules/CodeSmell/UnreachableCodeRule.php
src/Rules/Complexity/CognitiveComplexityRule.php
src/Rules/Complexity/ComplexityRule.php
src/Rules/Design/DataClassRule.php
src/Rules/Design/GodClassRule.php
src/Rules/Maintainability/MaintainabilityRule.php
src/Rules/Security/HardcodedCredentialsRule.php
src/Rules/Security/SensitiveParameterRule.php
src/Rules/Size/MethodCountRule.php
src/Rules/Structure/InheritanceRule.php
```

The supporting production editors are exactly these sixteen paths, so the full
R11 production union is 20 finding owners + 16 support owners = 36 unique PHP
files:

```text
src/Core/Symbol/MetricSubjectCodec.php
src/Metrics/VisitorCallableScope.php [new]
src/Metrics/VisitorFileEntryScope.php
src/Metrics/VisitorCallableMetadata.php
src/Metrics/VisitorMethodContext.php
src/Metrics/VisitorMethodTrackingTrait.php
src/Metrics/CodeSmell/CodeSmellVisitor.php
src/Metrics/CodeSmell/ParameterCountVisitor.php
src/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitor.php
src/Metrics/CodeSmell/UnreachableCodeVisitor.php
src/Metrics/Complexity/CognitiveComplexityVisitor.php
src/Metrics/Security/Credential/HardcodedCredentialsVisitor.php
src/Metrics/Security/SecurityPatternVisitor.php
src/Metrics/Security/SensitiveParameterVisitor.php
src/Rules/CodeSmell/CodeSmellFinding.php
src/Rules/Security/SecurityPatternFinding.php
```

`qmx.yaml` is the only configuration editor. Its sole permitted byte change is
one adjacent metrics-foundation pattern,
`'Qualimetrix\Metrics\VisitorCallableScope'`, after
`VisitorCallableMetadata`; all other bytes, including all six existing R4
foundation patterns and their comment, retain their pre-R11 hash. The exact
pre-edit SHA-256 is
`6842b36e117438eb393577129109eeb028649c5a0a61468081f0971530ec6862`;
the file with only that adjacent line added must hash to
`44ad5c7f93bb9210a599126936123bc673659074a4a6bc40b4d076133ce6ad39`.
The exact architecture command must prove the new type is assigned and the
layer graph remains green. The R10 inverted-MI point control is preserved only
while `NpathComplexityVisitor` is being edited; its final disposition is the
measured post-refactor gate below, not byte preservation.

**Approved supporting contracts:**

The new VO stays in the Metrics foundation because
`VisitorFileEntryScope` produces and promises its traversal semantics to metric
visitors; moving it to Core merely because twelve consumers use it would invert
contract ownership. It has no category-specific dependency, and the exact
`qmx.yaml` assignment enforces this boundary.

| Owner                        | Exact signature, responsibility, dependencies and invariant                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | Direct regression owner                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `VisitorCallableScope`       | `final readonly class` with public promoted fields `?string $namespace`, `?string $class`, `bool $anonymousClassContext`, `string $member`, `string $logicalFqn`, `string $traversalKey`, `int $startFilePos`, `int $sourceLine`, `CallableKind $kind`, `?string $anonymousSyntax`, `?int $classStartFilePos`. It depends only on Core `CallableKind`, contains no parser node, mutable collection or metric, and is the one typed identity passed from traversal to projection.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | new `VisitorMethodContextTest` construction/value assertions and PHPStan                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `VisitorFileEntryScope`      | Sole mutable namespace/class/property/callable/closure/collision state. Exact entry is `enterCallable(?string $namespace, ?string $class, ?string $member, int $startFilePos, int $sourceLine, CallableKind $kind, ?string $anonymousSyntax, ?int $classStartFilePos): VisitorCallableScope`. Function/method/property-hook callers supply a non-empty real member; closure/arrow callers must supply `null`. Reject a supplied member for `AnonymousCallable` and reject null/empty for every other kind. Only this method increments the sole closure counter and replaces null with `{closure#N}` exactly once. It computes `logicalFqn` as `namespace\\class::member` for class context or `namespace\\member` otherwise, omitting the namespace separator when namespace is null/empty; it computes `traversalKey` as `logicalFqn@startFilePos#collisionOrdinal`, where the per-base ordinal starts at zero. It pushes the exact VO and returns it. Anonymous class entry stores `{anonymous@startFilePos}` plus its position and `anonymousClassContext=true`; a named class nested under that lineage keeps its syntax name but retains the true flag. Metadata may construct lexical identity but never a declaring owner while the flag is true. `leaveCallable(): ?VisitorCallableScope` returns that same top VO before popping; `reset()` empties every stack/counter. | new `VisitorMethodContextTest`; `AnonymousClassContextRegressionTest`; all consumer oracles below                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `VisitorMethodContext`       | Exact final public surface is `reset(): void`, `enter(Node): ?VisitorCallableScope`, `leave(Node): ?VisitorCallableScope`, `currentFileEntrySubjectId(): string`, `fileEntrySubjectComponents(string): array<string,int|string>`, `createCallableWithMetrics(VisitorCallableScope, RelativePath, MetricBag, ?int): CallableWithMetrics`, `callableCollisionOrdinals(array<string,VisitorCallableScope>): array<string,int|null>`, and `projectLogicalMetricMap(array<string,mixed>, array<string,VisitorCallableScope>): array<string,mixed>`. `enter` updates lexical state first and returns only a newly entered callable; `leave` returns the exact leaving callable before popping it, then leaves property/class/namespace state. `reset` resets the sole `VisitorFileEntryScope`; old traversal-key/classifier accessors and every array-record overload are removed.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | new `VisitorMethodContextTest` pins that exact surface, rejected caller-synthesized anonymous member and missing named member, enter-before-return, leave-before-pop, namespace/class nesting, named and anonymous classes containing closure then arrow, a named class nested under anonymous lineage, sibling closure ordinals, exact logical FQNs/keys, property hook, collision ordinal and reset/reuse. It moves the obsolete CodeSmell reset assertion to real `enter()` scopes: enter/leave the same positioned `App\\run` function twice yields `App\\run@12#0`, then `#1`; after `reset()` and namespace re-entry it yields `#0`. `VisitorCallableMetadata` still has no mutable properties. |
| `VisitorMethodTrackingTrait` | Exact callable boundaries are `resetVisitorMethodContext(): void`, `enterVisitorMethodContext(Node $node): ?VisitorCallableScope`, `leaveVisitorMethodContext(Node $node): ?VisitorCallableScope`, `createCallableWithMetrics(VisitorCallableScope $scope, RelativePath $file, MetricBag $metrics, ?int $ordinal = null): CallableWithMetrics`, `callableCollisionOrdinals(array<string,VisitorCallableScope> $scopes): array<string,int|null>`, `projectLogicalMetricMap(array<string,mixed> $metrics, array<string,VisitorCallableScope> $scopes): array<string,mixed>`, `currentFileEntrySubjectId(): string`, and `fileEntrySubjectComponents(string $subjectId): array<string,int|string>`. Delete aliases `resetFileEntrySubjectTracking`, `resetCallableTraversalKeys`, `enterFileEntrySubjectContext`, `leaveFileEntrySubjectContext`; delete `createCallableTraversalKey`, all three FQN builders, anonymous-class builder, both class-like classifier/extractor wrappers, and every array-record overload. The complete 12 consumers migrate in the same package; there is no compatibility alias.                                                                                                                                                                                                                                                                       | the twelve enumerated consumer tests below plus context test                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `VisitorCallableMetadata`    | `create(VisitorCallableScope $scope, RelativePath $file, MetricBag $metrics, ?int $ordinal = null): CallableWithMetrics`; `collisionOrdinals(array<string, VisitorCallableScope> $scopes): array<string, int|null>`; `projectLogicalMetricMap(array<string,mixed> $metrics, array<string, VisitorCallableScope> $scopes): array<string,mixed>`. It consumes VO fields directly and preserves logical/lexical/ordinal projection without an array roundtrip; declaring owner is constructed only for method/property-hook scope with `anonymousClassContext=false`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | new context test for collision inputs plus existing collector projections                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `MetricSubjectCodec`         | `public static function decodeEntry(array $entry, RelativePath $containerFile): MetricSubject`. It selects exactly `subjectKind`, `logicalKind`, `namespace`, `class`, `member`, `startFilePos`, `collisionOrdinal`; retains only `int|string`; then calls `decode()`. Unrelated scalar/bool/float keys are ignored. A retained key holding bool/float is dropped and therefore makes `decode()` fail fast when that key is required. The caller-supplied container file remains authoritative.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | `MetricSubjectCodecTest` matrix: four valid file/class/method/function shapes; unrelated scalar/bool/float; every retained bool/float; forbidden and missing keys; conflicting entry path data cannot replace the container file                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| `CodeSmellFinding`           | `fromEntry(array $entry, RelativePath $file): self` calls `decodeEntry()` and deletes `SUBJECT_KEYS` plus its local projection. Location, optional-extra/promoted presence bits and complete `toViolation()` output remain its category-specific responsibility.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | `CodeSmellFindingTest`; exact file/class/method/function subjects, occurrence and output fields                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `SecurityPatternFinding`     | `fromEntry(array $entry, RelativePath $file): self` calls `decodeEntry()` and deletes `SUBJECT_KEYS` plus its local projection. Location, superglobal suffix and complete `toViolation()` output remain its category-specific responsibility.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      | `SecurityPatternFindingTest`; exact subjects, suffix/occurrence and output fields                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |

**Eight formerly omitted trait-consumer editors:**

| Production editor                                  | Exact migration; output invariant                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | Test fence                                             |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| `CodeSmellVisitor`                                 | `resetFileEntrySubjectTracking()` -> `resetVisitorMethodContext()`; `enterFileEntrySubjectContext($node)` -> `enterVisitorMethodContext($node)` with the nullable scope intentionally ignored; leave alias maps likewise. File-entry subject ID/components calls stay typed and unchanged. Delete obsolete test `itResetsTraversalKeysPerFileWithoutMetadataLifecycleState` and its now-unused `VisitorMethodContext`, `VisitorCallableMetadata`, and `ReflectionClass` imports; the new context test owns the replacement below.                                                                                                                 | existing `CodeSmellVisitorTest` is an editor           |
| `RepeatedExpression/IdenticalSubExpressionVisitor` | The same reset/enter/leave alias migration; finding capture still occurs after enter and before leave, so exact file/class/callable subject components and order do not change.                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | existing `IdenticalSubExpressionVisitorTest` read-only |
| `Credential/HardcodedCredentialsVisitor`           | The same reset/enter/leave alias migration; credential literal projection continues to receive `currentFileEntrySubjectId()` after entry.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | existing `HardcodedCredentialsVisitorTest` read-only   |
| `SecurityPatternVisitor`                           | The same reset/enter/leave alias migration; pattern locations keep the same current subject ID and entry order.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | existing `SecurityPatternVisitorTest` read-only        |
| `SensitiveParameterVisitor`                        | The same reset/enter/leave alias migration; parameter locations keep the same current subject ID and order.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | existing `SensitiveParameterVisitorTest` read-only     |
| `ParameterCountVisitor`                            | Replace `array<string,array{namespace:?string,class:?string,method:string,startFilePos:int,sourceLine:int,kind:CallableKind,anonymousSyntax:?string,classStartFilePos:?int,logicalFqn:string}> $methodInfos` with `array<string,VisitorCallableScope> $scopes`. `enterNode` stores the non-null result of `enterVisitorMethodContext($node)` under `$scope->traversalKey` and initializes only parameter/VO-constructor counters; `leaveNode` uses the returned scope to close metric state. Delete local namespace/class/property/closure stacks, all FQN/traversal/classifier builders and array projections; metadata calls receive `$scopes`. | existing `ParameterCountCollectorTest` read-only       |
| `UnreachableCodeVisitor`                           | The same typed `$scopes` transition, with only unreachable count/first-line stacks retained; entry/leave results select the metric accumulator by `$scope->traversalKey`. Delete the same local identity state/builders/array overloads.                                                                                                                                                                                                                                                                                                                                                                                                          | existing `UnreachableCodeCollectorTest` read-only      |
| `CognitiveComplexityVisitor`                       | The same typed `$scopes` transition; `startMethod(VisitorCallableScope $scope): void` initializes cognitive/nesting/increment state and `endMethod(VisitorCallableScope $scope): void` closes it. Delete local identity stacks/builders/classifiers and array overloads; cognitive nesting and increment ordering remain metric-local.                                                                                                                                                                                                                                                                                                            | existing `CognitiveComplexityVisitorTest` read-only    |

Before the seven production files whose tests remain read-only are edited, run
their exact seven tests together and require green discovery/compilation. Run
the same command after the migration without editing those seven tests. If any
assertion must change rather than merely compile against the private trait
migration, STOP and revise the test editor fence; implementation may not
silently convert another read-only oracle into an editor.

```text
vendor/bin/phpunit \
  tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitorTest.php \
  tests/Unit/Metrics/CodeSmell/ParameterCountCollectorTest.php \
  tests/Unit/Metrics/CodeSmell/UnreachableCodeCollectorTest.php \
  tests/Unit/Metrics/Complexity/CognitiveComplexityVisitorTest.php \
  tests/Unit/Metrics/Security/Credential/HardcodedCredentialsVisitorTest.php \
  tests/Unit/Metrics/Security/SecurityPatternVisitorTest.php \
  tests/Unit/Metrics/Security/SensitiveParameterVisitorTest.php
```

Together with the three rule rows below, those are the complete five direct
`decodeEntry()` consumers; repository-wide callsite search must report exactly
those five and no surviving local seven-key projection.

**Finding-owner implementation table:** public contracts remain unchanged;
each row names the only permitted private boundary and the identity it clears.

| Production owner               | Exact private operation / invariant                                                                                                                                                                                                                                                                                                          | Finding cleared and direct test                                                                                                                                                                                                                                         |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DotExporter`                  | Add `appendEdges(array<string> &$lines, DependencyGraphInterface $graph, array<string,true> $classSet): void`; both flat and clustered exporters call it after their distinct node layout. Preserve graph order, filtering and escaping.                                                                                                     | fresh `17f2a76fecd3d53c721a9bfd64a168872ba669ad0ffd732ca51a6f8471ace352`; `DotExporterTest`                                                                                                                                                                             |
| `CyclomaticComplexityVisitor`  | Store `array<string,VisitorCallableScope> $scopes`; `startMethod(VisitorCallableScope $scope): void` initializes only CCN state and `endMethod(VisitorCallableScope $scope): void` closes it. Entry/leave consume typed transitions; all local identity stacks/builders/classifiers and array records are deleted.                           | worsening `6e14c15fd5b2358bc89d5c4855e48e3721fd78cdb127a049debde8432f6cb964` <=48; collector + visitor tests                                                                                                                                                            |
| `NpathComplexityVisitor`       | Store typed `$scopes`; `startMethod(VisitorCallableScope $scope, int $npath): void` owns only NPATH/factor state and `endMethod(VisitorCallableScope $scope): void` closes it. Delete local identity stacks/builders/classifiers and array records. Preserve the R10 MI point control only during edits, then apply the measured gate below. | worsening `6e14c15fd5b2358bc89d5c4855e48e3721fd78cdb127a049debde8432f6cb964` <=48; `NpathComplexityCollectorTest`                                                                                                                                                       |
| `HalsteadVisitor`              | Store typed `$scopes`; `startMethod(VisitorCallableScope $scope): void` initializes only operator/operand state and `endMethod(VisitorCallableScope $scope): void` closes it. Metadata receives scopes directly; delete local identity stacks/builders/classifiers and array records.                                                        | worsening `cfee572cddb5cc0af3eb95dd29715f0de04a7bc751810b6ebb5421e2e454512c` <=32 and fresh `c320a4d31b0ee06bf3016652d7d71cf9c44ce573901a07513ae222e52f080742`, `30828a62103ec2298297057964489aaf7deea7a788bf96d95113ca25492285fd`; `HalsteadCollectorTest`             |
| `MethodStatementCountVisitor`  | Store typed `$scopes` plus a separate count map; `startMethod(VisitorCallableScope $scope): void` initializes count and `endMethod(VisitorCallableScope $scope): void` closes it. Metadata receives scopes directly; delete local identity stacks/builders/classifiers and array records.                                                    | worsening `cfee572cddb5cc0af3eb95dd29715f0de04a7bc751810b6ebb5421e2e454512c` <=32 and fresh `c320a4d31b0ee06bf3016652d7d71cf9c44ce573901a07513ae222e52f080742`, `30828a62103ec2298297057964489aaf7deea7a788bf96d95113ca25492285fd`; `MethodStatementCountCollectorTest` |
| `TypeCoverageVisitor`          | Delete its private class-like classifier/extractor pair; `enterNode` performs the direct `ClassLike` check and reads `name?->toString()` once where coverage state is entered. It does not become a callable-context consumer.                                                                                                               | fresh `a4f805f803bbe4b7d4649cb32808ac506d81989f91d177f113e265a0e5715567`; `TypeCoverageCollectorTest`                                                                                                                                                                   |
| `MethodCountVisitor`           | Delete its private class-like classifier/extractor pair; `enterNode` performs its direct `ClassLike` check and reads `name?->toString()` once where method-count state is entered. It does not become a callable-context consumer.                                                                                                           | fresh `a4f805f803bbe4b7d4649cb32808ac506d81989f91d177f113e265a0e5715567`; `MethodCountCollectorTest`                                                                                                                                                                    |
| `ConstructorOverinjectionRule` | `analyze()` performs its exact options guard then maps symbols through existing `checkSymbol`; no generic rule template.                                                                                                                                                                                                                     | fresh `b420edb29c01449c5a1121a494f52f5d0ef7c0d1a81c0e9c0cdb04e306e97949`, `c58dfc46988f8014caaf35efca5a6f2db1db53fa12d25cec61fe08851d17ff3d`; its rule test                                                                                                             |
| `LongParameterListRule`        | `analyzeEnabledSymbols(AnalysisContext $context): list<Violation>` owns callable plus VO-constructor policy and stable symbol order.                                                                                                                                                                                                         | fresh `b420edb29c01449c5a1121a494f52f5d0ef7c0d1a81c0e9c0cdb04e306e97949`; its rule test                                                                                                                                                                                 |
| `UnreachableCodeRule`          | `violationsForReachableSymbols(AnalysisContext $context): list<Violation>` owns unreachable-entry projection and stable order.                                                                                                                                                                                                               | fresh `c58dfc46988f8014caaf35efca5a6f2db1db53fa12d25cec61fe08851d17ff3d`; its rule test                                                                                                                                                                                 |
| `IdenticalSubExpressionRule`   | Use `MetricSubjectCodec::decodeEntry`; delete `subjectComponents`; retain exact fixed magnitude and semantic occurrence.                                                                                                                                                                                                                     | fresh `4c7b507b1e8bf0626f7f25c1fda24e5438b1786371fb9bb97e53cc2c681e3705`; its rule test                                                                                                                                                                                 |
| `HardcodedCredentialsRule`     | Use `decodeEntry`; `violationsForEntries(SymbolInfo $fileInfo, list<array<string,bool|float|int|string>> $entries, AnalysisContext $context): list<Violation>` retains pattern occurrence/order and severity policy.                                                                                                                         | fresh `4c7b507b1e8bf0626f7f25c1fda24e5438b1786371fb9bb97e53cc2c681e3705`, `79c655d195f4bf9ac9261743ff1847a69946f1f0acc65de3c106b13faf1b445c`; its rule test                                                                                                             |
| `SensitiveParameterRule`       | Use `decodeEntry`; `violationForEntry(SymbolInfo $fileInfo, array<string,bool|float|int|string> $entry, AnalysisContext $context): ?Violation` retains parameter occurrence/order and severity policy.                                                                                                                                       | fresh `79c655d195f4bf9ac9261743ff1847a69946f1f0acc65de3c106b13faf1b445c`; its rule test                                                                                                                                                                                 |
| `CognitiveComplexityRule`      | `classViolation(SymbolInfo $classInfo, MetricSubject $subject, int $maximum, ClassCognitiveComplexityOptions $options): ?Violation` owns cognitive maximum projection.                                                                                                                                                                       | worsening `18b3fb5d0f6fafbdae68c413ff247baa6a91760f2ab2cb03693d70416a8ff411` <=31; its rule test                                                                                                                                                                        |
| `ComplexityRule`               | `classViolation(SymbolInfo $classInfo, MetricSubject $subject, int $maximum, ClassComplexityOptions $options): ?Violation` owns CCN maximum/recommendation projection.                                                                                                                                                                       | worsening `18b3fb5d0f6fafbdae68c413ff247baa6a91760f2ab2cb03693d70416a8ff411` <=31; its rule test                                                                                                                                                                        |
| `DataClassRule`                | Existing `evaluateClass(AnalysisContext $context, SymbolInfo $classInfo): ?Violation` keeps its data-class criteria; `analyzeEligibleClasses(AnalysisContext $context): list<Violation>` owns the exact options guard and class iteration.                                                                                                   | worsening `c89f21a6b8804780237e5eec8cea9e0abdef4464714467c3b5fac7d720c558a6` <=28; its rule test                                                                                                                                                                        |
| `GodClassRule`                 | `analyze()` delegates each class to existing `evaluateClass`; exclusions and matched/evaluable severity remain local.                                                                                                                                                                                                                        | worsening `c89f21a6b8804780237e5eec8cea9e0abdef4464714467c3b5fac7d720c558a6` <=28; its rule test                                                                                                                                                                        |
| `MaintainabilityRule`          | `violationForMetric(SymbolInfo $methodInfo, MetricSubject $subject, float $miValue, MaintainabilityOptions $options): ?Violation` owns inverted MI direction/message; test-file and minimum-statement filters stay in `analyze()`.                                                                                                           | worsening `8ebdd25761c9aedd41a6ce70938c462bfd6852ac5876bc34a49f83d99b1c0013` <=22; its rule test                                                                                                                                                                        |
| `MethodCountRule`              | `violationForClass(SymbolInfo $classInfo, MetricSubject $subject, int $methodCount, MethodCountOptions $options): ?Violation` owns method-count direction/message.                                                                                                                                                                           | worsening `aa6fbcae7e3419632c5c7a60251264700f1d627cc13d7b4defe8540b729fc0f0` <=25; its rule test                                                                                                                                                                        |
| `InheritanceRule`              | `violationForClass(SymbolInfo $classInfo, MetricSubject $subject, int $ditValue, InheritanceOptions $options): ?Violation` owns only DIT severity/message. Its loop remains declaration-only and preserves order.                                                                                                                            | worsening `8ebdd25761c9aedd41a6ce70938c462bfd6852ac5876bc34a49f83d99b1c0013` <=22 and `aa6fbcae7e3419632c5c7a60251264700f1d627cc13d7b4defe8540b729fc0f0` <=25; its rule test                                                                                            |

No `AbstractRule` template, generic helper, token-only rewrite, category-policy
move or outward rule-contract change is allowed.

**Complete focused test editor set (28 unique paths):**

```text
tests/Unit/Analysis/Collection/Dependency/Export/DotExporterTest.php
tests/Unit/Core/Symbol/MetricSubjectCodecTest.php
tests/Unit/Metrics/VisitorMethodContextTest.php [new]
tests/Unit/Metrics/AnonymousClassContextRegressionTest.php
tests/Unit/Metrics/CodeSmell/CodeSmellVisitorTest.php
tests/Unit/Metrics/Complexity/CyclomaticComplexityCollectorTest.php
tests/Unit/Metrics/Complexity/CyclomaticComplexityVisitorTest.php
tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php
tests/Unit/Metrics/Design/TypeCoverageCollectorTest.php
tests/Unit/Metrics/Halstead/HalsteadCollectorTest.php
tests/Unit/Metrics/Size/MethodStatementCountCollectorTest.php
tests/Unit/Metrics/Structure/MethodCountCollectorTest.php
tests/Unit/Rules/CodeSmell/CodeSmellFindingTest.php
tests/Unit/Rules/CodeSmell/ConstructorOverinjectionRuleTest.php
tests/Unit/Rules/CodeSmell/IdenticalSubExpressionRuleTest.php
tests/Unit/Rules/CodeSmell/LongParameterListRuleTest.php
tests/Unit/Rules/CodeSmell/UnreachableCodeRuleTest.php
tests/Unit/Rules/Complexity/CognitiveComplexityRuleTest.php
tests/Unit/Rules/Complexity/ComplexityRuleTest.php
tests/Unit/Rules/Design/DataClassRuleTest.php
tests/Unit/Rules/Design/GodClassRuleTest.php
tests/Unit/Rules/Maintainability/MaintainabilityRuleTest.php
tests/Unit/Rules/Security/HardcodedCredentialsRuleTest.php
tests/Unit/Rules/Security/SecurityPatternFindingTest.php
tests/Unit/Rules/Security/SensitiveParameterRuleTest.php
tests/Unit/Rules/Size/MethodCountRuleTest.php
tests/Unit/Rules/Structure/InheritanceRuleTest.php
tests/Integration/Architecture/DogfoodingTopologyTest.php
```

The complete 12 visitor-consumer oracle set is:

```text
tests/Unit/Metrics/CodeSmell/CodeSmellVisitorTest.php
tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitorTest.php
tests/Unit/Metrics/CodeSmell/ParameterCountCollectorTest.php
tests/Unit/Metrics/CodeSmell/UnreachableCodeCollectorTest.php
tests/Unit/Metrics/Complexity/CognitiveComplexityVisitorTest.php
tests/Unit/Metrics/Complexity/CyclomaticComplexityVisitorTest.php
tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php
tests/Unit/Metrics/Halstead/HalsteadCollectorTest.php
tests/Unit/Metrics/Security/Credential/HardcodedCredentialsVisitorTest.php
tests/Unit/Metrics/Security/SecurityPatternVisitorTest.php
tests/Unit/Metrics/Security/SensitiveParameterVisitorTest.php
tests/Unit/Metrics/Size/MethodStatementCountCollectorTest.php
```

Exactly the seven tests named in the pre/post fence are read-only. The five
remaining tests in this twelve-row set (`CodeSmellVisitorTest`,
`CyclomaticComplexityVisitorTest`,
`NpathComplexityCollectorTest`, `HalsteadCollectorTest`, and
`MethodStatementCountCollectorTest`) are already included in the 28-path editor
set because their finding-owner visitors change shape.

`tests/Unit/Metrics/AnonymousClassContextRegressionTest.php` is a test-doc
editor: replace the obsolete narrative naming `extractClassLikeName()` and
`isClassLikeNode()` with a behavior-only statement that the outer lexical class
must be restored after leaving an anonymous class; all executable assertions
remain unchanged. `tests/Integration/Rules/PropertyHookControlPrecedenceTest.php`,
`tests/Integration/Architecture/DogfoodingTopologyTest.php`, and
`tests/Integration/Violation/ChannelCoverageTest.php` are also focused. The
topology test adds exactly `VisitorCallableScope.php` to the Metrics-root
inventory and exact foundation type set.

**Post-refactor NPATH MI gate:** keep the current adjacent annotation while
editing so an intermediate scanner run cannot create unrelated noise, then
measure the raw method metric with exactly:

```text
php bin/qmx check src/Metrics/Complexity/NpathComplexityVisitor.php \
  --config=qmx.yaml --format=metrics --workers=0 --no-cache \
  --fail-on=none --only-rule=maintainability.index \
  > /private/tmp/qmx-p6-r11-npath-mi.json
```

The artifact must contain exactly one method symbol named
`Qualimetrix\Metrics\Complexity\NpathComplexityVisitor::enterNode`; read its
finite `metrics.mi` as `raw`. If `raw >= 35.0`, delete the entire adjacent R10
`@qmx-threshold maintainability.index` line and prove the global default 35
accepts the method. If `raw < 35.0`, the exact complete annotation is
`@qmx-threshold maintainability.index warning=<raw> error=<raw> -- Finite
NPATH expression-factor dispatch and typed callable-scope transitions remain
one visitor responsibility.` using the full decimal emitted for `raw`. The
rule compares strictly `<`, so equality is the exclusive first accepted point.
No rounded current value,
global threshold/formula change or other inline control is permitted. Rerun
the same no-cache metrics command after the edit and require the identical raw
value, then run the complete common verifier.

**Post-implementation R1 correction — original seven rows plus one rejected-fix
successor:** both pinned scans are complete 693/693 with failed ==
generatedExcluded == 0 and exit zero. The original
`/private/tmp/qmx-p6-r11-evidence/selfscan-final.json` introduced the seven
rows below. In
`/private/tmp/qmx-p6-r11-evidence/selfscan-final-corrected.json`, all three
callable-contract controls plus the Context, Hardcoded and Complexity fixes
clear; the LongParameter cohesion row remains and the attempted options move
adds the eighth transient row listed last. Duplication is done in both: all
fourteen assigned groups are absent, nine preservation groups remain, two
improved away, and none worsened. These eight rows are the complete correction
authorization:

| Exact subject / channel                                                                                          | Value       | Final disposition                                                                                   |
| ---------------------------------------------------------------------------------------------------------------- | ----------: | --------------------------------------------------------------------------------------------------- |
| `VisitorCallableScope::__construct`, `code-smell.constructor-overinjection#code-smell.constructor-overinjection` | 11          | point control at 12/12                                                                              |
| `VisitorCallableScope::__construct`, `code-smell.long-parameter-list#code-smell.long-parameter-list`             | 11          | point control at 12/12                                                                              |
| `VisitorFileEntryScope::enterCallable`, `code-smell.long-parameter-list#code-smell.long-parameter-list`          | 8           | point control at 9/9                                                                                |
| `VisitorMethodContext::enter`, `complexity.cyclomatic#complexity.cyclomatic.callable`                            | 16          | private callable-dispatch extraction; public CCN <=10                                               |
| `HardcodedCredentialsRule::violationsForEntries`, `complexity.cyclomatic#complexity.cyclomatic.callable`         | 11          | private message-policy extraction; method CCN <=10                                                  |
| class `LongParameterListRule`, `computed.health#health.cohesion`                                                 | 30, LCOM4 3 | revert the real enabled guard to `analyze()` and add the exact class-scoped structural ignore below |
| class `ComplexityRule`, `coupling.cbo#coupling.cbo.class`                                                        | 20          | scalar cognitive recommendation input removes `MetricBag`; CBO <=19                                 |
| `LongParameterListRule::analyzeEnabledSymbols`, `complexity.cyclomatic#complexity.cyclomatic.callable`           | 10          | rejected-fix successor only: restore the enabled guard to `analyze()`; method CCN <=9               |

The first three are structural consequences of the approved exact contract,
not candidates for another parameter object: `VisitorCallableScope` must expose
eleven independent readonly identity/projection fields, and
`enterCallable()` must receive the exact eight scalar/parser-context inputs so
the sole mutable scope can validate and construct that VO atomically. A bundle
would recreate the prohibited array record; another helper/type would split
the state owner. **R1 decision:** retain the exact contract and approve these
three structural point controls; reject a contract redesign or another
subject. Add exactly these adjacent annotations and no others:

```text
VisitorCallableScope::__construct:
@qmx-threshold code-smell.constructor-overinjection warning=12 error=12 -- Exact immutable callable scope exposes eleven independent identity fields; bundling them would recreate the prohibited array record.
@qmx-threshold code-smell.long-parameter-list warning=12 error=12 -- Exact immutable callable scope constructor mirrors eleven readonly identity fields; bundling them would recreate the prohibited array record.

VisitorFileEntryScope::enterCallable:
@qmx-threshold code-smell.long-parameter-list warning=9 error=9 -- Sole mutable traversal scope atomically validates and constructs callable identity from eight context inputs.
```

Both rules compare higher-worse with `>=`; therefore 11 is accepted by 12 and
8 is accepted by 9, while equality at 12/9 is the first violation. Do not use
11/8, a global option, `qmx.yaml`, baseline, ignore, exclusion or suppression.
`VisitorMethodContextTest` continues to reflectively pin all eleven public
fields and the exact eight-parameter entry signature; the common selfscan pins
the three point outcomes.

The `LongParameterListRule` cohesion row is also structural, but for a
different reason. Direct LCOM4 inspection proves the analysis and both
projection methods are already one connected component; the other two
components are the interface metadata methods `getCategory()` and `requires()`,
which return the real external `RuleCategory::CodeSmell` and `MetricName::*`
constants. The LCOM4 stateless merge recognizes only scalar, array and
`self::`/`static::` constant-return shapes, so it cannot classify these two
external constant returns as stateless protocol metadata. Do not add private
constants used once, fake reads or a metadata helper merely to change that AST
shape. `ComputedMetricRule` does not consume per-subject threshold overrides,
so `@qmx-threshold` is not a valid control for this channel. **R1 decision:**
retain the truthful external metadata and approve one class-scoped
`health.cohesion` ignore; reject constant indirection, helper extraction and
contract redesign. Add exactly this class-docblock control:

```text
@qmx-ignore health.cohesion -- Interface metadata methods getCategory() and requires() return external enum/metric constants beside one cohesive analysis/projection component; LCOM4 cannot merge those stateless protocol methods.
```

Restore and retain the exact real gate in
`analyze(AnalysisContext $context): array`: if `$this->options` is not
`LongParameterListOptions` or is disabled, return `[]`; otherwise call
`analyzeEnabledSymbols($context)`. Retain
`checkSymbol(SymbolInfo $symbolInfo, MetricSubject $subject, int $parameterCountValue, SymbolType $symbolType, AnalysisContext $context, LongParameterListOptions $options): ?Violation`
and
`checkVoConstructor(SymbolInfo $symbolInfo, MetricSubject $subject, int $parameterCount, AnalysisContext $context, LongParameterListOptions $options): ?Violation`;
`analyzeEnabledSymbols()` asserts the options type once and passes that exact
object to both. `LongParameterListRuleTest` must pin wrong-options
and disabled early return, regular and VO defaults/overrides/equalities, stable
order and exact violations. `src/Rules/README.md` records this one rule-class
dogfood control and its exact reason. The no-cache selfscan must report no
`computed.health#health.cohesion` finding for the class and no fresh CCN,
cohesion, parameter or duplication identity; specifically,
`analyzeEnabledSymbols` must be CCN <=9 and the transient value-10 identity
must be absent.

The remaining three rows are source fixes inside the existing 36-file fence:

| Owner                      | Exact private correction                                                                                                                                                                                                                                                                                                                                                                                                                                                  | Required direct gate                                                                                                                                                   |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `VisitorMethodContext`     | `enter(Node $node): ?VisitorCallableScope` retains only the lexical namespace/class/property match, then calls `private enterCallable(Node $node, ?array{namespace:?string,class:string,start:int,anonymous:bool,subject:?string} $class, ?string $namespace): ?VisitorCallableScope`; that helper owns the closed Function/Closure/Arrow/ClassMethod/PropertyHook dispatch and delegates member/hook details to the existing private methods. No state or type is added. | `VisitorMethodContextTest` repeats every transition/anonymous/collision case; `enter` CCN <=10 and the new helper has no unexpected fresh identity.                    |
| `HardcodedCredentialsRule` | Add `private messageForPattern(string $pattern): string`; it owns the closed seven-pattern/default match and appends the secrets-manager suffix. `violationsForEntries()` retains entry order, decode, severity and complete violation projection.                                                                                                                                                                                                                        | `HardcodedCredentialsRuleTest` pins all seven patterns plus default and exact suffix; `violationsForEntries` CCN <=10 and helper CCN <=10 with no unexpected identity. |
| `ComplexityRule`           | Change `buildMethodRecommendation(int $ccnValue, int $threshold, MetricBag $metrics): string` to `buildMethodRecommendation(int $ccnValue, int $threshold, ?int $cognitive): string`; `analyzeMethodLevel()` reads `COMPLEXITY_COGNITIVE` once and passes null or the integer. Remove the now-unused `MetricBag` import/dependency.                                                                                                                                       | `ComplexityRuleTest` pins null, below-15 and equal/above-15 recommendation branches plus all violations; class CBO <=19 and no new helper finding.                     |

This R1 correction preserves the approved callable contract, all fourteen
duplication removals, the exact qmx foundation line/hash, and the final NPATH
MI disposition. It adds no path, type, role bucket, public/protected surface,
CHANGELOG/website requirement or test editor.

Documentation editors are exactly `src/Core/README.md`,
`src/Metrics/README.md`, and `src/Rules/README.md`; P7 later verifies them and
website has zero matching contract references. The additive Core method and
internal VO require no external consumer migration and no CHANGELOG Breaking
entry; the complete internal 12-consumer migration is owned above.
R4 -> R11 reopens the four existing visitor-foundation files, adds the fifth,
edits the complete twelve-consumer production set, edits five matching tests,
runs seven matching tests read-only, and reopens Metrics README, topology and
property-hook oracles. R9 -> R11
reopens `CodeSmellFinding.php`, `SecurityPatternFinding.php`, both finding
tests, `src/Core/README.md`, `src/Rules/README.md`, and read-only
`ChannelCoverageTest`; the five resolved R9 finding identities remain absence
oracles. R10 -> R11 reopens `MetricSubjectCodec` plus its test and the NPATH
visitor/test; the latter follows the measured MI gate above. The anonymous
context test changes only its stale explanatory docblock. P6-RG remains
sequential.

Detector implementation/tests, global thresholds, formulas, exclusions, all
other suppressions and `qmx-baseline.json` are immutable. `qmx.yaml` has only the
exact foundation-pattern addition above. Inline controls are limited to the
three exact structural annotations authorized by the R1 correction and the
exact `LongParameterListRule` cohesion ignore above, plus the R10 NPATH MI
point, which must end in exactly one of the two measured dispositions above;
no other point control is authorized. **DoD:** all 36
production paths and their ownership are present in the
implementation manifest; all 28 authorized test editors have only their named
changes, the seven fenced tests are unchanged and green both before and after
their consumer edits, the CodeSmell traversal-key assertion exists only in the
new context test through real `enter()` scopes, and the anonymous-context test
names no deleted helper while retaining every behavior assertion. All 14 assigned active groups
remain absent, the five resolved identities
remain absent, the nine surviving preservation identities do not worsen, and
the other two preservation identities remain improved away. The raw MI
artifact contains the one exact `enterNode` method and finite value; raw >=35
has no annotation, while raw <35 has exact raw/raw literals and the prescribed
finite-responsibility reason. The repeat metrics audit is identical. All named
tests plus `composer phpstan`, `composer cs-check`, `git diff --check`, and the
exact architecture command pass. Repository search finds none of the deleted
trait aliases/builders/classifiers or array-record signatures and exactly five
`decodeEntry()` consumers. The common verifier remains exactly
`php bin/qmx check src/ --config=qmx.yaml --format=json --workers=0 --no-cache`
with discovered == analyzed, failed == generatedExcluded == 0, source revision
plus dirty-tree manifest/hash, config/command/options/output hashes, and global
fresh/worsening/improved comparison. The corrected post-R11 verifier must stay
at the observed 693/693, failed == generatedExcluded == 0 and exit zero; its
manifest must account for every edited byte rather than copying a file-count
literal. It must report the first three original identities accepted only by
their exact adjacent threshold controls, the cohesion identity absent only by
its exact class-scoped ignore, the remaining three absent or within their
literal gates, the rejected-fix CCN-10 successor absent, no unexpected
fresh/worsening identity, all fourteen assigned
duplication identities absent, the nine/two preservation disposition above,
the exact qmx foundation line/hash, and the final NPATH MI disposition.

#### P6-RG — global coupling-health gate

**Depends on:** P6-R11. **Files:** no pre-authorized production, test,
configuration, control, or baseline edits. This gate reruns the exact pinned
current-tree shape after every R package is complete:

```text
php bin/qmx check src/ --config=qmx.yaml --format=json --workers=0 --no-cache
without --baseline, presets, rule selection, CLI source exclusions, or rule options
assert discovered == analyzed, failed == 0, generatedExcluded == 0
assert project computed.health#health.coupling >= frozen floor 48.0
record source revision, dirty-tree content manifest/hash, qmx.yaml hash,
       command/options, coverage, exit code, exact formula inputs/result,
       and output/artifact hashes in scratch metadata
```

The Stage 1 value `47.62489016022897` is unresolved evidence, not an accepted,
recalibrated, controlled, suppressed, excluded, or baselined value. If the
post-R11 value is still below 48.0, P6-RG stops before P5-F and repeats the
logical-edge, class-contributor, formula, and exact declaring-owner differential
against the approved read-only `ba1119a0` snapshot using the artifact schema in
`/private/tmp/qmx-r7-attribution-IzITsF/`. The new report must enumerate every
added/removed/type-changed edge and exact owner, then propose separately bounded
production/test/doc packages or an explicit reviewed decision. It must not
mechanically authorize the prior 103 outside owners or any new broad fence.

Formula, threshold, inline-control, suppression, exclusion, `qmx.yaml`, and
baseline changes are forbidden; current-magnitude copying and generic baseline
regeneration are also forbidden. Only a reviewed plan revision can authorize a
new source package. **DoD:** the pinned complete scan reports
`health.coupling >=48.0`, the 34-row ledger has 32 remediated rows, zero
unresolved rows, and exactly the already approved two R6 structural
recalibrations. No third R6 recalibration is introduced at this gate.

P6-RG's PASS closes that 34-row worsening ledger only. It does not authorize
an ancestorless baseline row: P5-F subsequently found the separate two-row
post-ledger fresh set in P6-RG1. The accounting therefore remains 32
remediated + zero unresolved + two R6 recalibrations, plus at most one
post-ledger structural fresh residual if and only if P6-RG1 receives its R1
decision and independent plan-review approval.

#### P6-RG1 — exact P5-F STOP correction and R1 decision gate

**Status:** STOP. This section records an exact candidate, not implementation
authorization. R1 must decide each disposition explicitly in one recorded
decision, and an independent plan review must accept the resulting contract before either
production work or baseline construction resumes. P5-F remains blocked.

P5-F's pinned run closed the v10 side without ambiguity: 311 mapped old groups
(including six split old groups), one renamed successor, 38 reviewed-remediated
absences, nine reviewed non-duplication retirements, and 13 reviewed duplication
retirements account for all 372 old groups. Its measured and crosswalk identity
sets are equal at 322/322. The only no-ancestor groups are:

| Exact identity                                                                                                                                                            | Measured value | Candidate disposition                                                                                                            |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------: | -------------------------------------------------------------------------------------------------------------------------------- |
| `declaration:class:Qualimetrix\\Analysis\\Collection\\CollectionOrchestrator@src/Analysis/Collection/CollectionOrchestrator.php:1050` / `computed.health#health.cohesion` | 40             | Remove by the exact source correction below; never baseline, control, suppress, exclude, or recalibrate it                       |
| `ns:Qualimetrix\\Metrics` / `coupling.cbo#coupling.cbo.namespace`                                                                                                         | 16             | Candidate for exactly one separately reviewed post-ledger structural fresh residual at ceiling 16; it is not an R6 recalibration |

The first attempted correction is rejected by exact STOP evidence. Changing
`appendControls` from static/`self::` to instance/`$this->` left cohesion
`40 -> 40`, method count 6, LCOM4 2, and the measured identity set unchanged at
`322 -> 322`:

| STOP artifact                                                    | SHA-256                                                            |
| ---------------------------------------------------------------- | ------------------------------------------------------------------ |
| pre-attempt measured JSON (322 identities, cohesion 40)          | `ba9604dbe5bde1a2dd6678293ea2bddd5666830cedbd81626a86983ee8b0e2ef` |
| post-attempt selfscan JSON (322 identities, cohesion 40)         | `eec332b11af3415100e99691541d7412cf2b1bfe74c08c16dd4e9d6a2e000eb6` |
| post-attempt metrics JSON (method count 6, LCOM4 2, cohesion 40) | `d9e914948fa24dd7b674696ef0d7b6cbbee32e60ff412caf0b1a24b0d363f1db` |
| semantic before/after comparison                                 | `6a257b3c5f354919834ca0dc3e71f9a67a95f3eee6eb7a70170909f73c2b62b4` |

That static-to-instance interim shape is not an implementation option and must
be absent from the accepted diff.

If R1 approves, the source correction reopens only these two tracked files:

```text
src/Analysis/Collection/CollectionOrchestrator.php
tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php
```

Keep `foldResults(iterable $results, MetricRepositoryInterface $repository):
CollectionPhaseOutput` as the owner of the successful/failure fold and preserve
its result, dependency, suppression, threshold-override, threshold-diagnostic,
progress, logging, and encounter-order boundaries byte-for-byte in behavior.
Delete the one-caller private `appendControls` method entirely. At the same
point in the successful-result branch, immediately after dependency append,
inline its exact three ordered non-empty projections: suppression first,
threshold override second, threshold diagnostic third, each retaining the same
file-path key and exact list value. Do not introduce a closure, helper, field,
type, dependency, control, threshold, suppression, or public/protected surface.
The accepted source contains no `appendControls` declaration or call and no
static-to-instance interim API. This reduces declared method count exactly
`6 -> 5`, so the existing missing-TCC fallback applies instead of the measured
LCOM4 component split. Preserve the strengthened direct mixed regression
already present in `CollectionOrchestratorTest`: non-empty and empty
suppression, override, and diagnostic collections across success/failure and
encounter-order cases, without reflective private-helper access. All R7
constructor, CBO, phase-order, profiler, failure-isolation, progress, logger,
and output contracts remain unchanged.

The source-correction DoD is exact: run
`tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php`, then the common
unbaselined workers-0/no-cache JSON and metrics scans. Coverage is complete;
there is no `appendControls` method/call or static-to-instance interim shape;
`collect` remains exactly cognitive 2 / CCN 2 / NPATH 2; `foldResults` is
exactly cognitive 13 / CCN 6 / NPATH 10 after the inline; declared method count
is 5; and the exact CollectionOrchestrator cohesion identity above is absent.
The scan also keeps every approved R7 gate no worse than its ceiling:
`DependencyVisitor` CBO <=24, `enterNode` CCN <=14 and NPATH <=576,
`DependencyGraphBuilder::build` cognitive <=16, CCN <=12 and NPATH <=592,
`AnalysisPipeline` WMC <=52, all six assigned R7 fresh groups absent, and
`Analysis\\Collection` namespace CBO <=19. The Metrics namespace CBO identity
remains the sole post-ledger fresh group at exactly 16; there is no other fresh
or worsening identity. If the inline raises any assigned or unexpected row,
changes either exact callable triple, or fails to remove cohesion, STOP instead
of expanding this fence or constructing a baseline.

The Metrics namespace candidate requires a separate topology proof before R1
may approve it. The finite progression is CBO 10 at read-only `ba1119a0`, CBO
12 at `HEAD e04e88769640e5651b6a44ac10419a76c726f7a1`, and CBO 16 in the
R4/R11 worktree. The exact four namespace neighbors added after `HEAD` are:

| Added namespace neighbor                           | Exact edge owner and reason                                                                                                |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `PhpParser\Node`                                   | `VisitorMethodContext` names `PropertyHook` in its callable dispatch                                                       |
| `PhpParser\Node\Expr`                              | `VisitorMethodContext` names `Closure` and `ArrowFunction` in its callable dispatch                                        |
| `Qualimetrix\Metrics\CodeSmell\RepeatedExpression` | the moved `IdenticalSubExpressionCollector` and `IdenticalSubExpressionVisitor` depend back on the root visitor foundation |
| `Qualimetrix\Metrics\Security\Credential`          | the moved `HardcodedCredentialsCollector` and `HardcodedCredentialsVisitor` depend back on the root visitor foundation     |

Those four namespace neighbors are supported by exactly these ten dependency
tuples; tuple type is part of the assertion:

| Source                                                                             | Target                                           | Type         |
| ---------------------------------------------------------------------------------- | ------------------------------------------------ | ------------ |
| `Qualimetrix\Metrics\VisitorMethodContext`                                         | `PhpParser\Node\PropertyHook`                    | `type_hint`  |
| `Qualimetrix\Metrics\VisitorMethodContext`                                         | `PhpParser\Node\PropertyHook`                    | `instanceof` |
| `Qualimetrix\Metrics\VisitorMethodContext`                                         | `PhpParser\Node\Expr\Closure`                    | `instanceof` |
| `Qualimetrix\Metrics\VisitorMethodContext`                                         | `PhpParser\Node\Expr\ArrowFunction`              | `instanceof` |
| `Qualimetrix\Metrics\CodeSmell\RepeatedExpression\IdenticalSubExpressionCollector` | `Qualimetrix\Metrics\AbstractCollector`          | `extends`    |
| `Qualimetrix\Metrics\CodeSmell\RepeatedExpression\IdenticalSubExpressionVisitor`   | `Qualimetrix\Metrics\ResettableVisitorInterface` | `implements` |
| `Qualimetrix\Metrics\CodeSmell\RepeatedExpression\IdenticalSubExpressionVisitor`   | `Qualimetrix\Metrics\VisitorMethodTrackingTrait` | `trait_use`  |
| `Qualimetrix\Metrics\Security\Credential\HardcodedCredentialsCollector`            | `Qualimetrix\Metrics\AbstractCollector`          | `extends`    |
| `Qualimetrix\Metrics\Security\Credential\HardcodedCredentialsVisitor`              | `Qualimetrix\Metrics\ResettableVisitorInterface` | `implements` |
| `Qualimetrix\Metrics\Security\Credential\HardcodedCredentialsVisitor`              | `Qualimetrix\Metrics\VisitorMethodTrackingTrait` | `trait_use`  |

The closed current 16-neighbor union is exactly
`PhpParser`, `PhpParser\Node`, `PhpParser\Node\Expr`,
`PhpParser\Node\Stmt`, `Qualimetrix\Core\Metric`,
`Qualimetrix\Core\Path`, `Qualimetrix\Core\Symbol`,
`Qualimetrix\Metrics\CodeSmell`,
`Qualimetrix\Metrics\CodeSmell\RepeatedExpression`,
`Qualimetrix\Metrics\Complexity`, `Qualimetrix\Metrics\Design`,
`Qualimetrix\Metrics\Halstead`, `Qualimetrix\Metrics\Security`,
`Qualimetrix\Metrics\Security\Credential`,
`Qualimetrix\Metrics\Size`, and `Qualimetrix\Metrics\Structure`.
The two parser additions belong to the root callable-dispatch implementation;
the two Metrics additions are subject splits whose collectors and visitors
consume the root cross-category traversal contract. The closed Metrics root
foundation remains `AbstractCollector`, `ResettableVisitorInterface`,
`VisitorMethodTrackingTrait`, `VisitorFileEntryScope`,
`VisitorCallableMetadata`, `VisitorCallableScope`, and
`VisitorMethodContext`. Moving traversal/projection ownership into either
subject split would duplicate the cross-category contract; moving it into Core
would make Core own Metrics traversal lifecycle. The root foundation is
therefore cohesive, and no move, fake edge, dependency collapse, helper bucket,
threshold, exclusion, control, suppression, or formula change is allowed.

The structural-candidate DoD runs these exact sixteen tests:

```text
tests/Unit/Metrics/VisitorMethodContextTest.php
tests/Unit/Metrics/CodeSmell/CodeSmellVisitorTest.php
tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionCollectorTest.php
tests/Unit/Metrics/CodeSmell/RepeatedExpression/IdenticalSubExpressionVisitorTest.php
tests/Unit/Metrics/CodeSmell/ParameterCountCollectorTest.php
tests/Unit/Metrics/CodeSmell/UnreachableCodeCollectorTest.php
tests/Unit/Metrics/Complexity/CognitiveComplexityVisitorTest.php
tests/Unit/Metrics/Complexity/CyclomaticComplexityVisitorTest.php
tests/Unit/Metrics/Complexity/NpathComplexityCollectorTest.php
tests/Unit/Metrics/Halstead/HalsteadCollectorTest.php
tests/Unit/Metrics/Security/Credential/HardcodedCredentialsVisitorTest.php
tests/Unit/Metrics/Security/Credential/HardcodedCredentialsCollectorTest.php
tests/Unit/Metrics/Security/SecurityPatternVisitorTest.php
tests/Unit/Metrics/Security/SensitiveParameterVisitorTest.php
tests/Unit/Metrics/Size/MethodStatementCountCollectorTest.php
tests/Integration/Architecture/DogfoodingTopologyTest.php
```

It repeats the exact
normal/show-suppressed scans and the namespace dependency projection; proves
the closed 16-neighbor set, exact four-neighbor delta, and ten supporting
dependency tuples; CBO 16; all three existing structural controls only; and no
second post-ledger fresh group. The proposed baseline entry is exactly
subject `ns:Qualimetrix\\Metrics`, channel
`coupling.cbo#coupling.cbo.namespace`, `magnitudes: [16]`, `count: 1`, with no
occurrence, edge, or mode. The literal comes from the closed 12 + 4 namespace
neighbor set, not from generic current-magnitude copying.

The former scratch copy of the `ba1119a0` dependency attribution is not an
available artifact and supplies no proof. Before R1, regenerate immutable
`ba1119a0bd87afa8f17f3ddbbfd43683c5db5ec5` and
`e04e88769640e5651b6a44ac10419a76c726f7a1` (`HEAD`) snapshots under fresh
`/private/tmp/qmx-p6-rg1-evidence/`: archive both revisions without checkout,
use the current repository runner and read-only `vendor` symlinks, and run
these literal preparation and scan commands from the repository root:

```text
mkdir -p /private/tmp/qmx-p6-rg1-evidence/checkpoint-ba1119a0
mkdir -p /private/tmp/qmx-p6-rg1-evidence/checkpoint-head-e04e8876
git archive ba1119a0bd87afa8f17f3ddbbfd43683c5db5ec5 | tar -x -C /private/tmp/qmx-p6-rg1-evidence/checkpoint-ba1119a0
git archive e04e88769640e5651b6a44ac10419a76c726f7a1 | tar -x -C /private/tmp/qmx-p6-rg1-evidence/checkpoint-head-e04e8876
ln -s <current-repository-root>/vendor /private/tmp/qmx-p6-rg1-evidence/checkpoint-ba1119a0/vendor
ln -s <current-repository-root>/vendor /private/tmp/qmx-p6-rg1-evidence/checkpoint-head-e04e8876/vendor
php bin/qmx check /private/tmp/qmx-p6-rg1-evidence/checkpoint-ba1119a0/src --config=/private/tmp/qmx-p6-rg1-evidence/checkpoint-ba1119a0/qmx.yaml --format=json --workers=0 --no-cache
php bin/qmx check /private/tmp/qmx-p6-rg1-evidence/checkpoint-ba1119a0/src --config=/private/tmp/qmx-p6-rg1-evidence/checkpoint-ba1119a0/qmx.yaml --format=metrics --workers=0 --no-cache
php bin/qmx check /private/tmp/qmx-p6-rg1-evidence/checkpoint-head-e04e8876/src --config=/private/tmp/qmx-p6-rg1-evidence/checkpoint-head-e04e8876/qmx.yaml --format=json --workers=0 --no-cache
php bin/qmx check /private/tmp/qmx-p6-rg1-evidence/checkpoint-head-e04e8876/src --config=/private/tmp/qmx-p6-rg1-evidence/checkpoint-head-e04e8876/qmx.yaml --format=metrics --workers=0 --no-cache
php bin/qmx check src/ --config=qmx.yaml --format=json --workers=0 --no-cache
php bin/qmx check src/ --config=qmx.yaml --format=metrics --workers=0 --no-cache
```

`<current-repository-root>` is resolved once with `git rev-parse
--show-toplevel`, recorded in scratch metadata, and never written to a tracked
file. A scratch `build-evidence.php` must apply the literal bidirectional
namespace projection used by `CouplingCollector::buildCoupledNamespaceSets` to
all three sources: iterate the collected dependencies; map a null source or
target namespace to the literal empty string `''`; skip only when the resulting
source and target namespaces are equal; otherwise add the target namespace to
the source namespace's set and the source namespace to the target namespace's
set. There is no global/empty-neighbor exclusion. For
`Qualimetrix\Metrics`, the report records and asserts whether `''` is absent in
each of the three snapshots; expected absence in all three is part of the
10/12/16 proof, while any present `''` remains in the set, counts toward CBO,
and fails that expected comparison instead of being discarded. The builder
sorts every dependency tuple and full neighbor set, emits the ba/HEAD/worktree
sets and exact four-neighbor delta, and asserts that the full Metrics-root
counts 10/12/16 equal the corresponding three metrics-output values.
The new manifest pins both archive revisions, worktree source identity, runner
revision, cwd, all six literal commands, options, exits, stdout/stderr hashes,
all three qmx hashes, all three sorted PHP content-list hashes, all three
coverage results, builder hash, three projection hashes, comparison-report
hash, and every artifact SHA-256; a sidecar pins the manifest hash, and a fresh
validation recomputes every content/artifact hash and the three-way manifest
comparison before review. Any mismatch or unavailable source stops R1 rather
than reviving the missing artifact claim.

The remaining evidence provenance is pinned to these existing artifacts; the
post-correction run adds the fresh RG1 manifest and hashes without overwriting
them:

| Evidence                           | SHA-256                                                                                                                                 |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| final R11 evidence manifest        | `22ce27193d7fae51555afca8335cb2a8b9b7fcb99a9499a0ca7c4816b41e1942`                                                                      |
| P6-RG manifest / report            | `2b2f14329006f7f4b422a18dd6bc5f5d4498500c59f5bffca48a93a588eba2d4` / `581ae9be81a1b187b6624ffb491058fe04425460796ca8433520b93d0cf44980` |
| P5-F STOP manifest / measured JSON | `e1014a97745613daba2d7ec84308da47d9972ad0d6ad74fa6eb5464e3655dc81` / `ba9604dbe5bde1a2dd6678293ea2bddd5666830cedbd81626a86983ee8b0e2ef` |
| P5-F mapping report / crosswalk    | `3bcee7fd20a59472004249d551a2b0b5aed53a0c574baa104fa61537810f9d99` / `55e8bcc865f92200a045776ab9da93067102b257feb887a4e6140aca92b24abe` |
| unapplied blocked candidate        | `0911520383a45083a1f7385b4fa4c28882ec85cd076a27d922a63011fc1073e7`                                                                      |

The original 63 frozen fresh rows remain remediated. The 34-row worsening
ledger remains 32 remediated, zero unresolved, and two R6 recalibrations.
P6-RG1 may add exactly one independently approved post-ledger structural fresh
residual; it cannot be counted as an R6 recalibration or as remediation of a
frozen row. A second post-ledger fresh identity blocks P5-F.

#### Reviewed non-duplication missing-row ledger

The ten old non-duplication rows absent from the candidate are finite. Each
disposition remains evidence-required at P5-F; the table is not permission to
drop a row on name similarity alone.

| Old identity/channel/ceiling                                                                        | Proposed disposition                                                                                                 | Required evidence before omission or mapping                                                                                                                                                                     |
| --------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `FileProcessor::extractMethodMetrics`, cognitive, 15                                                | renamed successor `FileProcessor::extractCallableMetrics`; map the old ceiling to the surviving declaration identity | source/history proves the rename and retained responsibility; focused `FileProcessorTest`; same-run crosswalk contains the successor and no old declaration                                                      |
| `DerivedMetricExtractor::extract`, cognitive, 20                                                    | improved below emission threshold; retire from ratchet                                                               | declaration still exists; same-run unbaselined result contains no identity on this channel; focused extractor tests prove behaviour; current measured value is recorded only as evidence, never as a new ceiling |
| `DerivedMetricExtractor::extract`, cyclomatic, 13                                                   | improved below emission threshold; retire from ratchet                                                               | same evidence for the cyclomatic channel and direct boundary comparison                                                                                                                                          |
| `DerivedMetricExtractor::extract`, NPATH, 600                                                       | improved below emission threshold; retire from ratchet                                                               | same evidence for the NPATH channel and direct boundary comparison                                                                                                                                               |
| `SuppressionFilter::shouldInclude`, cognitive, 15                                                   | improved below emission threshold; retire from ratchet                                                               | surviving declaration, focused suppression-filter test, same-run absence on cognitive channel, and measured value below the old upper boundary                                                                   |
| `BaselineMigrateCommand::reportMigration`, cyclomatic, 11                                           | removed source; retire from ratchet                                                                                  | tracked deletion of `BaselineMigrateCommand.php`, command-surface tests proving it is absent, and no renamed implementation                                                                                      |
| `BaselineMigrateCommand::reportMigration`, NPATH, 400                                               | removed source; retire from ratchet                                                                                  | the same deletion/surface evidence independently applied to the NPATH row                                                                                                                                        |
| `NpathExpressionCalculator::calculate`, cyclomatic, 13                                              | improved below emission threshold; retire from ratchet                                                               | surviving declaration, focused expression-calculator tests, same-run channel absence, and measured value below 13                                                                                                |
| namespace `Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator`, `health.coupling`, 49.7 | improved above the lower-bound floor; retire from ratchet                                                            | complete same-run namespace health projection and direction-aware value >= 49.7; configuration/DI tests exclude a missing-coverage explanation                                                                   |
| namespace `Qualimetrix\\Reporting\\Profile`, `health.maintainability`, 39.0                         | improved above the lower-bound floor; retire from ratchet                                                            | surviving namespace/source inventory, complete same-run namespace health projection and value >= 39.0; profile renderer tests exclude missing coverage                                                           |

An explained row may be omitted only after the named evidence is attached to
the mapping report and independently reviewed. P5-F blocks unexplained missing
rows; a reviewed removed-source or improved-below/above-boundary row is an
explicit retired ratchet entry, not an unexplained missing row.

#### P5-F v2 — exact mapped class-count decision gate

**Status:** STOP; this is a decision candidate, not authorization. P5-F remains
blocked until R1 explicitly approves or rejects this one disposition and an
independent plan review accepts it. No source move, deletion, threshold,
exclusion, inline control, suppression, or baseline edit is authorized here.

The exact mapped identity is `ns:Qualimetrix\\Rules\\CodeSmell` /
`size.class-count#size.class-count`: old v10 ceiling 23, complete current
measurement 24. The selector matched and reported accepted magnitude 23, so
this is a mapped-ceiling worsening, not an identity mismatch or no-ancestor
row. The canonical inventory format is one exact class FQN plus LF per line,
byte-sorted. Immutable `e04e88769640e5651b6a44ac10419a76c726f7a1`
contains these 23 classes:

```text
Qualimetrix\Rules\CodeSmell\AbstractCodeSmellRule
Qualimetrix\Rules\CodeSmell\BooleanArgumentOptions
Qualimetrix\Rules\CodeSmell\BooleanArgumentRule
Qualimetrix\Rules\CodeSmell\CodeSmellOptions
Qualimetrix\Rules\CodeSmell\ConstructorOverinjectionOptions
Qualimetrix\Rules\CodeSmell\ConstructorOverinjectionRule
Qualimetrix\Rules\CodeSmell\CountInLoopRule
Qualimetrix\Rules\CodeSmell\DebugCodeRule
Qualimetrix\Rules\CodeSmell\EmptyCatchRule
Qualimetrix\Rules\CodeSmell\ErrorSuppressionOptions
Qualimetrix\Rules\CodeSmell\ErrorSuppressionRule
Qualimetrix\Rules\CodeSmell\EvalRule
Qualimetrix\Rules\CodeSmell\ExitRule
Qualimetrix\Rules\CodeSmell\GotoRule
Qualimetrix\Rules\CodeSmell\IdenticalSubExpressionOptions
Qualimetrix\Rules\CodeSmell\IdenticalSubExpressionRule
Qualimetrix\Rules\CodeSmell\LongParameterListOptions
Qualimetrix\Rules\CodeSmell\LongParameterListRule
Qualimetrix\Rules\CodeSmell\SuperglobalsRule
Qualimetrix\Rules\CodeSmell\UnreachableCodeOptions
Qualimetrix\Rules\CodeSmell\UnreachableCodeRule
Qualimetrix\Rules\CodeSmell\UnusedPrivateOptions
Qualimetrix\Rules\CodeSmell\UnusedPrivateRule
```

Its inventory SHA-256 is
`3fe5f2b6bafe8d0b90b4c085b9aade51cf1fd6b5ed561f59c568e0e0920873fc`.
The current 24-class inventory is exactly that set plus
`Qualimetrix\\Rules\\CodeSmell\\CodeSmellFinding`, with nothing removed; its
SHA-256 is
`a9a6d621620e2f2a09b3f50f16aec1a30e5fb02090020e1b27cd519307309996`
and the added file SHA-256 is
`a310120a98d1c6eb0e45de796b00bba64a053ba1796ff881603f6034cc93fcd9`.
The HEAD/current PHP content-manifest hashes are respectively
`29eecc5a60dc58947b349db3880a2c525452a342fb63556b7335749d5856b372`
and `eb53bf032b4735926e080a22624b5eda7ae0d6f2c71dad227c7b30abfa405097`.

`CodeSmellFinding` is the R9 category-specific immutable entry-validation and
Violation-projection companion. It keeps the base rule on policy/filtering and
removes duplicated validation/projection without creating a generic factory or
role bucket. Moving or deleting that class would break the reviewed R9 subject
boundary rather than remediate class-count debt. If R1 approves, the only
permitted disposition is therefore a final ceiling 24 derived from the closed
23 + 1 inventory, not copied generically from the current measurement. Run
`CodeSmellFindingTest`, `BooleanArgumentRuleTest`, and the R9 CodeSmell rule
consumer tests read-only; regenerate both inventories and require the same
single addition. Any second added/removed class or different count stops P5-F.

Evidence provenance is exact: RG1 manifest
`4fd84d1e6167410cd36641682f0eeb5c399bfd1d4d41f34ce55c763d087aa96b`;
P5-F v2 STOP manifest/report
`d69b89de04c54fce2ff49923701882ed33716d556b2f1afa85fa9f121807ca61` /
`b023ba9753485fd0b1fa389f447268aef41d98a4c37ff3c70cc4f6bf8b6e5918`;
measured/crosswalk-report
`5d4f673dfb6017ea71005e66147e227fed8cfbc43bfd3216f6bcc4404143c9eb` /
`f66e338f336fa82ce10da7d8e2dbe78fe4f13f025ea6e316bd789d1fdeacb9ed`;
unapplied candidate/validation
`ac23a150cea8ff39da86a1e3eacdc9469f071d1f577c6a79e818d8ca69372cc6` /
`c8b9426fdd8e8b85711adb903a2d1cb0299f349ddf04564396666a09499b97cd`.

#### P5-F — explicit reviewed dogfooding baseline migration gate

**Depends on:** accepted P6-RG1 after P6-RG and accepted P5-F v2 decision gate.
**Owned tracked file:**
`qmx-baseline.json` only. No
production or test file belongs to this package. This is a reviewed migration,
not a loader conversion or debt-acceptance shortcut.

```text
old = exact v10 baseline bytes from the P5-A parent checkpoint
run = one measured analysis with pinned scope, config hash, options, exclusions,
      RunScope, source revision, and workers setting
crosswalk = deterministic generic-JSON identity projection from run
measured = v11-shaped serialization from the same MeasuredViolationSet
assert identity-set(measured) == identity-set(crosswalk)
map every old group and every measured group
review every split, renamed successor, removed source, improved retirement,
       ambiguous row, and fresh identity against the finite ledgers
final = empty v11 baseline
for each reviewed mapped surviving identity:
    copy the OLD accepted count/magnitude ceiling onto its mapped v11 identity
except exactly these two reviewed mapped structural residuals and no others:
    LayerViolationRule coupling.cbo.class -> ceiling 24
    namespace Qualimetrix\Architecture\Rules coupling.instability.namespace
      -> ceiling 26 / (26 + 2) = 0.928571
    require the exact R6 dependency/Ca/Ce proof before either replacement
except additionally exactly this one independently approved post-ledger mapped
structural recalibration and no other:
    namespace Qualimetrix\Rules\CodeSmell size.class-count -> ceiling 24
    require the exact closed 23 + CodeSmellFinding inventory and R9 subject proof
for each reviewed absent or retired old row:
    omit it from final and retain its evidence-backed disposition in the report
never copy measured/current magnitudes into final; the two R6 literals and the
    one post-ledger mapped literal above are finite reviewed structural ceilings,
    not generic current-value input
never add a fresh identity without an old ancestor, except the exact
    independently reviewed P6-RG1 Metrics namespace structural row at 16
never add any of the exact three reviewed/suppressed post-R11 identities:
  class Qualimetrix\Metrics\Security\Credential\CredentialLiterals,
    channel computed.health#health.cohesion,
    reason: Stateless credential-literal shapes share one classification policy and location boundary.
  class Qualimetrix\Metrics\Security\Credential\HardcodedCredentialsVisitor,
    channel design.data-class,
    reason: Traversal adapter intentionally delegates credential policy and retains only lifecycle state.
  class Qualimetrix\Rules\CodeSmell\LongParameterListRule,
    channel computed.health#health.cohesion,
    reason: Interface metadata methods getCategory() and requires() return external enum/metric constants beside one cohesive analysis/projection component; LCOM4 cannot merge those stateless protocol methods.
  verify all three byte-exact inline-control reasons and omit all three from final
reject unexplained or ambiguous rows and any fresh-debt acceptance
reject a third R6 recalibration, a second post-ledger mapped structural
    recalibration, a second post-ledger fresh residual, and generic baseline
    regeneration
// ... independent reviewer approves mapping and tracked JSON diff
```

Mapping uses the explicit logical `symbol`, never parsing canonical `subject`.
One-to-one and renamed-successor ceilings come from the old baseline except
for the exact two R6 mapped structural residuals and the one separately
approved post-ledger mapped structural recalibration above; split
groups are accepted only when their reviewed mapping is complete and the old
collapsed ceiling can be projected without accepting current magnitudes. P6-R
must have removed all 63 frozen non-dup fresh groups (including the already
completed P6-R0 row), preserved the mapped renamed successor at its
old ceiling 15, remediated 32 worsening drifts, reviewed exactly two structural
worsening residuals, resolved nine worsened duplication
successors, and ten no-ancestor blocks. The six improved drifts and two improved
duplication successors keep their tighter old ceilings. Unexplained missing and
all ambiguous rows block replacement; evidence-backed retired rows from the
finite ledger may be omitted. Regeneration is only a measured-identity input,
never the final baseline constructor. The exact three post-R11 controls named
above are source-controlled structural exceptions and must all be absent from
both the measured candidate identity set and final baseline; their exact
three-row `--show-suppressed` evidence is attached separately. Any one of the
three missing, duplicated, differently reasoned, or present in the normal
candidate blocks P5-F.

The R6 baseline recalibration set is finite and closed:

| Mapped surviving identity/channel                                                                | Old ceiling | Reviewed final ceiling | Required proof                                                                                                                                                                                                                                                                                                                                                |
| ------------------------------------------------------------------------------------------------ | ----------: | ---------------------: | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `class Qualimetrix\\Architecture\\Rules\\LayerViolationRule` / `coupling.cbo#coupling.cbo.class` | 23          | 24                     | R6 scan proves Ca=1, Ce=23, CBO=24 and the old class dependency set differs only by removal of the extracted `MatchedCriterion`/`MatchedCriterionKind` edges and addition of the required P5 `MetricSubject` identity plus `OwnedLayerTargets` and `LayerViolationFinding`; focused R6 tests prove the helper edges carry the exact approved responsibilities |
| `ns:Qualimetrix\\Architecture\\Rules` / `coupling.instability#coupling.instability.namespace`    | 0.923077    | 0.928571               | R6 scan proves Ca=2, old Ce=24, final Ce=26, and the namespace dependency union is the old union plus exactly `MetricSubject` and `OccurrenceKey`, required by P5 aggregate/declaration identity and semantic occurrence contracts                                                                                                                            |

The post-ledger mapped structural recalibration set is separately finite and
closed:

| Mapped surviving identity/channel                                        | Old ceiling | Reviewed final ceiling | Required proof                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------ | ----------: | ---------------------: | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ns:Qualimetrix\\Rules\\CodeSmell` / `size.class-count#size.class-count` | 23          | 24                     | independently approved P5-F v2 gate proves the immutable HEAD/current inventories are 23/24 with exactly `CodeSmellFinding` added, no removal or second delta, and preserves the reviewed R9 category-specific companion boundary |

The candidate baseline projection and final baseline may change old mapped
ceilings only for the two R6 rows and the one separately approved post-ledger
mapped row. All other mapped surviving rows retain old
ceilings; fresh,
retired, improved, duplicated, controlled, and missing-row dispositions remain
governed by their existing ledgers. No threshold, exclusion, formula, inline
control, suppression, or pre-P5-F baseline edit may substitute for either
review. P5-F constructs the two R6 entries and the separately approved
CodeSmell namespace class-count row at 24 explicitly; after P6-RG1 approval it
also constructs the one separate Metrics namespace structural row at 16.
It never runs generic baseline regeneration as the final constructor.

**DoD:** measured/crosswalk identity sets match under
`channel + subject + optional occurrence + optional edge`; every old and new
row is accounted; final v11 ceilings are old ceilings projected onto mapped
surviving identities except the exact two R6 topology-derived recalibrations
and the exact one separately approved post-ledger mapped structural
recalibration;
reviewed absent or retired rows alone are omitted; the 372-old-group disposition
remains unchanged. The corrected pinned run establishes measured/crosswalk
321/321 after removal of the exact CollectionOrchestrator cohesion identity;
the mapped class-count ceiling change does not alter that identity count. P5-F
stays blocked until the R1 disposition and independent review succeed; the
final file then has 322 entry groups after the renamed successor and sole
Metrics structural row are included. There is
zero generic copied current magnitude, exactly two R6 recalibrations, exactly
one independently approved post-ledger mapped structural recalibration from 23
to 24 with no change to the 321/321 mapped identity count, exactly one
independently approved post-ledger structural fresh residual at 16, zero other
recalibration or fresh acceptance, zero unexplained row, and candidate
validation after the exact ceiling-24 disposition reports zero violations and
exits zero; there is zero
`Qualimetrix\Metrics\Security\Credential\CredentialLiterals` cohesion baseline
entry, and zero `Qualimetrix\Metrics\Security\Credential\HardcodedCredentialsVisitor`
DataClass baseline entry, and zero
`Qualimetrix\Rules\CodeSmell\LongParameterListRule` cohesion baseline entry;
an independent reviewer approves the report and JSON diff;
`composer selfcheck` and then `composer check` exit zero. Scratch artifacts are
not tracked.

### P7 — Documentation, migration, and status

**Depends on:** P5-F.

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
src/Metrics/CodeSmell/README.md
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
website/docs/usage/cli-options.md
website/docs/usage/cli-options.ru.md
website/docs/usage/output-formats.md
website/docs/usage/output-formats.ru.md
```

**Work:** document callable ownership, hook controls, identity schema,
consumer migration, and metric deviations using F-014's durable forms; update
Round 1 status only after each verified fix is actually complete. Status
accounting remains `31 package/cross-package remediation rows + 1 unresolved
global gate + 2 reviewed structural rows` until P6-RG proves
`health.coupling >=48.0`. Only then may it say 32 worsening rows were remediated
and exactly the two R6 topology rows remain accepted reviewed structural
residual debt through the finite P5-F recalibrations. If P6-RG1 and P5-F are
accepted, P7 also records exactly one separate post-ledger Metrics namespace
structural fresh residual at ceiling 16; that row is neither one of the 34
worsening rows nor an R6 recalibration. It also records the separately approved
post-ledger mapped `Qualimetrix\\Rules\\CodeSmell` class-count recalibration
from 23 to 24: that row remains mapped, is not an R6 recalibration, and does not
change the 321/321 identity count. P7 must not claim all 34 were fixed or that
no debt was newly accepted.

P7 adds a `CHANGELOG.md` `Breaking` entry naming the exact constructor
migration: old `CollectionOrchestrator` calls could omit `ProgressReporter` and
`LoggerInterface` and receive null defaults; new calls must pass explicit
implementations. Consumer steps state that shipped DI already supplies
`DelegatingProgressReporter` and `DelegatingLogger`, while every direct
constructor consumer must pass its chosen explicit implementations. No shim,
default, nullable parameter, or overload is documented or introduced. The
R7-owned `src/Analysis/README.md` component migration note states the same
old-to-new contract. The verified literal documentation inventory contains no
constructor reference under `website/`, so no R7-specific website edit is
required; if that inventory changes before P7, the package stops to add the
exact EN/RU owners rather than leaving stale public instructions.

The P7 pre-edit literal census also found exactly eight stale
`baseline:migrate` reference lines across four paired pages: command and prose
at `website/docs/usage/baseline.md:58,61`,
`website/docs/usage/baseline.ru.md:58,61`,
`website/docs/usage/cli-options.md:321,330`, and
`website/docs/usage/cli-options.ru.md:318,327`. The four prose lines also claim
the obsolete v5-to-v10 fresh-capture contract. This is the complete current
reference-page set: `website/CONTRIBUTING_DOCS.md` assigns CLI command changes
to `usage/cli-options.md` and baseline behavior changes to
`usage/baseline.md`, while its bilingual policy requires each `.md` owner and
its `.ru.md` peer to change together. The configuration guide's EN/RU CLI
reference links remain links to this existing paired CLI owner; no new page or
navigation entry is required.

P7 deletes the `baseline:migrate` command line and bullet from both CLI option
pages, changes “all five commands” to the exact four surviving lifecycle
commands, and removes “migration” from the surface description. In both
baseline pages it replaces the “Migrate version 5” heading, command, and prose
with an exact “Replace an older baseline” contract: only version 11 is
loadable; neither v5 nor v10 can infer exact declaration subjects, semantic
occurrences, or dependency edges; the consumer runs a fresh analysis, maps or
splits every previously accepted group deliberately, reviews the result, and
writes a new v11 file. `baseline:generate --force` may replace bytes only after
that review; it is not an automatic converter and does not infer old identity.
All four pages state that the removed command has no alias or compatibility
shim. They must not route a v10 consumer through the historical v5 reader or
describe a generic current-value baseline regeneration as a reviewed mapping.

The
`src/Analysis/README.md` edit is sequential after P6-R2, P6-R3, P6-R5, and
P6-R7: P7 must preserve all four packages' exact structure entries and
responsibility contracts, including `DerivedCollectorRunner`'s topological
execution and R7's verified six-component phase/projection responsibilities,
inputs, outputs, dependency directions, invariants, and focused-test contracts.
P7 may change them only if the verified final implementation requires an
explicitly reviewed correction. P6-R8 through P6-R11 are not intermediate
editors of `src/Analysis/README.md`; if implementation changes that ownership,
the package stops for an overlap/plan revision before editing. The
`src/Metrics/README.md` edit is sequential after
P6-R4: P7 likewise preserves the exact `VisitorFileEntryScope.php`,
`VisitorCallableMetadata.php`, and `VisitorMethodContext.php` entries, their
live-scope/immutable-metadata/facade responsibility split, complete consumer
set, dependency contract, and focused-test mapping. It also preserves all six
CodeSmell/Security companion entries plus every moved collector, visitor, and
VO in the complete `RepeatedExpression` and `Credential` stacks, their
semantic-policy/existing-VO boundaries and exact subject-subnamespace paths,
the sole
`RepeatedConditions -> RepeatedExpressions` dependency,
the exact ControlFlow-vs-eval/error-suppression/superglobal residual boundary,
and direct companion/visitor-test mapping. P6-R10 is explicitly not an
intermediate editor under the no-formula/no-contract/no-inventory-change premise
recorded in R4. P7 also preserves `src/Metrics/CodeSmell/README.md`'s full
RepeatedExpression subject-stack inventory, moved paths/namespaces, exact
dependency topology, and direct tests. The `src/Architecture/README.md` edit is
sequential after P6-R6: P7 preserves the exact `OwnedLayerTargets.php` and
`LayerViolationFinding.php` structure entries, responsibilities, inputs,
outputs, dependency/test contracts, retained rule-policy boundary, and all
pre-existing Architecture contracts, including ADR 0021's LayerViolation 0..N
target projection and independent source/target control matrix.
The `src/Baseline/README.md` edit is sequential after P6-R8: P7 preserves all
six exact component structure entries, parsing/value-decoding/capture-result/
generation/explanation/suppression
responsibilities, typed inputs/outputs, dependency boundaries, invariants and
seven-file focused-test mapping, including the R1-approved
`BaselineEntryValues` value-schema boundary and `BaselineCapture` rejected-
group materialization factory. It also preserves the corrected v11 typed
subject + occurrence + edge identity and selector contract, explicit manual
v10 rejection guidance, and separation from the historical v5-to-v10
migrator. P6-R9 through P6-R11 are not intermediate editors; discovery to the
contrary stops the later package before a documentation edit.
The `src/Rules/README.md` edit is sequential after P6-R9: P7 preserves the
R1-approved `CodeSmellFinding.php` and `SecurityPatternFinding.php` structure
entries, their subject-owned entry-to-declaration/occurrence projection
contracts, dependency direction and direct-test mapping, plus the documented
private analysis boundaries of all twelve existing R9 rule owners. P6-R10 and
P6-R11 are not intermediate editors under their approved control-only and
private-deduplication scopes; if either changes a documented responsibility,
it stops for an explicit `P6-R9 -> later package -> P7` overlap revision.

**DoD:** every breaking surface has a consumer migration step and CHANGELOG
entry; specifically, the `Breaking` section and Analysis component note both
name the old optional-default and new mandatory-collaborator constructor forms,
the explicit DI/direct-consumer migration, and the absence of a compatibility
shim. A repeated literal search still finds no website constructor reference,
or exact EN/RU pages are added if one appears. Each changed metric's component
README and EN/RU website pages agree; there is no claim that a deferred or
unimplemented finding is fixed. A repeated literal census of the exact four
baseline/CLI owner pages finds zero `baseline:migrate`, zero fresh-v10
migration guidance, exactly four surviving lifecycle commands in each CLI
page, and matching EN/RU v11 fresh-analysis/manual-map-or-split guidance. The
website link census and `website/CONTRIBUTING_DOCS.md` ownership mapping remain
unchanged.

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

### P8-R1 — Final-review correction gate

**Status:** implemented, validated, and independently accepted. The first P8
review confirmed three findings; this correction closed them without reopening
any accepted P6/P5-F ledger disposition.

**Finite file fence (16 unique files):**

- production editors: `src/Core/Violation/Violation.php`,
  `src/Reporting/Formatter/Json/JsonViolationSection.php`;
- direct test editors: `tests/Unit/Core/Violation/ViolationTest.php`,
  `tests/Unit/Baseline/BaselineIdentityTest.php`,
  `tests/Unit/Reporting/Formatter/Json/JsonViolationSectionTest.php`,
  `tests/Unit/Reporting/Formatter/GitLabCodeQualityFormatterTest.php`,
  `tests/Unit/Reporting/Formatter/SarifFormatterTest.php`,
  `tests/Functional/Reporting/JsonShapePreservationTest.php`;
- documentation editors: `AGENTS.md`, `CHANGELOG.md`, `src/Core/README.md`,
  `src/Rules/README.md`, `src/Reporting/README.md`,
  `website/docs/usage/output-formats.md`,
  `website/docs/usage/output-formats.ru.md`;
- status/contract editor: this plan.

No new file, value type, public method, baseline schema, configuration key, or
control is authorized. `qmx.yaml`, `qmx-baseline.json`, every producer and
every formatter other than `JsonViolationSection` are read-only. Discovery of
another direct edge projection/fingerprint consumer or a need for another
editor stops implementation and returns to R1 rather than widening the fence.

The production census is finite and mechanical:

| Production owner / consumer                      | Live relationship                                                                                                                     | P8-R1 disposition                                                                                                                 |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `Architecture/Rules/LayerViolationFinding`       | The only production assignment of `Violation::$dependencyTarget`; it also always supplies the dependency's non-null `DependencyType`. | Read-only behavior oracle: all built-in emitted edges remain fully typed and byte-for-byte stable.                                |
| `Baseline/BaselineIdentity::forViolation()`      | Target presence defines an edge; `BaselineEdge` already stores the type optionally and its JSON shape omits `type` when null.         | Read-only correctness oracle; add only the direct target-only regression in its existing test. No v11 migration or baseline edit. |
| `Core/Violation/Violation::getFingerprint()`     | Direct source for GitLab and SARIF fingerprints; currently appends an edge only when target and type are both non-null.               | Production editor; retain the public signature and repair only the target-only branch.                                            |
| `Reporting/Formatter/Json/JsonViolationSection`  | Sole JSON `edge` projector and stable violation sorter; `JsonFormatter` only delegates to this component.                             | Production editor for optional-type projection and total ordering.                                                                |
| `Reporting/Formatter/GitLabCodeQualityFormatter` | Computes `md5(Violation::getFingerprint())`.                                                                                          | Read-only consumer; its direct test proves the repaired public output.                                                            |
| `Reporting/Formatter/Sarif/SarifFormatter`       | Writes `Violation::getFingerprint()` to `partialFingerprints.primaryLocationLineHash`.                                                | Read-only consumer; its direct test proves the repaired public output.                                                            |

`Violation` continues to admit the four constructor shapes because changing its
sixteen-field public constructor is outside this correction. Target presence is
the authoritative edge boundary: `(null, null)` is no edge;
`(target, null)` is an untyped edge; `(target, type)` is a typed edge; and the
legacy nonsensical `(null, type)` remains no edge in projections/fingerprints.
The last shape is preserved, not endorsed, and cannot erase a target because it
has none.

| Production editor      | Exact contract and pseudocode                                                                                                                                                                                                                                                                                                                                                                                                                                                                | Compatibility invariant                                                                                                                                                                                                                                                                                                                                     | Direct test owner                                                                                                                                                                                                                                                                                                                                                 |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Violation`            | Keep `public getFingerprint(): string`. Build the existing `channel:subject[:occurrence]` prefix. If target is null, append nothing. If target and type are present, append the existing exact `type:canonical-target` bytes. If target is present and type is null, append `untyped-edge:<byte-length>:<canonical-target>` as one edge component; byte length is `strlen()` of the canonical target. `untyped-edge` is asserted disjoint from all fourteen current `DependencyType` values. | No-edge, type-only-without-target, and fully typed fingerprint bytes do not change. Only a formerly erased target-only edge gains identity. Two untyped targets and typed/untyped forms of one target cannot collide under one channel/subject/occurrence tuple. No helper or new identity type is introduced.                                              | `ViolationTest` pins all four shapes, exact old no-edge/typed strings, the new length-prefixed target-only string, two-target separation, typed/untyped separation, occurrence composition, and marker disjointness. `BaselineIdentityTest` pins that the already-correct baseline identity retains both target-only edges and distinguishes typed/untyped forms. |
| `JsonViolationSection` | Change only private `formatEdge(Violation): ?array{target: string, type?: string}` and `identitySortKey(Violation): array{string, string, string, int, string, string}`. `formatEdge` returns null iff target is null, `{target}` for target-only, and the existing ordered `{type, target}` object for typed edges. The sort tuple is exactly channel, subject, occurrence-or-empty, edge-presence `0/1`, type-or-empty, target-or-empty.                                                   | Existing no-edge JSON remains null; existing fully typed object keys/values/order remain byte-stable. Ordering is total: no edge before edges, untyped before typed, two untyped edges by target, and fully typed edges retain type-then-target relative ordering. Metric values, messages, source lines, and display order inputs remain outside identity. | `JsonViolationSectionTest` covers the exact projection and the literal deliberately shuffled six-row sort matrix below, including opposing type/target order and same-type target order. `JsonShapePreservationTest` covers the public JSON writer path.                                                                                                          |

The exact regression matrix for each identity/output seam is: no edge; two
untyped targets `class:App\Alpha` and `class:App\Beta`; one untyped and one
`DependencyType::New_` (`new`) typed edge to `class:App\Alpha`; a second `new`
edge to `class:App\Zulu`; one `DependencyType::TypeHint` (`type_hint`) edge to
`class:App\Alpha`; and the read-only built-in typed edge. The JSON sorting test
passes the six matrix rows in the deliberately shuffled order
`type_hint/Alpha`, `untyped/Beta`, `new/Zulu`, `no-edge`, `new/Alpha`,
`untyped/Alpha` and asserts the exact legacy order `no-edge`, `untyped/Alpha`,
`untyped/Beta`, `new/Alpha`, `new/Zulu`, `type_hint/Alpha`. Thus `new/Zulu`
precedes `type_hint/Alpha` despite the opposing
target order because type remains the primary typed-edge key, while
`new/Alpha` precedes `new/Zulu` because target remains the secondary key for
equal types. The direct JSON tests also assert null, `{target}`, and
`{type,target}` shapes. GitLab asserts distinct MD5 fingerprints for the two target-only
edges and for typed versus untyped forms while retaining known no-edge and
typed hashes. SARIF asserts the analogous raw `primaryLocationLineHash`
strings. `BaselineIdentityTest` proves the v11 target-only identity was already
correct; it is an oracle, not an authorization to edit Baseline production.

The outward change is narrow but breaking for consumers that manually create a
`Violation` with a target and no type: JSON changes from `edge: null` to
`edge: {target: ...}`, its GitLab fingerprint changes from the no-edge MD5, and
its SARIF primary-location fingerprint gains the tagged target-only suffix.
`CHANGELOG.md` must name that old and new surface and state that no-edge and
fully typed fingerprints are unchanged. `src/Reporting/README.md` and both
output-format website languages must document the optional `edge.type`, the
target-only shape, and the fingerprint consequence with exact EN/RU parity.
No baseline migration is needed because v11 already stores target-only edges.

The documentation correction is otherwise exact and non-architectural:

- `AGENTS.md` replaces nonexistent `SymbolType::Callable` with
  `allCallables()`, asserts the returned `SymbolInfo::$subject`, and reads its
  metrics through `getSubject()`;
- `src/Core/README.md` replaces its stale logical
  `all(SymbolType::Method)` callable iteration with the same exact-declaration
  API and documents target-only versus typed fingerprint behavior;
- `src/Rules/README.md` replaces the stale authoring example's
  `all(SymbolType::Method)`, logical `get()`, and nonexistent
  `Violation::create()` with `allCallables()`, an exact non-null
  `MetricSubject`, `getSubject()`, effective options for that subject, and the
  existing `new Violation(...)` constructor. It remains an abbreviated
  authoring example, not a new rule contract;
- the execution ledger keeps P8 non-complete until this correction is
  implemented, validated, and independently reviewed; P5-F's stale Next cell
  is `None` and does not point at already-complete P7.

**Validation / DoD:** first obtain independent approval of this P8-R1 plan.
Then edit only the fence above and run the six focused test files above
(including the functional JSON test) as one no-coverage command, plus
`DocumentationConsistencyTest`. A literal audit finds zero
`SymbolType::Callable` in `AGENTS.md`, zero
callable-authoring uses of `all(SymbolType::Method)` in the three corrected
component/authoring documents, no `Violation::create()` in
`src/Rules/README.md`, and exact EN/RU edge-shape parity. Run strict MkDocs for
both languages, sequential no-cache CS, and full `composer check` (with the
same reviewed outside-sandbox socket rerun if necessary). Re-run workers-zero,
no-cache JSON selfscans: 693/693 source files, the unbaselined accepted set stays
321, the v11-baselined `--fail-on=warning` result stays zero, the three approved
controls remain exact, and no fresh/worsening identity appears. The exact
`qmx.yaml` SHA-256 remains
`44ad5c7f93bb9210a599126936123bc673659074a4a6bc40b4d076133ce6ad39`;
the exact baseline SHA-256 remains
`aa1713494ae6bdfda1f57ecbc58d0681711aa60bab935c5c690dd6a6a965e179`.
`git diff --check` passes, the index remains empty, final reproducible scratch
evidence supersedes the initial P8 package, and an independent final review has
no unresolved confirmed finding before the ledger may say P8 Complete.

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
