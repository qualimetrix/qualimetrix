<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers tagged configuration stages with the ConfigurationPipeline.
 */
final class ConfigurationStageCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'qmx.configuration_stage';
    private const string PIPELINE_SERVICE_ID = 'qmx.configuration.pipeline';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::PIPELINE_SERVICE_ID)) {
            return;
        }

        $pipeline = $container->findDefinition(self::PIPELINE_SERVICE_ID);
        $stages = $container->findTaggedServiceIds(self::TAG);

        foreach (array_keys($stages) as $serviceId) {
            $pipeline->addMethodCall('addStage', [new Reference($serviceId)]);
        }
    }
}
