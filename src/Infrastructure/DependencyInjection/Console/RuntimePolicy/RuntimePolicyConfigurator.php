<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Console\RuntimePolicy;

use Qualimetrix\Infrastructure\Console\CacheLifecycle\CacheRunLifecycle;

use Qualimetrix\Infrastructure\Console\Profile\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\Profile\ProfileSummaryRenderer;
use Qualimetrix\Infrastructure\Console\Progress\ProgressConfigurator;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitPolicySection;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\MemoryLimitSection;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\RuntimeEffects;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\RuntimeLimitsController;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RuntimePolicyConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $this->registerRuntimePolicy($container);
        $this->registerTargetLifecycle($container);
        $this->registerDiagnostics($container);
        $this->registerProfiling($container);
    }

    private function registerRuntimePolicy(ContainerBuilder $container): void
    {
        $container->register(ExitPolicySection::class)->setAutoconfigured(true);
        $container->register(MemoryLimitSection::class)->setAutoconfigured(true);
        $container->register(RuntimeEffects::class)->setAutowired(true);
        $container->register(RuntimeLimitsController::class)
                    ->setArguments([new Reference(OutOfMemoryHint::class)]);
        $container->register(ExitCodeResolver::class)
                    ->setAutowired(true);
    }

    private function registerTargetLifecycle(ContainerBuilder $container): void
    {
        $container->register(CacheRunLifecycle::class)->setAutowired(true)->setPublic(true);
        $container->register(RunTargetSession::class)
                    ->setArguments([
                        new Reference(RunTargets::class),
                        new Reference(RefusalPresenter::class),
                        new Reference(CacheRunLifecycle::class),
                    ]);
    }

    private function registerDiagnostics(ContainerBuilder $container): void
    {
        $container->register(RuntimeLoggerConfigurator::class)
                    ->setAutowired(true)
                    ->setArgument('$loggerHolder', new Reference(LoggerHolder::class))
                    ->setArgument('$runTargets', new Reference(RunTargets::class));
        $container->register(ProgressConfigurator::class)
                    ->setAutowired(true);
    }

    private function registerProfiling(ContainerBuilder $container): void
    {
        $container->register(ProfileSummaryRenderer::class);
        $container->register(ProfilePresenter::class)
                    ->setAutowired(true);
    }
}
