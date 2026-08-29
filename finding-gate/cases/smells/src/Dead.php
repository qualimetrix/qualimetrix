<?php

namespace Corpus\Smells;

class Dead
{
    private int $neverRead = 1;
    private const NEVER_USED = 2;

    public function value(): int
    {
        return 1;
        echo 'after the return';
    }

    private function neverCalled(): void
    {
    }
}
