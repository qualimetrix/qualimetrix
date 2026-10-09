# Design

## Subject and boundary

`Analysis\Evidence\Design` owns evidence and policy for class design:
type declaration coverage, inheritance depth (DIT), number of direct children
(NOC), data-class detection, and god-class detection. Its one public surface is
`Inheritance/Contract/`, promised to the composer adapter in
`Infrastructure\Composer` so that a chain leaving the analysed path can be
followed by reading (ADR 0074). Nothing else here is published.

Collectors consume Measurement's existing collection and repository contracts.
`DitGlobalCollector` and `NocCollector` also consume DependencyModel's public
graph contracts. Rules consume Finding's execution, option, channel, and
finding contracts. No consumer imports a Design collector or rule as a
cross-capability contract.

## Structure

```text
Design/
├── DataClass/
│   ├── DataClassExclusionCheck.php
│   ├── DataClassOptions.php
│   └── DataClassRule.php
├── GodClass/
│   ├── GodClassCriteriaEvaluator.php
│   ├── GodClassCriterionResult.php
│   ├── GodClassOptions.php
│   └── GodClassRule.php
├── Inheritance/
│   ├── Contract/
│   │   ├── ExternalParentSourceInterface.php
│   │   └── ParentLookup.php
│   ├── DitGlobalCollector.php
│   ├── ExternalAncestry.php
│   ├── ExternalChainOutcome.php
│   ├── ExternalDepth.php
│   ├── InheritanceDepthResolver.php
│   ├── InheritanceOptions.php
│   ├── InheritanceOutcome.php
│   ├── InheritanceResolution.php
│   ├── InheritanceRule.php
│   ├── NocCollector.php
│   ├── NocOptions.php
│   └── NocRule.php
└── TypeCoverage/
    ├── AbstractTypeCoverageRule.php
    ├── ParamTypeCoverageRule.php
    ├── PropertyTypeCoverageRule.php
    ├── ReturnTypeCoverageRule.php
    ├── TypeCoverageCollector.php
    ├── TypeCoverageOptions.php
    ├── TypeCoveragePercentCollector.php
    └── TypeCoverageVisitor.php
```

Four subject folders, one per design property judged: declaration typing,
inheritance shape, data-class shape, god-class shape. Each holds its own
collectors, visitor, options and rules; there are no cross-family imports and
no shared type, which is why the root carries no production code at all. `Noc`
lives in `Inheritance/` because DIT and NOC measure the same inheritance tree
from opposite ends, and NOC contributes one collector with no visitor of its
own. Do not recreate `Metrics/`, `Rules/`, or a generic helper subdirectory
inside any of them, and do not put a type back in the root: a type that would
belong to no family is the signal that a fifth family is being named.

DIT and NOC start from DependencyModel's positive named-class declaration
facts, including abstract classes and degree-zero roots. Interfaces, traits and
enums receive neither metric. DIT resolves an answer for each exact declaration;
NOC counts distinct logical child names from the ordinary graph. A loop has no
numeric DIT but still belongs to NOC's class population, so numeric DIT and NOC
aggregate sample counts can differ.

`Inheritance/Contract/` is the one public surface here. Following a chain out
of the analysed path requires a file placed and read, which is delivery: the
port is promised to the composer adapter in `Infrastructure\Composer`, and
this capability imports neither a composer type nor a parser (ADR 0074).

`InheritanceResolution` represents a private complete answer with
`InheritanceOutcome`: nullable depth, `Exact`/`Floor`/`Loop`, and nullable knowledge of whether the
chain reaches PHP's `Throwable`. `ExternalDepth` and `ExternalChainOutcome`
carry external-tail evidence, including an explicit continuation to an already
analysed name. The same resolver resumes that continuation using every known
declaration; Composer never chooses one body from that roster. Depth completeness
and exception classification are independent; a finite exact depth can still have
unknown exception status when duplicate parent declarations disagree.

## Behaviour and lifecycle

- `TypeCoverageCollector` and `TypeCoverageVisitor` collect parameter, return,
  and property declaration counts and coverage percentages for named
  class-like declarations. `TypeCoveragePercentCollector` derives the combined
  percentage from those raw counts. All six raw typed/total counters retain
  measured 0. A combined typeable total of zero omits percentage fields; a
  positive total with no typed declarations publishes 0%. Aggregate typing
  health uses the actual summed parameter, return and property totals.
