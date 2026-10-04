# Run

## Subject and boundary

`Analysis\\Run` owns the execution of one analysis: file discovery, parallel
collection, phase ordering, run coverage, and run-level results. It is a
navigation leaf, not a home for evidence, policy, or reporting state.

The only generic phase extension point is
`Contract\\FileSetInspectionParticipantInterface`. It is intentionally narrow:
Run supplies the eligible `list<SplFileInfo>`, resets the participant before a
run, and invokes it only when its producer rule is selected and its own options
have not switched it off. It neither reads nor stores a capability result.
`FileSetInspectionComposite` orders registered participants deterministically
and emits the generic
`file-set-inspection.<participant-id>` profiling span.

## Structure

```text
Run/
├── Contract/
│   ├── Collection/             # collection inputs and wire-safe outputs
│   ├── Configuration/          # mandatory RunConfiguration, captured universe, current measurement and reasons
│   ├── Discovery/              # ProjectFiles and opt-in ProjectTree metadata contracts
│   ├── Pipeline/               # AnalysisResult, MeasuredRunResult and coverage contracts
│   └── FileSetInspectionParticipantInterface.php
├── Collection/                 # per-file processing and private SourceReader;
│                               # CollectionPhaseFold assembles per-file
│                               # results into the phase output
├── Configuration/              # run resolution, PathsSection, PathsNormalizer,
│                               # ProjectScopePaths, ProjectScopeDefaults,
│                               # ManifestScopeEvidence for captured Composer interpretation,
│                               # and one project scope measurement
├── Discovery/                  # ProjectFiles, one walk/inspector and metadata queries
├── ExcludeBinding/             # per-walk selector facts and audit publication
├── FileSetInspection/          # rule-selected composite
├── Pipeline/                   # ordered analysis pipeline, plus the prepared
│                               # run both of its entry points share
├── InlineDirectiveRun.php     # authored Inline preparation, usage and ordered verdicts
└── RuleProducerPreparation.php # capability-specific producer gating and reset
```

`PathsSection` declares `paths` to the document engine and shares its value
reader with `RunConfigurationResolver`. Each
writing layer must supply a non-empty list of non-empty strings, including a
layer overridden by CLI paths. Existence and canonical input boundaries depend on the final run and are judged
after merging. Authored exclusions are measured during discovery, including when
they remove a written root.

`AnalysisResult::fromRun()` composes Run's `MeasuredRunResult`, Inline's
`DirectiveObservations`, nullable rule execution and a separate late-published
list. Measurements contain repository, coverage, namespace tree, final project
scope and duration. `findings()` reads canonical execution publication and late
findings; private counts preserve each original run's publication order through
merge, including null execution. The old upper fields and count aliases are
removed; see [ADR 0094](../../../docs/adr/0094-analysis-results-publish-subject-owned-values.md).

Private `ManifestScopeEvidence` interprets the already captured Composer facts
and autoload-development policy. `ProjectScopeCoverage` retains the one reader,
path IO and universe construction; defaults retain their refusal contract.

Private `RunPathSelection` chooses authored or inferred paths from the captured
document without rereading Composer. `WalkedEntrySelection` owns admission and
recursive metadata descent; `WalkedEntryOutcome` accumulates passed facts with
no IO. `RemovedRunPhpEvidence` searches metadata only for actual removed run
entries that require PHP evidence. These types share the same inspector and
ledger, without another classifier or walk.

## Phase order

```text
Discovery -> Collection -> DependencyModel build -> Architecture policy ->
Measurement aggregation -> ComputedMetrics evaluation -> CircularDependency
preparation -> FileSet inspection -> Rule execution -> result projection
```

`RunConfiguration` carries mandatory scope measurement, explicit generated and
autoload-development policies, and the invocation's captured `ProjectScopeUniverse`.
Composer facts, authored paths, source reasons and accepted aliases are captured
once. Pure `narrowTo()` retains that evidence without another manifest or IO read.

