# Inline source policy

`Analysis\\Policy\\Inline` owns source annotations: extraction, declaration
binding, threshold validation, annotation suppression, and the report on the
directives themselves. Run parses and measures a file, then calls the
Inline-owned extraction contract once; it owns no annotation policy state.

## Layout

```text
Inline/
├── Contract/
│   ├── Directive/               # the four annotation.* channel names, run state
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
│   ├── DirectiveNameHints.php      # "did you mean" by reverse query
│   ├── DirectiveRejection.php
│   ├── DirectiveEffect.php         # effective / overrun / inert / unmeasured
│   ├── DirectiveUnmeasurableReason.php # why a directive has no verdict
│   ├── DirectiveUsage.php          # what each authored suppression did
│   ├── DirectiveVerdict.php        # one authored site and its effect
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
  names and the two moments Run needs: `prepare()` before rule execution,
  `auditDirectiveUsage()` after it. Only `Analysis\Run\RuleProducerPreparation`
  calls them, under the same producer-enablement rule as every other
  capability preparation.

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
Run still calls `prepare()`, `reset()` and `auditDirectiveUsage()` on
`InlineDirectivePolicyInterface`, and the policy forwards the third — under its
own severity gate, which stays with the state the owning rule arms.

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
