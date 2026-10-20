<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

/** A merged map: each key resolved on its own. */
interface ResolvedMapInterface extends ResolvedValueInterface
{
    public function get(string $key): ?ResolvedValueInterface;

    /** @return array<string, ResolvedValueInterface> */
    public function entries(): array;

    /** @return array<string, mixed> */
    public function plain(): array;
}
