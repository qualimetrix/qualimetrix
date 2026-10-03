<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use Qualimetrix\Core\Path\RelativePath;

/** On-demand metadata inventory; inaccessible entries prevent an absence claim. */
final readonly class ProjectTreeSnapshot
{
    /**
     * @param list<RelativePath> $phpFiles sorted and distinct
     * @param list<RelativePath> $inaccessibleEntries
     */
    public function __construct(
        public array $phpFiles,
        public array $inaccessibleEntries,
        private bool $knownUniverse,
    ) {}

    public function complete(): bool
    {
        return $this->knownUniverse && $this->inaccessibleEntries === [];
    }
}
