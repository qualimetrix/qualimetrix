<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;

/** The winning nonempty publication filter; a written empty list clears it. */
final readonly class SelectionFilter
{
    /** @param list<string> $selectors */
    public function __construct(public array $selectors, public Provenance $provenance) {}

    public function rank(): int
    {
        return $this->provenance->layerIndex;
    }
}
