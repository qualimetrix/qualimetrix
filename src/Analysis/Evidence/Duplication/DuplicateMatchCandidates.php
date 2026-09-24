<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

use Closure;

/**
 * The matches {@see DuplicateBlockFinder} has found in one run, before the
 * ones whose copies all lie inside a longer match are dropped.
 *
 * Highly repetitive input yields a match at every point where its copies
 * stop agreeing, and nearly all of them are dropped: 30 files of `echo N;`
 * runs yielded 34 582 matches with 545 526 copies, of which 335 matches with
 * 5 145 copies survive. Held as blocks until then they exhausted a 128M
 * limit, so a match is held here as its length and its copies' packed
 * positions only, in parallel lists with each match's copies packed into one
 * string — an int and a string per match rather than two arrays.
 */
final class DuplicateMatchCandidates
{
    /** @var list<int> match => its length in tokens */
    private array $lengths = [];

    /** @var list<string> match => its copies' packed positions, `pack('q*')` */
    private array $packedCopies = [];

    /**
     * @param list<int> $copies packed positions of the match's copies
     */
    public function add(int $length, array $copies): void
    {
        $this->lengths[] = $length;
        $this->packedCopies[] = pack('q*', ...$copies);
    }

    /**
     * The matches of which at least one copy lies outside every longer match
     * kept before it, longest first; matches of equal length keep the order
     * they were added in.
     *
     * @param Closure(int, int): array{string, int, int} $span a copy's file, first and last
     *                                                         line, given the copy and the
     *                                                         match length
     *
     * @return list<array{int, list<int>}> each kept match's length and copies
     */
    public function withoutSubsumed(Closure $span): array
    {
        $lengths = $this->lengths;
        $order = array_keys($lengths);
        usort($order, static fn(int $a, int $b): int => [$lengths[$b], $a] <=> [$lengths[$a], $b]);

        /** @var array<string, list<array{int, int}>> $covered file => covered line ranges */
        $covered = [];
        $kept = [];

        foreach ($order as $match) {
            $length = $lengths[$match];
            $copies = self::unpack($this->packedCopies[$match]);
            $spans = array_map(static fn(int $copy): array => $span($copy, $length), $copies);

            if (self::allCovered($covered, $spans)) {
                continue;
            }

            $kept[] = [$length, $copies];

            foreach ($spans as [$file, $start, $end]) {
                $covered[$file][] = [$start, $end];
            }
        }

        return $kept;
    }

    /**
     * @param array<string, list<array{int, int}>> $covered
     * @param list<array{string, int, int}> $spans
     */
    private static function allCovered(array $covered, array $spans): bool
    {
        foreach ($spans as [$file, $start, $end]) {
            if (!array_any($covered[$file] ?? [], static fn(array $range): bool => $range[0] <= $start && $range[1] >= $end)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int>
     */
    private static function unpack(string $packed): array
    {
        $copies = unpack('q*', $packed);

        /** @var list<int> */
        return $copies === false ? [] : array_values($copies);
    }
}
