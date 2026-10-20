<?php

declare(strict_types=1);

namespace Corpus\ParallelFiles;

final class Shard028
{
    public function run(int $value): int
    {
        $result = 0;
        if ($value > 0) {
            ++$result;
        }
        if ($value > 1) {
            ++$result;
        }

        return $result;
    }
}
