<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

/** The token intervals of retained matches, ordered by start within each file. */
final class CopyCoverIndex
{
    /** @var array<int, list<array{int, int, int}>> file => [start, end, kept id] */
    private array $ranges = [];

    public function add(int $keptId, int $fileIndex, int $start, int $end): void
    {
        $low = 0;
        $high = \count($this->ranges[$fileIndex] ?? []);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->ranges[$fileIndex][$middle][0] <= $start) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $this->ranges[$fileIndex] ??= [];
        array_splice($this->ranges[$fileIndex], $low, 0, [[$start, $end, $keptId]]);
    }

    /** @param list<int> $out Replaced with retained ids containing [start, end). */
    public function containing(int $fileIndex, int $start, int $end, array &$out): void
    {
        $out = [];
        $low = 0;
        $high = \count($this->ranges[$fileIndex] ?? []);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->ranges[$fileIndex][$middle][0] <= $start) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        for ($index = 0; $index < $low; $index++) {
            if ($this->ranges[$fileIndex][$index][1] >= $end) {
                $out[] = $this->ranges[$fileIndex][$index][2];
            }
        }
    }
}
