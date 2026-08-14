<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/** Registers the Architecture policy capability and its public contracts. */
final class ArchitectureConfigurator implements ContainerConfiguratorInterface
{
    private const string ARCHITECTURE_POLICY = 'Qualimetrix\\Analysis\\Policy\\Architecture\\ArchitecturePolicy';
    private const string LAYER_ASSIGNMENT_COMMAND = 'Qualimetrix\\Infrastructure\\Console\\Command\\Debug\\LayerAssignmentCommand';
    private const string LAYER_ASSIGNMENT_RESOLVER = 'Qualimetrix\\Infrastructure\\Console\\LayerAssignmentResolver';
    private const string RUNTIME_CONFIGURATOR = 'Qualimetrix\\Infrastructure\\Console\\RuntimeConfigurator';

    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        $rules = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
        $loader->registerClasses(
            $rules,
            'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerViolation\\',
            $this->srcDir . '/Analysis/Policy/Architecture/LayerViolation/*Rule.php',
        );

        $container->register(self::ARCHITECTURE_POLICY)
            ->setAutowired(true);
        $container->setAlias(ArchitecturePolicyConfiguratorInterface::class, self::ARCHITECTURE_POLICY)
            ->setPublic(true);
        $container->setAlias(LayerPolicyPreparationInterface::class, self::ARCHITECTURE_POLICY)
            ->setPublic(true);
        $container->setAlias(LayerAssignmentInspectorInterface::class, self::ARCHITECTURE_POLICY)
            ->setPublic(true);

        $container->register(self::LAYER_ASSIGNMENT_RESOLVER)
            ->setArguments([
                new Reference(CollectionOrchestratorInterface::class),
                new Reference(DependencyGraphBuilderInterface::class),
                new Reference(LayerAssignmentInspectorInterface::class),
                new Reference(MetricRepositoryFactoryInterface::class),
                new Reference(FileDiscoveryFactoryInterface::class),
                new Reference(GeneratedFileFilterInterface::class),
            ]);
        $container->register(self::LAYER_ASSIGNMENT_COMMAND)
            ->setArguments([
                new Reference(ConfigurationPipelineInterface::class),
                new Reference(self::RUNTIME_CONFIGURATOR),
                new Reference(self::LAYER_ASSIGNMENT_RESOLVER),
            ])
            ->setPublic(true);
    }
}
