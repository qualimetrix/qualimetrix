<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Enrichment;

use Qualimetrix\Analysis\Collection\Dependency\Cycle;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;

/**
 * Holds the result of the metric enrichment phase.
 */
final readonly class TransitionalEnrichmentResult
{
    /**
     * @param list<Cycle> $cycles
     */
    public function __construct(
        public NamespaceTree $namespaceTree,
        public array $cycles,
    ) {}
}
