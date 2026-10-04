<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;

/** Verified matches held as packed positions until retained covers are known. */
final class DuplicateMatchCandidates
{
    /** @var array<int, string> */
    private array $recordChunks = [''];

    /** @var array<int, string> */
    private array $copyChunks = [''];

    private int $copySize = 0;

    private int $count = 0;

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
        $chunk = intdiv($this->count, 1024);
        $this->recordChunks[$chunk] ??= '';
        $this->recordChunks[$chunk] .= pack('q3', $length, \count($copies), $this->copySize);
        $previous = 0;
        $ordered = true;
        foreach ($copies as $copy) {
            if ($copy < $previous) {
                $ordered = false;
                break;
            }
            $previous = $copy;
        }
        $payload = $ordered ? "\1" : "\0";
        if (!$ordered) {
            $payload .= pack('q*', ...$copies);
        } else {
            $previousFile = 0;
            $previousOffset = 0;
            foreach ($copies as $copy) {
                $file = PackedPosition::fileIndex($copy);
                $offset = PackedPosition::offset($copy);
                self::appendUnsigned($payload, $file - $previousFile);
                self::appendUnsigned($payload, $file === $previousFile ? $offset - $previousOffset : $offset);
                $previousFile = $file;
                $previousOffset = $offset;
            }
        }
        $this->appendCopies($payload);
        $this->count++;
    }

    private static function appendUnsigned(string &$payload, int $value): void
    {
        while ($value >= 128) {
            $payload .= \chr(($value & 127) | 128);
            $value >>= 7;
        }
        $payload .= \chr($value);
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
        $order = $this->count === 0 ? [] : range(0, $this->count - 1);
        $this->sortOrder($order);

        $index = new CopyCoverIndex();
        $kept = [];
        foreach ($order as $match) {
            $record = $this->record($match);
            $length = $record[1];
            $copies = $this->readCopies($record[2], $record[3]);
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

    private function compare(int $a, int $b): int
    {
        $left = $this->record($a);
        $right = $this->record($b);
        $length = $right[1] <=> $left[1];
        if ($length !== 0) {
            return $length;
        }
        $copies = $right[2] <=> $left[2];
        return $copies !== 0 ? $copies : $a <=> $b;
    }

    /** @param array<int, int> $order */
    private function sortOrder(array &$order): void
    {
        $count = \count($order);
        for ($root = intdiv($count, 2) - 1; $root >= 0; $root--) {
            $this->sift($order, $root, $count);
        }
        for ($end = $count - 1; $end > 0; $end--) {
            $value = $order[0];
            $order[0] = $order[$end];
            $order[$end] = $value;
            $this->sift($order, 0, $end);
        }
    }

    /** @param array<int, int> $order */
    private function sift(array &$order, int $root, int $count): void
    {
        while (($child = $root * 2 + 1) < $count) {
            if ($child + 1 < $count && $this->compare($order[$child + 1], $order[$child]) > 0) {
                $child++;
            }
            if ($this->compare($order[$root], $order[$child]) >= 0) {
                return;
            }
            $value = $order[$root];
            $order[$root] = $order[$child];
            $order[$child] = $value;
            $root = $child;
        }
    }

    /** @return array{1: int, 2: int, 3: int} */
    private function record(int $index): array
    {
        /** @var array{1: int, 2: int, 3: int}|false $record */
        $record = unpack('q3', $this->recordChunks[intdiv($index, 1024)], $index % 1024 * 24);
        if ($record === false) {
            throw new LogicException('Cannot unpack the verified match copies');
        }

        return $record;
    }

    private function appendCopies(string $payload): void
    {
        $offset = 0;
        $size = \strlen($payload);
        while ($offset < $size) {
            $chunk = intdiv($this->copySize, 65536);
            $remaining = 65536 - $this->copySize % 65536;
            $take = min($remaining, $size - $offset);
            $this->copyChunks[$chunk] ??= '';
            $this->copyChunks[$chunk] .= substr($payload, $offset, $take);
            $this->copySize += $take;
            $offset += $take;
        }
    }

    private function copyByte(int $offset): string
    {
        return $this->copyChunks[intdiv($offset, 65536)][$offset % 65536];
    }

    /** @return list<int> */
    private function readCopies(int $count, int $offset): array
    {
        $ordered = $this->copyByte($offset++) === "\1";
        if (!$ordered) {
            return $this->readFixedCopies($count, $offset);
        }
        $copies = [];
        $previousFile = 0;
        $previousOffset = 0;
        for ($i = 0; $i < $count; $i++) {
            $file = $previousFile + $this->readUnsigned($offset);
            $offsetValue = $this->readUnsigned($offset);
            if ($file === $previousFile) {
                $offsetValue += $previousOffset;
            }
            $copies[] = PackedPosition::pack($file, $offsetValue);
            $previousFile = $file;
            $previousOffset = $offsetValue;
        }
        return $copies;
    }

    /** @return list<int> */
    private function readFixedCopies(int $count, int $offset): array
    {
        $copies = [];
        for ($i = 0; $i < $count; $i++) {
            $packed = '';
            for ($byte = 0; $byte < 8; $byte++) {
                $packed .= $this->copyByte($offset++);
            }
            /** @var array{1: int}|false $copy */
            $copy = unpack('q', $packed);
            if ($copy === false) {
                throw new LogicException('Cannot unpack the verified match copies');
            }
            $copies[] = $copy[1];
        }

        return $copies;
    }

    private function readUnsigned(int &$offset): int
    {
        $value = 0;
        $shift = 0;
        do {
            $byte = \ord($this->copyByte($offset++));
            $value |= ($byte & 127) << $shift;
            $shift += 7;
        } while ($byte >= 128);
        return $value;
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

}
