# Infrastructure DependencyInjection

This is the Symfony composition root. References to private declarations are
recorded as exact manifest composition bindings and do not widen public APIs.

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
