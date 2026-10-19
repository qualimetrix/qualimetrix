<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @qmx-threshold coupling.cbo 36 -- The explicitly enumerated composition subjects implement one internal container registration protocol; splitting that protocol would duplicate the same bootstrap contract.
 */
interface ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void;
}
