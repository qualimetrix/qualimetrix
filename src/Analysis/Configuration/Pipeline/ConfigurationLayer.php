<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

/**
 * A single configuration layer from a specific source.
 * Contains only the values defined at this layer.
 */
final readonly class ConfigurationLayer
{
    /**
     * @param string $source Source: "defaults", "composer.json", "qmx.yaml", "cli"
     * @param array<string, mixed> $values Sparse config values
     * @param list<array<string, mixed>> $documents Normalized source documents in precedence order
     */
    public function __construct(
        public string $source,
        public array $values,
        public array $documents = [],
    ) {}
}
