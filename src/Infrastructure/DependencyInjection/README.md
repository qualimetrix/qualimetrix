# Infrastructure DependencyInjection

This is the Symfony composition root. References to private declarations are
recorded as exact manifest composition bindings and do not widen public APIs.

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
