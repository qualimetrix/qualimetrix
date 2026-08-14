# P6 — Finding, Inline Policy, Baseline Policy, Prioritization, and finding projection

[Back to the modular-architecture overview](../modular-architecture.md)

> **Final closure — COMPLETE.** P6 landed the Finding, Inline Policy, Baseline
> Policy, Prioritization, and FindingProjection boundary. Final authority is
> 754 declarations in 752 files, 37 owners, zero seams, 51 exact grants
> collapsing to eight owner pairs, and 223 qmx allows; discovery contains 509
> PHPUnit classes / 7,251 semantic IDs. The final host `composer check` exited
> 0: 7,251 tests / 23,654 assertions / one skip, 17 Python tests, PHPStan over
> 1,280 files, architecture 754/752 and 37/0/51/8, 692 artifacts, 107 fixture
> directories, and workers=0 dogfood over 752 files with active 0 / stale 0.
>
> `native-codex-01` implemented the minimal public
> `RuleDefinitionInterface` class-string metadata contract; the independent
> address-check returned GO after closing the FQCN bypass, plain-literal gap,
> and generator PHPStan issue. `native-codex-02` completed the expanded
> 11-file current-document sweep and closed all confirmed MEDIUM/LOW findings.
> `native-codex-03` returned GO for the anchored
> `/qmx-baseline.json.lock`: the runtime recreated the ignored zero-byte lock
> without changing the baseline hash. The final baseline rekey changed exactly
> three offsets while preserving payloads `[8]`, `[8]`, `[40]`, count `1`, and
> the 208-entry cardinality. No commit or push was performed; branch and HEAD
> remain `codex/modular-architecture-p3` and
> `57fa22fa0d0f074cb11590e358fc01faff3eccf1`.
>
> **Next phase:** P7 and P8 remain pending. P7 has not started and is eligible
> only after a new explicit request.

> **Historical checkpoint note:** the paused 753 declarations / 751 files
> status and its three open native-codex findings below record the pre-closure
> review state; they are not current authority.

> **P6-E governance checkpoint:** **COMPLETE with publication deferred to
> P6-F.** `OutputConfigurator` now composes
> Reporting's private `FindingProjector`, Inline's private `SuppressionFilter`
> behind `AnnotationSuppressionInterface`, and the Infrastructure Git adapter
> behind Reporting's `GitScopeQueryInterface`; the removed Console
> `ViolationFilterPipeline` has no registration or source reference. The eight
> original P6 internal imports are absent from the composition source, while
> exact private string service IDs preserve compiler-pass and container wiring.
> The compiler/container selection passes 48 / 247, projection adapters pass
> 90 / 196, runtime/Inline/channel wiring passes 62 / 238, and the compiled
> container confirmation passes 22 / 162. Direct test inventory proves the
> reviewed 509 classes / 7,251
> IDs after adding only
> `AnalysisPipelineIntegrationTest::itPreservesInlineControlsAcrossARealParallelWorkerRoundTrip`.
> Its sequential half proves non-empty suppression, threshold override, and
> diagnostic payloads; the host Amp rerun passes the selected worker ID
> (`1 test / 9 assertions`, exit 0). Scratch production is green at 750
> declarations / 748 files / 37 owners / zero seams / 51 grants / eight coarse
> edges. Seven remaining original P6 grants close here; the eighth,
> `AnalysisConfigurator -> RuleExecution`, was already removed in P6-A, so 51
> is the honest final count rather than 50. Direct test authority is 509 / 7,251
> before the expected stale-suite publication stop, and focused governance is
> green (`1 test / 450 assertions`). Four separately classified Baseline/Console failures remain with
> their P6-D owner: three stale output-text expectations and one measured-set
> exclusion assertion. They are not composition failures and were not changed
> in P6-E.
>
> **Historical publication status:** this pre-closure checkpoint predates the
> final native review fixes. Its authority was 753 declarations / 751 files /
> 37 owners / zero seams / 51 grants / eight coarse edges and 509
> PHPUnit classes / 7,251 IDs. Exact magnitude comparison maps all 270 published
> baseline groups (`262 equal + 8 improved`), proves 11 accepted groups resolved,
> and leaves zero worsened, unmatched-fresh, duplicate, active, or stale rows.
> The reviewed PHPDoc-only DeclarationPath rekey is zero-net and preserves all
> 270 accepted groups.
> The Baseline capability now materializes the exact 45 production declarations,
> 38 test classes / 397 IDs, 20 fixtures, and three support files under
> `Analysis\\Policy\\Baseline`. All non-container Baseline behavior is green;
> 32 container-backed Baseline IDs and the dedicated container cleanup ID remain
> expected-red until P6-E registers Inline's moved `SuppressionFilter`. Of the
> 50 permanent Console regression IDs, the 14 reviewed mock call sites now use
> `exclusionStats()` under P6-D. Scoped lint, PHPStan, and project CS are green.
> **Earlier plan review:** five findings were addressed by this revision and
> the three deterministic ledgers linked below. The final native review then
> confirmed three findings, all subsequently closed: `native-codex-01` was
> implemented and independently address-checked GO, `native-codex-02` completed
> the documentation sweep, and `native-codex-03` closed the lock-file gate.
> **P6-B cycle amendment (reviewed and accepted):** the first governance checkpoint
> proved a real `Finding -> Inline -> Finding` owner cycle. The sole
> Finding-to-Inline edge was `AnalysisContext -> ThresholdOverride`, while
> Inline correctly consumes Finding rule/filter contracts. `ThresholdOverride`
> therefore moves to `Analysis\Finding\Contract\Threshold`, its application
> owner. `ControlScope` moves with it to `Analysis\Finding\Contract\Control`:
> Finding calls `specificity()` to select an override, while Inline only
> extracts, carries, and serializes the shared scope. Splitting identical
> suppression and threshold scope enums would add conversion and divergent wire
> states without a distinct semantic invariant. The corrected graph is
> `Run -> Inline -> Finding -> Core`, with no Finding-to-Inline edge. The two
> affected tests (11 and 6 IDs) move to Finding; all counts remain unchanged.
>
> **P6-B governance checkpoint:** **COMPLETE with publication/P6-E expected-red.**
> Scratch production is green at 745 declarations, 37 owners, zero seams, 59
> exact grants, and 10 coarse edges. Both residual P6 seams became unnecessary
> after the cycle correction and are removed without replacement. The manifest
> contains zero old Inline `ControlScope`/`ThresholdOverride` FQCNs and exact
> Finding-owned contract rows. Direct semantic discovery proves 190 selected
> IDs: the 164-ID Inline closure, the retained eight-ID binding test, the one
> planned SourceControls addition, and the 17 Finding-owned threshold IDs.
> Excluding exactly the two already classified P6-E DI methods, the focused
> selection passes 188 tests / 790 assertions. Scratch test publication and the
> encompassing existing governance ID stop at the stale old
> `phpunit.xml.dist` Inline test path before DI discovery; changing that shared
> publication file belongs to P6-E/P6-F, not this checkpoint.
>
> **P6-B amendment:** subject-cohesion resolution for `DeclarationBindings` is
> recorded in this plan and all three ledgers; its amendment review is accepted.
> **P6-C atomicity amendment (reviewed and accepted):** moving Baseline cannot leave
> four already-loaded external consumers importing removed FQCNs. P6-C may
> therefore edit exactly `ViolationFilterOrchestrator`,
> `ViolationFilterPipeline`, `ViolationFilterResult`, and `OutputConfigurator`
> for mechanical Baseline FQCN/import/DI scan-root adaptation only. Their
> control flow, construction arguments, stage order, result shape, service
> visibility, and ownership remain frozen for P6-D/P6-E. Exact types and tests
> are enumerated in the production, relation, and test ledgers.
> **P6-C governance checkpoint:** **COMPLETE with the reviewed P6-E/P6-D
> expected-reds preserved.** Scratch production is green at 745 declarations,
> 743 files, 37 owners, zero seams, 59 exact grants, and 10 coarse edges. The
> manifest contains exactly 45 `Analysis.Policy.Baseline / P6-C` declarations,
> all under `src/Analysis/Policy/Baseline`, and zero old `Qualimetrix\Baseline`
> declarations or `src/Baseline` paths. The finite Baseline test classifier is
> pinned by its exact 61-path digest: 38 PHPUnit classes / 397 IDs, 20 fixtures,
> and three supports. Direct discovery proves 38/397. The 364 cases not
> requiring pending Inline service registration pass with 962 assertions; the
> other 32 dataset-expanded cases fail only because P6-E has not registered
> `Inline\Contract\SuppressionFilter`. The three permanent Console regression
> classes retain 50 IDs and stop on exactly 14 reviewed `exclusionsStats()`
> mock API errors reserved for P6-D. Scratch aggregate test generation remains
> publication-red at the stale old Inline path in `phpunit.xml.dist`, which
> this checkpoint does not edit.
> **P6-B source checkpoint:** **COMPLETE; P6-E DI/governance handoff is
> required before repository-wide discovery.** The live source moves the exact
> 11 source rows (nine Inline-owned plus Finding-owned `ControlScope` and
> `ThresholdOverride`) plus the amended Run binding to Inline, adds only
> `SourceControlExtractorInterface`, and keeps `FileProcessor` on that contract
> with zero Run or Infrastructure imports of the internal
> `Extraction\\DeclarationControlBindings`. The amended eight binding IDs are
> retained and `SourceControlsTest` adds the planned
> `itExtractsSourceControlsWithoutRunDeclarationBindings` ID. Binding plus
> source-controls tests pass 12 / 68; FileProcessor, worker bootstrap, and PHP /
> igbinary round trips pass 39 / 148; the moved threshold integration passes 13
> / 283; and the Inline root passes 188 / 791 when excluding exactly the two
> container-dependent methods below. Scoped sequential PHPStan, PHP lint,
> project CS, and diff-check are green. The isolated production inventory is
> expected-red on exactly 12 old missing and 13 target extra declarations. Test
> inventory discovery and the two excluded methods await P6-E registration of
> the moved `Contract\\SuppressionFilter` service:
>
> - `InlineSuppressionLayerViolationIntegrationTest::qmxIgnoreOnSourceDoesNotDropTargetAttributedArchitectureLayerViolation`;
> - `ThresholdValidatorWiringTest::factoryProducesValidatorForEveryThresholdAwareRuleInRegistry`.
> **P6-A governance checkpoint:** **COMPLETE; topology publication is the next
> root-owned step, then P6-B.** The
> live source materializes exactly 61 Finding declarations (57 moved/replaced
> declarations plus four new contracts), one `FindingConfigurator`, and 34
> Finding test classes / two fixtures / two support files. The scratch
> production generator passes schema and declaration/contract validation, then
> originally failed closed on three unapproved imports from
> `FindingConfigurator`: the internal registry, internal execution
> implementation, and internal logging adapter. All three source defects are
> fixed. The third resumed scratch generation then failed closed on stale
> authority: consumer entry `#1` (`Analysis.Finding`) on
> `Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface`
> was unused because Finding no longer imports that transitional provider. The
> exact obsolete entry is now removed without replacement. The next scratch
> generation then failed closed on unused consumer entry `#0`
> (`Analysis.Configuration`) of
> `Analysis\Finding\Contract\Rule\AdditionalOptionKeysInterface`; that stale
> entry is removed while the observed `Analysis.Evidence.Coupling` consumer is
> preserved. The following stale `Analysis.Configuration` entry on
> `Analysis\Finding\Contract\Rule\CliAliasReader` is also removed while its
> observed `Infrastructure.Console` and `Infrastructure.Rule` consumers remain.
> The next scratch run stops on unused consumer entry `#0`
> (`Analysis.Configuration`) of
> `Analysis\Finding\Contract\Rule\RuleMatcher`; that stale entry is removed
> while `Analysis.Policy.Inline` and `Analysis.Run` remain. The next scratch run
> stops on unused consumer entry `#0` (`Analysis.Configuration`) of
> `Analysis\Finding\Contract\Rule\RuleNameReader`; that stale entry is removed
> while its four observed consumers remain. The next scratch run stops on
> unused consumer entry `#0` (`Analysis.Configuration`) of
> `Analysis\Finding\Contract\Rule\RuleOptionKey`; that stale entry is removed
> while its 13 observed consumers remain. The next scratch run stops on unused
> consumer entry `#0` (`Analysis.Configuration`) of
> `Analysis\Finding\Contract\Rule\RuleOptionsInterface`; that stale entry is
> removed while its 14 observed consumers remain. The next scratch run stops on
> unused consumer entry `#0` (`Analysis.Configuration`) of
> `Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface`; that stale
> entry is removed while its eight observed consumers remain. The next scratch
> run stops outside the moved Finding contracts on unused consumer entry `#0`
> (`Analysis.Configuration`) of `Core\Util\NamespaceMatcher`; that stale entry
> is removed without changing its P8 ownership/closure while its three observed
> consumers remain. The next scratch run stops on unused consumer entry `#0`
> (`Analysis.Configuration`) of the adjacent P8 contract
> `Core\Util\PathMatcher`; that stale entry is removed without changing P8
> ownership/closure while its two observed consumers remain. The next scratch
> run passed consumer checks and stopped on the now-unused existing internal grant
> `Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass` to
> `Infrastructure\Console\Command\RulesCommand`: the compiler pass now injects
> only Finding-owned `RuleExecution`. That P8 grant is removed without
> replacement, reducing the live grant count to 61. The next scratch run stops
> on another unused existing grant from `AnalysisConfigurator` to internal
> Finding `RuleExecution`: the configurator now imports and references only
> `RuleExecutionInterface`. That grant is removed without replacement, reducing
> the live grant count to 60. The next scratch run stops outside Finding on the
> unused P8 grant from `ConfigurationConfigurator` to internal
> `Infrastructure\Logging\DelegatingLogger`; the configurator no longer imports
> or references that logger. That grant is removed without replacement while
> preserving the logger's P8 declaration closure, reducing the live grant count
> to 59. The next scratch run stops because the P6-E enforcement seam on
> Finding `RuleExecution` is no longer necessary to break the current graph;
> it is removed immediately while one reviewed internal grant into that
> declaration remains, reducing the seam count to five. The next scratch run
> passes exact authority and DAG checks, then fails extension inventory with
> expected 41 rules but zero observed because the generator still identifies
> rules through removed `Core\Rule\RuleInterface` rather than Finding-owned
> `Analysis\Finding\Rule\RuleInterface`. That target is corrected and the
> 41-rule gate passes. The next scratch run stops on the stale phase-participant
> source `src/Analysis/RuleExecution/RuleExecutor.php`; the live Finding-owned
> execution source is `src/Analysis/Finding/RuleExecution.php`. That row is
> re-keyed while preserving 24 phase rows and 41-rule semantics. The next
> scratch run stops on unclassified active P6 planning documentation, beginning
> with `docs/internal/plans/modular-architecture/p6-finding-policy.md`; the
> canonical plan and its three P6 ledgers require exact documentation
> dispositions. Those four exact rows are added with P6-F/P6-0 lifecycles and
> finite-link assertions. The next scratch run stops on the new capability
> documentation `src/Analysis/Finding/README.md`, which still needs its exact
> `Analysis.Finding / P6-A` classifier row. That exact row is added; the updated
> Configuration README remains `Analysis.Configuration / P3`. Production
> scratch is now green at 744 declarations, 37 owners, five seams, 59 exact
> grants, and ten coarse edges. Test scratch then stops on the first
> unclassified moved Finding artifact,
> `tests/Analysis/Finding/Fixtures/Channels/declared.txt`; the finite P6-A test
> classifier needed all 34 classes, two fixtures, and two support files. The
> exact 38-path allowlist is now enforced without wildcard enrollment; scratch
> test inventory is green at 34 Finding classes / 578 IDs / two fixtures / two
> supports and 509 / 7,248 globally. Focused governance, schema/JSON, PHP lint,
> scoped CS, diff-check, and private-leak gates are green. Direct
> `architecture:check` stops only on expected generated-artifact freshness;
> publication remains root-owned and no generated artifact was edited here.
> **Prerequisites:** P0–P5 are complete. The accepted snapshot is 739
> declarations in 737 files, 37 owners, 6 singleton seams, 62 exact internal
> grants projecting to 10 owner pairs, and 509 PHPUnit classes / 7,245 semantic
> test IDs.

