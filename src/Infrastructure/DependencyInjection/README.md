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
Run preparation and observed scope reasons are services; their immutable result
records are values constructed by the operation, never container services.

`Registration/EvidenceRegistration` creates a fresh loader and fresh collector
and lazy rule prototypes for Cohesion, Complexity, Coupling, Maintainability
and Size. Their configurators retain the literal `registerClasses()` calls,
namespace and exact owned resource roots. LCOM tagged collections and Coupling
aliases remain explicit in their respective configurators. Prototypes and
loaders are not cached or registered as services.

## Invocation source bindings

`ComposerManifestReaderInterface` and `ManifestSnapshotControlInterface` alias
one Infrastructure Composer reader. `Application` begins its snapshot after
working-directory selection and before the first read; each root caches success,
absence and failure for that invocation. The next invocation clears them.
Measurement's namespace resolver is likewise composed under both its read port
and `ProjectNamespaceSourceControlInterface`. Console's
`ProjectSourceConfigurator` binds current facts and the analysed install anchor
before collection. No configuration field transports a feature's runtime state.
`OutputConfigurator` composes Run's existing `AnalysisFileDiscovery` for graph
export with discovery, generated-file filtering and the lazy exclude audit.
Exact private composition bindings and named public consumers remain manifest
entries rather than wildcard visibility.

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

An empty set of tagged services is not refused: a registered consumer receiving
no members is a legitimate container state.
