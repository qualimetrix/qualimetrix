<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Registers the exact collector and rule roots owned by Complexity. */
final class ComplexityConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Complexity\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        $loader->registerClasses(
            EvidenceRegistration::collectors(),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Complexity/**/*Collector.php',
        );
        $loader->registerClasses(
            EvidenceRegistration::rules(),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Complexity/**/*Rule.php',
        );
    }
}