## Outcome and invariants

P6 gives findings and each policy/projection stage one honest subject owner:

- `Analysis\Finding` owns rule metadata, rule configuration, rule execution,
  finding values, filtering primitives, and per-rule exclusion state.
- `Analysis\Policy\Inline` owns source annotations and their extraction,
  validation, and filtering semantics.
- `Analysis\Policy\Baseline` remains an independent peer capability; it owns
  baseline persistence, migration, capture, ceiling judgement, staleness, and
  explanation.
- `Analysis\Evidence\Prioritization` owns debt and impact evidence.
- `Reporting` owns presentation and output-only finding projection. Git remains
  an Infrastructure adapter behind a Reporting-owned port.
- `Analysis\Run` owns only invocation order. It owns no policy state and gains
  no generic lifecycle, finding-evaluation, policy pipeline, or graph/file-set
  participant.

Behaviour is preserved. In particular, Git scope changes only the reported
view; it cannot change measured findings, baseline acceptance, capture,
staleness, or inert-entry classification.

## P6-0 authoritative inventory gate

The current manifest contains exactly **156 P6 declarations**. This is the
authority to reconcile before implementation; the former 60-class execution
slice is not a complete P6 inventory.

The row-level authorities are tracked appendices, not prose summaries:

- [156-row production mapping](p6/p6-production-ledger.md);
- [86-class / 939-ID plus fixture/support mapping](p6/p6-test-ledger.md);
- [declared consumers and observed import relations](p6/p6-relations-ledger.md).

Their embedded input checksums bind this design to the reviewed P5 snapshot.
P6-0 must reproduce all three byte-for-byte or amend and re-review the plan.
Reviewed appendix SHA-256 values are production
`4066cf886d8f8a9b7ff61529b6c7bf731dbb85bbb6784de471d54f6be58161a8`,
tests `4a9e62bd717656f11f0f4808be9516d18f0cc88756ed4fd551a0f3fab6101434`,
and relations
`e13551182d29a0ac8b2783aeb2a3c50c42e1e62ef988e92e2f4b67ffeceb236f`.

| Semantic owner                     | Exact current set                                                                                                                                                                                                                                                                    | Count   |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------: |
| `Analysis.Finding`                 | `Analysis\RuleExecution\{RuleExclusionStats, RuleExecutor, RuleExecutorInterface}`; all 27 declarations under `Core\Rule`; all 17 under `Core\Violation`; `Core\Suppression\{ControlScope, ThresholdOverride}`; `Rules\AbstractRule`; `Rules\Support\ThresholdParser`                | 51      |
| `Analysis.Policy.Inline`           | `Analysis\Collection\SourceControl\SourceControls`; `Baseline\Suppression\{RuleValidatorMapFactory, SuppressionExtractor, SuppressionFilter, ThresholdOverrideExtractionResult, ThresholdOverrideExtractor}`; `Core\Suppression\{Suppression, SuppressionType, ThresholdDiagnostic}` | 9       |
| `Analysis.Policy.Baseline`         | The 45 manifest rows under `Baseline` excluding the five `Baseline\Suppression` rows listed above                                                                                                                                                                                    | 45      |
| `Analysis.Evidence.Prioritization` | `Reporting\Debt\{DebtCalculator, DebtSummary, RemediationTimeRegistry}`; `Reporting\Impact\{ClassRankIndex, ClassRankResolver, ImpactCalculator, RankedIssue}`                                                                                                                       | 7       |
| `Reporting`                        | `CoverageFailure`, `Filter\ViolationFilter`, `FormatterContext`, `GroupBy`, `Profile\ProfileSummaryRenderer`, `Report`, `ReportBuilder`, `ReportCoverage`, `Health\{HealthScoreResolver, SummaryEnricher}`; all 34 declarations under `Reporting\Formatter`                          | 44      |
| **Total**                          | Exact union above; `51 + 9 + 45 + 7 + 44 = 156`; no fourth `Analysis\RuleExecution` declaration exists in the current manifest                                                                                                                                                       | **156** |

The 27 `Core\Rule` declarations are exactly:
`AdditionalOptionKeysInterface`, `AnalysisContext`, `Attribute\CliAlias`,
`ChannelDeclarationReader`, `CliAliasReader`, `HierarchicalRuleInterface`,
`HierarchicalRuleOptionsInterface`, `InMemoryRuleChannelRegistry`,
`LevelOptionsInterface`, `Override\IndependentAxisValidator`,
`Override\InvertedOverrideValidator`, `Override\OverrideValidationFailure`,
`Override\OverrideValidatorInterface`, `Override\StandardOverrideValidator`,
`Override\StandardOverrideValidatorTrait`, `Override\WarningOnlyValidator`,
`RuleCategory`, `RuleChannelRegistryInterface`, `RuleInterface`, `RuleLevel`,
`RuleMatcher`, `RuleNameReader`, `RuleOptionKey`, `RuleOptionsInterface`,
`RuleSelector`, `ShorthandOptionKeysInterface`, and
`ThresholdAwareOptionsInterface`.

The 17 `Core\Violation` declarations are exactly:
`AcceptedLevel`, `ChannelDeclaration`, `ChannelDeclarationRegistryInterface`,
`ChannelShape`, `Filter\NamespaceExclusionFilter`,
`Filter\PathExclusionFilter`, `Filter\PredicateFilterStage`,
`Filter\ViolationFilterInterface`, `Filter\ViolationFilterStage`,
`Filter\ViolationFilterStageInterface`, `Filter\ViolationFilterStageResult`,
`Location`, `OccurrenceKey`, `RuleExclusionCaptureHolder`, `Severity`,
`Violation`, and `ViolationChannel`.

The 45 Baseline declarations are exactly:
`Baseline`, `BaselineCapture`, `BaselineCleaner`, `BaselineCleanupCandidate`,
`BaselineCleanupReason`, `BaselineCleanupRemoval`, `BaselineConflictException`,
`BaselineEdge`, `BaselineEntry`, `BaselineEntryMode`, `BaselineEntryParser`,
`BaselineEntryRejection`, `BaselineEntryUpdateOutcome`, `BaselineEntryValues`,
`BaselineGenerator`, `BaselineIdentity`, `BaselineLoader`, `BaselineMigrator`,
`BaselineMigratorResult`, `BaselineUpdateDisposition`,
`BaselineUpdateRefusalReason`, `BaselineUpdateResult`, `BaselineUpdater`,
`BaselineWriter`, `BoundaryExplanation`, `BoundaryExplanationService`,
`BoundaryExplanationStatus`, `EffectiveBoundary`,
`EffectiveBoundaryBaselineSource`, `EntrySelector`,
`Filter\BaselineCeilingStage`, `Filter\CeilingOutcome`,
`Filter\GroupCeilingVerdict`, `GroupAcceptance`, `InertBaselineEntry`,
`InertEntryReason`, `MigrationReport`, `MigrationReportDroppedEntry`,
`RunScope`, `UncapturedGroup`, `UncapturedReason`, `V5Baseline`,
`V5BaselineReader`, `V5Entry`, and `V5UnreadableRecord`.

The 34 formatter declarations are exactly:
`CheckstyleFormatter`, `FormatterInterface`, `FormatterRegistry`,
`FormatterRegistryInterface`, `GitLabCodeQualityFormatter`,
`GithubActionsFormatter`, `Health\HealthTextFormatter`,
`Html\HtmlDebtCalculator`, `Html\HtmlFormatter`, `Html\HtmlMetricAggregator`,
`Html\HtmlTreeBuilder`, `Html\HtmlTreeNode`,
`Html\HtmlViolationPartitioner`, `Json\JsonFormatter`,
`Json\JsonHealthSection`, `Json\JsonOffenderSection`, `Json\JsonSanitizer`,
`Json\JsonViolationSection`, `MetricsJsonFormatter`, `Sarif\SarifFormatter`,
`Sarif\SarifRuleCollector`, `Summary\HealthBarRenderer`,
`Summary\HintRenderer`, `Summary\OffenderListRenderer`,
`Summary\SummaryFormatter`, `Summary\TopIssuesRenderer`,
`Summary\ViolationSummaryRenderer`, `Support\AcceptedLevelNarrator`,
`Support\AnsiColor`, `Support\CoverageNarrator`,
`Support\DetailedViolationRenderer`, `Support\ViolationSorter`,
`TextFormatter`, and `TextVerboseFormatter`.

### Test inventory and arithmetic gate

The generated P6 test inventory contains exactly **118 artifacts**: **86
discovered PHPUnit classes / 939 semantic IDs**, 27 fixtures, and 5 support
files.

| Target subject                                             | PHPUnit classes | Existing IDs |
| ---------------------------------------------------------- | --------------: | -----------: |
| `Analysis/Finding`                                         | 29              | 256          |
| `Analysis/Policy/Baseline`                                 | 38              | 397          |
| `Analysis/Policy/Inline`                                   | 13              | 164          |
| `Reporting/FindingProjection`                              | 3               | 72           |
| Infrastructure integration seams that remain adapter-owned | 3               | 50           |
| **Total**                                                  | **86**          | **939**      |

P6-0 must mechanically enumerate the 86 current test-class rows, all method
IDs in those classes, and the complete non-owned integration test set before
review. It must then check the following mandatory new method intents against
existing IDs and assign final exact `itXxx` names without collision:

1. rule execution exposes immutable metadata without concrete rule instances;
2. exclusion statistics cross the Finding contract unchanged;
3. namespace exclusion can be configured and queried without a provider getter;
4. namespace-channel exclusion can be configured and queried without a provider getter;
5. path exclusion can be configured and queried without a provider getter;
6. two runs reset Finding rule configuration and exclusions independently;
7. Inline extraction no longer depends on Run-owned declaration bindings;
8. sequential collection preserves suppression, threshold, and diagnostic output;
9. parallel worker serialization preserves the same Inline output;
10. the complete measured/projection stage order is asserted by one authority;
11. annotation rejoin occurs after baseline judgement and before Git projection;
12. Git scope is last and cannot alter accepted, stale, or inert baseline facts;
13. Reporting projection depends on the Reporting Git port, not the concrete adapter;
14. Baseline fail-safe behaviour is unchanged after relocation;
15. all six P6 seams can return to their semantic owners without a cycle;
16. all eight P6 temporary grants are absent and no replacement grant appears;
17. no taxonomy target, generic lifecycle, or uncovered moved declaration appears.

