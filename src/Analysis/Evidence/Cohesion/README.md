# Cohesion

## Subject and boundary

`Analysis\\Evidence\\Cohesion` owns the LCOM4 and TCC/LCC class-cohesion
collectors, their AST visitors and per-class calculation data, the
`cohesion.lcom` rule with its options, and the LCOM collection configuration
needed by its named sequential and parallel consumers.
`ClassVisitorStackTrait` is private scaffolding shared only by the two Cohesion
visitors. `CohesionAstFacts` captures class and trait member facts;
`CohesionTraitUses` preserves resolved trait adaptations across the worker
boundary. `CohesionMemberWire` encodes declaration member facts, and
`CohesionFactWire` owns the exact serializable row format, while
`CohesionFactProjectionTrait` publishes those rows under declaration subjects.
`CohesionDeclarationData` keeps declared members separate from the LCOM graph;
`CohesionMethodReachability` follows instance calls to property reads;
`CohesionTraitComposition` resolves effective class members before metrics are
calculated.

`Contract/` exposes the exact
`LcomCollectionConfiguration` value; `Runtime/` owns its scoped store and
`Configuration/` owns the typed projection resolver. Measurement remains the owner of cross-capability metric and
aggregation contracts, while Finding owns rule options and findings.

## Structure

```text
Cohesion/
├── ClassVisitorStackTrait.php
├── CohesionAstFacts.php
├── CohesionCompositionCollector.php
├── CohesionDeclarationData.php
├── CohesionFactProjectionTrait.php
├── CohesionFactWire.php
├── CohesionMemberWire.php
├── CohesionMethodReachability.php
├── CohesionTraitComposition.php
├── CohesionTraitUses.php
├── Contract/
│   ├── LcomCollectionConfiguration.php
│   ├── LcomCollectionConfigurationResolverInterface.php
│   ├── LcomCollectionConfigurationStoreInterface.php
│   └── LcomCollectionConfigurableInterface.php
├── LcomClassData.php
├── LcomCollector.php
├── LcomGraphCalculator.php
├── LcomMethodPopulation.php
├── LcomOptions.php
├── LcomExcludedMethods.php   # unmatched authored method exclusions
├── LcomRule.php
├── LcomVisitor.php
├── Configuration/           # projection of the prepared typed LCOM options
├── Runtime/                 # invocation-owned LCOM store
├── TccLccClassData.php
├── TccLccCollector.php
└── TccLccVisitor.php
```

## Behaviour and runtime configuration

`LcomExcludedMethods` reports unmatched authored method names only for a
whole-project method universe, matching and deduplicating case-insensitively
while retaining the first authored spelling. It does not change the LCOM
graph or its academic calculation. The rule supplies its producer and full
secondary declaration; the diagnostic helper does not depend back on the rule.

`LcomCollector` and `TccLccCollector` publish file-local class and trait fact
rows. `CohesionCompositionCollector` resolves effective members and properties
from readable traits, including aliases, visibility changes, precedence,
overrides and nested uses. It publishes `cohesion.unread-trait-count` for every
class; a positive value records trait bodies outside the analysed source.
The seven outputs are `cohesion.lcom`, `cohesion.lcom-method-count`,
`cohesion.tcc`, `cohesion.lcc`, `cohesion.tcc-method-count`,
`cohesion.pure-method-count` and `cohesion.unread-trait-count`. They retain exact
class declaration subjects; their raw file-local facts retain Members reach.
All composition outputs declare Run reach: selecting a class file without a
trait file can change its LCOM, TCC, method counts and unread count. A class
whose traits are co-selected can have complete evidence, but the producer's
reach contract is uniform across its metric keys, so a narrow-file baseline
cannot claim whole-run comparability for this producer.

The pipeline retains metric keys and class-level aggregation
definitions, visitor reset semantics, and anonymous-class exclusion. Each named
class declaration retains its own calculation data, even when the same name
appears twice in one file. The shared stack keeps physical declaration identity
separate from the name used to interpret member references. Nested anonymous
classes cannot change an enclosing declaration's facts.

`cohesion.lcom-method-count` counts graph methods and is the rule's
`min_methods` population. A class with no graph methods has no `cohesion.lcom`
value. `cohesion.tcc-method-count` counts tracked public methods, the
denominator for the pure-method fraction. TCC and LCC remain absent when fewer
than two public methods qualify or when no instance property is declared or
read. Builtin health cohesion uses only available measured contributions and keeps
nonempty one- and two-vertex graphs, independently of the LCOM rule's minimum.
The pure-method and tracked TCC-method counts also publish namespace/project
averages for the same health correction at those levels; the academic LCOM4,
TCC and LCC values are unchanged.
`LcomMethodPopulation` owns effective-name lifecycle and static/configured
exclusion and the virtual stateless vertex decision shared by LCOM and its
graph-method count.

