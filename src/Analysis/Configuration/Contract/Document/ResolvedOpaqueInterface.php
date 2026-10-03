<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

/** A value every layer contributed to unread, kept per layer. */
interface ResolvedOpaqueInterface extends ResolvedValueInterface
{
    /** @return non-empty-list<array{provenance: Provenance, value: mixed}> */
    public function contributions(): array;

    /** @return list<mixed> each layer's value, lowest precedence first */
    public function plain(): array;
}
