# Infrastructure DependencyInjection

This is the Symfony composition root. References to private declarations are
recorded as exact manifest composition bindings and do not widen public APIs.

## Subject composition

`ProjectManifest/ProjectManifestConfigurator` composes the analysed Composer
snapshot behind both exact public contracts. The existing `Configurator/`
services compose their named subjects; `CohesionConfigurator` owns the LCOM
resolver, store and tagged collectors. `OutputConfigurator` keeps ordinary
services explicitly registered and autowired, with named loggers, the synthetic
logger holder, lazy Options, factories and tagged collections bound explicitly.
`ContainerFactory` registers the synthetic Console diagnostic trio and installs
the instances held by one `ConsoleDiagnostics`; `OutputConfigurator` binds their
consumers.
Run preparation and observed scope reasons are services; their immutable result
records are values constructed by the operation, never container services.

`Registration/EvidenceRegistration` creates a fresh loader and fresh collector
and lazy rule prototypes for Cohesion, Complexity, Coupling, Maintainability
and Size. Their configurators retain the literal `registerClasses()` calls,
namespace and exact owned resource roots. LCOM tagged collections and Coupling
aliases remain explicit in their respective configurators. Prototypes and
loaders are not cached or registered as services.

`ComputedMetricsConfigurator` registers configuration and evaluation separately.
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
`OutputConfigurator` composes Run's existing `AnalysisFileDiscovery` for graph
export with discovery, generated-file filtering and the lazy exclude audit.
Exact private composition bindings and named public consumers remain manifest
entries rather than wildcard visibility.

`AnalysisConfigurator` binds DependencyModel's
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

`RuleOptionsCompilerPass` refuses a tagged definition with no class, a class
that is not an executable rule, invalid Options metadata and an unresolved
class-typed constructor collaborator. Optional constructor defaults cannot hide
a missing product registration. Producer validation uses the rule-shape map:
a diagnostic channel's inherited remediation minutes do not make it a rule
that a configuration validator may borrow.
