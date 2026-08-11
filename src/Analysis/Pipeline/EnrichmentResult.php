<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use Qualimetrix\Analysis\Collection\Dependency\Cycle;
use Qualimetrix\Core\Namespace_\NamespaceTree;

/**
 * Holds the result of the metric enrichment phase.
 */
final readonly class EnrichmentResult
{
    /**
     * @param list<Cycle> $cycles
     */
    public function __construct(
        public NamespaceTree $namespaceTree,
        public array $cycles,
    ) {}
}
