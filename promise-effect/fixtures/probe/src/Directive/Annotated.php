<?php

declare(strict_types=1);

namespace Probe\Directive;

class Annotated
{
    /**
     * @qmx-ignore complexity.ccn nothing here is complex, so the directive addresses nothing
     */
    public function quiet(int $n): int
    {
        return $n;
    }
}
