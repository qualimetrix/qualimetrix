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
│   │                            # (effective / overrun / inert / unmeasured) and
│   │                            # DirectiveUnmeasurableReason — and the two halves of
│   │                            # what extraction could do with a tag: DeclarationBinding
│   │                            # (bound here) and DirectiveRefusal (not carried out)
│   ├── Suppression/             # suppression value and type
│   ├── Threshold/               # annotation diagnostic value
│   ├── AnnotationSuppressionInterface.php
│   ├── AnnotationSuppressionResult.php
│   ├── DocumentationRegions.php # which parts of a comment quote a tag rather
│   │                            # than write one; read by both extractors
│   ├── SourceControlExtractorInterface.php
│   ├── SourceControls.php       # immutable extraction result
│   ├── SuppressionExtractor.php
│   ├── ThresholdOverrideExtractor.php
│   └── RuleValidatorMapFactory.php
├── Extraction/
│   ├── DeclarationControlBindings.php
│   ├── SourceControlExtractor.php
│   └── UnattachedComments.php
├── Directive/                      # the directive itself: store, addressing, validation
│   ├── Audit/                      # what each authored directive did, both halves
│   │   ├── AuthoredDirectiveGroup.php # one authored @qmx-threshold, its bindings, site and subjects
│   │   ├── DirectiveMaskingCoalition.php # which threshold directives of one rule hide one another
│   │   ├── DirectiveUsage.php      # what each authored suppression did
│   │   ├── ExecutionFingerprint.php # what one rule execution produced, compared as a whole
│   │   ├── MaskingOutcome.php      # what the sweep decided about one group, before it is reported
│   │   ├── StaleDirectiveFinding.php # the finding that says a directive silenced nothing
│   │   └── ThresholdDirectiveAudit.php # what each authored @qmx-threshold did
│   ├── DirectiveAddressability.php # is this directive able to do anything?
│   ├── DirectiveChannelBan.php     # the channels no directive may address or silence
│   ├── DirectiveLevels.php         # which levels one directive can silence a channel at
│   ├── DirectiveNameHints.php      # "did you mean" by reverse query, incl. metric -> judging channel
│   ├── DirectiveRejection.php
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
  to the named Run consumer `FileProcessor`. It accepts the parsed AST, the
  exact source bytes it was parsed from, relative file path, callable
  measurement facts, and class measurement map. `FileProcessor` reads the file
  once and hands the same bytes to the parser and to extraction, so an AST from
  the cache and a fresh parse are read against the same text.
- `SourceControls` returns the three ordered worker-safe lists: suppressions,
  threshold overrides, and threshold diagnostics. Suppression and diagnostic
  values stay with Inline; Finding owns the shared `ControlScope` and
  `ThresholdOverride` vocabulary that Inline produces and Run transports.
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

**A directive that is read and refused is still carried.** Four mistakes are
decided inside extraction, before any channel is consulted, each a
`DirectiveRefusalReason`: a `@qmx-` tag name nobody reads (`@qmx-ignore-lines`);
a tag this tool reads written without the argument it requires (`@qmx-ignore`
or `@qmx-ignore-next-line` with no channel on its line, `@qmx-threshold` with no
rule) — reported under the form it is, not as an unknown tag; a declaration form
written where nothing it can act on is measured — above a statement, on a
property without hooks; and a `@qmx-threshold` in a line or block comment, which
only a docblock carries. Each used to be dropped where it was found, and a
directive that never reaches the store is judged by nothing: not the
configuration error `check` reports, not the verdict `directives` prints, not
the stale-directive rule. So the extractor keeps them, marked with a
`DirectiveRefusal`, which is the same move the channel grammars make when they
admit `:` and `#` — capture, then refuse by name. A refused directive answers
`false` to every channel, so it filters nothing; the refusal words itself,
`DirectiveAddressability` routes it onto `annotation.unresolved-directive` at the
line it was written on, and the audit reports it `unmeasured / already-refused`
under its own form rather than judging it twice. The wording lives with the
refusal and not with the addressability because these are the only directive
mistakes decided against the grammar of the tag and the place it was written
rather than against the channels a run resolved.

**One key names an authored directive.** `Suppression::authoredSite()` — line,
form, authored argument and refusal reason — is what extraction deduplicates by
(beside the binding), what the store keeps one of per site, and what the usage
audit groups by. The three used to spell the key separately from the type
rather than the form, and every refusal shares one type, so two different
refused tags naming one channel on one line collapsed into one — or one refusal
replaced another.

**A threshold tag is refused by the sweep only when its own reader did not
answer for it.** `ThresholdOverrideExtractor` reports which docblock tags each
override and each diagnostic came from, and the suppression sweep leaves exactly
those to it; every other `@qmx-threshold` — in the wrong carrier, with no rule,
over a node no threshold binds to — is refused there. The sweep asks what the
reader did rather than a second copy of its node types and grammar, so the two
cannot disagree about a tag and leave it to neither. A property without hooks is
read for its diagnostics only: nothing measures it, so its override is not
carried and is refused instead of vanishing.

The declaration form on an unbound node used to throw instead, and the throw was
not contained: the file failed to process, so one misplaced annotation cost every
metric and every finding in it, and the run reported a coverage failure rather
than an annotation mistake.

