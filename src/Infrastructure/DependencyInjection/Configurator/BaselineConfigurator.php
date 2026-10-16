<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class BaselineConfigurator implements ContainerConfiguratorInterface
{
    public function __construct(private string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        $loader->registerClasses(
            EvidenceRegistration::rules(),
            'Qualimetrix\\Analysis\\Policy\\Baseline\\EntryBinding\\',
            $this->srcDir . '/Analysis/Policy/Baseline/EntryBinding/*Rule.php',
        );
        $container->register(UnusedEntryAudit::class)->setAutowired(true);
    }
}
