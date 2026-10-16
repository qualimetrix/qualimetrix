<?php

namespace Corpus\ConfigPrecedence;

final class Complex
{
    public function score(int $value): int
    {
        $score = 0;
        if ($value > 1) { $score += 1; }
        if ($value > 2) { $score += 2; }
        if ($value > 3) { $score += 3; }
        if ($value > 4) { $score += 4; }
        if ($value > 5) { $score += 5; }
        if ($value > 6) { $score += 6; }
        if ($value > 7) { $score += 7; }
        return $score;
    }
}
