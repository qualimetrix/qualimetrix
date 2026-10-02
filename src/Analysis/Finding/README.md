# Finding

The Finding capability owns analysis-rule vocabulary, rule execution, rule configuration, emitted finding values, channel declarations, and pre-projection filtering primitives.

## Structure

```text
Finding/
├── Contract/             # Published metadata, configuration, finding, channel, and filter contracts
│   ├── Configuration/    # completed carrier and public typed options construction
│   │   ├── FindingConfiguration.php
│   │   └── RuleOptionsBuild.php
│   ├── Selection/        # public authored decisions and name judgement
│   │   ├── SelectionCellAddress.php # declared producer/channel/level/role
│   │   ├── AuthoredCellDecision.php # switch, admission and decisive writers
│   │   ├── CellSwitch.php
│   │   ├── CellAdmission.php
│   │   ├── RuleEnablementResolver.php
│   │   ├── RuleNameJudge.php
│   │   └── StatedEnablement.php
│   ├── ProjectScope/     # measured judgement, doors, channel lists and selector verdicts
│   ├── Control/          # finding control scope vocabulary
│   ├── Filter/           # Ordered finding-filter stages and results
│   ├── Rule/             # Rule authoring contracts
│   │   └── Override/     # numeric validation and authored request
│   │       ├── ThresholdOverrideRequest.php # values, syntax and written axes
│   │       ├── OverrideAxis.php # warning or error
│   │       └── OverrideSyntax.php # shorthand or explicit axes
│   └── Threshold/        # threshold override value
├── Exclusion/            # Private namespace and path exclusion stores, plus the one reader of a producer's configured suppression options
├── Rule/                 # Internal producer and channel implementations
│   └── Override/
│       └── NonNegativeOverrideThresholds.php # shared numeric judgement and spelling
├── RuleConfiguration/    # private RulesSection schema, suppression decoder and invocation stores
│   ├── ProducerOptionsBuild.php # immutable options for one producer
│   ├── OptionActivityResolution.php # per-cell mode and level activity
│   ├── ThresholdBandRefusal.php # winning authored and default halves
│   └── OptionForms/      # private declaration and document projection
│       ├── RuleOptionDefinition.php # scalar, word or compound declaration
│       ├── CompoundRuleOptionForm.php # list/map/union matching and wording
│       ├── CompoundOptionKind.php  # the three compound kinds
│       ├── RuleOptionSchemaProjection.php # the document form of one declaration
│       ├── RuleOptionDeclarations.php # disjoint recognition states
│       └── RuleOptionKeyMetadata.php # bands, shorthands, axes and retirements
├── Selection/            # private authored selection and retired-name advice
│   ├── AuthoredSelection.php # every authored writer and name judgement
│   ├── SelectionSpecificity.php # selector-cell membership and rank
│   ├── SelectionRefusals.php # contradictory or ineffective authored choices
│   ├── SelectionCauses.php # complete decisive ties and source ordering
│   └── EnablementIndex.php # immutable producer/channel cell lookup
├── SuppressionBinding/   # Whether a configured suppression value named anything the run holds
├── RuleExecution.php     # Selects producers, executes them, and returns what happened as a value
├── RuleMaterialization.php # Ordered deferred rules and validators for one snapshot identity
├── FindingPublication.php # Exclusion accounting and channel-selection projection
└── ChannelPresentationView.php # Joins a channel to its description and its producer's docs page
```

`RuleMaterialization` owns lazy instance caches and ordered producer metadata;
`FindingPublication` owns the one execution exclusion ledger. Late publication
uses the same channel selection without mutating the completed ledger.

`RuleExecutionInterface::execute()` returns `RuleExecutionResult` (in `Contract/`)
rather than a bare finding list: `$produced` (everything rules and their
configuration validators produced, before the per-rule exclusion ledger and
per-finding channel selection ran), `$published` (the subset `execute()` used
to return), `$exclusions` (`RuleExclusionStats`, unchanged), and
`$levelActivity` (`LevelActivity`), and `$selection` (`SelectionTrace`). Reporting's
`SuppressionCompositionBuilder` reads `$produced` and `$exclusions` to publish
`--format=suppressed`. Run's outward `AnalysisResult::findings()` composes
execution `$published` with late Inline usage and unmatched-exclude findings;
those late findings never enlarge execution publication or its audit ledger. See
`docs/adr/0037-suppressed-format-and-produced-findings.md`.

