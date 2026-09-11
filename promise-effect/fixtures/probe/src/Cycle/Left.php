<?php

declare(strict_types=1);

namespace Probe\Cycle;

class Left
{
    public function call(Right $right): string
    {
        return $right->name();
    }

    public function name(): string
    {
        return 'left';
    }
}
