# Architecture policy

`Analysis\\Policy\\Architecture` owns declared-layer policy: the `architecture:`
configuration section, layer membership preparation, diagnostics, and
`architecture.layer-violation`. It is a leaf capability, not the old combined
Architecture vertical slice; circular-dependency evidence is owned separately
by [`Analysis\\Evidence\\CircularDependency`](../../Evidence/CircularDependency/README.md).

## Public contracts

External owners use only the contracts in `Contract/`:

- `ArchitecturePolicyConfiguratorInterface` configures the policy from the
  immutable `ConfigurationDocument` and returns configuration
  warnings after the Console logger is available.
- `LayerPolicyPreparationInterface` is the Run-owned sequential preparation
  boundary. When no layer-policy producer runs, it clears state and does no
  class-universe or template-expansion work.
- `ArchitectureChannels` owns the literal channel names, the three preparation
  producer names, and their project-scoped subset; it carries no lifecycle.
- `UnassignedClassLayerRequirementInterface` judges completed Finding options and
  enablement before Console discovers files. Its implementation and mode semantics
  stay in `UnassignedClass/`.
- `UnmatchedTypeWarningInterface` answers Run's post-execution query with one
  nullable explanation when authored layer types could not be judged. Run asks
  only after final publication selects the exact unmatched-type project channel.
- `ShadowExemption` names established first-match exemptions for inspection.
- `LayerAssignmentInspectorInterface`, `LayerAssignment`,
  `LayerAssignmentMatch`, and `LayerAssignmentShadowVerdict` form the Console
  debug projection. The assignment carries the observed declaration spelling,
  edge-end-only provenance, final policy enablement, and typed shadow
  exemptions without a Console-owned array contract.
- `ExternalSupertypeSourceInterface` and `ExternalSupertypes` expose source
  facts from the analysed Composer install: exact placement and declaration
  spelling, declaration kind, parent, interfaces, traits, direct
  `__toString`, and a direct trait alias to `__toString`.
- Configuration and preparation failures are surfaced as
  `Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal`.
  A configuration refusal names the layer that wrote the refused value —
  through the resolved node it was read from, see below. Template expansion
  (`Layer/Expansion/`) still refuses through `atResolvedKey()`, addressing
  `ConfigurationSource::Resolved`: it judges the expanded layers at
  preparation time, after the document's provenance is no longer at hand.
  The two capability-owned exception classes this replaced are retired and
  kept only until a later cleanup removes them and their remaining
  Console-side callers.

The concrete `ArchitecturePolicy` owns configured and prepared state for one
run. `replace()` installs the resolved policy. Every assignment inspection
prepares the supplied graph and class universe afresh, including its observed
name index, so a repeated call cannot retain names from an earlier project.
It resets before a new configuration and before disabled preparation; no
policy state enters the worker or cache payload.

The declaration-evidence, producer-selection and consumer migration decisions
are recorded in [ADR 0103](../../../../docs/adr/0103-layer-policy-declaration-evidence-and-selection.md).
Debug shadow verdicts are mandatory typed projections of the same authority;
disabled policy output retains observed spelling without claiming a reported
shadow or publishing a diagnostic hint.

## Layout

```text
Architecture/
├── Contract/                  # exact external promises and debug values
│   ├── ExternalSupertypeSourceInterface.php
│   ├── ExternalSupertypes.php
│   ├── LayerAssignment.php
│   ├── LayerAssignmentInspectorInterface.php
│   ├── LayerAssignmentMatch.php
│   ├── LayerAssignmentShadowVerdict.php
│   ├── ShadowExemption.php
│   ├── UnassignedClassLayerRequirementInterface.php
│   └── UnmatchedTypeWarningInterface.php
├── Configuration/              # the `architecture:` section: its schema and validators
│   └── Allow/                  # allow selectors and binding values
├── Layer/                      # membership, capture-pattern compilation, and registry primitives
│   ├── LayerShadowVerdict.php
│   ├── UnmatchedTypeOccurrence.php
│   ├── UnmatchedTypeJudgement.php
│   └── Expansion/              # observed-template expansion
├── Observation/                # the shared walk, evidence and bounded diagnostic samples
├── LayerViolation/             # forbidden dependency edges and routing guidance
├── LayerDeclaration/           # declaration diagnostics and configuration validator
│   ├── LayerOverlapDiagnostic.php
│   └── UnmatchedTypeDiagnostic.php
├── UnassignedClass/            # analysed-class assignment summary and mode
│   └── UnassignedClassLayerRequirement.php
└── ArchitecturePolicy.php      # instance-owned configuration/preparation
```