The dispositions are now finite. Intents 2, 5, 6, 8, 10, 11, 12, and 14 augment
existing IDs respectively named `itExclusionStatsAreResetOnEachExecuteCall`,
`itFiltersViolationsByExcludePaths`, `itExclusionStatsAreResetOnEachExecuteCall`,
the three existing `SourceControlsTest` IDs,
`itRunsTheBaselineStageImmediatelyBeforeGitScope`,
`itReportsAnAnnotatedFindingAtItsOwnSeverityWhenTheRunDisablesAnnotations`,
`itMarksNothingStaleOnAGitScopedRun`, and the existing sixteen
`BaselineCeilingStageFailSafeTest` IDs. Intents 15–17 augment architecture
governance IDs and add no P6-owned discovery ID. Intents 1, 3, 4, 7, 9, and 13
are honest new IDs:

- `itPublishesRuleMetadataWithExactAliasMappingWithoutConcreteRuleInstances`;
- `itConfiguresAndQueriesNamespaceExclusionsWithoutProviderAccess`;
- `itConfiguresAndQueriesNamespaceChannelExclusionsWithoutProviderAccess`;
- `itExtractsSourceControlsWithoutRunDeclarationBindings`;
- `itPreservesInlineControlsAcrossARealParallelWorkerRoundTrip`;
- `itProjectsGitScopeThroughTheReportingPortWithoutAReverseImport`.

Their exact placements are, respectively,
`RuleExecutorTest`, `RuleNamespaceExclusionProviderTest`, `RuleNamespaceExclusionProviderTest`,
`SourceControlsTest`, `AnalysisPipelineIntegrationTest`, and
`GitScopeFilterProjectSubdirTest`. The two namespace-exclusion-provider
additions in `RuleNamespaceExclusionProviderTest` move with the
eight deferred-declaration tests even though those tests were classified under
P3 rather than the 86-class P6 closure; the global arithmetic still counts the
new methods once.

No existing ID is deleted or replaced. All six are added to existing classes,
so the global class count remains **509** and the global semantic ID count is
**7,245 + 6 = 7,251**. The manifest-closure P6 inventory receives only the
`SourceControlsTest` addition and becomes **86 classes / 940 IDs**, with 27
fixtures and 5 supports. Complete P6 regression authority is the 86 closure
classes plus five existing non-closure classes: 91 classes, `939 + 36 + 13 + 6
+ 2 + 8 = 1,004` retained IDs, then six additions = **1,010 IDs**. Later packages may
change these numbers only by reviewed plan amendment.

## Exact deferred declarations

P6 atomically moves these eight P3-deferred declarations; all target
implementations are internal:

| Current FQCN                                    | Target FQCN                                                          |
| ----------------------------------------------- | -------------------------------------------------------------------- |
| `Configuration\RuleNamespaceExclusionProvider`  | `Analysis\Finding\Exclusion\RuleNamespaceExclusionProvider`          |
| `Configuration\RulePathExclusionProvider`       | `Analysis\Finding\Exclusion\RulePathExclusionProvider`               |
| `Configuration\RuleOptionThresholdModeResolver` | `Analysis\Finding\RuleConfiguration\RuleOptionThresholdModeResolver` |
| `Configuration\RuleOptionsFactory`              | `Analysis\Finding\RuleConfiguration\RuleOptionsFactory`              |
| `Configuration\RuleOptionsParser`               | `Analysis\Finding\RuleConfiguration\RuleOptionsParser`               |
| `Configuration\RuleOptionsParserFactory`        | `Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory`        |
| `Configuration\RuleOptionsRegistry`             | `Analysis\Finding\RuleConfiguration\RuleOptionsRegistry`             |
| `Configuration\RuleThresholdKeyGroupRegistry`   | `Analysis\Finding\RuleConfiguration\RuleThresholdKeyGroupRegistry`   |

## Target layout, contracts, and visibility

Physical moves preserve class names unless the table below names a replacement.
No compatibility namespace or alias remains.

