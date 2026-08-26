<?php

namespace Corpus\Complexity;

function evaluateBatch(array $items, int $mode, string $rounding): int
{
    $total = 0;
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        $item = $items[$i];
        switch ($mode) {
            case 1:
                if ($item > 0 && $rounding === 'strict') {
                    $total += $item * 2;
                } elseif ($item > 0) {
                    $total += $item;
                }
                break;
            case 2:
                try {
                    $total += 100 / $item;
                } catch (\DivisionByZeroError $e) {
                    $total -= 1;
                }
                break;
            default:
                if ($rounding === 'strict') {
                    $total++;
                }
        }
    }

    for ($j = 0; $j < $total; $j++) {
        if ($j % 2 === 0) {
            $total--;
        } elseif ($j % 3 === 0) {
            $total -= 2;
        } else {
            continue;
        }
    }

    return $total > 0 ? $total : 0;
}
