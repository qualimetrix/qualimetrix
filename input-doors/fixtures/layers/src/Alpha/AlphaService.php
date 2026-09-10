<?php

declare(strict_types=1);

namespace Fixture\Alpha;

/**
 * Carries findings on purpose: the stand needs a fixture whose report is
 * non-empty in more than one namespace and more than one class, or the
 * report-scoping doors compare empty against empty.
 */
class AlphaService
{
    public const string TOKEN = 'AKIAIOSFODNN7EXAMPLE';

    public function branch(int $a, int $b, int $c, int $d, bool $flag, bool $other): int
    {
        $total = 0;

        if ($a > 0 && $b > 0) {
            $total += 1;
        }

        if ($c > 0 || $d > 0) {
            $total += 2;
        }

        if ($flag) {
            $total += 3;
        } else {
            $total += 4;
        }

        if ($other) {
            $total += 5;
        }

        for ($i = 0; $i < $a; $i++) {
            if ($i % 2 === 0) {
                $total += $i;
            } elseif ($i % 3 === 0) {
                $total -= $i;
            } else {
                $total *= 2;
            }
        }

        while ($total > 100) {
            $total = $total % 97;

            if ($total === 13) {
                break;
            }
        }

        return $total;
    }

    public function suppressed(): void
    {
        @file_get_contents('/nonexistent/path');
    }
}
