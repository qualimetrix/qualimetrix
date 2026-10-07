<?php

declare(strict_types=1);

namespace App\Cx;

final class Bytes__INVALID_BYTE__
{
    public function __construct(private \App\Domain\Port $port) {}

    public function choose(bool $a, bool $b, bool $c): int
    {
        return $a ? ($b ? ($c ? 1 : 2) : 3) : 4;
    }

    public function decide(int $value): int
    {
        if ($value > 3) {
            return $this->port->call();
        }
        if ($value > 2) {
            return 2;
        }
        if ($value > 1) {
            return 1;
        }

        return 0;
    }
}
