<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Run\Contract\Progress\ProgressReporterInterface;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Time\ClockInterface;
use Qualimetrix\Core\Time\SystemClock;
use Qualimetrix\Infrastructure\Console\Progress\SwitchableProgressReporter;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSessionControlInterface;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures core services: autowiring aliases, logging, progress reporting, and profiler.
 */
final class CoreServicesConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $this->configureDefaults($container);
        $this->registerClock($container);
        $this->registerLogging($container);
        $this->registerProgress($container);
        $this->registerProfiler($container);
    }

    /**
     * Registers the wall clock behind {@see ClockInterface}.
     *
     * Anything that stamps an artefact with a moment injects the contract,
     * so a test can hold time still and assert the artefact's bytes.
     */
    private function registerClock(ContainerBuilder $container): void
    {
        $container->register(SystemClock::class);
        $container->setAlias(ClockInterface::class, SystemClock::class);
    }

    /**
     * Configures global aliases for autowiring.
     *
     * These aliases let services depend on an owned contract rather than a
     * mutable holder implementation.
     */
    private function configureDefaults(ContainerBuilder $container): void
    {
        // After DelegatingLogger is registered, alias LoggerInterface to it
        // This allows autowiring of LoggerInterface to resolve to DelegatingLogger
        $container->registerAliasForArgument(DelegatingLogger::class, LoggerInterface::class);

        $container->registerAliasForArgument(
            SwitchableProgressReporter::class,
            ProgressReporterInterface::class,
        );
    }

    /**
     * Registers logging infrastructure.
     *
     * LoggerHolder is a mutable singleton that holds the current logger.
     * It's initialized with NullLogger and can be reconfigured at runtime
     * in CheckCommand based on CLI options (-v, --log-file, etc.).
     *
     * DelegatingLogger proxies all log calls to LoggerHolder::getLogger(),
     * allowing runtime logger configuration while services are created at compile time.
     */
    private function registerLogging(ContainerBuilder $container): void
    {
        // LoggerFactory for creating loggers
        $container->register(LoggerFactory::class)
            ->setPublic(true);
        $container->setAlias(LoggerFactoryInterface::class, LoggerFactory::class)->setPublic(true);

        // LoggerHolder - mutable, holds current logger
        $container->register(LoggerHolder::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(LoggerHolder::class, new LoggerHolder());

        // DelegatingLogger - proxies to LoggerHolder
        // Note: LoggerHolder is synthetic, so we can't use autowiring here
        $container->register(DelegatingLogger::class)
            ->setArguments([new Reference(LoggerHolder::class)]);
    }

    /**
     * Registers progress reporting infrastructure.
     *
     * One stable switch owns the per-container adapter mode and resets to
     * no-op between runs.
     */
    private function registerProgress(ContainerBuilder $container): void
    {
        $container->register(SwitchableProgressReporter::class);
        $container->setAlias(ProgressReporterInterface::class, SwitchableProgressReporter::class)->setPublic(true);
    }

    /**
     * Registers profiler infrastructure.
     *
     * One per-container session exposes the Core instrumentation port and the
     * Console lifecycle/report ports.
     */
    private function registerProfiler(ContainerBuilder $container): void
    {
        $container->register(ProfileSession::class);
        $container->setAlias(ProfilerInterface::class, ProfileSession::class)->setPublic(true);
        $container->setAlias(ProfileSessionControlInterface::class, ProfileSession::class)->setPublic(true);
        $container->setAlias(ProfileReportInterface::class, ProfileSession::class)->setPublic(true);
    }
}
