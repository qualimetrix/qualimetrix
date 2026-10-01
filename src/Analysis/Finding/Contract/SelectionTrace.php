<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/** Selection removals are accounted separately from per-rule exclusions. */
final readonly class SelectionTrace
{
    /**
     * @param list<array{finding: Finding, suppressor: string}> $removed
     * @param list<SelectionRecord> $notRun
     */
    public function __construct(public array $removed = [], public array $notRun = []) {}

    public function merge(self $other): self
    {
        return new self([...$this->removed, ...$other->removed], [...$this->notRun, ...$other->notRun]);
    }
}
