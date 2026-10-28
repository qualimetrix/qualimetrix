<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Core\FileTarget\FileIdentity;

/**
 * A single configuration layer from a specific source.
 * Contains only the values defined at this layer.
 */
final readonly class ConfigurationLayer
{
    /**
     * @param string $source Source: "defaults", "composer.json", "qmx.yaml", "cli"
     * @param array<string, mixed> $values Sparse config values
     * @param list<AuthoredLayer> $authored The same sources as written, for the document engine, in precedence order
     * @param list<ConfigurationDiagnostic> $diagnostics notices raised while discovering a configuration source
     * @param ?FileIdentity $file the file a source without authored layers was read from
     */
    public function __construct(
        public string $source,
        public array $values = [],
        public array $authored = [],
        public array $diagnostics = [],
        public ?FileIdentity $file = null,
    ) {}
}