`Configuration/`, `Layer/`, `Layer/Expansion/`, `Observation/`,
`LayerViolation/`, `LayerDeclaration/`, `UnassignedClass/`, and the policy coordinator are internal zones of one leaf. The
manifest-backed Architecture topology test enforces their exact DAG; sibling
internals are not a public API. The generated qmx projection enforces the leaf owner boundary.

## Configuration and lifecycle

`ArchitectureSection` declares the `architecture:` section to the
configuration document engine (`DocumentSectionSchemaInterface::declaration()`),
returning an atomic `SectionDeclaration`: its keys at every level, the form of each value and how the configuration layers that
wrote it merge. The engine recognises and shapes every layer before merging,
so a misspelt key — in the section, a `layers[i]` entry or its `exclude:` — is
refused with its writer and spelling whatever its value, `~` included. The
same policies are published, generated from `ArchitectureSection`, in the table
of `website/docs/getting-started/configuration.md`; the decision is
[ADR 0086](../../../../docs/adr/0086-one-configuration-document-merged-by-declared-policy.md).

| Node                                  | Merge                                                                 |
| ------------------------------------- | --------------------------------------------------------------------- |
| `layers`                              | the last layer that writes it replaces the whole list                 |
| `allow`                               | merged by source layer name; one name's target list is replaced whole |
| `coverage-gap`, `max_expanded_layers` | the last layer that writes it wins                                    |

`~` anywhere is "not written": the lower layer's value stands, and a key no
layer wrote takes its default — `relations: ~` on a long-form allow target is
"any relation", exactly like a target without `relations`. An empty map
(`exclude: {}`, `allow: {}`) writes nothing and changes nothing.

Two values the engine carries unread, because each has two written shapes: a
criterion (`patterns`, `suffix`, … — one string or a list) and an allow target
(a layer name or a long-form map). `LayerCriterionNormalizer` and
`LongFormAllowEntryNormalizer` judge them; the latter recognises the long-form
keys by the document's spelling rule (snake_case, kebab-case or camelCase).
Their form is judged in every layer that writes them, before the merge —
`ArchitectureSection` declares `LayerCriterionNormalizer::ofLayerEntry()` on a layer
entry and `CarriedValueForm::ofAllowTarget()` on a target — so a preset's
malformed criterion or target is refused even under a file that replaces
`layers` or the target list. Static-layer name grammar, fixed exclude capture
placement, pattern and selector syntax, match and relation kinds, the coverage
mode and a positive expansion ceiling are also judged in each writing layer,
using the same parsers as the resolved validators. `LayersValidator` also judges
each written list for duplicate names or patterns, missing membership criteria
and invalid template bindings before another list can replace it. Allow-layer
references, source/target capture compatibility and cycles require the merged
document and are judged there.
An allow source name's syntax is judged even when its value is `~`. Its
membership is judged after merging against the names the merged `layers`
declares, in the words of its writing layer (`allow: {infrq: ~}` is refused):
an exact name must be declared; a glob or captured selector may name layers
only template expansion produces. A source written `~` keeps the
targets a lower layer gave it; with none, it allows nothing.

`ArchitectureConfigurationFactory::fromResolved()` reads the section from
`ConfigurationDocument::resolved()` through `SectionSpot`, which pairs each
value with the resolved node it came from: every validator refuses through the
spot, so a refusal names the file or preset that wrote the value — the one
that won a leaf or wrote a list, every contributor of a merged map, and both
halves of a relation (an allow cycle, a `coverage-gap` without `layers`). The
central Configuration merger has no Architecture-specific branch or
deferred-warning transport.

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

`extends`, `implements`, class `attributes`, and `member_attributes` are
answered first from the declaration edges this run recorded.
`ClassContextFactory` is bound to the run's **class universe** alongside its
graph and Architecture's external-supertype source
(`ArchitecturePolicy::prepare()` is the single binding point). It follows a
non-analysed link through an exactly placed Composer source file, without
loading the declaration. An unmapped, unreadable, conditional, or unresolved
declaration remains a cut: `ClassContext::$declarationAnalysed` says whether
the subject's own declaration header was read, independently of whether
external supertype facts completed its ancestry, and `ClassContext::$ancestryCuts`
names where the parent-class chain was cut and, separately, every interface
the walk reached without readable facts. Per-run external facts and contexts
are memoised by the factory and cleared at every binding; Composer placement
and directory-listing snapshots are cleared when the analysed project is
reanchored.

