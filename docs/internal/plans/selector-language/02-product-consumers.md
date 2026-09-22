# Stage 2 — suppression, drill-down, graph, and coupling consumers

## Boundary change

Every migrated runtime boundary carries `list<PathPattern>` or
`list<NamespacePattern>` (or one nullable bound pattern) instead of
`list<string>`. The authored definition remains
available for messages and serialization, but no consumer reparses it.

This is an intentional internal breaking change. Public YAML and CLI breakage is
recorded in Stage 4. The repository has no compatibility shim or bare-string
constructor overload.

## P3 — configured finding exclusions

**Owner files:**

- `src/Analysis/Finding/Exclusion/ConfiguredSuppression.php`
- `src/Analysis/Finding/FindingExclusionLedger.php`
- `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php`
- `src/Analysis/Finding/RuleConfiguration/RuleOptionsRegistry.php`
- `src/Analysis/Finding/Contract/RuleConfigurationInterface.php`
- `src/Analysis/Finding/Exclusion/RulePathExclusionProvider.php`
- `src/Analysis/Finding/Exclusion/RuleNamespaceExclusionProvider.php`
- `src/Analysis/Finding/SuppressionBinding/*`
- Finding contracts that currently expose suppression string lists
- `src/Reporting/FindingProjection/FindingProjectionOptions.php`
- `src/Reporting/FindingProjection/Contract/ConfiguredFindingExclusions.php`
- `src/Reporting/FindingProjection/FindingProjector.php`
- `src/Reporting/FindingProjection/SuppressionCompositionBuilder.php`
- `src/Reporting/FindingProjection/RuleExclusionLedgerAttributor.php`
- `src/Infrastructure/Console/FindingFilterOrchestrator.php`
- `src/Infrastructure/Console/Command/BaselineRun.php`
- `src/Analysis/Finding/README.md` and `src/Reporting/README.md`

**Test files:** Finding exclusion/suppression-binding tests, Reporting finding
projection/composition tests, Console orchestration/functional tests, and their
fixtures. This package does not touch Run discovery or Architecture files.

**Behavior:** global and per-rule `suppress_paths`, `suppress_namespaces`, and
the values under `suppress_namespace_channels` use typed selectors. Channel keys
keep `ChannelLevelSelector` (exact or `X.*`, with its valid optional level).
First-match attribution and `neverMatched` compare stable
authored identities, not rendered PCRE strings.

`ValueScopeJudgement` no longer extracts a literal glob head. Exact/subtree may
retain sound location-based partial-run judgement. Regex is judged only when the
run covers the complete relevant universe; a partial run stays silent. The
application matcher and binding matcher are the same object/value path.

## P4 — report drill-down and graph projection

**Owner files:**

- `src/Reporting/DrillDown/FindingFilter.php`
- `src/Reporting/DrillDown/DrillDownBinding.php`
- `src/Reporting/FormatterContext.php`
- `src/Infrastructure/Console/FormatterContextFactory.php`
- `src/Infrastructure/Console/ResultPresenter.php`
- `src/Reporting/Formatter/Summary/TopIssuesRenderer.php`
- `src/Reporting/Formatter/Json/JsonFormatter.php`
- `src/Reporting/Formatter/Summary/HealthBarRenderer.php`
- `src/Reporting/Formatter/Summary/OffenderListRenderer.php`
- `src/Reporting/Formatter/Json/JsonOffenderSection.php`
- `src/Reporting/Formatter/Json/JsonHealthSection.php`
- `src/Reporting/Health/HealthScoreResolver.php`
- `src/Analysis/Evidence/ComputedMetrics/Health/Contract/DrillDown/HealthScoreDrillDown.php`
- `src/Analysis/Evidence/ComputedMetrics/Health/Contract/DrillDown/WorstClassDrillDown.php`
- `src/Analysis/Evidence/ComputedMetrics/Health/Offender/WorstOffenderBuilder.php`
- `src/Reporting/GraphProjection/NamespaceFilter.php`
- `src/Reporting/GraphProjection/Contract/GraphProjectionRequest.php`
- `src/Reporting/GraphProjection/DotExporterOptions.php`
- `src/Reporting/GraphProjection/DotExporter.php`
- `src/Reporting/GraphProjection/JsonGraphExporter.php`
- `src/Reporting/GraphProjection/DependencyGraphProjector.php`
- `src/Infrastructure/Console/Command/GraphExportCommand.php`
- Console check/directives request construction for `--namespace`
- Reporting/GraphProjection and Console README sections

**Test files:** Reporting DrillDown/GraphProjection Unit and Integration tests,
Console graph/check/directives functional tests.

**Behavior:** `--namespace` and graph `--namespace`/`--exclude-namespace` accept
typed CLI scalars. Class drill-down remains exact. Project sentinel and file
subjects remain outside namespace selection. `DrillDownBinding` counts the same
candidate strings that `FindingFilter` offers the matcher and refuses a selector
binding zero objects. Summary/JSON top issues, health aggregation, and worst
classes consume the same bound matcher. `FormatterContext` keeps authored display
text separate from the executable pattern. The direct-namespace comparison note
in `HealthBarRenderer` is shown only for `exact`; subtree/regex scores are the
weighted aggregate over all selected namespaces and have no invented single
`SymbolPath`. Graph include is OR, graph exclude is OR, exclusion wins.

## P5 — coupling configuration

**Owner files:**

- `src/Analysis/Evidence/Coupling/CouplingAnalysis.php`
- `src/Analysis/Evidence/Coupling/Contract/Configuration/CouplingConfiguratorInterface.php`
- `src/Analysis/Evidence/Coupling/DistanceOptions.php`
- `src/Analysis/Evidence/Coupling/DistanceRule.php`
- `src/Analysis/Evidence/Coupling/UnmatchedFrameworkNamespaceRule.php`
- `src/Infrastructure/Console/AnalysisRuntimeConfigurator.php`
- Coupling README and owning website rule pages in both languages

**Test files:** Coupling Unit/Integration tests and Console runtime-configurator
tests. Framework-classification-site governance remains intact but changes its
fixtures to typed definitions.

**Behavior:** `coupling.framework_namespaces` and distance
`include_namespaces` use namespace selectors. Framework classification and its
unmatched rule ask the same matcher over `FrameworkClassificationSites`.
Distance auto-detection remains a separate exact inferred set; only the explicit
override changes form.

## Execution order and stage gate

P3, P4, and P5 execute sequentially. Their production sets are disjoint, but
Console functional tests and migrated selector fixtures are shared; claiming
parallel file ownership would let two packages rewrite the same test corpus.
P3 owns the first migration of shared suppression fixtures, and P4 extends those
already-migrated files with namespace drill-down cases. P5 runs after both and
adds only Coupling-specific cases.

The stage gate runs each owner's focused suites plus Console integration, then a
reverse search proves there is no string-to-selector reconstruction in these
owners and no direct `fnmatch()` or private namespace-prefix predicate remains.
