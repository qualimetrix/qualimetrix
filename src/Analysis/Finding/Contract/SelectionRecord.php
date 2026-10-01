<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/** A producer slot skipped by the final selection. */
final readonly class SelectionRecord
{
    public function __construct(
        public string $producer,
        public string $reason,
        public string $statement,
        public string $layer,
    ) {}
}
