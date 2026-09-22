# Finding

The Finding capability owns analysis-rule vocabulary, rule execution, rule configuration, emitted finding values, channel declarations, and pre-projection filtering primitives.

## Structure

```text
Finding/
├── Contract/             # Published metadata, configuration, finding, channel, and filter contracts
│   ├── Control/          # finding control scope vocabulary
│   ├── Filter/           # Ordered finding-filter stages and results
│   ├── Rule/             # Rule authoring contracts
│   └── Threshold/        # threshold override value
├── Configuration/        # FindingConfigurationResolver — merges `rules:` across ordered configuration layers
├── Exclusion/            # Private namespace and path exclusion stores, plus the one reader of a producer's configured suppression options
├── Rule/                 # Internal producer and channel implementations
├── RuleConfiguration/    # Option parsing, selector decoding, key recognition, normalization, and per-run state
├── SuppressionBinding/   # Whether a configured suppression value named anything the run holds
├── RuleExecution.php     # Selects producers, executes them, and returns what happened as a value
└── ChannelPresentationView.php # Joins a channel's producer to that rule's own description and docs page
```

`RuleExecutionInterface::execute()` returns `RuleExecutionResult` (in `Contract/`)
rather than a bare finding list: `$produced` (everything rules and their
configuration validators produced, before the per-rule exclusion ledger and
per-finding channel selection ran), `$published` (the subset `execute()` used
to return), `$exclusions` (`RuleExclusionStats`, unchanged), and
`$levelActivity` (`LevelActivity`). Reporting's
`SuppressionCompositionBuilder` reads `$produced` and `$exclusions` to publish
`--format=suppressed`; every other caller keeps reading `$published`. See
`docs/adr/0037-suppressed-format-and-produced-findings.md`.

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
configured values and the run's universes are both in hand. The coverage
Every value is an explicit `exact`, `subtree`, or `regex` selector mapping;
the application and audit carry the same bound Core pattern, while reports use
its authored `kind:value` identity rather than the rendered PCRE.

The coverage
precondition is asked at that call site, so a run narrowed below the project's
production autoload roots — or one whose manifest declares no readable
production autoload — produces nothing here. `ValueScopeJudgement` asks the
second half of that question, per value: it places a value's subject through
the run's paths and the manifest's PSR-4 map, and a value naming a place this
run never analysed is not judged at all. It is built at the same call site from
the run's shape, so this namespace does not read `composer.json` itself.

`LevelActivity` records which producer/level pairs this configuration let run,
asked of the rules themselves during execution and published beside the
findings it explains (`RuleExecutionInterface::levelActivity()` answers the same
question without executing anything, because it is a fact about configuration).
A rule answers for itself through `RuleInterface::levelActivity()`, whose
default on `AbstractRule` reads its own channel declarations and its options —
per level when they are hierarchical; `ComputedMetricRule` overrides it for the
producers it hosts without a class of their own. `RuleExecution` completes the
record for channels a configuration validator declares in its producer's slot.

The directive audit reads this record instead of re-deriving enablement from
the merged configuration: three answers, not two, because a producer that does
not declare a level at all is a different fact from one switched off there.

`RuleExecutionInterface` exposes immutable `RuleMetadata`; concrete rule instances never cross the capability boundary. `RuleConfigurationInterface` is the only external mutation/query surface for per-run options, selection, and exclusions. Runtime reset clears CLI selection and exclusion state before every run.

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
holds three disjoint states — accepted, answered by the class itself (so that a
class such as `UnassignedClassOptions` keeps refusing `enabled` in its own
words), and unknown — declared in the canonical kebab spelling users type, and
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
set is deliberately narrow and its docblock says why: a set whose members exist
only at run time, and a value constrained by a pattern rather than by
membership, stay with the readers that own them instead of being spelled as a
shape. A closed set is declared case-sensitive (`RuleOptionShape::oneOf()`) or
case-folding (`RuleOptionShape::oneOfIgnoringCase()`), matching whichever way
the reader behind it compares — `RuleOptionWordSet::of()` and
`::foldingCase()` carry that choice on the set itself rather than as a policy
of the shape class, and a declaration on the wrong side of that split either
refuses a spelling its reader would honour or accepts one its reader then
drops without a word. Three readers inside `rules:` fold case and declare
`oneOfIgnoringCase()`: `annotation.directive`'s `unused-directive-severity`,
`architecture.unassigned-class`'s `mode`, and `architecture.layer-violation`'s
`severity`. The form lives *inside* the key set rather than beside it,
so a key cannot be admitted by one declaration and shaped by another; it is
derived from what the reading code does with the value — the cast's target, the
guard's predicate — not from what the key is called, which is what the removed
`validateNumericFields()` judged by. The words a refusal uses for a form, and
the words it uses for the form that was actually written, are one vocabulary:
`RuleOptionValueForm`. A hierarchical rule does not restate its level slots
here: `RuleOptionKeySet::withLevelSlots()` takes them from
`levelOptionsClasses()`, which stays the single source of a slot's existence
and form.

