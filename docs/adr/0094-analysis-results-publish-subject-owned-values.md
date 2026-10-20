# 0094. Analysis Results Publish Subject-Owned Values

**Date:** 2026-10-02
**Status:** Accepted

## Context

AnalysisResult accepted nine independent inputs: run measurements, source
annotations, and rule publication. Its findings duplicated the published half
of RuleExecutionResult. Adding another measured fact enlarged this constructor
without clarifying which owner promised it.

Separately merging execution publication and late publication would change the
existing order from left-published, left-late, right-published, right-late to
both published halves followed by both late halves.

## Decision

Run owns MeasuredRunResult: the metric repository, coverage, namespace tree,
final project-scope measurement and duration. Inline owns DirectiveObservations:
the per-file suppression and threshold-override maps that collection observed.
These maps do not replace per-file SourceControls or fabricate its diagnostics.
Both readonly values implement their subject's merge semantics.

AnalysisResult composes these values with nullable RuleExecutionResult and late
published findings. Its constructor is private; fromRun() requires all four
values. RuleExecutionResult remains the canonical source of execution-published
findings. Late Inline usage and unmatched-exclude findings stay separate, in
that order, under the same publication selection. findings() composes them
without retaining a second published array.

A private publication order records two counts per original run, not copies of
findings. The factory creates one segment from the actual collection lengths;
merge concatenates segments and their canonical collections. Reading segments
preserves the old left/right order, including nested merges and a side without
rule execution. External callers cannot supply segments. Existing merge rules
retain the left non-null tree and scope, maximum duration, coverage composition,
repository fallback, and ordered per-file directive concatenation.

MeasuredRunResult is a Run contract read by Console through AnalysisResult.
DirectiveObservations is an Inline contract constructed and carried by Run.
Neither is a DI service, generic context, phase port or new runtime store.

## Migration

Replace new AnalysisResult(...) with AnalysisResult::fromRun(). Construct a
MeasuredRunResult from the repository, coverage, nullable tree, nullable scope
and duration, and a DirectiveObservations from both observed maps. Pass the
execution result and a separate late-published list. For a hand-built result
without execution, pass null and place its findings in the late list. Never
copy execution-published findings into late or derive late by array subtraction.

Replace metrics, coverage, namespaceTree, projectScope and duration with the
corresponding measured fields; metrics becomes measured.repository. Replace
suppressions and thresholdOverrides with their directives fields. Replace the
findings property with findings(). Replace filesAnalyzed and filesSkipped with
measured.coverage.analyzedFilesCount() and skippedFilesCount(). Compatibility
aliases are removed. RuleExecutionResult produced/published audit semantics and
CLI report formats, messages, codes and ordering remain unchanged.

## Verification and cost

Existing result, pipeline, Console and baseline tests retain their assertions
through the new fields. The existing merge test adds distinguishable late
findings and nested ordering; one grouped-order mutation must fail it. No new
control, test root, process matrix or gate form is introduced. Each original
run contributes two private counts; publication assembles its requested list.
The external PHP consumers of the removed constructor and fields must migrate;
they are not enumerated by this repository's tests.
