<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Registers Coupling through its public configuration contract and exact roots. */
final class CouplingConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Coupling\\';
    private const string ANALYSIS = self::NAMESPACE . 'CouplingAnalysis';
    private const string SECTION = self::NAMESPACE . 'Configuration\\CouplingSection';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        $loader->registerClasses(
            EvidenceRegistration::collectors(),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Coupling/**/*Collector.php',
        );
        $loader->registerClasses(
            EvidenceRegistration::rules(),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Coupling/**/*Rule.php',
        );

        $container->register(self::ANALYSIS, self::ANALYSIS);
        $container->setAlias(CouplingConfiguratorInterface::class, self::ANALYSIS)
            ->setPublic(true);
        $container->register(self::SECTION)->setAutoconfigured(true);
    }
}
