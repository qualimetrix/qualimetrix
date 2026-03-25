<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Symfony\Component\DependencyInjection\ContainerBuilder;

interface ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void;
}
