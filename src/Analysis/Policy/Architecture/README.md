# Architecture policy

`Analysis\\Policy\\Architecture` owns declared-layer policy: YAML contribution
parsing, layer membership preparation, diagnostics, and
`architecture.layer-violation`. It is a leaf capability, not the old combined
Architecture vertical slice; circular-dependency evidence is owned separately
by [`Analysis\\Evidence\\CircularDependency`](../../Evidence/CircularDependency/README.md).

## Public contracts

External owners use only the contracts in `Contract/`:

- `ArchitecturePolicyConfiguratorInterface` configures the policy from the
  immutable `ConfigurationDocument` and returns configuration
  warnings after the Console logger is available.
- `LayerPolicyPreparationInterface` is the Run-owned sequential preparation
  boundary. Disabling the rule clears state and does no class-universe or
  template-expansion work. It also carries the literal names of the diagnostic
  channels the producer emits under rule names other than its own — three from
  the rules (`unassigned-class`, `unmatched-exclude`, `doubted-assignment`),
  five from its configuration validator — and the project-scoped
  subset of them.
- `LayerAssignmentInspectorInterface`, `LayerAssignment`, and
  `LayerAssignmentMatch` form the Console debug projection.
- Configuration and preparation failures are surfaced as
  `Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal`,
  built through its `atResolvedKey()`/`aboutResolvedInput()` shorthands, which
  address `ConfigurationSource::Resolved` because Architecture validates the
  already-merged document and cannot attribute a rejected value back to one
  file or CLI option. The two capability-owned exception classes this
  replaced are retired and kept only until a later cleanup removes them and
  their remaining Console-side callers.

The concrete `ArchitecturePolicy` owns configured and prepared state for one
run. It resets before a new configuration and before disabled preparation; no
policy state enters the worker or cache payload.

## Layout

```text
Architecture/
├── Contract/                  # exact external promises and debug values
├── Configuration/              # contributed `architecture:` document parser
│   └── Allow/                  # allow selectors and binding values
├── Layer/                      # membership, capture-pattern compilation, and registry primitives
│   └── Expansion/              # observed-template expansion
├── LayerViolation/             # two rules, declaration validator
│   └── Observation/            # the shared walk and the evidence it records
└── ArchitecturePolicy.php      # instance-owned configuration/preparation
```

`Configuration/`, `Layer/`, `Layer/Expansion/`, `LayerViolation/` (with its
`Observation/`), and the policy coordinator are internal zones of one leaf. The
manifest-backed Architecture topology test enforces their exact DAG; sibling
internals are not a public API. The generated qmx projection enforces the leaf owner boundary.

## Configuration and lifecycle

`ConfigurationDocument` preserves ordered source contributions.
`ArchitecturePolicy` alone merges its `architecture` contributions and turns
them into typed policy configuration. The central Configuration merger has no
Architecture-specific branch or deferred-warning transport.

Run prepares the policy after graph construction. Neither verdict traverses the
AST or constructs lifecycle state.

### Architecture pattern DSLs

Architecture owns two closed pattern languages. Layer membership patterns are
FQN-oriented: a bare value such as `App\\Domain` denotes that namespace and its
descendants, `*` and `?` stay inside one namespace segment, `**` may cross
segments, and `{module}` / `{path:**}` capture one / multiple segments for
template expansion. A trailing `\\**` selects strict descendants. Character
classes and raw PCRE syntax are rejected.

Allow-list selectors address concrete layer names. They support exact names,
anchored `*` / `?` wildcards, and `{name}` bindings shared between an allow
entry's source and targets. Concrete layer names cannot contain namespace
separators, so multi-segment captures are invalid there.

These languages are deliberately separate from the public Core selector
contract (`exact`, `subtree`, `regex`): they express Architecture-specific
capture and binding semantics rather than selecting an open universe of paths
or namespaces.

### What the analysed set can and cannot answer

`extends`, `implements` and `attributes` are answered from the declaration
edges this run recorded, so the answer is bounded by what the run analysed.
`ClassContextFactory` is bound to the run's **class universe** alongside its
graph (`ArchitecturePolicy::prepare()` is the single binding point) and reports
where the facts ran out: `ClassContext::$declarationAnalysed` says whether the
subject's own declaration was read, and `ClassContext::$ancestryCuts` names
where the parent-class chain was cut and, separately, every interface the walk
reached without facts of its own.

A class or interface PHP declares is not a cut. Its parent, interfaces and
class-level attributes come from `Core\Symbol\PhpBuiltinClassHierarchy`, a
static table over `PhpBuiltinClassRegistry`'s names, so membership is the same
whichever PHP runs the analysis and whichever extensions it loads. Nothing in
this slice reads reflection. For an interface, `extends` follows the
interfaces it extends, as PHP's own keyword does.