`ProjectFilesInterface::discover(RunConfiguration)` returns `DiscoveredProjectFiles`.
One `ProjectWalk` and `EntryInspector` own entry inspection and listing. Eligible
files, generated exclusions, named authored exclusions, skips, scope facts and
selector facts travel together. Binding happens before pruning for all matching
selectors, including overlapping selectors and named files. Private per-walk
`ExcludeSelectorLedger` stores passed facts; it performs no filesystem IO and is
not a DI service. The old discovery factory, list/side-channel ports, separate
prune/probe walks and `DiscoveredAnalysisFiles` are retired.

PHP candidates are regular files with the case-sensitive `.php` extension.
Walked directory links are not followed; walked file links and non-regular PHP
entries are failures. A named directory alias is accepted when its canonical
target is inside the captured root. A named file link keeps its lexical basename
when its canonical parent is internal, including a link to an outside file.
An external spelling canonically targeting an internal directory is valid;
a directory alias targeting outside is refused with its input provenance.
Unresolved internal paths retain the existing missing-input refusal. Publication
canonicalizes the parent and preserves the final name via `PathFactory::published()`;
outside or unresolvable parents have no best-effort fallback.

The pipeline combines captured universe and final selection once. Its final
`ProjectScopeMeasurement` contains Finding's `ProjectScopeJudgement`; the same
judgement reaches `AnalysisContext`, threshold counterfactual contexts and
`AnalysisResult`. `PreparedRun` retains context without a duplicate scope field.
Console publishes final measurement rather than the pre-discovery target state.
An omitted empty directory need not narrow final paths: observed missing PHP does.
Git reporting changes publication while preserving analysis paths and universe.

The judgement asks two questions. `judgesNamespaceClaims()` withholds declaration
absence when paths omit observed PHP, an authored removed run entry hides PHP
(or its metadata cannot be checked), generated PHP is removed, or the denominator
is unknown. `judgesExcludeSelectors()` uses path completeness and universe certainty
separately, without Exclude/Generated doors as inputs. Settled `Removed` selectors
survive a withheld answer.

Path coverage compares PHP files: a complete explicit roster can cover a known
universe. An observed outside-selection regular `.php` is a missing file even
when an authored exclusion matches it. An excluded outside-selection directory
has unseen descendants: a named `IncompleteUniverse` cause withholds both
questions without listing it. An asset or non-regular entry is not inferred
missing PHP. Removed-entry and generated-PHP counts keep their different units.

Six declaration-absence channels ask the first question:
`architecture.empty-template`, `architecture.unmatched-exclude`,
`architecture.unreachable-layer`, `cohesion.unmatched-exclude-method`,
`coupling.unmatched-framework-namespace` and `suppression.unmatched-namespace`.
`discovery.unmatched-exclude`, `suppression.unmatched-path` and
`suppression.unmatched-rule-ledger` use selector/path completeness; rule-ledger
namespace values also ask the first question. Finding owns this vocabulary.

`ProjectTreeQueryInterface` exposes opt-in `snapshot(ProjectScopeUniverse)` and
`hasFile(AbsolutePath, RelativePath)` metadata queries, with `Present`, `Absent`
and `Unknown`. The snapshot sorts distinct regular PHP paths under captured
autoload targets, retains inaccessible metadata, applies the built-in floor,
and ignores authored exclusions and generated policy. It opens no source and
follows no walked directory links. Unknown targets do not become a known empty
universe. Ordinary analysis requests no additional snapshot. Baseline metadata
consumers can request it explicitly; lifecycle integration is separate. It costs
O(entries) time and O(PHP files) memory, with no cap or persistent index.

Collection is the only parallel phase. Private `SourceReader` supplies one snapshot
to parsing, LOC and Inline extraction. Read refusal yields `unreadable-file` before
parser invocation. `CompositeCollector` resets before snapshot handoff and AST
traversal; only LOC implements Measurement's narrow
`SourceMeasuringCollectorInterface`. Run owns the read and byte snapshot. Generated-header
inspection and Duplication retain separate reads, so this is not an all-source
read-once promise.

