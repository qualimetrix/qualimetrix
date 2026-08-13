<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Run\Contract\Lifecycle\AnalysisLifecycleHookInterface;

/**
 * Wires the Architecture slice into the analysis lifecycle.
 *
 * Carries the slice-specific knowledge ({@see TransitionalResolvedConfiguration::$architecture}
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

    public function applyResolvedConfiguration(TransitionalResolvedConfiguration $resolved): void
    {
        $this->processor->reset();
        $this->processor->bind($resolved->architecture);
    }
}