| Current set                                                           | Target subject/layout                                               | Public surface and named consumers                                                                                                                                                                                                                                                                                                 |
| --------------------------------------------------------------------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Analysis\RuleExecution\*`                                            | `Analysis\Finding\RuleExecution\*`                                  | Replace `RuleExecutorInterface` with `Finding\Contract\RuleExecutionInterface`; Run consumes `execute(AnalysisContext): list<Violation>` and rule metadata; Console consumes `Finding\Contract\RuleExclusionStats`. `RuleExecutor` stays internal.                                                                                 |
| `Core\Rule\*`, `Rules\AbstractRule`, `Rules\Support\ThresholdParser`  | `Analysis\Finding\Rule\*`                                           | Types imported by named evidence, policy, Run, Console, Rule, or DI consumers live under exact `Finding\Contract\Rule` children. Readers, registries, matcher implementations, factories, and concrete validators stay internal. `RuleInterface` remains internal to Finding/composition and is never returned by a public method. |
| `Core\Violation\*`                                                    | `Analysis\Finding\Contract` and `Analysis\Finding\Filter`           | Finding values/stage protocols used by rules, policies, Run, Reporting, Console, and DependencyModel are contracts. Concrete filters remain internal unless a named consumer imports that exact operation.                                                                                                                         |
| eight deferred Configuration declarations                             | `Analysis\Finding\{Exclusion,RuleConfiguration}`                    | Providers and parsers stay internal. Publish `Finding\Contract\RuleOptionsDocument` and a narrow `RuleConfigurationInterface` only for exact Configuration/Console/Run consumers.                                                                                                                                                  |
| `Core\Suppression\*`, five `Baseline\Suppression\*`, `SourceControls` | `Analysis\Policy\Inline\{Contract,Extraction,Filtering,Validation}` | Run `FileProcessor` consumes exact `SourceControlExtractorInterface::extract(...) : SourceControls`. Baseline consumes only exact threshold values. Infrastructure Parallel/DI consumes exact construction contracts where an observed import remains.                                                                             |
| `DeclarationBindings`                                                 | `Analysis\Policy\Inline\Extraction\DeclarationControlBindings`      | Move/rename/demote to an Inline-internal binding implementation. Its only external effect crosses through `SourceControlExtractorInterface`; Run never imports the internal value.                                                                                                                                                 |
| 45 Baseline declarations                                              | `Analysis\Policy\Baseline\*`                                        | Preserve exact Console/DI contracts for load/write/generate/update/clean/explain and exact Reporting ceiling projection contracts. Everything else becomes internal.                                                                                                                                                               |
| seven debt/impact declarations                                        | `Analysis\Evidence\Prioritization\{Debt,Impact}`                    | Reporting consumes only exact summary/ranked-result contracts. Calculators and indexes are internal unless an observed named consumer requires a contract.                                                                                                                                                                         |
| 44 Reporting declarations                                             | `Reporting\{FindingProjection,Formatter,Health,Profile}`            | Projection exposes one framework-free operation/result. Formatters and registry retain only observed contracts. Internal registry/renderer implementations are not published.                                                                                                                                                      |
| current Git scope filtering                                           | Reporting port + Infrastructure adapter                             | `Reporting\FindingProjection\Contract\GitScopeQueryInterface` accepts the resolved report scope and project root and returns the changed-path projection required by Reporting. `Infrastructure\Git` implements it; Reporting never imports a concrete Git client/filter.                                                          |

`RuleOptionsRegistry` no longer exposes `getExclusionProvider()` or
`getPathExclusionProvider()`. Its Finding-owned surface performs exact
namespace, channel, and path configuration/query operations and reset. The
providers remain private implementation details. `RuleExecutionInterface`
returns immutable metadata rather than `list<RuleInterface>`.

The exact Finding execution contract is:

- `execute(AnalysisContext): list<Violation>`;
- `allRules(): list<RuleMetadata>` preserves current `getAllRules()` order;
- `activeRules(RuleSelection): list<RuleMetadata>` preserves current
  `getActiveRules()` filtering and order;
- `totalRuleCount(): int` preserves current registered/all count semantics;
- `exclusionStats(): RuleExclusionStats` preserves the last-run/empty-before-
  first-run semantics.

`RuleMetadata` is a readonly Finding contract with `name: string`,
`optionsClass: class-string<RuleOptionsInterface>`, `category: RuleCategory`,
`description: string`, `aliases: array<string,string>` mapping alias to
canonical option name, and `active: bool`. Metadata is
derived once from registered rules; it does not retain a rule instance. Exact
consumers are: Run executes rules and uses `allRules()` metadata for threshold
diagnostics; `RulesCommand` reads all/active views plus category, description,
aliases and counts; selection/filter diagnostics read active/count semantics;
`RuleExecutorTest`, `RulesCommandTest`, `RulesCommandWiringTest`,
`RuleInputValidator` tests, and threshold-diagnostic pipeline tests preserve
the current behaviour. The only production consumers of current
`getAllRules()` are RuleExecution itself and `AnalysisPipeline`; the only
production consumers of active/count are RuleExecution itself, while Console
currently receives concrete rule collections separately. P6-A must converge
both paths on these views without changing output or ordering.
`RulesCommand` output and CLI parsing must preserve every exact alias mapping,
not merely the set of alias names; the metadata test named above asserts the
alias-to-canonical map and the existing RulesCommand output test asserts the
same rendered aliases/order.

`TransitionalResolvedConfiguration::$ruleOptions` and the transitional runtime
holder slot are replaced by `RuleOptionsDocument` plus the exact
Finding-owned configuration operation. Configuration parses the document;
Finding owns normalization, rule-option construction, exclusion state, and
per-run reset. P6 must not add another universal runtime DTO.

## Dependency DAG

The target graph is acyclic and names only leaf owners:

```text
Evidence and policy rule producers -> Analysis.Finding contracts
Analysis.Run -> Analysis.Policy.Inline extraction
Analysis.Run -> Analysis.Finding rule execution
Analysis.Policy.Baseline -> Analysis.Finding contracts
Analysis.Policy.Baseline -> Analysis.Policy.Inline threshold contracts
Analysis.Evidence.Prioritization -> Measurement contracts + Finding contracts
Reporting -> Finding + Baseline + Prioritization + ComputedMetrics.Health contracts
Infrastructure.Console/DI/Git -> named public contracts
Infrastructure.Git -> Reporting-owned GitScopeQueryInterface
```

Forbidden reverse edges are `Finding -> Baseline|Inline|Reporting`,
`Inline -> Baseline`, `Reporting -> Infrastructure\Git`, and any dependency on
the `Analysis`, `Analysis\Evidence`, or `Analysis\Policy` taxonomy roots.

Reporting consumes Inline through exactly
`Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface::apply(list<Violation>): AnnotationSuppressionResult`.
The result contains ordered `retained` and `suppressed` finding lists. The
named consumer is `Reporting\FindingProjection\FindingProjector`; it uses
`suppressed` only for the post-baseline annotation rejoin. This permanent
`Reporting -> Inline` relation is included in the target DAG and is covered by
the authoritative stage-order test; Reporting never imports Inline internals.

## P3 transitional field closure

| Field                                      | Current producer/storage/consumers                                                                                                                                                     | P6 replacement                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | Exact writers and regressions                                                                                                                                                                                                                                                                                                                                            |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `format`                                   | Configuration pipeline -> `TransitionalRuntimeConfiguration`; sole live read is `ResultPresenter`                                                                                      | new Configuration-owned `Analysis\Configuration\Contract\OutputFormat(string $value)` on `TransitionalResolvedConfiguration::$outputFormat`; Configuration constructs it after precedence merging and Console passes its value to `FormatterRegistryInterface::get()`                                                                                                                                                                                                                                                                                                                                                                                                                    | `TransitionalRuntimeConfiguration`, `TransitionalResolvedConfiguration`, `ConfigurationPipeline`, `DefaultsStage`, `ResultPresenter`; existing Configuration pipeline/merger/reachability and ResultPresenter/formatter-output tests                                                                                                                                     |
| `disabledRules`, `onlyRules`               | Configuration pipeline/runtime DTO; live readers are `AnalysisRuntimeConfigurator`, `RuleInputValidator`, `CheckCommand`, and Run producer preparation                                 | existing `Finding\Contract\RuleSelection` on `TransitionalResolvedConfiguration::$ruleSelection`; Configuration preserves current merge/order, `AnalysisRuntimeConfigurator` configures it once, validation and conflict warning read it, and `RuleProducerPreparation` reads `RuleConfigurationInterface::selection()` internally for all three producer gates                                                                                                                                                                                                                                                                                                                          | the two Configuration DTOs and pipeline, `AnalysisRuntimeConfigurator`, `RuleInputValidator`, `CheckCommand`, `RuleProducerPreparation`, and the pipeline composition writer; existing configuration selection/merge, rule validation, conflict-warning, producer-gate and pipeline tests                                                                                |
| global `excludePaths`, `excludeNamespaces` | Configuration pipeline/runtime DTO; sole live policy reader is `MeasuredViolationSet`, which merges them with `CliOnlyNarrowing`; GraphProjection has an unrelated command-local field | new Configuration-owned `ResolvedFindingExclusions(excludePaths, excludeNamespaces)` on `TransitionalResolvedConfiguration::$findingExclusions`. `CheckCommand` is the sole full projection-options assembler: configuration lists first, then CLI lists, ordered first-occurrence deduplication, plus Baseline path, annotation flag, and Git request. `ViolationFilterOrchestrator` consumes that complete value and only invokes projection/renders diagnostics. `BaselineRun` separately constructs exclusion-only options because Baseline commands have no projection CLI surface. `FindingProjector` applies them after annotation suppression. GraphProjection remains unchanged | `TransitionalRuntimeConfiguration`, `TransitionalResolvedConfiguration`, `ConfigurationPipeline`, `CheckCommand`, `BaselineRun`, `ViolationFilterOrchestrator`, `MeasuredViolationSet`, `FindingProjector`; exact existing-ID dispositions below plus merge/reachability, pipeline order, measured-set and formatter golden tests. No GraphProjection writer is required |

P6-D removes these five fields from the transitional runtime DTO and its holder;
it does not conflate GraphProjection exclusions with finding policy. Exact
`TransitionalResolvedConfiguration` construction, `AnalysisRuntimeConfigurator`,
`RuleInputValidator`, `CheckCommand`, Run pipeline/producer preparation,
`MeasuredViolationSet`, `ViolationFilterOrchestrator`, `ResultPresenter`, and
their existing tests are the finite affected writer/verification set.
`FormatterContextFactory`, `GraphExportCommand`, `GraphProjectionRequest`, and
the DOT/JSON exporters are verified but not written: their live fields are
independent of these five transitional fields.

## P6-D finite declaration amendment (review pending)

P6-D performs the following exact declaration actions around the original
156-row closure: the six Console/Git live declarations and six additions are
outside it, while the Inline `SuppressionFilter` action supersedes row 46
inside it. `MOVE_RENAME` and `MOVE_RENAME_DEMOTE` preserve one declaration;
`DELETE_MERGE` removes one; the six `ADD_CONTRACT` rows add six. Starting from
the P6-C authority of 745 declarations, the net is `745 - 1 + 6 = 750`.

| Current declaration                                 | Action               | Target declaration / visibility                                                                       |
| --------------------------------------------------- | -------------------- | ----------------------------------------------------------------------------------------------------- |
| `Infrastructure\Console\ViolationFilterPipeline`    | `MOVE_RENAME`        | `Reporting\FindingProjection\FindingProjector`, internal service                                      |
| `Infrastructure\Console\ViolationFilterResult`      | `MOVE_RENAME`        | `Reporting\FindingProjection\FindingProjectionResult`, contract consumed by Console                   |
| `Infrastructure\Console\ViolationFilterOptions`     | `MOVE_RENAME`        | `Reporting\FindingProjection\FindingProjectionOptions`, contract constructed only by Console adapters |
| `Infrastructure\Console\GitScopeFilterConfig`       | `MOVE_RENAME`        | `Reporting\FindingProjection\Contract\GitScopeRequest`, contract                                      |
| `Infrastructure\Git\GitScopeFilter`                 | `MOVE_RENAME`        | `Infrastructure\Git\ReportingGitScopeQuery`, adapter implementing the Reporting port                  |
| `Analysis\Policy\Inline\Contract\SuppressionFilter` | `MOVE_RENAME_DEMOTE` | `Analysis\Policy\Inline\Suppression\SuppressionFilter`, internal implementation                       |
| `Infrastructure\Console\CliOnlyNarrowing`           | `DELETE_MERGE`       | no declaration; its three fields move into `FindingProjectionOptions`                                 |
| new                                                 | `ADD_CONTRACT`       | `Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface`                                      |
| new                                                 | `ADD_CONTRACT`       | `Analysis\Policy\Inline\Contract\AnnotationSuppressionResult`                                         |
| new                                                 | `ADD_CONTRACT`       | `Reporting\FindingProjection\Contract\GitScopeQueryInterface`                                         |
| new                                                 | `ADD_CONTRACT`       | `Reporting\FindingProjection\Contract\GitScopeResult`                                                 |
| new                                                 | `ADD_CONTRACT`       | `Analysis\Configuration\Contract\OutputFormat`                                                        |
| new                                                 | `ADD_CONTRACT`       | `Analysis\Configuration\Contract\ResolvedFindingExclusions`                                           |

`AnnotationSuppressionInterface::apply(list<Violation> $violations,
array<string,list<Suppression>> $suppressions): AnnotationSuppressionResult`
is stateless at the public boundary. The result has ordered `retained` and
`suppressed` lists. `SuppressionFilter` may keep its matching helpers internally,
but no external owner calls `setSuppressions()`, `clearSuppressions()`,
`shouldInclude()`, or `getSuppressedViolations()` after P6-D.

`FindingProjectionOptions` contains exactly `?string $baselinePath`, ordered
`list<string> $excludePaths`, ordered `list<string> $excludeNamespaces`,
`bool $annotationSuppressionDisabled`, and `?GitScopeRequest $gitScope`.
Configuration never imports this Reporting type. `ResolvedFindingExclusions`
contains only the two ordered `list<string>` configuration values, while
Configuration-owned `OutputFormat` validates the exact non-empty formatter
name. `CheckCommand` alone combines both configuration lists with the CLI lists
in configuration-first order, deduplicates each list by first occurrence, and
adds Baseline/annotation/Git values. `ViolationFilterOrchestrator` accepts the
complete options value and has no assembly helper. `BaselineRun` maps only the
resolved exclusions because Baseline commands do not render or accept those
projection-only flags. No second DTO is introduced.

The inspectable merge boundary is Configuration-owned:
`ResolvedFindingExclusions::withAdditional(list<string> $paths,
list<string> $namespaces): self`. It returns configuration values first and
then additional values, independently deduplicated by stable first occurrence.
`CheckCommand` calls it once with the two CLI lists before constructing the
Reporting options. Exact order/deduplication is asserted in the existing
`AnalysisConfigurationTest::itMergesConfigurations` ID. The CheckCommand E2E
asserts only externally observable combined exclusions and preservation of
Baseline, annotation, and Git behavior; reverse-import/governance inspection,
not an unobservable E2E claim, proves that Orchestrator has no assembly helper
and no additional DTO exists.
`FindingProjector::project(list<Violation> $violations,
array<string,list<Suppression>> $suppressions, FindingProjectionOptions $options):
FindingProjectionResult` preserves the current result fields: reported and
measured findings, per-stage removals, stale/inert Baseline entries, and
nullable Baseline scope. `MeasuredViolationSet` remains a Console orchestration
service for Baseline commands; it invokes Run and delegates the pure measured
projection to `FindingProjector` with no Baseline/Git request. Reporting never
imports Run.

## Reporting-owned Git port

The framework-free port is
`GitScopeQueryInterface::resolve(GitScopeRequest): GitScopeResult`.
`GitScopeRequest` contains `reference: string`, `projectRoot: AbsolutePath`,
and `includeParentNamespaces: bool`; it contains no Console input, Git client,
or Infrastructure type. `GitScopeResult` contains ordered unique
project-relative PHP paths. When `includeParentNamespaces=false`, its namespace
list is exactly empty. When it is true, namespaces are ordered by changed-file
order and declaration order, each declaration followed nearest-to-farthest by
its parents, with the first occurrence retained globally.

The sole adapter is `Infrastructure\Git\ReportingGitScopeQuery`, which replaces
the current `GitScopeFilter`, wraps
`GitClient`, preserves Git output order, drops deleted and non-PHP files,
normalizes at the Git boundary to project-relative slash-separated paths,
deduplicates first occurrence, joins only against the explicit project root,
and tokenizes readable files for namespaces only when parents are requested.
An unreadable or locally missing non-deleted PHP file retains its normalized
changed path and contributes no namespace; deleted and non-PHP rows contribute
neither path nor namespace. This is the exact fallback.
Strict report mode sets `includeParentNamespaces=false`; default mode includes
the normalized namespace and every parent. The sole application consumer is
`Reporting\FindingProjection\FindingProjector`; Console constructs only the
request value from `GitScopeResolution::$reportScope->ref`, project root, and
the inverse of `--report-strict`. Writer files are the new Reporting
contract/values/projector, the exact two replacement adapter/value files,
`ViolationFilterOrchestrator`, and their existing Git/project-subdirectory/
pipeline tests. Output DI wiring is deferred explicitly to P6-E. A static architecture guard rejects every
`Reporting\\** -> Infrastructure\\Git\\**` import.

## Authoritative runtime order

The runtime contract is the composition of the existing named authorities
below; P6-D does not claim that one unit test observes all eleven cross-package
operations:

1. Run collection parses files and Inline extracts `@qmx-ignore`, threshold
   overrides, and diagnostics.
2. Measurement aggregation and computed evidence finish.
3. Finding executes active rules and applies per-rule namespace, namespace-
   channel, and path exclusions.
4. Finding appends threshold/unsupported-override diagnostics.
5. Reporting begins finding projection with global annotation suppression.
6. Reporting applies global path exclusion.
7. Reporting applies global namespace exclusion. The result after steps 5–7
   is the measured set.
8. Baseline judges ceilings, accepted groups, stale entries, and inert entries.
9. When `--no-suppression-annotations` is active, annotated findings rejoin
   after baseline judgement.
10. Git scope narrows the report last.
11. The selected formatter renders the projected result and diagnostics.

`FindingProjectorTest` asserts the exact six-operation projection subsequence:
annotation suppression -> path exclusion -> namespace exclusion -> Baseline
judgement -> optional annotation rejoin -> Git scope. Existing
`AnalysisPipelineTest` (19 IDs), specifically
`itKeepsCollectionArchitectureEnrichmentAndRulesInExactOrderForDegreeZeroClasses`,
`itDelegatesComputedMetricEvaluationForZeroAnalyzedFiles`,
`itCarriesThresholdOverridesFromCollectionOntoTheResult`, and
`itWarnsWhenThresholdAnnotationTargetsUnsupportedRule`, owns operations 1–4;
`AnalysisPipelineIntegrationTest` (7 IDs) corroborates the real Run path but
is not the ordering authority;
`FindingProjectorTest` (36 retained IDs) owns operations 5–10;
`CaptureFromMeasuredSetTest` (5 IDs) proves the measured-set/Baseline boundary;
`CheckCommandBaselineTest` (5 IDs) composes collection through projection in a
real command; and `CoverageProjectionFormatterTest` (55 IDs) proves operation
11 without output-shape drift. There is no honest existing single E2E ID that
observes every internal boundary, so this named composition is authoritative.

Console parses CLI values and renders messages only. The current framework-free
logic in `ViolationFilterPipeline`, `MeasuredViolationSet`, and the non-I/O
part of `ViolationFilterOrchestrator` moves behind Reporting projection and
Baseline contracts. Console-owned DTOs may remain adapters; they cannot become
application-policy owners.

### P6-B source-control extraction contract amendment

`Analysis\Policy\Inline\Contract\SourceControlExtractorInterface` is owned by
Inline because Inline promises source-annotation interpretation to the named
Run consumer `Analysis\Run\Collection\FileProcessor`. Its single operation
accepts the parsed AST, relative file path, callable measurement facts, and
the exact live class map
`array<string, array{subject: MetricSubject, metrics: MetricBag, line: int}>`
already produced by `FileProcessor::extractClassMetrics()`, and returns
Inline-owned `SourceControls` containing suppressions, threshold overrides,
and threshold diagnostics. The exact PHP type shapes are recorded in the
production ledger; there is no `ClassWithMetrics` conversion.

`SourceControls` crosses the Inline-to-Run call boundary as the extractor's
return value. `FileProcessor` then projects its three lists — suppressions,
threshold overrides, and threshold diagnostics — into the existing
`SuccessfulFileProcessing` wire payload used by sequential and worker paths;
the `SourceControls` object itself is not serialized. The three nested value
graphs are therefore public contract surface. `Suppression`,
`SuppressionType`, and `ThresholdDiagnostic` are promoted into subject-specific
Inline Contract namespaces. Finding owns `ControlScope` under
`Contract\Control` and `ThresholdOverride` under `Contract\Threshold`; Inline
publishes them inside `SourceControls` as exact nested Finding contracts. Their scalar,
`MetricSubject`, enum, ordering, nullable-field, and validation semantics remain
byte-for-byte serialization-compatible.

`Analysis\Policy\Inline\Extraction\DeclarationControlBindings` is internal.
It maps AST declarations to subjects/scopes solely to implement Inline
annotation semantics. `FileProcessor` calls the contract once after measurement
extraction. Sequential and worker paths use the same `FileProcessor` operation,
so no binding object is serialized and no parallel-only contract is added.

The dependency direction is:

`Analysis.Run -> Analysis.Policy.Inline.Contract -> Analysis.Finding.Contract -> Core`

Inline may consume exact Measurement contracts for callable/class facts; it
does not import Run. No new seam, internal grant, generic lifecycle, or FileSet
participant is permitted.

## Seam and temporary-grant closure

All six remaining singleton seams close in P6, only after a temporary-output
generator probe proves that returning all six together to their semantic
owners leaves the graph acyclic:

1. `Analysis\Collection\SourceControl\SourceControls`;
2. `Analysis\RuleExecution\RuleExecutor`;
3. `Analysis\Run\Contract\Collection\Declaration\DeclarationBindings`;
4. `Baseline\Suppression\RuleValidatorMapFactory`;
5. `Baseline\Suppression\SuppressionFilter`;
6. `Core\Rule\RuleMatcher`.

All eight exact P6 grants close without replacement:

1. `ChannelDeclarationCompilerPass -> Core\Rule\RuleInterface`;
2. `FormatterCompilerPass -> Reporting\Formatter\FormatterRegistry`;
3. `RuleCompilerPass -> Analysis\RuleExecution\RuleExecutor`;
4. `RuleOptionsCompilerPass -> Core\Rule\RuleInterface`;
5. `AnalysisConfigurator -> Analysis\RuleExecution\RuleExecutor`;
6. `OutputConfigurator -> Reporting\Formatter\FormatterRegistry`;
7. `OutputConfigurator -> Reporting\Formatter\Support\DetailedViolationRenderer`;
8. `ContainerFactory -> Core\Rule\RuleInterface`.

Dedicated capability configurators register exact implementation roots and
publish public aliases. Literal tag/class metadata may name an internal class
where no PHP import is created, but no exact internal import grant survives.

## Serial work packages

Packages execute `P6-0 -> P6-A -> P6-B -> P6-C -> P6-D -> P6-E -> P6-F`.
They share Finding contracts, Run, DI, topology, and projection-order seams, so
they are intentionally serial.

### P6-0 — inventory, contract ledger, and reviewed arithmetic

**Sole writers:** this plan and its review amendments only.

Re-enumerate from source/manifest the 156 declarations, all exact current
imports and consumers, eight deferred declarations, six seams, eight grants,
118 test artifacts, 86 discovered classes, 939 IDs, non-owned integration
consumers, documentation rows, cache/wire class names, and shared DI/runtime
writers. Publish the exact old-FQCN -> action -> target-FQCN table and the exact
retained/renamed/new/deleted test-method ledger. Set the provisional final
global test count only from that ledger. Review this complete plan before A.

**Stop:** any count mismatch, unlisted consumer, target collision, dynamic
class-name/cache dependency, or inability to give every declaration and test
one owner blocks implementation.

### P6-A — Finding and rule configuration

**Writer set:** the exact 49 Finding rows, eight deferred Configuration files,
their Finding-owned tests/fixtures/support, exact Configuration/Console
consumers of rule-option documents and `RuleSelection`, a dedicated Finding DI
configurator, and the exact zero-net method rename in
`RuleCompilerPassTest::itCollectsTaggedRulesIntoRuleExecution`.
No Baseline, Inline, Reporting, generated, qmx, or baseline file is written.

Implement metadata-based rule execution, Finding-owned option document and
configuration operation, private exclusion providers, and per-run reset.

**DoD:** focused lint/style/static analysis and Finding tests pass; dynamic rule
discovery and constructor dependencies are unchanged; two-run isolation is
proved; no contract returns a concrete rule or provider.

**Stop:** concrete `RuleInterface` leakage, provider getter, static holder,
new internal grant, or discovery/DI behaviour change blocks B.

### P6-B — Inline source policy

**Writer set:** the exact nine Inline-owned rows, the two Finding-owned
`ControlScope`/`ThresholdOverride` rows, `DeclarationBindings`, exact
`FileProcessor`/worker/bootstrap/serialization consumers, and Inline-owned
tests/fixtures/support. It does not write Baseline policy, Reporting, shared DI,
or publication files.

Move extraction, suppression, thresholds, diagnostics, and binding semantics
under Inline. Move/rename `DeclarationBindings` to internal
`Extraction\DeclarationControlBindings`; move/rename its eight-ID test without
changing IDs. Introduce only `SourceControlExtractorInterface` and keep
`SourceControls` as the exact result consumed by Run.

**DoD:** `FileProcessor` imports only the Inline contract/result, never internal
bindings; sequential and real parallel collection produce identical
suppression, threshold, and diagnostic payloads; the eight binding IDs and
class count are preserved exactly; round-trip tests prove all `SourceControls`
fields and nested contract value types survive sequential and worker
serialization with ordering, enum cases, subjects, nulls, and numeric threshold
types unchanged; no Run policy state or generic participant exists; focused
gates pass.

**Stop:** worker payload/class-map incompatibility, Run-owned replacement seam,
Run-to-Inline internal import/grant, public binding value, or altered annotation
semantics blocks C.

### P6-C — Baseline peer capability

**Writer set:** the exact 45 Baseline rows, Baseline-owned tests/fixtures/support,
exact Baseline command adapters, plus import-only adaptation in these four
external consumers:

- `src/Infrastructure/Console/ViolationFilterOrchestrator.php` — `RunScope`;
- `src/Infrastructure/Console/ViolationFilterPipeline.php` — `BaselineEntry`,
  `BaselineLoader`, `Filter\BaselineCeilingStage`, `InertBaselineEntry`;
- `src/Infrastructure/Console/ViolationFilterResult.php` — `BaselineEntry`,
  `InertBaselineEntry`;
- `src/Infrastructure/DependencyInjection/Configurator/OutputConfigurator.php`
  — `BaselineCleaner`, `BaselineGenerator`, `BaselineLoader`,
  `BaselineUpdater`, `BaselineWriter`, `BoundaryExplanationService`, and the
  exact Baseline prototype namespace/resource root.

These four exceptions permit only old-to-new Baseline FQCNs, corresponding
`use` statements, class constants/references, and the prototype scan root and
exclude strings needed for container loading. P6-C does not otherwise write
Reporting projection, Git, shared DI, or publication files; semantic
Console/Reporting edits remain P6-D and composition redesign remains P6-E.

Move Baseline as one subject, preserving persistence formats and failure
semantics. Consume only Finding and Inline contracts.

**DoD:** generation/load/write/migrate/update/clean/explain, capture,
acceptance, promotion, stale/inert reporting, memory ceiling, incomplete
analysis, and fail-safe regressions pass; measured-set identity remains one
run. All 50 IDs in the three permanent Console regression classes pass, and
`ContainerFactoryTest::itHasBaselineCleanupCommandWithAllDependencies` proves
the import-only `OutputConfigurator` adaptation still builds the same command.
The package diff for each of the four external files contains no executable
logic change beyond class-name/resource-root replacement.

**Stop:** baseline format drift, a second analysis run, fail-open behaviour, or
`Inline -> Baseline` dependency blocks D. Any changed branch, argument order,
stage order, DTO field, service registration semantics, or additional external
writer stops P6-C for a separate amendment rather than borrowing P6-D/P6-E.

### P6-D — Prioritization and Reporting finding projection

**Checkpoint (complete with P6-E/publication expected-reds):** seven
Prioritization declarations and their five subject tests moved; Reporting owns
the framework-free projection and Git port, with the Infrastructure adapter
implementing it. The Configuration-neutral amendment is materialized: no
`Configuration -> Reporting` edge remains, and full options assembly is
confined to `CheckCommand` while `BaselineRun` remains exclusion-only. Scratch
production is green at 750/748/37, zero seams, and 58 grants. The finite test
authority is 509/7,250; the eight moved subject/adapter classes pass 144 tests /
231 assertions, and the existing governance authority passes 448 assertions.
Only P6-E DI wiring and P6-F publication remain.

**Writer set:** the exact seven Prioritization rows and 44 Reporting rows; the
six added contracts and seven current declarations in the finite table above;
`MeasuredViolationSet`, `ViolationFilterOrchestrator`, `AnalysisRuntimeConfigurator`,
`RuleInputValidator`, `CheckCommand`, `BaselineRun`, `ResultPresenter`, `AnalysisPipeline`,
the two existing Configuration contract DTOs, new `OutputFormat` and
`ResolvedFindingExclusions`, `ConfigurationPipeline`, and `DefaultsStage`; the exact
tests named in the P6-D test-ledger amendment; five exact Prioritization
subject-unit test moves (three Debt and two Impact, 90 IDs); and import/FQCN-only
adaptation of the remaining 15-file / 269-ID external consumer set (18 files /
334 IDs are newly enrolled overall, two / 25 already overlap). It does not write
manifest, generated, qmx, baseline, or shared DI files.

Move debt/impact evidence, create framework-free Reporting projection, create
the Reporting-owned Git port, and adapt Infrastructure Git. Preserve all
formatter output shapes.

**DoD:** the six-operation projection order has one authoritative contract
test and the complete eleven-operation runtime order is bound to the named
existing test composition above;
annotation rejoin and Git-last semantics are explicit; golden text/JSON/SARIF/
Checkstyle/GitLab/GitHub/HTML/health output remains stable; focused JS gates run
if any HTML template changes; path/namespace normalization, deduplication,
strict/default parent namespaces, unreadable-file fallback, and the reverse-
import guard pass.

**Stop:** any `Reporting -> Infrastructure\Git` import, Git effect on measured
or baseline facts, framework input inside projection, or output-shape drift
blocks E.

### P6-E — DI/runtime integration and topology closure

**Sole writers:** Finding/Inline/Baseline/Reporting capability configurators,
the exact shared compiler passes, `ContainerFactory`, runtime configurators,
container/DI integration tests including the exact zero-net replacements
`ContainerFactoryTest::itInjectsRulesIntoRuleExecution` and
`RuleExclusionStatsWiringTest::itSharesTheSameRuleExecutionInstanceBetweenThePipelineAndTheOrchestrator`,
manifest and governance-source tests needed to
model the intended target. Versioned generated outputs, qmx, baseline, PHPUnit
configuration, and completion docs remain untouched.

Close the seven original grants still present at P6-E; retain the P6-A proof
that the eighth (`AnalysisConfigurator -> RuleExecution`) was already removed.
All six historical seams are already absent. Run a scratch-output generation
probe and prove the target DAG, exact consumers, zero uncovered declarations,
and absence of every P6 seam and grant.

**DoD:** DI/container/runtime order tests, architecture governance tests, and
the scratch topology probe pass.

**Stop:** any new/widened grant, surviving P6 seam, taxonomy allow target,
uncovered declaration, cycle, or configuration-order drift blocks F.

### Post-P6-E correction — default preservation and exclusion-scope evidence

**Status:** plan amendment review pending. This correction does not change the
P6 declaration mapping, ownership, visibility, DAG, manifest arithmetic, test
class count, or test ID count. Authority remains 750 declarations in 748 files
and **509 PHPUnit classes / 7,251 IDs**.

The accepted default output format remains `summary`. P6-D extracted that value
into `Analysis\Configuration\Contract\OutputFormat`; it did not authorize a
CLI/default behavior change to `text`.

| Exact writer                                                                        | Exact action                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | Explicitly unchanged authority                                                    |
| ----------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| `src/Analysis/Configuration/Contract/OutputFormat.php`                              | set `OutputFormat::DEFAULT` to `summary`; constructor validation and value shape remain unchanged                                                                                                                                                                                                                                                                                                                                                                                                                             | `DefaultsStage` and `TransitionalRuntimeConfiguration` behavior remain unchanged  |
| `tests/Analysis/Configuration/Unit/AnalysisConfigurationTest.php`                   | in `itHasDefaultValues`, `itCreatesFromArrayWithDefaults`, `itFromArrayAcceptsAbsentKeysWithDefaults`, and `itFromArrayTreatsExplicitNullAsDefault`, restore only the default expectation to `summary`                                                                                                                                                                                                                                                                                                                        | all four existing IDs retained; explicit configured `text`/`json` cases unchanged |
| `tests/Analysis/Configuration/Integration/ConfigurationPipelineIntegrationTest.php` | change only the Defaults-priority comment in `complexScenario_allLayersCombined` from `format: text` to `format: summary`                                                                                                                                                                                                                                                                                                                                                                                                     | 16 IDs and all assertions unchanged                                               |
| `tests/Analysis/Policy/Baseline/Functional/BaselineMeasuredSetSeamTest.php`         | within existing `itCapturesExactlyWhatCheckMeasuresOverTheSamePaths`, keep `ConfiguredPath.php` as the path-excluded `code-smell.eval` occurrence fixture; make `ConfiguredNamespace.php` an `App\ConfiguredNamespace` property-bearing class; configure `size.property-count` with exact `warning: 1` and `error: 1`; first run both fixtures with thresholds but without exclusions and assert both exact channels present, then apply the full exclusions configuration and assert both absent from Baseline capture/check | same class/path/three IDs; no production Baseline writer                          |
| `tests/Analysis/Configuration/Unit/Pipeline/Stage/DefaultsStageTest.php`            | no edit                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | `returnsDefaultConfiguration` already proves `summary`                            |
| `tests/Analysis/Policy/Baseline/Functional/BaselineLifecycleTest.php`               | no edit                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | all four lifecycle IDs remain authoritative and unchanged                         |

The Baseline regression must use different finding scopes. Path exclusion is
proved by `code-smell.eval#code-smell.eval` from `ConfiguredPath.php`.
Namespace exclusion is proved by the declaration-scoped
`size.property-count#size.property-count` finding on the class declared in
`App\ConfiguredNamespace`. The no-exclusions control run must observe both
exact channels from these same files before the excluded run; otherwise an
inert fixture could make the negative assertions pass vacuously. Reusing `eval`
for both fixtures is invalid evidence because that occurrence finding does not
prove namespace filtering of a declaration subject.