`FileProcessingResult` holds the path and
exactly one terminal state: a `SuccessfulFileProcessing` payload, or a failure
kind plus error. The success payload carries the file metric bag, callable,
class, and namespace measurements, dependencies, suppressions, threshold
overrides, and threshold diagnostics. The same value graph crosses PHP and
igbinary worker serialization; services and capability-owned state never cross
it. DependencyModel receives
collected dependency occurrences through its public builder contract.
Measurement owns repository creation and aggregation. ComputedMetrics owns
formula definitions and evaluation; Run invokes only its evaluation contract
and stores no computed-metric state or result payload.

Analysis and phase durations use monotonic `hrtime` readings, converted from
nanoseconds to seconds. Adjusting the system date cannot make a measured run's
duration negative.

## Contracts and consumers

- `AnalysisPipelineInterface` is the public run entry point for adapters.
- `ProjectFilesInterface`, `CollectionOrchestratorInterface`, and
  `FileProcessorInterface` describe Run-owned mechanics.
- `SuccessfulFileProcessing` is the public worker payload used by
  Infrastructure Parallel. `FileProcessingResult` accepts exactly one complete
  success or failure terminal state and delegates successful getters to it.
- `FileSetInspectionParticipantInterface` is implemented by a capability and
  registered by Infrastructure DI. It is not a generic lifecycle, graph, or
  metric-derivation participant port.
- `DependencyTraversalParticipantInterface` belongs to DependencyModel, not
  Run: it promises extraction to its named consumers.
- `LayerPolicyPreparationInterface` and
`CircularDependencyPreparationInterface` are capability-specific
  contracts, not a generic lifecycle or graph-participant registry.
- `RuleProducerPreparation` coordinates their final producer enablement, reset and
  profiling with file-set inspection while `AnalysisPipeline` retains the
  complete phase order. It stores no capability result.
- `InlineDirectiveRun` prepares authored Inline state and asks its two
  post-execution questions through `InlineDirectivePolicyInterface` and
  `ThresholdDirectiveAuditInterface`. It retains the same policy and audit
  instances and sorts their combined verdicts by file, line, form and target.
  The pipeline calls it directly, without a generic phase port.

## `discovery.unmatched-exclude`

The shared walk records binding facts independently of Finding configuration.
Run groups effective authored selectors by canonical display and obtains all
origins from `ResolvedWriteHistoryInterface::writes()`: surviving contributors
alone lose later equal writes. Origin kind/name is retained without promising
list-member positions. All matches bind before pruning.

Settled selectors are `Removed`, including outside-selection binding. Unsettled
selectors retain named inaccessible evidence as `Unjudgeable`; withheld path or
universe completeness yields `NotJudged`. Otherwise verdicts distinguish
`Unmatched`, `CoveredBySameSource` and `CoveredByOtherSource`. Hiders are removed
physical directories, including accepted named directory aliases, never walked
links or individual files. Regex may match in any hidden directory; exact/subtree
uses literal containment. A hider is other-source when one of its origins is absent
from the query's source set. Intersections are valid; any other-source hider wins.

The Finding-owned factory accepts measured facts and sources with a closed
constructor, deriving outcomes instead of trusting a caller's free outcome.
It requires nonempty distinct origin lists and rejects duplicates; it does not
repair an invalid source roster.
PHP evidence belongs only to real entries removed from this run. The walker can
search their metadata until the first regular `.php`, continuing to another
removed run entry if the first contains none. It never searches an excluded
outside-selection directory or opens source for this audit. Regular PHP or
unavailable search metadata from an actual removed run entry holds declaration
absence closed, with selector/evidence in its Exclude reason; selector
completeness remains independent.

`UnmatchedExcludeAudit` materializes eligible verdicts at the pipeline seam under
final producer selection. Graph/debug discovery never builds Finding configuration
or invokes this audit. Same-source warnings describe what lies outside the hider's
removal without claiming absolute staleness. Other-source skipped values retain
`channel`, `option` and `pattern`; a linked Exclude scope reason names the query,
chosen hider, only its foreign origins and a rerun without that exclusion.
Occurrence kinds and
producer identity remain unchanged.

