<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Registers the exact collector and rule roots owned by Maintainability. */
final class MaintainabilityConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Maintainability\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        $loader->registerClasses(
            EvidenceRegistration::collectors(),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Maintainability/**/*Collector.php',
        );
        $loader->registerClasses(
            EvidenceRegistration::rules(),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Maintainability/**/*Rule.php',
        );
    }
}
