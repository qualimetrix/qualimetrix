<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Token\TokenStream;

/**
 * Exact common-prefix length of every group member with the group's first
 * member, the anchor.
 *
 * Members inside a periodic run overlap each other, so comparing each one
 * from scratch costs the run length per member: quadratic in the run. A
 * member that starts inside the span already matched by an earlier member of
 * its file reuses that match through the anchor's own Z-array (Gusfield's
 * Z-box), so a file costs its matched span plus the anchor's self-similar
 * prefix. Members outside every span are compared token by token, which is
 * what an isolated copy costs anyway.
 */
final class AnchoredPrefixLengths
{
    /** @var array<int, int> Z-array of the anchor suffix, computed lazily from index 1 */
    private array $selfPrefix = [];
    private int $computed = 0;
    private int $zLeft = 0;
    private int $zRight = 0;
    private int $comparisons = 0;

    /**
     * @param list<string> $pattern
     */
    private function __construct(
        private readonly array $pattern,
        private readonly int $base,
        private readonly int $length,
    ) {}

    /**
     * @param array<int, TokenStream> $streams
     * @param non-empty-list<int> $members packed positions in file/offset order
     * @param int $known tokens every member is known to share with the anchor
     *
     * @return array{list<int>, int} Lengths indexed like `$members`, and the token comparisons made
     */
    public static function of(array $streams, array $members, int $known): array
    {
        $anchor = $members[0];
        $pattern = $streams[PackedPosition::fileIndex($anchor)]->values;
        $base = PackedPosition::offset($anchor);
        $lengths = new self($pattern, $base, \count($pattern) - $base);

        return [$lengths->measure($streams, $members, $known), $lengths->comparisons];
    }

    /**
     * @param array<int, TokenStream> $streams
     * @param non-empty-list<int> $members
     *
     * @return list<int>
     */
    private function measure(array $streams, array $members, int $known): array
    {
        $result = [$this->length];
        $file = -1;
        $text = [];
        $spanStart = 0;
        $spanEnd = 0;

        for ($i = 1, $count = \count($members); $i < $count; $i++) {
            $fileIndex = PackedPosition::fileIndex($members[$i]);
            $offset = PackedPosition::offset($members[$i]);
            if ($fileIndex !== $file) {
                $file = $fileIndex;
                $text = $streams[$fileIndex]->values;
                $spanEnd = 0;
            }

            $matched = $known;
            if ($offset < $spanEnd) {
                // $text[$spanStart, $spanEnd) equals the anchor's prefix of that length.
                $reused = $this->selfPrefixAt($offset - $spanStart);
                if ($reused < $spanEnd - $offset) {
                    $result[] = $reused;

                    continue;
                }
                $matched = max($matched, $spanEnd - $offset);
            }

            $matched = $this->extend($text, $offset, $matched);
            $result[] = $matched;
            if ($offset + $matched > $spanEnd) {
                $spanStart = $offset;
                $spanEnd = $offset + $matched;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $text
     */
    private function extend(array $text, int $offset, int $matched): int
    {
        $limit = min($this->length, \count($text) - $offset);
        $start = $matched;
        while ($matched < $limit && $text[$offset + $matched] === $this->pattern[$this->base + $matched]) {
            $matched++;
        }
        $this->comparisons += $matched - $start + 1;

        return $matched;
    }

    private function selfPrefixAt(int $index): int
    {
        while ($this->computed < $index) {
            $i = ++$this->computed;
            $z = $i < $this->zRight ? min($this->zRight - $i, $this->selfPrefix[$i - $this->zLeft]) : 0;
            $start = $z;
            while ($i + $z < $this->length && $this->pattern[$this->base + $z] === $this->pattern[$this->base + $i + $z]) {
                $z++;
            }
            $this->comparisons += $z - $start + 1;
            $this->selfPrefix[$i] = $z;
            if ($i + $z > $this->zRight) {
                $this->zLeft = $i;
                $this->zRight = $i + $z;
            }
        }

        return $this->selfPrefix[$index];
    }
}
