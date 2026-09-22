# Governance controls that couple two kinds of artifact

Taken because the restructured plan rests on one claim — "each stage validates
green on its own" — and that is a claim about a set of controls. Two independent
plan reviewers each found exactly one coupling control, because each looked in
one place. This is the whole set, swept mechanically.

## How obtained, and what this method cannot see

Obtained by grepping all 120 files under `governance/` for literal markers of a
foreign artifact: `website/`, `README.md`, `docs/internal/generated` and `.tsv`,
`manifest`, `html-report`, `CHANGELOG`, the build configs (`phpunit.xml`,
`composer.json`, `.gitignore`, `.dockerignore`, `box.json`, `phpstan.neon`) and
the project configs (`qmx.yaml`, `qmx-baseline`). Docblocks of the three
controls nearest this plan's changes were then read in full.

What the method cannot see, and these are not hypothetical:

- **Coupling expressed as a number.** `HtmlReportShipsOnlyWhatItReadsTest`
  carries a re-derived count of the files under `html-report/` (34 at the last
  two measurements). A grep for paths and names cannot find a count, and the
  count moves when the viewer gains or loses *any* file — including a new test
  file. Found here only by reading the docblock.
- **Coupling expressed as a `file:line` anchor.** `SubprocessDrain/SubprocessReadsAreDrainedConcurrentlyTest`
  keeps a registry of every `proc_open` and `popen` call site keyed by
  `path:line`. Adding a single `use` import above such a call shifts the line and
  reddens the control, in a file the change was otherwise only appending to. Found
  the same way as the file count: by being hit, not by the sweep. Same family as
  the count -- a number is not a path or a name, so neither spelling is visible to
  a sweep for paths and names.
- **A path built from a constant or a parent-class helper.** A control composing
  `self::DOCS_DIR . '/…'` does not match a literal `website/`.
- **A generated artifact that is not `.tsv`.** JSON and Markdown artifacts under
  a directory this sweep did not name are missed.
- **A literal command string embedding a path**, which CLAUDE.md names as its
  own spelling and which this sweep does not cover.

So: the table below is a floor, not a ceiling. A control absent from it is
unproven, not proven harmless.

## Counts

| Metric                                                 | Count |
| ------------------------------------------------------ | ----- |
| Control files under `governance/`                      | 120   |
| Controls coupling two kinds of artifact                | 46    |
| Of those, coupling production code to **website docs** | 11    |
| Coupling to `html-report/`                             | 2     |
| Coupling to a **generated** artifact                   | 10    |
| Coupling to the **manifest**                           | 11    |
| Coupling to a component `README.md`                    | 5     |
| Coupling to `CHANGELOG.md`                             | 5     |

All governance groups registered under the `Governance` suite execute inside
`composer check:code`, through `composer.json` → `scripts/phpunit-aggregate.py`
(`SUITES` includes `Governance`) → `phpunit.xml.dist`. An *unregistered* group
executes nowhere, and CLAUDE.md records that this fails loudly by name.

## The three questions this sweep was taken to answer

**1. Which controls force a code change and a website change into one stage?**

`Channel/ChannelPresentationCoverageTest`,
`Channel/ChannelPublicationConsistencyTest`,
`Channel/SarifRuleDescriptorCoverageTest`,
`DocumentationCensus/RegisteredFormatterDocumentationTest`,
`FormatOptionKeys/OutputFormatSchemaConsistencyTest`,
`PlanningRecords/PlanningRecordIsolationTest`,
`RatchetArtifact/BaselineCountPublicationTest`,
`RuleDeclaration/DebugCodeDocumentationConsistencyTest`,
`RuleDeclaration/DocumentationRuleSurfaceTest`,
`RuleDeclaration/RuleIdentifierLiteralGuardTest`,
`RuleDeclaration/RuleRemediationMinutesCoverageTest`.

Of these, the ones this plan's changes actually reach:
`OutputFormatSchemaConsistencyTest` (JSON format schemas against the EN and RU
`output-formats` pages, both directions) and `SarifRuleDescriptorCoverageTest`
(every real channel must resolve to a page carrying that producer's `Rule ID:`
anchor).

**2. Which controls couple code to a generated artifact, and what regenerates
it?** The `manifest` and `generated` rows below; regeneration is
`composer architecture:check`'s generator for the modular-architecture artifacts,
and the suppression snapshot for `GeneratedArtifactFreshness`.

**3. Is there a control that reddens when `composer build:js` was forgotten?**
Not directly. `DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest` compares
what the dist package carries under `html-report/` against what the formatter
reads, by count — it catches a *file* appearing or vanishing, not a stale
bundle. A stale `dist/report.min.js` is therefore a silent failure: the PHP
tests pass and the shipped report keeps the old footer.

## The coupling table

