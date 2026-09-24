<?php

declare(strict_types=1);

namespace LevelFixture\Gone;

final class Third
{
    public function pick(int $value): int
    {
        if ($value > 0) {
            return $value;
        }

        return -$value;
    }
}
