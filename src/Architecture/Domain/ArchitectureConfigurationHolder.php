<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain;

use Qualimetrix\Architecture\Domain\Layer\LayerPolicy;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;

/**
 * Mutable holder for the resolved {@see ArchitectureConfiguration}.
 *
 * Set at runtime after the configuration pipeline resolves, consumed by
 * architecture-aware rules during the rule-execution phase.
 *
 * Lives in Core to maintain the dependency direction:
 * Rules -> Core (not Rules -> Configuration). Mirrors the pattern established
 * by {@see \Qualimetrix\Core\Coupling\FrameworkNamespacesHolder}.
 *
 * The default state is an empty configuration (no layers, no policy,
 * {@see CoverageMode::Ignore}), which means architecture-aware rules
 * short-circuit when no user configuration is present.
 */
final class ArchitectureConfigurationHolder
{
    private ArchitectureConfiguration $configuration;

    public function __construct()
    {
        $this->configuration = self::empty();
    }

    public function get(): ArchitectureConfiguration
    {
        return $this->configuration;
    }

    public function set(ArchitectureConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function reset(): void
    {
        $this->configuration = self::empty();
    }

    private static function empty(): ArchitectureConfiguration
    {
        return new ArchitectureConfiguration(
            new LayerRegistry([]),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );
    }
}
