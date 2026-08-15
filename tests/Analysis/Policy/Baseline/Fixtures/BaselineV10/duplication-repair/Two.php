<?php

declare(strict_types=1);

namespace BaselineFixture\DuplicationRepair;

final class Two
{
    public function small(int $value): int
    {
        $result = $value ^ 7;
        $result |= 3;
        $result &= 31;
        $result %= 11;
        $result += 2;
        $result *= 3;
        $result -= 4;
        $result >>= 1;
        $result <<= 2;
        $result ^= 9;
        return $result;
    }

}
