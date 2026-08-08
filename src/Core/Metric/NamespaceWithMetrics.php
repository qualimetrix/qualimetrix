<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Value object representing a namespace with its source-owned metrics.
 */
final readonly class NamespaceWithMetrics
{
    public function __construct(
        public string $namespace,
        public int $line,
        public MetricBag $metrics,
    ) {}

    public function getSymbolPath(): SymbolPath
    {
        return SymbolPath::forNamespace($this->namespace);
    }
}
