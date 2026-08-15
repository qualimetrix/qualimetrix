<?php

declare(strict_types=1);

namespace BaselineFixture\DuplicationRepair;

final class Three
{
    public function large(int $value): int
    {
        $first = $value + 1;
        $second = $first * 2;
        $third = $second - 3;
        $fourth = $third + 4;
        $fifth = $fourth * 5;
        $sixth = $fifth - 6;
        $seventh = $sixth + 7;
        return $seventh * 8;
    }
}