**A comment's own punctuation is not an argument.** The channelless form was
refused only in a line comment; in a block comment and a docblock the closing
delimiter's `*` was read as the channel argument, and `*` is the one argument
that names no channel at all. So `/** @qmx-ignore */` silenced every channel on
the declaration it stood over, and said nothing: the tag parsed, so no refusal
was reported, and it silenced something, so `annotation.unused-directive` stayed
quiet too. The three grammars now require the argument on the tag's own line and
refuse one that begins with the delimiter, which leaves the authored `*` — the
documented "no rule filter" spelling — and a selector merely ending against the
delimiter untouched. The threshold grammar carries the same two guards: its
separator used to cross a line break, so `@qmx-threshold` with nothing after it
was reported as a threshold on the rule `*`, which nobody wrote.

**A carried-out declaration control travels with a `DeclarationBinding`.** The
measured declaration, the control scope and the declaration's last line are one
fact with one lifetime: a suppression either binds to a declaration or is a
physical control or a refusal, and the three used to be optional arguments whose
only legal combinations were all-or-nothing. The binding lives beside
`DirectiveRefusal` because the two answer the same question either way — what
extraction could and could not carry out.

**Where a physical directive may be written is not a question about PHP.** The
file and next-line forms are bound to a line and a file, so extraction reads them
off every comment in the file rather than off a list of node types. The list that used to gate this named neither `if`, `foreach`, `return`,
`namespace` nor `use`, and on each of those a docblock directive did nothing while
the same directive in a line comment worked — the second condition that let
unlisted nodes through excluded docblocks by construction. The declaration forms
keep their binding requirement, which is theirs and not the grammar's:
`@qmx-ignore` and `@qmx-threshold` name a measured declaration or they are
refused, and `@qmx-threshold` is refused outside a docblock as well.

**A comment the AST does not carry is read from the source.** php-parser gives a
comment to the node that starts at the next token, so a comment followed by a
modifier, a keyword or a closing bracket reaches no node — among them the
docblock between a declaration's attributes and the declaration (`#[Attr]`, then
the docblock, then `public function`), which PHP's own reflection hands to the
declaration. Read from the AST alone, a directive there was neither carried out
nor refused. `Extraction\UnattachedComments` finds the `@qmx-` comments no node
carries in the source tokens (only in a file that contains the prefix at all).
One standing after a declaration's last attribute group and before the first
node of its own belongs to that declaration and reads exactly as the same
comment written above the attributes; any other is read as a comment on a
statement — the physical forms work, the declaration forms are refused. The
declaration is read through a copy carrying the extra comments, never by
writing them into the tree, because a cache hit shares one tree.

A comment php-parser does attach, but to the wrong node, is not re-homed: a
docblock between two attribute groups belongs to the second `AttributeGroup`
and one between `function` and the name to the name `Identifier`. Both are
refused as binding to nothing rather than read as the declaration's own.

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

**The fourth channel is one of two channels a directive may not address.**
`DirectiveChannelBan` refuses every directive whose target reaches
`annotation.unused-directive` — the exact name, `annotation.*`, either of them
with `:file`, under any of the three tags — with an
`annotation.unresolved-directive` on the line it was written on, and the audit
reports the same directive `unmeasured / already-refused` rather than judging it
a second time. The ban is asked **after** the `channel:level` grammar, so
`annotation.unused-directive:class` is still answered as the impossible pair it
is. The form with no rule filter names no channel and is not refused; it simply
no longer silences the channel, because `SuppressionFilter` applies no directive
to it. Both questions read one object, so a form cannot be refused by `check`
and still judged by `directives`.

The ban is not an exemption from the report. Unlike the three configuration
errors, a finding on this channel stays inside the pipeline: the top-level
`suppress_paths` drops it, a baseline ceiling accepts it, a git scope narrows it,
and the run's channel selection decides it exactly as it decides every other
channel — `--disable-rule=annotation.unused-directive` silences it, an
`--only-rule` that never names it does not report it, and both spellings reach
it through `RuleExecutionInterface::publishable()`, which
`AnalysisPipeline::reportedFindings()` asks at the point the channel is
assembled. Three exclusions never reach it: the top-level `suppress_namespaces`
matches on a namespace, and this finding's subject is the **file** the
annotation sits in; and the producer's own `suppress_paths` /
`suppress_namespaces` run inside `RuleExecution`, whose exclusion ledger is
closed — its counters, its `--show-suppressed` retention and its attributions
are read into the execution result before this channel exists, so applying it
here would remove a finding that the run's own account of removals never
mentions. What the ban removes is only the ability to hide the finding with the
mechanism it exists to audit.

**The second banned channel is `duplication.clone`, for a different
reason** — no directive form binds to its project-wide finding in a way an
author controls; see the `DirectiveChannelBan` docblock for the mechanism.
Every form is refused at the line it is written on, with the same
`annotation.unresolved-directive` code and its own wording; the working path
is channel-level (`disabled_rules` / `--disable-rule` / baseline), not a
directive. A bare directive that named no channel and used to silence a
duplication finding by covering everything no longer does — see
`SuppressionFilter` above — and, if that directive silenced nothing else, it
now surfaces as `annotation.unused-directive` where it previously produced no
finding at all.

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
`Extraction\\UnattachedComments` is internal: the comments php-parser attached to
no node and the declaration each belongs to, for one file.
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

## Rule option key declarations

`InlineDirectiveOptions` declares its accepted option keys through
`RuleOptionsInterface::acceptedOptionKeys()`: `enabled` and
`unused-directive-severity`, a plain transcription of its constructor
parameters — there is no answered-by-the-class key here, unlike the
Architecture capability's two options classes. `RuleOptionsFactory` reads this
declaration and refuses any other key by name.

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
