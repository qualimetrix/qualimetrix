<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

/**
 * Owns the duplication result for one analysis run.
 *
 * @qmx-ignore design.data-class -- Run-scoped state holder intentionally exposes replace/read/reset lifecycle around private duplication evidence; it is not a DTO or public data surface.
 */
final class DuplicationResultProvider
{
    /** @var list<DuplicateBlock> */
    private array $blocks = [];

    /**
     * @param list<DuplicateBlock> $blocks
     */
    public function replace(array $blocks): void
    {
        $this->blocks = $blocks;
    }

    /**
     * @return list<DuplicateBlock>
     */
    public function all(): array
    {
        return $this->blocks;
    }

    public function reset(): void
    {
        $this->blocks = [];
    }
}