`RuleOptionKeyRecognition` compares what the user wrote against those
declarations at both depths and refuses — `ConfigurationRefusal`
(`Analysis\Configuration\Contract\Refusal`), which `check` prints as
`Configuration error: …` and exits 3 on, uniformly across commands — the
first key in document order that nothing at its depth answers for.
`RuleOptionsFactory` calls it on the user-written config, after the
framework keys are taken out and before
`fromArray()`. A key the class declared as answered by itself passes through
untouched, so `fromArray()` may refuse it in its own words; the three framework
keys (`suppress-paths`, `suppress-namespaces`, `suppress-namespace-channels`)
are legal at the rule's own depth only and are declared by no options class.
`FrameworkOptionKeys` (`Contract\Rule`) is where those three are named, so that
a refusal for a mistyped one can name the spelling that works and the `rules`
listing can advertise them. `RuleOptionSurface` beside it answers the question
both of those sides ask — which declaration answers at which depth, and what may
legally stand there — and the walk addresses through it rather than deriving the
pair itself.
`RuleOptionRefusalWording` holds the sentences, beside `ChannelLevelRefusalWording`
and for the same reason. Every sentence prints the key exactly as the walk
received it: each door folds separators before the factory exists, so there is
no authored spelling left to quote and none is guessed at.

A rule's `threshold` shorthand and the graduated `warning`/`error` pair it
stands for are two spellings of one concept, and each configuration layer
(preset, config file, CLI) is free to pick either. `RuleOptionThresholdShorthand::unfold()`
rewrites one layer's `threshold` key, for every group `RuleThresholdKeyGroupRegistry`
declares at that rule/path, into the graduated pair before that layer is
merged with any other — called on both sides of a merge by both merge sites,
`RuleOptionsFactory` (config file ↔ CLI) and `Configuration/FindingConfigurationResolver`
(config-file layers among themselves), and at every nesting level. Unfolding
both layers before they meet removes the base/overlay asymmetry a prior design
(the deleted `RuleOptionThresholdModeResolver`) could not express: that design
evicted the lower layer's keys of whichever mode the higher layer switched
away from, which silently dropped a value the lower layer wrote instead of
merely leaving it unrewritten. `RuleOptionValueWrittenness::isWritten()` is the
one place both merge sites (and `ThresholdParser`) ask whether a value counts
as written — `null` (an author's `~`) does not, so an overlay's `~` selects no
mode and leaves the layer below it alone rather than erasing it. See
`docs/adr/0058-a-layers-value-survives-the-layers-above-it.md`.

`ControlScope` and `ThresholdOverride` are Finding-owned vocabulary. Inline
produces them from source annotations, Run transports them, and Finding applies
them while selecting effective rule thresholds.

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
(present only for a `magnitude` producer's channel), `levels`, and
`configurationError`.

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
description, documentation page and remediation estimate, and whose options —
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
joins `ChannelIdentityInterface::producerOf()` with that producer's own
`RuleMetadata` (description) and its declared documentation page —
`ChannelPresentationView` is the composing service, a small run-time join
rather than a fourth view on the universe (rule *instances* do not exist when
the universe is assembled). It cannot depend on `ComputedMetricDefinition` to
prefer a configured `computed.*`/`health.*` channel's own description without
closing a dependency cycle back onto this capability; that preference is
layered on by `Infrastructure\Rule\ComputedMetricChannelPresentation`, a
decorator registered in front of the public alias.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