The interfaces PHP adds unwritten — `UnitEnum` and `BackedEnum` on an enum,
`Stringable` on a class or interface declaring `__toString()` — arrive as
declaration edges from `ClassLikeHandler`. The interface walk also follows an
interface's own `implements` edge, since that edge can only be the `Stringable`
PHP gave it. A `__toString()` a class takes from a trait is not seen, and
`extends: ['\Stringable']` does not see the one an interface gets.
Criterion FQNs are stored without a leading `\`, which is how a class in the
global namespace is written (`\Throwable`) and how the run records none of
them.

`LayerCriteriaMatcher` turns that into a third answer beside match and
non-match. `CriterionOutcome::Undecidable` is what a declared criterion returns
when the run holds no facts to decide it, and `CriteriaEvaluation::outcome()` —
the single point where a `MatchMode` is applied, for positive criteria, for
`exclude:`, and for template observation alike — combines the kinds
three-valued: under `any` one hit decides and only a fully decided walk may
report a non-match; under `all` one definite miss decides and only a fully
decided walk may report a match. A hit found on a truncated chain still counts,
because truncation can hide evidence but never invent it.

Which kinds this reaches, and why exactly those: `patterns` and `suffix` are
derived from the FQN and are always decided; `attributes` is decided whenever
the subject's own declaration was analysed; `extends` is decided when the
parent-class chain was not cut, and `implements` when neither that chain nor
the interfaces above it were. The two are kept apart because an unread parent
class can hide both a parent and an interface, while an unread interface can
hide only interfaces. A criterion naming the class's own **direct** parent or
interface still matches even when it is vendor code, because the edge was
recorded from the analysed child; what cannot be answered is a miss on a chain
that passes through an unread non-PHP declaration, or any graph-backed
criterion about a subject the run never analysed (a dependency-edge end outside
`paths:`) unless PHP itself declares it.

`ClassContextFactory` reads a class's declared supertypes and attributes from
the graph's declaration view, `DependencyGraphInterface::getDeclarationDependencies()`,
not from `getAllDependencies()`. The coupling view leaves out every edge to a
class PHP itself declares, `extends` aside, so that none counts toward coupling;
read from there, a class declaring `implements \JsonSerializable` or carrying
`#[\AllowDynamicProperties]` would be told it does neither. The declaration view
keeps those edges, and from a direct PHP interface the walk continues through
PHP's own hierarchy (`implements: ['\Traversable']` for a class declaring
`implements \IteratorAggregate`). The allow-list check still reads the coupling
view, so no edge to a PHP type is ever judged against a layer.

`MembershipResult::undecided()` carries an unanswered positive criterion out of
the layer, `LayerRegistry::undecidedLayers()` is the third exit of the one
cached walk, and `LayerRegistry::chainStopsAt()` names where the chain stopped,
which `debug:layer-assignment` prints. `undecidedLayers()` is also the one place
that decides which unanswered layers bear on an assignment: all of them for a
symbol nothing matched, otherwise those declared before the first match the run
established — a match whose own `exclude:` went unanswered is not established.
Every reader of the doubt takes that list as it comes rather than
re-deriving the rule from the declaration order. `LayerRegistry::contenders()`,
the fifth exit, names the layers that could own the symbol once those are
answered, and `LayerRegistry::establishedMatches()`, the sixth, the matches
whose `exclude:` was answered — the first of them is where the contest stops.
`architecture.coverage-gap` names the
count and a sample of undecided symbols outside every layer — only when such a
symbol exists, so an all-decided project reads the sentence it always read —
and says what a later layer does with them: it assigns them, as a guess.

An unanswered layer never withdraws a match. Neither an undecidable layer
declared before one that matched nor an undecidable `exclude:` on the matching
layer itself removes the class: withdrawing it would leave the class in no
layer, no allow-list would judge its edges, and real violations would stop being
reported. The layer whose `exclude:` went unanswered answers
`MembershipResult::doubtedMatch()`; when it is the assigned layer it is named in
both the match list and `undecidedLayers()`, and whichever layer won it is named
by `unansweredExcludeLayers()`, the fourth exit of the walk.
`LayerEvidenceCollector` counts every symbol that stands assigned with a
non-empty `undecidedLayers()` — analysed classes and dependency-edge ends
alike, each end on its own — and keeps apart those the run did not analyse.
That count is information, not a gap: `architecture.coverage-gap` names it only
when it fires for unassigned or undecidable symbols, and never fires for it.
`architecture.doubted-assignment` publishes it at `info` in every coverage mode,
together with the symbols in no layer only because a layer could not answer,
and names each such layer with its counts — the per-layer `undecided` column of
the walk's symbol sets.

