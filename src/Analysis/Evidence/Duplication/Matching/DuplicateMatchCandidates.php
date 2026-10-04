<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Closure;
use LogicException;

/** Verified matches held as packed positions until retained covers are known. */
final class DuplicateMatchCandidates
{
    /** @var list<int> */
    private array $lengths = [];

    /** @var list<string> Copies packed with pack('q*'). */
    private array $packedCopies = [];

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

    /** @param list<int> $copies */
    public function add(int $length, array $copies): void
    {
        $this->lengths[] = $length;
        $this->packedCopies[] = pack('q*', ...$copies);
    }

    /**
     * A candidate disappears only when retained matches connect all its copies.
     *
     * @param Closure(int, int): array{int, int, int} $span File index and half-open token interval
     *
     * @return list<array{int, list<int>}>
     */
    public function withoutSubsumed(Closure $span): array
    {
        $order = array_keys($this->lengths);
        usort($order, function (int $a, int $b): int {
            $length = $this->lengths[$b] <=> $this->lengths[$a];
            if ($length !== 0) {
                return $length;
            }
            $copies = \strlen($this->packedCopies[$b]) <=> \strlen($this->packedCopies[$a]);

            return $copies !== 0 ? $copies : $a <=> $b;
        });

        $index = new CopyCoverIndex();
        $kept = [];
        foreach ($order as $match) {
            $length = $this->lengths[$match];
            $copies = self::unpack($this->packedCopies[$match]);
            if ($this->isConnectedCover($index, $copies, $length, $span)) {
                continue;
            }

            $keptId = \count($kept);
            $kept[] = [$length, $copies];
            foreach ($copies as $copy) {
                [$file, $start, $end] = $span($copy, $length);
                $index->add($keptId, $file, $start, $end);
            }
        }

        return $kept;
    }

    /**
     * @param list<int> $copies
     * @param Closure(int, int): array{int, int, int} $span
     */
    private function isConnectedCover(CopyCoverIndex $index, array $copies, int $length, Closure $span): bool
    {
        $this->anchors = [];
        try {
            foreach ($copies as $copy) {
                [$file, $start, $end] = $span($copy, $length);
                $index->containing($file, $start, $end, $this->covers);
                if ($this->covers === []) {
                    return false;
                }

                $anchor = $this->covers[0];
                $this->anchors[] = $anchor;
                foreach ($this->covers as $id) {
                    if (!isset($this->parents[$id])) {
                        $this->parents[$id] = $id;
                        $this->sizes[$id] = 1;
                        $this->touched[] = $id;
                    }
                    $this->join($anchor, $id);
                }
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

    /** @return list<int> */
    private static function unpack(string $packed): array
    {
        $copies = unpack('q*', $packed);
        if ($copies === false) {
            throw new LogicException('Cannot unpack the verified match copies');
        }
        /** @var list<int> */
        return array_values($copies);
    }
}