**Writer set:** exactly the four rows marked with an action above. No manifest,
generator, governance, generated inventory, qmx, baseline snapshot, PHPUnit
configuration, production Baseline, `DefaultsStage`, or `BaselineLifecycleTest`
writer is authorized.

**DoD:** the four named AnalysisConfiguration IDs pass with `summary`; the
pipeline integration comment matches the behavior; the existing Baseline seam
ID first proves both exact channels present without exclusions, then proves
both absent with the full exclusion configuration, and preserves its
capture/check round-trip assertions; focused Configuration and seam selections
pass; global discovery remains 509/7,251.

**Stop:** any formatter-output drift beyond the default correction, any new
test ID, any change to `DefaultsStage` or Baseline production/lifecycle code,
or failure to emit the pre-exclusion property-count finding requires a new
reviewed amendment.

### Post-P6-E correction — split extraction behavior from its result

**Status:** review pending. Fresh no-baseline dogfood found an error-level exact
cycle absent from the accepted baseline. `SourceControlExtractorInterface`
correctly returns `SourceControls`, but `SourceControls` incorrectly implements
that same port. The result and service have different reasons to change and
must not be one declaration.

`Analysis\Policy\Inline\Contract\SourceControls` remains the immutable result
crossing the Inline-to-Run call boundary. It loses the port
implementation and all AST/extraction collaborators and helpers.
`Analysis\Policy\Inline\Extraction\SourceControlExtractor` is the sole new
internal declaration: it implements the existing exact port, owns the moved
extraction behavior, uses `DeclarationControlBindings`, and returns
`SourceControls`. No generic lifecycle or extractor subject is introduced;
both declarations remain owned by Inline policy.

