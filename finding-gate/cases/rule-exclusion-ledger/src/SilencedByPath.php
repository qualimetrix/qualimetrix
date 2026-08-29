<?php

declare(strict_types=1);

namespace Corpus\RuleExclusionLedger;

final class SilencedByPath
{
    public function run(int $a, int $b, int $c, int $d, int $e): int
    {
        return $a + $b + $c + $d + $e;
    }
}
