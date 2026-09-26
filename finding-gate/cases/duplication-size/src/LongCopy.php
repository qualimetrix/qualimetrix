<?php

namespace Corpus\DuplicationSize;

final class LongCopy
{
    public function total(array $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            if ($value > 20) {
                $total += $value * 2;
            } elseif ($value > 10) {
                $total += $value + 3;
            } else {
                $total += $value;
            }
        }
        return $total;
    }
}