Current production arithmetic changes only by that addition:
**750 declarations / 748 files -> 751 / 749**. The 37 owners, zero seams, 51
grants, eight coarse edges, and all 156 original P6 dispositions remain
unchanged. The materialized P6 set is 157 solely because of this reviewed
post-E correction. Test authority remains **509 classes / 7,251 IDs**.

**Writer set:** exactly
`src/Analysis/Policy/Inline/Contract/SourceControls.php`, new
`src/Analysis/Policy/Inline/Extraction/SourceControlExtractor.php`,
`src/Analysis/Run/Collection/FileProcessor.php`,
`src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php`,
`src/Infrastructure/Parallel/WorkerBootstrap.php`, and the nine exact test
files enumerated in the test ledger. The manifest, production/test generators,
existing P3/P6 governance ID, exact `src/Analysis/Policy/Inline/README.md`, and
active plan/three ledgers are governance-checkpoint writers after source
validation.
Generated inventories, qmx, baseline, PHPUnit configuration, shared
architecture docs, ADR, and CHANGELOG remain P6-F publication writers only.

**Runtime construction:** `FileProcessor` requires
`SourceControlExtractorInterface` and never constructs `SourceControls` as a
service. After extraction it projects the result's three lists into the
existing `SuccessfulFileProcessing` wire; it does not serialize the result
object. `AnalysisConfigurator` registers the exact internal extractor by
private string service ID
`Qualimetrix\\Analysis\\Policy\\Inline\\Extraction\\SourceControlExtractor`
and passes `new Reference($privateExtractorId)`
directly into the private `FileProcessor` definition. It removes/does not add
any `SourceControlExtractorInterface` container alias; the public port remains
the PHP type boundary, not a public service ID.
`WorkerBootstrap` validates the same exact private class string against the
port, constructs it with the threshold extractor, and supplies it to the
worker processor. The internal implementation is never imported by Run or
Infrastructure PHP source.

The new extractor carries exactly one active class-docblock exception:
`@qmx-ignore health.cohesion -- One public extraction operation uses both extraction collaborators; TCC is undefined, promoted constructor properties create an LCOM artifact, and private static traversal helpers push the general health methodCount to its >= 6 eligibility cutoff.`
This is an applicability exception rather than accepted debt: the service has
one public subject operation, both collaborators participate in it, TCC has no
method pair to compare, and the private static helpers only decompose that
operation. No qmx exclusion, baseline row, threshold change, method-level tag,
or second ignored channel is authorized. The exact
`src/Analysis/Policy/Inline/README.md` writer adds the extractor/result split
to the structure and records this applicability reason.

The nine-class test authority is closed without new IDs. In particular,
`WorkerBootstrapTest::itCreatesFileProcessorOnFirstCall` and
`ContainerFactoryTest::itCreatesCompiledContainer` receive the two composition
assertions; the latter proves only that the compiled private `FileProcessor`
receives the effective exact private extractor implementation. Governance
count arithmetic belongs to
`ModularArchitectureGovernanceIntegrationTest::itPinsTheReviewedSnapshotAsComputedEvidence`;
the exact internal declaration/import guards and explicit old-cycle rejection
belong to
`itEncodesThePostP3RunConfigurationMeasurementAndDependencyModelBoundaries`,
whose uncompiled source/definition inspection also proves the direct private
`Reference`, absence of a port alias, and exact full-reason class-level
cohesion tag.
The remaining six retained/renamed authorities and their exact counts are
enumerated in the test ledger.

**DoD:** all four renamed extractor-test IDs and eight binding IDs pass;
FileProcessor, property-hook, pipeline, worker bootstrap, wire-format,
container, and existing governance authorities pass with unchanged method
multisets; scratch production is 751/749/37/0/51 with eight coarse edges;
scratch test discovery is 509/7,251; fresh workers=0 no-baseline dogfood has no
`SourceControlExtractorInterface`/`SourceControls` cycle and introduces no new
active finding; focused lint, scoped CS/PHPStan, finite README ownership/link
checks, ledger checksum validation, private-leak scan, and `git diff --check`
are green. No qmx, baseline, threshold, generated inventory, or PHPUnit
configuration writer is opened by this correction.

**Stop:** any public concrete extractor, Run/Infrastructure import of the
internal implementation, public port container alias, optional FileProcessor
fallback, missing/different cohesion reason, qmx/baseline/threshold mutation,
new grant/seam, changed nested wire-field graph, new test ID, or fresh
unreviewed finding returns the package to plan review.

### P6-F semantic closure amendment (implemented; publication complete)

Fresh workers=0 analysis after the source-control correction first contained
293 grouped rows. After the reviewed 15-row applicability/structural
disposition and renderer split it contains 273 rows. The magnitude-closure
amendment below removes three more structural rows and requires two mapped
groups to return to their accepted ceilings, leaving exactly 270 mapped rows,
zero unmatched fresh rows, and the following 11 accepted rows resolved rather
than rekeyed:

| Accepted identity / channel                                                                                                                                                                                                             | Accepted value / occurrence                                                                 | Resolution / predecessor evidence                                                                                                                                    |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Infrastructure\Console\ViolationFilterPipeline::filter`; `complexity.cognitive#complexity.cognitive.callable`                                                                                                                          | 16                                                                                          | removed with the P6-D Console pipeline; no replacement operation inherits this debt                                                                                  |
| same removed method; `complexity.cyclomatic#complexity.cyclomatic.callable`                                                                                                                                                             | 11                                                                                          | removed with the P6-D Console pipeline; no replacement operation inherits this debt                                                                                  |
| `Analysis\RuleExecution\RuleExecutor`; `coupling.instability#coupling.instability.class`                                                                                                                                                | 0.846154                                                                                    | replaced by Finding-owned `RuleExecution`, which does not emit this channel                                                                                          |
| `ns:Qualimetrix\Core\Rule`; `computed.health#health.cohesion`                                                                                                                                                                           | 46.4                                                                                        | old namespace was split; the only plausible successor is explicitly handled as an applicability exclusion below, not rekeyed into the baseline                       |
| `declaration:callable:Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer::renderGrouped@src/Reporting/Formatter/Support/DetailedViolationRenderer.php:3215`; `complexity.cyclomatic#complexity.cyclomatic.callable`      | magnitude 16; occurrence `null`                                                             | reviewed split moves grouping branches into `ViolationDetailRenderer`; the thin predecessor method is removed and no successor emits this channel                    |
| `declaration:callable:Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer::renderViolation@src/Reporting/Formatter/Support/DetailedViolationRenderer.php:4963`; `code-smell.boolean-argument#code-smell.boolean-argument` | occurrence `f99202a9276ad773d121ed72f78666c70c097e21d1d202af3b79f32517970971`; no magnitude | reviewed split replaces the predecessor's `showFile` boolean branch with location selection inside `ViolationDetailRenderer`; no successor occurrence exists         |
| `declaration:class:Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer@src/Reporting/Formatter/Support/DetailedViolationRenderer.php:612`; `complexity.wmc#complexity.wmc`                                                | magnitude 55; occurrence `null`                                                             | reviewed split leaves a thin compositor and distributes detail/debt behavior across the two named internal helpers; no successor class inherits the accepted WMC row |
| same accepted class identity; `computed.health#health.cohesion`                                                                                                                                                                         | magnitude 40; occurrence `null`                                                             | the reviewed thin compositor/helper graph removes the predecessor class-health finding; no helper or compositor emits a replacement row                              |
| `declaration:class:Qualimetrix\Reporting\FormatterContext@src/Reporting/FormatterContext.php:244`; `coupling.cbo#coupling.cbo.class`                                                                                                    | magnitude 28; occurrence `null`; count 1                                                    | the exact class threshold below accepts the current immutable boundary at raw 29 and rejects dependency 30; no baseline row remains                                  |
| `ns:Qualimetrix\Infrastructure\Git`; `computed.health#health.cohesion`                                                                                                                                                                  | magnitude 44.4; occurrence `null`; count 1                                                  | the exact namespace/channel structural exclusion below removes the current raw 43.3 row without enrolling children or another health channel                         |
| `ns:Qualimetrix\Infrastructure\Parallel`; `coupling.instability#coupling.instability.namespace`                                                                                                                                         | magnitude 0.90625; occurrence `null`; count 1                                               | the exact namespace/channel structural exclusion below removes the current raw 0.911765 row without enrolling children or the class channel                          |

Thus the only permitted baseline transform is **281 accepted -> 270 mapped +
11 removed = 270 final**, with **0 additions**. The first four removals come
from the P6 namespace/pipeline migration, the second four are direct
improvements caused by the reviewed renderer split, and the last three are the
finite structural dispositions below. The raw comparison artifacts
must preserve the exact subjects, channel names, magnitude/occurrence payloads,
and zero-unmatched-new result above.

Fifteen of the 16 fresh rows are finite applicability or structural decisions.
They produce no baseline addition:

| Exact writer/target                                              | Exact action                                                                                                                                                                                                                                                                                                      | Reason and fail-closed evidence                                                                                                                                                                                                                                      |
| ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `TransitionalResolvedConfiguration` class docblock               | add `@qmx-threshold code-smell.constructor-overinjection warning=10 error=10 -- Transitional resolved configuration exposes nine independently owned resolved values; grouping them would recreate the opaque configuration aggregate being dismantled, and the inclusive threshold of 10 rejects a tenth field.` | raw 9 is below both exact thresholds; a tenth promoted field fails at warning and error                                                                                                                                                                              |
| same class docblock                                              | add the identical-reason `@qmx-threshold code-smell.long-parameter-list warning=10 error=10` tag                                                                                                                                                                                                                  | the flat nine-field transitional boundary is intentional; no parameter object or baseline row is introduced                                                                                                                                                          |
| `RuleExecution::metadata()` method docblock                      | add `@qmx-ignore code-smell.boolean-argument -- The private metadata selector maps one rule into the same immutable view for either the complete or active registry; the boolean is an internal two-state query flag, and splitting it would duplicate the exact mapping.`                                        | the exception is exact to this private method/channel; public metadata contracts and active/all semantics remain unchanged                                                                                                                                           |
| `FindingProjector` class docblock                                | add `@qmx-ignore coupling.instability -- Finding projection intentionally composes the six ordered policy operations across Finding, Inline, Baseline, and Git contracts; its two callers and fifteen outgoing types are the reviewed Reporting orchestration boundary.`                                          | exact class/channel only; the six-operation order and framework-free ports remain measured by tests/governance                                                                                                                                                       |
| `coupling.distance.exclude_namespaces`                           | add exact `[Q]ualimetrix\Analysis`                                                                                                                                                                                                                                                                                | raw `0.5947498097757165`; this exact navigation taxonomy owns no type and aggregates independently owned leaf capabilities, so root distance has no subject-level design meaning; child namespaces remain active                                                     |
| same qmx key                                                     | add exact `[Q]ualimetrix\Analysis\Evidence\Prioritization`                                                                                                                                                                                                                                                        | raw `0.5294117647058824`; the exact navigation root unites independently cohesive Debt and Impact subjects and owns no declaration; both child subjects remain active                                                                                                |
| same qmx key                                                     | add exact `[Q]ualimetrix\Analysis\Finding`                                                                                                                                                                                                                                                                        | raw `0.6641604010025063`; the capability root deliberately combines its stable Contract subtree with its small internal execution/configuration services, so the namespace aggregate collapses opposite sides of the same public boundary; descendants remain active |
| same qmx key                                                     | add exact `[Q]ualimetrix\Analysis\Finding\Contract`                                                                                                                                                                                                                                                               | raw `0.6209039548022599`; the exact public-surface root is a union of independently consumed Finding value/filter/rule contracts, not one abstraction unit; child contract subjects remain active                                                                    |
| same qmx key                                                     | add exact `[Q]ualimetrix\Analysis\Finding\Contract\Rule`                                                                                                                                                                                                                                                          | raw `0.505982905982906`; this exact rule contract language intentionally combines interfaces, immutable option values, enums, readers, and selectors promised to the same consumers; its Override child remains active                                               |
| same qmx key                                                     | add exact `[Q]ualimetrix\Analysis\Finding\Contract\Rule\Override`                                                                                                                                                                                                                                                 | raw `0.7375`; concrete validation strategies implement the externally promised override language while abstractions are consumed outside this exact namespace, making its local A/I ratio structurally incomplete; no parent or sibling is enrolled by this row      |
| `computed.health.exclude_namespace_channels.health.cohesion`     | add exact `[Q]ualimetrix\Analysis\Evidence\Prioritization`                                                                                                                                                                                                                                                        | raw `41.4`; the exact root recursively unions independent Debt calculations and Impact ranking, which do not share state by design; both children and every non-cohesion health channel remain active                                                                |
| same qmx channel key                                             | add exact `[Q]ualimetrix\Analysis\Finding\Contract\Rule`                                                                                                                                                                                                                                                          | raw `47.7`; interfaces, immutable option values, enums, readers, and selectors form a contract language rather than a shared-field class cluster; Override and every other health channel remain active                                                              |
| same qmx channel key                                             | add exact `[Q]ualimetrix\Analysis\Policy\Inline\Contract`                                                                                                                                                                                                                                                         | raw `48.6`; immutable extraction results and stateless extraction/suppression contracts intentionally expose disjoint fields while sharing the Inline policy promise; child namespaces and other health channels remain active                                       |
| same qmx channel key                                             | add exact `[Q]ualimetrix\Reporting\FindingProjection`                                                                                                                                                                                                                                                             | raw `44.0`; immutable request/result/options values and the stateless projection orchestrator share one projection lifecycle but not mutable fields; its Contract child and other health channels remain active                                                      |
| `coupling.cbo.exclude_namespace_channels.coupling.cbo.namespace` | add exact `[Q]ualimetrix\Analysis\Policy\Inline\Contract`                                                                                                                                                                                                                                                         | raw 18 is the required union of eight inbound and twelve outbound contract dependencies; exact namespace-channel scope leaves declaration CBO, child namespaces, and all other coupling channels active                                                              |

