<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;

/** One level's effective option switch and the write that decided it. */
final readonly class OptionActivity
{
    public function __construct(
        public bool $active,
        public ?string $written = null,
        public ?Provenance $decidedBy = null,
    ) {}

    public function rank(): int
    {
        return $this->decidedBy === null ? -1 : $this->decidedBy->layerIndex;
    }
}
