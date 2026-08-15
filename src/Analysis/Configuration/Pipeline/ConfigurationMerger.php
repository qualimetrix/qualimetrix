<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

/**
 * Source-only top-level overlay used while assembling one source layer.
 * Capability-specific list and nested merge semantics belong to the consuming owner.
 */
final class ConfigurationMerger
{
    /**
     * Merges an overlay configuration layer into a base configuration.
     *
     * @param array<string, mixed> $base Accumulated configuration from earlier layers
     * @param array<string, mixed> $overlay New layer to merge on top
     *
     * @return array<string, mixed> Merged configuration
     */
    public static function merge(array $base, array $overlay): array
    {
        return array_replace($base, $overlay);
    }
}
