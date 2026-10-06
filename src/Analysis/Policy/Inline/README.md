# Inline source policy

`Analysis\\Policy\\Inline` owns source annotations: extraction, declaration
binding, threshold validation, annotation suppression, and the report on the
directives themselves. Run parses and measures a file, then calls the
Inline-owned extraction contract once; it owns no annotation policy state.

## Layout

```text
Inline/
├── Contract/
│   ├── Directive/               # the four annotation.* channel names, run state,
│   │                            # the threshold audit's contract and input, the
│   │                            # verdict vocabulary a report renders:
│   │                            # DirectiveVerdict, DirectiveSite, DirectiveEffect
│   │                            # (effective / overrun / inert / unmeasured / refused) and
│   │                            # DirectiveUnmeasurableReason — and the two halves of
│   │                            # what extraction could do with a tag: DeclarationBinding
│   │                            # (bound here), DeclarationReach (whole or lines),
│   │                            # DirectiveRefusal (not carried out), and DirectiveVerdictRefusal
│   ├── Suppression/             # suppression value and type
│   ├── Threshold/               # annotation diagnostic value
│   ├── AnnotationSuppressionInterface.php
│   ├── AnnotationSuppressionResult.php
│   ├── DirectiveObservations.php # run-level suppression and threshold-override maps
│   ├── DocumentationRegions.php # which parts of a comment quote a tag rather
│   │                            # than write one; read by both extractors
│   ├── SourceControlExtractorInterface.php
│   ├── SourceControls.php       # immutable extraction result
│   ├── SuppressionSyntax.php      # authored spelling and arguments without binding
│   ├── SuppressionExtractor.php
│   ├── ThresholdOverrideExtractor.php
│   └── RuleValidatorMapFactory.php
├── Extraction/
│   ├── DeclarationControlBindings.php
│   ├── DeclarationRanges.php      # measured enclosing declaration ranges
│   ├── DeclarationSource.php      # source carrier descriptions and direct anonymous values
│   ├── SourceControlExtractor.php
│   └── UnattachedComments.php
├── Directive/                      # the directive itself: store, addressing, validation
│   ├── Audit/                      # what each authored directive did, both halves
│   │   ├── AuthoredDirectiveGroup.php # one authored @qmx-threshold, its bindings, site and subjects
│   │   ├── DirectiveMaskingCoalition.php # which threshold directives of one rule hide one another
│   │   ├── DirectiveUsage.php      # what each authored suppression did
│   │   ├── DirectiveMeasurability.php # addressed producer and subject-coverage evidence
│   │   ├── ExecutionFingerprint.php # what one rule execution produced, compared as a whole
│   │   ├── MaskingOutcome.php      # what the sweep decided about one group, before it is reported
│   │   ├── StaleDirectiveFinding.php # the finding that says a directive silenced nothing
│   │   ├── ThresholdDirectiveEligibility.php # enabled and executed producer eligibility
│   │   └── ThresholdDirectiveAudit.php # what each authored @qmx-threshold did
│   ├── DirectiveAddressability.php # is this directive able to do anything?
│   ├── DirectiveChannelBan.php     # the channels no directive may address or silence
│   ├── DirectiveLevels.php         # which levels one directive can silence a channel at
│   ├── DirectiveNameHints.php      # "did you mean" by reverse query, incl. metric -> judging channel
│   ├── DirectiveRejection.php
│   ├── InlineDirectiveOptions.php
│   ├── RefusedDirective.php        # one classified site and refusal details
│   ├── RefusedDirectives.php       # shared validator/audit classifier
│   ├── DirectiveRefusalChannel.php # unresolved / unsupported / invalid
│   ├── InlineDirectivePolicy.php   # per-run directive store; delegates usage accounting
│   ├── InlineDirectiveValidator.php # owns the three annotation.* directive errors
│   └── UnusedDirectiveRule.php     # owns annotation.unused-directive; arms usage reporting
├── Threshold/
│   ├── ThresholdOverrideValueParser.php # authored number or axis tokens
│   └── DeclaredOverrideForms.php # every declared level and written axis
├── Suppression/
│   └── SuppressionFilter.php   # internal annotation matching
└── ThresholdOverrideExtractionResult.php
```

## Public contracts

