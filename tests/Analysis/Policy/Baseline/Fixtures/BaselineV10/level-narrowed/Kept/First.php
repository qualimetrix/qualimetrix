<?php

declare(strict_types=1);

namespace LevelFixture\Kept;

final class First
{
    public function pick(int $value): int
    {
        if ($value > 0) {
            return $value;
        }

        return -$value;
    }
}
