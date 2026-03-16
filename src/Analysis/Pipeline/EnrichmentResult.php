<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Pipeline;

use AiMessDetector\Analysis\Collection\Dependency\Cycle;
use AiMessDetector\Core\Duplication\DuplicateBlock;

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
        public array $cycles,
        public array $duplicateBlocks,
    ) {}
}
