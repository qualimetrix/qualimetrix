<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use Qualimetrix\Core\Dependency\Dependency;

/**
 * Complete output of the collection phase.
 *
 * Separates data by lifecycle:
 * - CollectionResult (summary + suppressions) — persists through the entire pipeline
 * - Dependencies — consumed during graph building and discarded afterward
 */
final readonly class CollectionPhaseOutput
{
    /**
     * @param CollectionResult $result Summary and suppressions (long-lived)
     * @param list<Dependency> $dependencies Collected dependencies (short-lived, consumed by graph builder)
     */
    public function __construct(
        public CollectionResult $result,
        public array $dependencies,
    ) {}
}