The ledger half of the `$produced`/`$published` difference is in `$exclusions`.
The selection half is in `$selection`: retained produced findings removed by
channel selection carry their deciding statement. Reporting accounts for them
under mechanism `selection`. A producer skipped before execution created no
findings; its reason is separate `notRun` metadata, never an invented removal.
Selection has no stale-suppression count because it states what this invocation
asks to publish rather than a premise about accepted code debt.

`SuppressionBinding/` answers a question no rule can: whether a `suppress_paths`
or `suppress_namespaces` value — global or under `rules.<name>` — named any file
this run analysed or any namespace it declared. That is a different zero from
the one `--format=suppressed` publishes: `neverMatched` is built from removals,
so it says "this suppressor removed nothing", which an honest, paid-down
suppression also reaches; binding-zero says "this suppressor named nothing",
which no repaired code can cause. `UnboundSuppressionRule` gives the three
`suppression.unmatched-*` channels their identity, options and place in
`qmx rules`; `UnboundSuppressionAudit` — the namespace's only published type —
builds the findings and passes them through `publishable()` itself, and is
called by `FindingFilterOrchestrator` at the reporting seam, the one place the
configured values and the run's universes are both in hand.
Every value is an explicit `exact`, `subtree`, or `regex` selector mapping;
the application and audit carry the same bound Core pattern, while reports use
its authored `kind:value` identity rather than the rendered PCRE.

The audit consumes the pipeline's single measured `ProjectScopeJudgement`.
Namespace/declaration absence asks `judgesNamespaceClaims()`; path values ask
`judgesExcludeSelectors()`, and rule-ledger namespace values also ask the former.
Report state and a second Console boolean do not substitute for those facts.
Accepted PSR-4 records still locate values independently of report state;
without a usable map namespace values remain unjudged.

`ValueScopeJudgement` also checks each location. A literal under a removed-entry
anchor is not judged. Path/ledger regex is unjudged when path completeness is
withheld or authored removal could hide a match; namespace regex also needs
complete declaration evidence. This conservative rule may leave an unrelated
stale regex unjudged; rerun without the exclusion to settle it. The audit opens
no source or manifest. Reports publish skipped `{channel, option, pattern}`
values even on a `covered` run.

`Contract/ProjectScope` owns the judgement, doors, channel lists and selector
verdict vocabulary. Its closed factory derives source/hidden-directory outcomes
from measured facts; nonempty distinct origin lists are required, and duplicates
are refused. Run supplies facts, and readers ask their own question.
Settled `Removed` and named inaccessible evidence survive withheld selector
completeness. See [ADR 0093](../../../docs/adr/0093-measured-run-scope-and-project-tree-queries.md).

`LevelActivity` records the producer/level cells admitted by the committed
`RuleEnablement`. `RuleExecutionInterface::levelActivity()` reads that immutable
answer without running rules. Rule instances no longer provide a second activity
algorithm or reconstruct selection from their options. The directive audit keeps
the distinction between a level not declared and a declared level switched off.

`RuleExecutionInterface::publication()` returns `ChannelPublication`; its
`publishes(producer, channel, level, addressedProducer)` query reads the same
completed enablement for Baseline cleanup/explain and other identity-only readers.
Channel/level selection, option activity and declared diagnostic roles therefore
do not drift between execution and those readers.

`RuleExecutionInterface` exposes immutable `RuleMetadata`, not concrete instances.
`RuleConfigurationInterface::replace(FindingConfiguration)` publishes resolved
options, final enablement and the invocation channel snapshot. No raw options,
`RuleSelection` DTO or live catalogue is an alternate committed configuration.
`resetRuntimeState()` clears invocation configuration and exclusions before a new
run; configuration must complete preflight before the next replacement.

A rule instance is reused within one resolved options snapshot and replaced
when the snapshot changes. It is executed more than once per run, so it carries
no state between calls: `RuleInterface::analyze()` states the
contract, including which writes through an injected collaborator stay inside
it, and `governance/RuleDeclaration/RuleInstanceStatelessnessTest` refuses a
registered rule or validator with a reassignable or static property, a readonly
property its source writes outside the constructor, or a `static` variable.

`ThresholdAwareOptionsInterface::warningBoundary()` is how a rule's options
name the warning boundary of the channel they configure, returning the number or
`NoConfiguredBoundary::MoreThanOneBoundary` when the class holds several and
nothing in the question says which applied. Options that hold no boundary at all
express that by not implementing the interface. `baseline:explain` reads it
instead of guessing property names; `getSeverity()` witnesses the declaration
only for rules that delegate to it. See
`docs/adr/0038-an-options-class-names-its-own-warning-boundary.md`.