`cohesion.lcom` is the stable rule ID — its `cohesion` family, and so the
heading `qmx rules` lists it under, is now read off that name rather than
declared beside it (renamed from Design, see ADR). It carries warning/error
defaults of 3/5, readonly and
minimum-method eligibility checks, CLI aliases, and threshold-override
behaviour. `LcomOptions::excludeMethods`
continues to configure the LCOM graph. Finding resolves the effective rule
configuration, and Cohesion projects its exact value to
`LcomCollectionConfiguration`. The instance-owned
`LcomCollectionConfigurationStore` supplies the main collector and the typed
worker payload; it is Cohesion-owned invocation state with an explicit reset
point.

LCOM4 counts connected groups of instance methods through shared property
access or calls to instance methods through `$this`, `self`, or `static`.
Method names follow PHP's case-insensitive matching. Constructors and destructors are excluded
from the graph by their effective names after trait composition (see the
scope-adaptation note below). Raw lifecycle bodies and method flags remain
available when a trait adaptation exposes an ordinary alias. Uncalled stateless
constant methods are merged into a virtual node to avoid false positives from protocol metadata; a called stateless method
stays in the graph. TCC and LCC measure direct and transitive property-sharing
connections among public methods, including reads through own-method calls.

> **Note:** [Hitz and Montazeri](https://www.researchgate.net/publication/238729882_Measuring_coupling_and_cohesion_in_object-oriented_systems)
> define connections through shared instance variables or method calls.
> Qualimetrix follows those two edge kinds, resolving PHP method names without
> regard to case and including effective trait methods. Its scope adaptation
> merges only uncalled stateless constant methods into one virtual node;
> a called method retains its vertex. Metadata obligations therefore do not
> each imply a disconnected responsibility. Empty graphs publish no value.
> These scope choices are a semantic interpretation of LCOM4, rather than
> literal original-spec compliance. Constructors and destructors are excluded
> from the method set entirely, matching the treatment TCC/LCC already applies.

> [Bieman and Kang (1995)](https://www.cs.colostate.edu/~bieman/Pubs/bieman-kang-ssr95.pdf),
> sections 2–3, define property access through directly or transitively called
> methods on the same object. Following private helpers is part of that
> definition; the public-only method population and stateless abstention are
> the named scope adaptations.

## Test ownership and Definition of Done

Owned tests live in `tests/Analysis/Evidence/Cohesion/Unit/` and cover LCOM
data/collection, TCC/LCC data/collection, and the LCOM rule options,
eligibility, thresholds, controls and output identity.

- The existing metric IDs and the `cohesion.lcom` rule ID remain unchanged;
  graph population and unread-trait keys are added.
- `excludeMethods` affects both direct collection and configured runtime runs.
- Visitor and runtime state are replaced between files and analysis runs.
- LCOM and TCC/LCC continue to ignore anonymous classes.


## Typed LCOM options and exclusions

`LcomOptions::fromResolved()` consumes the judged values in the invocation's
`ResolvedRuleOptions`; the collection resolver projects exactly that snapshot
into `LcomCollectionConfiguration`, never a raw option map or fallback defaults.
`exclude_methods` is a sequence. PHP method matching folds case, deduplicates
normalized names and retains the first authored spelling in diagnostics.

`cohesion.unmatched-exclude-method` is a secondary channel of the LCOM producer.
When measured judgement permits declaration absence, it reports each configured
normalized name that
matches no precomputed Method-kind declaration. Functions and property hooks do
not witness a method; partial runs make no project-wide absence claim. Its project
magnitude is 1, its occurrence identifies the normalized name, and it explicitly
declines the producer's configured warning boundary. Selected accounting judges
every normalized configured name, including a healthy match. When the method
universe is not judged, each configured selector belongs to the declared
`method-universe` absence group without enumerating callables or inventing
unmatched findings. The full secondary declaration owns the same scope gate
for direct, unselected and selected execution. The primary 3/5 LCOM band and
secondary-channel lifecycle are unchanged.

DoD includes typed snapshot handoff, case-insensitive matching with original
spelling, normalized duplicate handling, method-kind-only whole-project evidence
and silence when paths, authored PHP removal, generated removal or an unknown
denominator withhold declaration absence.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.

The primary LCOM population checks the class coordinate, configured readonly exclusion, minimum method count and published LCOM value in that order. A missing graph-eligible method count retains the native zero fallback; missing LCOM values remain unjudged. Secondary configured-method-selector accounting is independent.

Invalid trait method composition carries the exact declaration through
`TraitCompositionFailure`; Run refuses that source file and publishes the rest
of the analysis with incomplete coverage.