The two declaration verdicts that conclude something from who won or lost read
the walk instead of the bare match list. `LayerEvidence::reachedCounts()` is
the one place that decides which of the `contended` column keeps a layer out of
`architecture.unreachable-layer`. An analysed class the layer's own criteria
matched counts. An analysed class the layer could not answer about counts only
while some type its `attributes:`/`implements:`/`extends:` criteria name is one
the run met — declared in the analysed paths, built into PHP, at an end of a
dependency edge, or declared by the analysed project's composer install
(`KnownTypes`): a class with an unread parent leaves every such criterion
unanswered, a mistyped name included. The install is read through Design's
`ExternalParentSourceInterface`, the port DIT's ancestor walk reads it by,
which `ArchitecturePolicy` takes by autowiring and hands down as a lookup when
it binds the run; it answers only whether the type exists, so a criterion over
a vendor chain stays undecidable and the layer is named by
`architecture.doubted-assignment` rather than called empty. A
symbol outside the analysed paths never counts, for the same reason. The
finding says what it left out in the words true of each share: the unanswered
symbols and the named types the run never met — a typo, or, when no install
was found to ask, a type only unanalysed code reaches — or the outside symbols the layer matched that an earlier
unanswered `exclude:` holds. `LayerShadowing` draws a shadow only
between `establishedMatches()`, the first of them shadowing the rest, and
`debug:layer-assignment` reports its `shadowed` list and hint by the same rule.
`architecture.doubted-assignment` names every layer a contest keeps out of
`unreachable-layer`: those that could not answer, and — from the walk's
`ownsIfExcluded` column — those that would own a symbol if an unanswered
`exclude:` in front of them removed it.

