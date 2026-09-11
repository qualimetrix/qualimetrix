<?php

declare(strict_types=1);

namespace Probe\Domain;

final class Order
{
    public function __construct(public int $id, public string $label, public bool $paid) {}

    public function describe(int $a, int $b, int $c, int $d, int $e, int $f): string
    {
        if ($a > 0 && $b > 0) {
            foreach ([$c, $d] as $n) {
                if ($n > 1 || $e > 2) {
                    return $this->label . $n;
                }
            }
        }

        return match (true) {
            $f > 3 => 'high',
            $f > 2 => 'mid',
            default => 'low',
        };
    }
}
