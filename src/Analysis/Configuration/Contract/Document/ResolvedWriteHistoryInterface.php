<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

/** A scalar or list with each layer's value before merge policy selects the result. */
interface ResolvedWriteHistoryInterface extends ResolvedValueInterface
{
    /** @return non-empty-list<array{provenance: Provenance, value: mixed}> */
    public function writes(): array;
}