`RuleOptionKeySet` is how an options class states which option keys it answers
for, at the rule's own depth and inside each level slot, instead of the reader
reconstructing that set from constructor reflection plus opt-in interfaces. It
holds four disjoint states — accepted, accepted with detailed owner validation,
answered by the class for its declared refusal, and unknown — declared in the
canonical kebab spelling users type, and
compared after `ConfigKeySpelling::normalize()` on both sides so snake, camel
and kebab stay one key. `RuleOptionsInterface::acceptedOptionKeys()` and
`LevelOptionsInterface::acceptedOptionKeys()` publish it; a hierarchical
options class also names the level options class behind each slot through
`HierarchicalRuleOptionsInterface::levelOptionsClasses()`, because slots of one
rule accept different key sets and the map cannot be derived from parameter
types.

Each accepted key carries its form in the same entry, as a `RuleOptionShape`:
an integer, a number, a boolean, a string, a non-empty string, a list or map of
one of those, a nested block, a union of several, or a closed set of words
(`RuleOptionWordSet`), each optionally accepting an explicit `null`. The closed
set is deliberately narrow: a set whose members exist
only at run time, and a value constrained by a pattern rather than by
membership, stay with the readers that own them instead of being spelled as a
shape. A closed set is declared with `RuleOptionShape::words(wordSet)`;
`RuleOptionWordSet::of()` and `::foldingCase()` carry the sensitive/folding
choice on the set itself, matching the reader's own comparison. The shape
publishes its nullable `words` property for declaration/reader agreement.
A declaration on the wrong side of that split either
refuses a spelling its reader would honour or accepts one its reader then
drops without a word. Three readers inside `rules:` fold case and declare
`words(RuleOptionWordSet::foldingCase(...))`: `annotation.directive`'s `unused-directive-severity`,
`architecture.unassigned-class`'s `mode`, and `architecture.layer-violation`'s
`severity`. The form lives *inside* the key set rather than beside it,
so a key cannot be admitted by one declaration and shaped by another; it is
derived from what the reading code does with the value — the cast's target, the
guard's predicate — not from what the key is called, which is what the removed
`validateNumericFields()` judged by. The words a refusal uses for a form, and
the words it uses for the form that was actually written, are one vocabulary:
`RuleOptionValueForm`. A number carries its range as well as its type:
`integer()` and `number()` are never negative, because every such option is a
count or a boundary on a measurement that is never negative and a negative
boundary inverts the rule rather than tightening it; `signedNumber()` keeps
both signs for a boundary on a user-written computed-metric formula. The range
lives on the form, so the key walk and the `threshold` shorthand unfolding ask
one question, and a value out of range is refused with the value itself
(`got -1`). A hierarchical rule does not restate its level slots
here: `RuleOptionKeySet::withLevelSlots()` takes them from
`levelOptionsClasses()`, which stays the single source of a slot's existence
and form.

The private `OptionForms` collaborators own compound matching and schema
projection. `RuleOptionShape` keeps the declaration DSL and its narrow
`asNodeSchema()` operation; it does not import compound methods through a
trait. `RuleOptionKeySet` combines the recognition declaration and its metadata
without duplicating either. Root and level schema projection retain their
distinct meaning without a mode flag.

`RuleOptionsInterface::acceptedOptionKeys()` and each hierarchical
`levelOptionsClasses()` are the owner declarations. `RuleOptionSurface` combines
those declarations with framework-owned `enabled` and suppression keys, and
provides the same accepted root/level sets to the document schema and listing.
The constructor is not another dictionary. `RulesSection` is registered for
`rules`, `only_rules` and `disabled_rules`; no undeclared raw rule subtree remains.

The named external operations are published by
`Contract\Configuration\RuleOptionsBuild` and
`Contract\Selection\{RuleEnablementResolver,RuleNameJudge,StatedEnablement}`.
Console preflight consumes Build/Resolver, RulesCommand consumes Resolver,
CliOptionsParser consumes Judge, and DI composes the same concrete services.
The returned StatedEnablement exposes decisions, diagnostics and the only filter.
Build takes only RuleExecutionInterface; its suppression decoder remains private,
not a constructor dependency exposed to consumers.

