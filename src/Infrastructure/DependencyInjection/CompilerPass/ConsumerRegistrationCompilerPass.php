<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Refuses a container build in which a {@see ConsumerBoundPassInterface}
 * pass would skip its step because a service it writes into is not
 * registered.
 *
 * The population is every such pass the container was given, read off its
 * own pass configuration rather than listed here, so a pass added later is
 * checked without an edit to this class. Registered by
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory}
 * ahead of the passes it checks; a fixture container that does not register
 * it keeps the passes' own skip.
 */
final class ConsumerRegistrationCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, list<class-string>> $passesByServiceId */
        $passesByServiceId = [];

        foreach ($container->getCompilerPassConfig()->getPasses() as $pass) {
            if (!$pass instanceof ConsumerBoundPassInterface) {
                continue;
            }

            foreach ($pass::consumerServiceIds() as $serviceId) {
                $passesByServiceId[$serviceId][] = $pass::class;
            }
        }

        if ($passesByServiceId === []) {
            throw new LogicException(\sprintf(
                'No compiler pass implementing %s is registered, so %s checked nothing.',
                ConsumerBoundPassInterface::class,
                self::class,
            ));
        }

        foreach ($passesByServiceId as $serviceId => $passes) {
            if ($container->hasDefinition($serviceId)) {
                continue;
            }

            throw new LogicException(\sprintf(
                'Service "%s" is not registered, and compiler pass(es) %s write into it. Each would skip its'
                . ' step and the container would build without it; register the service or correct the id'
                . ' the pass names.',
                $serviceId,
                implode(', ', array_unique($passes)),
            ));
        }
    }
}
