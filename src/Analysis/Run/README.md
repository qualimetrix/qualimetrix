# Run

## Subject and boundary

`Analysis\\Run` owns the execution of one analysis: file discovery, parallel
collection, phase ordering, run coverage, and run-level results. It is a
navigation leaf, not a home for evidence, policy, or reporting state.

The only generic phase extension point is
`Contract\\FileSetInspectionParticipantInterface`. It is intentionally narrow:
Run supplies the eligible `list<SplFileInfo>`, resets the participant before a
run, and invokes it only when its producer rule is selected. It neither reads
nor stores a capability result. `FileSetInspectionComposite` orders registered
participants deterministically and emits the generic
`file-set-inspection.<participant-id>` profiling span.

## Structure

```text
Run/
├── Contract/
│   ├── Collection/             # collection inputs and wire-safe outputs
│   ├── Discovery/              # discovery contracts
│   ├── Pipeline/               # analysis result and coverage contracts
│   └── FileSetInspectionParticipantInterface.php
├── Collection/                 # orchestration and per-file processing
├── Configuration/              # run configuration resolution and project
│                               # scope coverage
├── Discovery/                  # discovery coordination and implementations
├── ExcludeBinding/             # what the run's exclude patterns bound to, and
│                               # the `discovery.unmatched-exclude` producer
├── FileSetInspection/          # rule-selected composite
├── Pipeline/                   # ordered analysis pipeline, plus the prepared
│                               # run both of its entry points share
└── RuleProducerPreparation.php # capability-specific producer gating and reset
```

## Phase order

```text
Discovery -> Collection -> DependencyModel build -> Architecture policy ->
Measurement aggregation -> ComputedMetrics evaluation -> CircularDependency
preparation -> FileSet inspection -> Rule execution -> result projection
```

`ProjectScopeCoverage` answers whether a run looked at the whole project or at
a slice of it: its denominator is every production autoload target of
`composer.json` — `psr-4` and `psr-0` roots, `classmap` and `files` entries
alike — so `check src/` on a project autoloading `src/` covers the project
while `check src/Foo/` does not. A `classmap` or `files` entry may name a
single file, which changes nothing: the question is containment.
`ProjectScopeMeasurement` carries both halves of one measurement — the
uncovered targets the console warns about, and the verdict a channel reads —
because a manifest declaring no readable production autoload at all (absent,
unparseable, or without a production section) names no uncovered target and
still may not be judged. The answer travels on
`RunConfiguration::$coversProjectScope` because it is a fact about that
configuration's paths: `RunConfigurationResolver` fills it, `CheckCommand`
refills it from `CheckScopeResolver` when a Git report scope narrows the run
after resolution, and `AnalysisPipeline` copies it onto
`AnalysisContext::$coversProjectScope` for the rules. The console's
incomplete-scope warning is rendered from the same single measurement, so the
warning and the findings cannot disagree about whether the run was a slice. The
field has no default: every site that narrows a run states its own answer. A rule that reports a configured value as
having bound to nothing must read it first: "bound nothing" is a fact about the
pair (configuration, run scope), and a slice cannot carry the configuration's
denominator. The predicate sees narrowing by path only, and answers "covers"
when there is no composer manifest to be a denominator.

`AnalysisFileDiscovery` coordinates the default or explicit discovery strategy,
deduplicates overlapping roots by project-relative path, and applies
`GeneratedFilePolicy::Include` or `GeneratedFilePolicy::Exclude` without a
boolean policy argument. Its `DiscoveredAnalysisFiles` result keeps eligible
files, project-relative paths excluded as generated, and the post-deduplication,
pre-filter discovery count together.

`DirectoryPruner` owns directory exclusion during discovery. It evaluates
typed `PathPattern` values against one canonical subject: the directory path
relative to the project root with `/` separators. The check happens before
descent and returns the first matching selector for attribution. Explicit file
arguments remain exact inputs and are not filtered as directories. The
built-in `vendor`, `node_modules`, and `.git` exclusions are internal regex
selectors that match those directory names at any depth; user selectors do not
inherit that special basename behavior.

Collection is the only parallel phase. `FileProcessingResult` holds the path and
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

## Contracts and consumers

- `AnalysisPipelineInterface` is the public run entry point for adapters.
- `FileDiscoveryInterface`, `CollectionOrchestratorInterface`, and
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
- `RuleProducerPreparation` coordinates their rule selection, reset and
  profiling with file-set inspection while `AnalysisPipeline` retains the
  complete phase order. It stores no capability result. It is also where Run
  asks the inline-directive capability its two post-execution questions —
  which suppressions silenced nothing, and what each `@qmx-threshold` did —
  through `InlineDirectivePolicyInterface` and
  `ThresholdDirectiveAuditInterface`.

## `discovery.unmatched-exclude`

Run's own channel, and the only one it produces. An `--exclude` value or an
`exclude:` entry that matches no directory keeps nothing out of the analysis,
and before the channel existed that run's report was byte-identical to one
configured with no exclusion at all.

Three pieces, in the order the run reaches them:

- `RunConfigurationResolver` records the author's entries separately, in
  `RunConfiguration::$authoredPathExcludes`. The merged `pathExcludes` cannot
  answer for them: it also carries the built-in `vendor`, `node_modules` and
  `.git`, and `node_modules` is legitimately absent from most PHP trees.
- `ExcludeBindingProbe` walks the run's roots through the same
  `DirectoryPruner` and answers, per typed selector, whether any directory
  matched. It has to be asked *during* discovery: pruned directories disappear
  before anything downstream can count them, so a selector that worked and one
  that matched nothing are indistinguishable from the output. A selector whose
  possible match lies below an already-pruned parent is unjudgeable and is not
  reported as stale; exact and subtree selectors are located precisely, while
  arbitrary regex selectors are treated conservatively.
- `UnmatchedExcludeAudit` turns that answer into findings, and
  `AnalysisFileDiscovery` asks it, so they ride out of discovery with the
  files (`DiscoveredAnalysisFiles::$unmatchedExcludeFindings`) and are
  published through `RuleExecutionInterface::publishable()` after rule
  execution, like every other finding. The audit is registered **lazy**: built
  eagerly it would capture its rule's Options before the console had applied
  `rules.<name>.enabled` or `--rule-opt`.
- `UnmatchedExcludeRule` gives the channel its identity — `qmx rules`,
  `--disable-rule`, severity, baseline. It emits nothing; the channel's name
  lives on `UnmatchedExcludeOptions`, which is what keeps the rule and the
  audit from naming each other and forming a cycle.

The channel is silent on a run narrowed below the project's production
autoload roots (`RunConfiguration::$coversProjectScope`): there a pattern binds
nothing because of the path the caller chose, not because of anything the
author wrote.

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
- Run imports capability promises only through declared contracts and stores no
  capability payload.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
