<?php

declare(strict_types=1);

namespace Corpus\RuleExclusionLedger\Excluded;

final class SilencedByNamespace
{
    public function run(int $a, int $b, int $c, int $d, int $e): int
    {
        return $a + $b + $c + $d + $e;
    }
}
