<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\Configurator;

use Symfony\Component\DependencyInjection\ContainerBuilder;

interface ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void;
}