Four declarations that used to be accepted are now refused at config load,
because there is no correct silent reading of any of them. A template layer may
not declare `suffix`, `attributes`, `implements` or `extends` under
`match: any`: only `patterns` carries capture variables, so the criterion would
be copied into every expanded instance as one project-wide net and the instance
that wins a class would be decided by binding-value order. A non-`ignore`
`coverage-gap:` requires at least one `layers:` entry, because with no layers
every class is outside every layer while the walk short-circuits and the run
exits 0 — the strictest setting producing the quietest outcome. An allow entry's
`relations:` written without a value takes the same refusal as `relations: []`
instead of reading as "every relation allowed". And an `attributes`,
`implements` or `extends` entry that is nothing but `\` passed the
namespace-separator check while naming no class; with the leading separator now
dropped it is refused as such.

`ClassContextFactory` skips a `Dependency` flagged
`describesNestedAnonymousClass` when it builds `extendsMap`, `implementsMap`
and `attributesMap`: that edge is a declaration fact about an anonymous class
nested inside the source, not about the source itself, so counting it would
match the enclosing class — including transitively, since membership walks
`extendsMap` as a BFS closure — into a layer whose criteria describe the
nested anonymous class instead (ADR 0071). The dependency the edge still
represents is unaffected; only its reading as a declaration fact about its
recorded source is narrowed.

`LayerViolation/` is four subjects, not one. The first is `Observation/`:
`LayerEvidenceCollector` walks the analysed classes and the dependency graph
**once per run** — memoised weakly by
the run's `AnalysisContext`, so nothing survives into the next run — and returns
one `LayerEvidence`: the edges the allow-list rejects, per-layer tallies of what
each layer was ASSIGNED, what it MATCHED at all and what its `exclude:` clause
REMOVED, the shadow evidence, the classes outside every layer, and the coverage
state. The exclusion tally exists because membership collapses "the clause
removed it" and "no criterion caught it" into the same absence:
`MembershipResult::excluded()` keeps the two apart and `LayerRegistry::excludedLayers()`
is the second exit of the one cached walk `resolveAll()` already performs;
`undecidedLayers()` is the third, for the gap the run could not decide.
Assignment is untouched by that — `resolveAll()` still returns no layer for an
excluded class, so `debug:layer-assignment` and the shadow evidence read
exactly what they read before. It short-circuits to `null`
when the producer is disabled or no layers are declared, so "report nothing" has
one answer rather than two. It answers to both consumer gates — the rule's
`enabled` and `UnassignedClassOptions::$mode` — and materialises the
outside-every-layer set when either of them, or the coverage mode, has a use
for it. The class walk and the edge walk each hand their half to the merge as a
typed value (`ClassWalkEvidence`, `EdgeWalkEvidence`) — each carrying the same
six symbol-set columns and the undecidable and doubted symbols it booked — and a rejected edge and a
shadowed class travel as `ForbiddenEdge` and `ShadowedClass` rather than array
shapes, so the `Dependency` and `MatchedCriterion` they carry count as coupling
of those value objects; the lists that hold them are still typed in PHPDoc
only. `Observation/` reads its two consumers' gates through the generic
`RuleOptionsInterface` and its code references nothing in `LayerViolation/`,
which the topology test enforces as a zone of its own; the two rules, the validator and
the diagnostics built from the evidence read it.

Two rules report on the **code** over that one walk. `LayerViolationRule` emits
`architecture.layer-violation` per forbidden edge and
`architecture.unmatched-exclude` per layer whose `exclude:` clause removed
nothing while the layer's own criteria caught something — a layer wider than
its declaration asks for, which is debt rather than a broken configuration, so
it is the rule's channel at a fixed `warning` and not the validator's. The
"caught something" half of the predicate is what keeps it from restating
`architecture.unreachable-layer`: the clause is evaluated only after the
positive criteria succeed, so a layer that matched nothing never offered it
anything to remove. A clause that could not be answered for some symbol its
layer caught is not reported: "removed nothing" has not been shown for it.
The rule's third channel, `architecture.doubted-assignment`, is built by
`DoubtedAssignmentDiagnostic`, reading the same population the coverage text
names.
`UnassignedClassRule` emits the magnitude channel
`architecture.unassigned-class`, gated by its own single `mode` option and built
by `UnassignedClassSummary`; both are ordinary debt a baseline may accept. Being
a producer of its own is why `LayerPolicyPreparationInterface::PRODUCER_RULE_NAMES`
names two rules: the run prepares the policy when either is selected, and asking
about one of two left `--only-rule=architecture.unassigned-class` reaching an
unprepared collector.

`LayerDeclarationValidator` is the verdict on the **declaration** and is a
`ConfigurationValidatorInterface`, not a rule — which is the whole statement
that its five channels are configuration errors. `DeclaredLayerReachability`
builds four of them: `architecture.coverage-gap`, `architecture.unreachable-layer`,
`architecture.pending-layer-matched` and `architecture.empty-template`;
`PotentialShadowDiagnostic` renders `architecture.potential-shadow` from the
shadow evidence, which no other verdict reads. `unreachable-layer` and
`empty-template` say that no class matches a declaration, so the validator
withholds them on a run whose paths do not cover the project's autoload roots
(`AnalysisContext::$coversProjectScope`, the gate `architecture.unmatched-exclude`
reads); the run's scope warning and the report's `projectScope` say so. A
project whose manifest declares no readable production autoload is judged,
with its analysed paths taken as the whole project. The validator declares `architecture.layer-violation`
as its producer, so all five are registered, addressed, excluded, described and
switched off exactly as they were while the rule declared them, and it runs in
the rule's slot so their position in an unsorted report is unchanged.
`DiagnosticSampleList` formats the bounded FQN samples
`architecture.coverage-gap`, `architecture.doubted-assignment` and
`architecture.unassigned-class` print, and is the
one piece of code shared across the code/declaration split — a narrow
formatting utility with no policy semantics of its own.

A layer declared `pending: true` — reserved for code not
written yet — is exempt from `architecture.unreachable-layer` and is reported
by `architecture.pending-layer-matched` once its criteria match, which the
matched tally sees even when a broader layer wins every assignment.
The Console debug command invokes the inspector contract over the same collected
graph and class universe.

## Rule option key declarations

`LayerViolationOptions` and `UnassignedClassOptions` declare their accepted
option keys through `RuleOptionsInterface::acceptedOptionKeys()`.
`LayerViolationOptions` accepts `enabled`, `severity`, and additionally
declares `empty-template-severity`, `potential-shadow-severity` and
`unreachable-layer-severity` as answered-by-the-class: `fromArray()` refuses
these three in its own words (the diagnostics they used to tune now gate the
run unconditionally) rather than through the generic "unknown option"
warning. `UnassignedClassOptions` accepts only `mode`, and declares `enabled`
as answered-by-the-class: `fromArray()` accepts `enabled: false` when it
agrees with `mode: ignore` and refuses it otherwise, naming `mode` as the
replacement. `RuleOptionsFactory` reads these declarations and refuses an
unrecognised key by name — an answered-by-the-class key reaches the class's own
bespoke refusal unchallenged; anything else is rejected before construction.

## Definition of Done

- Keep public consumers on the declared contracts; do not import an internal
  Architecture zone from another owner.
- Preserve independent reset semantics across sequential runs and zero work
  when layer policy is disabled.
- Update this README, the manifest inventory, topology tests, and exact
  generated projection whenever the leaf surface or zone DAG changes.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
