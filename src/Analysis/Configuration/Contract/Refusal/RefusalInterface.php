<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Refusal;

use Throwable;

interface RefusalInterface extends Throwable
{
    public function summary(): string;

    public function position(): ?RefusedPosition;

    /** @return non-empty-list<ConfigurationOrigin>|null */
    public function sources(): ?array;
}