`RuleEnablementResolver::decide()` reads every authored enabled/disabled writer
and the effective only filter against the invocation's immutable channel universe.
`RuleOptionsBuild::build()` constructs every producer's options through
`fromResolved(ResolvedRuleOptionValues)`, including inactive producers, and
projects typed suppression values. `conclude()` adds each cell's option activity,
refuses contradictory, empty, dead, outside-filter or explicitly muted enables,
and returns the single final `RuleEnablement`. Configuration publishers commit
that completed carrier atomically; lazy rule execution never parses raw arrays.

Forms are expanded in the layer that wrote them, before merging. `threshold`
and its graduated pair are mutually exclusive in one band in one layer. A top
hierarchical shorthand conflicts with an explicit write to the same expanded
leaf in that layer; independent leaves such as level enabled are preserved.
Different layers merge their expanded leaves. Empty rule/level maps and null
write nothing. The boolean rule form changes enabled only and preserves lower
options. A reset without enumerating defaults is not expressed by this language.

`ResolvedRuleOptionValues` exposes the declared values and their authored history.
Owners perform their numeric judgement on effective bands; a refusal retains
full rules/producer/key paths, the real contributing sources and any default half.
No winner masks malformed lower-layer input. Existing academic algorithms and
numeric defaults are independent of these configuration semantics.

The threshold completeness guard checks judge-call counts per path and membership
of named band references. It does not establish a bijection between distinct bands
and calls: a repeated known band can satisfy count/membership while another band
is not independently witnessed. Owner builder differential/drift regressions
observe the resulting behaviour; documentation must not promise exactly one call
per distinct band from that guard alone.

Namespace-channel exclusions use `ChannelLevelAddressing` with the producer's
actual channels and required Namespace level. One channel must witness selector
membership, producer membership and declared level simultaneously. Unknown and
retired keys are refused through the declared vocabulary with one replacement
hint, not by a second raw dictionary walk.

`RuleEnablement` distinguishes execution (`runs`) from finding publication
(`publishes`). Declaration roles are `Selectable`, `FilterExempt` and
`FollowsAddressedRule`; a role is not an implicit exact enable. Produced findings
removed by the final selection are recorded separately from producers that never
ran. Decisive tied writers remain attached to the decision, so listings and audit
publishers do not reconstruct provenance from displayed text.
`EnablementDecision` is constructed from a `SelectionCellAddress`, an
`AuthoredCellDecision` and `OptionActivity`. Its readonly observations remain
available; statement and provenance are projected from the first decisive writer.
`CellSwitch` and `CellAdmission` express the authored choice without independent
boolean constructor switches. Private selection and options builders retain
the same decide/build/conclude publication boundary.

`ControlScope` and `ThresholdOverride` are Finding-owned vocabulary. Inline
produces them from source annotations, Run transports them, and Finding applies
them while selecting effective rule thresholds.

NonNegativeOverrideThresholds owns Warning-first/Error-second non-negativity
and diagnostic number spelling. WarningOnlyValidator asks only about its
Warning before refusing an authored Error; pair strategies judge both values
before their own ordering constraint.

Rule-specific override validators receive one ThresholdOverrideRequest. Its
constructor admits equal non-null shorthand or distinct explicit axes matching
the non-null values. The consumer skips validation when no override exists;
malformed authored input remains a diagnostic. Warning-only validation refuses
actually authored Error, while an implicit equal shorthand stays lawful.

Infrastructure composes these internals through `FindingConfigurator`. Rule discovery and container construction remain Infrastructure concerns.

