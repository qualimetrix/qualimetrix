<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use Qualimetrix\Analysis\Lifecycle\AnalysisLifecycleHookInterface;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;

/**
 * Wires the Architecture slice into the analysis lifecycle.
 *
 * Carries the slice-specific knowledge ({@see ResolvedConfiguration::$architecture}
 * is the field that feeds the processor) so {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator}
 * stays cross-cutting and never imports any Architecture type. When Computed
 * Metrics eventually migrates to a vertical slice it ships its own hook the
 * same way and the runtime configurator picks it up via the autoconfigured tag.
 */
final class ArchitectureLifecycleHook implements AnalysisLifecycleHookInterface
{
    public function __construct(
        private readonly ArchitectureProcessorInterface $processor,
    ) {}

    public function applyResolvedConfiguration(ResolvedConfiguration $resolved): void
    {
        $this->processor->reset();
        $this->processor->bind($resolved->architecture);
    }
}
