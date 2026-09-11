<?php

declare(strict_types=1);

namespace Probe\Cycle;

class Right
{
    public function call(Left $left): string
    {
        return $left->name();
    }

    public function name(): string
    {
        return 'right';
    }
}
