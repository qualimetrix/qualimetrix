# Cohesion

## Subject and boundary

`Analysis\\Evidence\\Cohesion` owns the LCOM4 and TCC/LCC class-cohesion
collectors, their AST visitors and per-class calculation data, the
`cohesion.lcom` rule with its options, and the LCOM collection configuration
needed by its named sequential and parallel consumers.
`ClassVisitorStackTrait` is private scaffolding shared only by the two Cohesion
visitors. `CohesionAstFacts` captures class and trait member facts, own
property accesses, hand-offs of own state and own calls, keeping what closures,
arrow functions and first-class callables do apart as deferred; `CohesionOwnCalls`
names the own method a node invokes or refers to, or any method when only a
dynamic name is given; `CohesionTraitUses` preserves
resolved trait adaptations across the worker boundary. `CohesionMemberWire`
encodes declaration member facts and deferred-access rows, and
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
├── CohesionOwnCalls.php
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
`LcomMethodPopulation` owns effective-name lifecycle, initialization-helper,
static and configured exclusion and the virtual stateless vertex decision
shared by LCOM and its graph-method count. Initialization helpers leave before
the stateless partition, so a removed constant helper never holds the virtual
vertex open.

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
access or own-method calls: through `$this`, `self`, `static`, the class's own
name or `$this` used as a class, and through references to an own method —
a first-class callable, a callable array or callable string naming the object
or class, or `new ReflectionMethod(...)` — which count as deferred calls.
Method names follow PHP's case-insensitive matching. Constructors and destructors are excluded
from the graph by their effective names after trait composition (see the
scope-adaptation note below). Raw lifecycle bodies and method flags remain
available when a trait adaptation exposes an ordinary alias.

Initialization helpers leave with them. Candidates are private instance
methods that a constructor or destructor body reaches through direct calls,
transitively, and that hand no instance state to later code: a method that
touches a property inside a closure, arrow function or nested function (by name
or dynamically), binds a property or an element or member of one by reference
(on either side of `=&`, as a by-reference array item or as a by-reference
`foreach` value), or is a generator registers behaviour and is never a
candidate. Every other method — public and protected
ones (even when the constructor calls them), static methods and
`exclude_methods` entries — is a root of ordinary use, and so are the callees
of deferred calls made by lifecycle bodies and candidates. Every closure, arrow
function and first-class callable counts as deferred, including one the
constructor invokes at once (`array_map(fn ($i) => $this->wrap($i), ...)`):
the approximation keeps such callees in the graph rather than telling
registration from immediate use. A candidate that no root reaches over call
edges, never passing through a lifecycle body, is an initialization helper and
leaves the graph. Every method records its deferred calls and accesses the same
way, because trait aliases decide lifecycle only after composition; for
ordinary methods they are edges and accesses like any other, and TCC/LCC
reachability reads both kinds alike.

No method leaves when the call map may be incomplete: when a used trait was not
read (`cohesion.unread-trait-count` above zero), or when any method names an own
method only dynamically — a non-literal name in an own call (`$this->$name()`,
`self::$name()`, `$this::{$x}()`), a callable array or `new ReflectionMethod`
whose first element is `$this`, `self::class`, `static::class`, the own
`::class`, `$this::class`, `__CLASS__`, `get_class($this)` or
`get_called_class()` and whose method name is not a literal. Invoking `$this`
itself (`call_user_func($this)`) reaches only the public `__invoke`, a root of
ordinary use, and records nothing. Not recorded, so a helper reached only through them is taken for an
initialization helper: calls on another instance (including an alias such as
`$self = $this`), the nullsafe `$this?->m()`, `parent::` calls and own names
reached through other strings or reflection lookups. By-reference argument
passing and an object-valued property captured by value in a static closure
do not count as a hand-off. Uncalled stateless
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
> from the method set entirely, matching the treatment TCC/LCC already applies,
> and the private helpers only they reach leave with them. Removing such a
> helper can raise LCOM, when it was the only bridge between two property
> groups, and can empty the graph, which then publishes no value.

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
