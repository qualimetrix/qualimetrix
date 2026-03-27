<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use Qualimetrix\Analysis\Collection\Dependency\Cycle;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Namespace_\NamespaceTree;

/**
 * Holds the result of the metric enrichment phase.
 */
final readonly class EnrichmentResult
{
    /**
     * @param list<Cycle> $cycles
     * @param list<DuplicateBlock> $duplicateBlocks
     */
    public function __construct(
        public NamespaceTree $namespaceTree,
        public array $cycles,
        public array $duplicateBlocks,
    ) {}
}
