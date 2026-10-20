<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Selection;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;

/** The deciding authored value and all equally strong writers, in display order. */
final readonly class AuthoredCellDecision
{
    /** @param list<array{text: string, provenance: Provenance}> $decisiveStatements */
    public function __construct(
        public CellSwitch $switch,
        public CellAdmission $admission,
        public array $decisiveStatements = [],
    ) {}
}
