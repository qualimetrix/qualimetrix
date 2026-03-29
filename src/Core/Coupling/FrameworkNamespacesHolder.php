<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Coupling;

/**
 * Mutable holder for FrameworkNamespaces configuration.
 *
 * Set at runtime after configuration pipeline resolves, consumed by
 * CouplingCollector during the global collection phase.
 *
 * Lives in Core to maintain the dependency direction:
 * Metrics -> Core (not Metrics -> Configuration).
 */
final class FrameworkNamespacesHolder
{
    private FrameworkNamespaces $frameworkNamespaces;

    public function __construct()
    {
        $this->frameworkNamespaces = new FrameworkNamespaces();
    }

    public function get(): FrameworkNamespaces
    {
        return $this->frameworkNamespaces;
    }

    public function set(FrameworkNamespaces $frameworkNamespaces): void
    {
        $this->frameworkNamespaces = $frameworkNamespaces;
    }

    public function reset(): void
    {
        $this->frameworkNamespaces = new FrameworkNamespaces();
    }
}
