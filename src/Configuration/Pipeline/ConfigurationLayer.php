<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline;

/**
 * A single configuration layer from a specific source.
 * Contains only the values defined at this layer.
 */
final readonly class ConfigurationLayer
{
    /**
     * @param string $source Source: "defaults", "composer.json", ".aimd.yaml", "cli"
     * @param array<string, mixed> $values Sparse config values
     */
    public function __construct(
        public string $source,
        public array $values,
    ) {}
}
