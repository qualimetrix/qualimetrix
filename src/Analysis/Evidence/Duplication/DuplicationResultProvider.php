<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

/**
 * Owns the duplication result for one analysis run.
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