- `SourceControlExtractorInterface` promises source-annotation interpretation
  to the named Run consumer `FileProcessor`. It accepts the parsed AST, the
  exact source bytes it was parsed from, relative file path, callable
  measurement facts, and class measurement map. `FileProcessor` reads the file
  once and hands the same bytes to the parser and to extraction, so an AST from
  the cache and a fresh parse are read against the same text.
- `SourceControls` returns the three ordered worker-safe lists: suppressions,
  threshold overrides, and threshold diagnostics. Suppression and diagnostic
  values stay with Inline; Finding owns the shared `ControlScope` and
  `ThresholdOverride` vocabulary that Inline produces and Run transports.
- `DirectiveObservations` carries the two observed per-file maps in Run's
  outward result, with ordered concatenation when results merge. It contains no
  fabricated diagnostics and does not replace the three-list `SourceControls`
  extraction contract. Run constructs it and Console reads it through
  `AnalysisResult.directives`.
- `SuppressionExtractor` and `ThresholdOverrideExtractor` preserve the exact
  physical and declaration annotation syntax. `RuleValidatorMapFactory`
  supplies rule-specific threshold validation to sequential and worker paths.
  Both read one answer to "which of this comment is prose" —
  `DocumentationRegions` — so a tag quoted as documentation and a tag written
  as a directive cannot be told apart differently by the two.
- `AnnotationSuppressionInterface` exposes one stateless projection operation
  to Reporting. Its immutable result separates kept and suppressed findings.
- Internal `SuppressionFilter` implements annotation matching without exposing
  its indexes or incremental operations across the owner boundary. Its one
  static entry point answers the per-directive question the indexed path
  cannot: whether *this* directive silenced anything.
- `InlineDirectivePolicyInterface` promises the four `annotation.*` channel
  names and the moments Run needs: `prepare()` before rule execution,
  `auditDirectiveUsage()` and `directiveVerdicts()` after it, receiving the same
  measured `SubjectCoverageFacts` as their required final argument. Only
  `Analysis\Run\InlineDirectiveRun` calls them using the same invocation
  policy instance. Authored state is prepared independently of whether its
  reporting rule runs; a channel is a rule's output, a verdict is what a
  caller asked for.

- `ThresholdDirectiveAuditInterface` promises the other half of the same
  question to the same consumer, and `ThresholdDirectiveAuditInput` is the
  prepared run it needs to answer: the context the rules already ran against,
  the executor that ran them, and what they produced.

An otherwise inert suppression is `Unmeasured` with `scope-unmeasured` when its
producer's subject was not covered. A narrowed run cannot prove a run-dependent
declaration unused; an analyzed local member can still be judged. Effective,
refused and disabled-producer verdicts keep their earlier meanings.

ThresholdDirectiveAudit refuses unaddressable directives through RefusedDirectives
before ThresholdDirectiveEligibility checks a directly live producer and its
executed declared levels. Counterfactual audits reuse
the prepared context without recollecting files.

ThresholdOverrideValueParser constructs the Finding-owned typed request, and
DeclaredOverrideForms checks each declared level and its admitted numeric axes.
The extractor retains diagnostic codes, first-refusal order, reason syntax and
worker-safe output. An absent annotation creates no request; malformed authored
text still reports its existing syntax or form diagnostic.

## Authored sites, binding and judgement