Each parent or interface branch follows at most 256 links. If the declaration
at that depth names another relation, the next FQN is recorded as an ancestry
cut, so a missing criterion remains undecidable instead of becoming a false
negative. This is a per-branch recursion boundary, not a global source-read
budget: independent branches may visit more than 256 declarations in total.
Implicit `Stringable` follows the same per-branch boundary and does not preload
external declarations past it.

A class or interface PHP declares is not a cut. Its parent, interfaces and
class-level attributes come from `Core\Symbol\PhpBuiltinClassHierarchy`, a
static table over `PhpBuiltinClassRegistry`'s names, so membership is the same
whichever PHP runs the analysis and whichever extensions it loads. Nothing in
this slice reads reflection. For an interface, `extends` follows the
interfaces it extends, as PHP's own keyword does.

`UnitEnum` and `BackedEnum` remain declaration edges. Implicit `Stringable` is
derived from `ClassLikeDeclaration` facts and the same mandatory facts read
from external declarations. The derivation follows parents, implemented or
extended interfaces, and nested trait uses, so a direct or inherited
`__toString()` produces the same answer PHP does. An interface gets
`Stringable` in both its interface and interface-parent closure; a class gets
it only in the interface closure. A trait supplies evidence to a class that
uses it but is not itself `Stringable`. A trait adaptation that
aliases some method to `__toString` is carried as a narrow doubt until another
fact proves the result; it does not make unrelated `implements` or `extends`
criteria undecidable.
Criterion FQNs are stored without a leading `\`, which is how a class in the
global namespace is written (`\Throwable`) and how the run records none of
them. A class PHP declares is one name whatever its case, so both sides of the
comparison carry it in `PhpBuiltinClassRegistry::spelling()`:
`LayerCriterionNormalizer` stores a criterion that way, and
`NameSpellingIndex` chooses one observed spelling for every project class-name
identity from analysed declarations and graph endpoints; `ClassContextFactory`
uses it consistently across a closure. Criteria and `KnownTypes::met()` remain
case-sensitive, and an external type is known only when placement and the
declaration's spelling both equal the requested name.

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
derived from the FQN and are always decided; `attributes` and
`member_attributes` are decided whenever the subject's own declaration was
analysed; `extends` is decided when the
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
while some type its `attributes:`/`member_attributes:`/`implements:`/`extends:` criteria name is one
the run met — declared in the analysed paths, built into PHP, at an end of a
dependency edge, or declared by the analysed project's composer install
(`KnownTypes`): a class with an unread parent leaves every such criterion
unanswered, a mistyped name included. The install is read through
Architecture's `ExternalSupertypeSourceInterface`; readable vendor chains are
answered fully, while an unreadable or unmapped link remains undecidable and
is named by `architecture.doubted-assignment` rather than called empty. A
symbol outside the analysed paths never counts, for the same reason. The
finding says what it left out in the words true of each share: the unanswered
symbols and the named types the run never met — a typo, or, when no install
was found to ask, a type only unanalysed code reaches — or the outside symbols the layer matched that an earlier
unanswered `exclude:` holds. `LayerShadowing` draws a shadow only
between `establishedMatches()`, the first of them shadowing the rest, and
`debug:layer-assignment` reports typed shadow verdicts and their exact
`ShadowExemption` by the same rule. A later match whose own `exclude:` remains
unanswered is still shown as an additional, contending match; it is not
labelled as an established shadow.
A layer repeating the pattern of one that does not take every class it names
(`MembershipSpec::ownsItsPatterns()` — an `exclude:`, or `match: all` beside
another criterion) is the recipient of what that layer leaves over and is not
shadowed by it; `DuplicatePatternRejector` accepts a repeated pattern by the
same predicate, so a configuration that loads is never failed for it.
`LayerShadowing::verdicts()` judges each established late match against the
first established match. A narrower pattern first and a repeating pattern that
receives the earlier layer's residue are exempt before the universal `**` case.
A universal `**` that owns its patterns reports every later match; other
pattern/pattern pairs retain the existing comparison. A non-pattern side is
`NonPatternPrecedence`, rather than a declaration error. The internal
`LayerShadowVerdict` carries the pair and its `ShadowExemption`, if any; the
inspection shadow list projects the same verdicts.

A later non-pattern layer with its own analysed classes and observed precedence
losses emits `architecture.layer-overlap` at `info`, one occurrence per pair,
with the class count and bounded examples. A fully lost layer instead reports
`unreachable-layer` with the named earlier layers. Late pattern layers, including
a catch-all, never emit overlap, but their complete precedence losses still
explain an unreachable layer. An unanswered exclusion establishes no shadow
pair. The walk also retains display names for symbols removed by a layer's own
exclude, so an empty layer can name that cause without reparsing canonical keys.

`architecture.doubted-assignment` names every layer a contest keeps out of
`unreachable-layer`: those that could not answer, and — from the walk's
`ownsIfExcluded` column — those that would own a symbol if an unanswered
`exclude:` in front of them removed it.

Three declarations that used to be accepted are now refused at config load,
because there is no correct silent reading of any of them. A template layer may
not declare `suffix`, `attributes`, `member_attributes`, `implements` or `extends` under
`match: any`: only `patterns` carries capture variables, so the criterion would
be copied into every expanded instance as one project-wide net and the instance
that wins a class would be decided by binding-value order. A non-`ignore`
`coverage-gap:` requires at least one `layers:` entry, because with no layers
every class is outside every layer while the walk short-circuits and the run
exits 0 — the strictest setting producing the quietest outcome. And an `attributes`,
`member_attributes`, `implements` or `extends` entry that is nothing but `\` passed the
namespace-separator check while naming no class; with the leading separator now
dropped it is refused as such.

`ClassContextFactory` skips a `Dependency` flagged
`describesNestedAnonymousClass` when it builds `extendsMap`, `implementsMap`
and the class/member attribute maps: that edge is a declaration fact about an anonymous class
nested inside the source, not about the source itself, so counting it would
match the enclosing class — including transitively, since membership walks
`extendsMap` as a BFS closure — into a layer whose criteria describe the
nested anonymous class instead (ADR 0071). The dependency the edge still
represents is unaffected; only its reading as a declaration fact about its
recorded source is narrowed.

`Observation/` owns `LayerEvidenceCollector`: one class and dependency-edge
walk per `AnalysisContext`, memoised weakly so nothing survives into the next
run. Its `LayerEvidence` carries forbidden edges, assignment/match/exclusion
tallies, contested symbols, coverage, shadows, own-exclude samples and precedence
losses. Analysed-class assignments remain separate from dependency-edge hits,
because overlap requires at least one class owned by the late layer. `ClassWalkEvidence` and
`EdgeWalkEvidence` carry the two halves of that observation; `ForbiddenEdge`
and `ShadowedClass` retain their exact dependency and criterion facts.
`DiagnosticSampleList` formats bounded samples without policy semantics.
The collector reads three independent enabled gates through `RuleOptionsInterface`
and returns no evidence when all three are off or no layers are declared.
It has no dependency on any of the verdict folders.

`LayerViolation/` owns only forbidden-edge findings. `LayerViolationRule` emits
`architecture.layer-violation` per forbidden edge; its CLI aliases and severity
option still govern that producer alone.

`LayerDeclaration/` owns `LayerDeclarationRule`, its enabled-only
`LayerDeclarationOptions`, and `LayerDeclarationValidator`. The rule emits
`architecture.unmatched-exclude` at fixed warning when an exclusion removed
nothing despite positive matches, and `architecture.doubted-assignment` at
fixed info from the contested population. `LayerOverlapDiagnostic` emits the
third ordinary channel, `architecture.layer-overlap`, at fixed info for partial
non-pattern precedence losses. `UnmatchedTypeDiagnostic` emits `architecture.unmatched-type` at fixed warning
for each authored positive or exclude type this complete run did not meet,
provided the analysed project's Composer install was read. A known neighbour
never conceals a missing type. Occurrences retain source kind, locator and
importer chain, authored key path, document layer index, line and exact FQN;
expanded template copies share the original occurrence, and an empty template
still has its authored types judged. Messages name the configuration writer and
position. An undecidable exclusion cannot be
called inert. These are ordinary occurrence findings a baseline may accept.
The validator belongs to `architecture.layer-declaration` and emits five
configuration-error occurrences: `architecture.coverage-gap`,
`architecture.unreachable-layer`, `architecture.pending-layer-matched`,
`architecture.empty-template`, and `architecture.potential-shadow`.
`DeclaredLayerReachability` builds the first four; `PotentialShadowDiagnostic`
renders the last from observed shadows.

All five validator channels declare `ChannelSelectionRole::FilterExempt`.
An unrelated `--only-rule` filter cannot hide them. Selecting
just one still leaves the other four live; explicitly disabling those four
isolates it. Disabling a diagnostic, its `:project` cell, the declaration
producer or its group still works, as does `enabled: false`. Disabled
layer-violation options do not control declaration findings. The channel names,
levels, descriptions, severities, occurrence shape, documentation page and
15-minute remediation stay unchanged. The declaration producer has no channel
named after itself. Existing channel publication order is retained.

`UnassignedClass/` owns the separate magnitude producer
`architecture.unassigned-class`, its mode options and summary. Preparation
reads `ArchitectureChannels::PRODUCERS` and the final `RuleEnablement::runs`
answer for all three producers. The collector's disjunction permits declaration
judgement when the forbidden-edge and unassigned-class consumers are disabled.

`UnassignedClassLayerRequirement` rejects a merged configuration with an enabled
`warn`/`error` mode and no declared layers, including an explicitly empty list.
It reads final `RuleEnablement::isEnabled`, so an `only` filter does not conceal
the invalid document. `ignore`, a disabled producer and a disabled Architecture
group are accepted. The refusal names the mode's writer and the authored empty
list, or the merged missing key when no layer wrote it. Console invokes the
public requirement after options and enablement are complete and before
discovery; it never reads the private options itself.

`unreachable-layer`, `empty-template`, `unmatched-exclude` and `unmatched-type` infer absence and
require measured `ProjectScopeJudgement::judgesNamespaceClaims()`. Missing
observed PHP, authored/generated removal and an unknown denominator can withhold
that judgement. A whole-root fallback can establish filesystem completeness;
a subset cannot assume it. Coverage, matched pending layers and observed shadows
remain valid on the measured slice. Selection does not manufacture scope.

`unmatched-type` also asks the declaration-absence question and requires the
project Composer install to have been read. Withheld scope or an unread install
produces no finding; after execution Run prints one warning naming the reasons,
only if the exact channel is published and unresolved authored types remain.
Rules and preparation do not log, queue messages or alter the execution result.
The policy's query reads already prepared state and fails before preparation.

Spelling suggestions leave matching case-sensitive. Named types use observed
full-name spelling, with exactly placed installed declarations as an additional
source. `unreachable-layer` and `unmatched-exclude` retain their findings and add
these hints as well as pattern hints. The existing capture-pattern compiler
projects only wildcard-free inclusive subtrees and strict trailing `\**`
subtrees. The observed prefix index replaces only that literal prefix; universal,
mid-segment wildcard, `?` and capture patterns receive no suggestion. No second
pattern parser or PHP grammar is involved.

A layer declared `pending: true` — reserved for code not
written yet — is exempt from `architecture.unreachable-layer` and is reported
by `architecture.pending-layer-matched` once its criteria match, which the
matched tally sees even when a broader layer wins every assignment.
The Console debug command invokes the inspector contract over the same collected
graph and class universe.

## Definition of Done

- Keep public consumers on the declared contracts; do not import an internal
  Architecture zone from another owner.
- Preserve independent reset semantics across sequential runs and zero work
  when layer policy is disabled.
- Update this README, the manifest inventory, topology tests, and exact
  generated projection whenever the leaf surface or zone DAG changes.


## Declared options and preparation

`LayerViolationOptions`, `LayerDeclarationOptions` and `UnassignedClassOptions`
use `fromResolved` and owner-declared forms. Framework `enabled` is legal for all three producers. Unassigned
mode independently determines reportability: false+warn is lawful and off,
explicit true+ignore refuses, and warn/error activates an otherwise enabled
producer. Retired severity keys refuse through their declared replacement hint.

Preparation reads final `RuleEnablement::runs` for each producer. The shared
layer evidence walk is needed when any lawful producer runs; it must not gate
one producer through a sibling's options. Reset, no-layer short-circuiting, the captured project universe and all layer assignment judgments are unchanged.
DoD retains three-producer preparation, no work for muted/off producers and
full authored provenance of malformed options.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
