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
├── Pipeline/                   # ordered analysis pipeline
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
  complete phase order. It stores no capability result.

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