Finding owns the channel name space as a contract and not as an implementation.
`ChannelUniverseInterface` composes three narrow views —
`ChannelDeclarationRegistryInterface` (what a channel declares),
`RuleChannelRegistryInterface` (what a producer emits) and
`ChannelIdentityInterface` (which names exist, what they belong to, what `X.*`
expands to) — and Infrastructure supplies the single instance behind them.
Matching stays string comparison in `NameSelector`, the one selector grammar
there is now that a channel is one name; it does not consult the universe, and
the universe validates and resolves. `ChannelDeclaration` carries `direction`
(present only for a `magnitude` producer's channel), `levels`,
`configurationError`, `description`, the selection role and warning-boundary
eligibility. These are channel facts, not another producer option store.

`description` is the channel's own display text, declared with `describedAs()`.
The channel named after its producer declares none — the producer's
`getDescription()` describes it — and every other channel must declare one:
`architecture.doubted-assignment` is not a forbidden layer dependency, so the
producer's text published under its name would describe the wrong finding.
`ChannelDeclarationCompilerPass` refuses the container build on either
violation, for rules and validators alike.

`ChannelShape` (ADR 0031) is a producer property, not a channel one:
`RuleInterface::shape()` and `ConfigurationValidatorInterface::shape()` answer
it once per producer, read by a plain static call the same way
`getOptionsClass()` already is. The computed-metric family is why direction
stayed on the channel instead of moving with shape — its per-dimension direction
comes from each `ComputedMetricDefinition`'s own `inverted` flag at run time, so
one producer answers both `higher` and `lower` depending on the channel, while
its shape is uniformly `magnitude`. `ChannelDeclarationCompilerPass` checks two
things registry assembly alone can: that a producer's declared shape agrees
with whether its own channels carry a direction, and that a validator agrees
with the rule whose name it borrows.

`isConfigurationError()`: whether the channel's findings report a mistake in the
configuration rather than debt in the code — such a finding is refused by every
baseline path and fails the run without consulting `fail_on`. **It is not
authored.** `ConfigurationValidatorInterface` is the second kind of finding
producer, and a channel is a configuration error exactly when a validator
declared it. `ChannelDeclaration`'s constructor is private, its two factories
both yield `false`, and `asConfigurationError()` is applied in one place — the
channel-registry assembly in `ChannelDeclarationCompilerPass`, where the
declaring type is still known. `ConfigurationErrorClassificationTopologyTest`
counts that place, and pins that no other production file even names the wither, so
an indirect call cannot hide from the count. Today the five layer-declaration verdicts and the three
inline-directive errors carry it.

A validator is not free-standing: `producerRuleName()` names the rule it belongs
to, and that name is what registers its channels, what `--disable-rule`,
`only_rules`, `suppress_paths` and `suppress_namespaces` address, what resolves its
documentation page and remediation estimate, and whose options —
`enabled` included — it answers to. `RuleExecution` runs it in that rule's slot,
so its findings keep their position in every report that does not sort, and
refuses a finding on a channel the validator does not declare.

`levels`: the `SymbolLevel`s the channel reports at, declared in full and never
empty. It governs what the registry accepts. Emission is a separate path — a
rule builds its finding's subject and the level follows from that — so the two
can disagree, and `ChannelLevelDeclarationDriftTest` runs the external corpus to
find out whether they have.

Three artefacts have to agree about a level, and each pair is compared by a
test rather than by convention: the channel **name** against the channel's
**declaration** (`ChannelLevelAssemblyTopologyTest` — no declared code carries a
level at all, and no level segment may be written as a literal anywhere in
`src/`; the detector is held against a retired level-bearing name so an empty
offender list cannot mean "it stopped recognising anything"),
the declaration against what the product is **observed** emitting
(`ChannelLevelDeclarationDriftTest`), and the declaration against the tracked
fixture (`ChannelDeclarationFixtureDriftTest`). Finding neither resolves computed
definitions nor retains Infrastructure-owned definition state.

`ChannelPresentationInterface` (`presentationFor()` → `ChannelPresentation`)
joins `ChannelIdentityInterface::producerOf()` with the channel's declared
`description`, falling back to the producer's own `RuleMetadata` description
for the channel named after it, and with the producer's declared documentation
page —
`ChannelPresentationView` is the composing service, a small run-time join
rather than a fourth view on the universe (rule *instances* do not exist when
the universe is assembled). It cannot depend on `ComputedMetricDefinition` to
prefer a configured `computed.*`/`health.*` channel's own description without
closing a dependency cycle back onto this capability; that preference is
layered on by `Infrastructure\Rule\ComputedMetricChannelPresentation`, a
decorator registered in front of the public alias.


## Producer and channel identity

The registered metadata set is a set of producers, not a channel count. A producer
may emit several named channels; computed/health channels and their reporting
levels depend on the immutable definition snapshot for this invocation. Never
substitute a static producer list for the channel universe. Level-qualified
selection addresses a declared channel code, not an alias for every producer.

`usesProducerWarningBoundary` defaults to true. A secondary channel may opt out
with `withoutConfiguredWarningBoundary()` without changing its producer's
magnitude shape. The options still own the actual warning number or reason none
exists. LCOM's primary boundary remains 3; `cohesion.unmatched-exclude-method`
uses project magnitude 1 and no configured boundary. Judged catalog membership
is not boundary eligibility: GodClass legitimately has no catalog judge but has
an options boundary.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
