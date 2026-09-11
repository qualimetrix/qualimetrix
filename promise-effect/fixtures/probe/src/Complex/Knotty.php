<?php

declare(strict_types=1);

namespace Probe\Complex;

class Knotty
{
    private int $left = 0;

    private int $right = 0;

    private string $label = '';

    public function tangle(int $a, int $b, int $c, int $d, int $e): int
    {
        $out = 0;

        if ($a > 1) {
            if ($b > 1) {
                if ($c > 1) {
                    if ($d > 1) {
                        $out += 1;
                    } elseif ($e > 1) {
                        $out += 2;
                    } else {
                        $out += 3;
                    }
                } elseif ($d > 2) {
                    $out += 4;
                }
            } elseif ($c > 2 && $d > 2 || $e > 2) {
                $out += 5;
            }
        }

        foreach ([$a, $b, $c, $d, $e] as $n) {
            if ($n % 2 === 0) {
                $out += $n;
            } elseif ($n % 3 === 0) {
                $out -= $n;
            } else {
                while ($n > 0) {
                    --$n;

                    if ($n === 7) {
                        break;
                    }
                }
            }
        }

        switch ($out) {
            case 1:
                return $out + 1;
            case 2:
                return $out + 2;
            case 3:
                return $out + 3;
            default:
                return $out > 0 ? $out : -$out;
        }
    }

    public function paths(int $a, int $b, int $c, int $d, int $e, int $f, int $g, int $h): int
    {
        $n = 0;
        $n += $a > 0 ? 1 : 2;
        $n += $b > 0 ? 1 : 2;
        $n += $c > 0 ? 1 : 2;
        $n += $d > 0 ? 1 : 2;
        $n += $e > 0 ? 1 : 2;
        $n += $f > 0 ? 1 : 2;
        $n += $g > 0 ? 1 : 2;
        $n += $h > 0 ? 1 : 2;

        return $n;
    }

    public function onlyLeft(): int
    {
        return $this->left;
    }

    public function onlyRight(): int
    {
        return $this->right;
    }

    public function onlyLabel(): string
    {
        return $this->label;
    }
}
