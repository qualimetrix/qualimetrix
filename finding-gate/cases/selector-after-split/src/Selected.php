<?php

declare(strict_types=1);

namespace Corpus\SelectorAfterSplit;

final class Selected
{
    public function select(bool $first, bool $second): int
    {
        if ($first) {
            return 1;
        }

        if ($second) {
            return 2;
        }

        return 0;
    }
}
