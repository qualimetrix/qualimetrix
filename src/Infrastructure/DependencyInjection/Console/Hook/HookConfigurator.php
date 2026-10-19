<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Console\Hook;

use Qualimetrix\Infrastructure\Console\Hook\HookFileTransaction;

use Qualimetrix\Infrastructure\Console\Hook\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookUninstallCommand;
use Qualimetrix\Infrastructure\Console\Hook\RunningBinaryLocator;
use Qualimetrix\Infrastructure\Console\Hook\RunningBinaryLocatorInterface;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class HookConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(HookFileTransaction::class)
                    ->setArgument('$errorStream', new Reference(ErrorStream::class));
        $container->register(GitRepositoryLocator::class);
        $container->setAlias(GitRepositoryLocatorInterface::class, GitRepositoryLocator::class);
        $container->register(RunningBinaryLocator::class);
        $container->setAlias(RunningBinaryLocatorInterface::class, RunningBinaryLocator::class);
        $container->register(HookInstallCommand::class)
                    ->setArguments([
                        new Reference(GitRepositoryLocator::class),
                        new Reference(RunningBinaryLocator::class),
                        new Reference(HookFileTransaction::class),
                    ])
                    ->setPublic(true);
        $container->register(HookUninstallCommand::class)
                    ->setArguments([
                        new Reference(GitRepositoryLocator::class),
                        new Reference(RunningBinaryLocator::class),
                        new Reference(HookFileTransaction::class),
                    ])
                    ->setPublic(true);
        $container->register(HookStatusCommand::class)
                    ->setArguments([
                        new Reference(GitRepositoryLocator::class),
                        new Reference(RunningBinaryLocator::class),
                        new Reference(HookFileTransaction::class),
                    ])
                    ->setPublic(true);
    }
}
