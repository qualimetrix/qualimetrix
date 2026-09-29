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
│   ├── Discovery/              # discovery contracts
│   ├── Pipeline/               # analysis result and coverage contracts
│   └── FileSetInspectionParticipantInterface.php
├── Collection/                 # orchestration and per-file processing;
│                               # CollectionPhaseFold assembles per-file
│                               # results into the phase output
├── Configuration/              # run resolution, PathsSection, PathsNormalizer,
│                               # ProjectScopePaths, ProjectScopeDefaults,
│                               # and one project scope measurement
├── Discovery/                  # discovery coordination and implementations
├── ExcludeBinding/             # what the run's exclude patterns bound to, and
│                               # the `discovery.unmatched-exclude` producer
├── FileSetInspection/          # rule-selected composite
├── Pipeline/                   # ordered analysis pipeline, plus the prepared
│                               # run both of its entry points share
└── RuleProducerPreparation.php # capability-specific producer gating and reset
```

`PathsSection` declares `paths` to the document engine and shares its value
reader with `RunConfigurationResolver`. Each
writing layer must supply a non-empty list of non-empty strings, including a
layer overridden by CLI paths. Existence and conflicts with the resolved
exclusions depend on the final run and are judged after merging.

## Phase order

```text
Discovery -> Collection -> DependencyModel build -> Architecture policy ->
Measurement aggregation -> ComputedMetrics evaluation -> CircularDependency
preparation -> FileSet inspection -> Rule execution -> result projection
```

`ProjectScopeCoverage` measures paths against the selected Composer autoload
universe. Production `psr-4`, `psr-0`, `classmap` and `files` targets form
the denominator; development targets join it only with
`AutoloadDevPolicy::Include`. Configuration discovery and Run consume the
same `ComposerManifestReaderInterface` snapshot. Run applies its built-in
`vendor`, `node_modules` and `.git` floor to defaults and the denominator,
and names removed targets as reasons. Authored directory exclusions remain a
separate discovery policy.

`ProjectScopePaths` captures initial path pruning, canonicalization,
denominator and written-path resolutions. `ProjectScopeDefaults` judges
selected manifest completeness and refuses inferred paths with no usable
universe. `ProjectScopeCoverage` keeps the initial verdict and source reasons;
these operations use the same snapshot and preserve their evaluation order.
Report-facing reasons name the analysed manifest as `composer.json`; auxiliary
manifest issues retain their full dependency path so that the source remains
identifiable. Manifest reading and main-versus-auxiliary classification use
physical paths before those reasons are published.
This separation adds one named dependency to `RunConfigurationResolver`:
it asks `ProjectScopePaths` for initial pruning while retaining
`ProjectScopeCoverage` for the verdict. Its raw class CBO is 20, so a point
warning boundary of 21 accepts the transferred edge and still reports the
next distinct coupling; the configured error boundary of 30 is unchanged.

`measure(root, paths, autoloadDev, PathsAuthorship)` captures one immutable
`Contract\Configuration\ProjectScopeUniverse`: canonical root, initial
paths authoredness, denominator, pruned targets, source reasons, namespace-map
usability and written-to-canonical resolutions. Its seven constructor fields
are mandatory. `ProjectScopeMeasurement` has four mandatory fields: that
universe, current paths, state and uncovered targets. `narrowTo(paths)` shares
the same universe and only closes the current verdict, using captured path
facts without filesystem reads. `PathsNormalizer` applies the same
`PathFactory::fromCliArgument()` normalization to authored paths and defaults.

| Evidence and paths                                                       | State        | Whole-project channels |
| ------------------------------------------------------------------------ | ------------ | ---------------------- |
| Intact selected autoload; every counted target reached                   | `Covered`    | judge                  |
| Intact selected autoload; a counted target omitted                       | `Narrowed`   | withhold               |
| Damaged selected universe; authored whole root                           | `Unknown`    | judge                  |
| Damaged selected universe; subset or inferred partial defaults           | `Unmeasured` | withhold               |
| Absent/no-declared-code manifest; whole root including fallback defaults | `Unknown`    | judge                  |
| Absent/no-declared-code manifest; subset                                 | `Unmeasured` | withhold               |

Unusable damaged defaults refuse before discovery; write explicit paths.
Valid sibling records survive partial manifest damage without turning those
inferred fragments into a complete project. Metadata errors alone do not
damage the selected code universe. A declared target missing on disk remains
outside the denominator and is named as a `MissingTarget` reason; an intact
all-missing manifest can therefore leave an empty denominator and cover an
authored subset. Missing default paths still refuse before analysis.

`RunConfiguration` requires a measurement and explicit
`GeneratedFilePolicy`/`AutoloadDevPolicy`; its public `paths` and
`coversProjectScope` are derived from that measurement. The root must match
the measured root or an exact captured alias. `withProjectScope()` carries
every other run field unchanged. Pure `initial->narrowTo(finalPaths)`
preserves evidence and origin, performs no IO, and can only close coverage;
a wider path cannot reopen an already withheld answer.

`CheckScopeResolver` reuses this initial measurement. The current Git scope
keeps analysis paths unchanged and limits finding publication through
`reportScope`; it does not reread the manifest. `AnalysisPipeline` copies
the derived coverage answer to `AnalysisContext`.
`WHOLE_PROJECT_CHANNELS` names the eight readers withheld on `Narrowed` and
`Unmeasured`, and the existing reader registration test holds that list to
its consumers.

Namespace location depends on accepted PSR-4 facts, separately from the enum.
Finding owns the per-value suppression audit and names skipped values;
Reporting preserves those values and the scope reasons. Auxiliary manifest
issues and install-root omissions explain degraded ancestry evidence without
closing main-project coverage. See ADR 0089 for source lifetime, the measured
price and the scope limits.

`AnalysisFileDiscovery` coordinates the default or explicit discovery strategy,
deduplicates overlapping roots by project-relative path, and applies
`GeneratedFilePolicy::Include` or `GeneratedFilePolicy::Exclude` without a
boolean policy argument. Its `DiscoveredAnalysisFiles` result keeps eligible
files, project-relative paths excluded as generated, the post-deduplication,
pre-filter discovery count, and what discovery refused, together.

A directory is never a unit of analysis, and what discovery refuses is named.
`FinderFileDiscovery` keeps two decisions apart that used to be one callback:
what may be analyzed, and where the walk may descend. A symbolic link to a
directory *met inside a walked tree* is not descended into — following it would
change which files a run measures, could leave the project root, and would not
terminate on a cycle — and a non-regular `*.php` entry (FIFO, socket, dangling
link) is not a candidate. A link named on the command line as a path to scan is
the exception and is followed: naming it is asking for it, so it is classified
as the directory it points at rather than refused, and there is no skip to
record. A directory the process cannot list costs that branch, not the run, and
it costs it the same way whether it refuses the check made before the descent
or the descent itself: `DirectoryWalk` is what makes the second one a record
instead of a branch quietly missing from the result. Each of
these is recorded as a `SkippedEntry` and reaches the report through
`AnalysisCoverage::withSkipped()`, which gives it a terminal state among the
failures: the run is then incomplete, which is what every existing reader —
exit code, machine formats, text report — already knows how to say. A subtree
that is not read is otherwise indistinguishable from a subtree with no code in
it.

`SkipReportingDiscoveryInterface` carries that list beside `discover()` rather
than inside it, so a discovery that only ever returns a list is not forced to
answer a question it cannot answer. `AnalysisFileDiscovery` asserts the same
regular-file invariant over whatever it is given, which is what makes it hold
for discoveries that never walked a filesystem.

A `SkippedEntry` names itself relative to the project root without resolving
its own last segment: canonicalizing a symbolic link reports its target, a path
that may be outside the project and that nothing in the tree is called. Only
the containing directory is resolved, on both conversion branches — the
out-of-root fallback canonicalizes whatever it is handed, so it is handed the
parent and the entry's own name is appended afterwards. A tree analysed from
outside the project root is where that mattered: there the fallback is the
branch that runs, and the name it published belonged to the link's target.

`DirectoryPruner` owns directory exclusion during discovery. It evaluates
typed `PathPattern` values against one canonical subject: the directory path
relative to the project root with `/` separators. The check happens before
descent and returns the first matching selector for attribution. Explicit file
arguments remain exact inputs and are not filtered as directories. The
built-in `vendor`, `node_modules`, and `.git` exclusions are internal regex
selectors that match those directory names at any depth; user selectors do not
inherit that special basename behavior. A directory argument that a built-in
selector removes itself (`lib/vendor`) is refused by `FinderFileDiscovery`
before anything is yielded, through `DirectoryPruner::builtInExclusion()`: no
default path is ever such a directory, so it was written by hand, and the walk
would have reported success over zero files. A directory argument inside one
(`vendor/acme`) is walked. A root removed only by an authored `exclude:` is
skipped by discovery without a word, because discovery cannot tell a written
root from a composer default the author excluded on purpose.
`RunConfigurationResolver` can, so it refuses a directory the author wrote
(`paths:` or a command-line path) that an authored selector removes, before
analysis and by the same `DirectoryPruner::match()` discovery asks of a root:
`exclude: [subtree: legacy]` refuses `check legacy` and `check legacy/old`,
`exclude: [exact: legacy]` refuses only `check legacy`. A composer default the
author excluded stays a silent exclusion, and a written file inside an excluded
directory stays an exact input.

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

Analysis and phase durations use monotonic `hrtime` readings, converted from
nanoseconds to seconds. Adjusting the system date cannot make a measured run's
duration negative.

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

The run reaches these operations in order:

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
  arbitrary regex selectors are treated conservatively. Its `judge()` keeps
  that verdict apart from "nothing matched": a selector left unjudged because a
  directory would not *list* — as opposed to one the configuration deliberately
  pruned — is named in `ExcludeBindingVerdict::$unlistable` together with the
  directory that stopped the walk. Without that split the run had one answer
  for "checked, it bound" and "could not check", and the second walk covers
  tree discovery never visits, so nothing else would have said so.
- `ExcludeBindingVerdict::unsettled()` preserves authored selector order.
  `ExcludeBindingProbe::judgeProject()` asks the current roots first and the
  whole project only for those unsettled selectors.
- `UnmatchedExcludeFinding` builds the unmatched-pattern occurrence;
  `UnjudgedExcludeFinding` retains the separate unlistable-directory vocabulary.
  `UnmatchedExcludeAudit` applies the Options, authored-pattern and project-scope
  gates before asking the probe, then turns its answer into findings, and
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

A selector the second walk could not settle is reported too, on the same
channel and under its own occurrence identity: the message names the directory
that would not list, and says the run makes no claim about the selector. A
project that accepted "nothing matched this" has not thereby accepted "nobody
looked".

The channel is silent on a run narrowed below the project's autoload
targets (`RunConfiguration::$coversProjectScope`): there a pattern binds
nothing because of the path the caller chose, not because of anything the
author wrote.

## Graph discovery

`DependencyGraphAnalyzerInterface::analyze()` receives mandatory
`RunConfiguration` and `FileDiscoveryInterface`. It uses `AnalysisFileDiscovery::discoverEligible()`, so graph export applies the resolved path exclusions
and `GeneratedFilePolicy`, and its coverage counts excluded generated files.
The graph path preserves the shared discovery's skipped entries and collects
dependency evidence without calling the Finding-backed exclude audit or
constructing Finding or Reporting configuration. Console resolves Coupling's framework namespaces for
this run before requesting the graph.

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


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
