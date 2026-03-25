<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

/**
 * A single configuration layer from a specific source.
 * Contains only the values defined at this layer.
 */
final readonly class ConfigurationLayer
{
    /**
     * @param string $source Source: "defaults", "composer.json", "qmx.yaml", "cli"
     * @param array<string, mixed> $values Sparse config values
     */
    public function __construct(
        public string $source,
        public array $values,
    ) {}
}
