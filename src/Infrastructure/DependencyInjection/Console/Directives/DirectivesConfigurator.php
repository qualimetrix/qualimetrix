<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Console\Directives;

use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;

use Qualimetrix\Infrastructure\Console\Directives\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RunSetup\Input\AnalysisPreflight;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class DirectivesConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(DirectivesCommand::class)
                    ->setArguments([
                        new Reference(DirectiveAuditInterface::class),
                        new Reference(AnalysisPreflight::class),
                        new Reference(RefusalPresenter::class),
                    ])
                    ->setPublic(true);
    }
}
