<?php

declare(strict_types=1);

namespace BaselineFixture\Duplication;

final class Beta
{
    public function repeat(int $value): int
    {
        $first = $value + 1;
        $second = $first * 2;
        $third = $second - 3;
        $fourth = $third + 4;
        return $fourth * 5;
    }
}
