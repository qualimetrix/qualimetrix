<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\AnalysisPreflightProfile;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/** Registers the Architecture policy capability and its public contracts. */
final class ArchitectureConfigurator implements ContainerConfiguratorInterface
{
    private const string ARCHITECTURE_POLICY = 'Qualimetrix\\Analysis\\Policy\\Architecture\\ArchitecturePolicy';
    private const string ARCHITECTURE_SECTION = 'Qualimetrix\\Analysis\\Policy\\Architecture\\Configuration\\ArchitectureSection';
    private const string LAYER_ASSIGNMENT_COMMAND = 'Qualimetrix\\Infrastructure\\Console\\Command\\Debug\\LayerAssignmentCommand';
    private const string LAYER_ASSIGNMENT_RESOLVER = 'Qualimetrix\\Infrastructure\\Console\\LayerAssignmentResolver';
    private const string LAYER_DECLARATION_VALIDATOR = 'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerDeclaration\\LayerDeclarationValidator';
    private const string LAYER_EVIDENCE_COLLECTOR = 'Qualimetrix\\Analysis\\Policy\\Architecture\\Observation\\LayerEvidenceCollector';
    private const string LAYER_VIOLATION_RULE = 'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerViolation\\LayerViolationRule';
    private const string LAYER_DECLARATION_RULE = 'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerDeclaration\\LayerDeclarationRule';
    private const string UNASSIGNED_CLASS_RULE = 'Qualimetrix\\Analysis\\Policy\\Architecture\\UnassignedClass\\UnassignedClassRule';

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

        $loader->registerClasses(
            $rules,
            'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerDeclaration\\',
            $this->srcDir . '/Analysis/Policy/Architecture/LayerDeclaration/*Rule.php',
        );

        $container->register(self::ARCHITECTURE_POLICY)
            ->setAutowired(true);
        $container->register(self::ARCHITECTURE_SECTION)
            ->setAutoconfigured(true);

        $this->registerLayerVerdicts($container);
        $loader->registerClasses(
            $rules,
            'Qualimetrix\\Analysis\\Policy\\Architecture\\UnassignedClass\\',
            $this->srcDir . '/Analysis/Policy/Architecture/UnassignedClass/*Rule.php',
        );
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
                new Reference(ProjectFilesInterface::class),
            ]);
        $container->register(AnalysisPreflightProfile::class)
            ->setFactory([AnalysisPreflightProfile::class, 'analysis']);
        $container->register(self::LAYER_ASSIGNMENT_COMMAND)
            ->setArguments([
                new Reference(AnalysisPreflight::class),
                new Reference(AnalysisPreflightProfile::class),
                new Reference(self::LAYER_ASSIGNMENT_RESOLVER),
                new Reference(RefusalPresenter::class),
            ])
            ->setPublic(true);
    }

    /** Registers observation and the validator before the unassigned-class channel to preserve published order. */
    private function registerLayerVerdicts(ContainerBuilder $container): void
    {
        $options = new Reference(RuleOptionsCompilerPass::optionsServiceIdForRule(self::LAYER_VIOLATION_RULE));
        $declarationOptions = new Reference(RuleOptionsCompilerPass::optionsServiceIdForRule(self::LAYER_DECLARATION_RULE));
        $unassignedClassOptions = new Reference(RuleOptionsCompilerPass::optionsServiceIdForRule(self::UNASSIGNED_CLASS_RULE));

        // Named, because all three gates share one parameter type and a swap would
        // still type-check while silencing `architecture.unassigned-class`.
        $container->register(self::LAYER_EVIDENCE_COLLECTOR)
            ->setArguments([
                '$layerViolation' => $options,
                '$unassignedClass' => $unassignedClassOptions,
                '$layerDeclaration' => $declarationOptions,
                '$processor' => new Reference(self::ARCHITECTURE_POLICY),
            ]);

        $container->register(self::LAYER_DECLARATION_VALIDATOR)
            ->setArguments([new Reference(self::LAYER_EVIDENCE_COLLECTOR)])
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
    }
}
