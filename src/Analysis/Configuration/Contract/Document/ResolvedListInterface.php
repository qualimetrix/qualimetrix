<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

/** A list, replaced whole by the highest layer that wrote it. */
interface ResolvedListInterface extends ResolvedValueInterface
{
    /** @return list<ResolvedValueInterface> */
    public function items(): array;

    /** @return list<mixed> */
    public function plain(): array;
}
