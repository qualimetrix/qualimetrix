<?php

declare(strict_types=1);

namespace Fixture;

final class Silenced
{
    /**
     * @qmx-ignore code-smell.debug-code The suppressed format needs a finding
     *   that a mechanism actually held back, or its entry shape is unobservable.
     */
    public function emit(): void
    {
        var_dump(1);
    }
}
