# Run

## Subject and boundary

`Analysis\\Run` owns the execution of one analysis: file discovery, parallel
collection, phase ordering, run coverage, and run-level results. It is a
navigation leaf, not a home for evidence, policy, or reporting state.

The only P3 phase extension point is
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
├── Discovery/                  # discovery coordination and implementations
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

`AnalysisFileDiscovery` coordinates the default or explicit discovery strategy,
deduplicates overlapping roots by project-relative path, and applies
`GeneratedFilePolicy::Include` or `GeneratedFilePolicy::Exclude` without a
boolean policy argument. Its `DiscoveredAnalysisFiles` result keeps eligible
files, project-relative paths excluded as generated, and the post-deduplication,
pre-filter discovery count together.

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
  `CircularDependencyPreparationInterface` are capability-specific P4
  contracts, not a generic lifecycle or graph-participant registry.
- `RuleProducerPreparation` coordinates their rule selection, reset and
  profiling with file-set inspection while `AnalysisPipeline` retains the
  complete phase order. It stores no capability result. It is also where Run
  asks the inline-directive capability its two post-execution questions —
  which suppressions silenced nothing, and what each `@qmx-threshold` did —
  through `InlineDirectivePolicyInterface` and
  `ThresholdDirectiveAuditInterface`.

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
