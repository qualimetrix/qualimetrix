<?php

declare(strict_types=1);

namespace Corpus\BaselineCycle;

final class Stale
{
    public function run(int $value): int
    {
        $result = 0;
        if ($value > 0) {
            ++$result;
        }

        return $result;
    }
}
