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
│   │                            # the threshold audit's contract and input, and
│   │                            # the verdict vocabulary a report renders:
│   │                            # DirectiveVerdict, DirectiveSite, DirectiveEffect
│   │                            # (effective / overrun / inert / unmeasured) and
│   │                            # DirectiveUnmeasurableReason
│   ├── Suppression/             # suppression value and type
│   ├── Threshold/               # annotation diagnostic value
│   ├── AnnotationSuppressionInterface.php
│   ├── AnnotationSuppressionResult.php
│   ├── SourceControlExtractorInterface.php
│   ├── SourceControls.php       # immutable extraction result
│   ├── SuppressionExtractor.php
│   ├── ThresholdOverrideExtractor.php
│   └── RuleValidatorMapFactory.php
├── Extraction/
│   ├── DeclarationControlBindings.php
│   └── SourceControlExtractor.php
├── Directive/
│   ├── DirectiveAddressability.php # is this directive able to do anything?
│   ├── DirectiveMaskingCoalition.php # which threshold directives of one rule hide one another
│   ├── DirectiveNameHints.php      # "did you mean" by reverse query
│   ├── DirectiveRejection.php
│   ├── DirectiveUsage.php          # what each authored suppression did
│   ├── DirectiveLevels.php         # which levels one directive can silence a channel at
│   ├── StaleDirectiveFinding.php   # the finding that says a directive silenced nothing
│   ├── ThresholdDirectiveAudit.php # what each authored @qmx-threshold did
│   ├── InlineDirectiveOptions.php
│   ├── InlineDirectivePolicy.php   # per-run directive store; delegates usage accounting
│   ├── InlineDirectiveValidator.php # owns the three annotation.* directive errors
│   └── UnusedDirectiveRule.php     # owns annotation.unused-directive; arms usage reporting
├── Suppression/
│   └── SuppressionFilter.php   # internal annotation matching
└── ThresholdOverrideExtractionResult.php
```

## Public contracts

- `SourceControlExtractorInterface` promises source-annotation interpretation
  to the named Run consumer `FileProcessor`. It accepts the parsed AST,
  relative file path, callable measurement facts, and class measurement map.
- `SourceControls` returns the three ordered worker-safe lists: suppressions,
  threshold overrides, and threshold diagnostics. Suppression and diagnostic
  values stay with Inline; Finding owns the shared `ControlScope` and
  `ThresholdOverride` vocabulary that Inline produces and Run transports.
- `SuppressionExtractor` and `ThresholdOverrideExtractor` preserve the exact
  physical and declaration annotation syntax. `RuleValidatorMapFactory`
  supplies rule-specific threshold validation to sequential and worker paths.
- `AnnotationSuppressionInterface` exposes one stateless projection operation
  to Reporting. Its immutable result separates kept and suppressed findings.
- Internal `SuppressionFilter` implements annotation matching without exposing
  its indexes or incremental operations across the owner boundary. Its one
  static entry point answers the per-directive question the indexed path
  cannot: whether *this* directive silenced anything.
- `InlineDirectivePolicyInterface` promises the four `annotation.*` channel
  names and the moments Run needs: `prepare()` before rule execution,
  `auditDirectiveUsage()` and `directiveVerdicts()` after it. Only
  `Analysis\Run\RuleProducerPreparation` calls them, under the same
  producer-enablement rule as every other capability preparation. The last of
  the three is not gated on the owning rule having run: a channel is a rule's
  output, a verdict is what a caller asked for.
- `ThresholdDirectiveAuditInterface` promises the other half of the same
  question to the same consumer, and `ThresholdDirectiveAuditInput` is the
  prepared run it needs to answer: the context the rules already ran against,
  the executor that ran them, and what they produced.

## The directive report

Three of the four channels belong to `InlineDirectiveValidator`, a
`ConfigurationValidatorInterface` rather than a rule — which is what makes them
configuration errors: a name that
addresses nothing (`annotation.unresolved-directive`), a threshold on a rule
that declares no override support (`annotation.unsupported-threshold`), and
values that do not parse or validate (`annotation.invalid-threshold`). None of
them can be accepted by a baseline, and each fails the run without consulting
`fail_on` — they say "I cannot do what you asked", not "your code is poor". The
validator names `annotation.directive` as its producer, so those three are
registered, addressed, excluded and switched off exactly as they were while the
rule declared them, and it answers to that rule's `enabled` option.

**The run state and the usage accounting are two classes, not one.**
`InlineDirectivePolicy` holds what the run carried — the suppressions,
threshold overrides and diagnostics — and answers the authored views over them.
`DirectiveUsage` turns prepared suppressions plus produced findings into
**verdicts**, and the stale findings are one projection of those; it is a pure
function with no run state, and it is injected into the policy rather than built
by it, so the store keeps the three collaborators a store needs and none of the
ones the accounting needs. The port is unchanged:
Run still calls `prepare()`, `directiveVerdicts()` and `auditDirectiveUsage()`
on `InlineDirectivePolicyInterface` — the last two now take the run's
`LevelActivity` beside the findings, because whether a producer was switched
off is a fact the execution recorded rather than one the audit may re-derive
from configuration — and the policy forwards them —
`auditDirectiveUsage()` under its own severity gate, which stays with the state
the owning rule arms.

There is no separate clearing operation. `prepare()` replaces the whole of the
previous run's state, gate included, so a run that carries no directives
prepares an empty set through the same call. The `reset()` that used to exist
had one caller — Run clearing the store when the directive rule was disabled —
and that call silenced something nobody asked to silence: with an empty store
the audit's suppression half reports "this tree carries no annotations" beside
real threshold verdicts. Switching the rule off still silences everything the
rule emits, through the two gates that were always the real ones (the rule arms
its own channel as it runs; the validator executes inside its producer's slot,
which a disabled producer does not get).

**A verdict is not a boolean, and the absence of an answer is not a verdict.**
`DirectiveEffect` has four values. `Effective` and `Inert` are answers;
`Overrun` belongs to the threshold half and is not produced here; `Unmeasured`
means the question could not be asked, and `DirectiveUnmeasurableReason` says
which of the four ways: the producer was switched off (by either mechanism), the
directive was already refused elsewhere, it carries no rule filter, or another
directive of the same rule covers the same subject. Reporting any of those as
`Inert` would tell an author to delete an annotation on the strength of a
question nobody asked — and for the "already refused" family it would answer one
mistake twice, since `annotation.unresolved-directive` has already answered it.

**The verdict is judged on what the rules produced, not on what the report
published.** The two differ by the per-rule exclusion ledger and the per-finding
channel selection, and both are decisions about a *report*: a suppression that
covered a finding the ledger would have dropped anyway did not silence nothing.
`AnalysisPipeline::reportedFindings()` hands the audit `produced` for that
reason.

The fourth channel, `annotation.unused-directive`, stays with `UnusedDirectiveRule`
because it is ordinary debt: a suppression that
addressed something real and matched nothing this run. It defaults below
`Warning`, and its accounting is deliberately narrow — only directives naming
enabled rules, and only files this run analysed. The rule emits nothing itself;
it arms the usage report, which can only be assembled after every rule has run.

**All four channels report once per authored annotation.** The extractor binds
a class docblock to the class and to every declaration inside it, so a single
typo on a forty-method class would otherwise print forty-one identical
findings — and a configuration error ends the run past `fail_on`, which makes
that exactly the report a reader learns to skip. The identity of a directive is
its file, line, form and authored text; the finding's subject is the **file**,
because that is where the annotation is written and because a declaration
subject would carry a byte offset that moves on every unrelated edit above it.

Validation happens **after configuration has resolved**, because a channel may
exist only because the run defines a computed metric. Whether a rule is
*enabled* is not part of that: enablement filters execution, it does not decide
which names exist.

`Extraction\\DeclarationControlBindings` is internal. It maps collected
declaration facts onto AST nodes while extracting controls and never crosses
the Run boundary or the serialized worker payload.
`Extraction\\SourceControlExtractor` is the private implementation of the Run
port and returns the immutable `SourceControls` result. Its class-level
`health.cohesion` exception records metric inapplicability: the one public
operation uses both collaborators, while its private static methods only
decompose that operation; TCC therefore has no public method pair to compare.

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
the narrowing. On this project's own `src`, narrowing is the difference between
eight rule executions and thirty-three whole ones.

**One removal is one annotation, not one binding.** A class docblock
materialises on the class and on every declaration inside it; removing the
first of those and leaving the rest would report an annotation still in force
as inert.

**The fingerprint is the whole finding, split in two.** `threshold` and the
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

**Where no boundary is published, the question cannot be asked.** Nine of the
twenty-seven rule files put no boundary in their findings and four of those
accept overrides. On those, a boundary the measured value had already passed
leaves the fingerprint unchanged, so the verdict is `Inert` and
`DirectiveVerdict::$boundaryObservable` is false — read off the run's own
findings rather than off a list of rule names, which would drift from the tree
in silence.

**Coalitions are refusals, not verdicts.** `DirectiveMaskingCoalition` answers
which directives of one leave-one-out sweep hide one another; `ThresholdDirectiveAudit`
is its only caller and is the one that owns the prepared run, so the
counterfactual operation crosses that boundary as an injected closure rather
than the coalition class seeing the run itself. Directives of one rule covering the
same subject mask each other: removing any one alone changes nothing, although
removing them all changes the run. Overlap only makes that possible, so the
answer is bought with two more executions, and the question is differential —
the run without this directive's maskers against the run without them and it.
What the neighbours do cancels between the two sides, which is what keeps a dead
annotation beside a live one from being refused on the live one's account. Where
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
object. Measured on this project's own `src`: thirty-one authored directives,
thirty-three executions, both controls reproducing.

What the audit does **not** measure is a directive's effect on the parsing of
itself. `InlineDirectiveValidator` reads the policy's own copy of the override
map, which no counterfactual touches, so its diagnostics are identical on every
pass — and the `annotation.*` channels have already answered for malformed,
unresolvable and unsupported annotations.
