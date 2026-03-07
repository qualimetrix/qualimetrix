<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection\Configurator;

use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Infrastructure\Console\Progress\DelegatingProgressReporter;
use AiMessDetector\Infrastructure\Console\Progress\ProgressReporterHolder;
use AiMessDetector\Infrastructure\Logging\DelegatingLogger;
use AiMessDetector\Infrastructure\Logging\LoggerFactory;
use AiMessDetector\Infrastructure\Logging\LoggerHolder;
use Psr\Log\LoggerInterface;
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
        $this->registerLogging($container);
        $this->registerProgress($container);
        $this->registerProfiler($container);
    }

    /**
     * Configures global aliases for autowiring.
     *
     * These aliases allow services to depend on LoggerInterface/ProgressReporter
     * without knowing the concrete implementation (DelegatingLogger/DelegatingProgressReporter).
     */
    private function configureDefaults(ContainerBuilder $container): void
    {
        // After DelegatingLogger is registered, alias LoggerInterface to it
        // This allows autowiring of LoggerInterface to resolve to DelegatingLogger
        $container->registerAliasForArgument(DelegatingLogger::class, LoggerInterface::class);

        // After DelegatingProgressReporter is registered, alias ProgressReporter to it
        $container->registerAliasForArgument(
            DelegatingProgressReporter::class,
            \AiMessDetector\Core\Progress\ProgressReporter::class,
        );
    }

    /**
     * Registers logging infrastructure.
     *
     * LoggerHolder is a mutable singleton that holds the current logger.
     * It's initialized with NullLogger and can be reconfigured at runtime
     * in AnalyzeCommand based on CLI options (-v, --log-file, etc.).
     *
     * DelegatingLogger proxies all log calls to LoggerHolder::getLogger(),
     * allowing runtime logger configuration while services are created at compile time.
     */
    private function registerLogging(ContainerBuilder $container): void
    {
        // LoggerFactory for creating loggers
        $container->register(LoggerFactory::class)
            ->setPublic(true);

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
     * ProgressReporterHolder is a mutable singleton that holds the current progress reporter.
     * It's initialized with NullProgressReporter and can be reconfigured at runtime
     * in AnalyzeCommand based on CLI options (--no-progress, -q, TTY detection).
     *
     * DelegatingProgressReporter proxies all progress calls to ProgressReporterHolder::getReporter(),
     * allowing runtime progress reporter configuration while services are created at compile time.
     */
    private function registerProgress(ContainerBuilder $container): void
    {
        // ProgressReporterHolder - mutable, holds current progress reporter
        $container->register(ProgressReporterHolder::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(ProgressReporterHolder::class, new ProgressReporterHolder());

        // DelegatingProgressReporter - proxies to ProgressReporterHolder
        // Note: ProgressReporterHolder is synthetic, so we can't use autowiring here
        $container->register(DelegatingProgressReporter::class)
            ->setArguments([new Reference(ProgressReporterHolder::class)]);
    }

    /**
     * Registers profiler infrastructure.
     *
     * ProfilerHolder is a mutable singleton that holds the current profiler.
     * It's initialized with NullProfiler (no-op) and can be reconfigured at runtime
     * in AnalyzeCommand based on CLI options (--profile).
     */
    private function registerProfiler(ContainerBuilder $container): void
    {
        // ProfilerHolder - mutable, holds current profiler
        $container->register(ProfilerHolder::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(ProfilerHolder::class, new ProfilerHolder());
    }
}
