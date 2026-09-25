<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;

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
     * @param list<AuthoredLayer> $authored The same sources as written, for the document engine, in precedence order
     * @param list<ConfigurationRefusal> $deferredRefusals refusals of the normalized values, raised once the engine accepted every written layer
     */
    public function __construct(
        public string $source,
        public array $values,
        public array $documents = [],
        public array $authored = [],
        public array $deferredRefusals = [],
    ) {}
}
