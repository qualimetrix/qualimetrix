<?php

declare(strict_types=1);

namespace Corpus\RuleExclusionLedger\Excluded;

final class SuppressedInsideExcluded
{
    /**
     * @qmx-ignore code-smell.long-parameter-list -- A suppression written on top of the per-rule
     *             ledger exclusion: the finding is produced and the ledger drops it, so whether
     *             this directive is judged inert says which universe the audit reads.
     */
    public function run(int $a, int $b, int $c, int $d, int $e): int
    {
        return $a + $b + $c + $d + $e;
    }
}
