<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
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
    private const string LAYER_DECLARATION_VALIDATOR = 'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerViolation\\LayerDeclarationValidator';
    private const string LAYER_EVIDENCE_COLLECTOR = 'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerViolation\\LayerEvidenceCollector';
    private const string LAYER_VIOLATION_RULE = 'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerViolation\\LayerViolationRule';
    private const string UNASSIGNED_CLASS_RULE = 'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerViolation\\UnassignedClassRule';

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

        $this->registerLayerVerdicts($container);
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
                new Reference(self::RUNTIME_CONFIGURATOR),
                new Reference(self::LAYER_ASSIGNMENT_RESOLVER),
                new Reference(ConfigurationInputAdapter::class),
                new Reference(RunConfigurationResolverInterface::class),
                new Reference(CacheConfigurationResolverInterface::class),
                new Reference(ParallelConfigurationResolverInterface::class),
                new Reference(RuleInputValidator::class),
            ])
            ->setPublic(true);
    }

    /**
     * The verdicts on the declared layers and the walk they share.
     *
     * Called after the rule scan, and that order is load-bearing: channels
     * enter the universe in the order their producers are registered, and this
     * family's published order has the two rules' channels ahead of the
     * validator's five. See ChannelDeclarationCompilerPass.
     *
     * The rule and its validator answer to one options service — the producer
     * rule's own — because `--rule-opt=architecture.layer-violation:enabled=false`
     * has always silenced that family, and a second Options instance would be a
     * second place for that answer to be read. The ids are derived from the
     * rules the same way {@see RuleOptionsCompilerPass} derives them when it
     * registers the services later in the build; a reference to a service
     * defined by a later pass resolves at the end of compilation.
     *
     * The shared walk gets a second options service on top of that one:
     * `architecture.unassigned-class` is a producer of its own now, and what
     * the walk has to materialise depends on both gates.
     */
    private function registerLayerVerdicts(ContainerBuilder $container): void
    {
        $options = new Reference(RuleOptionsCompilerPass::optionsServiceIdForRule(self::LAYER_VIOLATION_RULE));
        $unassignedClassOptions = new Reference(RuleOptionsCompilerPass::optionsServiceIdForRule(self::UNASSIGNED_CLASS_RULE));

        $container->register(self::LAYER_EVIDENCE_COLLECTOR)
            ->setArguments([$options, $unassignedClassOptions, new Reference(self::ARCHITECTURE_POLICY)]);

        $container->register(self::LAYER_DECLARATION_VALIDATOR)
            ->setArguments([new Reference(self::LAYER_EVIDENCE_COLLECTOR), $options])
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
    }
}