- `DitGlobalCollector` is the sole writer and definition owner of
  `design.dit`, `design.dit-unresolved`, and `design.is-exception`. It requires
  an exact Measurement subject for every named class graph fact; a missing
  subject is an invariant failure, not a silently omitted measurement.
- `InheritanceDepthResolver` indexes every named class declaration before its
  declaration-view `extends` edges. Root declarations remain candidates when
  a parent name has several declarations. Each child retains its own immediate
  parent; a logical parent merges all candidate answers. Finite depths merge
  by maximum, any floor makes the result a floor, and any loop removes numeric
  depth from the descendant. Completed answers are memoized by exact
  declaration, separately from canonical identities active in the walk. A chain
  returning from external sources to an analysed name uses the same roster,
  active path and memo; its external prefix contributes each parent link once.
- Registered PHP builtin ancestry is followed transitively through Core's
  static hierarchy before asking whether Composer source placement is
  configured. External project ancestry is read through
  `Contract\ExternalParentSourceInterface`, never loaded. Unregistered
  extension classes remain unknown unless readable source supplies evidence;
  loaded extensions on the analysing machine do not decide the result. The
  64-visit budget applies to each consecutive external segment. A known analysed
  target reached by the 64th link continues through graph evidence without a
  65th external read; a still-external target remains a floor.
- An unread, unplaced, unconfigured, or 64-step-capped tail yields a numeric
  floor. A canonical inheritance loop, including a named class's own
  self-`extends`, yields no `design.dit`. Every named class receives
  `design.dit-unresolved`: 0 for exact, 1 for floor or loop. Namespace and
  project DIT aggregates include only published numeric depths, including zero
  roots, through Measurement's existing exact-declaration aggregation.
- `design.is-exception` is 1 when all parent alternatives prove `Throwable`,
  0 when all prove otherwise, and absent when their answers disagree or remain
  unknown. Proven `Throwable` evidence survives a later unread tail.
  Interfaces, traits and enums receive 0. Size does not classify exceptions.
- `InheritanceRule` emits at most one warning per enabled `analyze()` call,
  distinguishing floors, loops, or both. Disabling the rule silences this
  warning while collection still publishes the metric evidence. A numeric
  floor can cross the unchanged thresholds; both its finding and recommendation
  say the DIT is at least the published value. A loop emits no numeric finding.
- `NocCollector` derives direct-child counts from the same DependencyModel
  graph and retains its collector name, definitions, ordering, and aggregation
  semantics. It counts distinct child **names**: a subclass declared in two
  files is one subclass, and the parent side is a name in any case, since
  `extends` does not say which file declared the parent.
- Both global collectors skip a `Dependency` flagged
  `describesNestedAnonymousClass`: an anonymous class's own `extends` edge is
  recorded with the enclosing named class as source (it has no declaration
  identity of its own to attach to), so counting it would give the enclosing
  class a parent, and the parent a child, neither has (ADR 0071).
- `ParamTypeCoverageRule`, `ReturnTypeCoverageRule` and
  `PropertyTypeCoverageRule` judge one dimension each, one channel each, and
  share `AbstractTypeCoverageRule` for the walk and the emission plus one
  `TypeCoverageOptions` implementation for the shape of their configuration.
  Configuration is keyed by producer rule name, never by Options class, so
  sharing the class does not share the configured instance. They are registered
  by name in `DesignConfigurator`, in the order `param, return, property`,
  because channel order is published in a "did you mean" tie-break and would
  otherwise be decided by alphabetical filenames.
- `DataClassRule`, `GodClassRule`, `InheritanceRule`, and `NocRule` retain their
  IDs, options, and CLI aliases. All rules here read precomputed Measurement
  facts and never traverse an AST.
- `DataClassRule` gates on a **low** WOC: the share of the public interface
  that carries behaviour rather than data access. Its finding channel is
  therefore `WorseDirection::Lower`, and both `@qmx-threshold` axes are upper
  bounds. What counts as data access is decided by method name in
  `Size\MethodCountVisitor` — `get*`/`is*`/`has*`/`set*` — and by public
  property declarations; **a method body is never read**. A public method that
  merely forwards to a collaborator is behaviour for this rule, and an
  `is*`/`has*` predicate that computes its answer is data access: WOC measures
  the shape of the interface, not the weight of the bodies behind it. The
  constructor counts on neither side of the ratio. Classes with no public
  members at all score 100 and are never flagged, and the size floor
  (`minMembers`) counts declared methods plus declared properties so a struct
  of public fields stays in reach. Traits are in the population; only
  interfaces, abstract classes and property-less classes are excluded. With
  `excludeExceptions=true`, proven exceptions and unknown exception status
  cannot be judged; `false` ignores that classification and applies the
  remaining criteria. Readonly and promoted-only exclusions remain configurable.
