# Infrastructure DependencyInjection

This is the Symfony composition root. References to private declarations are
recorded as exact manifest composition bindings and do not widen public APIs.

## Subject composition

`ProjectManifest/ProjectManifestConfigurator` composes the analysed Composer
snapshot behind both exact public contracts. CodeSmell, Cohesion, Complexity,
Coupling, Design, Maintainability, Security, Size, CircularDependency, Duplication
and ComputedMetrics, Architecture and Baseline each own their composition in a direct subject directory.
`Configurator/` retains the composition contract and process defaults. Run,
Configuration, Measurement, Finding, DependencyModel, Parsing, Reporting,
Parallel and RuleCatalog own their concrete registrations; the product factory
calls them explicitly and never scans sibling configurators.
`Cohesion/CohesionConfigurator` owns the LCOM resolver, store and tagged collectors.
`Reporting/OutputConfigurator` composes formatters, report enrichment, finding
projection and output-format configuration. `Baseline/BaselineConfigurator`
registers baseline persistence and policy services; `BaselineCommandsConfigurator`
composes its measured run and five lifecycle commands.

`Console/` composes each delivery operation under its own subject: `RunSetup/RunSetupConfigurator`
resolves input and capability state, `RuntimePolicy/RuntimePolicyConfigurator` binds process
limits, logging and target lifetime, and `Check/CheckConfigurator` binds selection and
report publication. `Hook/HookConfigurator`, `GraphExport/GraphExportConfigurator`,
`Directives/DirectivesConfigurator` and `RuleListing/RuleListingConfigurator` own their respective
command bindings. Changing graph export does not require changing formatter
registration or baseline persistence.

`Run/DiscoveryConfigurator` owns the filesystem roster and generated-file filter;
`Run/AnalysisConfigurator` composes collection and the analysis pipeline.
`Inline/InlineConfigurator` owns source extraction and directive policy state.
`ContainerFactory` names every configurator, registers the synthetic Console
diagnostic trio and installs the instances held by one `ConsoleDiagnostics`.
Run preparation and observed scope reasons are services; their immutable result
records are values constructed by the operation, never container services.

`Registration/EvidenceRegistration` creates a fresh loader and fresh collector
and lazy rule prototypes for CodeSmell, Cohesion, Complexity, Coupling, Maintainability, Security
and Size. Coupling and Maintainability pass their ordered exact collector roots
to its registration method; the other configurators keep their own
`registerClasses()` calls. LCOM tagged collections and Coupling aliases remain
explicit in their respective configurators. Prototypes and loaders are not
cached or registered as services.

`ComputedMetricsConfigurator` registers configuration and evaluation separately.
Its metadata catalog and health-summary registrations are separate operations.
Its metadata catalog and health-summary registrations are separate operations.
Its expression and evaluator aliases retain their explicit shared implementation
bindings; configuration and Health consume those same expression services.

## Invocation source bindings

`ComposerManifestReaderInterface` and `ManifestSnapshotControlInterface` alias
one Infrastructure Composer reader. `Application` resolves its snapshot from
the lazy product container at product execution, after working-directory
selection and before the first read; each root caches success,
absence and failure for that invocation. The next invocation clears them.
Measurement's namespace resolver is likewise composed under both its read port
and `ProjectNamespaceSourceControlInterface`. Console's
`ProjectSourceConfigurator` binds current facts and the analysed install anchor
before collection. No configuration field transports a feature's runtime state.
`Console/GraphExport/GraphExportConfigurator` composes Run's dependency-graph analyzer with
discovery, parsing, dependency traversal and declaration registration.
Exact private composition bindings and named public consumers remain manifest
entries rather than wildcard visibility.

`DependencyModelConfigurator` binds DependencyModel's
`ExternalClassSpellingInterface` to Composer's `InstalledExternalClassSpelling`.
It uses the same stateless `DeclaredSupertypeReader` as Architecture's external
facts binding. Placement and directory listings share the captured install
anchor; `pointAt()` refreshes that snapshot. Parsed external facts are not a
shared memo: the reader can parse again, while Architecture's factory memo
belongs to its own prepared run.

`ArchitectureConfigurator` binds preparation, assignment inspection and the pure
`UnmatchedTypeWarningInterface` query to the same policy instance. Run requires
the preparation/query intersection and checks exact channel publication before
requesting a warning. Console requires final Finding enablement before projecting
`policyDisabled`; neither consumer infers enablement from private rule options.

## Compiler passes that write into named services

`ChannelCatalog/` owns channel declarations, judged-metric validation and file scope.
`RuleCatalog/` owns rule metadata and materialization,
producer Options, configuration validators and threshold-validator composition.
`CompilerPass/` retains collection, file-set, formatting, configuration-stage and
composition-integrity passes. `Parallel/ParallelCollectorClassesCompilerPass` transports the collector and rule
rosters into worker composition, consuming both tag vocabularies without making
the two compiler-pass subjects depend on each other. The product factory registers these explicitly in
their existing order; the consumer-registration witnesses scan these three exact
roots. No future sibling is enrolled implicitly.

A pass that writes into a service it names by id implements
`CompilerPass/ConsumerBoundPassInterface` and lists those ids in
`consumerServiceIds()`, from the same constants it reads. On its own such a
pass skips its step when a named service is absent, which keeps it usable on a
partial fixture container. The product container registers
`ConsumerRegistrationCompilerPass` ahead of them: it reads every registered
consumer-bound pass off the container's own pass configuration and refuses the
build, naming the service and each pass, when one of those ids is not
registered. A renamed service or a mistyped id therefore stops the build
instead of producing a container with a step silently missing.
`ConsumerRegistrationTest` removes each declared service from the real,
uncompiled container (`ContainerFactory::configure()`) and requires that
refusal.

`CompilerPass/TagPopulationFloorCompilerPass` checks the product's explicit
`ContainerFactory::REQUIRED_TAG_POPULATIONS` after interface tagging and before
consumer registration checks. Rules, configuration validators, file collectors,
derived collectors, global collectors, LCOM-configurable collectors, file-set
inspection participants, formatters and configuration stages each require at
least one service. The refusal names the empty tag and its required minimum.
Tags outside that table have no implicit floor; a partial fixture container
running an individual pass does not inherit the product table.

`RuleCompilerPass` and `RuleRegistryCompilerPass` order executable producers by
lexicographic `NAME` before publishing materialization and metadata catalogs.
This defines execution order and keeps catalog order stable across registration
permutations and physical moves. Classless producer declarations retain their
separate registration order after the executable producers.

`RuleOptionsCompilerPass` refuses a tagged definition with no class, a class
that is not an executable rule, invalid Options metadata and an unresolved
class-typed constructor collaborator. Optional constructor defaults cannot hide
a missing product registration. Producer validation uses the rule-shape map:
a diagnostic channel's inherited remediation minutes do not make it a rule
that a configuration validator may borrow.
