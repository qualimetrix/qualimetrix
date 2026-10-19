<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Security;

use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Registers the exact collector and rule roots owned by Security. */
final class SecurityConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Security\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        $loader->registerClasses(
            EvidenceRegistration::collectors(),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Security/**/*Collector.php',
        );
        foreach (['' => '*', 'DirectSinks\\' => 'DirectSinks/*'] as $namespace => $root) {
            $loader->registerClasses(
                EvidenceRegistration::rules(),
                self::NAMESPACE . $namespace,
                $this->srcDir . '/Analysis/Evidence/Security/' . $root . 'Rule.php',
                $this->srcDir . '/Analysis/Evidence/Security/' . str_replace('*', 'Abstract*', $root) . 'Rule.php',
            );
        }
    }
}
