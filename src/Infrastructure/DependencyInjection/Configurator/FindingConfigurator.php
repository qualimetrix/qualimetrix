<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Composes Finding internals behind their exact public contracts. */
final class FindingConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $ruleOptionsRegistry = 'Qualimetrix\\Analysis\\Finding\\RuleConfiguration\\RuleOptionsRegistry';
        $ruleExecution = 'Qualimetrix\\Analysis\\Finding\\RuleExecution';
        $delegatingLogger = 'Qualimetrix\\Infrastructure\\Logging\\DelegatingLogger';

        $container->register($ruleOptionsRegistry);
        $container->setAlias(RuleConfigurationInterface::class, $ruleOptionsRegistry)
            ->setPublic(true);

        $container->register(RuleOptionsFactory::class)
            ->setArguments([
                new Reference($ruleOptionsRegistry),
                new Reference($delegatingLogger),
            ])
            ->setPublic(true);

        $container->register($ruleExecution)
            ->setArguments([
                '$rules' => [],
                '$profiler' => new Reference(ProfilerInterface::class),
                '$ruleOptionsRegistry' => new Reference($ruleOptionsRegistry),
                '$ruleSelector' => new Reference(RuleSelector::class),
                '$configurationValidators' => [],
            ]);
        $container->setAlias(RuleExecutionInterface::class, $ruleExecution)
            ->setPublic(true);
    }
}