Every listed reason is placed adjacent to its own qmx row. The raw values are
evidence, not raised global thresholds; `[Q]` is mandatory because a plain
prefix would silently enroll descendants. For each of all 11 qmx rows, the
configuration probe asserts only exact matcher semantics: the named target
matches `true`, while a synthetic `target\Child` matches `false`. No child is
required to emit the same metric/channel, and this amendment claims no
child-channel finding-preservation subset.

The remaining fresh row is real class instability in
`DetailedViolationRenderer` (`0.8181818181818182`, threshold `0.8`). Resolve
it in source rather than excluding it. Reporting gains two internal,
subject-specific collaborators in the existing formatter-support subject:

- `ViolationDetailRenderer::render(list<Violation>, FormatterContext): string`
  owns sorting, grouping, location/severity/message/rule/symbol and accepted-level
  rendering;
- `DebtBreakdownRenderer::__construct(DebtCalculator)` and
  `DebtBreakdownRenderer::render(list<Violation>, ?list<Violation>): string`
  owns debt calculation, all-versus-displayed selection, rule counts, ordering,
  pluralization, and formatted minutes;
- `DetailedViolationRenderer::__construct(DebtCalculator)` remains byte-compatible
  and constructs its same-owner `ViolationDetailRenderer` plus
  `DebtBreakdownRenderer($debtCalculator)` internally; Support is deliberately
  excluded from formatter prototype registration, so no DI definition changes;
- `DetailedViolationRenderer::render(list<Violation>, FormatterContext,
  ?list<Violation>): string` remains the internal formatter-facing compositor,
  owns the two empty-state labels, and joins the two helper outputs without
  changing whitespace or text.

All three declarations remain internal to `Reporting`; no new contract,
owner, grant, seam, or coarse edge is introduced. Authority changes from
**751 declarations / 749 files** to **753 / 751**; owners remain 37, seams zero,
grants 51, and coarse edges eight. The exact 16-ID redistribution is in the
test ledger: four compositor IDs, ten violation-detail IDs, and two debt IDs,
with the same test class/path and no ID change. The existing file-grouping ID
is the non-empty composition seam and must assert both detail and debt output.

**Writer set:** the three exact renderer source files; the existing
`DetailedViolationRendererTest`; `src/Reporting/README.md`; the two exact
class/method docblock writers above; `qmx.yaml`; manifest, production/test
generators, existing governance IDs, generated inventories, and the active
plan/three ledgers. P6-F publication may then update the already-authorized
shared docs/ADR/CHANGELOG/PHPUnit artifacts. `qmx-baseline.json` is read-only
except for removing/rekeying already accepted rows; none of these 16 rows may
be added.

`OutputConfigurator` is explicitly not a writer: it continues to pass the
single `DebtCalculator` constructor argument. The four direct consumer
authorities are also unchanged/non-writers:
`ArchitectureViolationSmokeTest` (11 IDs), `SummaryFormatterTest` (42),
`TextFormatterTest` (22), and `TextVerboseFormatterTest` (11). Their retained
construction sites are compatibility evidence, not permission to edit them.

**DoD:** focused renderer selection retains one class and exactly 16 IDs with
byte-identical returned strings under the existing assertions; fresh production
authority is 753/751/37/0/51 with eight coarse edges; test authority is
509/7,251; the original 15 exact tags/qmx rows suppress only their named subject/channel,
and all matcher probes assert `target=true` plus synthetic
`target\Child=false`; fresh no-baseline workers=0 has no unmatched
row; the magnitude-closure amendment below returns both mapped source groups
to their accepted ceilings and baseline reconciliation publishes exactly 270
mapped rows, removes the 11 resolved rows above, and adds none;
double generation is byte-identical; architecture, governance, focused tests,
lint, scoped CS/PHPStan, links, private leaks, and diff-check are green before
the aggregate gate.

**Stop:** a seventeenth fresh row, any wider prefix exclusion, a global
threshold change, a baseline addition, changed renderer text/order, a new test
class/ID, a public helper, a new owner edge/grant/seam, or failure of any exact
negative probe returns this amendment to review.

### P6-F magnitude closure amendment (historical publication checkpoint; implemented)

This historical amendment closed the five exact magnitude deltas without changing
the authority at that checkpoint: **753 declarations / 751 files / 37 owners / zero seams /
51 grants / eight coarse edges** and **509 PHPUnit classes / 7,251 IDs**.

#### Two source corrections

| Exact writer                                                                   | Finite change                                                                                                                                                                                                                                                                                               | Exact consumers / dependency result                                                                                                                                                                        |
| ------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Analysis/Run/Pipeline/AnalysisPipeline.php`                               | remove the `RuleConfigurationInterface` promoted constructor parameter and the local `selection()` read; call the three preparation operations without `only`/`disabled` arrays                                                                                                                             | constructor magnitude returns from 12 to accepted 11; `AnalysisPipeline` no longer imports Finding rule configuration                                                                                      |
| `src/Analysis/Run/RuleProducerPreparation.php`                                 | add `RuleConfigurationInterface` to this coordinator's constructor; each of `prepareArchitecture(...)`, `prepareCircularDependencies(...)`, and `inspectFiles(...)` reads the current `RuleSelection` internally and applies its `only`/`disabled` lists to the existing `RuleSelector`/file-set operations | the existing Run -> Finding contract dependency moves from the pipeline to its subject-specific preparation collaborator; phase order and configured-selection semantics remain unchanged                  |
| `src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php` | move the exact `RuleConfigurationInterface` reference from the private `AnalysisPipeline` definition to the private `RuleProducerPreparation` definition                                                                                                                                                    | no public alias, service visibility, owner edge, grant, seam, or container surface changes                                                                                                                 |
| `tests/Analysis/Run/Support/Pipeline/TestPipelineBuilder.php`                  | keep `withRuleConfiguration(...)`, but pass its registry to `RuleProducerPreparation` and remove the named `AnalysisPipeline` constructor argument                                                                                                                                                          | all direct pipeline authorities retain their current setup API and IDs                                                                                                                                     |
| `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php`                | remove `normalizeNamespaceExclusions()`; after the two `takeAliasedOption()` calls, unconditionally pass both mixed values to concrete `configureNamespaceExclusions()` and `configureNamespaceChannelExclusions()`                                                                                         | class WMC returns from 77 to accepted 67; `null`/empty handling, scalar/list and channel-map validation, messages, key removal, and public options behavior remain unchanged                               |
| `src/Analysis/Finding/RuleConfiguration/RuleOptionsRegistry.php`               | widen both concrete methods contravariantly to `configureNamespaceExclusions(string, mixed)` and `configureNamespaceChannelExclusions(string, mixed)`; delegate unconditionally to provider `configureExclusions()` and `configureChannelExclusions()` respectively                                         | both `RuleConfigurationInterface` methods remain array-typed for public consumers; the existing provider becomes the single normalization/validation authority for both legacy namespaces and channel maps |

The pipeline correction has one exact contract-consumer rekey in governance:
`RuleConfigurationInterface` drops
`Analysis\Run\Pipeline\AnalysisPipeline` and adds
`Analysis\Run\RuleProducerPreparation`; all four existing Infrastructure
consumers remain. The Finding correction is same-owner delegation and creates
no manifest relation.

#### Three structural dispositions

| Exact writer/target                                                                          | Exact action                                                                                                                                                                                                                                                                   | Exact reason and negative probe                                                                                                                                                                                                                                                                                                                                                                                                                 |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Reporting/FormatterContext.php`, class docblock                                         | `@qmx-threshold coupling.cbo warning=30 error=30 -- Formatter context is the immutable Reporting input boundary that deliberately composes format, grouping, coverage, profile, projection, and path values; the inclusive threshold of 30 rejects one additional dependency.` | current raw 29 is below both exact thresholds; synthetic raw 30 fails at warning and error; no namespace/global threshold changes                                                                                                                                                                                                                                                                                                               |
| `qmx.yaml`, `computed.health.exclude_namespace_channels.health.cohesion`                     | add exact `[Q]ualimetrix\Infrastructure\Git`                                                                                                                                                                                                                                   | raw 43.3 versus accepted 44.4 is structural because the adapter namespace unites independent repository, scope, and changed-file values without shared mutable state; matcher probe requires target `true`, `target\Child` `false`, and another health channel `false`                                                                                                                                                                          |
| `qmx.yaml`, `coupling.instability.exclude_namespace_channels.coupling.instability.namespace` | add exact `[Q]ualimetrix\Infrastructure\Parallel`                                                                                                                                                                                                                              | raw 0.911765 is the truthful Ca=3 / Ce=31 boundary: this delivery-adapter namespace is consumed only at three composition/runtime seams while necessarily depending outward on 31 Run, Finding, Inline, Measurement, framework, serialization, and process types; the ratio measures its adapter role rather than an unstable promised abstraction. Matcher probe requires target `true`, `target\Child` `false`, and class instability `false` |

These rows increase the complete applicability set from 15 to **18**: the
original four source tags plus 11 `[Q]` rows, this exact class threshold, and
these two exact `[Q]` namespace-channel rows. None is a baseline addition.

#### Deterministic magnitude comparator

The P6-F evidence checker is a temporary `/tmp` publication tool, not a project
writer. It groups both snapshots as a multiset and pairs on the exact key
`(normalized identity, channel, occurrence)`. Normalization is limited to the
reviewed FQCN/path move ledger and terminal source offsets. It must reject a
duplicate identical key on either side; `count` is payload, never part of the
pairing key.

For every paired group it compares shape first. Occurrence groups use
`GroupAcceptance::countWithin(currentCount, storedCount)`. Magnitude groups
normalize every current magnitude with
`BaselineEntry::normalizeMagnitude()` (six decimal places) and then call
both `GroupAcceptance::countWithin(currentCount, storedCount)` and
`GroupAcceptance::magnitudesWithin(current, stored, channelWorseDirection)`;
there is no epsilon and no independently reimplemented sign/rank comparison.
Each side's own `count` must agree with its own magnitude-vector shape; the two
sides need not have equal counts because a smaller current group is an honest
improvement.

The report classifies each unique pair as `equal` when normalized payloads are
identical, `improved` when the production acceptance primitive accepts current
but the reverse comparison does not, and `worsened` otherwise. Publication
requires `equal + improved = 270`, `worsened = 0`, unmatched fresh `= 0`,
unmatched accepted `= 11`, duplicate keys `= 0`, and additions `= 0`. The two
source corrections must specifically make pipeline constructor `[11]` equal
to accepted `[11]` and RuleOptionsFactory WMC `[67]` equal to accepted `[67]`;
the three structural rows must be among the 11 unmatched accepted rows, not
silently paired to another subject.

#### Exact test authority and writer set (zero new IDs)

