<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Closure;

/** The token intervals of retained matches, ordered by start within each file. */
final class CopyCoverIndex
{
    /** @var array<int, list<array{int, int, int}>> file => [start, end, kept id] */
    private array $ranges = [];

    /** @var array<int, int> */
    private array $parents = [];

    /** @var array<int, int> */
    private array $sizes = [];

    /** @var list<int> */
    private array $touched = [];

    /** @var list<int> */
    private array $covers = [];

    /** @var list<int> */
    private array $anchors = [];

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

    /**
     * @param list<int> $copies
     * @param Closure(int, int): array{int, int, int} $span
     */
    public function connects(array $copies, int $length, Closure $span): bool
    {
        $this->anchors = [];
        try {
            foreach ($copies as $copy) {
                [$file, $start, $end] = $span($copy, $length);
                $this->containing($file, $start, $end, $this->covers);
                if ($this->covers === []) {
                    return false;
                }

                $anchor = $this->covers[0];
                $this->anchors[] = $anchor;
                $this->joinContaining($anchor);
            }

            $root = $this->root($this->anchors[0]);
            foreach ($this->anchors as $anchor) {
                if ($this->root($anchor) !== $root) {
                    return false;
                }
            }

            return true;
        } finally {
            foreach ($this->touched as $id) {
                unset($this->parents[$id], $this->sizes[$id]);
            }
            $this->touched = [];
            $this->covers = [];
            $this->anchors = [];
        }
    }

    private function joinContaining(int $anchor): void
    {
        foreach ($this->covers as $id) {
            if (!isset($this->parents[$id])) {
                $this->parents[$id] = $id;
                $this->sizes[$id] = 1;
                $this->touched[] = $id;
            }
            $this->join($anchor, $id);
        }
    }

    private function root(int $id): int
    {
        while ($this->parents[$id] !== $id) {
            $this->parents[$id] = $this->parents[$this->parents[$id]];
            $id = $this->parents[$id];
        }

        return $id;
    }

    private function join(int $left, int $right): void
    {
        $left = $this->root($left);
        $right = $this->root($right);
        if ($left === $right) {
            return;
        }
        if ($this->sizes[$left] < $this->sizes[$right]) {
            [$left, $right] = [$right, $left];
        }
        $this->parents[$right] = $left;
        $this->sizes[$left] += $this->sizes[$right];
    }

}