| Control                                                                  | Couples with                                  |
| ------------------------------------------------------------------------ | --------------------------------------------- |
| `Channel/ChannelLevelDeclarationDriftTest.php`                           | generated qmx-config                          |
| `Channel/ChannelPresentationCoverageTest.php`                            | website                                       |
| `Channel/ChannelPublicationConsistencyTest.php`                          | website generated                             |
| `Channel/ChannelRenameMapTest.php`                                       | generated                                     |
| `Channel/ProjectScopedChannelRollCallTest.php`                           | README                                        |
| `Channel/SarifRuleDescriptorCoverageTest.php`                            | website                                       |
| `Channel/ScopeConditionedChannelGuardTest.php`                           | manifest build-config qmx-config              |
| `CommitSubjectPolicy/CommitSubjectVerdictTest.php`                       | CHANGELOG                                     |
| `ConfigurationVocabulary/ConfigSchemaEntryClosureTest.php`               | qmx-config                                    |
| `ConfigurationVocabulary/ConfigurationValidatorSilencingPathsTest.php`   | qmx-config                                    |
| `DeclaredDependencies/ShippedCodeReachesOnlyDeclaredPackagesTest.php`    | manifest build-config                         |
| `DeclaredDependencies/ShippedCodeRunsOnlyOnDeclaredExtensionsTest.php`   | manifest build-config                         |
| `DistributedPackage/DockerBuildContextExcludesToolingTestRootsTest.php`  | build-config                                  |
| `DistributedPackage/GitScopeWorksFromTheDistPackageTest.php`             | build-config                                  |
| `DistributedPackage/HookInstallWorksFromTheDistPackageTest.php`          | build-config                                  |
| `DistributedPackage/HtmlReportShipsOnlyWhatItReadsTest.php`              | html-report build-config                      |
| `DistributedPackage/InstalledDependencyGraphRefusesDisagreementTest.php` | manifest build-config                         |
| `DistributedPackage/PharCarriesWhatTheDistCarriesTest.php`               | README CHANGELOG build-config qmx-config      |
| `DocumentationCensus/RegisteredFormatterDocumentationTest.php`           | website                                       |
| `FormatOptionKeys/OutputFormatSchemaConsistencyTest.php`                 | website                                       |
| `GeneratedArtifactFreshness/SuppressionSnapshotFreshnessTest.php`        | generated                                     |
| `ModularOwnership/ComputedMetricsInternalTopologyTest.php`               | manifest                                      |
| `ModularOwnership/DogfoodingTopologyTest.php`                            | generated manifest                            |
| `ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`      | generated manifest build-config qmx-config    |
| `Occurrence/OccurrenceLeafFreezeGuardTest.php`                           | generated CHANGELOG build-config              |
| `PackageVersion/EntryPointRefusesTheVersionsComposerRefusesTest.php`     | manifest build-config                         |
| `PlanningRecords/PlanningRecordIsolationTest.php`                        | website README qmx-config                     |
| `RatchetArtifact/BaselineCountPublicationTest.php`                       | website README generated CHANGELOG qmx-config |
| `RatchetArtifact/RatchetKeyGrammarTest.php`                              | qmx-config                                    |
| `RepositoryEntrypoints/BaselineLifecycleEntrypointSurfaceTest.php`       | qmx-config                                    |
| `RepositoryEntrypoints/MemoryCeilingManifestTest.php`                    | manifest                                      |
| `RuleDeclaration/DebugCodeDocumentationConsistencyTest.php`              | website                                       |
| `RuleDeclaration/DocumentationRuleSurfaceTest.php`                       | website README                                |
| `RuleDeclaration/RuleIdentifierLiteralGuardTest.php`                     | website html-report CHANGELOG                 |
| `RuleDeclaration/RuleRegistrationDriftTest.php`                          | manifest                                      |
| `RuleDeclaration/RuleRemediationMinutesCoverageTest.php`                 | website                                       |
| `SelectorSyntax/SelectorSurfaceRegistryTest.php`                         | generated                                     |
| `SubprocessDrain/ModuleIsLoadedByPathTest.php`                           | build-config                                  |
| `SymbolVocabulary/PhpBuiltinClassRegistryCensusTest.php`                 | build-config                                  |
| `TestSuiteHygiene/CoverageIsRequestedExplicitlyTest.php`                 | build-config                                  |
| `TestSuiteHygiene/RegisteredDirectoriesReachTrackedFilesTest.php`        | build-config                                  |
| `TestSuiteHygiene/TestFilesAreExecutedTest.php`                          | build-config                                  |
| `TestSuiteHygiene/TestMethodsAreReachableTest.php`                       | build-config                                  |
| `TestSuiteHygiene/TestPathsNameTheirSubjectTest.php`                     | manifest                                      |
| `ThresholdKeys/ConfiguredWarningBoundaryMapTest.php`                     | qmx-config                                    |
| `ThresholdKeys/WarningBoundaryDeclarationTest.php`                       | generated qmx-config                          |