| Exact test/support authority                                                                      | Existing IDs and writer disposition                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Analysis/Run/Unit/RuleProducerPreparationTest.php`                                         | all five existing IDs retain producer enable/reset behavior; in `itSkipsCircularDependencyDetectionWhenRuleDisabled`, configure its `RuleOptionsRegistry` with `RuleSelection(disabled: [CircularDependencyPreparationInterface::PRODUCER_RULE_NAME])`, add an in-method `FileSetInspectionParticipantInterface` whose `producerRuleName()` returns that same constant, call `inspectFiles(...)`, and assert `resetForRun()` occurs but `inspect()` does not. Together with the four retained IDs, this proves all three changed methods read/forward the configured selection without a new helper class or ID                                                                                                                                                                                                                                                                     |
| `tests/Analysis/Run/Unit/Pipeline/AnalysisPipelineTest.php`                                       | unchanged/run-only: retain all 19 IDs; construction adapts through `TestPipelineBuilder`, while `itKeepsCollectionArchitectureEnrichmentAndRulesInExactOrderForDegreeZeroClasses` remains the exact phase-order authority                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `tests/Analysis/Run/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`                     | unchanged/run-only: retain all seven IDs; `itResetsArchitectureAndCircularStateIndependentlyAcrossCompiledContainerRuns` proves runtime selection reaches both preparation capabilities after the dependency move                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| `tests/Integration/DependencyInjection/ContainerFactoryTest.php`                                  | augment existing `itCreatesCompiledContainer` only: reflect the effective private definitions/objects to prove `RuleProducerPreparation` receives the exact rule-configuration instance and `AnalysisPipeline` does not; no new ID                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php`                                          | unchanged/run-only: retain all 93 IDs; the exact affected IDs are `createExtractsExcludeNamespacesSnakeCase`, `createExtractsExcludeNamespacesCamelCase`, `createExtractsExcludeNamespacesStringCoercedToArray`, `itExtractsViolationCodeScopedNamespaceExclusions`, `itRejectsEmptyViolationCodeScopedNamespaceExclusions`, `itRejectsEmptyNamespacePatternsInChannelExclusions`, `itRejectsNonListChannelNamespaceExclusions`, `itRejectsEmptyViolationCodeSelectorsInNamespaceChannelExclusions`, `itRejectsChannelMapsUnderLegacyExcludeNamespaces`, `itRejectsNonStringLegacyNamespaceExclusions`, `createRemovesExcludeNamespacesFromOptionsBeforeFromArray`, and `resetClearsExclusionProvider`; existing assertions prove both unconditional concrete calls preserve null/empty/success/failure behavior while governance proves the duplicate factory normalizer is absent |
| `tests/Analysis/Finding/Unit/RuleNamespaceExclusionProviderTest.php`                              | unchanged/run-only: retain all 15 IDs; `itConfiguresAndQueriesNamespaceExclusionsWithoutProviderAccess` and `itConfiguresAndQueriesNamespaceChannelExclusionsWithoutProviderAccess` are the exact concrete-registry/provider delegation authorities for the two widened methods                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php` | augment `itPinsTheReviewedSnapshotAsComputedEvidence` for the 18 finite dispositions, 270/11/0 arithmetic, exact contract-consumer rekey, absence of the factory normalizer, both unconditional concrete delegate calls, both concrete `mixed` signatures with both public `array` signatures retained, threshold and two matcher negative probes; augment `itKeepsTheModularArchitecturePlanSplitLinkedAndFinite` for the three new ledger hashes; no new ID                                                                                                                                                                                                                                                                                                                                                                                                                       |

Unchanged primitive authorities are run, not edited:
`BaselineEntryTest::itRoundsMagnitudesToSixDecimalPlaces`,
`itStoresMagnitudesAscending`, and
`itRejectsAMagnitudeListThatDisagreesWithTheCount`; and all eight existing
`GroupAcceptanceTest` IDs, including higher/lower magnitude and count growth.

**Writer set:** `AnalysisPipeline`, `RuleProducerPreparation`,
`RuleOptionsFactory`, `RuleOptionsRegistry`, `FormatterContext`,
`AnalysisConfigurator`, the exact `qmx.yaml` rows, the manifest,
`RuleProducerPreparationTest`, `ContainerFactoryTest`, the existing governance
class, `TestPipelineBuilder`, and this plan/three ledgers. The two pipeline
test classes, `RuleOptionsFactoryTest`, `RuleNamespaceExclusionProviderTest`,
baseline primitive tests, generators,
generated inventories,
baseline, PHPUnit suites, and shared docs remain P6-F-only writers after this
amendment is reviewed.

**DoD:** focused Run/Finding/container/governance selections preserve the same
method multiset and global 509/7,251 authority; source authority stays 753/751;
the two accepted magnitude ceilings are restored; all 18 applicability actions
pass exact positive/negative probes; comparator totals are 270 paired, 11
resolved, zero worsened, zero unmatched fresh, zero duplicate keys, and zero
additions; generation is byte-identical; architecture, lint, scoped CS/PHPStan,
links, private leaks, and diff-check are green before publication.

**Stop:** a duplicate comparator key, count/shape mismatch, any epsilon or
home-grown direction comparison, a widened public interface, a second
namespace-normalization path, changed phase order/selection semantics, broader
qmx match, new ID/class/declaration/edge/grant/seam, or any unmatched fresh row
returns this amendment to review.

### P6-F baseline identity rekey amendment (reviewed; publication complete)

The final PHPDoc value typing changes only the terminal DeclarationPath byte
offset of one already accepted finding. The symbol, method body, channel,
occurrence, count, and magnitude are unchanged, so this is an exact identity
rekey rather than a resolution or a new baseline acceptance.

| Predecessor                                                                                                                                                              | Successor                                                                                                                                                                | Immutable payload                                                                                            | Source cause and evidence                                                                                                                                                                                                                                                                                 |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `declaration:callable:Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter::shouldInclude@src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php:2433` | `declaration:callable:Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter::shouldInclude@src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php:2552` | channel `complexity.cyclomatic#complexity.cyclomatic.callable`; magnitude `11`; count `1`; occurrence `null` | the exact `array<string, list<Suppression>>` PHPDoc added before the unchanged method shifts DeclarationPath by 119 bytes; `/tmp/qmx-p6f-dogfood-current.stderr` records the stale predecessor and the independent `--no-cache` run `/tmp/qmx-p6f-dogfood-nocache.json` records the successor and payload |

Publication replaces that single key in place. Baseline authority remains 270
rows: additions 0, removals 0, and the historical 11 resolved groups remain
unchanged. The comparator must pair all 270 rows and write its explicit result
to `/tmp/qmx-p6f-baseline-rekey-comparison.txt`; final workers=0 dogfood must
report active 0, stale 0, and exit 0. Any payload change, a second unmatched
identity, or arithmetic other than `270 paired / 0 added / 0 removed` returns
this amendment to review.

### P6-F — publication, validation, documentation, and final closure

**Historical publication checkpoint:** publication was complete. Generated production/test inventories
are byte-identical across two runs; `architecture:check` is green; the reviewed
270-row baseline rekey pairs every group with zero addition or removal; and
workers=0 dogfood exited zero with no active or stale finding. The later
aggregate validation and independent review are recorded in the final closure
at the top of this document.

**Sole writers:** the manifest publication delta, qmx generated region and
reviewed exact exclusions, evidence-driven baseline rekeys, PHPUnit discovery
configuration, all generated modular-architecture inventories, affected module
READMEs, architecture docs/ADR, CHANGELOG migration entry, website docs, and
P6 completion status. No production behaviour is authored here.

Generate twice and require byte-identical output. Reconcile the baseline from
the immutable pre-P6 snapshot: moves are identity rekeys, improvements tighten
or remove rows, and no new/magnitude-increased debt is accepted without a
separate reviewed source correction or structural-exclusion decision.

Before review, execute the efficient validation order: full PHPStan,
`composer architecture:check`, workers=0 machine-readable dogfood with
`--fail-on=warning`, then one full `composer check`. Persist long-command output
under `/tmp` and inspect explicit exit codes. Run the standard independent
review over the complete P6 diff, including package seams and the exact input
shape no package covered. Verify every finding; return confirmed findings,
including LOW, to their owning package. After fixes rerun affected focused
gates, the three preflight gates, and one final `composer check`, then re-review
when the review workflow requires it.

**Stop:** non-byte-stable generation, discovery loss/duplication, active or
stale dogfood violation, baseline addition, private leak, documentation link
failure, unverified review finding, or any aggregate failure prevents P6 from
being marked complete.

## Post-P6 review amendment — `native-codex-01` (implemented; independent address-check GO)

The completed audit proved the leak was a class-name metadata/transport boundary,
not an instance boundary. Internal `RuleInterface` declares five instance
operations (`getName`, `getDescription`, `getCategory`, `requires`, `analyze`)
and one static operation (`getOptionsClass`). Every external class-string
consumer uses the static operation and/or reflection readers; none requires an
instance. The implementation added exactly
`Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface` with only
static `getOptionsClass(): string` and exact
`class-string<RuleOptionsInterface>` return, then made internal
`RuleInterface` extend it. `NAME`, aliases, category, description,
requirements, channel declarations, and analysis remain outside the public
definition surface.

The exact declaration mapping, 14-file production boundary writer set,
unchanged compiler-pass dispositions, and 754/752 arithmetic are in the
[production ledger](p6/p6-production-ledger.md). The 11 exact named
cross-owner consumers, same-owner inheritance/imports, and unchanged 37/0/51/8
topology are in the [relations ledger](p6/p6-relations-ledger.md). The exact 12
test writers reuse existing IDs, so global authority remains 509/7,251, as
listed in the [test ledger](p6/p6-test-ledger.md).

The focused closure suite passed 240 tests / 2,751 assertions. The parser
resolves absolute, alias, namespace-relative, and nested class-string targets
and fails closed for an external internal target. Its negative matrix preserves
zero targets for plain literals, prose, quoted strings, bare `class-string`,
and template forms; the direct generator PHPStan issue was fixed before the GO.

The completed serial boundary package:

1. added the Finding-owned definition contract and adapted the exact production
   class-string consumers while retaining executable-rule tagging and instance DI;
2. extended production inventory generation with resolved
   `class_string_targets`, including nested generic/array/union forms;
3. published the exact manifest declaration/consumer rows and failed closed when a
   cross-owner class-string target is internal;
4. adapted the finite test writer set and augmented existing governance/behavior
   IDs only; regeneration to `/tmp` preserved 754/752 plus 509/7,251;
5. passed focused tests, scoped PHPStan/lint/CS, scratch production/test inventory,
   architecture governance, private-leak and diff checks before independent
   address-check and publication.

The inventory parser attaches targets only to syntactic PHPDoc
`class-string<T>` expressions, resolves aliases and namespaces, retains source
FQCNs, and rejects an external nested
`list<class-string<RuleInterface>>`. It does not treat free text, plain string
literals, or unbounded `class-string` as a fabricated contract relation.

The existing governance boundary ID owns the complete synthetic parser matrix.
Positive evidence binds a private `@var` property, promoted private constructor
`@param`, and private method `@return` to an exact multiset spanning direct,
list, array-shape, and outer-union
`class-string<RuleDefinitionInterface>` forms. The oracle compares source
declaration, member kind/name, target FQCN, and multiplicity. Negative evidence
is free prose mentioning the literal, a quoted/plain string, bare
`class-string`, and `@template T` plus `class-string<T>`; all must yield zero
targets. The nested external internal-target rejection remains a separate
negative. A missing occurrence, wrong source-member binding, deduplication, or
fabricated target fails the same existing ID; no test ID or writer is added.

**Stop:** any external `class-string<T>` still names internal `RuleInterface`;
any rule instance/factory becomes public; executable tag validation is
weakened to the marker; optional reflection metadata is forced into the
contract; a generic carrier appears; owner/edge/grant/seam or 509/7,251 test
authority changes; or governance cannot reject a nested internal target.

`native-codex-02` and `native-codex-03` are complete. Their finite closure
work was:

- `native-codex-02`: reconciled `AGENTS.md`, `docs/ARCHITECTURE.md`,
  `src/Analysis/README.md`, `src/Analysis/Policy/Baseline/README.md`,
  `src/Analysis/Evidence/Duplication/README.md`,
  `src/Analysis/Evidence/DependencyModel/README.md`,
  `src/Infrastructure/README.md`, `src/Infrastructure/Git/README.md`,
  `src/Rules/README.md`, `src/Core/README.md`, and
  `website/CONTRIBUTING_DOCS.md`; removed obsolete
  `src/Baseline`, `Core\\Rule`, `Core\\Violation`, `GitScopeFilter`, old seam
  counts, and implemented-but-pending P6 wording. Reverse-search classified
  all remaining older 753/751 references as historical, distinct from the
  final 754/752 authority.
- `native-codex-03`: removed only the untracked zero-byte root
  `qmx-baseline.json.lock` and added the exact root ignore
  `/qmx-baseline.json.lock` without broadening the pattern to dependency lock
  files; verified that the runtime writer recreates the ignored lock without
  dirtying the worktree.

## Final Definition of Done

- Every one of the 156 current declarations has the reviewed move/replacement
  disposition and exactly one leaf owner; every added contract has a named,
  observed consumer.
- All eight deferred declarations are internal to Finding; raw rule options no
  longer live in the transitional Configuration DTO/holder.
- Rule execution publishes metadata and statistics, not concrete rules or
  provider getters.
- Inline, Baseline, Prioritization, Reporting projection, and Git adapter obey
  the DAG and authoritative order.
- All six seams and eight grants are removed without replacement; every
  remaining seam/grant belongs to a later package and is still necessary.
- All 86 existing P6 closure test classes / 939 IDs remain discovered exactly once;
  the closure becomes 86/940, while the exhaustive P6-D affected-writer audit
  expands complete regression authority to 123/1,577 without changing global
  discovery;
  the reviewed P6-0 method ledger and resulting global count match publication.
- Generated artifacts are byte-stable; architecture, dogfood, full validation,
  documentation, leak checks, and independent review are green. Final host
  `composer check` is green; P7 remains pending and unstarted.
