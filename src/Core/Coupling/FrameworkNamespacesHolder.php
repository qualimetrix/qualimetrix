<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Coupling;

/**
 * Late-init holder for FrameworkNamespaces.
 *
 * The container is built before configuration is parsed, but
 * CouplingCollector — registered at container build time — needs a
 * FrameworkNamespaces value that only exists after the configuration
 * pipeline resolves. The Holder bridges that gap: same instance is shared
 * by writer (RuntimeConfigurator::configure) and readers (CouplingCollector
 * and other coupling-aware collectors).
 *
 * Lifecycle (single process, sequential):
 *   1. Container build registers Holder with a default-constructed empty
 *      FrameworkNamespaces.
 *   2. RuntimeConfigurator::configure() resets the Holder and calls set()
 *      with the resolved FrameworkNamespaces before any analysis runs.
 *   3. Global collection phase reads the value via get().
 *
 * Not used in parallel workers: CouplingCollector implements
 * GlobalContextCollectorInterface and runs in the main process after the
 * parallel collection phase, so there is no cross-process visibility
 * problem.
 *
 * Lives in Core to keep the dependency direction Metrics -> Core (rather
 * than Metrics -> Configuration).
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
