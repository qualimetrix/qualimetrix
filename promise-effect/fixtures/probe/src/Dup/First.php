<?php

declare(strict_types=1);

namespace Probe\Dup;

class First
{
    public function tally(array $rows, int $floor, int $ceiling): array
    {
        $kept = [];
        $dropped = [];

        foreach ($rows as $index => $row) {
            $weight = (int) ($row['weight'] ?? 0);

            if ($weight < $floor) {
                $dropped[$index] = $weight;

                continue;
            }

            if ($weight > $ceiling) {
                $dropped[$index] = $ceiling;

                continue;
            }

            $kept[$index] = $weight * 2 + $floor - $ceiling;
        }

        return ['kept' => $kept, 'dropped' => $dropped, 'total' => \count($kept) + \count($dropped)];
    }
}