## Graph discovery

`DependencyGraphAnalyzerInterface::analyze(RunConfiguration)` consumes the same
`ProjectFilesInterface`, generated policy and captured aliases as analysis.
Coverage retains generated and named exclusions and every skipped/read-refused
entry; incomplete graphs are not authoritative. It collects dependencies without
the Finding-backed audit. Debug layer assignment passes captured configuration
and symbol to the sole resolver entry point, without a synthesized configuration,
second universe or separate `resolveIncludingGenerated()` branch.

## The two entry points

`analyze()` answers what the code is like. `auditDirectives()` answers what the
run's own annotations did, and both begin with the same private step: discover,
measure, prepare every rule-producing capability, and execute the rules once.
The step is shared rather than repeated because the directive audit's method is
to re-execute rules **on this run's context** — a second collection would
measure a second world, and a difference between two worlds says nothing about
an annotation. By default a counterfactual re-executes only the rule the
directive addresses (`DirectiveSweepScope::Narrow`); the caller can ask for
every enabled rule instead (`Full`), which answers the same question at higher
cost and exists to measure that the narrowing is safe.

`auditDirectives()` is published as `DirectiveAuditInterface` — a second
contract on the same class rather than a second operation on
`AnalysisPipelineInterface`: the consumers of that contract analyse and do not
audit, the same split `DependencyGraphAnalyzerInterface` already makes for the
graph. The composition root binds one instance under both. `DirectiveAuditReport`
carries the coverage the verdicts were measured under, because a verdict is a
statement about one run — a threshold retuning a metric computed over the
analysed subgraph is live over one tree and dead over a subdirectory of it, and
neither answer is wrong. The rule selection is deliberately not carried: it is
Finding's internal type, and the caller that prints it resolved those selectors
itself. See ADR 0039.

## Test ownership

Run owns the subject-first tests under `tests/Analysis/Run/`, including
collection, discovery, pipeline, and FileSet inspection behavior. The essential
regressions prove that a disabled expensive participant performs no inspection,
participant ordering is deterministic, and two sequential runs reset state.

## Definition of Done

- Discovery, sequential collection, and parallel collection preserve the same
  analysis facts.
- A failed file produces an incomplete `AnalysisResult`, while generated-file
  exclusion remains intentional and complete.
- A late FileSet inspection read refusal is matched to the input identity
  captured before inspection, becomes an unreadable-file failure and makes the
  run incomplete with exit 4. The participant's partial Duplication result is
  cleared rather than published. Its absence in that result is not evidence
  that the project contains no duplicate copies; stale-entry absence authority
  remains a separate policy boundary.
- An entry discovery refused — a directory symlink met inside a walked tree, a
  non-regular file, a directory that would not list before or during the
  descent — carries a terminal state of its own and makes the run incomplete;
  none of them is silently absent from the file set. A symbolic link named as a
  path to scan is followed rather than refused, and so has no terminal state to
  carry.
- A skipped entry names itself by the name the reader was pointed at, whether
  or not the tree lies under the project root.
- An `exclude:` selector the walk could not settle, because a directory would
  not list, is reported as unjudged rather than dropped from the answer.
- Run imports capability promises only through declared contracts and stores no
  capability payload.


## Final producer preparation

Before Discovery, the document is judged and one invocation channel snapshot
feeds decide/build/conclude. `RuleProducerPreparation` reads the committed final
`RuleEnablement::runs` answer, resets participants once and prepares only live
producers. It does not apply a second string-selection algorithm or turn an only
filter into an enable. FileSet inspection uses the same answer.

The mandatory `ProjectScopeMeasurement`, captured universe, authored paths
and whole-project verdict remain the authority. Selection does not manufacture
complete coverage or turn a partial run into a project-wide absence statement.
DoD preserves reset/prepare ordering, independent either-producer participants
and zero inspection for an inactive producer.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