- Each rule's full channel declaration owns its ordered population gates. The
  same declaration evaluates eligibility in direct calls and during traced
  execution; publication selection controls accounting only. Admitted classes
  count as judged even when their substantive thresholds produce no finding.
  Inputs are yielded in source order and stop at the first failed gate.
- Data-class population gates retain interface, abstract, property, exception,
  readonly, promoted-only and member-floor order before WOC presence.
  `DataClassExclusionCheck::populationGates()` declares the class-shape portion;
  `populationInputs()` supplies its current metric bag and effective options.
  Disabled exception exclusion bypasses both presence and nonzero checks;
  unconditional interface and abstract flags exclude only the integer value 1.
- God-class population requires a class declaration, an admitted readonly flag,
  the method floor and enough evaluable criteria. Criteria are evaluated once
  after the earlier gates, and their results are reused for the finding. Enough
  evaluable but unmatched criteria is a healthy judgement, not an abstention.
- DIT zero is a judged root depth; missing DIT is an abstention and retains the
  independently published exact/floor/loop diagnostic. NOC absence and measured
  zero have separate gates; negative direct-child counts refuse judgement.
- Each type-coverage dimension distinguishes a missing total from a measured
  nonpositive total. A positive total admits judgement even when coverage is
  absent, preserving the substantive 0% fallback. Parameter, return and property
  totals are never substituted for each other.
- Per-file visitors implement Measurement reset semantics. Global collectors
  are stateless across runs; the worker wire payload remains Measurement-owned.

## Tests

Owned test code is under `tests/Analysis/Evidence/Design/`:

```text
Fixtures/
└── DataClass/
    ├── ReadonlyDto.php
    └── SmallClass.php
Integration/
├── DataClass/
│   └── DataClassDetectionTest.php
└── Inheritance/
    ├── AnonymousClassDeclarationEdgeRunTest.php
    ├── DitAggregateRunTest.php
    ├── DuplicateDeclarationDepthRunTest.php
    ├── UnloadableExternalParentRunTest.php
    └── UnreadAncestryDiagnosticRunTest.php
Unit/
├── DataClass/
│   └── DataClassRuleTest.php
├── GodClass/
│   └── GodClassRuleTest.php
├── Inheritance/
│   ├── DitGlobalCollectorTest.php
│   ├── ExternalAncestryTest.php
│   ├── InheritanceDepthResolverTest.php
│   ├── InheritanceRuleTest.php
│   ├── NocCollectorTest.php
│   └── NocRuleTest.php
└── TypeCoverage/
    ├── TypeCoverageCollectorTest.php
    ├── TypeCoverageOptionsTest.php
    ├── TypeCoveragePercentCollectorTest.php
    └── TypeCoverageRuleTest.php
```

Run the owned suite with:

```bash
vendor/bin/phpunit --no-coverage --do-not-cache-result tests/Analysis/Evidence/Design
```

`DataClassDetectionTest` drives the rule from PHP source instead of a
hand-written metric bag: the unit suite can only assert what the rule does with
a WOC number, never what that number means, which is how an inverted WOC
survived it. The tests cover type-coverage
projection and scale, data/god-class criteria, local/imported/external
inheritance floors and loops, static builtin ancestry, duplicate-parent merges,
exception classification, DIT/NOC global graph behavior, thresholds, and finding
identity, healthy population accounting, conditional bypasses, missing-versus-zero
reasons, and reached-input timing. Shared container, worker, and cross-capability integration tests stay
with their owning integration subjects.

## Change recipe

For a Design metric or rule change, update this leaf's implementation and
owned tests together; keep cross-owner interactions on the existing
Measurement, DependencyModel, and Finding contracts. Update the two Design
website pages when user-visible metric or rule behaviour changes. Adding a
new external consumer requires an explicit, narrow contract decision rather
than exposing a concrete collector or rule.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