The canonical user grammar is
[Inline suppression](../../../../website/docs/usage/baseline.md#inline-suppression).
Both extractors read `DocumentationRegions`: an admitted tag starts its
physical comment line after whitespace/decoration. Midline exact mentions are
refused unless a same-line equal-delimiter backtick span quotes them with no
prose before the tag inside the span. Closed backtick/tilde fences quote whole
regions; unclosed fences preserve refused mentions. Typos at line start are
carried, not silently discarded. Arguments cannot cross the tag's line or use
the comment closer as a missing argument.

`DeclarationControlBindings` has separate `suppressionBindingsFor()` and
`thresholdBindingsFor()`. It accepts measured declarations and direct
anonymous callable values (argument, array value, return, expression, ordinary
assignment or coalesce assignment chains), but does not search wrappers for
the first nested closure. `ClosureNotDirectValue` tells an author to move the
docblock before `function`/`fn`; a construct containing no callable retains
`NoDeclarationToBind`. `UnattachedComments` and the source extractor
associate docblocks throughout the declaration header, including attribute
gaps and the keyword/name gap, without modifying the cached AST.

`DeclarationBinding(subject, scope, reach)` requires Inline's
`DeclarationReach`. `whole(endLine, standsOn)` covers the whole bound
declaration; `lines(start, end, standsOn)` requires a finding location in
the authored file and inside the inclusive line range. All bindings of one
authored node describe the same source construct. A method has whole-callable and class-lines reach; a property
has hook-whole and class-lines reach; a constant or enum case has class-lines
reach. Parameters have callable-lines reach; promoted parameters additionally
have class-lines and any hook-whole reach. A class-like annotation has whole
reach over the class and its measured callable members.

Explicit levels must be both declared by the channel and reachable here.
Member class findings are silenced only inside the member's lines, never on
the class line or a neighbour. Same-line parameters remain indistinguishable
by column. Thresholds keep whole-only bindings: properties with hooks retune
hooks, while plain properties, constants, enum cases and parameters do not
retune their containing class/callable.

`Suppression` requires nonnegative `position`; its `line` names the tag,
and `silencedLine` exists exactly for an accepted next-line control, pointing
after the comment end. `authoredSite()` includes position, form, authored
argument and refusal reason, independent of declaration binding.
`ThresholdDiagnostic` also requires position, and deduplication preserves it.
`DirectiveSite` requires nullable position: suppressions/diagnostics have it,
threshold overrides do not. Consequently identical comments on one line are
separate sites, while same-rule same-line threshold overrides still coalesce.

### Refusals and publication

One shared internal `RefusedDirectives` classifier supplies validator findings
and policy verdicts. `RefusedDirective` and `DirectiveRefusalChannel` remain
internal; `DirectiveVerdictRefusal(channel, message, addressedProducer)` is
the outward carrier. Unknown/binding-refused suppressions and unknown threshold
names are unresolved with no addressed producer. Known non-retunable rules
are unsupported; threshold diagnostics are invalid. Unsupported/invalid
refusals resolve their addressed producer once.

Every read tag yields one site, even when it has multiple bindings or refusal
details. `DirectiveVerdict` permits a nonempty `refusals` list exactly for
`DirectiveEffect::Refused`; every other effect carries an empty list.
The public JSON publishes each refusal as `{channel, message}`, never the
internal address. Refused sites do not also become Unmeasured or unused debt.
Unresolved and unused channels are `Selectable`; unsupported/invalid
threshold diagnostics are `FollowsAddressedRule`. No role fabricates findings
for a producer that did not run.

Three channels belong to `InlineDirectiveValidator`, a
`ConfigurationValidatorInterface`: `annotation.unresolved-directive`,
`annotation.unsupported-threshold` and `annotation.invalid-threshold`.
When published, these configuration errors end `check` regardless of
`fail_on`, and baselines or source suppressions cannot accept them.
Their producer is `annotation.directive`. `UnusedDirectiveRule` owns
ordinary debt `annotation.unused-directive`, defaults to Warning, and arms
usage accounting after rule execution. Explicit
`unused-directive-severity: info` preserves Info.
Top-level path suppression, baseline and git scope can narrow that debt;
namespace suppression does not match its file subject, and the producer's
closed exclusion ledger does not account for findings assembled afterwards.

`DirectiveChannelBan` still forbids addressing `annotation.unused-directive`
or `duplication.clone`. Duplication also declares
`SUPPORTS_THRESHOLD_OVERRIDE = false`, so `@qmx-threshold duplication.clone`
produces the existing `annotation.unsupported-threshold` refusal. Reach and
channel-level admission happen before the ban. Blanket `*` or a bare file directive is not refused: usage judges it
Effective/Inert against the real produced findings, while matching continues
to exclude those banned channels and configuration errors.

There are five effects: Effective, Overrun, Inert, Unmeasured and Refused.
Unmeasured is restricted to a disabled producer or a masked threshold.
`directives` exit 4 for incomplete analysis dominates exit 2 for a publishable
refusal or an Inert verdict with an observable boundary; otherwise it returns 0.
Sites remain visible when annotation publication is disabled; the exit reads
the invocation's final `RuleEnablement` with channel, File level and internal
address, exactly as publication does.

### State and attribution

`InlineDirectivePolicy::prepare()` replaces the previous run's complete
authored state and severity gate; no separate reset exists.
`DirectiveUsage` is injected pure accounting over prepared suppressions,
produced findings and the recorded `LevelActivity`. Usage judges what rules
produced, before report exclusions and selection, rather than re-deriving
producer activity from configuration.
`DirectiveMeasurability` judges addressed producer and subject coverage before
that accounting. An enabled but uncovered channel takes precedence over a
disabled producer; unknown coverage cannot establish an inert directive.

`AnnotationSuppressionResult` carries kept/suppressed findings and the first
actually applied `DirectiveSite` for each suppressed finding in match order.
`suppressorOf(Finding)` refuses a finding outside that suppressed set.
Reporting receives that same result through `FindingProjectionResult`;
`SuppressionCompositionBuilder` consumes it instead of running another
directive matcher. Its public suppressor remains `file:line`, so two
physical sites on one line share that label.

`RuleValidatorMapFactory` builds the same map for sequential and real worker
collection and refuses a missing class with its FQCN; a real rule declaring no
threshold support is still skipped normally. It receives Finding's
`RuleOptionDocumentFormsInterface` to project declared threshold shapes. Main
container compilation and worker bootstrap supply the same private Finding
implementation, so accepted forms and refusal wording share one interpretation.
Extraction internals never cross Run or the serialized worker payload.

## Change recipe

When changing an inline annotation or its wire value:

1. update the Inline contract/value and its subject-owned unit tests;
2. preserve declaration-collision, physical-control, and diagnostic ordering;
3. exercise both `FileProcessor` and worker serialization round trips;
4. prove sequential and real parallel collection return identical controls;
5. update the manifest and generated architecture inventory in the publication
   package; never expose `Extraction` internals to Run.

## Definition of Done

- Run imports only Inline contracts and stores no policy state.
- Source controls survive PHP and igbinary worker round trips unchanged.
- Two sequential runs cannot retain a previous suppression or threshold set.
- Inline has no dependency on Baseline or Reporting.


## Typed directive construction and admission

`InlineDirectiveOptions::fromResolved` reads declared framework enabled and
owner severity values from the completed snapshot. The validator constructor is
`InlineDirectiveValidator(policy, identity)`; it no longer accepts unused options.
RuleExecution applies the producer activity gate before invocation. Integer
threshold boundaries refuse fractional overrides as annotation.invalid-threshold;
non-integer owners keep their own numeric grammar.

Channel roles govern publication after selection. Unresolved and unused directives
are directly selectable; unsupported/invalid threshold diagnostics follow the addressed
rule. An explicit annotation.directive
disable still stops the producer. These roles do not invent findings for a
producer that never ran. Directive text/JSON retain every tied decisive disabling
text in resolver order, deduplicate repeated cells and omit writes canceled by a
later enable; selection.disabled remains a string list.

Fingerprint identity excludes the internal addressedProducer used for admission.
Its separate invariance observation complements public identity/boundary field
coverage; the numeric/message boundary split and audit lifecycle do not change.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.

## The threshold half

A `@qmx-threshold` publishes nothing about itself. No rule reports the boundary
it decided with, and the rule layer has no single notion of a boundary to ask
about, so the only observable a threshold directive has is **the difference it
makes**. `ThresholdDirectiveAudit` removes one authored directive at a time and
executes the rules again over the context the run already prepared, comparing
what the two executions produced.

**The counterfactual executes one rule, not the whole layer, by default.** A
`@qmx-threshold` addresses exactly one rule by exact name, so only that rule's
producer needs re-executing (`DirectiveSweepScope::Narrow`, the `bin/qmx
directives` default); `--sweep=full` re-executes every enabled rule for the
same verdicts. `full` is not a slower fallback — it is the control that
measures, rather than assumes, that removing a directive of one rule cannot
move another rule's findings: the two scopes are run over the same tree and
compared verdict for verdict, and a disagreement between them is a defect in
the narrowing. Narrowing avoids re-executing unrelated enabled rules for each threshold site.

That comparison is `composer directives:narrow-control`, and it runs three
times. Over `tests/Analysis/Policy/Inline/Fixtures/NarrowControl`, whose
directives are seeded so that every verdict and every reason for refusing one
occurs at least once — the run demands that and refuses the population
otherwise; over that fixture's `Silenced/` half, where every addressed rule was
silenced by its own directive; and over `src/`, the real population. `src/`
alone would not do: every verdict in it is `Effective`, so an agreement there
reddens for a defect that kills verdicts and stays silent for one that revives
them, which is the direction it watches as normal. Measured: collapsing the
narrowed baseline onto the full one flips four of the fixture's eight verdicts
and none of `src/`'s.

`Silenced/` is a second window on the same fixture rather than a second fixture,
and it exists because the floor and the discrimination pull apart. The floor
demands an `Overrun`; an `Overrun` demands a finding the addressed rule actually
produced; and a produced finding is exactly what makes
`assertNarrowingChangedNothing()` refuse a sweep narrowed to the wrong producer
before any verdict is reached. Measured: that defect leaves the whole fixture as
"not comparable" and the `Silenced/` half as three disagreeing verdicts.

**One removal is one annotation, not one binding.** A class docblock
materialises on the class and on every declaration inside it; removing the
first of those and leaving the rest would report an annotation still in force
as inert.

**The fingerprint is the public finding, split in two.** `threshold` and the
prose that quotes it — `message` and `recommendation` — are the boundary a
finding names; every other field is what the finding *is*. When two runs differ only in the boundary half, the directive
applied and the finding fired regardless — `Overrun`, a promise made and not
kept, which is not the same as an annotation that does nothing. The message
belongs to that half because several rules spell the boundary into their prose
instead of into the field, and so does the recommendation: `ComplexityRule`
writes the threshold into the advice as well as into the message, and counting
that as identity would turn every overrun on such a rule into `Effective`. What no field of the key names is invisible to the
audit, so the split is checked against `Finding`'s constructor by reflection and
each field is moved on its own in a test: a field added later cannot become a
difference the audit silently ignores.

`Overrun` names the common case rather than every one. A directive that
*tightens* a boundary produces the same shape of difference, and the rule layer
has no notion of which direction is stricter — instability is worse when higher,
cohesion when lower — so what the verdict states exactly is "applied, and
nothing moved except the boundary it printed".

**Where no boundary is published, the question cannot be asked.** Some rules accept overrides without publishing a boundary in their findings.
On those, a boundary the measured value had already passed
leaves the fingerprint unchanged, so the verdict is `Inert` and
`DirectiveVerdict::$boundaryObservable` is false — read off the run's own
findings rather than off a list of rule names, which would drift from the tree
in silence.

**Coalitions withhold a measurement.** `DirectiveMaskingCoalition` answers
which directives of one leave-one-out sweep hide one another; `ThresholdDirectiveAudit`
is its only caller and is the one that owns the prepared run, so the
counterfactual operation crosses that boundary as an injected closure rather
than the coalition class seeing the run itself. Directives of one rule covering the
same subject mask each other: removing any one alone changes nothing, although
removing them all changes the run. Overlap only makes that possible, so the
answer is bought with two more executions, and the question is differential —
the run without this directive's maskers against the run without them and it.
What the neighbours do cancels between the two sides, which is what keeps a dead
annotation beside a live one from being classified as masked on the live one's
account. Where
the rule reports on that subject under no directive at all, both sides agree and
every directive there is inert for real.

The neighbour the verdict names is measured too, one at a time: put back on its
own, the one that still makes this directive's removal invisible is the one
named, so a report cannot call a directive a masker on the same page it calls
that directive dead. Only joint hiding, where no single neighbour does it alone,
leaves the name positional.

The unit is every masker and not the first, because specificity has four steps:
a class docblock, a property docblock and a property hook's docblock can all
retune one subject, and then no single removal and no pair moves the outcome
while the whole set does. It is also one hop and not a closure: a directive can
only hide what it covers.

**The method's own assumption is controlled, not assumed.** A sweep begins and
ends with the full override set in place, and both control passes must
reproduce the run exactly. A drift between them is shared state in the rules,
which invalidates every verdict rather than any one directive, so the audit
throws instead of answering, and it runs both controls through the same
context-rebuilding path the counterfactuals use rather than against the original
object. The control executions are part of the counterfactual operation, independent
of the current number of directives.

What the counterfactual audit does **not** measure is a directive's effect on
the parsing of itself. `InlineDirectiveValidator` reads the policy's own copy of the override
map, which no counterfactual touches, so its diagnostics are identical on every
pass — and the `annotation.*` channels have already answered for malformed,
unresolvable and unsupported annotations.
